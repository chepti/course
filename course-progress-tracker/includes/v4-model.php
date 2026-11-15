<?php
if (!defined('ABSPATH')) { exit; }

/**
 * v4 "item" structure - the Coursera/Udemy-style course model.
 *
 *   unit -> chapters (מבט על / כלים / דיון / משימה)
 *        -> groups   (a sub-chapter, e.g. "משחקים ולומדות") + completion rule
 *        -> items    (one tool / one video / one text) - own page, own tick
 *
 * Stored in the option `cpt_unit_v4_{slug}`:
 * [
 *   'v' => 4, 'sidebar_title' => '', 'primary_color' => '#..', 'blocked_url' => '',
 *   'chapters' => [[
 *      'id' => 'tools', 'title' => 'כלים', 'intro' => '<p>..</p>',
 *      'groups' => [[
 *          'id' => 'tools_games', 'title' => 'משחקים ולומדות', 'intro' => '',
 *          'rule' => 'one' | 'all' | 'optional' | <int>,
 *          'items' => [[ 'id' => 'tools_games-1', 'title' => 'קנבה קוד',
 *                        'kind' => 'video|read|embed|discussion|task',
 *                        'html' => '..', 'comments' => false ]],
 *      ]],
 *   ]],
 * ]
 *
 * The old option (cpt_unit_content_{slug}) is left untouched, so switching the
 * whole course back (option cpt_v4_enabled = 0) is always possible.
 *
 * Completion rule of a group = how many of its items a learner needs:
 *   'one' -> 1 (pick what interests you), 'all' -> every item,
 *   'optional' -> 0 (enrichment, never counted), int N -> N items.
 * A unit is 100% when every non-optional group met its rule - learners are
 * NOT expected to open every item.
 */

global $wpdb;
define('CPT_ITEM_TABLE', $wpdb->prefix . 'course_item_done');
define('CPT_NOTES_TABLE', $wpdb->prefix . 'course_notes');
define('CPT_V4_DB_VERSION', '1');

/** Tables are created on demand (the plugin is deployed by file copy, so the
 *  activation hook never runs on upgrades). */
add_action('plugins_loaded', function () {
    if (get_option('cpt_v4_db') === CPT_V4_DB_VERSION) { return; }
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE " . CPT_ITEM_TABLE . " (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        post_id bigint(20) NOT NULL,
        item_id varchar(190) NOT NULL,
        source varchar(20) NOT NULL DEFAULT '',
        done_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_item (user_id, post_id, item_id)
    ) $charset;");
    dbDelta("CREATE TABLE " . CPT_NOTES_TABLE . " (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        post_id bigint(20) NOT NULL,
        item_id varchar(190) NOT NULL,
        note longtext,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_item (user_id, post_id, item_id)
    ) $charset;");
    update_option('cpt_v4_db', CPT_V4_DB_VERSION, true);
});

// ---------------------------------------------------------------- storage

function cpt_v4_option_key($slug) {
    return 'cpt_unit_v4_' . preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $slug));
}

function cpt_v4_get($slug) {
    $data = get_option(cpt_v4_option_key($slug), null);
    if (!is_array($data) || empty($data['chapters']) || !is_array($data['chapters'])) {
        return null;
    }
    return $data;
}

function cpt_v4_save($slug, $data) {
    $data['v'] = 4;
    return update_option(cpt_v4_option_key($slug), $data, false);
}

/** Is the item structure live for every learner? */
function cpt_v4_enabled() {
    return get_option('cpt_v4_enabled') === '1';
}

/**
 * Should THIS request render the item structure? Live for everyone once
 * enabled; before that, admins can preview with ?v4=1 (and, after the switch,
 * look at the old view with ?v4=0).
 */
function cpt_v4_active_for_request() {
    if (current_user_can('manage_options') && isset($_GET['v4'])) {
        return $_GET['v4'] !== '0';
    }
    return cpt_v4_enabled();
}

/** The [course_unit id="..."] slug of a page (cached per request). */
function cpt_v4_slug_for_post($post_id) {
    static $cache = [];
    $post_id = (int) $post_id;
    if (isset($cache[$post_id])) { return $cache[$post_id]; }
    $slug = '';
    $post = get_post($post_id);
    if ($post && preg_match('/\[course_unit[^\]]*id=["\']?([a-z0-9-]+)/i', $post->post_content, $m)) {
        $slug = strtolower($m[1]);
    }
    return $cache[$post_id] = $slug;
}

/**
 * v4 data of a unit page, or null. $force = ignore the global switch (the v4
 * ajax endpoints and the admin preview always work on v4 data).
 */
