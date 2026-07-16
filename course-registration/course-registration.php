<?php
/**
 * Plugin Name: Course Registration
 * Description: רישום אוטומטי לקורס אחרי תשלום Sumit - יצירת משתמשים, תפקיד "לומד בקורס", מייל ברוכים הבאים, מעקב אפילייאטים. הופרד מתוסף Course Progress Tracker.
 * Version: 1.0.0
 * Author: Chepti
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Constants for course auto registration
define('CAR_DEFAULT_ROLE', 'לומד בקורס');
define('CAR_AFFILIATE_COOKIE_NAME', 'affiliate_ref');
define('CAR_AFFILIATE_COOKIE_EXPIRY', 30); // days
// כתובת FROM קבועה למיילים מהתוסף (אפשר להתאים אם צריך)
define('CAR_FROM_EMAIL', 'chep@chepti.com');

// On activation: create the learner role and default settings
function car_plugin_activate() {
    car_create_course_student_role();
    car_init_default_settings();
}
register_activation_hook(__FILE__, 'car_plugin_activate');

// Initialize default settings
function car_init_default_settings() {
    $defaults = [
        'car_role_name' => CAR_DEFAULT_ROLE,
        'car_send_email' => true,
        'car_email_subject' => 'ברוכים הבאים לקורס!',
        'car_whatsapp_gold' => 'https://chat.whatsapp.com/F053eDddoLoIDD2MOPUbCy',
        'car_whatsapp_silver' => 'https://chat.whatsapp.com/CYHeVm1bnXY461koFteTff',
    ];
    
    foreach ($defaults as $key => $value) {
        if (get_option($key) === false) {
            add_option($key, $value);
        }
    }
}

// Create course student role
function car_create_course_student_role() {
    $role_name = get_option('car_role_name', CAR_DEFAULT_ROLE);
    
    // Try to find existing role by name first (for Hebrew names)
    $role_slug = null;
    $all_roles = wp_roles()->get_names();
    
    // Search for role by name (case-insensitive)
    foreach ($all_roles as $slug => $name) {
        if (strtolower($name) === strtolower($role_name)) {
            $role_slug = $slug;
            break;
        }
    }
    
    // If not found by name, try to create slug from name
    if (!$role_slug) {
        // For Hebrew names, try common alternatives
        if ($role_name === 'לומד בקורס' || $role_name === CAR_DEFAULT_ROLE) {
            // Try "learner" as slug (English alternative)
            if (get_role('learner')) {
                $role_slug = 'learner';
            } else {
                $role_slug = sanitize_key($role_name);
            }
        } else {
            $role_slug = sanitize_key($role_name);
        }
    }
    
    // Check if role already exists by slug
    if (get_role($role_slug)) {
        return $role_slug; // Return the slug
    }
    
    // Role doesn't exist, create it
    // Check if Members plugin is active
    if (function_exists('members_register_role')) {
        // Use Members plugin to register role - use slug as first parameter
        $result = members_register_role($role_slug, [
            'label' => $role_name,
            'capabilities' => [
                'read' => true,
            ],
        ]);
        return $result !== false ? $role_slug : false;
    } else {
        // Fallback: use WordPress add_role
        $result = add_role($role_slug, $role_name, ['read' => true]);
        return $result !== null ? $role_slug : false;
    }
}

// Get role slug by name (handles Hebrew names and finds existing roles)
function car_get_role_slug($role_name) {
    // First, try to find existing role by name (case-insensitive)
    $all_roles = wp_roles()->get_names();
    foreach ($all_roles as $slug => $name) {
        if (mb_strtolower($name) === mb_strtolower($role_name)) {
            return $slug;
        }
    }

    // If not found, try special cases for Hebrew role name
    if ($role_name === 'לומד בקורס' || $role_name === CAR_DEFAULT_ROLE) {
        if (get_role('learner')) {
            return 'learner';
        }
    }

    // As a last resort, use sanitized key
    return sanitize_key($role_name);
}

// Resolve actual role slug from options with verification and logging
function car_resolve_target_role() {
    $role_name = get_option('car_role_name', CAR_DEFAULT_ROLE);
    $role_slug = car_get_role_slug($role_name);

    // If role not found, attempt to create and then re-check
    if (!get_role($role_slug)) {
        $created = car_create_course_student_role();
        if ($created) {
            $role_slug = $created;
        } elseif (get_role('learner')) {
            $role_slug = 'learner';
        }
    }

    // Final verification
    if (!get_role($role_slug)) {
        error_log('CAR: Target role not found. Requested name: ' . $role_name . ', slug tried: ' . $role_slug);
        return false;
    }

    return $role_slug;
}

/**
 * Check if a user has the course (learner) role.
 * Used to apply longer cookie lifetime and cookie refresh on course pages.
 */
function car_user_has_course_role($user_id) {
    $role_slug = car_resolve_target_role();
    if (!$role_slug) {
        return false;
    }
    $user = get_userdata($user_id);
    if (!$user || empty($user->roles)) {
        return false;
    }
    return in_array($role_slug, (array) $user->roles, true);
}

/**
 * Extend auth cookie expiration for course learners (30 days) so they stay logged in.
 */
function car_auth_cookie_expiration_for_learners($expiration, $user_id, $remember) {
    if ($remember && car_user_has_course_role($user_id)) {
        return 30 * DAY_IN_SECONDS;
    }
    return $expiration;
}
add_filter('auth_cookie_expiration', 'car_auth_cookie_expiration_for_learners', 10, 3);

/**
 * Refresh auth cookie when a learner views a private (course) page – keeps connection stable.
 */
function car_refresh_cookie_on_course_page() {
    if (!is_user_logged_in()) {
        return;
    }
    if (!car_user_has_course_role(get_current_user_id())) {
        return;
    }
    $post = get_queried_object();
    if (!($post instanceof WP_Post) || $post->post_status !== 'private') {
        return;
    }
    wp_set_auth_cookie(get_current_user_id(), true);
}
add_action('template_redirect', 'car_refresh_cookie_on_course_page', 5);

// Verify Sumit payment is valid and not already used
function car_verify_sumit_payment($payment_id, $customer_id, $mark_as_used = false) {
    if (empty($payment_id) || empty($customer_id)) {
        return false;
    }
    
    // Check if this payment ID was already used
    $used_payments = get_option('car_used_payment_ids', []);
    if (in_array($payment_id, $used_payments)) {
        error_log('CAR: Payment ID ' . $payment_id . ' already used');
        return false; // Payment already used
    }
    
    // Try to verify with Sumit API if credentials are set
    $sumit_api_key = get_option('car_sumit_api_key', '');
    $sumit_api_secret = get_option('car_sumit_api_secret', '');
    $sumit_business_id = get_option('car_sumit_business_id', '');
    
    $is_valid = false;
    $api_attempted = false;
    $api_response_error = false;
    
    if (!empty($sumit_api_key)) {
        // Verify payment with Sumit API (supports key+secret or key+businessId)
        $api_url = 'https://api.sumit.co.il/v1/payments/' . urlencode($payment_id);
        if (!empty($sumit_business_id)) {
            $api_url = add_query_arg(['businessId' => rawurlencode($sumit_business_id)], $api_url);
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if (!empty($sumit_api_secret)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($sumit_api_key . ':' . $sumit_api_secret);
        } else {
            // Key-only fallback
            $headers['Authorization'] = 'Bearer ' . $sumit_api_key;
            $headers['X-API-Key'] = $sumit_api_key;
            if (!empty($sumit_business_id)) {
                $headers['X-Business-ID'] = $sumit_business_id;
            }
        }
        
        $response = wp_remote_get($api_url, [
            'headers' => $headers,
            'timeout' => 10,
        ]);
        $api_attempted = true;
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            // Check if payment is valid and completed
            if ($data && isset($data['status'])) {
                $status = strtolower($data['status']);
                // Payment should be completed/approved
                if (in_array($status, ['completed', 'approved', 'success', 'paid'])) {
                    $is_valid = true;
                }
            }
        } else {
            $api_response_error = true;
            error_log('CAR: Sumit API error for payment ' . $payment_id . ' - ' . $response->get_error_message());
        }
    }
    
    // Fallback basic validation אם API לא קיים או נכשל/לא אישר
    if (!$is_valid) {
        $should_fallback = (!$api_attempted) || $api_response_error || !$is_valid;
        if ($should_fallback) {
            if (is_numeric($payment_id) && is_numeric($customer_id) && strlen($payment_id) >= 8) {
                $is_valid = true;
                error_log('CAR: Fallback validation accepted payment ' . $payment_id);
            } else {
                error_log('CAR: Fallback validation failed for payment ' . $payment_id);
            }
        }
    }
    
    // Mark as used only if valid AND mark_as_used is true
    if ($is_valid && $mark_as_used) {
        $used_payments[] = $payment_id;
        update_option('car_used_payment_ids', $used_payments);
    }
    
    return $is_valid;
}

// Mark payment as used (called after successful registration)
function car_mark_payment_as_used($payment_id) {
    if (empty($payment_id)) {
        return;
    }
    
    $used_payments = get_option('car_used_payment_ids', []);
    if (!in_array($payment_id, $used_payments)) {
        $used_payments[] = $payment_id;
        update_option('car_used_payment_ids', $used_payments);
    }
}

