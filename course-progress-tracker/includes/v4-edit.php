<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Structural edits of a v4 unit - one implementation shared by the front-end
 * editor (admin-ajax cpt_v4_edit) and the agent API (includes/agent-api.php).
 */

function cpt_v4_new_id($prefix, $taken) {
    do {
        $id = $prefix . '-' . strtolower(wp_generate_password(5, false, false));
    } while (isset($taken[$id]));
    return $id;
}

function cpt_v4_guess_kind($html, $current) {
    if ($current === 'discussion' || $current === 'task') { return $current; }
    if (cpt_v4_video_ids($html)) { return 'video'; }
    if (stripos($html, '<iframe') !== false || strpos($html, '[canva:') !== false) { return 'embed'; }
    return 'read';
}

/** Same allow-list as the editor's cpt_kses_section_html(), for already-unslashed input. */
function cpt_v4_kses($html) {
    $allowed = wp_kses_allowed_html('post');
    $allowed['iframe'] = [
        'src' => true, 'width' => true, 'height' => true, 'style' => true,
        'allow' => true, 'allowfullscreen' => true, 'loading' => true,
        'frameborder' => true, 'title' => true,
    ];
    $allowed['button'] = ['class' => true, 'type' => true];
    $allowed['pre']    = ['class' => true, 'style' => true];
    return wp_kses((string) $html, $allowed);
}

/**
 * Apply ONE edit to a unit. $a holds only the fields the caller sent (already
 * unslashed): id, title, html, dir ('up'|'down'), rule, comments (bool),
 * kind ('auto'|'discussion'|'task'), blocked_url.
 *
 * Ops: unit_save, chapter_add, chapter_save, chapter_move, chapter_delete,
 *      chapter_addgroup, group_save, group_move, group_delete, group_additem,
 *      item_save, item_move, item_delete.
 *
 * Returns ['ok' => bool, 'message' => string, 'data' => unit, 'focus' => id].
 */
