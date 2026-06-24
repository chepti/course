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

function cpt_delete_unit_content($slug) {
    return delete_option(cpt_unit_content_option_key($slug));
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
 * Build the <iframe> markup for one video (carrying the data-track-video
 * attribute the progress tracker relies on).
 */
function cpt_video_html($url, $title, $section_id) {
    $embed = cpt_youtube_embed_url($url);
    if ($embed === '') { return ''; }
    $h = '<div class="video-container">';
    if (trim((string) $title) !== '') {
        $h .= '<div class="video-title">' . esc_html(trim($title)) . '</div>';
    }
    $h .= '<iframe src="' . esc_url($embed) . '" allowfullscreen data-track-video="' . esc_attr($section_id) . '"></iframe></div>';
    return $h;
}

/**
 * Replace inline [video: URL | optional title] tokens (placed by the author
 * inside the rich text) with the tracked video markup - so videos sit exactly
 * where they belong in the flow, not lumped at the end. A token alone in its
 * own paragraph drops the wrapping <p> so the layout stays clean.
 */
function cpt_replace_video_tokens($html, $section_id) {
    return preg_replace_callback(
        '/(?:<p>\s*)?\[video:\s*([^\]\|]+?)\s*(?:\|\s*([^\]]*?))?\s*\](?:\s*<\/p>)?/u',
        function ($m) use ($section_id) {
            return cpt_video_html($m[1], isset($m[2]) ? $m[2] : '', $section_id);
        },
        $html
    );
}

/**
 * Replace [prompt]…[/prompt] tokens with a copyable prompt box. Line breaks in
 * the authored text are preserved inside the <pre>; the empty .copy-button is
 * filled with the copy icon by the engine (unit.js, copyIcons:'inject').
 */
function cpt_replace_prompt_tokens($html) {
    return preg_replace_callback('/\[prompt\]([\s\S]*?)\[\/prompt\]/u', function ($m) {
        $txt = $m[1];
        $txt = preg_replace('/<br\s*\/?>/i', "\n", $txt);
        $txt = preg_replace('/<\/p>\s*<p[^>]*>/i', "\n\n", $txt);
        $txt = wp_strip_all_tags($txt);
        $txt = html_entity_decode($txt, ENT_QUOTES, 'UTF-8');
        return '<div class="prompt-container"><button class="copy-button"></button><pre>' . esc_html(trim($txt)) . '</pre></div>';
    }, $html);
}

/**
 * Build the content-section HTML for one section: the author's rich text
 * (with inline [video:..] and [prompt]..[/prompt] tokens resolved in place),
 * then any videos from the structured list appended at the end.
 */
function cpt_build_section_html($section) {
    $id   = $section['id'];
    $html = isset($section['html']) ? $section['html'] : '';
    $html = cpt_replace_prompt_tokens($html);
    $html = cpt_replace_video_tokens($html, $id);

    $videos_html = '';
    if (!empty($section['videos']) && is_array($section['videos'])) {
        foreach ($section['videos'] as $v) {
            $videos_html .= cpt_video_html(
                isset($v['url']) ? $v['url'] : '',
                isset($v['title']) ? $v['title'] : '',
                $id
            );
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
