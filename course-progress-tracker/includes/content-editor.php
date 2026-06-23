<?php
if (!defined('ABSPATH')) { exit; }

/**
 * No-code unit content editor (Phase 2).
 *
 * Admin → "עריכת תוכן יחידות": pick a unit, edit its chapters (title + rich
 * text + a list of videos) and save. Stored via content-model.php and rendered
 * by the shared engine. Units with no saved content keep rendering from their
 * HTML file, so this is purely additive.
 */

// Section HTML is prose authored in the rich editor; videos live in their own
// fields. We allow iframe so pasted embeds (e.g. Canva) survive a re-save.
function cpt_kses_section_html($html) {
    $allowed = wp_kses_allowed_html('post');
    $allowed['iframe'] = [
        'src' => true, 'width' => true, 'height' => true, 'style' => true,
        'allow' => true, 'allowfullscreen' => true, 'loading' => true,
        'frameborder' => true, 'title' => true,
    ];
    return wp_kses(wp_unslash($html), $allowed);
}

function cpt_parse_videos_textarea($raw) {
    $videos = [];
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        $parts = array_map('trim', explode('|', $line, 2));
        $url = $parts[0];
        if ($url === '') { continue; }
        $videos[] = ['url' => esc_url_raw($url), 'title' => isset($parts[1]) ? sanitize_text_field($parts[1]) : ''];
    }
    return $videos;
}

function cpt_videos_to_textarea($videos) {
    if (empty($videos) || !is_array($videos)) { return ''; }
    $lines = [];
    foreach ($videos as $v) {
        $url = isset($v['url']) ? $v['url'] : '';
        $t   = isset($v['title']) ? $v['title'] : '';
        $lines[] = $t !== '' ? ($url . ' | ' . $t) : $url;
    }
    return implode("\n", $lines);
}

// ---------- Menu ----------

add_action('admin_menu', function () {
    add_submenu_page(
        'course-progress-tracker',
        'עריכת תוכן יחידות',
        '✏️ עריכת תוכן',
        'manage_options',
        'cpt-content-editor',
        'cpt_content_editor_page'
    );
}, 30);

// ---------- Page ----------

function cpt_content_editor_page() {
    if (!current_user_can('manage_options')) { return; }

    $notice = '';

    // Save
    if (isset($_POST['cpt_save_content']) && check_admin_referer('cpt_save_content')) {
        $slug = sanitize_text_field(wp_unslash($_POST['unit_slug']));
        $data = [
            'sidebar_title' => sanitize_text_field(wp_unslash($_POST['sidebar_title'] ?? '')),
            'primary_color' => sanitize_hex_color($_POST['primary_color'] ?? '') ?: '#2a8c8c',
            'sections'      => [],
        ];
        $ids = isset($_POST['sec_id']) && is_array($_POST['sec_id']) ? $_POST['sec_id'] : [];
        foreach ($ids as $i => $sid) {
            if (!empty($_POST['sec_delete'][$i])) { continue; }
            $sid = sanitize_key($sid);
            if ($sid === '') { continue; }
            $data['sections'][] = [
                'id'     => $sid,
                'title'  => sanitize_text_field(wp_unslash($_POST['sec_title'][$i] ?? '')),
                'parent' => sanitize_key($_POST['sec_parent'][$i] ?? ''),
                'videos' => cpt_parse_videos_textarea($_POST['sec_videos'][$i] ?? ''),
                'html'   => cpt_kses_section_html($_POST['sec_html'][$i] ?? ''),
            ];
        }
        // optional new section
        if (!empty($_POST['new_id'])) {
            $nid = sanitize_key($_POST['new_id']);
            if ($nid !== '') {
                $data['sections'][] = [
                    'id'     => $nid,
                    'title'  => sanitize_text_field(wp_unslash($_POST['new_title'] ?? '')),
                    'parent' => sanitize_key($_POST['new_parent'] ?? ''),
                    'videos' => cpt_parse_videos_textarea($_POST['new_videos'] ?? ''),
                    'html'   => cpt_kses_section_html($_POST['new_html'] ?? ''),
                ];
            }
        }
        cpt_save_unit_content($slug, $data);
        $notice = '<div class="notice notice-success"><p>התוכן של יחידה ' . esc_html($slug) . ' נשמר (' . count($data['sections']) . ' פרקים).</p></div>';
    }

    $unit = isset($_GET['unit']) ? sanitize_text_field(wp_unslash($_GET['unit'])) : '';

    echo '<div class="wrap" dir="rtl">';
    echo $notice;

    if ($unit === '') {
        cpt_content_editor_list();
    } else {
        cpt_content_editor_form($unit);
    }
    echo '</div>';
}

