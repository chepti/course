<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Course manifest - the single source of truth for the course structure:
 * which units exist (post_id, order, title) and which sections each unit has,
 * including what is required to complete each section.
 *
 * Stored in the option 'cpt_course_manifest' as:
 * [
 *   'units' => [
 *     [
 *       'post_id' => 123,
 *       'number' => 1,
 *       'title' => 'יחידה 1: היכרות עם בינה מלאכותית',
 *       'sections' => [
 *         ['id' => 'overview',   'title' => 'מבט על',  'require' => ['video_watch' => 1]],
 *         ['id' => 'tools_demo', 'title' => 'כלים',     'require' => ['video_watch' => 4]],
 *         ['id' => 'discussion', 'title' => 'דיון',     'require' => ['comment' => 1]],
 *         ['id' => 'task',       'title' => 'משימה',    'require' => ['manual_check' => 1]],
 *       ],
 *     ],
 *   ],
 * ]
 *
 * Supported 'require' keys: video_watch, comment, manual_check, button_click, scroll.
 * Optional per-section 'weight' (default 1) controls its share of the unit percent.
 */

function cpt_manifest_get() {
    static $manifest = null;
    if ($manifest === null) {
        $manifest = get_option('cpt_course_manifest', []);
        if (!is_array($manifest)) {
            $manifest = [];
        }
    }
    return $manifest;
}

function cpt_manifest_get_units() {
    $manifest = cpt_manifest_get();
    return isset($manifest['units']) && is_array($manifest['units']) ? $manifest['units'] : [];
}

function cpt_manifest_get_unit($post_id) {
    foreach (cpt_manifest_get_units() as $unit) {
        if (intval($unit['post_id']) === intval($post_id)) {
            return $unit;
        }
    }
    return null;
}

/**
 * Find a section definition for a unit. Matches the exact id first,
 * then treats sub-sections ("tools_demo_x") as belonging to their parent.
 */
function cpt_manifest_get_section($post_id, $section_id) {
    $unit = cpt_manifest_get_unit($post_id);
    if (!$unit || empty($unit['sections'])) {
        return null;
    }
    foreach ($unit['sections'] as $section) {
        if ($section['id'] === $section_id) {
            return $section;
        }
    }
    foreach ($unit['sections'] as $section) {
        if (strpos($section_id, $section['id'] . '_') === 0) {
            return $section;
        }
    }
    return null;
}

/**
 * Did the admin actually edit section titles? The autobuild sets title = id;
 * as long as that is the case, learner-facing views collapse to the classic
 * four groups (מבט על / כלים / דיון / משימה) instead of listing raw ids.
 */
function cpt_manifest_unit_is_curated($unit_def) {
    if (empty($unit_def['sections'])) {
        return false;
    }
    foreach ($unit_def['sections'] as $section) {
        if (!empty($section['title']) && $section['title'] !== $section['id']) {
            return true;
        }
    }
    return false;
}

/**
 * Build a manifest skeleton from existing data: every post_id seen in the
 * activity table becomes a unit, every section seen becomes a section with
 * requirements equivalent to the legacy heuristics. A starting point to edit.
 */
function cpt_manifest_autobuild() {
    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;

    $rows = $wpdb->get_results(
        "SELECT post_id, section_id, COUNT(DISTINCT user_id) AS users
         FROM $activity_table GROUP BY post_id, section_id"
    );

    $units = [];
    foreach ($rows as $row) {
        $post_id = intval($row->post_id);
        $title = get_the_title($post_id);
        if ($title === '' || strpos($title, 'פרק בדיקה') !== false) {
            continue;
        }
        if (!isset($units[$post_id])) {
            $number = 999;
            if (preg_match('/יחידה\s*(\d+)/', $title, $m)) {
                $number = intval($m[1]);
            }
            $units[$post_id] = [
                'post_id' => $post_id,
                'number' => $number,
                'title' => preg_replace('/^פרטי:\s*/', '', $title),
                'sections' => [],
            ];
        }
        $sid = $row->section_id;
        // Legacy-equivalent default requirements
        if (strpos($sid, 'overview') === 0 || strpos($sid, 'intro') === 0) {
            $require = ['video_watch' => 1];
        } elseif (strpos($sid, 'tools_demo') === 0 || strpos($sid, 'tools_intermediaries') === 0) {
            $require = ['video_watch' => 4];
        } elseif (strpos($sid, 'tools') === 0) {
            $require = ['video_watch' => 3];
        } elseif (strpos($sid, 'discussion') === 0) {
            $require = ['comment' => 1];
        } elseif (strpos($sid, 'task') === 0 || strpos($sid, 'assignment') === 0) {
            $require = ['manual_check' => 1];
        } else {
            $require = [];
        }
        $units[$post_id]['sections'][] = [
            'id' => $sid,
            'title' => $sid,
            'require' => $require,
        ];
    }

    $units = array_values($units);
    usort($units, function ($a, $b) {
        return $a['number'] <=> $b['number'];
    });
    return ['units' => $units];
}