function cpt_v4_unit_for_post($post_id, $force = false) {
    if (!$force && !cpt_v4_enabled()) { return null; }
    $slug = cpt_v4_slug_for_post($post_id);
    return $slug !== '' ? cpt_v4_get($slug) : null;
}

// ---------------------------------------------------------------- structure helpers

/** YouTube ids referenced by an item's raw html ([video:] tokens + iframes). */
function cpt_v4_video_ids($html) {
    $ids = [];
    if (preg_match_all('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|live/|shorts/))([A-Za-z0-9_-]{11})~', (string) $html, $m)) {
        foreach ($m[1] as $id) { $ids[$id] = true; }
    }
    return array_keys($ids);
}

function cpt_v4_has_done_button($html) {
    return strpos((string) $html, '[done-button:') !== false || strpos((string) $html, 'data-track-manual') !== false;
}

/** How many items a group requires. */
function cpt_v4_rule_need($rule, $count) {
    if ($rule === 'optional') { return 0; }
    if ($rule === 'all') { return $count; }
    if (is_numeric($rule)) { return max(0, min((int) $rule, $count)); }
    return min(1, $count); // 'one'
}

/**
 * Flat lookup tables of a unit:
 *  items[id]  => chapter, group, kind, videos[], done_button, comments, title
 *  groups[id] => chapter, title, rule, items[], need
 */
function cpt_v4_index($data) {
    $idx = ['items' => [], 'groups' => [], 'chapters' => []];
    foreach ($data['chapters'] as $ch) {
        $cid = $ch['id'];
        $idx['chapters'][$cid] = ['title' => $ch['title'], 'groups' => []];
        foreach ((isset($ch['groups']) ? $ch['groups'] : []) as $g) {
            $gid = $g['id'];
            $ids = [];
            foreach ((isset($g['items']) ? $g['items'] : []) as $it) {
                $html = isset($it['html']) ? $it['html'] : '';
                $idx['items'][$it['id']] = [
                    'chapter' => $cid, 'group' => $gid,
                    'title' => $it['title'],
                    'kind' => isset($it['kind']) ? $it['kind'] : 'read',
                    'videos' => cpt_v4_video_ids($html),
                    'done_button' => cpt_v4_has_done_button($html),
                    'comments' => !empty($it['comments']),
                ];
                $ids[] = $it['id'];
            }
            $rule = isset($g['rule']) ? $g['rule'] : 'one';
            $idx['groups'][$gid] = [
                'chapter' => $cid, 'title' => $g['title'], 'rule' => $rule,
                'items' => $ids, 'need' => cpt_v4_rule_need($rule, count($ids)),
            ];
            $idx['chapters'][$cid]['groups'][] = $gid;
        }
    }
    return $idx;
}

/**
 * How an item gets its tick (always automatic):
 *  'comment' - the learner posted a comment on the unit
 *  'check'   - the task's own "done" checkbox
 *  'video'   - watched (half of / 3 minutes of) one of its videos
 *  'view'    - opened and stayed on the item
 */
function cpt_v4_item_mode($meta) {
    if ($meta['kind'] === 'discussion') { return 'comment'; }
    if ($meta['done_button']) { return 'check'; }
    if (!empty($meta['videos'])) { return 'video'; }
    return 'view';
}

// ---------------------------------------------------------------- done items

function cpt_v4_mark_done($user_id, $post_id, $item_id, $source = '') {
    global $wpdb;
    unset($GLOBALS['cpt_v4_state_cache'][$user_id . ':' . $post_id]);
    return $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO " . CPT_ITEM_TABLE . " (user_id, post_id, item_id, source, done_at) VALUES (%d, %d, %s, %s, %s)",
        $user_id, $post_id, $item_id, $source, current_time('mysql')
    ));
}

function cpt_v4_unmark_done($user_id, $post_id, $item_id) {
    global $wpdb;
    unset($GLOBALS['cpt_v4_state_cache'][$user_id . ':' . $post_id]);
    return $wpdb->delete(CPT_ITEM_TABLE, ['user_id' => $user_id, 'post_id' => $post_id, 'item_id' => $item_id], ['%d', '%d', '%s']);
}

/** item_id => true, including "@g:{group}" credits carried over from the old tracker. */
function cpt_v4_done_map($user_id, $post_id) {
    global $wpdb;
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT item_id FROM " . CPT_ITEM_TABLE . " WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ));
    return is_array($rows) ? array_fill_keys($rows, true) : [];
}

/**
 * One-time, per learner per unit: carry over what the old tracker recorded,
 * so nobody loses progress when the structure changes.
 *  - a watched video ticks the item that holds that video
 *  - a section the old tracker counted as complete credits its whole group
 *  - the old unit percent is kept as a floor (the new number never drops below)
 */
