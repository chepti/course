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
    $atts = shortcode_atts([
        'id'    => '',
        'chrome' => 'on',   // set chrome="off" to render only the unit body (legacy)
    ], $atts);
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
    wp_enqueue_style('cpt-varela-font',
        'https://fonts.googleapis.com/css2?family=Varela+Round&display=swap',
        [], null);

    // Fallback if the wp_enqueue_scripts detection below didn't catch the
    // shortcode (e.g. shortcode rendered outside post_content). Styles
    // enqueued this late print in the footer; the slim units' override
    // selectors are body-prefixed, so the cascade is order-independent.
    cpt_unit_engine_enqueue_assets();
    wp_enqueue_style('cpt-course-shell', CPT_PLUGIN_URL . 'assets/course-shell.css', [], CPT_VERSION);

    $body = file_get_contents($file);

    // Legacy escape hatch: render bare unit, no shell chrome.
    if (strtolower($atts['chrome']) === 'off') {
        return $body;
    }

    return cpt_render_unit_shell($slug, $body);
}
add_shortcode('course_unit', 'cpt_course_unit_shortcode');

/**
 * Wrap a unit body in the coherent course shell: between-units nav, hero
 * banner, intro and loader. Title / banner / intro come from the WordPress
 * page (title / featured image / excerpt) so the author edits them with no
 * code; the top nav comes from the central course-structure config.
 */
function cpt_render_unit_shell($slug, $body) {
    $current = is_numeric($slug) ? intval($slug) : 0;
    if (!$current) {
        // map slugs like "4-map" back to their unit number for nav highlight
        if (preg_match('/^(\d+)/', $slug, $m)) {
            $current = intval($m[1]);
        }
    }

    $post_id = get_the_ID();
    $title   = $post_id ? preg_replace('/^פרטי:\s*/u', '', get_the_title($post_id)) : '';

    $banner = '';
    if ($post_id && has_post_thumbnail($post_id)) {
        $banner = get_the_post_thumbnail_url($post_id, 'full');
    }
    if (!$banner) {
        foreach (cpt_course_units() as $u) {
            if ($u['n'] === $current) { $banner = $u['img']; break; }
        }
    }

    // Intro: only the manually-set excerpt, never an auto-generated one
    // (auto-excerpt would scrape the unit HTML).
    $intro = '';
    if ($post_id) {
        $post = get_post($post_id);
        if ($post && trim($post->post_excerpt) !== '') {
            $intro = wp_kses_post($post->post_excerpt);
        }
    }

    $html  = '<div class="course-shell" id="course-shell">';
    $html .= cpt_render_course_topnav($current);

    if ($banner || $title) {
        // Background-image (not <img>) so theme "img { height:auto }" rules
        // cannot blow up the banner; it always crops via background-size:cover.
        $style = $banner ? ' style="background-image:url(\'' . esc_url($banner) . '\')"' : '';
        $html .= '<header class="course-hero"' . $style . '>';
        $html .= '<div class="course-hero-overlay">';
        if ($current) {
            $html .= '<span class="course-hero-kicker">יחידה ' . intval($current) . '</span>';
        }
        if ($title) {
            $html .= '<h1 class="course-hero-title">' . esc_html($title) . '</h1>';
        }
        $html .= '</div></header>';
    }

    if ($intro !== '') {
        $html .= '<div class="course-intro">' . wpautop($intro) . '</div>';
    }

    // Integrated progress bar (replaces the standalone [unit_progress] card).
    // Server-rendered initial value; course-tracker.js keeps it live.
    if ($post_id && is_user_logged_in() && function_exists('cpt_get_unit_overall_progress')) {
        $uid = get_current_user_id();
        $sp  = cpt_get_unit_section_progress($uid, $post_id);
        if (!is_array($sp)) { $sp = []; }
        $pct = (int) cpt_get_unit_overall_progress($uid, $post_id, $sp);
        $html .= '<div class="course-progress">';
        $html .= '<span class="course-progress-label">ההתקדמות שלי ביחידה</span>';
        $html .= '<span class="course-progress-track"><span class="course-progress-fill" id="course-progress-fill" style="width:' . $pct . '%"></span></span>';
        $html .= '<span class="course-progress-pct" id="course-progress-pct">' . $pct . '%</span>';
        $html .= '</div>';
    }

    $html .= '<div class="course-unit-body">';
    $html .= '<div class="course-loader" aria-live="polite"><span class="spinner"></span><span>טוען את היחידה…</span></div>';
    $html .= $body;
    $html .= '</div>'; // .course-unit-body

    $html .= '</div>'; // .course-shell
    return $html;
}

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
