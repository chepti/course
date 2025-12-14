<?php
/**
 * Plugin Name: Course Progress Tracker
 * Description: A lightweight, custom plugin to track user progress through HTML-based course units.
 * Version: 2.8.1
 * Author: Chepti
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define table names
global $wpdb;
define('CPT_TABLE_NAME', $wpdb->prefix . 'course_progress');
define('CPT_ACTIVITY_TABLE_NAME', $wpdb->prefix . 'course_activity');
define('CPT_LAST_POSITION_TABLE_NAME', $wpdb->prefix . 'course_last_position');

// Define constants for course auto registration
define('CAR_DEFAULT_ROLE', 'לומד בקורס');
define('CAR_AFFILIATE_COOKIE_NAME', 'affiliate_ref');
define('CAR_AFFILIATE_COOKIE_EXPIRY', 30); // days

// 1. Database table creation on plugin activation
function cpt_activate() {
    global $wpdb;
    $table_name = CPT_TABLE_NAME;
    $activity_table_name = CPT_ACTIVITY_TABLE_NAME;
    $last_position_table_name = CPT_LAST_POSITION_TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();

    // Main progress table (existing)
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        post_id bigint(20) NOT NULL,
        section_id varchar(255) NOT NULL,
        completed_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_progress (user_id, post_id, section_id)
    ) $charset_collate;";

    // Activity tracking table (new)
    $sql_activity = "CREATE TABLE $activity_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        post_id bigint(20) NOT NULL,
        section_id varchar(255) NOT NULL,
        activity_type varchar(50) NOT NULL,
        activity_data longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY user_post_section (user_id, post_id, section_id),
        KEY activity_type (activity_type)
    ) $charset_collate;";

    // Last position table (new - for resume functionality)
    $sql_last_position = "CREATE TABLE $last_position_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        post_id bigint(20) NOT NULL,
        section_id varchar(255) NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_post (user_id, post_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    dbDelta($sql_activity);
    dbDelta($sql_last_position);
    
    // Create course student role if it doesn't exist
    car_create_course_student_role();
    
    // Initialize default settings
    car_init_default_settings();
}
register_activation_hook(__FILE__, 'cpt_activate');


// 2. AJAX endpoint to get progress
function cpt_get_progress_callback() {
    if (!is_user_logged_in() || !isset($_GET['post_id']) || !check_ajax_referer('cpt_progress_nonce', 'nonce', false)) {
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
    if (!is_user_logged_in() || !check_ajax_referer('cpt_progress_nonce', 'nonce', false)) {
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
    
    // Check nonce
    if (!check_ajax_referer('cpt_progress_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
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
    if (!is_user_logged_in() || !check_ajax_referer('cpt_progress_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Authentication failed.'], 403);
        return;
    }
    
    if (empty($_GET['post_id'])) {
        wp_send_json_error(['message' => 'Invalid data.'], 400);
        return;
    }

    $user_id = get_current_user_id();
    $post_id = intval($_GET['post_id']);

    $comments = get_comments([
        'post_id' => $post_id,
        'user_id' => $user_id,
        'count' => true,
    ]);

    wp_send_json_success(['has_comment' => $comments > 0]);
}
add_action('wp_ajax_cpt_check_comment_status', 'cpt_check_comment_status_callback');


// 3.3 AJAX endpoint to save manual check
function cpt_save_manual_check_callback() {
    // Check authentication
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.'], 403);
        return;
    }
    
    // Check nonce
    if (!check_ajax_referer('cpt_progress_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
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
        // Mark section as completed
        cpt_check_and_mark_section_complete($user_id, $post_id, $section_id);
        wp_send_json_success(['message' => 'Manual check saved.']);
    } else {
        $error_msg = $wpdb->last_error ? $wpdb->last_error : 'Database insert failed.';
        wp_send_json_error(['message' => 'Failed to save manual check.', 'db_error' => $error_msg]);
    }
}
add_action('wp_ajax_cpt_save_manual_check', 'cpt_save_manual_check_callback');


// 3.4 Helper function to check and mark section as complete
function cpt_check_and_mark_section_complete($user_id, $post_id, $section_id) {
    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;
    $progress_table = CPT_TABLE_NAME;

    // Get all activities for this section
    $activities = $wpdb->get_results($wpdb->prepare(
        "SELECT activity_type, activity_data FROM $activity_table 
         WHERE user_id = %d AND post_id = %d AND section_id = %s",
        $user_id, $post_id, $section_id
    ));

    // Calculate progress based on section type
    $progress = cpt_calculate_section_progress($post_id, $section_id, $activities);
    
    // If progress is 100%, mark section as completed
    if ($progress >= 100) {
        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $progress_table WHERE user_id = %d AND post_id = %d AND section_id = %s",
            $user_id, $post_id, $section_id
        ));
        
        if (!$exists) {
            $wpdb->insert(
                $progress_table,
                [
                    'user_id' => $user_id,
                    'post_id' => $post_id,
                    'section_id' => $section_id,
                    'completed_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%s']
            );
        }
    }
}


// 3.5 Calculate section progress based on activities
function cpt_calculate_section_progress($post_id, $section_id, $activities) {
    if (empty($activities)) {
        return 0;
    }
    
    // Group activities by type
    $activity_by_type = [];
    foreach ($activities as $activity) {
        $type = $activity->activity_type;
        if (!isset($activity_by_type[$type])) {
            $activity_by_type[$type] = [];
        }
        $activity_data = is_string($activity->activity_data) ? json_decode($activity->activity_data, true) : $activity->activity_data;
        $activity_by_type[$type][] = $activity_data;
    }

    // Determine section requirements based on section_id pattern
    // "overview" sections: require 1 video watch (50%+)
    if (strpos($section_id, 'overview') !== false || $section_id === 'overview') {
        $video_watches = isset($activity_by_type['video_watch']) ? count($activity_by_type['video_watch']) : 0;
        return min(100, ($video_watches / 1) * 100);
    }
    
    // "tools_demo" or "tools_intermediaries" sections: require 4 video watches (50%+ each)
    if (strpos($section_id, 'tools_demo') !== false || strpos($section_id, 'tools_intermediaries') !== false) {
        $video_watches = isset($activity_by_type['video_watch']) ? count($activity_by_type['video_watch']) : 0;
        return min(100, ($video_watches / 4) * 100);
    }
    
    // Other "tools" sections: count videos watched
    if (strpos($section_id, 'tools') !== false) {
        $video_watches = isset($activity_by_type['video_watch']) ? count($activity_by_type['video_watch']) : 0;
        // For tools sections with multiple videos, require at least some engagement
        if ($video_watches > 0) {
            return min(100, ($video_watches * 25)); // 25% per video, max 100%
        }
        return 0;
    }
    
    // "discussion" sections: require 1 comment
    if (strpos($section_id, 'discussion') !== false || $section_id === 'discussion') {
        $has_comment = isset($activity_by_type['comment']) && count($activity_by_type['comment']) > 0;
        return $has_comment ? 100 : 0;
    }
    
    // "task" or "assignment" sections: require manual check
    if (strpos($section_id, 'task') !== false || strpos($section_id, 'assignment') !== false || $section_id === 'task' || $section_id === 'assignment') {
        $has_check = isset($activity_by_type['manual_check']) && count($activity_by_type['manual_check']) > 0;
        return $has_check ? 100 : 0;
    }
    
    // Default: count any activity as progress
    $total_activities = count($activities);
    return min(100, $total_activities * 20); // 20% per activity, max 100%
}


// 3.6 AJAX endpoint to get activity progress
function cpt_get_activity_progress_callback() {
    if (!is_user_logged_in() || !isset($_GET['post_id']) || !check_ajax_referer('cpt_progress_nonce', 'nonce', false)) {
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
    if (!is_user_logged_in() || !check_ajax_referer('cpt_progress_nonce', 'nonce', false)) {
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
    if (!is_user_logged_in() || !check_ajax_referer('cpt_progress_nonce', 'nonce', false)) {
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


// 4. Helper function to render progress bars
function cpt_render_progress_bar($percentage) {
    // Gradient from #f7d979 to #3a757f
    $gradient = 'linear-gradient(to left, #f7d979, #3a757f)';
    
    return sprintf(
        '<div style="background-color: #e9ecef; border-radius: 5px; overflow: hidden; width: 100%%; height: 22px; direction: ltr;">
            <div style="background: %s; width: %d%%; height: 100%%; text-align: center; color: white; line-height: 22px; font-weight: bold; font-size: 12px;">
                %d%%
            </div>
        </div>',
        $gradient,
        $percentage,
        round($percentage)
    );
}

// 5. Shortcode to display user progress (enhanced)
function cpt_progress_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>יש להתחבר כדי לראות את ההתקדמות.</p>';
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;

    // Get all units with activity (only units the user has interacted with)
    $all_units = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT post_id FROM $activity_table WHERE user_id = %d ORDER BY post_id ASC",
        $user_id
    ));
    
    if (empty($all_units)) {
        return '<p>עוד לא התחלת... קדימה!</p>';
    }

    // Filter out "פרק בדיקה" and sort by unit number
    $filtered_units = [];
    foreach ($all_units as $unit) {
        $post_title = get_the_title($unit->post_id);
        // Skip "פרק בדיקה" - but be more specific to avoid filtering units with "בדיקה" in title
        if (strpos($post_title, 'פרק בדיקה') !== false || 
            (strpos($post_title, 'בדיקה') !== false && strpos($post_title, 'יחידה') === false)) {
            continue;
        }
        // Extract unit number from title (e.g., "יחידה 1", "יחידה 2", etc.)
        $unit_number = 999; // Default for units without number
        if (preg_match('/יחידה\s*(\d+)/', $post_title, $matches)) {
            $unit_number = intval($matches[1]);
        }
        $filtered_units[] = [
            'post_id' => $unit->post_id,
            'unit_number' => $unit_number
        ];
    }
    
    // Sort by unit number
    usort($filtered_units, function($a, $b) {
        return $a['unit_number'] <=> $b['unit_number'];
    });
    
    if (empty($filtered_units)) {
        return '<p>עוד לא התחלת... קדימה!</p>';
    }

    $output = '<h3>ההתקדמות שלי בקורס</h3><div class="cpt-progress-wrapper">';
    
    foreach ($filtered_units as $unit_data) {
        $unit = (object)['post_id' => $unit_data['post_id']];
        $post_id = $unit->post_id;
        $post_title = get_the_title($post_id);
        // Remove "פרטי:" prefix if exists
        $post_title = preg_replace('/^פרטי:\s*/', '', $post_title);
        $post_url = get_permalink($post_id);
        
        // Get all sections for this unit from activity table (only sections with activity)
        $all_sections = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT section_id FROM $activity_table WHERE user_id = %d AND post_id = %d",
            $user_id, $post_id
        ));
        
        if (empty($all_sections)) {
            continue; // Skip units with no sections
        }
        
        // Calculate progress per section using activity data
        $section_progress = [];
        foreach ($all_sections as $section_id) {
            $section_activities = $wpdb->get_results($wpdb->prepare(
                "SELECT activity_type, activity_data FROM $activity_table 
                 WHERE user_id = %d AND post_id = %d AND section_id = %s",
                $user_id, $post_id, $section_id
            ));
            $section_progress[$section_id] = cpt_calculate_section_progress($post_id, $section_id, $section_activities);
        }
        
        // Calculate overall percentage
        // Each unit has exactly 4 main sections: overview/intro, tools, discussion, task/assignment
        // Each section is worth 25%
        // Note: task and assignment are the same - count only once
        // Note: intro is treated as overview (some units use 'intro' instead of 'overview')
        $main_sections = ['overview', 'tools', 'discussion'];
        $total_progress = 0;
        
        foreach ($main_sections as $main_section) {
            // Check if this main section has any activity
            $section_progress_value = 0;
            foreach ($section_progress as $section_id => $progress) {
                // Check if section_id matches the main section (including sub-sections)
                // For overview, also check for 'intro' (used in some units)
                if ($main_section === 'overview') {
                    if ($section_id === $main_section || $section_id === 'intro' || strpos($section_id, $main_section) === 0 || strpos($section_id, 'intro') === 0) {
                        if ($progress > $section_progress_value) {
                            $section_progress_value = $progress;
                        }
                    }
                } else {
                    if ($section_id === $main_section || strpos($section_id, $main_section) === 0) {
                        // Use the highest progress for this main section
                        if ($progress > $section_progress_value) {
                            $section_progress_value = $progress;
                        }
                    }
                }
            }
            // Each main section contributes 25% (0-100% of that 25%)
            $total_progress += ($section_progress_value / 100) * 25;
        }
        
        // Handle task/assignment - count only once
        $task_progress_value = 0;
        foreach ($section_progress as $section_id => $progress) {
            if ($section_id === 'task' || $section_id === 'assignment' || 
                strpos($section_id, 'task') === 0 || strpos($section_id, 'assignment') === 0) {
                if ($progress > $task_progress_value) {
                    $task_progress_value = $progress;
                }
            }
        }
        $total_progress += ($task_progress_value / 100) * 25;
        
        // Round to avoid floating point issues
        $overall_percentage = round($total_progress); // Already calculated as percentage (0-100)
        
        // Determine color based on progress
        $color_class = 'progress-low';
        if ($overall_percentage >= 80) {
            $color_class = 'progress-high';
        } elseif ($overall_percentage >= 50) {
            $color_class = 'progress-medium';
        }
        
        $output .= '<div class="cpt-progress-item ' . $color_class . '">';
        $output .= '<h4><a href="' . esc_url($post_url) . '">' . esc_html($post_title) . '</a></h4>';
        $output .= cpt_render_progress_bar($overall_percentage);
        // Add colored progress line
        $progress_color = $overall_percentage >= 80 ? '#27ae60' : ($overall_percentage >= 50 ? '#f39c12' : '#e74c3c');
        $output .= '<div class="cpt-progress-line" style="background: linear-gradient(to right, ' . $progress_color . ' ' . $overall_percentage . '%, #e0e0e0 ' . $overall_percentage . '%);"></div>';
        
        // Section breakdown - show only main sections with Hebrew names
        // Note: task and assignment are the same - show only one
        // Note: intro is treated as overview (some units use 'intro' instead of 'overview')
        $main_sections = ['overview', 'tools', 'discussion'];
        $section_names_he = [
            'overview' => 'מבט על',
            'intro' => 'מבט על',
            'tools' => 'כלים',
            'discussion' => 'דיון',
            'task' => 'משימה',
            'assignment' => 'משימה'
        ];
        
        $output .= '<div class="cpt-section-breakdown">';
        foreach ($main_sections as $main_section) {
            $progress_value = 0;
            foreach ($section_progress as $section_id => $progress) {
                // Skip task/assignment sections - they will be handled separately
                if ($section_id === 'task' || $section_id === 'assignment' || 
                    strpos($section_id, 'task') === 0 || strpos($section_id, 'assignment') === 0) {
                    continue;
                }
                // For overview, also check for 'intro' (used in some units)
                if ($main_section === 'overview') {
                    if ($section_id === $main_section || $section_id === 'intro' || strpos($section_id, $main_section) === 0 || strpos($section_id, 'intro') === 0) {
                        if ($progress > $progress_value) {
                            $progress_value = $progress;
                        }
                    }
                } else {
                    if ($section_id === $main_section || strpos($section_id, $main_section) === 0) {
                        if ($progress > $progress_value) {
                            $progress_value = $progress;
                        }
                    }
                }
            }
            $icon = $progress_value >= 100 ? '✓' : ($progress_value > 0 ? '◐' : '○');
            $section_name_he = isset($section_names_he[$main_section]) ? $section_names_he[$main_section] : $main_section;
            $section_color = $progress_value >= 80 ? '#27ae60' : ($progress_value >= 50 ? '#f39c12' : '#e74c3c');
            $output .= '<div class="cpt-section-item" style="--section-progress: ' . $progress_value . '%; --section-color: ' . $section_color . ';">';
            $output .= '<span class="cpt-section-icon">' . $icon . '</span>';
            $output .= '<span class="cpt-section-name">' . esc_html($section_name_he) . '</span>';
            $output .= '<span class="cpt-section-progress">' . round($progress_value) . '%</span>';
            $output .= '<div class="cpt-section-progress-line" style="position: absolute; bottom: -1px; right: 0; height: 2px; width: ' . $progress_value . '%; background: ' . $section_color . '; border-radius: 1px;"></div>';
            $output .= '</div>';
        }
        
        // Handle task/assignment - show only once
        $task_progress = 0;
        foreach ($section_progress as $section_id => $progress) {
            if ($section_id === 'task' || $section_id === 'assignment' || 
                strpos($section_id, 'task') === 0 || strpos($section_id, 'assignment') === 0) {
                if ($progress > $task_progress) {
                    $task_progress = $progress;
                }
            }
        }
        $icon = $task_progress >= 100 ? '✓' : ($task_progress > 0 ? '◐' : '○');
        $task_color = $task_progress >= 80 ? '#27ae60' : ($task_progress >= 50 ? '#f39c12' : '#e74c3c');
        $output .= '<div class="cpt-section-item" style="--section-progress: ' . $task_progress . '%; --section-color: ' . $task_color . ';">';
        $output .= '<span class="cpt-section-icon">' . $icon . '</span>';
        $output .= '<span class="cpt-section-name">משימה</span>';
        $output .= '<span class="cpt-section-progress">' . round($task_progress) . '%</span>';
        $output .= '<div class="cpt-section-progress-line" style="position: absolute; bottom: -1px; right: 0; height: 2px; width: ' . $task_progress . '%; background: ' . $task_color . '; border-radius: 1px;"></div>';
        $output .= '</div>';
        
        $output .= '</div>';
        
        $output .= '</div>';
    }
    $output .= '</div>';

    $output .= '<style>
        .cpt-progress-wrapper { display: grid; gap: 20px; margin-top: 20px; }
        .cpt-progress-item { padding: 20px; background: #f8f9fa; border-radius: 8px; border-right: 4px solid #ddd; position: relative; }
        .cpt-progress-item.progress-high { border-right-color: #27ae60; }
        .cpt-progress-item.progress-medium { border-right-color: #f39c12; }
        .cpt-progress-item.progress-low { border-right-color: #e74c3c; }
        .cpt-progress-item h4 { margin-top: 0; margin-bottom: 15px; }
        .cpt-progress-item h4 a { text-decoration: none; color: inherit; }
        .cpt-progress-line { height: 3px; width: 100%; margin-top: 10px; border-radius: 2px; }
        .cpt-section-breakdown { margin-top: 15px; display: grid; gap: 8px; }
        .cpt-section-item { display: flex; align-items: center; gap: 10px; padding: 5px 0; border-bottom: 1px solid #e0e0e0; position: relative; }
        .cpt-section-item::after { content: ""; position: absolute; bottom: -1px; right: 0; height: 2px; width: 0%; transition: width 0.3s ease; border-radius: 1px; }
        .cpt-section-icon { font-weight: bold; width: 20px; text-align: center; }
        .cpt-section-name { flex: 1; }
        .cpt-section-progress { font-size: 0.9em; color: #666; }
    </style>';

    return $output;
}
add_shortcode('user_course_progress', 'cpt_progress_shortcode');

// Shortcode for specific unit progress
function cpt_unit_progress_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p>יש להתחבר כדי לראות את ההתקדמות.</p>';
    }
    
    $atts = shortcode_atts([
        'unit_id' => 0, // Post ID of the unit
    ], $atts);
    
    if (empty($atts['unit_id'])) {
        return '<p>יש לציין את מזהה היחידה (unit_id).</p>';
    }
    
    $user_id = get_current_user_id();
    $post_id = intval($atts['unit_id']);
    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;
    
    // Get all sections for this unit
    $all_sections = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT section_id FROM $activity_table WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ));
    
    if (empty($all_sections)) {
        return '<p>עוד לא התחלת יחידה זו.</p>';
    }
    
    // Calculate progress per section
    $section_progress = [];
    foreach ($all_sections as $section_id) {
        $section_activities = $wpdb->get_results($wpdb->prepare(
            "SELECT activity_type, activity_data FROM $activity_table 
             WHERE user_id = %d AND post_id = %d AND section_id = %s",
            $user_id, $post_id, $section_id
        ));
        $section_progress[$section_id] = cpt_calculate_section_progress($post_id, $section_id, $section_activities);
    }
    
    // Calculate overall percentage (each main section = 25%)
    // Note: task and assignment are the same - count only once
    // Note: intro is treated as overview (some units use 'intro' instead of 'overview')
    $main_sections = ['overview', 'tools', 'discussion'];
    $total_progress = 0;
    
    foreach ($main_sections as $main_section) {
        $section_progress_value = 0;
        foreach ($section_progress as $section_id => $progress) {
            // Match exact or starts with
            // For overview, also check for 'intro' (used in some units)
            if ($main_section === 'overview') {
                if ($section_id === $main_section || $section_id === 'intro' || strpos($section_id, $main_section) === 0 || strpos($section_id, 'intro') === 0) {
                    if ($progress > $section_progress_value) {
                        $section_progress_value = $progress;
                    }
                }
            } else {
                if ($section_id === $main_section || strpos($section_id, $main_section) === 0) {
                    if ($progress > $section_progress_value) {
                        $section_progress_value = $progress;
                    }
                }
            }
        }
        $total_progress += ($section_progress_value / 100) * 25;
    }
    
    // Handle task/assignment - count only once
    $task_progress_value = 0;
    foreach ($section_progress as $section_id => $progress) {
        if ($section_id === 'task' || $section_id === 'assignment' || 
            strpos($section_id, 'task') === 0 || strpos($section_id, 'assignment') === 0) {
            if ($progress > $task_progress_value) {
                $task_progress_value = $progress;
            }
        }
    }
    $total_progress += ($task_progress_value / 100) * 25;
    
    // Round to avoid floating point issues
    $total_progress = round($total_progress);
    
    $post_title = get_the_title($post_id);
    $post_url = get_permalink($post_id);
    
    // Hebrew section names mapping
    $section_names_he = [
        'overview' => 'מבט על',
        'intro' => 'מבט על',
        'tools' => 'כלים',
        'discussion' => 'דיון',
        'task' => 'משימה',
        'assignment' => 'משימה'
    ];
    
    $color_class = 'progress-low';
    if ($total_progress >= 80) {
        $color_class = 'progress-high';
    } elseif ($total_progress >= 50) {
        $color_class = 'progress-medium';
    }
    
    $post_title_clean = preg_replace('/^פרטי:\s*/', '', $post_title);
    
    $output = '<div class="cpt-progress-item ' . $color_class . '">';
    $output .= '<h3>ההתקדמות שלי ביחידה: ' . esc_html($post_title_clean) . '</h3>';
    $output .= cpt_render_progress_bar(round($total_progress));
    
    // Section breakdown - show only main sections with Hebrew names
    // Note: task and assignment are the same - show only one
    // Note: intro is treated as overview (some units use 'intro' instead of 'overview')
    $output .= '<div class="cpt-section-breakdown">';
    foreach ($main_sections as $main_section) {
        $progress_value = 0;
        foreach ($section_progress as $section_id => $progress) {
            // Skip task/assignment sections - they will be handled separately
            if ($section_id === 'task' || $section_id === 'assignment' || 
                strpos($section_id, 'task') === 0 || strpos($section_id, 'assignment') === 0) {
                continue;
            }
            // For overview, also check for 'intro' (used in some units)
            if ($main_section === 'overview') {
                if ($section_id === $main_section || $section_id === 'intro' || strpos($section_id, $main_section) === 0 || strpos($section_id, 'intro') === 0) {
                    if ($progress > $progress_value) {
                        $progress_value = $progress;
                    }
                }
            } else {
                if ($section_id === $main_section || strpos($section_id, $main_section) === 0) {
                    if ($progress > $progress_value) {
                        $progress_value = $progress;
                    }
                }
            }
        }
        $icon = $progress_value >= 100 ? '✓' : ($progress_value > 0 ? '◐' : '○');
        $section_name_he = isset($section_names_he[$main_section]) ? $section_names_he[$main_section] : $main_section;
        $output .= '<div class="cpt-section-item">';
        $output .= '<span class="cpt-section-icon">' . $icon . '</span>';
        $output .= '<span class="cpt-section-name">' . esc_html($section_name_he) . '</span>';
        $output .= '<span class="cpt-section-progress">' . round($progress_value) . '%</span>';
        $output .= '</div>';
    }
    
    // Handle task/assignment - show only once
    $icon = $task_progress_value >= 100 ? '✓' : ($task_progress_value > 0 ? '◐' : '○');
    $output .= '<div class="cpt-section-item">';
    $output .= '<span class="cpt-section-icon">' . $icon . '</span>';
    $output .= '<span class="cpt-section-name">משימה</span>';
    $output .= '<span class="cpt-section-progress">' . round($task_progress_value) . '%</span>';
    $output .= '</div>';
    
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<style>
        .cpt-progress-item { padding: 20px; background: #f8f9fa; border-radius: 8px; border-right: 4px solid #4A90E2; margin-bottom: 20px; }
        .cpt-progress-item.progress-high { border-right-color: #27ae60; }
        .cpt-progress-item.progress-medium { border-right-color: #f39c12; }
        .cpt-progress-item.progress-low { border-right-color: #4A90E2; }
        .cpt-progress-item h3 { margin-top: 0; margin-bottom: 15px; font-size: 1.3em; }
        .cpt-progress-item h3 a { text-decoration: none; color: inherit; }
        .cpt-section-breakdown { margin-top: 15px; display: grid; gap: 8px; }
        .cpt-section-item { display: flex; align-items: center; gap: 10px; padding: 5px 0; }
        .cpt-section-icon { font-weight: bold; width: 20px; text-align: center; }
        .cpt-section-name { flex: 1; }
        .cpt-section-progress { font-size: 0.9em; color: #666; }
    </style>';
    
    return $output;
}
add_shortcode('unit_progress', 'cpt_unit_progress_shortcode');

// Add admin bar menu item
function cpt_add_admin_bar_menu($wp_admin_bar) {
    if (!is_user_logged_in()) {
        return;
    }
    
    // Add main menu item
    $wp_admin_bar->add_menu([
        'id' => 'cpt-my-progress',
        'title' => '📊 ההתקדמות שלי',
        'href' => '#',
        'meta' => [
            'class' => 'cpt-progress-menu'
        ]
    ]);
    
    // Get user's progress summary
    $user_id = get_current_user_id();
    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;
    
    $all_units = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT post_id FROM $activity_table WHERE user_id = %d ORDER BY post_id ASC",
        $user_id
    ));
    
    // Filter out "פרק בדיקה" and sort by unit number
    $filtered_units = [];
    foreach ($all_units as $unit) {
        $post_title = get_the_title($unit->post_id);
        // Skip "פרק בדיקה" - but be more specific to avoid filtering units with "בדיקה" in title
        if (strpos($post_title, 'פרק בדיקה') !== false || 
            (strpos($post_title, 'בדיקה') !== false && strpos($post_title, 'יחידה') === false)) {
            continue;
        }
        // Extract unit number from title
        $unit_number = 999;
        if (preg_match('/יחידה\s*(\d+)/', $post_title, $matches)) {
            $unit_number = intval($matches[1]);
        }
        $filtered_units[] = [
            'post_id' => $unit->post_id,
            'unit_number' => $unit_number,
            'post_title' => $post_title
        ];
    }
    
    // Sort by unit number
    usort($filtered_units, function($a, $b) {
        return $a['unit_number'] <=> $b['unit_number'];
    });
    
    if (!empty($filtered_units)) {
        foreach ($filtered_units as $unit_data) {
            $post_id = $unit_data['post_id'];
            $post_title = $unit_data['post_title'];
            // Remove "פרטי:" prefix if exists
            $post_title = preg_replace('/^פרטי:\s*/', '', $post_title);
            $post_url = get_permalink($post_id);
            
            // Calculate progress for this unit
            $all_sections = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT section_id FROM $activity_table WHERE user_id = %d AND post_id = %d",
                $user_id, $post_id
            ));
            
            // Initialize progress calculation
            $section_progress = [];
            if (!empty($all_sections)) {
                foreach ($all_sections as $section_id) {
                    $section_activities = $wpdb->get_results($wpdb->prepare(
                        "SELECT activity_type, activity_data FROM $activity_table 
                         WHERE user_id = %d AND post_id = %d AND section_id = %s",
                        $user_id, $post_id, $section_id
                    ));
                    $section_progress[$section_id] = cpt_calculate_section_progress($post_id, $section_id, $section_activities);
                }
            }
            
            // Calculate overall percentage (each main section = 25%)
            // Note: task and assignment are the same - count only once
            $main_sections = ['overview', 'tools', 'discussion'];
            $total_progress = 0;
            
            foreach ($main_sections as $main_section) {
                $section_progress_value = 0;
                foreach ($section_progress as $section_id => $progress) {
                    // Match exact or starts with
                    if ($section_id === $main_section || strpos($section_id, $main_section) === 0) {
                        if ($progress > $section_progress_value) {
                            $section_progress_value = $progress;
                        }
                    }
                }
                $total_progress += ($section_progress_value / 100) * 25;
            }
            
            // Handle task/assignment - count only once
            $task_progress_value = 0;
            foreach ($section_progress as $section_id => $progress) {
                if ($section_id === 'task' || $section_id === 'assignment' || 
                    strpos($section_id, 'task') === 0 || strpos($section_id, 'assignment') === 0) {
                    if ($progress > $task_progress_value) {
                        $task_progress_value = $progress;
                    }
                }
            }
            $total_progress += ($task_progress_value / 100) * 25;
            
            // Round to avoid floating point issues
            $total_progress = round($total_progress);
            
            // Determine color based on progress
            $progress_color = $total_progress >= 80 ? '#27ae60' : ($total_progress >= 50 ? '#f39c12' : '#e74c3c');
            
            // Create title with colored progress line
            $title_html = esc_html($post_title) . ' (' . $total_progress . '%)';
            
            // Add progress and color to class name for easier extraction
            $class_name = 'cpt-unit-item cpt-progress-' . $total_progress;
            
            $wp_admin_bar->add_menu([
                'parent' => 'cpt-my-progress',
                'id' => 'cpt-unit-' . $post_id,
                'title' => $title_html,
                'href' => $post_url,
                'meta' => [
                    'html' => false,
                    'class' => $class_name,
                    'title' => 'progress:' . $total_progress
                ]
            ]);
        }
    } else {
        $wp_admin_bar->add_menu([
            'parent' => 'cpt-my-progress',
            'id' => 'cpt-no-progress',
            'title' => 'עוד לא התחלת',
            'href' => '#',
        ]);
    }
}
add_action('admin_bar_menu', 'cpt_add_admin_bar_menu', 100);


// 6. AJAX endpoint for drill-down details (enhanced with activity data)
function cpt_get_user_unit_details_callback() {
    if (!current_user_can('manage_options') || !check_ajax_referer('cpt_admin_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Authentication failed.'], 403);
        return;
    }

    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

    if (!$user_id || !$post_id) {
        wp_send_json_error(['message' => 'Invalid parameters.'], 400);
        return;
    }

    global $wpdb;
    $table_name = CPT_TABLE_NAME;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;

    // Get all distinct sections for this unit
    // Get all sections from activity table (more accurate - only sections with actual activity)
    $all_sections_raw = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT section_id FROM $activity_table WHERE post_id = %d ORDER BY section_id ASC", 
        $post_id
    ));
    
    // Filter out old/invalid sections - only keep valid main sections and their sub-sections
    // Note: intro is treated as overview (some units use 'intro' instead of 'overview')
    $valid_section_prefixes = ['overview', 'intro', 'tools', 'discussion', 'task', 'assignment', 'help_tools', 'inspiration', 'tools_demo', 'tools_intermediaries', 'tools_intro', 'tools_oral', 'tools_ask_me', 'tools_learning_mode', 'tools_document_analysis', 'tools_screen_share', 'tools_presentations', 'tools_infographics', 'tools_diagrams', 'tools_form', 'tools_one_way', 'tools_two_way', 'tools_advanced', 'image-generators', 'image-editing', 'designs', '3d', 'help-tools', 'tools_meetings', 'tools_tasks', 'tools_documents', 'tools_analysis', 'tools_dashboard', 'tools_practice', 'tools_alt_assessment', 'tools_micro_assessment', 'tools_test_build', 'tools_results_analysis', 'tools_resources', 'tools_audio', 'tools_dubbing', 'tools_animation', 'tools_explanation', 'tools_video'];
    $all_sections = [];
    foreach ($all_sections_raw as $section_id) {
        // Check if section matches any valid prefix
        $is_valid = false;
        foreach ($valid_section_prefixes as $prefix) {
            if ($section_id === $prefix || strpos($section_id, $prefix) === 0) {
                $is_valid = true;
                break;
            }
        }
        if ($is_valid) {
            $all_sections[] = $section_id;
        }
    }

    // Get sections completed by this specific user (filtered)
    $completed_sections_raw = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT section_id FROM $activity_table WHERE user_id = %d AND post_id = %d", 
        $user_id, $post_id
    ));
    $completed_sections_filtered = [];
    foreach ($completed_sections_raw as $sec_id) {
        // Only include valid sections
        foreach ($valid_section_prefixes as $prefix) {
            if ($sec_id === $prefix || strpos($sec_id, $prefix) === 0) {
                $completed_sections_filtered[] = $sec_id;
                break;
            }
        }
    }
    $completed_sections = array_flip($completed_sections_filtered);

    // Get activity details for each section (only valid sections)
    $section_activities = [];
    foreach ($all_sections as $section_id) {
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT activity_type, activity_data, created_at FROM $activity_table 
             WHERE user_id = %d AND post_id = %d AND section_id = %s 
             ORDER BY created_at DESC",
            $user_id, $post_id, $section_id
        ));
        
        $section_activities_obj = [];
        foreach ($activities as $activity) {
            $section_activities_obj[] = (object)[
                'activity_type' => $activity->activity_type,
                'activity_data' => $activity->activity_data,
            ];
        }
        
        $progress = cpt_calculate_section_progress($post_id, $section_id, $section_activities_obj);
        
        $section_activities[$section_id] = [
            'completed' => isset($completed_sections[$section_id]),
            'progress' => $progress,
            'activities' => $activities,
        ];
    }

    $details = [
        'all_sections' => $all_sections,
        'completed_sections' => $completed_sections,
        'section_activities' => $section_activities,
    ];

    wp_send_json_success($details);
}
add_action('wp_ajax_cpt_get_user_unit_details', 'cpt_get_user_unit_details_callback');