// Generic POST to the Sumit/OfficeGuy REST API with Credentials (CompanyID + APIKey).
// Sumit's API does NOT use a secret – only APIKey + CompanyID (business id).
function car_sumit_api_post($endpoint, $extra = []) {
    $api_key    = get_option('car_sumit_api_key', '');
    $company_id = get_option('car_sumit_business_id', '');
    if (empty($api_key) || empty($company_id)) {
        return false;
    }

    $payload = array_merge([
        'Credentials' => [
            'CompanyID' => (int) $company_id,
            'APIKey'    => $api_key,
        ],
    ], $extra);

    $response = wp_remote_post('https://api.sumit.co.il' . $endpoint, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload),
        'timeout' => 12,
    ]);

    if (is_wp_error($response)) {
        error_log('CAR: Sumit API transport error ' . $endpoint . ' - ' . $response->get_error_message());
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        error_log('CAR: Sumit API non-JSON response from ' . $endpoint);
        return false;
    }
    return $data;
}

// Fetch customer email + name from a Sumit document (the paying customer on that document).
// Returns ['email'=>..,'name'=>..] or false. Verified path: Data.Document.Customer.EmailAddress/Name, Status==0.
function car_get_sumit_customer_from_document($document_id) {
    if (empty($document_id)) {
        return false;
    }
    $data = car_sumit_api_post('/accounting/documents/getdetails/', ['DocumentID' => (int) $document_id]);
    if (!$data || !isset($data['Status']) || (int) $data['Status'] !== 0) {
        $msg = (is_array($data) && isset($data['UserErrorMessage'])) ? $data['UserErrorMessage'] : 'unknown';
        error_log('CAR: Sumit getdetails failed for document ' . $document_id . ' - ' . $msg);
        return false;
    }
    $customer = isset($data['Data']['Document']['Customer']) ? $data['Data']['Document']['Customer'] : null;
    if (!$customer || empty($customer['EmailAddress'])) {
        error_log('CAR: Sumit document ' . $document_id . ' has no customer email');
        return false;
    }
    return [
        'email' => sanitize_email($customer['EmailAddress']),
        'name'  => isset($customer['Name']) ? sanitize_text_field($customer['Name']) : '',
    ];
}

// Force assign role to user (used after user creation)
// This function mimics how Import plugin assigns roles
function car_assign_user_role_force($user_id, $role_slug) {
    if (empty($user_id) || empty($role_slug)) {
        return false;
    }
    
    // Verify role exists
    $role_obj = get_role($role_slug);
    if (!$role_obj) {
        error_log('CAR: Role ' . $role_slug . ' does not exist');
        return false;
    }
    
    // Get fresh user object
    $user_obj = new WP_User($user_id);
    if (!$user_obj->exists()) {
        return false;
    }
    
    // Method 1: Use WordPress set_role (this is what most plugins use)
    // Remove all existing roles first
    $current_roles = $user_obj->roles;
    foreach ($current_roles as $old_role) {
        $user_obj->remove_role($old_role);
    }
    
    // Set the new role using WordPress core function
    $user_obj->set_role($role_slug);
    
    // Clear cache immediately
    clean_user_cache($user_id);
    wp_cache_delete($user_id, 'users');
    wp_cache_delete($user_id, 'user_meta');
    
    // Reload user to verify
    $user_obj = new WP_User($user_id);
    $final_roles = $user_obj->roles;
    
    if (in_array($role_slug, $final_roles)) {
        // Also try Members plugin method if available (for compatibility)
        if (function_exists('members_set_user_role')) {
            members_set_user_role($user_id, $role_slug);
        }
        return true;
    }
    
    // Method 2: Direct database update (like Import plugin does)
    global $wpdb;
    
    // Build capabilities array
    $capabilities = [];
    if ($role_obj && isset($role_obj->capabilities)) {
        foreach ($role_obj->capabilities as $cap => $value) {
            if ($value) {
                $capabilities[$cap] = true;
            }
        }
    }
    
    // Update capabilities directly in database
    $capabilities_meta = [$role_slug => true];
    $capabilities_meta = array_merge($capabilities_meta, $capabilities);
    
    // Delete old capabilities
    $wpdb->delete(
        $wpdb->usermeta,
        [
            'user_id' => $user_id,
            'meta_key' => $wpdb->prefix . 'capabilities'
        ],
        ['%d', '%s']
    );
    
    // Insert new capabilities
    $wpdb->insert(
        $wpdb->usermeta,
        [
            'user_id' => $user_id,
            'meta_key' => $wpdb->prefix . 'capabilities',
            'meta_value' => serialize($capabilities_meta)
        ],
        ['%d', '%s', '%s']
    );
    
    // Clear all caches again
    clean_user_cache($user_id);
    wp_cache_delete($user_id, 'users');
    wp_cache_delete($user_id, 'user_meta');
    wp_cache_flush();
    
    // Reload user
    $user_obj = new WP_User($user_id);
    $final_roles = $user_obj->roles;
    
    // If still not working, try Members plugin method
    if (!in_array($role_slug, $final_roles) && function_exists('members_set_user_role')) {
        // Try Members method
        members_set_user_role($user_id, $role_slug);
        
        // Clear cache and reload
        clean_user_cache($user_id);
        wp_cache_delete($user_id, 'users');
        $user_obj = new WP_User($user_id);
        $final_roles = $user_obj->roles;
    }
    
    return in_array($role_slug, $final_roles);
}

// Hook to assign role after user registration
function car_assign_role_on_registration($user_id) {
    $role_name = get_option('car_role_name', CAR_DEFAULT_ROLE);
    $role_slug = sanitize_key($role_name);
    
    // Check if this is a user we just created (by checking transient)
    $user = get_userdata($user_id);
    if ($user) {
        $transient_key = 'car_pending_role_' . md5($user->user_email);
        $pending_role = get_transient($transient_key);
        
        if ($pending_role && $pending_role === $role_slug) {
            car_assign_user_role_force($user_id, $role_slug);
        }
    }
}
add_action('user_register', 'car_assign_role_on_registration', 20);
add_action('wp_insert_user', 'car_assign_role_on_registration', 20);

// Get affiliate ref from cookie or URL
function car_get_affiliate_ref() {
    // First check URL parameter
    if (isset($_GET['ref']) && !empty($_GET['ref'])) {
        $ref = sanitize_text_field($_GET['ref']);
        // Set cookie for 30 days
        setcookie(CAR_AFFILIATE_COOKIE_NAME, $ref, time() + (CAR_AFFILIATE_COOKIE_EXPIRY * DAY_IN_SECONDS), '/');
        return $ref;
    }
    
    // Then check cookie
    if (isset($_COOKIE[CAR_AFFILIATE_COOKIE_NAME]) && !empty($_COOKIE[CAR_AFFILIATE_COOKIE_NAME])) {
        return sanitize_text_field($_COOKIE[CAR_AFFILIATE_COOKIE_NAME]);
    }
    
    return null;
}

// Get affiliate name from conversions table
function car_get_affiliate_name($ref) {
    $conversions = get_option('car_affiliate_conversions', []);
    if (isset($conversions[$ref]) && isset($conversions[$ref]['affiliate_name'])) {
        return $conversions[$ref]['affiliate_name'];
    }
    return $ref; // Fallback to ref if name not found
}

// Save affiliate conversion
function car_save_affiliate_conversion($ref, $user_id) {
    if (empty($ref)) {
        return;
    }
    
    $conversions = get_option('car_affiliate_conversions', []);
    $now = current_time('mysql');
    
    if (!isset($conversions[$ref])) {
        // First conversion for this ref
        $conversions[$ref] = [
            'affiliate_name' => $ref, // Default to ref, can be updated manually
            'conversions_count' => 1,
            'first_conversion_date' => $now,
            'last_conversion_date' => $now,
            'user_ids' => [$user_id],
        ];
    } else {
        // Update existing ref
        $conversions[$ref]['conversions_count']++;
        $conversions[$ref]['last_conversion_date'] = $now;
        if (!in_array($user_id, $conversions[$ref]['user_ids'])) {
            $conversions[$ref]['user_ids'][] = $user_id;
        }
    }
    
    update_option('car_affiliate_conversions', $conversions);
}

// Create user from payment data (with role assignment at creation time)
function car_create_user_from_payment($email, $name) {
    // Sanitize inputs
    $email = sanitize_email($email);
    $name = sanitize_text_field($name);

    // Validate email
    if (!is_email($email)) {
        return new WP_Error('invalid_email', 'כתובת אימייל לא תקינה.');
    }

    // Resolve target role
    $role_slug = car_resolve_target_role();
    if (!$role_slug) {
        return new WP_Error('missing_role', 'לא נמצא תפקיד יעד להקצאה.');
    }

    // Check if user already exists
    $user = get_user_by('email', $email);
    if ($user) {
        // Affiliate attribution for existing user
        $affiliate_ref = car_get_affiliate_ref();
        if ($affiliate_ref && !get_user_meta($user->ID, 'referral_source', true)) {
            update_user_meta($user->ID, 'referral_source', $affiliate_ref);
            $affiliate_name = car_get_affiliate_name($affiliate_ref);
            update_user_meta($user->ID, 'referral_name', $affiliate_name);
            car_save_affiliate_conversion($affiliate_ref, $user->ID);
        }

        // Ensure role exists for existing user (idempotent)
        $user_obj = new WP_User($user->ID);
        if (!in_array($role_slug, $user_obj->roles, true)) {
            car_assign_user_role_force($user->ID, $role_slug);
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        return ['user' => $user, 'is_new' => false];
    }

    // Parse name into first and last name
    $name_parts = explode(' ', $name, 2);
    $first_name = $name_parts[0];
    $last_name = isset($name_parts[1]) ? $name_parts[1] : '';

    // Generate username from email
    $username = sanitize_user(str_replace('@', '_', $email));
    $original_username = $username;
    $counter = 1;
    while (username_exists($username)) {
        $username = $original_username . $counter;
        $counter++;
    }

    // Generate random password
    $password = wp_generate_password(12, false);

    // Create user with role set at creation
    $user_id = wp_insert_user([
        'user_login' => $username,
        'user_email' => $email,
        'user_pass' => $password,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'role' => $role_slug,
    ]);

    if (is_wp_error($user_id)) {
        error_log('CAR: wp_insert_user error - ' . $user_id->get_error_message());
        return $user_id;
    }

    // Double-check role; fallback assignment if missing
    $user_obj = new WP_User($user_id);
    if (!in_array($role_slug, $user_obj->roles, true)) {
        car_assign_user_role_force($user_id, $role_slug);
    }

    // Affiliate tracking
    $affiliate_ref = car_get_affiliate_ref();
    if ($affiliate_ref) {
        update_user_meta($user_id, 'referral_source', $affiliate_ref);
        $affiliate_name = car_get_affiliate_name($affiliate_ref);
        update_user_meta($user_id, 'referral_name', $affiliate_name);
        car_save_affiliate_conversion($affiliate_ref, $user_id);
    }

    // Log user in (remember=true + filter gives learners 30-day cookie)
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);

    // Send welcome email if enabled
    $send_email = get_option('car_send_email', true);
    error_log('CAR: Email sending enabled: ' . ($send_email ? 'YES' : 'NO'));
    if ($send_email) {
        error_log('CAR: Sending welcome email to user ' . $user_id);
        car_send_welcome_email($user_id, $email, $username, $password);
    }

    return ['user' => get_userdata($user_id), 'is_new' => true];
}

