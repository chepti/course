<?php
if (!defined('ABSPATH')) { exit; }

/**
 * v4 endpoints (admin-ajax: never page-cached, not blocked by the host's WAF
 * the way REST POSTs are). Learner calls authenticate by the login cookie;
 * editing calls also need the cpt_fe nonce + manage_options.
 *
 *  cpt_v4_state   GET  post_id                     -> progress + notes + resume point
 *  cpt_v4_event   POST post_id,item,type,[data]    -> record an automatic completion
 *  cpt_v4_note    POST post_id,item,note           -> save a private note
 *  cpt_v4_edit    POST slug,op,...                 -> front-end editing (admin)
 *  cpt_v4_switch  POST on=1|0                      -> turn the structure on/off for everyone (admin)
 */

function cpt_v4_ajax_context() {
    if (!is_user_logged_in()) { wp_send_json_error(['message' => 'יש להתחבר'], 401); }
    $post_id = isset($_REQUEST['post_id']) ? intval($_REQUEST['post_id']) : 0;
    $data = $post_id ? cpt_v4_unit_for_post($post_id, true) : null;
    if (!$data) { wp_send_json_error(['message' => 'היחידה לא נמצאה'], 404); }
    return [get_current_user_id(), $post_id, $data, cpt_v4_index($data)];
}

function cpt_v4_state_response($user_id, $post_id, $data, $idx, $extra = []) {
    $state = cpt_v4_state($user_id, $post_id, $data, $idx);
    return array_merge($state, $extra);
}

add_action('wp_ajax_nopriv_cpt_v4_state', function () {
    nocache_headers();
    wp_send_json_error(['logged_in' => false], 401);
});
add_action('wp_ajax_cpt_v4_state', function () {
    nocache_headers();
    list($user_id, $post_id, $data, $idx) = cpt_v4_ajax_context();
    // resume point: an item id, or (saved by the old tracker) a sub-chapter id
    $last = cpt_get_last_position($user_id, $post_id);
    $resume = null;
    if ($last) {
        $sid = $last->section_id;
        if (isset($idx['items'][$sid])) {
            $resume = $sid;
        } elseif (isset($idx['groups'][$sid]) && !empty($idx['groups'][$sid]['items'])) {
            $resume = $idx['groups'][$sid]['items'][0];
        }
    }
    wp_send_json_success(cpt_v4_state_response($user_id, $post_id, $data, $idx, [
        'notes'  => (object) cpt_v4_notes($user_id, $post_id),
        'resume' => $resume,
    ]));
});

add_action('wp_ajax_cpt_v4_event', function () {
    list($user_id, $post_id, $data, $idx) = cpt_v4_ajax_context();
    $item = isset($_POST['item']) ? sanitize_text_field(wp_unslash($_POST['item'])) : '';
    $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
    if (!isset($idx['items'][$item])) { wp_send_json_error(['message' => 'פריט לא מוכר'], 400); }
    $meta = $idx['items'][$item];
    $mode = cpt_v4_item_mode($meta);
    $d = isset($_POST['data']) ? json_decode(wp_unslash($_POST['data']), true) : [];
    if (!is_array($d)) { $d = []; }

    $ok = false; $activity = ''; $log = ['item' => $item];
    if ($type === 'video' && $mode === 'video') {
        $vid = isset($d['video_id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $d['video_id']) : '';
        if (in_array($vid, $meta['videos'], true)) {
            $ok = true; $activity = 'video_watch';
            $log += ['video_id' => $vid, 'seconds' => isset($d['seconds']) ? (int) $d['seconds'] : 0, 'method' => 'playback'];
        }
    } elseif ($type === 'view' && ($mode === 'view' || ($mode === 'video' && !empty($d['blocked'])))) {
        // a video item counts by presence only when the player could not load (YouTube blocked)
        $ok = true; $activity = 'scroll';
        $log += ['method' => $mode === 'video' ? 'video_blocked' : 'item_view', 'time_spent' => isset($d['seconds']) ? (int) $d['seconds'] : 0];
    } elseif ($type === 'check' && $mode === 'check') {
        $ok = true; $activity = 'manual_check'; $log += ['checked' => true];
    } elseif ($type === 'uncheck' && $mode === 'check') {
        cpt_v4_unmark_done($user_id, $post_id, $item);
    } elseif ($type === 'comment' && $mode === 'comment') {
        // cpt_v4_state() verifies the comment itself
    } else {
        wp_send_json_error(['message' => 'אירוע לא מתאים לפריט'], 400);
    }

    if ($ok && cpt_v4_mark_done($user_id, $post_id, $item, $type)) {
        // keep the activity log (admin reports read it); section = the item's group
        global $wpdb;
        $wpdb->insert(CPT_ACTIVITY_TABLE_NAME, [
            'user_id' => $user_id, 'post_id' => $post_id, 'section_id' => $meta['group'],
            'activity_type' => $activity, 'activity_data' => wp_json_encode($log),
        ], ['%d', '%d', '%s', '%s', '%s']);
    }
    wp_send_json_success(cpt_v4_state_response($user_id, $post_id, $data, $idx));
});

add_action('wp_ajax_cpt_v4_note', function () {
    list($user_id, $post_id, $data, $idx) = cpt_v4_ajax_context();
    $item = isset($_POST['item']) ? sanitize_text_field(wp_unslash($_POST['item'])) : '';
    if (!isset($idx['items'][$item])) { wp_send_json_error(['message' => 'פריט לא מוכר'], 400); }
    $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
    if (mb_strlen($note) > 20000) { $note = mb_substr($note, 0, 20000); }
    cpt_v4_save_note($user_id, $post_id, $item, $note);
    wp_send_json_success(['saved' => true]);
});

add_action('wp_ajax_cpt_v4_switch', function () {
    if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'אין הרשאה'], 403); }
    check_ajax_referer('cpt_fe', 'nonce');
    $on = !empty($_POST['on']) && $_POST['on'] !== '0';
    update_option('cpt_v4_enabled', $on ? '1' : '0', true);
    wp_send_json_success(['live' => $on]);
});
