<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Frontend script loading for course unit pages.
 * Loads for logged-in users on pages under the 'aia' parent page,
 * or on any page allowed via the 'cpt_should_load_tracker' filter.
 */
add_action('wp_enqueue_scripts', 'cpt_enqueue_course_scripts', 5);
function cpt_enqueue_course_scripts() {
    if (!is_user_logged_in()) {
        return;
    }
    $post_id = get_the_ID();
    if (!$post_id) {
        return;
    }
    $should_load = false;
    $parent_page = get_page_by_path('aia');
    if ($parent_page && (is_page($parent_page->ID) || in_array($parent_page->ID, get_post_ancestors($post_id)))) {
        $should_load = true;
    }
    // Units defined in the manifest always get the tracker
    if (!$should_load && cpt_manifest_get_unit($post_id)) {
        $should_load = true;
    }
    $should_load = apply_filters('cpt_should_load_tracker', $should_load, $post_id);
    if (!$should_load) {
        return;
    }

    wp_enqueue_script('cpt-course-tracker',
        CPT_PLUGIN_URL . 'assets/course-tracker.js',
        [], // no jQuery dependency anymore
        CPT_VERSION,
        true
    );

    wp_localize_script('cpt-course-tracker', 'cpt_tracker_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'post_id'  => (int) $post_id,
    ]);

    // Embed initial progress state so circles light up on page load immediately,
    // without waiting for (or depending on) the GET /state REST call.
    // Uses the same DB queries as the [user_course_progress] shortcode.
    $user_id = get_current_user_id();
    global $wpdb;
    $completed = $wpdb->get_col($wpdb->prepare(
        "SELECT section_id FROM " . CPT_TABLE_NAME . " WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ));
    if (!is_array($completed)) { $completed = []; }
    $sp = cpt_get_unit_section_progress($user_id, $post_id);
    if (!is_array($sp)) { $sp = []; }
    foreach ($sp as $sid => $pct) {
        if ((int) $pct >= 100 && !in_array($sid, $completed, true)) {
            $completed[] = $sid;
        }
    }
    $initial_json = wp_json_encode([
        'completed_sections' => array_values($completed),
        'section_progress'   => (object) $sp,
    ], JSON_UNESCAPED_UNICODE);
    if ($initial_json !== false) {
        wp_add_inline_script('cpt-course-tracker',
            'if(typeof cpt_tracker_data!=="undefined"){cpt_tracker_data.initial_state=' . $initial_json . ';}',
            'after'
        );
    }
}