// ---------- Admin page ----------

function cpt_manifest_admin_menu() {
    add_submenu_page(
        'course-progress-tracker',
        'מבנה הקורס',
        '🗺️ מבנה הקורס',
        'manage_options',
        'cpt-course-manifest',
        'cpt_manifest_admin_page'
    );
}
add_action('admin_menu', 'cpt_manifest_admin_menu', 20);

function cpt_manifest_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = '';

    if (isset($_POST['cpt_manifest_json']) && check_admin_referer('cpt_manifest_save')) {
        $raw = wp_unslash($_POST['cpt_manifest_json']);
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $notice = '<div class="notice notice-error"><p>JSON לא תקין: ' . esc_html(json_last_error_msg()) . ' — לא נשמר.</p></div>';
        } elseif (!isset($decoded['units']) || !is_array($decoded['units'])) {
            $notice = '<div class="notice notice-error"><p>חסר מפתח "units" — לא נשמר.</p></div>';
        } else {
            $errors = [];
            foreach ($decoded['units'] as $i => $unit) {
                if (empty($unit['post_id']) || !is_numeric($unit['post_id'])) {
                    $errors[] = "יחידה #$i: חסר post_id מספרי";
                } elseif (!get_post(intval($unit['post_id']))) {
                    $errors[] = "יחידה #$i: post_id " . intval($unit['post_id']) . ' לא קיים באתר';
                }
                if (empty($unit['sections']) || !is_array($unit['sections'])) {
                    $errors[] = "יחידה #$i: חסר sections";
                } else {
                    foreach ($unit['sections'] as $j => $section) {
                        if (empty($section['id'])) {
                            $errors[] = "יחידה #$i פרק #$j: חסר id";
                        }
                    }
                }
            }
            if ($errors) {
                $notice = '<div class="notice notice-error"><p>שגיאות במבנה — לא נשמר:<br>' . esc_html(implode(' | ', $errors)) . '</p></div>';
            } else {
                update_option('cpt_course_manifest', $decoded);
                $notice = '<div class="notice notice-success"><p>מבנה הקורס נשמר בהצלחה (' . count($decoded['units']) . ' יחידות).</p></div>';
            }
        }
    }

    if (isset($_POST['cpt_manifest_autobuild']) && check_admin_referer('cpt_manifest_save')) {
        $built = cpt_manifest_autobuild();
        update_option('cpt_course_manifest', $built);
        $notice = '<div class="notice notice-success"><p>המבנה נבנה אוטומטית מנתוני הפעילות (' . count($built['units']) . ' יחידות). עברי על ההגדרות וערכי כותרות ודרישות.</p></div>';
    }

    $manifest = get_option('cpt_course_manifest', []);
    $json = $manifest ? wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';

    echo '<div class="wrap" dir="rtl">';
    echo '<h1>מבנה הקורס (Manifest)</h1>';
    echo $notice;
    echo '<p>כאן מוגדר מבנה הקורס במקום אחד: אילו יחידות יש, אילו פרקים בכל יחידה, ומה נדרש להשלמת כל פרק. ההגדרה הזו קובעת את חישוב ההתקדמות, את הדשבורד של הלומד ואת הדוחות.</p>';
    echo '<p>מפתחות דרישה נתמכים ב-<code>require</code>: <code>video_watch</code> (מספר סרטונים), <code>comment</code>, <code>manual_check</code>, <code>button_click</code>, <code>scroll</code>. אפשר להוסיף <code>weight</code> לפרק כדי לשנות את משקלו באחוז הכולל.</p>';
    echo '<form method="post">';
    wp_nonce_field('cpt_manifest_save');
    echo '<textarea name="cpt_manifest_json" rows="30" style="width:100%; font-family:monospace; direction:ltr; text-align:left;" placeholder=\'{"units": [...]}\'>'
        . esc_textarea($json) . '</textarea>';
    echo '<p><button type="submit" class="button button-primary">שמירת המבנה</button> ';
    echo '<button type="submit" name="cpt_manifest_autobuild" value="1" class="button" onclick="return confirm(\'לבנות מבנה אוטומטית מנתוני הפעילות? זה ידרוס את ההגדרה הנוכחית.\');">בנייה אוטומטית מנתוני הפעילות</button></p>';
    echo '</form>';
    echo '</div>';
}