// 7. Enqueue scripts and styles for admin page
function cpt_admin_enqueue_scripts($hook = '') {
    // Load admin page scripts and styles only on plugin's admin page
    if (!empty($hook) && 'toplevel_page_course-progress-tracker' === $hook) {
        $plugin_url = plugin_dir_url(__FILE__);
        wp_enqueue_style('cpt-admin-style', $plugin_url . 'admin-style.css', [], '2.0.0');
        wp_enqueue_script('cpt-admin-script', $plugin_url . 'admin-script.js', ['jquery'], '2.0.0', true);
        
        // Pass data to script
        wp_localize_script('cpt-admin-script', 'cpt_admin_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cpt_admin_nonce'),
        ]);
    }
    
    // Always load admin bar styles and scripts for logged-in users
    if (is_user_logged_in()) {
        // Add inline CSS for admin bar progress lines
        $admin_bar_css = '
            #wpadminbar .cpt-progress-menu .ab-sub-wrapper .cpt-unit-item,
            #wpadminbar #cpt-my-progress .ab-item,
            #wpadminbar [id^="cpt-unit-"] {
                position: relative !important;
                padding-bottom: 10px !important;
                margin-bottom: 2px !important;
            }
            #wpadminbar .cpt-progress-line-admin {
                position: absolute !important;
                bottom: 0 !important;
                right: 0 !important;
                left: 0 !important;
                height: 3px !important;
                border-radius: 2px !important;
                pointer-events: none !important;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
                transition: all 0.3s ease !important;
                z-index: 10 !important;
            }
            #wpadminbar .cpt-unit-item:hover .cpt-progress-line-admin,
            #wpadminbar #cpt-my-progress .ab-item:hover .cpt-progress-line-admin {
                height: 4px !important;
                box-shadow: 0 2px 4px rgba(0,0,0,0.15) !important;
            }
        ';
        wp_add_inline_style('admin-bar', $admin_bar_css);
        
        // Add inline JavaScript to add progress lines to admin bar items
        $admin_bar_js = 'jQuery(document).ready(function($) {
            function addProgressLines() {
                var selectors = [
                    "#wpadminbar .cpt-unit-item",
                    "#wpadminbar #cpt-my-progress .ab-item",
                    "#wpadminbar [id^=\'cpt-unit-\']"
                ];
                
                for (var i = 0; i < selectors.length; i++) {
                    var selector = selectors[i];
                    $(selector).each(function() {
                        var $item = $(this);
                        if ($item.find(".cpt-progress-line-admin").length > 0 || $item.hasClass("cpt-line-added")) {
                            return;
                        }
                        
                        var progress = 0;
                        var classAttr = $item.attr("class") || "";
                        
                        var classMatch = classAttr.match(/cpt-progress-(\d+)/);
                        if (classMatch) {
                            progress = parseInt(classMatch[1]);
                        }
                        
                        if (progress === 0) {
                            var titleAttr = $item.attr("title") || "";
                            var titleMatch = titleAttr.match(/progress:(\d+)/);
                            if (titleMatch) {
                                progress = parseInt(titleMatch[1]);
                            }
                        }
                        
                        if (progress === 0) {
                            progress = parseInt($item.attr("data-progress")) || parseInt($item.data("progress")) || 0;
                        }
                        
                        if (progress === 0) {
                            var textContent = $item.text();
                            var textMatch = textContent.match(/\((\d+)%\)/);
                            if (textMatch) {
                                progress = parseInt(textMatch[1]);
                            }
                        }
                        
                        var color = "#e0e0e0";
                        if (progress >= 80) {
                            color = "#27ae60";
                        } else if (progress >= 50) {
                            color = "#f39c12";
                        } else if (progress > 0) {
                            color = "#e74c3c";
                        }
                        
                        var line = $("<div>").addClass("cpt-progress-line-admin").css({
                            "background": "linear-gradient(to right, " + color + " " + progress + "%, #e0e0e0 " + progress + "%)",
                            "background-size": "100% 100%"
                        });
                        $item.append(line);
                        $item.addClass("cpt-line-added");
                    });
                }
            }
            
            addProgressLines();
            setTimeout(addProgressLines, 300);
            setTimeout(addProgressLines, 600);
            setTimeout(addProgressLines, 1000);
            setTimeout(addProgressLines, 2000);
            
            $(document).on("click", "#wpadminbar #cpt-my-progress, #wpadminbar .cpt-progress-menu", function() {
                setTimeout(addProgressLines, 100);
                setTimeout(addProgressLines, 300);
                setTimeout(addProgressLines, 600);
            });
            
            if (typeof MutationObserver !== "undefined") {
                var observer = new MutationObserver(function(mutations) {
                    addProgressLines();
                });
                var adminBar = document.getElementById("wpadminbar");
                if (adminBar) {
                    observer.observe(adminBar, {
                        childList: true,
                        subtree: true,
                        attributes: true,
                        attributeFilter: ["class"]
                    });
                }
            }
        });';
        wp_add_inline_script('jquery', $admin_bar_js);
    }
}
add_action('admin_enqueue_scripts', 'cpt_admin_enqueue_scripts');