function cpt_content_editor_list() {
    echo '<h1>עריכת תוכן יחידות</h1>';
    echo '<p>בחרי יחידה לעריכה. יחידה שנערכה כאן תוצג מהתוכן הערוך; יחידה שלא נגעת בה ממשיכה להציג את הקובץ המקורי.</p>';
    echo '<table class="widefat striped" style="max-width:680px"><thead><tr><th>#</th><th>יחידה</th><th>מצב</th><th></th></tr></thead><tbody>';
    foreach (cpt_course_units() as $u) {
        $slug = (string) $u['n'];
        $has  = cpt_get_unit_content($slug) ? '✅ נערך' : '— קובץ מקורי';
        $url  = admin_url('admin.php?page=cpt-content-editor&unit=' . $slug);
        echo '<tr><td>' . intval($u['n']) . '</td><td>' . esc_html($u['title']) . '</td><td>' . $has . '</td>'
           . '<td><a class="button" href="' . esc_url($url) . '">ערוך תוכן</a></td></tr>';
    }
    echo '</tbody></table>';
}

function cpt_content_editor_form($slug) {
    $importing = isset($_GET['import']) && check_admin_referer('cpt_import_' . $slug);
    $data = $importing ? cpt_import_unit_from_file($slug) : cpt_get_unit_content($slug);
    if (!$data) {
        $data = ['sidebar_title' => '', 'primary_color' => '#2a8c8c', 'sections' => []];
    }

    $list_url   = admin_url('admin.php?page=cpt-content-editor');
    $import_url = wp_nonce_url(admin_url('admin.php?page=cpt-content-editor&unit=' . $slug . '&import=1'), 'cpt_import_' . $slug);

    echo '<h1>עריכת תוכן — יחידה ' . esc_html($slug) . '</h1>';
    echo '<p><a href="' . esc_url($list_url) . '">→ חזרה לרשימת היחידות</a> &nbsp;|&nbsp; ';
    echo '<a href="' . esc_url($import_url) . '" onclick="return confirm(\'לטעון לטופס את התוכן מהקובץ המקורי? שינויים שלא נשמרו יוחלפו. לאחר הטעינה לחצי שמירה כדי לקבע.\');">⤓ טען מהקובץ המקורי</a></p>';
    if ($importing) {
        echo '<div class="notice notice-info"><p>נטען לטופס מהקובץ (' . count($data['sections']) . ' פרקים). בדקי ולחצי <b>שמירת התוכן</b> כדי לקבע.</p></div>';
    }

    $main_options = [];
    foreach ($data['sections'] as $s) {
        if (empty($s['parent'])) { $main_options[$s['id']] = $s['title']; }
    }

    echo '<form method="post">';
    wp_nonce_field('cpt_save_content');
    echo '<input type="hidden" name="unit_slug" value="' . esc_attr($slug) . '">';

    echo '<table class="form-table"><tr><th>כותרת הסיידבר</th><td><input type="text" name="sidebar_title" class="regular-text" style="width:100%;max-width:560px" value="' . esc_attr($data['sidebar_title']) . '"></td></tr>';
    echo '<tr><th>צבע ראשי</th><td><input type="color" name="primary_color" value="' . esc_attr($data['primary_color']) . '"></td></tr></table>';

    echo '<h2>פרקים</h2>';
    echo '<p class="description">כל פרק: מזהה (אנגלית, יציב), כותרת (עברית), פרק-אב (ריק = פרק ראשי), סרטונים (שורה לכל סרטון: <code>קישור | כותרת</code>), ותוכן.</p>';

    foreach ($data['sections'] as $i => $s) {
        cpt_content_editor_section_block($i, $s, $main_options);
    }

    // new section
    echo '<hr><h2>הוספת פרק חדש</h2>';
    echo '<table class="form-table">';
    echo '<tr><th>מזהה</th><td><input type="text" name="new_id" placeholder="לדוגמה: summary"></td></tr>';
    echo '<tr><th>כותרת</th><td><input type="text" name="new_title" class="regular-text"></td></tr>';
    echo '<tr><th>פרק-אב</th><td>' . cpt_parent_select('new_parent', '', $main_options) . '</td></tr>';
    echo '<tr><th>סרטונים</th><td><textarea name="new_videos" rows="2" style="width:100%;max-width:560px" placeholder="https://youtu.be/XXXX | כותרת הסרטון"></textarea></td></tr>';
    echo '<tr><th>תוכן</th><td>';
    wp_editor('', 'new_html', ['textarea_name' => 'new_html', 'textarea_rows' => 8, 'media_buttons' => true]);
    echo '</td></tr></table>';

    echo '<p><button type="submit" name="cpt_save_content" value="1" class="button button-primary button-hero">💾 שמירת התוכן</button></p>';
    echo '</form>';
}

