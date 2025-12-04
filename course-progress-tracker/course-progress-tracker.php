<?php
/**
 * Plugin Name: Course Progress Tracker
 * Description: A lightweight, custom plugin to track user progress through HTML-based course units.
 * Version: 2.6.1
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

    $output = '<h3>ההתקדמות שלי בקורס</h3><div class="cpt-progress-wrapper">';
    
    foreach ($all_units as $unit) {
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
            $output .= '<div class="cpt-section-item">';
            $output .= '<span class="cpt-section-icon">' . $icon . '</span>';
            $output .= '<span class="cpt-section-name">' . esc_html($section_name_he) . '</span>';
            $output .= '<span class="cpt-section-progress">' . round($progress_value) . '%</span>';
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
        $output .= '<div class="cpt-section-item">';
        $output .= '<span class="cpt-section-icon">' . $icon . '</span>';
        $output .= '<span class="cpt-section-name">משימה</span>';
        $output .= '<span class="cpt-section-progress">' . round($task_progress) . '%</span>';
        $output .= '</div>';
        
        $output .= '</div>';
        
        $output .= '</div>';
    }
    $output .= '</div>';

    $output .= '<style>
        .cpt-progress-wrapper { display: grid; gap: 20px; margin-top: 20px; }
        .cpt-progress-item { padding: 20px; background: #f8f9fa; border-radius: 8px; border-right: 4px solid #ddd; }
        .cpt-progress-item.progress-high { border-right-color: #27ae60; }
        .cpt-progress-item.progress-medium { border-right-color: #f39c12; }
        .cpt-progress-item.progress-low { border-right-color: #e74c3c; }
        .cpt-progress-item h4 { margin-top: 0; margin-bottom: 15px; }
        .cpt-progress-item h4 a { text-decoration: none; color: inherit; }
        .cpt-section-breakdown { margin-top: 15px; display: grid; gap: 8px; }
        .cpt-section-item { display: flex; align-items: center; gap: 10px; padding: 5px 0; }
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
    
    if (!empty($all_units)) {
        foreach ($all_units as $unit) {
            $post_id = $unit->post_id;
            $post_title = get_the_title($post_id);
            // Remove "פרטי:" prefix if exists
            $post_title = preg_replace('/^פרטי:\s*/', '', $post_title);
            $post_url = get_permalink($post_id);
            
            // Calculate progress for this unit
            $all_sections = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT section_id FROM $activity_table WHERE user_id = %d AND post_id = %d",
                $user_id, $post_id
            ));
            
            if (!empty($all_sections)) {
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
                
                $wp_admin_bar->add_menu([
                    'parent' => 'cpt-my-progress',
                    'id' => 'cpt-unit-' . $post_id,
                    'title' => esc_html($post_title) . ' (' . $total_progress . '%)',
                    'href' => $post_url,
                ]);
            }
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
    $valid_section_prefixes = ['overview', 'intro', 'tools', 'discussion', 'task', 'assignment', 'help_tools', 'inspiration', 'tools_demo', 'tools_intermediaries', 'tools_intro', 'tools_oral', 'tools_ask_me', 'tools_learning_mode', 'tools_document_analysis', 'tools_screen_share', 'tools_presentations', 'tools_infographics', 'tools_diagrams', 'image-generators', 'image-editing', 'designs', '3d', 'help-tools'];
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
function cpt_admin_enqueue_scripts($hook) {
    // Only load on our plugin's admin page
    if ('toplevel_page_course-progress-tracker' !== $hook) {
        return;
    }
    $plugin_url = plugin_dir_url(__FILE__);
    wp_enqueue_style('cpt-admin-style', $plugin_url . 'admin-style.css', [], '2.0.0');
    wp_enqueue_script('cpt-admin-script', $plugin_url . 'admin-script.js', ['jquery'], '2.0.0', true);
    
    // Pass data to script
    wp_localize_script('cpt-admin-script', 'cpt_admin_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cpt_admin_nonce'),
    ]);
}
add_action('admin_enqueue_scripts', 'cpt_admin_enqueue_scripts');


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
    // Sort unit IDs numerically
    sort($all_unit_ids, SORT_NUMERIC);
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