// Separate function for frontend admin bar styles
function cpt_frontend_admin_bar_styles() {
    if (!is_user_logged_in()) {
        return;
    }
    
    // Add inline CSS for admin bar progress lines (frontend)
    $admin_bar_css = '
        #wpadminbar .cpt-progress-menu .ab-sub-wrapper .cpt-unit-item,
        #wpadminbar #cpt-my-progress .ab-item,
        #wpadminbar [id^="cpt-unit-"] {
            position: relative !important;
            padding-bottom: 10px !important;
            margin-bottom: 2px !important;
        }
        #wpadminbar .cpt-progress-line-admin {
            position: absolute !important;
            bottom: 0 !important;
            right: 0 !important;
            left: 0 !important;
            height: 3px !important;
            border-radius: 2px !important;
            pointer-events: none !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
            transition: all 0.3s ease !important;
            z-index: 10 !important;
        }
        #wpadminbar .cpt-unit-item:hover .cpt-progress-line-admin,
        #wpadminbar #cpt-my-progress .ab-item:hover .cpt-progress-line-admin {
            height: 4px !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15) !important;
        }
    ';
    wp_add_inline_style('admin-bar', $admin_bar_css);
    
    // Add inline JavaScript to add progress lines to admin bar items (frontend)
    $admin_bar_js = 'jQuery(document).ready(function($) {
        function addProgressLines() {
            var selectors = [
                "#wpadminbar .cpt-unit-item",
                "#wpadminbar #cpt-my-progress .ab-item",
                "#wpadminbar [id^=\'cpt-unit-\']"
            ];
            
            for (var i = 0; i < selectors.length; i++) {
                var selector = selectors[i];
                $(selector).each(function() {
                    var $item = $(this);
                    if ($item.find(".cpt-progress-line-admin").length > 0 || $item.hasClass("cpt-line-added")) {
                        return;
                    }
                    
                    var progress = 0;
                    var classAttr = $item.attr("class") || "";
                    
                    var classMatch = classAttr.match(/cpt-progress-(\d+)/);
                    if (classMatch) {
                        progress = parseInt(classMatch[1]);
                    }
                    
                    if (progress === 0) {
                        var titleAttr = $item.attr("title") || "";
                        var titleMatch = titleAttr.match(/progress:(\d+)/);
                        if (titleMatch) {
                            progress = parseInt(titleMatch[1]);
                        }
                    }
                    
                    if (progress === 0) {
                        progress = parseInt($item.attr("data-progress")) || parseInt($item.data("progress")) || 0;
                    }
                    
                    if (progress === 0) {
                        var textContent = $item.text();
                        var textMatch = textContent.match(/\((\d+)%\)/);
                        if (textMatch) {
                            progress = parseInt(textMatch[1]);
                        }
                    }
                    
                    var color = "#e0e0e0";
                    if (progress >= 80) {
                        color = "#27ae60";
                    } else if (progress >= 50) {
                        color = "#f39c12";
                    } else if (progress > 0) {
                        color = "#e74c3c";
                    }
                    
                    var line = $("<div>").addClass("cpt-progress-line-admin").css({
                        "background": "linear-gradient(to right, " + color + " " + progress + "%, #e0e0e0 " + progress + "%)",
                        "background-size": "100% 100%"
                    });
                    $item.append(line);
                    $item.addClass("cpt-line-added");
                });
            }
        }
        
        addProgressLines();
        setTimeout(addProgressLines, 300);
        setTimeout(addProgressLines, 600);
        setTimeout(addProgressLines, 1000);
        setTimeout(addProgressLines, 2000);
        
        $(document).on("click", "#wpadminbar #cpt-my-progress, #wpadminbar .cpt-progress-menu", function() {
            setTimeout(addProgressLines, 100);
            setTimeout(addProgressLines, 300);
            setTimeout(addProgressLines, 600);
        });
        
        if (typeof MutationObserver !== "undefined") {
            var observer = new MutationObserver(function(mutations) {
                addProgressLines();
            });
            var adminBar = document.getElementById("wpadminbar");
            if (adminBar) {
                observer.observe(adminBar, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ["class"]
                });
            }
        }
    });';
    wp_add_inline_script('jquery', $admin_bar_js);
}
add_action('wp_enqueue_scripts', 'cpt_frontend_admin_bar_styles');


