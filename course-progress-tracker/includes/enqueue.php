<?php
if (!defined('ABSPATH')) { exit; }

/**
 * True when the current request is a course unit / aia child page.
 */
function cpt_is_course_tracker_page($post_id = 0) {
    $post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
    if (!$post_id) {
        return false;
    }
    $parent_page = get_page_by_path('aia');
    if ($parent_page && (is_page($parent_page->ID) || in_array($parent_page->ID, get_post_ancestors($post_id), true))) {
        return true;
    }
    if (function_exists('cpt_manifest_get_unit') && cpt_manifest_get_unit($post_id)) {
        return true;
    }
    $post = get_post($post_id);
    return $post && has_shortcode($post->post_content, 'course_unit');
}

/**
 * Logged-in learners must not get guest-cached HTML: tracker scripts and
 * progress state are only injected when WordPress renders the page live.
 */
add_action('template_redirect', function () {
    if (!is_user_logged_in() || !cpt_is_course_tracker_page()) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (function_exists('do_action')) {
        do_action('litespeed_control_set_nocache', 'cpt course page');
    }
}, 0);

add_filter('rocket_override_donotcachepage', function ($donotcache, $post_id) {
    if (is_user_logged_in() && cpt_is_course_tracker_page($post_id)) {
        return true;
    }
    return $donotcache;
}, 10, 2);

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
