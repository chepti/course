<?php
if (!defined('ABSPATH')) { exit; }

// 2. AJAX endpoint to get progress
function cpt_get_progress_callback() {
    // רק בדיקה שהמשתמש מחובר + שיש post_id. ויתרנו על nonce בגלל קאשינג בין משתמשים שונים.
    if (!is_user_logged_in() || !isset($_GET['post_id'])) {
        wp_send_json_error(['message' => 'Authentication failed.'], 403);
        return;
    }

    $user_id = get_current_user_id();
    $post_id = intval($_GET['post_id']);

    global $wpdb;
    $table_name = CPT_TABLE_NAME;
    
    $completed_sections = $wpdb->get_col($wpdb->prepare(
        "SELECT section_id FROM $table_name WHERE user_id = %d AND post_id = %d",
        $user_id,
        $post_id
    ));

    wp_send_json_success(['completed_sections' => $completed_sections]);
}
add_action('wp_ajax_cpt_get_progress', 'cpt_get_progress_callback');


// 3. AJAX endpoint to save progress
function cpt_save_progress_callback() {
    // רק בדיקה שהמשתמש מחובר. ויתרנו על nonce בגלל קאשינג בין משתמשים שונים.
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Authentication failed.'], 403);
        return;
    }
    
    if (empty($_POST['post_id']) || empty($_POST['section_id'])) {
        wp_send_json_error(['message' => 'Invalid data.'], 400);
        return;
    }

    $user_id = get_current_user_id();
    $post_id = intval($_POST['post_id']);
    $section_id = sanitize_text_field($_POST['section_id']);

    global $wpdb;
    $table_name = CPT_TABLE_NAME;

    $result = $wpdb->insert(
        $table_name,
        [
            'user_id' => $user_id,
            'post_id' => $post_id,
            'section_id' => $section_id,
            'completed_at' => current_time('mysql'),
        ],
        ['%d', '%d', '%s', '%s']
    );

    if ($result) {
        wp_send_json_success(['message' => 'Progress saved.']);
    } else {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d AND post_id = %d AND section_id = %s",
            $user_id, $post_id, $section_id
        ));
        if ($exists) {
            wp_send_json_success(['message' => 'Progress already marked.']);
        } else {
            wp_send_json_error(['message' => 'Failed to save progress.']);
        }
    }
}
add_action('wp_ajax_cpt_save_progress', 'cpt_save_progress_callback');


// 3.1 AJAX endpoint to track activity
function cpt_track_activity_callback() {
    // Check authentication
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.'], 403);
        return;
    }
    
    if (empty($_POST['post_id']) || empty($_POST['section_id']) || empty($_POST['activity_type'])) {
        wp_send_json_error([
            'message' => 'Invalid data.',
            'received' => [
                'post_id' => isset($_POST['post_id']) ? $_POST['post_id'] : 'missing',
                'section_id' => isset($_POST['section_id']) ? $_POST['section_id'] : 'missing',
                'activity_type' => isset($_POST['activity_type']) ? $_POST['activity_type'] : 'missing',
            ]
        ], 400);
        return;
    }

    $user_id = get_current_user_id();
    $post_id = intval($_POST['post_id']);
    $section_id = sanitize_text_field($_POST['section_id']);
    $activity_type = sanitize_text_field($_POST['activity_type']);
    $activity_data = isset($_POST['activity_data']) ? json_encode($_POST['activity_data']) : null;

    global $wpdb;
    $table_name = CPT_ACTIVITY_TABLE_NAME;
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    if (!$table_exists) {
        wp_send_json_error(['message' => 'Activity table does not exist. Please deactivate and reactivate the plugin.'], 500);
        return;
    }
    
    // Prevent duplicate entries for same activity within 5 minutes
    // This prevents multiple tracking of the same video watch or click
    $recent_duplicate = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name 
         WHERE user_id = %d AND post_id = %d AND section_id = %s AND activity_type = %s 
         AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         LIMIT 1",
        $user_id, $post_id, $section_id, $activity_type
    ));
    
    if ($recent_duplicate) {
        wp_send_json_success(['message' => 'Activity already tracked recently.', 'duplicate' => true]);
        return;
    }

    $result = $wpdb->insert(
        $table_name,
        [
            'user_id' => $user_id,
            'post_id' => $post_id,
            'section_id' => $section_id,
            'activity_type' => $activity_type,
            'activity_data' => $activity_data,
        ],
        ['%d', '%d', '%s', '%s', '%s']
    );

    if ($result) {
        // Check if section should be marked as completed
        cpt_check_and_mark_section_complete($user_id, $post_id, $section_id);
        wp_send_json_success(['message' => 'Activity tracked.']);
    } else {
        $error_msg = $wpdb->last_error ? $wpdb->last_error : 'Database insert failed.';
        wp_send_json_error(['message' => 'Failed to track activity.', 'db_error' => $error_msg]);
    }
}
add_action('wp_ajax_cpt_track_activity', 'cpt_track_activity_callback');