// 7.1 Helper function to enqueue course tracker script
// Note: This function should be called from theme's functions.php, not from here!
// See FUNCTIONS_PHP_CODE.txt for the code to add to your theme's functions.php
// 
// DO NOT uncomment the add_action below - it will cause conflicts!
// Instead, copy the function to your theme's functions.php and customize the condition


// 8. Admin dashboard page
function cpt_admin_menu() {
    add_menu_page(
        'דוח התקדמות בקורס',
        'התקדמות בקורס',
        'manage_options',
        'course-progress-tracker',
        'cpt_admin_page_content',
        'dashicons-chart-line',
        20
    );
}
add_action('admin_menu', 'cpt_admin_menu');

function cpt_admin_page_content() {
    global $wpdb;
    $table_name = CPT_TABLE_NAME;

    // 1. Get all progress data
    $all_progress = $wpdb->get_results("SELECT user_id, post_id, section_id FROM $table_name");

    if (empty($all_progress)) {
        echo '<div class="wrap"><h1>דוח התקדמות בקורס</h1><p>אין עדיין נתוני התקדמות.</p></div>';
        return;
    }

    // 2. Process data
    $user_progress = [];
    $all_unit_ids = [];
    $total_sections_per_unit = [];

    foreach ($all_progress as $row) {
        $user_id = $row->user_id;
        $post_id = $row->post_id;
        $section_id = $row->section_id;

        if (!isset($user_progress[$user_id])) {
            $user_progress[$user_id] = [];
        }
        if (!isset($user_progress[$user_id][$post_id])) {
            $user_progress[$user_id][$post_id] = [];
        }
        $user_progress[$user_id][$post_id][$section_id] = true;
        $all_unit_ids[$post_id] = true;
    }
    
    // Calculate total sections for each unit based on distinct completed sections across all users
    $totals_results = $wpdb->get_results("SELECT post_id, COUNT(DISTINCT section_id) as total_sections FROM $table_name GROUP BY post_id", OBJECT_K);
     foreach ($totals_results as $post_id => $data) {
        $total_sections_per_unit[$post_id] = (int) $data->total_sections;
    }

    $all_unit_ids = array_keys($all_unit_ids);
    
    // Filter out "פרק בדיקה" and sort by unit number
    $filtered_unit_ids = [];
    foreach ($all_unit_ids as $unit_id) {
        $unit_title = get_the_title($unit_id);
        // Skip "פרק בדיקה" - but be more specific to avoid filtering units with "בדיקה" in title
        if (strpos($unit_title, 'פרק בדיקה') !== false || 
            (strpos($unit_title, 'בדיקה') !== false && strpos($unit_title, 'יחידה') === false)) {
            continue;
        }
        // Extract unit number from title
        $unit_number = 999;
        if (preg_match('/יחידה\s*(\d+)/', $unit_title, $matches)) {
            $unit_number = intval($matches[1]);
        }
        $filtered_unit_ids[] = [
            'id' => $unit_id,
            'number' => $unit_number
        ];
    }
    
    // Sort by unit number
    usort($filtered_unit_ids, function($a, $b) {
        return $a['number'] <=> $b['number'];
    });
    
    $all_unit_ids = array_column($filtered_unit_ids, 'id');
    ?>
    <div class="wrap">
        <h1>דוח התקדמות בקורס - סיכום</h1>
        <style>
            .progress-table { width: 100%; border-collapse: collapse; }
            .progress-table th, .progress-table td { padding: 8px 12px; border: 1px solid #ddd; text-align: right; }
            .progress-table th { background-color: #f2f2f2; }
            .unit-header { writing-mode: vertical-rl; text-orientation: mixed; white-space: nowrap; }
        </style>
        <table class="wp-list-table widefat fixed striped progress-table">
            <thead>
                <tr>
                    <th>משתמש/ת</th>
                    <?php foreach ($all_unit_ids as $unit_id): 
                        $unit_title = get_the_title($unit_id);
                        $unit_title = preg_replace('/^פרטי:\s*/', '', $unit_title);
                    ?>
                        <th class="unit-header"><?php echo esc_html($unit_title); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($user_progress as $user_id => $units): ?>
                    <tr>
                        <td><?php echo esc_html(get_userdata($user_id)->display_name); ?></td>
                        <?php foreach ($all_unit_ids as $unit_id): ?>
                            <td class="progress-cell" data-user-id="<?php echo esc_attr($user_id); ?>" data-post-id="<?php echo esc_attr($unit_id); ?>" title="לחץ לפירוט">
                                <?php
                                // Calculate progress based on activities, not just completed sections
                                $activity_table = CPT_ACTIVITY_TABLE_NAME;
                                $user_activities = $wpdb->get_results($wpdb->prepare(
                                    "SELECT section_id, activity_type, activity_data FROM $activity_table WHERE user_id = %d AND post_id = %d",
                                    $user_id, $unit_id
                                ));
                                
                                // Group by section
                                $section_activities_map = [];
                                foreach ($user_activities as $activity) {
                                    $sec_id = $activity->section_id;
                                    if (!isset($section_activities_map[$sec_id])) {
                                        $section_activities_map[$sec_id] = [];
                                    }
                                    $section_activities_map[$sec_id][] = (object)[
                                        'activity_type' => $activity->activity_type,
                                        'activity_data' => $activity->activity_data,
                                    ];
                                }
                                
                                // Calculate progress for each section
                                // Each unit has exactly 4 main sections: overview, tools, discussion, task/assignment
                                // Each section is worth 25%
                                // Note: task and assignment are the same - count only once
                                $main_sections = ['overview', 'tools', 'discussion'];
                                $total_progress = 0;
                                
                                foreach ($main_sections as $main_section) {
                                    // Find all sections that belong to this main section
                                    $section_progress_value = 0;
                                    foreach ($section_activities_map as $sec_id => $sec_activities) {
                                        // Check if section_id matches the main section (including sub-sections)
                                        if ($sec_id === $main_section || strpos($sec_id, $main_section) === 0) {
                                            $progress = cpt_calculate_section_progress($unit_id, $sec_id, $sec_activities);
                                            // Use the highest progress for this main section
                                            if ($progress > $section_progress_value) {
                                                $section_progress_value = $progress;
                                            }
                                        }
                                    }
                                    // Each main section contributes 25% (0-100% of that 25%)
                                    $total_progress += ($section_progress_value / 100) * 25;
                                }
                                
                                // Handle task/assignment - count only once
                                $task_progress_value = 0;
                                foreach ($section_activities_map as $sec_id => $sec_activities) {
                                    if ($sec_id === 'task' || $sec_id === 'assignment' || 
                                        strpos($sec_id, 'task') === 0 || strpos($sec_id, 'assignment') === 0) {
                                        $sec_progress = cpt_calculate_section_progress($unit_id, $sec_id, $sec_activities);
                                        if ($sec_progress > $task_progress_value) {
                                            $task_progress_value = $sec_progress;
                                        }
                                    }
                                }
                                $total_progress += ($task_progress_value / 100) * 25;
                                
                                $percentage = round($total_progress); // Already calculated as percentage (0-100)
                                echo cpt_render_progress_bar($percentage);
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Modal for details -->
        <div id="cpt-details-modal" style="display:none;">
            <div id="cpt-modal-content">
                <button id="cpt-modal-close">&times;</button>
                <h3 id="cpt-modal-title">פירוט התקדמות</h3>
                <div id="cpt-modal-body"></div>
            </div>
            <div id="cpt-modal-backdrop"></div>
        </div>

    </div>
    <?php
}

/* 
 * =====================================================================================
 *  הוראות שימוש חשובות - גרסה 2.0.0
 * =====================================================================================
 * 
 * כדי שהתוסף יעבוד, יש "להזריק" נתונים לתוך הסקריפט שרץ ביחידות ה-HTML.
 * הדרך המומלצת לעשות זאת בוורדפרס היא להוסיף את הקוד הבא לקובץ `functions.php`
 * של תבנית העיצוב שלך (או תבנית בת).
 *
 * הקוד בודק אם המשתמש צופה בעמוד ספציפי (יש להתאים את התנאי), ורק אז
 * הוא טוען את סקריפט המעקב `course-tracker.js` ויוצר אובייקט JavaScript בשם 
 * `progress_tracker_data` שיהיה זמין לסקריפט.
 *
 * יש להתאים את התנאי `is_page('slug-of-your-course-page')` כך שיתאים לעמודים
 * שבהם מוטמע תוכן הקורס.
 *
 * חשוב: יש להוסיף attributes למעקב ביחידות ה-HTML:
 * - data-track-section על כל content-section
 * - data-track-video על כל iframe YouTube
 * - data-track-click על כפתורים חשובים
 * - data-track-manual על תיבת סימון בפרק המשימה
 *
 * ראה קובץ TRACKING_IMPLEMENTATION_GUIDE.md להנחיות מפורטות.
*/

// הדבק את הקוד הבא בקובץ functions.php של התבנית שלך:
// ראה קובץ FUNCTIONS_PHP_CODE.txt בקובץ התוסף לקוד מוכן להעתקה
/*
add_action('wp_enqueue_scripts', 'cpt_enqueue_course_scripts');
function cpt_enqueue_course_scripts() {
    // בדוק אם המשתמש מחובר
    if (!is_user_logged_in()) {
        return;
    }
    
    // *** שנה את התנאי כאן כדי שיתאים לעמודי הקורס שלך ***
    // דוגמאות:
    // 
    // אופציה 1: כל העמודים שהם ילדים של עמוד מסוים (מומלץ!)
    // $parent_page = get_page_by_path('aia');
    // if ($parent_page && (is_page($parent_page->ID) || in_array($parent_page->ID, get_post_ancestors(get_the_ID())))) {
    //
    // אופציה 2: לפי slug ספציפי:
    // if (is_page('unit-1') || is_page('unit-2')) {
    //
    // אופציה 3: לפי custom post type:
    // if (is_singular('course_unit')) {
    //
    // אופציה 4: לפי template:
    // if (is_page_template('template-course.php')) {
    //
    // דוגמה בסיסית:
    $parent_page = get_page_by_path('aia');
    if ($parent_page && (is_page($parent_page->ID) || in_array($parent_page->ID, get_post_ancestors(get_the_ID())))) { 
        
        wp_enqueue_script('jquery');
        // חשוב: לא להשתמש ב-__FILE__ כאן כי זה מצביע על התבנית, לא על התוסף!
        wp_enqueue_script('cpt-course-tracker', 
            plugins_url('course-progress-tracker/course-tracker.js'), 
            ['jquery'], 
            '2.0.0', 
            true
        );

        wp_localize_script('cpt-course-tracker', 'progress_tracker_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'post_id'  => get_the_ID(),
            'nonce'    => wp_create_nonce('cpt_progress_nonce'),
            'get_action' => 'cpt_get_progress',
            'save_action' => 'cpt_save_progress',
            'track_action' => 'cpt_track_activity',
            'check_comment_action' => 'cpt_check_comment_status',
            'manual_check_action' => 'cpt_save_manual_check',
            'get_progress_action' => 'cpt_get_activity_progress',
        ));
    }
}
*/

// =====================================================================================
// Course Auto Registration - רישום אוטומטי לקורס אחרי תשלום
// =====================================================================================

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
    // First, try to find existing role by name
    $all_roles = wp_roles()->get_names();
    
    foreach ($all_roles as $slug => $name) {
        if (strtolower($name) === strtolower($role_name)) {
            return $slug;
        }
    }
    
    // If not found, try special cases
    if ($role_name === 'לומד בקורס' || $role_name === CAR_DEFAULT_ROLE) {
        // Try "learner" first (English alternative)
        if (get_role('learner')) {
            return 'learner';
        }
    }
    
    // Fallback to sanitized key
    return sanitize_key($role_name);
}

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
    
    $is_valid = false;
    
    if (!empty($sumit_api_key) && !empty($sumit_api_secret)) {
        // Verify payment with Sumit API
        $api_url = 'https://api.sumit.co.il/v1/payments/' . urlencode($payment_id);
        
        $response = wp_remote_get($api_url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($sumit_api_key . ':' . $sumit_api_secret),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 10,
        ]);
        
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
        }
    } else {
        // If no API, use basic validation:
        // 1. Payment ID format looks valid (numeric, at least 8 digits)
        // 2. Customer ID format looks valid (numeric)
        // Note: This is less secure, but works if API is not available
        if (is_numeric($payment_id) && is_numeric($customer_id) && strlen($payment_id) >= 8) {
            $is_valid = true;
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

// Get Sumit customer data from API
function car_get_sumit_customer_data($customer_id, $api_key, $api_secret) {
    // Sumit API endpoint (adjust if needed)
    $api_url = 'https://api.sumit.co.il/v1/customers/' . urlencode($customer_id);
    
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $api_secret),
            'Content-Type' => 'application/json',
        ],
        'timeout' => 10,
    ]);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if ($data && isset($data['email'])) {
        return [
            'email' => $data['email'],
            'name' => isset($data['name']) ? $data['name'] : (isset($data['firstName']) && isset($data['lastName']) ? $data['firstName'] . ' ' . $data['lastName'] : ''),
        ];
    }
    
    return false;
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

