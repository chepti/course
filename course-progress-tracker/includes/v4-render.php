<?php
if (!defined('ABSPATH')) { exit; }

/**
 * v4 rendering: the unit shell + the JSON payload the item engine
 * (assets/unit-v4.js) renders. Item HTML is resolved here (video / prompt /
 * canva / done-button tokens), so the browser gets ready-to-show markup.
 */

function cpt_v4_icon($name) {
    $p = [
        'film'  => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 3v18M17 3v18M3 7.5h4M3 12h18M3 16.5h4M17 7.5h4M17 16.5h4"/>',
        'play'  => '<polygon points="6 3 20 12 6 21 6 3"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . (isset($p[$name]) ? $p[$name] : '') . '</svg>';
}

/** A light "poster + play" facade; the real player loads on click (unit-v4.js). */
function cpt_v4_video_facade($yt_id, $title = '') {
    $h = '<div class="cu4-video" data-yt="' . esc_attr($yt_id) . '">';
    if (trim((string) $title) !== '') {
        $h .= '<div class="cu4-video-title">' . esc_html(trim($title)) . '</div>';
    }
    $h .= '<button type="button" class="cu4-video-play" aria-label="הפעלת הסרטון">'
        . '<img src="https://i.ytimg.com/vi/' . esc_attr($yt_id) . '/hqdefault.jpg" alt="" loading="lazy">'
        . '<span class="cu4-video-btn">' . cpt_v4_icon('play') . '</span>'
        . '</button></div>';
    return $h;
}

function cpt_v4_replace_videos($html, $item_id) {
    // [video: URL | title] tokens
    $html = preg_replace_callback(
        '/(?:<p>\s*)?\[video:\s*([^\]\|]+?)\s*(?:\|\s*([^\]]*?))?\s*\](?:\s*<\/p>)?/u',
        function ($m) use ($item_id) {
            $ids = cpt_v4_video_ids($m[1]);
            if ($ids) { return cpt_v4_video_facade($ids[0], isset($m[2]) ? $m[2] : ''); }
            return cpt_video_html($m[1], isset($m[2]) ? $m[2] : '', $item_id); // non-YouTube embed
        },
        $html
    );
    // raw YouTube iframes pasted into the content
    $html = preg_replace_callback(
        '~<iframe\b[^>]*\bsrc=["\'][^"\']*(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([A-Za-z0-9_-]{11})[^"\']*["\'][^>]*>\s*</iframe>~i',
        function ($m) { return cpt_v4_video_facade($m[1]); },
        $html
    );
    return $html;
}

function cpt_v4_render_html($html, $item_id = '') {
    $html = (string) $html;
    $html = cpt_replace_prompt_tokens($html);
    $html = cpt_replace_canva_tokens($html);
    $html = cpt_v4_replace_videos($html, $item_id);
    $html = cpt_replace_done_button_tokens($html);
    return $html;
}

/** The structure the browser engine works with. $with_raw adds the editable source (admins). */
function cpt_v4_payload($data, $with_raw = false) {
    $idx = cpt_v4_index($data);
    $chapters = [];
    foreach ($data['chapters'] as $ch) {
        $c = [
            'id' => $ch['id'], 'title' => $ch['title'],
            'intro' => cpt_v4_render_html(isset($ch['intro']) ? $ch['intro'] : ''),
            'groups' => [],
        ];
        if ($with_raw) { $c['rawIntro'] = isset($ch['intro']) ? $ch['intro'] : ''; }
        foreach ((isset($ch['groups']) ? $ch['groups'] : []) as $g) {
            $gi = $idx['groups'][$g['id']];
            $gg = [
                'id' => $g['id'], 'title' => $g['title'],
                'rule' => $gi['rule'], 'need' => $gi['need'],
                'intro' => cpt_v4_render_html(isset($g['intro']) ? $g['intro'] : ''),
                'items' => [],
            ];
            if ($with_raw) { $gg['rawIntro'] = isset($g['intro']) ? $g['intro'] : ''; }
            foreach ((isset($g['items']) ? $g['items'] : []) as $it) {
                $m = $idx['items'][$it['id']];
                $ii = [
                    'id' => $it['id'], 'title' => $it['title'],
                    'kind' => $m['kind'], 'mode' => cpt_v4_item_mode($m),
                    'videos' => $m['videos'], 'comments' => $m['comments'],
                    'html' => cpt_v4_render_html(isset($it['html']) ? $it['html'] : '', $it['id']),
                ];
                if ($with_raw) { $ii['raw'] = isset($it['html']) ? $it['html'] : ''; }
                $gg['items'][] = $ii;
            }
            $c['groups'][] = $gg;
        }
        $chapters[] = $c;
    }
    return [
        'sidebarTitle' => isset($data['sidebar_title']) ? $data['sidebar_title'] : '',
        'blockedUrl' => isset($data['blocked_url']) ? $data['blocked_url'] : '',
        'chapters' => $chapters,
    ];
}

/** Is the item structure what this page shows right now? */
function cpt_v4_page_active($post_id = 0) {
    $post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
    if (!$post_id || !cpt_v4_active_for_request()) { return false; }
    $slug = cpt_v4_slug_for_post($post_id);
    return $slug !== '' && cpt_v4_get($slug) !== null;
}

add_action('wp_enqueue_scripts', function () {
    if (!is_singular() || !cpt_v4_page_active()) { return; }
    wp_enqueue_style('cpt-rubik-font', 'https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;700&display=swap', [], null);
    wp_enqueue_style('cpt-varela-font', 'https://fonts.googleapis.com/css2?family=Varela+Round&display=swap', [], null);
    wp_enqueue_style('cpt-course-shell', CPT_PLUGIN_URL . 'assets/course-shell.css', [], CPT_VERSION);
    wp_enqueue_style('cpt-unit-v4', CPT_PLUGIN_URL . 'assets/unit-v4.css', ['cpt-course-shell'], CPT_VERSION);
    wp_enqueue_script('cpt-unit-v4', CPT_PLUGIN_URL . 'assets/unit-v4.js', [], CPT_VERSION, true);
    if (current_user_can('manage_options')) {
        wp_enqueue_editor();
        wp_enqueue_media();
        wp_enqueue_script('cpt-unit-v4-edit', CPT_PLUGIN_URL . 'assets/unit-v4-edit.js', ['cpt-unit-v4'], CPT_VERSION, true);
    }
}, 6);

add_filter('body_class', function ($classes) {
    if (is_singular() && cpt_v4_page_active()) { $classes[] = 'cpt-v4-page'; }
    return $classes;
});

/**
 * The whole unit page in the item structure: between-units nav, hero, intro,
 * the app root (filled by unit-v4.js) and the footer.
 */
function cpt_v4_render_unit($slug, $data) {
    $current = preg_match('/^(\d+)/', $slug, $m) ? intval($m[1]) : 0;
    $post_id = get_the_ID();
    $title   = $post_id ? preg_replace('/^פרטי:\s*/u', '', get_the_title($post_id)) : '';

    $banner = ($post_id && has_post_thumbnail($post_id)) ? get_the_post_thumbnail_url($post_id, 'full') : '';
    if (!$banner) {
        foreach (cpt_course_units() as $u) {
            if ($u['n'] === $current) { $banner = $u['img']; break; }
        }
    }
    $intro = '';
    $post = $post_id ? get_post($post_id) : null;
    if ($post && trim($post->post_excerpt) !== '') { $intro = wp_kses_post($post->post_excerpt); }

    $is_admin = current_user_can('manage_options');
    $color = !empty($data['primary_color']) ? $data['primary_color'] : '#2a8c8c';

    $html  = '<div class="course-shell cu4-shell is-ready" id="course-shell">';
    $html .= cpt_render_course_topnav($current);

    if ($banner || $title) {
        $style = $banner ? ' style="background-image:url(\'' . esc_url($banner) . '\')"' : '';
        $html .= '<header class="course-hero"' . $style . '><div class="course-hero-overlay">';
        if ($current) { $html .= '<span class="course-hero-kicker">יחידה ' . intval($current) . '</span>'; }
        if ($title)   { $html .= '<h1 class="course-hero-title">' . esc_html($title) . '</h1>'; }
        $html .= '</div>';
        if (!empty($data['blocked_url'])) {
            $html .= '<a class="course-hero-blocked" href="' . esc_url($data['blocked_url']) . '" target="_blank" rel="noopener" '
                   . 'title="אם יוטיוב חסום אצלך - כל סרטוני היחידה נמצאים גם בתיקייה הזו">'
                   . cpt_v4_icon('film') . ' סרטונים לחסומים</a>';
        }
        $html .= '</header>';
    }
    if ($intro !== '') { $html .= '<div class="course-intro">' . wpautop($intro) . '</div>'; }

    $html .= '<div class="cu4" id="cu4" style="--cu4-accent:' . esc_attr($color) . '">'
           . '<div class="cu4-loading"><span class="cu4-spinner"></span><span>טוען את היחידה…</span></div>'
           . '</div>';

    if (function_exists('cpt_render_course_footer')) { $html .= cpt_render_course_footer(); }
    $html .= '</div>';

    $cfg = [
        'postId'   => (int) $post_id,
        'slug'     => $slug,
        'unitNo'   => $current,
        'unitTitle' => $title,
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'loginUrl' => wp_login_url($post_id ? get_permalink($post_id) : ''),
        'isAdmin'  => $is_admin,
        'live'     => cpt_v4_enabled(),
        'unit'     => cpt_v4_payload($data, $is_admin),
    ];
    if ($is_admin) { $cfg['nonce'] = wp_create_nonce('cpt_fe'); }
    $html .= '<script>window.cptV4=' . wp_json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
    return $html;
}