// 3.2 AJAX endpoint to check comment status
function cpt_check_comment_status_callback() {
    // רק בדיקה שהמשתמש מחובר. ויתרנו על nonce בגלל קאשינג בין משתמשים שונים.
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Authentication failed.'], 403);
        return;
    }
    
    if (empty($_GET['post_id'])) {
        wp_send_json_error(['message' => 'Invalid data.'], 400);
        return;
    }

    $user_id = get_current_user_id();
    $post_id = intval($_GET['post_id']);
    $post_id = apply_filters('cpt_comment_check_post_id', $post_id);

    $comments = get_comments([
        'post_id' => $post_id,
        'user_id' => $user_id,
        'count' => true,
    ]);

    wp_send_json_success(['has_comment' => $comments > 0, 'post_id_checked' => $post_id]);
}
add_action('wp_ajax_cpt_check_comment_status', 'cpt_check_comment_status_callback');


// 3.3 AJAX endpoint to save manual check
function cpt_save_manual_check_callback() {
    // Check authentication
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.'], 403);
        return;
    }
    
    if (empty($_POST['post_id']) || empty($_POST['section_id'])) {
        wp_send_json_error([
            'message' => 'Invalid data.',
            'received' => [
                'post_id' => isset($_POST['post_id']) ? $_POST['post_id'] : 'missing',
                'section_id' => isset($_POST['section_id']) ? $_POST['section_id'] : 'missing',
            ]
        ], 400);
        return;
    }

    $user_id = get_current_user_id();
    $post_id = intval($_POST['post_id']);
    $section_id = sanitize_text_field($_POST['section_id']);
    $section_id = apply_filters('cpt_manual_check_section_id', $section_id, $post_id);
    if (empty($section_id)) {
        wp_send_json_error(['message' => 'section_id is required.', 'received' => ['post_id' => $post_id, 'section_id' => $_POST['section_id'] ?? '']], 400);
        return;
    }

    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$activity_table'") == $activity_table;
    if (!$table_exists) {
        wp_send_json_error(['message' => 'Activity table does not exist. Please deactivate and reactivate the plugin.'], 500);
        return;
    }

    // Track as manual check activity
    $result = $wpdb->insert(
        $activity_table,
        [
            'user_id' => $user_id,
            'post_id' => $post_id,
            'section_id' => $section_id,
            'activity_type' => 'manual_check',
            'activity_data' => json_encode(['checked' => true]),
        ],
        ['%d', '%d', '%s', '%s', '%s']
    );

    if ($result) {
        cpt_check_and_mark_section_complete($user_id, $post_id, $section_id);
        wp_send_json_success([
            'message' => 'Manual check saved.',
            'saved' => ['post_id' => $post_id, 'section_id' => $section_id],
        ]);
    } else {
        $error_msg = $wpdb->last_error ? $wpdb->last_error : 'Database insert failed.';
        wp_send_json_error([
            'message' => 'Failed to save manual check.',
            'db_error' => $error_msg,
            'received' => ['post_id' => $post_id, 'section_id' => $section_id],
        ]);
    }
}
add_action('wp_ajax_cpt_save_manual_check', 'cpt_save_manual_check_callback');


// 3.4 Helper function to check and mark section as complete
// 3.6 AJAX endpoint to get activity progress
function cpt_get_activity_progress_callback() {
    // רק בדיקה שהמשתמש מחובר + שיש post_id. ויתרנו על nonce בגלל קאשינג בין משתמשים שונים.
    if (!is_user_logged_in() || !isset($_GET['post_id'])) {
        wp_send_json_error(['message' => 'Authentication failed.'], 403);
        return;
    }

    $user_id = get_current_user_id();
    $post_id = intval($_GET['post_id']);
    $section_id = isset($_GET['section_id']) ? sanitize_text_field($_GET['section_id']) : null;

    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;
    
    $where = $wpdb->prepare("user_id = %d AND post_id = %d", $user_id, $post_id);
    if ($section_id) {
        $where .= $wpdb->prepare(" AND section_id = %s", $section_id);
    }
    
    $activities = $wpdb->get_results("SELECT section_id, activity_type, activity_data, created_at FROM $activity_table WHERE $where ORDER BY created_at DESC");
    
    // Group by section
    $progress_by_section = [];
    foreach ($activities as $activity) {
        $sec_id = $activity->section_id;
        if (!isset($progress_by_section[$sec_id])) {
            $progress_by_section[$sec_id] = [];
        }
        $progress_by_section[$sec_id][] = [
            'type' => $activity->activity_type,
            'data' => json_decode($activity->activity_data, true),
            'created_at' => $activity->created_at,
        ];
    }
    
    // Calculate progress for each section
    $result = [];
    foreach ($progress_by_section as $sec_id => $sec_activities) {
        // Convert activities array to format expected by cpt_calculate_section_progress
        $section_activities = [];
        foreach ($activities as $activity) {
            if ($activity->section_id === $sec_id) {
                $section_activities[] = (object)[
                    'activity_type' => $activity->activity_type,
                    'activity_data' => $activity->activity_data,
                ];
            }
        }
        $result[$sec_id] = [
            'activities' => $sec_activities,
            'progress' => cpt_calculate_section_progress($post_id, $sec_id, $section_activities),
        ];
    }

    wp_send_json_success(['progress' => $result]);
}
add_action('wp_ajax_cpt_get_activity_progress', 'cpt_get_activity_progress_callback');