// Create user from payment data
function car_create_user_from_payment($email, $name) {
    // Sanitize inputs
    $email = sanitize_email($email);
    $name = sanitize_text_field($name);
    
    // Validate email
    if (!is_email($email)) {
        return new WP_Error('invalid_email', 'כתובת אימייל לא תקינה.');
    }
    
    // Check if user already exists
    $user = get_user_by('email', $email);
    $is_new_user = false;
    
    if ($user) {
        // User exists - log them in
        // Handle affiliate tracking for existing users too (if not already set)
        $affiliate_ref = car_get_affiliate_ref();
        if ($affiliate_ref && !get_user_meta($user->ID, 'referral_source', true)) {
            update_user_meta($user->ID, 'referral_source', $affiliate_ref);
            $affiliate_name = car_get_affiliate_name($affiliate_ref);
            update_user_meta($user->ID, 'referral_name', $affiliate_name);
            car_save_affiliate_conversion($affiliate_ref, $user->ID);
        }
        
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        return ['user' => $user, 'is_new' => false];
    }
    
    // Parse name into first and last name
    $name_parts = explode(' ', $name, 2);
    $first_name = $name_parts[0];
    $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
    
    // Generate username from email or name
    $username = sanitize_user(str_replace('@', '_', $email));
    $original_username = $username;
    $counter = 1;
    
    // Make sure username is unique
    while (username_exists($username)) {
        $username = $original_username . $counter;
        $counter++;
    }
    
    // Generate random password
    $password = wp_generate_password(12, false);
    
    // Get role name from settings BEFORE creating user
    $role_name = get_option('car_role_name', CAR_DEFAULT_ROLE);
    
    // Get role slug - try to find existing role first
    $role_slug = car_get_role_slug($role_name);
    
    // Verify role exists
    if (!get_role($role_slug)) {
        // Try to create it
        $created_slug = car_create_course_student_role();
        if ($created_slug) {
            $role_slug = $created_slug;
        } else {
            // If creation failed, try "learner" as fallback
            if (get_role('learner')) {
                $role_slug = 'learner';
            }
        }
    }
    
    // Store role in transient for use in hook
    set_transient('car_pending_role_' . md5($email), $role_slug, 60);
    
    // Create user - WordPress will assign default role
    $user_id = wp_create_user($username, $password, $email);
    
    if (is_wp_error($user_id)) {
        delete_transient('car_pending_role_' . md5($email));
        return $user_id;
    }
    
    // Update user meta
    update_user_meta($user_id, 'first_name', $first_name);
    update_user_meta($user_id, 'last_name', $last_name);
    
    // Assign role immediately using our force function (same as manual assignment)
    $role_assigned = car_assign_user_role_force($user_id, $role_slug);
    
    // Verify role was assigned
    $user_obj = new WP_User($user_id);
    $final_roles = $user_obj->roles;
    
    if (!in_array($role_slug, $final_roles)) {
        // If still not assigned, try "learner" as fallback
        if (get_role('learner')) {
            $role_assigned = car_assign_user_role_force($user_id, 'learner');
            if ($role_assigned) {
                $role_slug = 'learner';
            }
        }
    }
    
    // Log if assignment failed (for debugging)
    if (!$role_assigned) {
        error_log('CAR: Failed to assign role to new user ' . $user_id . '. Tried: ' . $role_slug);
    }
    
    // Clean up transient
    delete_transient('car_pending_role_' . md5($email));
    
    // Handle affiliate tracking
    $affiliate_ref = car_get_affiliate_ref();
    if ($affiliate_ref) {
        update_user_meta($user_id, 'referral_source', $affiliate_ref);
        $affiliate_name = car_get_affiliate_name($affiliate_ref);
        update_user_meta($user_id, 'referral_name', $affiliate_name);
        car_save_affiliate_conversion($affiliate_ref, $user_id);
    }
    
    // Log user in
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id);
    
    // Send welcome email if enabled
    $send_email = get_option('car_send_email', true);
    error_log('CAR: Email sending enabled: ' . ($send_email ? 'YES' : 'NO'));
    if ($send_email) {
        error_log('CAR: Calling car_send_welcome_email for user: ' . $user_id . ', email: ' . $email);
        car_send_welcome_email($user_id, $email, $username, $password);
    } else {
        error_log('CAR: Email sending is disabled in settings');
    }
    
    return ['user' => get_userdata($user_id), 'is_new' => true];
}