// Send welcome email with login credentials (פונקציה חדשה ופשוטה)
function car_send_welcome_email($user_id, $email, $username, $password) {
    $user       = get_userdata($user_id);
    $first_name = get_user_meta($user_id, 'first_name', true);
    $display    = !empty($first_name) ? $first_name : ($user ? $user->display_name : '');

    $subject    = get_option('car_email_subject', 'ברוכים הבאים לקורס!');
    $login_url  = wp_login_url();
    $site_name  = get_bloginfo('name');

    // בונים מייל טקסט פשוט – בלי HTML בכלל
    $body  = "שלום {$display},\n\n";
    $body .= "ברוכים הבאים לקורס {$site_name}!\n\n";
    $body .= "פרטי ההתחברות שלך:\n";
    $body .= "שם משתמש: {$username}\n";
    $body .= "סיסמה: {$password}\n\n";
    $body .= "קישור התחברות: {$login_url}\n\n";

    $whatsapp_gold   = get_option('car_whatsapp_gold', '');
    $whatsapp_silver = get_option('car_whatsapp_silver', '');
    if ($whatsapp_gold || $whatsapp_silver) {
        $body .= "קבוצות ווטסאפ:\n";
        if ($whatsapp_gold) {
            $body .= "מסלול זהב: {$whatsapp_gold}\n";
        }
        if ($whatsapp_silver) {
            $body .= "מסלול כסף: {$whatsapp_silver}\n";
        }
        $body .= "\n";
    }

    $body .= "אם אינך מזהה מייל זה, אפשר להתעלם ממנו.\n";

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $site_name . ' <' . CAR_FROM_EMAIL . '>',
    ];

    error_log('CAR: Sending SIMPLE welcome email to ' . $email);
    $result = wp_mail($email, $subject, $body, $headers);
    error_log('CAR: SIMPLE welcome email result: ' . ($result ? 'success' : 'fail'));
}

// Get default HTML email template
function car_get_default_email_html($display_name, $username, $password, $login_url, $site_name, $logo_url, $whatsapp_gold, $whatsapp_silver) {
    $logo_url_esc = esc_url($logo_url);
    $site_name_esc = esc_attr($site_name);
    $display_name_esc = esc_html($display_name);
    $site_name_html = esc_html($site_name);
    $username_esc = esc_html($username);
    $password_esc = esc_html($password);
    $login_url_esc = esc_url($login_url);
    $whatsapp_gold_esc = esc_url($whatsapp_gold);
    $whatsapp_silver_esc = esc_url($whatsapp_silver);

    // מינימום HTML, ללא עיצוב כבד
    $html = <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head><meta charset="UTF-8"></head>
<body style="font-family:Tahoma,Arial,sans-serif; direction:rtl; text-align:right;">
    <p>שלום {$display_name_esc},</p>
    <p>פתחנו עבורך חשבון ב{$site_name_html}. פרטי ההתחברות:</p>
    <p><strong>שם משתמש:</strong> {$username_esc}<br>
    <strong>סיסמה:</strong> {$password_esc}</p>
    <p><a href="{$login_url_esc}" target="_blank" rel="noopener noreferrer">כניסה לקורס</a></p>
    <p>קבוצות ווטסאפ:<br>
    מסלול זהב: <a href="{$whatsapp_gold_esc}" target="_blank" rel="noopener noreferrer">קישור</a><br>
    מסלול כסף: <a href="{$whatsapp_silver_esc}" target="_blank" rel="noopener noreferrer">קישור</a></p>
    <p style="color:#666; font-size:12px;">הודעה אוטומטית מ{$site_name_html}.</p>
</body>
</html>
HTML;

    return $html;
}

// Wrap plain text email in HTML template
function car_wrap_email_html($content, $logo_url, $site_name) {
    return '
<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            direction: rtl;
            text-align: right;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
        }
        .email-header {
            background: linear-gradient(135deg, #4A90E2 0%, #F7D979 100%);
            padding: 30px 20px;
            text-align: center;
        }
        .email-logo {
            max-width: 200px;
            height: auto;
            margin-bottom: 15px;
        }
        .email-content {
            padding: 30px 20px;
            font-size: 16px;
            line-height: 1.6;
            color: #333;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            font-size: 14px;
            border-top: 1px solid #e0e0e0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" class="email-logo" />
        </div>
        
        <div class="email-content">
            ' . $content . '
        </div>
        
        <div class="footer">
            <p style="margin: 0;">בברכה,<br><strong>צוות ' . esc_html($site_name) . '</strong></p>
        </div>
    </div>
</body>
</html>';
}

// Search user by email or username
function car_search_user_ajax() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'אין הרשאה']);
        return;
    }
    
    check_ajax_referer('car_search_user_nonce', 'nonce');
    
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    if (empty($search)) {
        wp_send_json_error(['message' => 'יש להזין חיפוש']);
        return;
    }
    
    // Search by email or login
    $users = get_users([
        'search' => '*' . $search . '*',
        'search_columns' => ['user_login', 'user_email', 'display_name', 'user_nicename'],
        'number' => 10,
    ]);
    
    $results = [];
    foreach ($users as $user) {
        $user_obj = new WP_User($user->ID);
        $results[] = [
            'ID' => $user->ID,
            'display_name' => $user->display_name,
            'user_email' => $user->user_email,
            'user_login' => $user->user_login,
            'roles' => $user_obj->roles,
        ];
    }
    
    wp_send_json_success(['users' => $results]);
}
add_action('wp_ajax_car_search_user', 'car_search_user_ajax');

// Send test email
function car_send_test_email() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'אין הרשאה']);
        return;
    }
    
    // בדיקת nonce בצורה שמחזירה JSON (לא -1), כדי שלא תשבור את ה-JS
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'car_test_email_nonce')) {
        wp_send_json_error(['message' => 'האימות פג תוקף, רענני את העמוד ונסי שוב.']);
        return;
    }
    
    $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
    if (empty($test_email)) {
        wp_send_json_error(['message' => 'יש להזין כתובת אימייל']);
        return;
    }
    
    error_log('CAR: Test email requested to ' . $test_email);
    
    $subject   = get_option('car_email_subject', 'ברוכים הבאים לקורס!');
    $login_url = wp_login_url();
    $site_name = get_bloginfo('name');

    // אותו פורמט כמו מייל אמיתי – טקסט פשוט
    $body  = "שלום משתמש בדיקה,\n\n";
    $body .= "זהו מייל בדיקה מהתוסף {$site_name}.\n\n";
    $body .= "שם משתמש לדוגמה: test_user\n";
    $body .= "סיסמה לדוגמה: Test123!@#\n\n";
    $body .= "קישור התחברות: {$login_url}\n\n";
    $body .= "אם את רואה את המייל הזה, שליחת המיילים עובדת.\n";

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $site_name . ' <' . CAR_FROM_EMAIL . '>',
    ];

    error_log('CAR: Sending SIMPLE test email to ' . $test_email);
    $result = wp_mail($test_email, $subject . ' (בדיקה)', $body, $headers);
    error_log('CAR: SIMPLE test email result: ' . ($result ? 'success' : 'fail'));
    
    if ($result) {
        wp_send_json_success(['message' => 'מייל בדיקה נשלח בהצלחה!']);
    } else {
        wp_send_json_error(['message' => 'שגיאה בשליחת המייל']);
    }
}
add_action('wp_ajax_car_send_test_email', 'car_send_test_email');