function cpt_v4_apply_edit($data, $op, $a) {
    $id    = isset($a['id']) ? (string) $a['id'] : '';
    $title = isset($a['title']) ? sanitize_text_field($a['title']) : '';
    $has_html = array_key_exists('html', $a);
    $html  = $has_html ? cpt_v4_kses($a['html']) : '';
    $dir   = (isset($a['dir']) && $a['dir'] === 'up') ? -1 : 1;
    $idx   = cpt_v4_index($data);
    $fail  = function ($msg) use ($data) {
        return ['ok' => false, 'message' => $msg, 'data' => $data, 'focus' => ''];
    };
    $done  = function ($data, $focus) {
        return ['ok' => true, 'message' => '', 'data' => $data, 'focus' => $focus];
    };
    $swap = function ($list, $i, $dir) {
        $j = $i + $dir;
        if ($j >= 0 && $j < count($list)) { $t = $list[$i]; $list[$i] = $list[$j]; $list[$j] = $t; }
        return $list;
    };

    if ($op === 'unit_save') {
        if (isset($a['title'])) { $data['sidebar_title'] = $title; }
        if (isset($a['blocked_url'])) { $data['blocked_url'] = esc_url_raw($a['blocked_url']); }
        return $done($data, '');
    }
    if ($op === 'chapter_add') {
        $new = cpt_v4_new_id('ch', $idx['chapters']);
        $data['chapters'][] = ['id' => $new, 'title' => $title !== '' ? $title : 'פרק חדש', 'intro' => $html, 'groups' => []];
        return $done($data, $new);
    }

    foreach ($data['chapters'] as $ci => $ch) {
        if (strpos($op, 'chapter_') === 0) {
            if ($ch['id'] !== $id) { continue; }
            if ($op === 'chapter_save') {
                if ($title !== '') { $data['chapters'][$ci]['title'] = $title; }
                if ($has_html) { $data['chapters'][$ci]['intro'] = $html; }
                return $done($data, $id);
            }
            if ($op === 'chapter_move') {
                $data['chapters'] = $swap($data['chapters'], $ci, $dir);
                return $done($data, $id);
            }
            if ($op === 'chapter_delete') {
                if (!empty($ch['groups'])) { return $fail('אפשר למחוק רק פרק ריק. יש למחוק קודם את תתי-הפרקים שבו.'); }
                array_splice($data['chapters'], $ci, 1);
                return $done($data, '');
            }
            if ($op === 'chapter_addgroup') {
                $new = cpt_v4_new_id('g', $idx['groups']);
                $data['chapters'][$ci]['groups'][] = ['id' => $new, 'title' => $title !== '' ? $title : 'תת-פרק חדש', 'intro' => $html, 'rule' => 'one', 'items' => []];
                return $done($data, $new);
            }
            return $fail('פעולה לא מוכרת: ' . $op);
        }

        $groups = isset($ch['groups']) ? $ch['groups'] : [];
        foreach ($groups as $gi => $g) {
            if (strpos($op, 'group_') === 0) {
                if ($g['id'] !== $id) { continue; }
                if ($op === 'group_save') {
                    if ($title !== '') { $groups[$gi]['title'] = $title; }
                    if ($has_html) { $groups[$gi]['intro'] = $html; }
                    if (isset($a['rule'])) {
                        $rule = sanitize_key((string) $a['rule']);
                        $groups[$gi]['rule'] = in_array($rule, ['one', 'all', 'optional'], true) ? $rule : max(1, (int) $rule);
                    }
                } elseif ($op === 'group_move') {
                    $groups = $swap($groups, $gi, $dir);
                } elseif ($op === 'group_delete') {
                    if (!empty($g['items'])) { return $fail('אפשר למחוק רק תת-פרק ריק. יש למחוק קודם את הפריטים שבו.'); }
                    array_splice($groups, $gi, 1);
                    $data['chapters'][$ci]['groups'] = $groups;
                    return $done($data, $ch['id']);
                } elseif ($op === 'group_additem') {
                    $new = cpt_v4_new_id($g['id'], $idx['items']);
                    $groups[$gi]['items'][] = ['id' => $new, 'title' => $title !== '' ? $title : 'פריט חדש',
                        'kind' => cpt_v4_guess_kind($html, 'read'), 'html' => $html, 'comments' => false];
                    $data['chapters'][$ci]['groups'] = $groups;
                    return $done($data, $new);
                } else {
                    return $fail('פעולה לא מוכרת: ' . $op);
                }
                $data['chapters'][$ci]['groups'] = $groups;
                return $done($data, $id);
            }

            if (strpos($op, 'item_') !== 0) { return $fail('פעולה לא מוכרת: ' . $op); }
            $items = isset($g['items']) ? $g['items'] : [];
            foreach ($items as $ii => $it) {
                if ($it['id'] !== $id) { continue; }
                $focus = $id;
                if ($op === 'item_save') {
                    if ($title !== '') { $items[$ii]['title'] = $title; }
                    if ($has_html) { $items[$ii]['html'] = $html; }
                    if (isset($a['comments'])) { $items[$ii]['comments'] = (bool) $a['comments']; }
                    if (isset($a['kind']) && in_array($a['kind'], ['auto', 'discussion', 'task'], true)) {
                        $items[$ii]['kind'] = $a['kind'] === 'auto' ? 'read' : $a['kind'];
                    }
                    $items[$ii]['kind'] = cpt_v4_guess_kind(isset($items[$ii]['html']) ? $items[$ii]['html'] : '', isset($items[$ii]['kind']) ? $items[$ii]['kind'] : 'read');
                } elseif ($op === 'item_move') {
                    $items = $swap($items, $ii, $dir);
                } elseif ($op === 'item_delete') {
                    array_splice($items, $ii, 1);
                    $focus = $g['id'];
                } else {
                    return $fail('פעולה לא מוכרת: ' . $op);
                }
                $data['chapters'][$ci]['groups'][$gi]['items'] = $items;
                return $done($data, $focus);
            }
        }
    }
    return $fail('הפעולה לא בוצעה: לא נמצא היעד');
}

add_action('wp_ajax_cpt_v4_edit', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'אין הרשאה'], 403); }
    check_ajax_referer('cpt_fe', 'nonce');

    $slug = sanitize_text_field(wp_unslash(isset($_POST['slug']) ? $_POST['slug'] : ''));
    $data = cpt_v4_get($slug);
    if (!$data) { wp_send_json_error(['message' => 'היחידה לא נמצאה'], 404); }
    $op = sanitize_key(isset($_POST['op']) ? $_POST['op'] : '');
    $a = [];
    foreach (['id', 'title', 'html', 'dir', 'rule', 'kind', 'blocked_url'] as $k) {
        if (isset($_POST[$k])) { $a[$k] = wp_unslash($_POST[$k]); }
    }
    if (isset($_POST['comments'])) { $a['comments'] = $_POST['comments'] === '1'; }

    $res = cpt_v4_apply_edit($data, $op, $a);
    if (!$res['ok']) { wp_send_json_error(['message' => $res['message']], 400); }
    cpt_v4_save($slug, $res['data']);
    wp_send_json_success(['unit' => cpt_v4_payload($res['data'], true), 'focus' => $res['focus']]);
});