function cpt_v4_migrate_legacy($user_id, $post_id, $data, $idx) {
    $flag = 'cpt_v4_migrated_' . (int) $post_id;
    if (get_user_meta($user_id, $flag, true)) { return; }
    update_user_meta($user_id, $flag, 1); // set first: never run twice, even on error

    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT section_id, activity_type, activity_data FROM " . CPT_ACTIVITY_TABLE_NAME . " WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ));
    $completed = $wpdb->get_col($wpdb->prepare(
        "SELECT section_id FROM " . CPT_TABLE_NAME . " WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ));
    if (!is_array($completed)) { $completed = []; }
    if (empty($rows) && empty($completed)) { return; }

    // old numbers, computed by the old algorithm
    $GLOBALS['cpt_v4_force_legacy'] = true;
    $old_sp  = cpt_get_unit_section_progress($user_id, $post_id);
    $old_pct = (int) cpt_get_unit_overall_progress($user_id, $post_id, $old_sp);
    unset($GLOBALS['cpt_v4_force_legacy']);
    if (!is_array($old_sp)) { $old_sp = []; }
    foreach ($old_sp as $sid => $p) {
        if ($p >= 100 && !in_array($sid, $completed, true)) { $completed[] = $sid; }
    }
    if ($old_pct > 0) { update_user_meta($user_id, 'cpt_v4_floor_' . (int) $post_id, $old_pct); }

    // the group an old section id refers to (exact id, or an old "group_sub" id)
    $groups_of = function ($sid) use ($idx) {
        $out = [];
        foreach ($idx['groups'] as $gid => $g) {
            if ($gid === $sid || strpos($sid, $gid . '_') === 0) {
                $out[] = $gid;
            }
        }
        return $out;
    };

    // The old tracker counted the whole "כלים" chapter as done once ONE of its
    // sub-chapters was complete, and the unit as done at 100% - honour both.
    $old_tools = 0;
    foreach ($old_sp as $sid => $p) {
        if (strpos($sid, 'tools') === 0) { $old_tools = max($old_tools, (int) $p); }
    }
    foreach ($idx['groups'] as $gid => $g) {
        if ($g['need'] <= 0) { continue; }
        if ($old_pct >= 100 || ($old_tools >= 100 && strpos($g['chapter'], 'tools') === 0)) {
            cpt_v4_mark_done($user_id, $post_id, '@g:' . $gid, 'legacy');
        }
    }

    $anon_video = []; // section => count of watched videos with no id recorded
    foreach ($rows as $r) {
        $d = json_decode((string) $r->activity_data, true);
        $type = $r->activity_type;
        if ($type === 'video_watch') {
            $vid = is_array($d) && !empty($d['video_id']) ? $d['video_id'] : '';
            $hit = false;
            if ($vid !== '') {
                foreach ($idx['items'] as $iid => $m) {
                    if (in_array($vid, $m['videos'], true)) { cpt_v4_mark_done($user_id, $post_id, $iid, 'legacy'); $hit = true; }
                }
            }
            if (!$hit) { $anon_video[$r->section_id] = (isset($anon_video[$r->section_id]) ? $anon_video[$r->section_id] : 0) + 1; }
        } elseif ($type === 'comment') {
            foreach ($idx['items'] as $iid => $m) {
                if ($m['kind'] === 'discussion') { cpt_v4_mark_done($user_id, $post_id, $iid, 'legacy'); }
            }
        } elseif ($type === 'manual_check') {
            foreach ($idx['items'] as $iid => $m) {
                if ($m['kind'] === 'task') { cpt_v4_mark_done($user_id, $post_id, $iid, 'legacy'); }
            }
        } elseif ($type === 'scroll' || $type === 'button_click') {
            foreach ($groups_of($r->section_id) as $gid) {
                foreach ($idx['groups'][$gid]['items'] as $iid) {
                    if (cpt_v4_item_mode($idx['items'][$iid]) === 'view') { cpt_v4_mark_done($user_id, $post_id, $iid, 'legacy'); }
                }
            }
        }
    }
    foreach ($anon_video as $sid => $n) {
        foreach ($groups_of($sid) as $gid) {
            foreach ($idx['groups'][$gid]['items'] as $iid) {
                if ($n <= 0) { break 2; }
                if (!empty($idx['items'][$iid]['videos'])) { cpt_v4_mark_done($user_id, $post_id, $iid, 'legacy'); $n--; }
            }
        }
    }
    foreach ($completed as $sid) {
        foreach ($groups_of($sid) as $gid) { cpt_v4_mark_done($user_id, $post_id, '@g:' . $gid, 'legacy'); }
    }
}

/**
 * Full progress picture of a learner in a unit.
 * [
 *   'done'     => [item ids],
 *   'groups'   => [gid => ['done','need','total','complete']],
 *   'chapters' => [cid => ['pct' => 0-100|null, 'complete' => bool]],   // null = optional only
 *   'percent'  => 0-100, 'items_done' => n, 'items_total' => n,
 * ]
 */
function cpt_v4_state($user_id, $post_id, $data, $idx = null) {
    $ck = $user_id . ':' . $post_id;
    if (isset($GLOBALS['cpt_v4_state_cache'][$ck])) { return $GLOBALS['cpt_v4_state_cache'][$ck]; }
    if ($idx === null) { $idx = cpt_v4_index($data); }
    cpt_v4_migrate_legacy($user_id, $post_id, $data, $idx);
    $done = cpt_v4_done_map($user_id, $post_id);

    // A comment posted on the unit ticks the discussion item (checked live, so
    // it works no matter how the comment was submitted).
    $pending_disc = [];
    foreach ($idx['items'] as $iid => $m) {
        if ($m['kind'] === 'discussion' && empty($done[$iid])) { $pending_disc[] = $iid; }
    }
    if ($pending_disc) {
        $check_post = apply_filters('cpt_comment_check_post_id', $post_id);
        $n = get_comments(['post_id' => $check_post, 'user_id' => $user_id, 'count' => true]);
        if ($n > 0) {
            foreach ($pending_disc as $iid) { cpt_v4_mark_done($user_id, $post_id, $iid, 'comment'); $done[$iid] = true; }
        }
    }

    $groups = [];
    $chapters = [];
    $items_done = 0;
    foreach ($idx['chapters'] as $cid => $ch) {
        $sum = 0; $n_req = 0; $all = true;
        foreach ($ch['groups'] as $gid) {
            $g = $idx['groups'][$gid];
            $d = 0;
            foreach ($g['items'] as $iid) { if (!empty($done[$iid])) { $d++; } }
            $items_done += $d;
            $credited = !empty($done['@g:' . $gid]);
            $complete = $g['need'] > 0 && ($credited || $d >= $g['need']);
            $groups[$gid] = ['done' => $d, 'need' => $g['need'], 'total' => count($g['items']), 'complete' => $complete];
            if ($g['need'] > 0) {
                $n_req++;
                $sum += $complete ? 1 : ($d / $g['need']);
                if (!$complete) { $all = false; }
            }
        }
        $chapters[$cid] = [
            'pct' => $n_req ? (int) round(100 * $sum / $n_req) : null,
            'complete' => $n_req ? $all : false,
        ];
    }

    $acc = 0; $n = 0; $all = true;
    foreach ($chapters as $c) {
        if ($c['pct'] === null) { continue; }
        $n++; $acc += $c['pct'];
        if (!$c['complete']) { $all = false; }
    }
    $percent = $n ? (int) round($acc / $n) : 0;
    if ($n && $all) { $percent = 100; }
    $floor = (int) get_user_meta($user_id, 'cpt_v4_floor_' . (int) $post_id, true);
    $percent = max($percent, min(100, $floor));

    $ids = [];
    foreach ($done as $iid => $_) { if (isset($idx['items'][$iid])) { $ids[] = $iid; } }

    return $GLOBALS['cpt_v4_state_cache'][$ck] = [
        'done' => $ids,
        'groups' => $groups,
        'chapters' => $chapters,
        'percent' => $percent,
        'items_done' => $items_done,
        'items_total' => count($idx['items']),
    ];
}

// ---------------------------------------------------------------- notes

function cpt_v4_notes($user_id, $post_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT item_id, note FROM " . CPT_NOTES_TABLE . " WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ));
    $out = [];
    foreach ((array) $rows as $r) { $out[$r->item_id] = $r->note; }
    return $out;
}

function cpt_v4_save_note($user_id, $post_id, $item_id, $note) {
    global $wpdb;
    $note = trim((string) $note);
    if ($note === '') {
        return $wpdb->delete(CPT_NOTES_TABLE, ['user_id' => $user_id, 'post_id' => $post_id, 'item_id' => $item_id], ['%d', '%d', '%s']);
    }
    return $wpdb->query($wpdb->prepare(
        "INSERT INTO " . CPT_NOTES_TABLE . " (user_id, post_id, item_id, note, updated_at) VALUES (%d, %d, %s, %s, %s)
         ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = VALUES(updated_at)",
        $user_id, $post_id, $item_id, $note, current_time('mysql')
    ));
}
