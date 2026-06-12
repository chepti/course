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
}