// Send welcome email with login credentials
function car_send_welcome_email($user_id, $email, $username, $password) {
    $user = get_userdata($user_id);
    $first_name = get_user_meta($user_id, 'first_name', true);
    $display_name = !empty($first_name) ? $first_name : $user->display_name;
    
    $subject = get_option('car_email_subject', 'ברוכים הבאים לקורס!');
    $login_url = wp_login_url();
    $site_name = get_bloginfo('name');
    $logo_url = 'https://i.imgur.com/DTrDwid.png';
    
    // Get custom email template or use default
    $email_template = get_option('car_email_template', '');
    $whatsapp_gold = get_option('car_whatsapp_gold', 'https://chat.whatsapp.com/F053eDddoLoIDD2MOPUbCy');
    $whatsapp_silver = get_option('car_whatsapp_silver', 'https://chat.whatsapp.com/CYHeVm1bnXY461koFteTff');
    
    if (!empty($email_template)) {
        // Replace placeholders in template - if it's HTML, use as is, otherwise convert to HTML
        $message = str_replace(
            ['{display_name}', '{username}', '{password}', '{login_url}', '{site_name}', '{whatsapp_gold}', '{whatsapp_silver}', '{logo_url}'],
            [$display_name, $username, $password, $login_url, $site_name, $whatsapp_gold, $whatsapp_silver, $logo_url],
            $email_template
        );
        
        // If template doesn't contain HTML tags, convert to HTML
        if (strip_tags($message) === $message) {
            $message = nl2br(esc_html($message));
            $message = car_wrap_email_html($message, $logo_url, $site_name);
        }
    } else {
        // Default HTML template
        $message = car_get_default_email_html($display_name, $username, $password, $login_url, $site_name, $logo_url, $whatsapp_gold, $whatsapp_silver);
    }
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
    ];
    
    // Log email attempt
    error_log('CAR: Attempting to send welcome email to: ' . $email);
    error_log('CAR: Email subject: ' . $subject);
    error_log('CAR: Message length: ' . strlen($message) . ' characters');
    
    // Send email and check result
    $mail_result = wp_mail($email, $subject, $message, $headers);
    
    if ($mail_result) {
        error_log('CAR: Email sent successfully to: ' . $email);
    } else {
        error_log('CAR: ERROR - Failed to send email to: ' . $email);
        // Try sending a simple text email as fallback
        $simple_message = "שלום " . $display_name . ",\n\n";
        $simple_message .= "ברוכים הבאים לקורס!\n\n";
        $simple_message .= "פרטי ההתחברות שלך:\n";
        $simple_message .= "שם משתמש: " . $username . "\n";
        $simple_message .= "סיסמה: " . $password . "\n\n";
        $simple_message .= "כדי להתחבר, גשו ל: " . $login_url . "\n\n";
        $simple_message .= "בברכה,\n" . $site_name;
        
        $simple_headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        ];
        
        $fallback_result = wp_mail($email, $subject, $simple_message, $simple_headers);
        if ($fallback_result) {
            error_log('CAR: Fallback text email sent successfully');
        } else {
            error_log('CAR: ERROR - Fallback email also failed');
        }
    }
}