// 3.7 AJAX endpoint to save last position
function cpt_save_last_position_callback() {
    // רק בדיקה שהמשתמש מחובר. ויתרנו על nonce בגלל קאשינג בין משתמשים שונים.
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Authentication failed.'], 403);
        return;
    }
    
    if (empty($_POST['post_id']) || empty($_POST['section_id'])) {
        wp_send_json_error(['message' => 'Invalid data.'], 400);
        return;
    }

    $user_id = get_current_user_id();
    $post_id = intval($_POST['post_id']);
    $section_id = sanitize_text_field($_POST['section_id']);

    global $wpdb;
    $table_name = CPT_LAST_POSITION_TABLE_NAME;
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    if (!$table_exists) {
        wp_send_json_error(['message' => 'Last position table does not exist. Please deactivate and reactivate the plugin.'], 500);
        return;
    }

    // Insert or update last position (ON DUPLICATE KEY UPDATE)
    $result = $wpdb->query($wpdb->prepare(
        "INSERT INTO $table_name (user_id, post_id, section_id, updated_at) 
         VALUES (%d, %d, %s, NOW())
         ON DUPLICATE KEY UPDATE section_id = %s, updated_at = NOW()",
        $user_id, $post_id, $section_id, $section_id
    ));

    if ($result !== false) {
        wp_send_json_success(['message' => 'Last position saved.']);
    } else {
        wp_send_json_error(['message' => 'Failed to save last position.', 'db_error' => $wpdb->last_error]);
    }
}
add_action('wp_ajax_cpt_save_last_position', 'cpt_save_last_position_callback');


// 3.8 AJAX endpoint to get last position (for current unit or all units)
function cpt_get_last_position_callback() {
    // רק בדיקה שהמשתמש מחובר. ויתרנו על nonce בגלל קאשינג בין משתמשים שונים.
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Authentication failed.'], 403);
        return;
    }

    $user_id = get_current_user_id();
    $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
    $all_units = isset($_GET['all_units']) && $_GET['all_units'] === 'true';

    global $wpdb;
    $table_name = CPT_LAST_POSITION_TABLE_NAME;
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    if (!$table_exists) {
        wp_send_json_success(['section_id' => null, 'message' => 'No last position saved.']);
        return;
    }

    if ($all_units) {
        // Get last position from all units (most recent)
        $last_position = $wpdb->get_row($wpdb->prepare(
            "SELECT post_id, section_id, updated_at FROM $table_name 
             WHERE user_id = %d 
             ORDER BY updated_at DESC 
             LIMIT 1",
            $user_id
        ));

        if ($last_position) {
            $post_title = get_the_title($last_position->post_id);
            $post_title = preg_replace('/^פרטי:\s*/', '', $post_title);
            $post_url = get_permalink($last_position->post_id);
            
            wp_send_json_success([
                'post_id' => $last_position->post_id,
                'post_title' => $post_title,
                'post_url' => $post_url,
                'section_id' => $last_position->section_id,
                'updated_at' => $last_position->updated_at
            ]);
        } else {
            wp_send_json_success(['section_id' => null, 'message' => 'No last position saved.']);
        }
    } else {
        // Get last position for specific unit
        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post_id.'], 400);
            return;
        }

        $last_position = $wpdb->get_row($wpdb->prepare(
            "SELECT section_id, updated_at FROM $table_name WHERE user_id = %d AND post_id = %d",
            $user_id, $post_id
        ));

        if ($last_position) {
            wp_send_json_success([
                'section_id' => $last_position->section_id,
                'updated_at' => $last_position->updated_at
            ]);
        } else {
            wp_send_json_success(['section_id' => null, 'message' => 'No last position saved.']);
        }
    }
}
add_action('wp_ajax_cpt_get_last_position', 'cpt_get_last_position_callback');
