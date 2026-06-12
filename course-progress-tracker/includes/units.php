<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Unit rendering - [course_unit id="N"]
 *
 * Unit content lives in the plugin's units/ directory (units/unit-N.html),
 * one file per unit. The WordPress page contains only the shortcode, so
 * updating course content = uploading a new plugin zip. WordPress comments
 * belong to the page itself and are unaffected.
 *
 * Available ids: 1-9, 4-map, flowchart (file units/unit-{id}.html).
 */

function cpt_course_unit_shortcode($atts) {
    $atts = shortcode_atts(['id' => ''], $atts);
    $slug = strtolower(trim($atts['id']));

    // Strict allowlist pattern - the slug becomes part of a file path
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $slug)) {
        return '<p>course_unit: יש לציין id תקין, למשל [course_unit id="1"]</p>';
    }

    $file = CPT_PLUGIN_DIR . 'units/unit-' . $slug . '.html';
    if (!file_exists($file)) {
        return '<p>course_unit: לא נמצא קובץ יחידה "' . esc_html($slug) . '"</p>';
    }

    // The unit engine uses the Rubik font (was a <link> in the standalone HTML)
    wp_enqueue_style('cpt-rubik-font',
        'https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;700&display=swap',
        [], null);

    // Fallback if the wp_enqueue_scripts detection below didn't catch the
    // shortcode (e.g. shortcode rendered outside post_content). Styles
    // enqueued this late print in the footer; the slim units' override
    // selectors are body-prefixed, so the cascade is order-independent.
    cpt_unit_engine_enqueue_assets();

    return file_get_contents($file);
}
add_shortcode('course_unit', 'cpt_course_unit_shortcode');

/**
 * Shared unit engine (v2) assets.
 *
 * Loaded for every page containing [course_unit], including old-format units:
 * that is safe because unit.js no-ops when window.cptUnitContent is undefined
 * (only slim-format units define it), and every rule in unit.css is prefixed
 * with #interactive-unit-container.cpt-engine-v2 - a marker class only
 * slim-format unit markup carries - so old-format units are unaffected.
 */
function cpt_unit_engine_enqueue_assets() {
    wp_enqueue_style('cpt-unit-engine', CPT_PLUGIN_URL . 'assets/unit.css', [], CPT_VERSION);
    // In the footer: the unit's inline <script> (page content) defines
    // window.cptUnitContent/cptUnitConfig before this script runs.
    wp_enqueue_script('cpt-unit-engine', CPT_PLUGIN_URL . 'assets/unit.js', [], CPT_VERSION, true);
}

/**
 * Enqueue at wp_enqueue_scripts so the stylesheet prints in <head>, BEFORE the
 * per-unit inline override <style> that ships inside the unit HTML (page
 * content always comes after wp_head).
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_singular()) {
        return;
    }
    $post = get_post();
    if ($post && has_shortcode($post->post_content, 'course_unit')) {
        cpt_unit_engine_enqueue_assets();
    }
});

/**
 * The tracker script is enqueued at wp_enqueue_scripts, before shortcodes run.
 * Detect the shortcode in the page content so the enqueue condition passes.
 */
add_filter('cpt_should_load_tracker', function ($load, $post_id) {
    if ($load) {
        return $load;
    }
    $post = get_post($post_id);
    return $post && has_shortcode($post->post_content, 'course_unit');
}, 10, 2);
