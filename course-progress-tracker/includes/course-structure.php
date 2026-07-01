<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canonical course structure - the single source of truth for the
 * between-units navigation (the top bar that links unit 1..9).
 *
 * This replaces the hand-pasted "course-nav-final" HTML block that used to
 * live inside every unit page. Editing the course order / titles / images
 * now happens in ONE place. Per-unit content (title, banner, intro) is pulled
 * from the WordPress page itself (title / featured image / excerpt) so the
 * author edits those with no code.
 *
 * Each entry: number (the shortcode id), slug (the /aia/{slug}/ permalink
 * segment), title, and img (the circular thumbnail in the top nav).
 */
function cpt_course_units() {
    $base = 'https://tikshuv.chepti.com/aia/';
    $up   = 'https://tikshuv.chepti.com/wp-content/uploads/2025/07/';
    $units = [
        ['n' => 1, 'slug' => 'consult',     'title' => 'התייעצות',     'img' => $up . '7f47ae02-05e5-4749-bc89-6abbfb92b1cf-300x225.jpg'],
        ['n' => 2, 'slug' => 'classic',     'title' => 'חומרי ליבה',   'img' => $up . '1bc53846-ec29-4372-bf70-7d136c36adb6-300x225.jpg'],
        ['n' => 3, 'slug' => 'visual',      'title' => 'ויזואליה',     'img' => $up . '9468026b-b6cd-479c-b8c2-5fb01902bf46.jpg'],
        ['n' => 4, 'slug' => 'charts',      'title' => 'תרשימים',      'img' => $up . '4af7551d-c742-4e3e-be7c-8fde12dc3737-300x225.jpg'],
        ['n' => 5, 'slug' => 'interactive', 'title' => 'אינטראקטיבי',  'img' => $up . 'fdca443c-e083-4d1c-b4ea-4b146a11fe17-300x225.jpg'],
        ['n' => 6, 'slug' => 'media',       'title' => 'אודיו ווידאו', 'img' => $up . '1383374a-193e-49aa-b23a-44de7e55d244-300x225.jpg'],
        ['n' => 7, 'slug' => 'apps',        'title' => 'אפליקציות',    'img' => $up . '240f220f-f4e6-4b8d-a6f5-a3f1d0c23c64-300x225.jpg'],
        ['n' => 8, 'slug' => 'assessment',  'title' => 'הערכה',        'img' => $up . '3ea11d54-2be9-4d9d-ba39-2cb983d11da3-300x225.jpg'],
        ['n' => 9, 'slug' => 'portfolio',   'title' => 'ארגון וניהול', 'img' => $up . 'c2426c07-18ca-45f5-9a65-9033f7538667-300x225.jpg'],
    ];
    foreach ($units as &$u) {
        $u['url'] = $base . $u['slug'] . '/';
    }
    unset($u);
    return apply_filters('cpt_course_units', $units);
}

/**
 * URL of the "סרטונים לחסומים" (videos for blocked users) page, shown in the
 * corner of the top nav. Filterable so it is not hard-coded forever.
 */
function cpt_blocked_videos_url() {
    return apply_filters('cpt_blocked_videos_url', 'https://tikshuv.chepti.com/aia/blocked-videos/');
}

/**
 * Render the between-units navigation bar. $current is the active unit number.
 */
function cpt_render_course_topnav($current) {
    $units = cpt_course_units();
    $current = intval($current);

    $html  = '<nav class="course-topnav" aria-label="ניווט בין יחידות הקורס">';
    $html .= '<ul class="course-topnav-list">';
    foreach ($units as $u) {
        $is_active = ($u['n'] === $current);
        $html .= '<li class="course-topnav-item' . ($is_active ? ' is-active' : '') . '">';
        $html .= '<a href="' . esc_url($u['url']) . '"' . ($is_active ? ' aria-current="page"' : '') . '>';
        $html .= '<span class="course-topnav-thumb">';
        $html .= '<img src="' . esc_url($u['img']) . '" alt="" loading="lazy">';
        $html .= '<span class="course-topnav-num">' . intval($u['n']) . '</span>';
        $html .= '</span>';
        $html .= '<span class="course-topnav-text">' . esc_html($u['title']) . '</span>';
        $html .= '</a></li>';
    }
    $html .= '</ul>';
    $html .= '</nav>';
    return $html;
}