// Get default HTML email template
function car_get_default_email_html($display_name, $username, $password, $login_url, $site_name, $logo_url, $whatsapp_gold, $whatsapp_silver) {
    // Escape variables for use in HTML
    $logo_url_esc = esc_url($logo_url);
    $site_name_esc = esc_attr($site_name);
    $display_name_esc = esc_html($display_name);
    $site_name_html = esc_html($site_name);
    $username_esc = esc_html($username);
    $password_esc = esc_html($password);
    $login_url_esc = esc_url($login_url);
    $whatsapp_gold_esc = esc_url($whatsapp_gold);
    $whatsapp_silver_esc = esc_url($whatsapp_silver);
    
    $html = <<<HTML
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
        .email-title {
            color: #ffffff;
            font-size: 28px;
            font-weight: bold;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .email-content {
            padding: 30px 20px;
        }
        .greeting {
            font-size: 20px;
            color: #333;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .info-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-right: 4px solid #4A90E2;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
        }
        .info-box-title {
            font-size: 18px;
            font-weight: bold;
            color: #4A90E2;
            margin-bottom: 15px;
        }
        .credentials-box {
            background: #ffffff;
            border: 2px solid #4A90E2;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        .credential-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .credential-row:last-child {
            border-bottom: none;
        }
        .credential-label {
            font-weight: bold;
            color: #333;
            font-size: 16px;
        }
        .credential-value {
            font-family: "Courier New", monospace;
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 5px;
            color: #4A90E2;
            font-weight: bold;
            font-size: 16px;
        }
        .button-container {
            text-align: center;
            margin: 30px 0;
        }
        .login-button {
            display: inline-block;
            background: linear-gradient(135deg, #4A90E2 0%, #F7D979 100%);
            color: #ffffff;
            text-decoration: none;
            padding: 15px 40px;
            border-radius: 10px;
            font-size: 18px;
            font-weight: bold;
            box-shadow: 0 4px 15px rgba(74, 144, 226, 0.3);
            transition: all 0.3s ease;
        }
        .whatsapp-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            margin: 25px 0;
            border-radius: 10px;
            text-align: center;
        }
        .whatsapp-title {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            margin-bottom: 20px;
        }
        .whatsapp-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .whatsapp-button {
            display: inline-block;
            text-decoration: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 18px;
            font-weight: bold;
            color: #ffffff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }
        .whatsapp-gold {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            color: #000;
        }
        .whatsapp-silver {
            background: linear-gradient(135deg, #C0C0C0 0%, #808080 100%);
            color: #fff;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            font-size: 14px;
            border-top: 1px solid #e0e0e0;
        }
        .warning-box {
            background: #fff3cd;
            border-right: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <img src="{$logo_url_esc}" alt="{$site_name_esc}" class="email-logo" />
            <h1 class="email-title">ברוכים הבאים!</h1>
        </div>
        
        <div class="email-content">
            <p class="greeting">שלום {$display_name_esc},</p>
            
            <div class="info-box">
                <div class="info-box-title">🎉 חשבון נפתח עבורך בהצלחה!</div>
                <p style="margin: 0; color: #333; line-height: 1.6;">אנו שמחים לראות אותך מצטרף/ת ל-{$site_name_html}. עכשיו תוכל/י לגשת לכל התוכן וההסברים בקורס.</p>
            </div>
            
            <div class="info-box">
                <div class="info-box-title">🔐 פרטי ההתחברות שלך:</div>
                <div class="credentials-box">
                    <div class="credential-row">
                        <span class="credential-label">שם משתמש:</span>
                        <span class="credential-value">{$username_esc}</span>
                    </div>
                    <div class="credential-row">
                        <span class="credential-label">סיסמה:</span>
                        <span class="credential-value">{$password_esc}</span>
                    </div>
                </div>
            </div>
            
            <div class="button-container">
                <a href="{$login_url_esc}" class="login-button">התחבר/י עכשיו</a>
            </div>
            
            <div class="warning-box">
                <strong>💡 חשוב:</strong> אנו ממליצים לשנות את הסיסמה לאחר ההתחברות הראשונה.
            </div>
            
            <div class="whatsapp-section">
                <div class="whatsapp-title">📱 הצטרף/י לקבוצות הווטסאפ שלנו!</div>
                <div class="whatsapp-buttons">
                    <a href="{$whatsapp_gold_esc}" class="whatsapp-button whatsapp-gold" target="_blank">מסלול זהב</a>
                    <a href="{$whatsapp_silver_esc}" class="whatsapp-button whatsapp-silver" target="_blank">מסלול כסף</a>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p style="margin: 0;">בברכה,<br><strong>צוות {$site_name_html}</strong></p>
        </div>
    </div>
</body>
</html>';
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
    
    check_ajax_referer('car_test_email_nonce', 'nonce');
    
    $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
    if (empty($test_email)) {
        wp_send_json_error(['message' => 'יש להזין כתובת אימייל']);
        return;
    }
    
    $subject = get_option('car_email_subject', 'ברוכים הבאים לקורס!');
    $email_template = get_option('car_email_template', '');
    $login_url = wp_login_url();
    $site_name = get_bloginfo('name');
    $logo_url = 'https://i.imgur.com/DTrDwid.png';
    $whatsapp_gold = get_option('car_whatsapp_gold', 'https://chat.whatsapp.com/F053eDddoLoIDD2MOPUbCy');
    $whatsapp_silver = get_option('car_whatsapp_silver', 'https://chat.whatsapp.com/CYHeVm1bnXY461koFteTff');
    
    // Use test data
    $display_name = 'משתמש בדיקה';
    $username = 'test_user';
    $password = 'Test123!@#';
    
    if (!empty($email_template)) {
        $message = str_replace(
            ['{display_name}', '{username}', '{password}', '{login_url}', '{site_name}', '{whatsapp_gold}', '{whatsapp_silver}', '{logo_url}'],
            [$display_name, $username, $password, $login_url, $site_name, $whatsapp_gold, $whatsapp_silver, $logo_url],
            $email_template
        );
        
        // If template doesn't contain HTML tags, convert to HTML
        if (strip_tags($message) === $message) {
            $message = nl2br(esc_html($message));
            $message = car_wrap_email_html($message, $logo_url, $site_name);
        }
    } else {
        $message = car_get_default_email_html($display_name, $username, $password, $login_url, $site_name, $logo_url, $whatsapp_gold, $whatsapp_silver);
    }
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
    ];
    $result = wp_mail($test_email, $subject . ' (בדיקה)', $message, $headers);
    
    if ($result) {
        wp_send_json_success(['message' => 'מייל בדיקה נשלח בהצלחה!']);
    } else {
        wp_send_json_error(['message' => 'שגיאה בשליחת המייל']);
    }
}
add_action('wp_ajax_car_send_test_email', 'car_send_test_email');

// Thank you page shortcode
function car_thank_you_shortcode() {
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
        
        // CRITICAL: Verify payment is valid and not already used
        if (!empty($sumit_payment_id) && !empty($sumit_customer_id)) {
            $payment_verified = car_verify_sumit_payment($sumit_payment_id, $sumit_customer_id, false); // Don't mark as used yet
            
            if (!$payment_verified) {
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
        
        // Try to get customer info from Sumit API if configured
        $sumit_api_key = get_option('car_sumit_api_key', '');
        $sumit_api_secret = get_option('car_sumit_api_secret', '');
        
        if (!empty($sumit_api_key) && !empty($sumit_api_secret) && !empty($sumit_customer_id)) {
            $sumit_customer_data = car_get_sumit_customer_data($sumit_customer_id, $sumit_api_key, $sumit_api_secret);
            if ($sumit_customer_data && isset($sumit_customer_data['email'])) {
                $email = sanitize_email($sumit_customer_data['email']);
            }
            if ($sumit_customer_data && isset($sumit_customer_data['name'])) {
                $name = sanitize_text_field($sumit_customer_data['name']);
            }
        }
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
                        <input type="email" id="car_manual_email" name="car_manual_email" required class="car-form-input" placeholder="הזן את כתובת האימייל שלך" />
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
                
                // Redirect to avoid resubmission
                $redirect_url = add_query_arg(['registered' => '1'], get_permalink());
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
                        data: {
                            action: 'car_send_test_email',
                            nonce: car_email_data.nonce,
                            test_email: testEmail
                        },
                        success: function(response) {
                            if (response.success) {
                                resultSpan.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                            } else {
                                resultSpan.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                            }
                        },
                        error: function() {
                            resultSpan.html('<span style="color: red;">✗ שגיאה בשליחת המייל</span>');
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
