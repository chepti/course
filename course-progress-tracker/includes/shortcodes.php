<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Learner-facing shortcodes:
 * [user_course_progress] - course dashboard (all units, resume button)
 * [course_resume_button] - standalone "continue where you left off" button
 * [unit_progress unit_id="123"] - progress card for a single unit
 */

// Shared CSS, printed once per page
function cpt_shortcode_styles() {
    static $printed = false;
    if ($printed) { return ''; }
    $printed = true;
    return '<style>
        .cpt-progress-wrapper { display: grid; gap: 20px; margin-top: 20px; }
        .cpt-progress-item { padding: 20px; background: #f8f9fa; border-radius: 8px; border-right: 4px solid #ddd; position: relative; }
        .cpt-progress-item.progress-high { border-right-color: #27ae60; }
        .cpt-progress-item.progress-medium { border-right-color: #f39c12; }
        .cpt-progress-item.progress-low { border-right-color: #e74c3c; }
        .cpt-progress-item.progress-none { border-right-color: #bbb; opacity: 0.85; }
        .cpt-progress-item h4 { margin-top: 0; margin-bottom: 15px; }
        .cpt-progress-item h4 a { text-decoration: none; color: inherit; }
        .cpt-section-breakdown { margin-top: 15px; display: grid; gap: 8px; }
        .cpt-section-item { display: flex; align-items: center; gap: 10px; padding: 5px 0; border-bottom: 1px solid #e0e0e0; position: relative; }
        .cpt-section-icon { font-weight: bold; width: 20px; text-align: center; }
        .cpt-section-name { flex: 1; }
        .cpt-section-progress { font-size: 0.9em; color: #666; }
        .cpt-resume-banner { display: flex; align-items: center; gap: 12px; padding: 14px 18px; margin: 15px 0;
            background: linear-gradient(45deg, #3a757f, #27ae60); color: #fff; border-radius: 10px;
            text-decoration: none; font-weight: bold; box-shadow: 0 2px 8px rgba(0,0,0,0.15); transition: transform .2s ease; }
        .cpt-resume-banner:hover { transform: translateY(-2px); color: #fff; }
        .cpt-resume-banner .cpt-resume-sub { font-weight: normal; font-size: 0.85em; opacity: 0.9; }
    </style>';
}

// Resume banner HTML for a user (or empty string)
function cpt_resume_banner_html($user_id) {
    $last = cpt_get_last_position($user_id);
    if (!$last) {
        return '';
    }
    $post_title = preg_replace('/^פרטי:\s*/', '', get_the_title($last->post_id));
    $url = get_permalink($last->post_id);
    if (!$url) {
        return '';
    }
    $url .= (strpos($url, '?') === false ? '?' : '&') . 'cpt_resume=' . urlencode($last->section_id);

    $section_label = $last->section_id;
    $section_def = cpt_manifest_get_section($last->post_id, $last->section_id);
    if ($section_def && !empty($section_def['title'])) {
        $section_label = $section_def['title'];
    }

    return '<a class="cpt-resume-banner" href="' . esc_url($url) . '">'
        . '<span style="font-size:1.4em;">📍</span>'
        . '<span>המשך מאיפה שעצרת: ' . esc_html($post_title)
        . ' <span class="cpt-resume-sub">(' . esc_html($section_label) . ')</span></span>'
        . '</a>';
}

function cpt_resume_button_shortcode() {
    if (!is_user_logged_in()) {
        return '';
    }
    return cpt_shortcode_styles() . cpt_resume_banner_html(get_current_user_id());
}
add_shortcode('course_resume_button', 'cpt_resume_button_shortcode');

// Render one unit card (shared by dashboard + unit shortcode)
function cpt_render_unit_card($unit, $heading_tag = 'h4', $with_link = true) {
    $percent = $unit['percent'];
    $color_class = 'progress-none';
    if ($percent >= 80) {
        $color_class = 'progress-high';
    } elseif ($percent >= 50) {
        $color_class = 'progress-medium';
    } elseif ($percent > 0) {
        $color_class = 'progress-low';
    }

    $title_html = esc_html($unit['title']);
    if ($with_link && !empty($unit['url'])) {
        $title_html = '<a href="' . esc_url($unit['url']) . '">' . $title_html . '</a>';
    }

    $out = '<div class="cpt-progress-item ' . $color_class . '">';
    $out .= "<$heading_tag>" . $title_html . "</$heading_tag>";
    $out .= cpt_render_progress_bar($percent);

    $out .= '<div class="cpt-section-breakdown">';

    // Legacy Hebrew names for units without manifest titles
    $legacy_names = [
        'overview' => 'מבט על', 'intro' => 'מבט על', 'tools' => 'כלים',
        'discussion' => 'דיון', 'task' => 'משימה', 'assignment' => 'משימה',
    ];

    if (!empty($unit['section_titles'])) {
        // Manifest unit: show every defined section in order
        foreach ($unit['section_titles'] as $sid => $stitle) {
            $p = isset($unit['sections'][$sid]) ? $unit['sections'][$sid] : 0;
            $out .= cpt_render_section_row($stitle ?: $sid, $p);
        }
    } else {
        // Legacy unit: collapse to the four main sections
        $mains = ['overview' => 0, 'tools' => 0, 'discussion' => 0, 'task' => 0];
        foreach ($unit['sections'] as $sid => $p) {
            if ($sid === 'overview' || $sid === 'intro' || strpos($sid, 'overview') === 0 || strpos($sid, 'intro') === 0) {
                $mains['overview'] = max($mains['overview'], $p);
            } elseif ($sid === 'task' || $sid === 'assignment' || strpos($sid, 'task') === 0 || strpos($sid, 'assignment') === 0) {
                $mains['task'] = max($mains['task'], $p);
            } elseif (strpos($sid, 'discussion') === 0) {
                $mains['discussion'] = max($mains['discussion'], $p);
            } elseif (strpos($sid, 'tools') === 0) {
                $mains['tools'] = max($mains['tools'], $p);
            }
        }
        foreach ($mains as $key => $p) {
            $name = $key === 'task' ? 'משימה' : $legacy_names[$key];
            $out .= cpt_render_section_row($name, $p);
        }
    }

    $out .= '</div></div>';
    return $out;
}

function cpt_render_section_row($name, $progress) {
    $icon = $progress >= 100 ? '✓' : ($progress > 0 ? '◐' : '○');
    $color = $progress >= 80 ? '#27ae60' : ($progress >= 50 ? '#f39c12' : '#e74c3c');
    $row = '<div class="cpt-section-item">';
    $row .= '<span class="cpt-section-icon" style="color:' . ($progress >= 100 ? '#27ae60' : '#888') . ';">' . $icon . '</span>';
    $row .= '<span class="cpt-section-name">' . esc_html($name) . '</span>';
    $row .= '<span class="cpt-section-progress">' . round($progress) . '%</span>';
    $row .= '<div style="position:absolute; bottom:-1px; right:0; height:2px; width:' . round($progress) . '%; background:' . $color . '; border-radius:1px;"></div>';
    $row .= '</div>';
    return $row;
}

// Course dashboard
function cpt_progress_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>יש להתחבר כדי לראות את ההתקדמות.</p>';
    }

    $user_id = get_current_user_id();
    $units = cpt_get_course_summary($user_id);

    $output = cpt_shortcode_styles();
    $output .= '<h3>ההתקדמות שלי בקורס</h3>';
    $output .= cpt_resume_banner_html($user_id);

    if (empty($units)) {
        return $output . '<p>עוד לא התחלת... קדימה!</p>';
    }

    $output .= '<div class="cpt-progress-wrapper">';
    foreach ($units as $unit) {
        $output .= cpt_render_unit_card($unit);
    }
    $output .= '</div>';

    return $output;
}
add_shortcode('user_course_progress', 'cpt_progress_shortcode');

// Single unit progress
function cpt_unit_progress_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p>יש להתחבר כדי לראות את ההתקדמות.</p>';
    }

    $atts = shortcode_atts(['unit_id' => 0], $atts);
    $post_id = intval($atts['unit_id']);
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    if (!$post_id) {
        return '<p>יש לציין את מזהה היחידה (unit_id).</p>';
    }

    $user_id = get_current_user_id();
    $section_progress = cpt_get_unit_section_progress($user_id, $post_id);
    $unit_def = cpt_manifest_get_unit($post_id);

    if (empty($section_progress) && !$unit_def) {
        return '<p>עוד לא התחלת יחידה זו.</p>';
    }

    $unit = [
        'post_id' => $post_id,
        'title' => 'ההתקדמות שלי ביחידה: ' . preg_replace('/^פרטי:\s*/', '', get_the_title($post_id)),
        'url' => '',
        'percent' => cpt_get_unit_overall_progress($user_id, $post_id, $section_progress),
        'sections' => $section_progress,
        'section_titles' => ($unit_def && cpt_manifest_unit_is_curated($unit_def)) ? wp_list_pluck($unit_def['sections'], 'title', 'id') : [],
    ];

    return cpt_shortcode_styles() . cpt_render_unit_card($unit, 'h3', false);
}
add_shortcode('unit_progress', 'cpt_unit_progress_shortcode');