function cpt_parent_select($name, $current, $main_options) {
    $html = '<select name="' . esc_attr($name) . '"><option value="">— פרק ראשי —</option>';
    foreach ($main_options as $id => $title) {
        $html .= '<option value="' . esc_attr($id) . '"' . selected($current, $id, false) . '>' . esc_html($title) . ' (' . esc_html($id) . ')</option>';
    }
    $html .= '</select>';
    return $html;
}

function cpt_content_editor_section_block($i, $s, $main_options) {
    $sid = isset($s['id']) ? $s['id'] : '';
    echo '<div style="border:1px solid #ccd0d4;background:#fff;padding:12px 16px;margin:12px 0;border-radius:6px">';
    echo '<input type="hidden" name="sec_id[' . $i . ']" value="' . esc_attr($sid) . '">';
    echo '<p style="margin:0 0 8px"><b>' . esc_html($s['title'] ?: $sid) . '</b> <code>' . esc_html($sid) . '</code> '
       . '<label style="float:left;color:#b32d2e"><input type="checkbox" name="sec_delete[' . $i . ']" value="1"> מחק פרק זה</label></p>';
    echo '<table class="form-table" style="margin:0">';
    echo '<tr><th style="width:120px">כותרת</th><td><input type="text" name="sec_title[' . $i . ']" class="regular-text" value="' . esc_attr($s['title']) . '"></td></tr>';
    echo '<tr><th>פרק-אב</th><td>' . cpt_parent_select('sec_parent[' . $i . ']', isset($s['parent']) ? $s['parent'] : '', $main_options) . '</td></tr>';
    echo '<tr><th>סרטונים</th><td><textarea name="sec_videos[' . $i . ']" rows="2" style="width:100%;max-width:560px">' . esc_textarea(cpt_videos_to_textarea(isset($s['videos']) ? $s['videos'] : [])) . '</textarea></td></tr>';
    echo '<tr><th>תוכן</th><td>';
    wp_editor(isset($s['html']) ? $s['html'] : '', 'sec_html_' . $i, ['textarea_name' => 'sec_html[' . $i . ']', 'textarea_rows' => 8, 'media_buttons' => true]);
    echo '</td></tr></table>';
    echo '</div>';
}