// Render the post-registration success screen (WhatsApp groups + enter course)
// Shown on /thank-you/?registered=1 regardless of payment params.
function car_render_success_screen() {
    $whatsapp_gold   = get_option('car_whatsapp_gold', 'https://chat.whatsapp.com/F053eDddoLoIDD2MOPUbCy');
    $whatsapp_silver = get_option('car_whatsapp_silver', 'https://chat.whatsapp.com/CYHeVm1bnXY461koFteTff');
    $course_url      = 'https://tikshuv.chepti.com/aia/';

    return '<style>
        @import url("https://fonts.googleapis.com/css2?family=Varela+Round&display=swap");
        .car-success-wrapper { font-family: "Varela Round", sans-serif; max-width: 600px; margin: 40px auto; padding: 0; }
        .car-success-container { background: linear-gradient(135deg, #4A90E2 0%, #F7D979 100%); border-radius: 20px; padding: 40px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); text-align: right; direction: rtl; }
        .car-success-message { background: rgba(255,255,255,0.95); border-radius: 15px; padding: 30px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); text-align: center; margin-bottom: 30px; }
        .car-success-title { font-size: 28px; font-weight: bold; color: #4A90E2; margin: 0 0 15px 0; }
        .car-success-text { font-size: 18px; color: #333; margin: 0 0 10px 0; line-height: 1.6; }
        .car-enter-course-btn { display: block; margin-top: 20px; padding: 16px 20px; background: linear-gradient(135deg, #4A90E2 0%, #357ABD 100%); color: #fff; text-decoration: none; border-radius: 12px; text-align: center; font-weight: bold; font-size: 20px; box-shadow: 0 4px 15px rgba(74,144,226,0.3); }
        .car-whatsapp-groups { background: rgba(255,255,255,0.95); border-radius: 15px; padding: 30px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .car-whatsapp-title { font-size: 24px; font-weight: bold; color: #4A90E2; text-align: center; margin: 0 0 20px 0; }
        .car-whatsapp-buttons { display: grid; gap: 15px; }
        .car-whatsapp-btn { display: block; padding: 18px 20px; text-decoration: none; border-radius: 12px; text-align: center; font-weight: bold; font-size: 20px; font-family: "Varela Round", sans-serif; box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .car-whatsapp-gold { background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%); color: #000; }
        .car-whatsapp-silver { background: linear-gradient(135deg, #C0C0C0 0%, #808080 100%); color: #fff; }
    </style>
    <div class="car-success-wrapper">
        <div class="car-success-container">
            <div class="car-success-message">
                <h2 class="car-success-title">רישום הושלם בהצלחה! 🎉</h2>
                <p class="car-success-text">חשבון נפתח עבורך בהצלחה. פרטי ההתחברות נשלחו לכתובת האימייל שלך.</p>
                <p class="car-success-text" style="font-weight:bold;">תודה, מייל נשלח אליך עם פרטי הכניסה.</p>
                <a href="' . esc_url($course_url) . '" class="car-enter-course-btn">כניסה לקורס ▶</a>
            </div>
            <div class="car-whatsapp-groups">
                <h3 class="car-whatsapp-title">הצטרפו לקבוצות הווטסאפ שלנו!</h3>
                <div class="car-whatsapp-buttons">
                    <a href="' . esc_url($whatsapp_gold) . '" target="_blank" rel="noopener noreferrer" class="car-whatsapp-btn car-whatsapp-gold">📱 מסלול זהב</a>
                    <a href="' . esc_url($whatsapp_silver) . '" target="_blank" rel="noopener noreferrer" class="car-whatsapp-btn car-whatsapp-silver">📱 מסלול כסף</a>
                </div>
            </div>
        </div>
    </div>';
}

// Thank you page shortcode
function car_thank_you_shortcode() {
    error_log('CAR: Thank you shortcode triggered');

    // מסך הצלחה אחרי הרשמה - עצמאי, לפני כל בדיקת פרמטרים או הפניה
    if (isset($_GET['registered']) && $_GET['registered'] == '1') {
        return car_render_success_screen();
    }
    // Get parameters from POST first (most payment gateways use POST), then GET
    $email = '';
    $name = '';
    
    // Try POST first (common for payment gateways)
    if (isset($_POST['email']) && !empty($_POST['email'])) {
        $email = sanitize_email($_POST['email']);
    } elseif (isset($_GET['email']) && !empty($_GET['email'])) {
        $email = sanitize_email($_GET['email']);
    }
    
    if (isset($_POST['name']) && !empty($_POST['name'])) {
        $name = sanitize_text_field($_POST['name']);
    } elseif (isset($_GET['name']) && !empty($_GET['name'])) {
        $name = sanitize_text_field($_GET['name']);
    }
    
    // Try alternative parameter names (some gateways use different names)
    if (empty($email)) {
        // Common alternatives: customer_email, user_email, buyer_email, email_address
        $email_fields = ['customer_email', 'user_email', 'buyer_email', 'email_address', 'Email', 'EMAIL'];
        foreach ($email_fields as $field) {
            if (isset($_POST[$field]) && !empty($_POST[$field])) {
                $email = sanitize_email($_POST[$field]);
                break;
            } elseif (isset($_GET[$field]) && !empty($_GET[$field])) {
                $email = sanitize_email($_GET[$field]);
                break;
            }
        }
    }
    
    if (empty($name)) {
        // Common alternatives: customer_name, user_name, buyer_name, full_name, customerName
        $name_fields = ['customer_name', 'user_name', 'buyer_name', 'full_name', 'customerName', 'Name', 'NAME'];
        foreach ($name_fields as $field) {
            if (isset($_POST[$field]) && !empty($_POST[$field])) {
                $name = sanitize_text_field($_POST[$field]);
                break;
            } elseif (isset($_GET[$field]) && !empty($_GET[$field])) {
                $name = sanitize_text_field($_GET[$field]);
                break;
            }
        }
    }
    
    // Check if this is Sumit payment gateway
    $is_sumit = false;
    $sumit_customer_id = '';
    $sumit_payment_id = '';
    $payment_verified = false;
    
    if (isset($_GET['OG-CustomerID']) || isset($_POST['OG-CustomerID'])) {
        $is_sumit = true;
        $sumit_customer_id = isset($_GET['OG-CustomerID']) ? sanitize_text_field($_GET['OG-CustomerID']) : sanitize_text_field($_POST['OG-CustomerID']);
        $sumit_payment_id = isset($_GET['OG-PaymentID']) ? sanitize_text_field($_GET['OG-PaymentID']) : (isset($_POST['OG-PaymentID']) ? sanitize_text_field($_POST['OG-PaymentID']) : '');
        $sumit_document_id = isset($_GET['OG-DocumentID']) ? sanitize_text_field($_GET['OG-DocumentID']) : (isset($_POST['OG-DocumentID']) ? sanitize_text_field($_POST['OG-DocumentID']) : '');

        // Diagnostic: log exactly which OfficeGuy params arrived (helps map the redirect once).
        error_log('CAR: Sumit redirect params - CustomerID=' . $sumit_customer_id . ' PaymentID=' . $sumit_payment_id . ' DocumentID=' . $sumit_document_id . ' | all keys: ' . implode(',', array_keys(array_merge($_GET, $_POST))));

        if (empty($sumit_payment_id) || empty($sumit_customer_id)) {
            error_log('CAR: Sumit detected but missing IDs');
            wp_safe_redirect(home_url());
            exit;
        }

        // CRITICAL: Verify payment is valid and not already used
        if (!empty($sumit_payment_id) && !empty($sumit_customer_id)) {
            $payment_verified = car_verify_sumit_payment($sumit_payment_id, $sumit_customer_id, false); // Don't mark as used yet
            
            if (!$payment_verified) {
                error_log('CAR: Payment verification failed for ' . $sumit_payment_id);
                // Payment invalid or already used - show error
                return '<div class="car-thank-you-wrapper" style="max-width: 600px; margin: 40px auto; padding: 30px; background: #f8f9fa; border-radius: 10px; text-align: right; direction: rtl;">
                    <div class="car-thank-you-message car-error" style="padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;">
                        <h3 style="margin-top: 0; color: #721c24;">שגיאה באימות התשלום</h3>
                        <p style="color: #721c24;">הקישור הזה כבר שימש או שהתשלום לא אומת. אנא פנה לתמיכה.</p>
                        <p style="color: #721c24; font-size: 14px; margin-top: 10px;">אם שילמת עכשיו, ייתכן שהקישור כבר שימש בעבר. כל קישור תשלום יכול לשמש פעם אחת בלבד.</p>
                    </div>
                </div>';
            }
        }
        
        // Try to get customer email + name automatically from Sumit (only needs API Key + Business ID).
        // Reliable path is by DocumentID (Data.Document.Customer). If the redirect didn't include one,
        // we fall through to the manual form below.
        $sumit_api_key = get_option('car_sumit_api_key', '');
        if (!empty($sumit_api_key) && !empty($sumit_document_id) && (empty($email) || empty($name))) {
            $sumit_customer_data = car_get_sumit_customer_from_document($sumit_document_id);
            if ($sumit_customer_data && !empty($sumit_customer_data['email'])) {
                if (empty($email)) {
                    $email = $sumit_customer_data['email'];
                }
                if (empty($name) && !empty($sumit_customer_data['name'])) {
                    $name = $sumit_customer_data['name'];
                }
                error_log('CAR: Auto-fetched customer from Sumit document ' . $sumit_document_id . ' - email=' . $email);
            }
        }
    }

    // Block direct access when אין מזהה תשלום (מנהלים רואים תצוגה מקדימה של הטופס)
    if (!$is_sumit) {
        if (!current_user_can('manage_options')) {
            error_log('CAR: Thank you accessed without Sumit parameters, redirecting');
            wp_safe_redirect(home_url());
            exit;
        }
        // מנהל מחובר: ממשיכים כדי להציג את טופס הרישום לתצוגה מקדימה
    }
    
    // Debug mode - show what we received (only for admins)
    $debug_mode = get_option('car_debug_mode', false);
    $debug_output = '';
    
    if ($debug_mode && current_user_can('manage_options')) {
        $debug_output = '<div class="car-debug-info" style="padding: 15px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px; margin-bottom: 20px; font-family: monospace; font-size: 12px; direction: ltr; text-align: left;">
            <strong>Debug Info (Admin Only):</strong><br>
            <strong>GET params:</strong> ' . esc_html(print_r($_GET, true)) . '<br>
            <strong>POST params:</strong> ' . esc_html(print_r($_POST, true)) . '<br>
            <strong>Detected Email:</strong> ' . ($email ? esc_html($email) : 'NOT FOUND') . '<br>
            <strong>Detected Name:</strong> ' . ($name ? esc_html($name) : 'NOT FOUND') . '<br>
        </div>';
    }
    
    // If no email or name, show form to enter manually (especially for Sumit)
    if (empty($email) || empty($name)) {
        // Show manual entry form with beautiful design
        $form_output = '<style>
            @import url("https://fonts.googleapis.com/css2?family=Varela+Round&display=swap");
            .car-registration-form-wrapper {
                font-family: "Varela Round", sans-serif;
                max-width: 600px;
                margin: 40px auto;
                padding: 0;
            }
            .car-registration-form-container {
                background: linear-gradient(135deg, #4A90E2 0%, #F7D979 100%);
                border-radius: 20px;
                padding: 40px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.15);
                text-align: right;
                direction: rtl;
            }
            .car-registration-form-title {
                font-size: 32px;
                font-weight: bold;
                color: #fff;
                margin: 0 0 15px 0;
                text-align: center;
                text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            .car-registration-form-subtitle {
                font-size: 18px;
                color: #fff;
                margin: 0 0 30px 0;
                text-align: center;
                line-height: 1.6;
                text-shadow: 0 1px 2px rgba(0,0,0,0.2);
            }
            .car-registration-form {
                background: rgba(255,255,255,0.95);
                border-radius: 15px;
                padding: 30px;
                box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            }
            .car-form-field {
                margin-bottom: 25px;
            }
            .car-form-label {
                display: block;
                margin-bottom: 8px;
                font-size: 16px;
                font-weight: bold;
                color: #333;
            }
            .car-form-input {
                width: 100%;
                padding: 15px;
                border: 2px solid #e0e0e0;
                border-radius: 10px;
                font-size: 16px;
                font-family: "Varela Round", sans-serif;
                transition: all 0.3s ease;
                box-sizing: border-box;
            }
            .car-form-input:focus {
                outline: none;
                border-color: #4A90E2;
                box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
            }
            .car-form-submit-btn {
                width: 100%;
                padding: 18px;
                background: linear-gradient(135deg, #4A90E2 0%, #F7D979 100%);
                color: #fff;
                border: none;
                border-radius: 10px;
                font-size: 20px;
                font-weight: bold;
                font-family: "Varela Round", sans-serif;
                cursor: pointer;
                transition: all 0.3s ease;
                text-shadow: 0 1px 2px rgba(0,0,0,0.2);
                box-shadow: 0 4px 15px rgba(74, 144, 226, 0.3);
            }
            .car-form-submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(74, 144, 226, 0.4);
            }
            .car-form-submit-btn:active {
                transform: translateY(0);
            }
        </style>
        
        <div class="car-registration-form-wrapper">
            <div class="car-registration-form-container">
                <h2 class="car-registration-form-title">תודה על הרכישה!</h2>
                <p class="car-registration-form-subtitle">איזה כיף, תיכף מתחילים. כדי לקבל גישה מלאה לקורס ואת כל ההסברים - צריך למלא שוב כמה פרטים.</p>
                
                <form method="post" action="" id="car-manual-registration-form" class="car-registration-form">
                    <div class="car-form-field">
                        <label for="car_manual_email" class="car-form-label">כתובת אימייל:</label>
                        <input type="email" id="car_manual_email" name="car_manual_email" required class="car-form-input" placeholder="הזן את כתובת האימייל שלך" inputmode="email" autocomplete="email" />
                    </div>
                    <div class="car-form-field">
                        <label for="car_manual_name" class="car-form-label">שם מלא:</label>
                        <input type="text" id="car_manual_name" name="car_manual_name" required class="car-form-input" placeholder="הזן את שמך המלא" />
                    </div>';
        
        if ($is_sumit && !empty($sumit_customer_id) && $payment_verified) {
            $form_output .= '<input type="hidden" name="car_sumit_customer_id" value="' . esc_attr($sumit_customer_id) . '" />';
            $form_output .= '<input type="hidden" name="car_sumit_payment_id" value="' . esc_attr($sumit_payment_id) . '" />';
            $form_output .= '<input type="hidden" name="car_payment_verified" value="1" />';
        }
        
        $form_output .= '<button type="submit" class="car-form-submit-btn">
                    השלם רישום
                </button>
            </form>
        </div>
    </div>';
        
        // Handle form submission
        if (isset($_POST['car_manual_email']) && isset($_POST['car_manual_name'])) {
            // Security check: if this is Sumit payment, verify it wasn't already used
            $payment_id_to_mark = null;
            if (isset($_POST['car_sumit_payment_id']) && !empty($_POST['car_sumit_payment_id'])) {
                $payment_id = sanitize_text_field($_POST['car_sumit_payment_id']);
                $customer_id = isset($_POST['car_sumit_customer_id']) ? sanitize_text_field($_POST['car_sumit_customer_id']) : '';
                
                // Verify payment again (in case someone tries to reuse)
                if (!car_verify_sumit_payment($payment_id, $customer_id, false)) {
                    return '<div class="car-thank-you-wrapper" style="max-width: 600px; margin: 40px auto; padding: 30px; background: #f8f9fa; border-radius: 10px; text-align: right; direction: rtl;">
                        <div class="car-thank-you-message car-error" style="padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;">
                            <h3 style="margin-top: 0; color: #721c24;">שגיאה באימות התשלום</h3>
                            <p style="color: #721c24;">התשלום כבר שימש או לא אומת. אנא פנה לתמיכה.</p>
                        </div>
                    </div>';
                }
                
                // Store payment ID to mark as used after successful registration
                $payment_id_to_mark = $payment_id;
            }
            
            $manual_email = sanitize_email($_POST['car_manual_email']);
            $manual_name = sanitize_text_field($_POST['car_manual_name']);
            
            if (!empty($manual_email) && !empty($manual_name) && is_email($manual_email)) {
                // Process registration with manual data
                $result = car_create_user_from_payment($manual_email, $manual_name);
                
                // If registration successful and we have payment ID, mark it as used
                if (!is_wp_error($result) && $payment_id_to_mark) {
                    car_mark_payment_as_used($payment_id_to_mark);
                }
                
                // Redirect למניעת רענון כפול והצגת מסך התודה (נשאר ב-thank-you שבו יש shortcode)
                $redirect_url = add_query_arg(['registered' => '1'], 'https://tikshuv.chepti.com/thank-you/');
                wp_redirect($redirect_url);
                exit;
            }
        }
        
        // Check if just registered
        if (isset($_GET['registered']) && $_GET['registered'] == '1') {
            $whatsapp_gold = get_option('car_whatsapp_gold', 'https://chat.whatsapp.com/F053eDddoLoIDD2MOPUbCy');
            $whatsapp_silver = get_option('car_whatsapp_silver', 'https://chat.whatsapp.com/CYHeVm1bnXY461koFteTff');
            
            $form_output = '<style>
                @import url("https://fonts.googleapis.com/css2?family=Varela+Round&display=swap");
                .car-success-wrapper {
                    font-family: "Varela Round", sans-serif;
                    max-width: 600px;
                    margin: 40px auto;
                    padding: 0;
                }
                .car-success-container {
                    background: linear-gradient(135deg, #4A90E2 0%, #F7D979 100%);
                    border-radius: 20px;
                    padding: 40px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
                    text-align: right;
                    direction: rtl;
                }
                .car-success-message {
                    background: rgba(255,255,255,0.95);
                    border-radius: 15px;
                    padding: 30px;
                    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
                    text-align: center;
                    margin-bottom: 30px;
                }
                .car-success-title {
                    font-size: 28px;
                    font-weight: bold;
                    color: #4A90E2;
                    margin: 0 0 15px 0;
                }
                .car-success-text {
                    font-size: 18px;
                    color: #333;
                    margin: 0;
                    line-height: 1.6;
                }
                .car-whatsapp-groups {
                    background: rgba(255,255,255,0.95);
                    border-radius: 15px;
                    padding: 30px;
                    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
                }
                .car-whatsapp-title {
                    font-size: 24px;
                    font-weight: bold;
                    color: #4A90E2;
                    text-align: center;
                    margin: 0 0 20px 0;
                }
                .car-whatsapp-buttons {
                    display: grid;
                    gap: 15px;
                }
                .car-whatsapp-btn {
                    display: block;
                    padding: 18px 20px;
                    text-decoration: none;
                    border-radius: 12px;
                    text-align: center;
                    font-weight: bold;
                    font-size: 20px;
                    font-family: "Varela Round", sans-serif;
                    transition: all 0.3s ease;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
                }
                .car-whatsapp-btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(0,0,0,0.25);
                }
                .car-whatsapp-gold {
                    background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
                    color: #000;
                }
                .car-whatsapp-silver {
                    background: linear-gradient(135deg, #C0C0C0 0%, #808080 100%);
                    color: #fff;
                }
            </style>
            
            <div class="car-success-wrapper">
                <div class="car-success-container">
                    <div class="car-success-message">
                        <h2 class="car-success-title">רישום הושלם בהצלחה! 🎉</h2>
                        <p class="car-success-text">חשבון נפתח עבורך בהצלחה. פרטי ההתחברות נשלחו לכתובת האימייל שלך.</p>
                        <p class="car-success-text" style="font-weight:bold;">תודה, מייל נשלח אליך עם פרטי הכניסה.</p>
                    </div>
                    
                    <div class="car-whatsapp-groups">
                        <h3 class="car-whatsapp-title">הצטרף לקבוצות הווטסאפ שלנו!</h3>
                        <div class="car-whatsapp-buttons">
                            <a href="' . esc_url($whatsapp_gold) . '" target="_blank" rel="noopener noreferrer" class="car-whatsapp-btn car-whatsapp-gold">
                                📱 מסלול זהב
                            </a>
                            <a href="' . esc_url($whatsapp_silver) . '" target="_blank" rel="noopener noreferrer" class="car-whatsapp-btn car-whatsapp-silver">
                                📱 מסלול כסף
                            </a>
                        </div>
                    </div>
                </div>
            </div>';
            
            return $debug_output . $form_output;
        }
        
        if ($debug_mode && current_user_can('manage_options')) {
            $form_output .= '<p style="margin: 15px 0 0 0; color: #856404; font-size: 12px;"><strong>למנהל:</strong> Sumit מזוהה. אפשר להגדיר API credentials בהגדרות כדי לקבל את המידע אוטומטית.</p>';
        }
        
        return '<div class="car-thank-you-wrapper" style="max-width: 600px; margin: 40px auto; padding: 30px; background: #f8f9fa; border-radius: 10px; text-align: right; direction: rtl;">' . $debug_output . $form_output . '</div>';
    }
    
    // Process registration
    $result = car_create_user_from_payment($email, $name);
    
    // If registration successful and we have Sumit payment ID, mark it as used
    if (!is_wp_error($result) && !empty($sumit_payment_id) && $payment_verified) {
        car_mark_payment_as_used($sumit_payment_id);
    }
    
    $output = '<div class="car-thank-you-wrapper" style="max-width: 600px; margin: 40px auto; padding: 30px; background: #f8f9fa; border-radius: 10px; text-align: right; direction: rtl;">';
    
    // Add debug output if enabled
    if ($debug_mode && current_user_can('manage_options')) {
        $output .= $debug_output;
    }
    
    if (is_wp_error($result)) {
        // Error occurred
        $output .= '<div class="car-thank-you-message car-error" style="padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; margin-bottom: 20px;">
            <h3 style="margin-top: 0; color: #721c24;">שגיאה</h3>
            <p style="color: #721c24;">' . esc_html($result->get_error_message()) . '</p>
        </div>';
    } else {
        // Success
        $user = $result['user'];
        $is_new_user = $result['is_new'];
        
        $output .= '<div class="car-thank-you-message car-success" style="padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; margin-bottom: 20px;">
            <h2 style="margin-top: 0; color: #155724;">תודה רבה על הרכישה!</h2>';
        
        if ($is_new_user) {
            $output .= '<p style="color: #155724; font-size: 16px;">חשבון נפתח עבורך בהצלחה. פרטי ההתחברות נשלחו לכתובת האימייל שלך.</p>';
        } else {
            $output .= '<p style="color: #155724; font-size: 16px;">הינך מחובר/ת כעת לחשבון שלך. תוכל להתחיל ללמוד מיד!</p>';
        }
        
        $output .= '</div>';
        
        // Add WhatsApp group links
        $whatsapp_gold = get_option('car_whatsapp_gold', 'https://chat.whatsapp.com/F053eDddoLoIDD2MOPUbCy');
        $whatsapp_silver = get_option('car_whatsapp_silver', 'https://chat.whatsapp.com/CYHeVm1bnXY461koFteTff');
        
        $output .= '<div class="car-whatsapp-groups" style="margin-top: 30px; padding: 20px; background: #fff; border-radius: 8px; border: 2px solid #25D366;">
            <h3 style="margin-top: 0; color: #25D366; text-align: center;">הצטרף לקבוצות הווטסאפ שלנו!</h3>
            <div style="display: grid; gap: 15px; margin-top: 20px;">
                <a href="' . esc_url($whatsapp_gold) . '" target="_blank" rel="noopener noreferrer" style="display: block; padding: 15px 20px; background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%); color: #000; text-decoration: none; border-radius: 8px; text-align: center; font-weight: bold; font-size: 18px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: transform 0.2s;">
                    📱 מסלול זהב
                </a>
                <a href="' . esc_url($whatsapp_silver) . '" target="_blank" rel="noopener noreferrer" style="display: block; padding: 15px 20px; background: linear-gradient(135deg, #C0C0C0 0%, #808080 100%); color: #fff; text-decoration: none; border-radius: 8px; text-align: center; font-weight: bold; font-size: 18px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: transform 0.2s;">
                    📱 מסלול כסף
                </a>
            </div>
        </div>';
    }
    
    $output .= '</div>';
    
    return $output;
}
add_shortcode('course_thank_you', 'car_thank_you_shortcode');

// Enqueue JavaScript for affiliate cookie management
function car_enqueue_affiliate_script() {
    if (is_admin()) {
        return;
    }
    
    $script = "
    (function() {
        // Check for ref parameter in URL
        var urlParams = new URLSearchParams(window.location.search);
        var ref = urlParams.get('ref');
        
        if (ref) {
            // Set cookie for 30 days
            var expiryDate = new Date();
            expiryDate.setTime(expiryDate.getTime() + (30 * 24 * 60 * 60 * 1000));
            document.cookie = 'affiliate_ref=' + encodeURIComponent(ref) + '; expires=' + expiryDate.toUTCString() + '; path=/';
        }
    })();
    ";
    
    // Add script inline
    wp_add_inline_script('jquery', $script);
}

// Add affiliate script to footer as well (in case jQuery is not loaded)
function car_add_affiliate_script_footer() {
    if (is_admin()) {
        return;
    }
    
    $script = "
    (function() {
        // Check for ref parameter in URL
        var urlParams = new URLSearchParams(window.location.search);
        var ref = urlParams.get('ref');
        
        if (ref) {
            // Set cookie for 30 days
            var expiryDate = new Date();
            expiryDate.setTime(expiryDate.getTime() + (30 * 24 * 60 * 60 * 1000));
            document.cookie = 'affiliate_ref=' + encodeURIComponent(ref) + '; expires=' + expiryDate.toUTCString() + '; path=/';
        }
    })();
    ";
    
    echo '<script>' . $script . '</script>';
}
add_action('wp_enqueue_scripts', 'car_enqueue_affiliate_script', 20);
add_action('wp_footer', 'car_add_affiliate_script_footer', 20);

// Admin settings page
function car_admin_settings_page() {
    // Handle form submission
    if (isset($_POST['car_save_settings']) && check_admin_referer('car_settings_nonce')) {
        $old_role_name = get_option('car_role_name', CAR_DEFAULT_ROLE);
        $new_role_name = sanitize_text_field($_POST['car_role_name']);
        
        update_option('car_role_name', $new_role_name);
        update_option('car_send_email', isset($_POST['car_send_email']));
        update_option('car_email_subject', sanitize_text_field($_POST['car_email_subject']));
        update_option('car_email_template', wp_kses_post($_POST['car_email_template']));
        update_option('car_whatsapp_gold', esc_url_raw($_POST['car_whatsapp_gold']));
        update_option('car_whatsapp_silver', esc_url_raw($_POST['car_whatsapp_silver']));
        update_option('car_debug_mode', isset($_POST['car_debug_mode']));
        update_option('car_sumit_api_key', sanitize_text_field($_POST['car_sumit_api_key']));
        update_option('car_sumit_api_secret', sanitize_text_field($_POST['car_sumit_api_secret']));
        update_option('car_sumit_business_id', sanitize_text_field($_POST['car_sumit_business_id']));
        
        // If role name changed, create new role
        if ($old_role_name !== $new_role_name) {
            car_create_course_student_role();
        }
        
        // Handle affiliate name updates
        if (isset($_POST['affiliate_names']) && is_array($_POST['affiliate_names'])) {
            $conversions = get_option('car_affiliate_conversions', []);
            foreach ($_POST['affiliate_names'] as $ref => $name) {
                if (isset($conversions[$ref])) {
                    $conversions[$ref]['affiliate_name'] = sanitize_text_field($name);
                }
            }
            update_option('car_affiliate_conversions', $conversions);
        }
        
        echo '<div class="notice notice-success"><p>ההגדרות נשמרו בהצלחה!</p></div>';
    }
    
    // Handle manual role creation
    if (isset($_POST['car_create_role']) && check_admin_referer('car_settings_nonce')) {
        car_create_course_student_role();
        $role_name = get_option('car_role_name', CAR_DEFAULT_ROLE);
        $role_slug = sanitize_key($role_name);
        if (get_role($role_slug)) {
            echo '<div class="notice notice-success"><p>התפקיד נוצר בהצלחה!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>שגיאה ביצירת התפקיד. בדוק שהתוסף Members מותקן אם אתה משתמש בו.</p></div>';
        }
    }
    
    // Handle manual role assignment test
    if (isset($_POST['car_test_role_assignment']) && check_admin_referer('car_settings_nonce')) {
        $test_user_id = isset($_POST['car_test_user_id']) ? intval($_POST['car_test_user_id']) : 0;
        if ($test_user_id > 0) {
            $role_name = get_option('car_role_name', CAR_DEFAULT_ROLE);
            $role_slug = car_get_role_slug($role_name);
            
            // If role not found, try "learner"
            if (!get_role($role_slug) && get_role('learner')) {
                $role_slug = 'learner';
                echo '<div class="notice notice-info"><p>השתמשתי בתפקיד "learner" כי התפקיד "' . esc_html($role_name) . '" לא נמצא</p></div>';
            }
            
            $user_obj = new WP_User($test_user_id);
            if ($user_obj->exists()) {
                // Show current roles before
                $roles_before = $user_obj->roles;
                
                // Use our force assignment function
                $result = car_assign_user_role_force($test_user_id, $role_slug);
                
                // Verify
                $user_obj = new WP_User($test_user_id);
                $final_roles = $user_obj->roles;
                
                if (in_array($role_slug, $final_roles)) {
                    echo '<div class="notice notice-success"><p>✓ התפקיד הוקצה בהצלחה למשתמש ID: ' . $test_user_id . '</p>';
                    echo '<p>תפקידים לפני: ' . (empty($roles_before) ? 'ללא' : implode(', ', $roles_before)) . '</p>';
                    echo '<p>תפקידים אחרי: ' . implode(', ', $final_roles) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>✗ שגיאה בהקצאת התפקיד</p>';
                    echo '<p>תפקידים לפני: ' . (empty($roles_before) ? 'ללא' : implode(', ', $roles_before)) . '</p>';
                    echo '<p>תפקידים אחרי: ' . (empty($final_roles) ? 'ללא' : implode(', ', $final_roles)) . '</p>';
                    echo '<p>תפקיד מבוקש: ' . esc_html($role_slug) . ' (' . esc_html($role_name) . ')</p>';
                    echo '<p>התפקיד קיים במערכת: ' . (get_role($role_slug) ? 'כן' : 'לא') . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>משתמש לא נמצא!</p></div>';
            }
        }
    }
    
    $role_name = get_option('car_role_name', CAR_DEFAULT_ROLE);
    $send_email = get_option('car_send_email', true);
    $email_subject = get_option('car_email_subject', 'ברוכים הבאים לקורס!');
    $email_template = get_option('car_email_template', '');
    $whatsapp_gold = get_option('car_whatsapp_gold', 'https://chat.whatsapp.com/F053eDddoLoIDD2MOPUbCy');
    $whatsapp_silver = get_option('car_whatsapp_silver', 'https://chat.whatsapp.com/CYHeVm1bnXY461koFteTff');
    $conversions = get_option('car_affiliate_conversions', []);
    
    // Enqueue scripts for email editor
    wp_enqueue_script('jquery');
    wp_localize_script('jquery', 'car_email_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('car_test_email_nonce'),
    ]);
    
    // Add ajaxurl for user search
    wp_add_inline_script('jquery', 'var ajaxurl = "' . admin_url('admin-ajax.php') . '";', 'before');
    
    ?>
    <div class="wrap">
        <h1>רישום אוטומטי לקורס</h1>
        
        <form method="post" action="">
            <?php wp_nonce_field('car_settings_nonce'); ?>
            
            <h2>הגדרות כלליות</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="car_role_name">שם התפקיד (Role)</label>
                    </th>
                    <td>
                        <input type="text" id="car_role_name" name="car_role_name" value="<?php echo esc_attr($role_name); ?>" class="regular-text" />
                        <p class="description">התפקיד שיוקצה למשתמשים חדשים (ברירת מחדל: "לומד בקורס")</p>
                        <?php
                        $role_slug = car_get_role_slug($role_name);
                        $role_exists = get_role($role_slug);
                        $all_roles = wp_roles()->get_names();
                        $found_by_name = false;
                        
                        // Check if role exists by name
                        foreach ($all_roles as $slug => $name) {
                            if (strtolower($name) === strtolower($role_name)) {
                                $found_by_name = true;
                                $role_slug = $slug;
                                break;
                            }
                        }
                        
                        if (!$role_exists && !$found_by_name) {
                            echo '<div class="notice notice-warning inline" style="margin: 10px 0; padding: 10px;"><p><strong>⚠️ התפקיד לא קיים!</strong> לחץ על הכפתור למטה כדי ליצור אותו.</p>';
                            echo '<p>Slug שנוצר: <code>' . esc_html($role_slug) . '</code></p></div>';
                        } else {
                            $actual_slug = $found_by_name ? $role_slug : (get_role($role_slug) ? $role_slug : 'לא נמצא');
                            echo '<div class="notice notice-success inline" style="margin: 10px 0; padding: 10px;"><p>✓ התפקיד קיים במערכת</p>';
                            echo '<p>Slug: <code>' . esc_html($actual_slug) . '</code></p></div>';
                        }
                        ?>
                        <p>
                            <button type="submit" name="car_create_role" class="button" style="margin-top: 10px;">
                                יצור תפקיד עכשיו
                            </button>
                            <span class="description" style="margin-right: 10px;">לחץ כאן כדי ליצור את התפקיד במערכת (אם הוא לא קיים)</span>
                        </p>
                        <p style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                            <strong>בדיקת הקצאה ידנית:</strong><br>
                            <div style="margin-top: 10px;">
                                <label style="display: block; margin-bottom: 5px;"><strong>חפש משתמש לפי אימייל או שם:</strong></label>
                                <input type="text" id="car_search_user" placeholder="הזן אימייל או שם משתמש" style="width: 300px; padding: 5px;" />
                                <button type="button" id="car_search_user_btn" class="button" style="margin-right: 10px;">חפש</button>
                                <div id="car_search_results" style="margin-top: 10px; padding: 10px; background: #f0f0f0; border-radius: 5px; display: none;"></div>
                            </div>
                            <div style="margin-top: 15px;">
                                <label style="display: block; margin-bottom: 5px;"><strong>או הזן ID ישירות:</strong></label>
                                <input type="number" name="car_test_user_id" id="car_test_user_id" placeholder="ID משתמש" min="1" style="width: 200px; padding: 5px;" />
                                <button type="submit" name="car_test_role_assignment" class="button" style="margin-right: 10px;">
                                    הקצה תפקיד למשתמש זה
                                </button>
                            </div>
                        </p>
                        <script>
                        jQuery(document).ready(function($) {
                            $('#car_search_user_btn').on('click', function() {
                                var searchTerm = $('#car_search_user').val();
                                if (!searchTerm) {
                                    alert('אנא הזן אימייל או שם משתמש');
                                    return;
                                }
                                
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'car_search_user',
                                        search: searchTerm,
                                        nonce: '<?php echo wp_create_nonce('car_search_user_nonce'); ?>'
                                    },
                                    success: function(response) {
                                        if (response.success && response.data.users.length > 0) {
                                            var html = '<strong>נמצאו משתמשים:</strong><br><ul style="margin: 10px 0; padding-right: 20px;">';
                                            response.data.users.forEach(function(user) {
                                                html += '<li style="margin: 5px 0;">';
                                                html += '<strong>ID:</strong> ' + user.ID + ' | ';
                                                html += '<strong>שם:</strong> ' + user.display_name + ' | ';
                                                html += '<strong>אימייל:</strong> ' + user.user_email + ' | ';
                                                html += '<strong>תפקידים:</strong> ' + (user.roles.length > 0 ? user.roles.join(', ') : 'ללא') + ' ';
                                                html += '<button type="button" class="button button-small" onclick="document.getElementById(\'car_test_user_id\').value=' + user.ID + '">השתמש ב-ID זה</button>';
                                                html += '</li>';
                                            });
                                            html += '</ul>';
                                            $('#car_search_results').html(html).show();
                                        } else {
                                            $('#car_search_results').html('<p style="color: red;">לא נמצאו משתמשים</p>').show();
                                        }
                                    },
                                    error: function() {
                                        $('#car_search_results').html('<p style="color: red;">שגיאה בחיפוש</p>').show();
                                    }
                                });
                            });
                            
                            // Allow Enter key to trigger search
                            $('#car_search_user').on('keypress', function(e) {
                                if (e.which === 13) {
                                    $('#car_search_user_btn').click();
                                }
                            });
                        });
                        </script>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="car_send_email">שליחת מייל</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="car_send_email" name="car_send_email" value="1" <?php checked($send_email, true); ?> />
                            שלח מייל עם פרטי התחברות למשתמשים חדשים
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="car_email_subject">נושא המייל</label>
                    </th>
                    <td>
                        <input type="text" id="car_email_subject" name="car_email_subject" value="<?php echo esc_attr($email_subject); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="car_email_template">תוכן המייל</label>
                    </th>
                    <td>
                        <textarea id="car_email_template" name="car_email_template" rows="15" class="large-text" style="font-family: monospace; direction: rtl;"><?php echo esc_textarea($email_template); ?></textarea>
                        <p class="description">
                            <strong>משתנים זמינים:</strong><br>
                            <code>{display_name}</code> - שם המשתמש<br>
                            <code>{username}</code> - שם המשתמש להתחברות<br>
                            <code>{password}</code> - הסיסמה<br>
                            <code>{login_url}</code> - קישור להתחברות<br>
                            <code>{site_name}</code> - שם האתר<br>
                            <code>{whatsapp_gold}</code> - קישור ווטסאפ מסלול זהב<br>
                            <code>{whatsapp_silver}</code> - קישור ווטסאפ מסלול כסף<br>
                            <code>{logo_url}</code> - קישור ללוגו<br>
                            <br>
                            <strong>הערה:</strong> אם תכתוב HTML, הוא יישלח כפי שהוא. אם תכתוב טקסט רגיל, הוא יועבר לעיצוב HTML אוטומטי עם לוגו ועיצוב יפה.<br>
                            אם השדה ריק, יישלח תוכן ברירת מחדל מעוצב.
                        </p>
                        <p>
                            <button type="button" id="car_test_email_btn" class="button" style="margin-top: 10px;">
                                📧 שלח מייל בדיקה
                            </button>
                            <input type="email" id="car_test_email_input" placeholder="כתובת אימייל לבדיקה" style="margin-right: 10px; padding: 5px;" />
                            <span id="car_test_email_result" style="margin-right: 10px;"></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="car_whatsapp_gold">קישור ווטסאפ - מסלול זהב</label>
                    </th>
                    <td>
                        <input type="url" id="car_whatsapp_gold" name="car_whatsapp_gold" value="<?php echo esc_url($whatsapp_gold); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="car_whatsapp_silver">קישור ווטסאפ - מסלול כסף</label>
                    </th>
                    <td>
                        <input type="url" id="car_whatsapp_silver" name="car_whatsapp_silver" value="<?php echo esc_url($whatsapp_silver); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="car_debug_mode">מצב Debug</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="car_debug_mode" name="car_debug_mode" value="1" <?php checked(get_option('car_debug_mode', false), true); ?> />
                            הפעל מצב Debug (יציג מה מגיע מהתשלום - למנהלים בלבד)
                        </label>
                        <p class="description">מצב זה יעזור לך לראות איזה פרמטרים Sumit שולח. מומלץ להפעיל רק לבדיקה.</p>
                    </td>
                </tr>
            </table>
            
            <h2>הגדרות Sumit (מומלץ מאוד!)</h2>
            <p style="color: #d63638; margin-bottom: 20px; font-weight: bold;">⚠️ <strong>חשוב מאוד:</strong> ללא API credentials, המערכת תשתמש באימות בסיסי בלבד. מומלץ מאוד להגדיר API כדי לאמת תשלומים אמיתיים ולמנוע שימוש חוזר באותו קישור.</p>
            <p style="color: #666; margin-bottom: 20px;">אם יש לך API credentials של Sumit, תוכל להגדיר אותם כאן כדי לקבל את פרטי הלקוח אוטומטית ולאמת תשלומים. אחרת, הלקוח יוזמן למלא את הפרטים ידנית.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="car_sumit_api_key">Sumit API Key</label>
                    </th>
                    <td>
                        <input type="text" id="car_sumit_api_key" name="car_sumit_api_key" value="<?php echo esc_attr(get_option('car_sumit_api_key', '')); ?>" class="regular-text" />
                        <p class="description">API Key מ-Sumit (אם יש)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="car_sumit_business_id">Sumit Business ID</label>
                    </th>
                    <td>
                        <input type="text" id="car_sumit_business_id" name="car_sumit_business_id" value="<?php echo esc_attr(get_option('car_sumit_business_id', '')); ?>" class="regular-text" />
                        <p class="description">Business ID (למשל 1282317371) למוד אימות עם API Key בלבד</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="car_sumit_api_secret">Sumit API Secret</label>
                    </th>
                    <td>
                        <input type="password" id="car_sumit_api_secret" name="car_sumit_api_secret" value="<?php echo esc_attr(get_option('car_sumit_api_secret', '')); ?>" class="regular-text" />
                        <p class="description">API Secret מ-Sumit (אם יש)</p>
                    </td>
                </tr>
            </table>
            
            <script>
            jQuery(document).ready(function($) {
                $('#car_test_email_btn').on('click', function() {
                    var testEmail = $('#car_test_email_input').val();
                    var resultSpan = $('#car_test_email_result');
                    
                    if (!testEmail) {
                        resultSpan.html('<span style="color: red;">יש להזין כתובת אימייל</span>');
                        return;
                    }
                    
                    resultSpan.html('<span style="color: #666;">שולח...</span>');
                    
                    $.ajax({
                        url: car_email_data.ajax_url,
                        type: 'POST',
                        timeout: 15000,
                        data: {
                            action: 'car_send_test_email',
                            nonce: car_email_data.nonce,
                            test_email: testEmail
                        },
                        success: function(response) {
                            try {
                                if (typeof response === 'string') {
                                    response = JSON.parse(response);
                                }
                            } catch (e) {
                                resultSpan.html('<span style="color: red;">✗ שגיאה לא צפויה בתשובת השרת</span>');
                                return;
                            }
                            
                            if (response && response.success) {
                                resultSpan.html('<span style="color: green;">✓ ' + (response.data && response.data.message ? response.data.message : "מייל נשלח") + '</span>');
                            } else {
                                var msg = (response && response.data && response.data.message) ? response.data.message : 'שגיאה בשליחת המייל';
                                resultSpan.html('<span style="color: red;">✗ ' + msg + '</span>');
                            }
                        },
                        error: function() {
                            resultSpan.html('<span style="color: red;">✗ שגיאה בשליחת המייל</span>');
                        },
                        complete: function() {
                            if (resultSpan.text().indexOf('שולח') !== -1) {
                                resultSpan.html('<span style="color: red;">✗ לא התקבלה תגובה מהשרת</span>');
                            }
                        }
                    });
                });
            });
            </script>
            
            <h2>תשלומים שכבר שימשו</h2>
            <?php
            $used_payments = get_option('car_used_payment_ids', []);
            if (!empty($used_payments)): ?>
                <p>מספר תשלומים שכבר שימשו: <strong><?php echo count($used_payments); ?></strong></p>
                <?php if (isset($_POST['car_clear_payments']) && check_admin_referer('car_settings_nonce')): ?>
                    <?php update_option('car_used_payment_ids', []); ?>
                    <div class="notice notice-success"><p>רשימת התשלומים נוקתה!</p></div>
                <?php endif; ?>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('car_settings_nonce'); ?>
                    <button type="submit" name="car_clear_payments" class="button" onclick="return confirm('האם אתה בטוח? זה יאפשר שימוש חוזר בכל התשלומים.');">
                        נקה רשימת תשלומים
                    </button>
                </form>
                <p class="description">רשימה זו מונעת שימוש חוזר באותו קישור תשלום. נקה רק אם אתה בטוח.</p>
            <?php else: ?>
                <p>אין תשלומים שנרשמו עדיין.</p>
            <?php endif; ?>
            
            <h2>מעקב אפילייאט</h2>
            <?php if (!empty($conversions)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>קוד (Ref)</th>
                            <th>שם האפילייאט</th>
                            <th>מספר המרות</th>
                            <th>המרה ראשונה</th>
                            <th>המרה אחרונה</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conversions as $ref => $data): ?>
                            <tr>
                                <td><strong><?php echo esc_html($ref); ?></strong></td>
                                <td>
                                    <input type="text" name="affiliate_names[<?php echo esc_attr($ref); ?>]" value="<?php echo esc_attr($data['affiliate_name']); ?>" class="regular-text" />
                                </td>
                                <td><?php echo esc_html($data['conversions_count']); ?></td>
                                <td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($data['first_conversion_date']))); ?></td>
                                <td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($data['last_conversion_date']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>עדיין אין המרות רשומות.</p>
            <?php endif; ?>
            
            <h2>הוראות שימוש</h2>
            <div style="background: #f0f0f1; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <h3>יצירת דף תודה</h3>
                <ol style="text-align: right; direction: rtl;">
                    <li>צור עמוד חדש ב-WordPress</li>
                    <li>הוסף את ה-shortcode הבא: <code>[course_thank_you]</code></li>
                    <li>פרסם את העמוד</li>
                    <li>הגדר את Sumit להפנות לדף זה עם הפרמטרים: <code>?email=XXX&name=XXX</code></li>
                </ol>
                
                <h3>דוגמאות ללינקים לשותפות</h3>
                <p>כדי לעקוב אחרי אפילייאט, הוסף את הפרמטר <code>?ref=XXX</code> ללינק:</p>
                <ul style="text-align: right; direction: rtl;">
                    <li><code><?php echo esc_url(home_url('/thank-you/?ref=PARTNER1')); ?></code></li>
                    <li><code><?php echo esc_url(home_url('/thank-you/?ref=PARTNER2')); ?></code></li>
                </ul>
                <p>העוגיה תישמר ל-30 יום, כך שגם אם המשתמש יגיע לדף התודה ישירות, המרה תירשם לאפילייאט הנכון.</p>
            </div>
            
            <p class="submit">
                <input type="submit" name="car_save_settings" class="button button-primary" value="שמור הגדרות" />
            </p>
        </form>
    </div>
    <?php
}

// Add admin menu
function car_add_admin_menu() {
    add_options_page(
        'רישום אוטומטי לקורס',
        'Course Registration',
        'manage_options',
        'course-auto-registration',
        'car_admin_settings_page'
    );
}
add_action('admin_menu', 'car_add_admin_menu');

// נקודת בדיקה פשוטה בממשק הציבורי: ?car_email_test=1&to=someone@example.com
function car_simple_email_test_endpoint() {
    if (!isset($_GET['car_email_test'])) {
        return;
    }

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        return;
    }

    $current_user = wp_get_current_user();
    $to_param     = isset($_GET['to']) ? sanitize_email($_GET['to']) : '';
    $to_email     = is_email($to_param) ? $to_param : $current_user->user_email;

    if (!$to_email) {
        return;
    }

    $site_name = get_bloginfo('name');
    $subject   = 'בדיקת מייל פשוטה מהתוסף';

    $body  = "שלום,\n\n";
    $body .= "זהו מייל בדיקה פשוט מהתוסף {$site_name}.\n";
    $body .= "אם קיבלת את המייל הזה, שליחת המיילים דרך CAR עובדת.\n\n";
    $body .= "כתובת יעד: {$to_email}\n";

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $site_name . ' <' . CAR_FROM_EMAIL . '>',
    ];

    error_log('CAR: SIMPLE URL TEST sending to ' . $to_email);
    $result = wp_mail($to_email, $subject, $body, $headers);
    error_log('CAR: SIMPLE URL TEST result: ' . ($result ? 'success' : 'fail'));

    wp_die($result ? 'OK - נשלח מייל בדיקה ל-' . esc_html($to_email) : 'FAIL - לא הצלחנו לשלוח מייל לבדיקה');
}
add_action('init', 'car_simple_email_test_endpoint');
