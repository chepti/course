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
    // keep prompt boxes intact (copy button + <pre>) across a re-save
    $allowed['button'] = ['class' => true, 'type' => true];
    $allowed['pre']    = ['class' => true, 'style' => true];
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

    // Save (also fires for ▲▼ reorder, which saves then swaps two chapters)
    if ((isset($_POST['cpt_save_content']) || isset($_POST['cpt_move'])) && check_admin_referer('cpt_save_content')) {
        $slug = sanitize_text_field(wp_unslash($_POST['unit_slug']));
        $data = [
            'sidebar_title' => sanitize_text_field(wp_unslash($_POST['sidebar_title'] ?? '')),
            'primary_color' => sanitize_hex_color($_POST['primary_color'] ?? '') ?: '#2a8c8c',
            'sections'      => [],
        ];
        $built_idx = []; // POST row index for each built section (for reorder mapping)
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
            $built_idx[] = $i;
        }
        // optional new section
        if (!empty($_POST['new_id'])) {
            $raw_nid = trim(wp_unslash($_POST['new_id']));
            $nid = sanitize_key($raw_nid);
            if ($nid === '') {
                $notice = '<div class="notice notice-error"><p>⚠️ הפרק החדש לא נשמר: המזהה "' . esc_html($raw_nid) . '" אינו תקין. יש להשתמש באנגלית בלבד (אותיות, מספרים, קו תחתון, מקף).</p></div>';
            } else {
                $data['sections'][] = [
                    'id'     => $nid,
                    'title'  => sanitize_text_field(wp_unslash($_POST['new_title'] ?? '')),
                    'parent' => sanitize_key($_POST['new_parent'] ?? ''),
                    'videos' => cpt_parse_videos_textarea($_POST['new_videos'] ?? ''),
                    'html'   => cpt_kses_section_html($_POST['new_html'] ?? ''),
                ];
                $built_idx[] = -1;
            }
        }

        // Apply a reorder move (swap the chapter with its neighbour)
        if (!empty($_POST['cpt_move']) && preg_match('/^(up|down)_(\d+)$/', $_POST['cpt_move'], $mv)) {
            $dir = $mv[1];
            $row = intval($mv[2]);
            $k = array_search($row, $built_idx, true);
            if ($k !== false) {
                $j = ($dir === 'up') ? $k - 1 : $k + 1;
                if ($j >= 0 && $j < count($data['sections'])) {
                    $tmp = $data['sections'][$k];
                    $data['sections'][$k] = $data['sections'][$j];
                    $data['sections'][$j] = $tmp;
                }
            }
        }

        cpt_save_unit_content($slug, $data);
        $msg = !empty($_POST['cpt_move']) ? 'הסדר עודכן' : 'התוכן נשמר';
        $notice = '<div class="notice notice-success"><p>' . $msg . ' (יחידה ' . esc_html($slug) . ', ' . count($data['sections']) . ' פרקים).</p></div>';
    }

    // Bulk import: convert every unit's HTML file into the new format and save.
    if (isset($_POST['cpt_import_all']) && check_admin_referer('cpt_import_all')) {
        $done = [];
        foreach (cpt_course_units() as $u) {
            $slug = (string) $u['n'];
            $d = cpt_import_unit_from_file($slug);
            if ($d && !empty($d['sections'])) {
                cpt_save_unit_content($slug, $d);
                $done[] = $u['n'] . ' (' . count($d['sections']) . ')';
            }
        }
        $notice = '<div class="notice notice-success"><p>הומרו ונשמרו: יחידות ' . esc_html(implode(', ', $done)) . '. עברי על כל יחידה ובדקי.</p></div>';
    }

    // Revert one unit to its original file (delete the saved content).
    if (isset($_GET['revert']) && check_admin_referer('cpt_revert_' . sanitize_text_field($_GET['revert']))) {
        $rslug = sanitize_text_field(wp_unslash($_GET['revert']));
        cpt_delete_unit_content($rslug);
        $notice = '<div class="notice notice-success"><p>יחידה ' . esc_html($rslug) . ' חזרה להצגה מהקובץ המקורי.</p></div>';
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

    // bulk import
    echo '<form method="post" style="margin:14px 0">';
    wp_nonce_field('cpt_import_all');
    echo '<button type="submit" name="cpt_import_all" value="1" class="button button-primary" '
       . 'onclick="return confirm(\'להמיר ולשמור את כל היחידות מהקבצים לתצורה החדשה? אפשר להחזיר כל יחידה בנפרד אחר כך.\');">'
       . '⤓ ייבא ושמור את כל היחידות</button> '
       . '<span class="description">המרה חד-פעמית של כל הקורס לתצורה הניתנת לעריכה.</span>';
    echo '</form>';

    echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>#</th><th>יחידה</th><th>מצב</th><th></th></tr></thead><tbody>';
    foreach (cpt_course_units() as $u) {
        $slug = (string) $u['n'];
        $data = cpt_get_unit_content($slug);
        $has  = $data ? ('✅ נערך (' . count($data['sections']) . ' פרקים)') : '— קובץ מקורי';
        $url  = admin_url('admin.php?page=cpt-content-editor&unit=' . $slug);
        echo '<tr><td>' . intval($u['n']) . '</td><td>' . esc_html($u['title']) . '</td><td>' . $has . '</td><td>';
        echo '<a class="button" href="' . esc_url($url) . '">ערוך תוכן</a>';
        if ($data) {
            $revert = wp_nonce_url(admin_url('admin.php?page=cpt-content-editor&revert=' . $slug), 'cpt_revert_' . $slug);
            echo ' <a class="button-link-delete" style="color:#b32d2e" href="' . esc_url($revert) . '" '
               . 'onclick="return confirm(\'להחזיר יחידה זו להצגה מהקובץ המקורי? התוכן הערוך יימחק.\');">החזר לקובץ</a>';
        }
        echo '</td></tr>';
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
    echo '<div class="description" style="background:#f6f7f7;border-right:4px solid #2271b1;padding:10px 14px;margin:10px 0;max-width:760px">';
    echo '<b>איך עורכים פרק:</b> מזהה (אנגלית, יציב), כותרת (עברית), פרק-אב (ריק = פרק ראשי), ותוכן.<br>';
    echo 'כדי לשבץ <b>סרטון במקום מסוים</b> בתוך הטקסט — כתבי שורה: <code>[video: קישור-יוטיוב | כותרת]</code> (הכותרת לא חובה). אפשר כמה סרטונים, כל אחד במקומו, כך שכל מקטע נשאר ברצף (כותרת → טקסט → סרטון → כפתור).<br>';
    echo 'ל<b>תיבת פרומפט להעתקה</b> — כתבי <code>[prompt]טקסט הפרומפט[/prompt]</code> (תיבה עם כפתור העתקה תיווצר אוטומטית).<br>';
    echo 'ל<b>מצגת קנבה</b> — כתבי <code>[canva: קישור-הצפייה-של-קנבה]</code> (ההטמעה הרספונסיבית תיווצר אוטומטית).<br>';
    echo 'ל<b>קו הפרדה עדין</b> בין מקטעים — כפתור הקו האופקי (⎯) בעורך.<br>';
    echo 'השדה "סרטונים (בסוף הפרק)" מוסיף סרטונים בסוף הפרק בלבד — לרוב עדיף הטוקן <code>[video:]</code>.<br>'
       . 'ל<b>כפתור "סיימתי / העליתי"</b> שמסמן השלמה (עם וי) — כתבי <code>[done-button: העליתי לחומר פתוח]</code> (הכותרת ניתנת לשינוי).';
    echo '</div>';

    foreach ($data['sections'] as $i => $s) {
        cpt_content_editor_section_block($i, $s, $main_options);
    }

    // new section
    echo '<hr><h2>הוספת פרק חדש</h2>';
    echo '<table class="form-table">';
    echo '<tr><th>מזהה <span style="color:#b32d2e">*</span></th><td><input type="text" name="new_id" placeholder="לדוגמה: summary (אנגלית בלבד, ללא רווחים)" style="width:100%;max-width:320px"><br><small style="color:#666">⚠️ חובה — אנגלית בלבד (אותיות, מספרים, _ או -). עברית לא נשמרת.</small></td></tr>';
    echo '<tr><th>כותרת</th><td><input type="text" name="new_title" class="regular-text"></td></tr>';
    echo '<tr><th>פרק-אב</th><td>' . cpt_parent_select('new_parent', '', $main_options) . '</td></tr>';
    echo '<tr><th>סרטונים (בסוף)</th><td><textarea name="new_videos" rows="2" style="width:100%;max-width:560px" placeholder="https://youtu.be/XXXX | כותרת הסרטון"></textarea></td></tr>';
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
    echo '<p style="margin:0 0 8px;display:flex;align-items:center;gap:8px">';
    echo '<button type="submit" name="cpt_move" value="up_' . $i . '" class="button button-small" title="הזז למעלה">▲</button>';
    echo '<button type="submit" name="cpt_move" value="down_' . $i . '" class="button button-small" title="הזז למטה">▼</button>';
    echo '<b>' . esc_html($s['title'] ?: $sid) . '</b>';
    echo '<label style="margin-right:auto;color:#b32d2e"><input type="checkbox" name="sec_delete[' . $i . ']" value="1"> מחק פרק זה</label></p>';
    echo '<table class="form-table" style="margin:0">';
    echo '<tr><th style="width:120px">מזהה (slug)</th><td>'
       . '<input type="text" name="sec_id[' . $i . ']" value="' . esc_attr($sid) . '" style="width:220px;font-family:monospace" pattern="[a-z0-9_-]+" title="אנגלית בלבד: אותיות קטנות, מספרים, _ או -">'
       . '<br><small style="color:#888">⚠️ שינוי המזהה ימחק היסטוריית התקדמות לפרק זה.</small>'
       . '</td></tr>';
    echo '<tr><th>כותרת</th><td><input type="text" name="sec_title[' . $i . ']" class="regular-text" value="' . esc_attr($s['title']) . '"></td></tr>';
    echo '<tr><th>פרק-אב</th><td>' . cpt_parent_select('sec_parent[' . $i . ']', isset($s['parent']) ? $s['parent'] : '', $main_options) . '</td></tr>';
    echo '<tr><th>סרטונים (בסוף)</th><td><textarea name="sec_videos[' . $i . ']" rows="2" style="width:100%;max-width:560px">' . esc_textarea(cpt_videos_to_textarea(isset($s['videos']) ? $s['videos'] : [])) . '</textarea></td></tr>';
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

    // Nav tree: ordered list of (id, title, parent). Run the item regex over
    // the WHOLE file - the only .main-item/.sub-item markup is the nav, and
    // bounding the nav region with </div> counting truncated it (dropped the
    // later chapters like discussion/task).
    $order = [];   // id => ['title'=>, 'parent'=>]
    if (preg_match_all('/<div class="(main-item|sub-item)" data-section="([^"]+)">.*?<span>(.*?)<\/span>/su', $src, $items, PREG_SET_ORDER)) {
        $current_main = '';
        foreach ($items as $it) {
            $is_main = ($it[1] === 'main-item');
            $id = $it[2];
            $title = trim(wp_strip_all_tags($it[3]));
            if ($is_main) { $current_main = $id; $order[$id] = ['title' => $title, 'parent' => '']; }
            else { $order[$id] = ['title' => $title, 'parent' => $current_main]; }
        }
    }

    // Content map: matches  key: `...`  AND  'key': `...`  AND  "key": `...`
    // (units differ - some quote the keys, which the old pattern missed,
    // leaving units 3/9 with titles but no content).
    $content_map = [];
    if (preg_match_all('/[\'"]?([A-Za-z0-9_-]+)[\'"]?\s*:\s*`([\s\S]*?)`\s*(?:,|\}\s*;)/u', $src, $cm, PREG_SET_ORDER)) {
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
        // turn each video-container into an inline [video:..] token IN PLACE,
        // so the video keeps its position in the flow (matched up to the
        // closing </iframe></div> so a nested video-title div doesn't cut it short)
        $raw = preg_replace_callback('/<div class="video-container">([\s\S]*?)<\/iframe>\s*<\/div>/u', function ($mm) {
            $blk = $mm[1];
            $vt = '';
            if (preg_match('/<div class="video-title">(.*?)<\/div>/su', $blk, $tm)) { $vt = trim(wp_strip_all_tags($tm[1])); }
            if (preg_match('/<iframe[^>]*src="([^"]+)"/u', $blk, $im)) {
                return '<p>[video: ' . $im[1] . ($vt !== '' ? ' | ' . $vt : '') . ']</p>';
            }
            return $mm[0];
        }, $raw);
        // convert manual-completion checkboxes to [done-button:] tokens so
        // they survive TinyMCE and wp_kses (both strip <input>)
        $raw = preg_replace_callback(
            '/<div[^>]*>\s*<label[^>]*>\s*<input[^>]+data-track-manual[^>]*>\s*<span>(.*?)<\/span>\s*<\/label>\s*<\/div>/su',
            function ($mm) { return '[done-button: ' . trim(wp_strip_all_tags($mm[1])) . ']'; },
            $raw
        );
        // drop ${copyIcon} interpolations left from template literals
        $raw = str_replace(['${copyIcon}', '${checkIcon}'], '', $raw);
        return ['id' => $id, 'title' => $title, 'parent' => $parent, 'videos' => [], 'html' => trim($raw)];
    };

    if ($order) {
        foreach ($order as $id => $meta) {
            $data['sections'][] = $build($id, $meta['title'], $meta['parent']);
        }
        // append any content section that isn't referenced in the nav, so no
        // content is silently dropped (e.g. an "intro" without a nav entry)
        foreach ($content_map as $cid => $_) {
            if (!isset($order[$cid])) {
                $data['sections'][] = $build($cid, $cid, '');
            }
        }
    } else {
        foreach ($content_map as $id => $_) {
            $data['sections'][] = $build($id, $id, '');
        }
    }

    return $data;
}
