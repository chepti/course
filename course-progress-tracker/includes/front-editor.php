<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Front-end inline editing (Phase 2b) - admins only.
 *
 * On a unit page, an admin gets an "edit mode" toggle; turning it on reveals
 * ✏️ affordances on the current chapter's content and on the sidebar title.
 * Editing happens in place (rich editor) and saves over AJAX into the same
 * structured content the engine renders. Learners never see any of this, and
 * it only works once a unit has been migrated to the structured format.
 */

add_action('wp_enqueue_scripts', function () {
    if (!is_singular() || !current_user_can('manage_options')) { return; }
    $post = get_post();
    if (!$post || !preg_match('/\[course_unit[^\]]*id=["\']?([a-z0-9-]+)/i', $post->post_content, $m)) {
        return;
    }
    $slug = $m[1];
    $data = function_exists('cpt_get_unit_content') ? cpt_get_unit_content($slug) : null;

    wp_enqueue_editor(); // TinyMCE + quicktags for the inline rich editor
    wp_enqueue_media();  // so the "Add Media" button's modal works
    wp_enqueue_style('cpt-fe-editor', CPT_PLUGIN_URL . 'assets/fe-editor.css', [], CPT_VERSION);
    wp_enqueue_script('cpt-fe-editor', CPT_PLUGIN_URL . 'assets/fe-editor.js', [], CPT_VERSION, true);

    $sections = [];
    if ($data && !empty($data['sections'])) {
        foreach ($data['sections'] as $s) {
            $sections[$s['id']] = isset($s['html']) ? $s['html'] : '';
        }
    }
    wp_localize_script('cpt-fe-editor', 'cptFE', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('cpt_fe'),
        'slug'         => $slug,
        'hasData'      => $data ? 1 : 0,
        'sidebarTitle' => $data ? (isset($data['sidebar_title']) ? $data['sidebar_title'] : '') : '',
        'sections'     => $sections,
        'editUrl'      => admin_url('admin.php?page=cpt-content-editor&unit=' . $slug),
    ]);
});

/**
 * Save one chapter's prose (text + [video:] tokens) and return it re-rendered.
 */
add_action('wp_ajax_cpt_fe_save_section', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'אין הרשאה'], 403); }
    check_ajax_referer('cpt_fe', 'nonce');

    $slug = sanitize_text_field(wp_unslash($_POST['slug'] ?? ''));
    $sid  = sanitize_key($_POST['section_id'] ?? '');
    $html = cpt_kses_section_html($_POST['html'] ?? '');

    $data = cpt_get_unit_content($slug);
    if (!$data || $sid === '') { wp_send_json_error(['message' => 'לא נמצא תוכן'], 404); }

    $found = null;
    foreach ($data['sections'] as &$s) {
        if ($s['id'] === $sid) { $s['html'] = $html; $found = $s; break; }
    }
    unset($s);
    if (!$found) { wp_send_json_error(['message' => 'הפרק לא נמצא'], 404); }

    cpt_save_unit_content($slug, $data);
    wp_send_json_success(['rendered' => cpt_build_section_html($found)]);
});

/**
 * Save the sidebar title.
 */
add_action('wp_ajax_cpt_fe_save_title', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'אין הרשאה'], 403); }
    check_ajax_referer('cpt_fe', 'nonce');

    $slug  = sanitize_text_field(wp_unslash($_POST['slug'] ?? ''));
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $data  = cpt_get_unit_content($slug);
    if (!$data) { wp_send_json_error(['message' => 'לא נמצא תוכן'], 404); }

    $data['sidebar_title'] = $title;
    cpt_save_unit_content($slug, $data);
    wp_send_json_success(['title' => $title]);
});