/**
 * Best-effort import of an existing unit HTML file into the data model.
 * Handles both slim (window.cptUnitContent) and old-format (const content)
 * units; pulls the sidebar title, the nav tree, and each section's HTML, and
 * lifts video-containers out into the structured `videos` field.
 * Used to PRE-FILL the form (not saved until the admin clicks save).
 */
function cpt_import_unit_from_file($slug) {
    $slug_clean = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
    $file = CPT_PLUGIN_DIR . 'units/unit-' . $slug_clean . '.html';
    if (!file_exists($file)) { return null; }
    $src = file_get_contents($file);

    $data = ['sidebar_title' => '', 'primary_color' => '#2a8c8c', 'sections' => []];

    if (preg_match('/--primary-color:\s*(#[0-9a-fA-F]{3,6})/', $src, $m)) {
        $data['primary_color'] = $m[1];
    }
    if (preg_match('/<div class="sidebar-title">(.*?)<\/div>/su', $src, $m)) {
        $data['sidebar_title'] = trim(wp_strip_all_tags($m[1]));
    }

    // Nav tree: ordered list of (id, title, parent)
    $order = [];   // id => ['title'=>, 'parent'=>]
    if (preg_match('/<div id="nav">(.*?)<\/div>\s*<\/div>\s*<\/div>/su', $src, $navm)
        || preg_match('/<div id="nav">(.*)/su', $src, $navm)) {
        $nav = $navm[1];
        // walk main-items and the sub-items that follow them
        if (preg_match_all('/<div class="(main-item|sub-item)" data-section="([^"]+)">.*?<span>(.*?)<\/span>/su', $nav, $items, PREG_SET_ORDER)) {
            $current_main = '';
            foreach ($items as $it) {
                $is_main = ($it[1] === 'main-item');
                $id = $it[2];
                $title = trim(wp_strip_all_tags($it[3]));
                if ($is_main) { $current_main = $id; $order[$id] = ['title' => $title, 'parent' => '']; }
                else { $order[$id] = ['title' => $title, 'parent' => $current_main]; }
            }
        }
    }

    // Content map: key: `...` (slim) or 'key': `...` - capture backtick blocks
    $content_map = [];
    if (preg_match_all('/([A-Za-z0-9_]+)\s*:\s*`([\s\S]*?)`\s*(?:,|\}\s*;)/u', $src, $cm, PREG_SET_ORDER)) {
        foreach ($cm as $c) {
            $content_map[$c[1]] = $c[2];
        }
    }

    // Build sections in nav order; fall back to content-map order for any extras
    $build = function ($id, $title, $parent) use ($content_map) {
        $raw = isset($content_map[$id]) ? $content_map[$id] : '';
        // strip the outer content-section wrapper
        $raw = preg_replace('/^\s*<div class="content-section"[^>]*>/u', '', $raw);
        $raw = preg_replace('/<\/div>\s*$/u', '', $raw);
        // lift videos out
        $videos = [];
        $raw = preg_replace_callback('/<div class="video-container">([\s\S]*?)<\/div>/u', function ($mm) use (&$videos) {
            $blk = $mm[1];
            $vt = '';
            if (preg_match('/<div class="video-title">(.*?)<\/div>/su', $blk, $tm)) { $vt = trim(wp_strip_all_tags($tm[1])); }
            if (preg_match('/<iframe[^>]*src="([^"]+)"/u', $blk, $im)) {
                $videos[] = ['url' => $im[1], 'title' => $vt];
                return '';
            }
            return $mm[0];
        }, $raw);
        // drop ${copyIcon} interpolations left from template literals
        $raw = str_replace(['${copyIcon}', '${checkIcon}'], '', $raw);
        return ['id' => $id, 'title' => $title, 'parent' => $parent, 'videos' => $videos, 'html' => trim($raw)];
    };

    if ($order) {
        foreach ($order as $id => $meta) {
            $data['sections'][] = $build($id, $meta['title'], $meta['parent']);
        }
    } else {
        foreach ($content_map as $id => $_) {
            $data['sections'][] = $build($id, $id, '');
        }
    }

    return $data;
}
