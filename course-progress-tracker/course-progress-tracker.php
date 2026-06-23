<?php
/**
 * Plugin Name: Course Progress Tracker
 * Description: מעקב התקדמות בקורס מבוסס יחידות HTML - מניפסט קורס מרכזי, REST API, דשבורד לומד, המשך מאיפה שעצרת, ודוחות. (הרישום האוטומטי הופרד לתוסף Course Registration)
 * Version: 3.9.1
 * Author: Chepti
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('CPT_VERSION', '3.9.1');
define('CPT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CPT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Database table names
global $wpdb;
define('CPT_TABLE_NAME', $wpdb->prefix . 'course_progress');
define('CPT_ACTIVITY_TABLE_NAME', $wpdb->prefix . 'course_activity');
define('CPT_LAST_POSITION_TABLE_NAME', $wpdb->prefix . 'course_last_position');

// Database table creation on plugin activation (same tables as v2 - existing data is preserved)
function cpt_activate() {
    global $wpdb;
    $table_name = CPT_TABLE_NAME;
    $activity_table_name = CPT_ACTIVITY_TABLE_NAME;
    $last_position_table_name = CPT_LAST_POSITION_TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        post_id bigint(20) NOT NULL,
        section_id varchar(255) NOT NULL,
        completed_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_progress (user_id, post_id, section_id)
    ) $charset_collate;";

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

// Modules
require_once CPT_PLUGIN_DIR . 'includes/manifest.php';        // progress structure (sections + requirements)
require_once CPT_PLUGIN_DIR . 'includes/course-structure.php'; // between-units nav + shell config
require_once CPT_PLUGIN_DIR . 'includes/content-model.php';    // structured unit content (Phase 2)
require_once CPT_PLUGIN_DIR . 'includes/content-editor.php';   // no-code unit content editor (Phase 2)
require_once CPT_PLUGIN_DIR . 'includes/progress.php';   // progress calculation
require_once CPT_PLUGIN_DIR . 'includes/ajax.php';       // legacy admin-ajax endpoints (kept for cached pages running the old JS)
require_once CPT_PLUGIN_DIR . 'includes/api.php';        // REST API (course/v1) + session bootstrap
require_once CPT_PLUGIN_DIR . 'includes/shortcodes.php'; // learner dashboard, resume button, unit progress
require_once CPT_PLUGIN_DIR . 'includes/admin.php';      // admin reports, admin bar, drill-down
require_once CPT_PLUGIN_DIR . 'includes/enqueue.php';    // frontend script loading
require_once CPT_PLUGIN_DIR . 'includes/units.php';      // [course_unit] - unit content served from the plugin
