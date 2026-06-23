<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Structured unit content (Phase 2) - the data model behind the no-code editor.
 *
 * Each unit's content is stored as an option `cpt_unit_content_{slug}` (slug =
 * the [course_unit id="..."] value, e.g. "1".."9"). Shape:
 *
 * [
 *   'sidebar_title' => 'string',
 *   'primary_color' => '#8E44AD',
 *   'sections' => [
 *     [
 *       'id'     => 'overview',     // stable key (a-z0-9_)
 *       'title'  => 'במבט על',      // Hebrew label shown in the curriculum
 *       'parent' => '',             // '' = main chapter, else the parent section id
 *       'videos' => [ ['url'=>'https://youtu.be/..', 'title'=>'..'], ... ],
 *       'html'   => '<p>rich text…</p>',
 *     ],
 *   ],
 * ]
 *
 * The renderer turns this into the SAME slim-format markup the canonical engine
 * (assets/unit.js + unit.css) already drives - so no rendering logic is
 * duplicated: we emit #nav + window.cptUnitContent/cptUnitConfig, unit.js does
 * the rest.
 */

function cpt_unit_content_option_key($slug) {
    return 'cpt_unit_content_' . preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));
}

function cpt_get_unit_content($slug) {
    $data = get_option(cpt_unit_content_option_key($slug), null);
    if (!is_array($data) || empty($data['sections']) || !is_array($data['sections'])) {
        return null;
    }
    return $data;
}

function cpt_save_unit_content($slug, $data) {
    return update_option(cpt_unit_content_option_key($slug), $data, false);
}

/**
 * Normalise a YouTube (or other) URL to its embeddable form.
 */
function cpt_youtube_embed_url($url) {
    $url = trim($url);
    if ($url === '') { return ''; }
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|live/|shorts/))([A-Za-z0-9_-]{11})~', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    return esc_url_raw($url); // already an embed URL or a non-YouTube embed
}

/**
 * Build the content-section HTML for one section: the author's rich text,
 * followed by each video (with the data-track-video attribute that the
 * progress tracker relies on).
 */
function cpt_build_section_html($section) {
    $id   = $section['id'];
    $html = isset($section['html']) ? $section['html'] : '';

    $videos_html = '';
    if (!empty($section['videos']) && is_array($section['videos'])) {
        foreach ($section['videos'] as $v) {
            $embed = cpt_youtube_embed_url(isset($v['url']) ? $v['url'] : '');
            if ($embed === '') { continue; }
            $vtitle = isset($v['title']) ? trim($v['title']) : '';
            $videos_html .= '<div class="video-container">';
            if ($vtitle !== '') {
                $videos_html .= '<div class="video-title">' . esc_html($vtitle) . '</div>';
            }
            $videos_html .= '<iframe src="' . esc_url($embed) . '" allowfullscreen data-track-video="' . esc_attr($id) . '"></iframe>';
            $videos_html .= '</div>';
        }
    }

    return '<div class="content-section" data-track-section="' . esc_attr($id) . '">'
         . $html . $videos_html . '</div>';
}

/**
 * Render a full unit from structured data into slim-format markup
 * (style override + container + #nav + window.cptUnitContent/cptUnitConfig).
 */
function cpt_render_unit_from_data($data) {
    $color    = !empty($data['primary_color']) ? $data['primary_color'] : '#2a8c8c';
    $sidebar  = isset($data['sidebar_title']) ? $data['sidebar_title'] : '';
    $sections = isset($data['sections']) && is_array($data['sections']) ? $data['sections'] : [];

    // split into mains (in order) and their children
    $mains = [];
    $children = [];
    foreach ($sections as $s) {
        if (empty($s['id'])) { continue; }
        if (empty($s['parent'])) {
            $mains[] = $s;
        } else {
            $children[$s['parent']][] = $s;
        }
    }

    $out  = '<style>body #interactive-unit-container.cpt-engine-v2{--primary-color:' . esc_attr($color) . ';}</style>';
    $out .= '<div id="interactive-unit-container" class="cpt-engine-v2">';
    $out .= '<div id="content"><div class="progress-bar"><div class="progress-indicator"></div></div><div id="content-area"></div></div>';

    // curriculum sidebar
    $out .= '<div id="nav"><div class="sidebar-title">' . esc_html($sidebar) . '</div>';
    foreach ($mains as $m) {
        $mid = $m['id'];
        $out .= '<div class="nav-item"><div class="main-item" data-section="' . esc_attr($mid) . '">'
              . '<div class="completion-circle"></div><span>' . esc_html($m['title']) . '</span></div>';
        if (!empty($children[$mid])) {
            $out .= '<div class="sub-items">';
            foreach ($children[$mid] as $c) {
                $out .= '<div class="nav-item"><div class="sub-item" data-section="' . esc_attr($c['id']) . '">'
                      . '<div class="completion-circle"></div><span>' . esc_html($c['title']) . '</span></div></div>';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
    }
    $out .= '</div></div>'; // #nav + container

    // content map for the engine
    $content_map = [];
    foreach ($sections as $s) {
        if (empty($s['id'])) { continue; }
        $content_map[$s['id']] = cpt_build_section_html($s);
    }
    $default = !empty($mains) ? $mains[0]['id'] : 'overview';

    $config = [
        'defaultSection'   => $default,
        'activeMode'       => 'self',
        'hasActiveStrip'   => true,
        'autoOpenFirstSub' => false,
        'copyIcons'        => 'inject',
        'freshWrapperCheck' => true,
    ];

    $out .= '<script>'
          . 'window.cptUnitConfig=' . wp_json_encode($config) . ';'
          . 'window.cptUnitContent=' . wp_json_encode($content_map) . ';'
          . '</script>';

    return $out;
}
