<?php
if (!defined('ABSPATH')) {
    exit;
}

function ltw_activate() {
    global $wpdb;
    $table = LTW_TABLE_NAME;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        campaign varchar(50) NOT NULL DEFAULT 'default',
        tip_text varchar(300) NOT NULL,
        display_name varchar(100) NOT NULL DEFAULT '',
        initials_only tinyint(1) NOT NULL DEFAULT 0,
        stars tinyint(1) NOT NULL DEFAULT 5,
        color varchar(7) NOT NULL DEFAULT '#fef3c7',
        status varchar(20) NOT NULL DEFAULT 'visible',
        ip_hash varchar(64) NOT NULL DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        KEY campaign_status (campaign, status),
        KEY created_at (created_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function ltw_color_palette() {
    return ['#fef3c7', '#fce7f3', '#dbeafe', '#d1fae5', '#ede9fe', '#ffedd5', '#e0f2fe', '#fef9c3'];
}

function ltw_pick_color() {
    $palette = ltw_color_palette();
    return $palette[array_rand($palette)];
}

function ltw_format_display_name($name, $initials_only) {
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    if (!$initials_only) {
        return $name;
    }
    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach ($parts as $part) {
        $initials .= mb_substr($part, 0, 1, 'UTF-8') . '.';
    }
    return rtrim($initials, '.');
}

function ltw_get_tips($campaign, $since_id = 0, $include_hidden = false) {
    global $wpdb;
    $table = LTW_TABLE_NAME;
    $campaign = sanitize_key($campaign);

    if ($include_hidden && current_user_can('manage_options')) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, tip_text, display_name, initials_only, stars, color, status, created_at
             FROM $table
             WHERE campaign = %s AND id > %d
             ORDER BY id DESC
             LIMIT 200",
            $campaign,
            $since_id
        ), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, tip_text, display_name, initials_only, stars, color, status, created_at
             FROM $table
             WHERE campaign = %s AND status = 'visible' AND id > %d
             ORDER BY id DESC
             LIMIT 200",
            $campaign,
            $since_id
        ), ARRAY_A);
    }

    return array_map('ltw_map_tip_row', $rows ?: []);
}

function ltw_map_tip_row($row) {
    $display = ltw_format_display_name($row['display_name'], (bool) $row['initials_only']);
    return [
        'id'           => (int) $row['id'],
        'tip'          => $row['tip_text'],
        'name'         => $display !== '' ? $display : null,
        'stars'        => (int) $row['stars'],
        'color'        => $row['color'],
        'status'       => $row['status'],
        'created_at'   => $row['created_at'],
    ];
}

function ltw_insert_tip($data) {
    global $wpdb;
    $table = LTW_TABLE_NAME;

    $inserted = $wpdb->insert($table, [
        'campaign'       => sanitize_key($data['campaign']),
        'tip_text'       => $data['tip_text'],
        'display_name'   => $data['display_name'],
        'initials_only'  => $data['initials_only'] ? 1 : 0,
        'stars'          => $data['stars'],
        'color'          => $data['color'],
        'status'         => 'visible',
        'ip_hash'        => $data['ip_hash'],
    ], ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);

    if (!$inserted) {
        return null;
    }

    $id = (int) $wpdb->insert_id;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, tip_text, display_name, initials_only, stars, color, status, created_at
         FROM $table WHERE id = %d",
        $id
    ), ARRAY_A);

    return ltw_map_tip_row($row);
}

function ltw_set_tip_status($id, $status) {
    global $wpdb;
    $table = LTW_TABLE_NAME;
    $allowed = ['visible', 'hidden'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    return (bool) $wpdb->update(
        $table,
        ['status' => $status],
        ['id' => (int) $id],
        ['%s'],
        ['%d']
    );
}

function ltw_client_ip_hash() {
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
        $ip = trim($parts[0]);
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }
    return hash('sha256', $ip . wp_salt('auth'));
}

function ltw_is_rate_limited($campaign) {
    $key = 'ltw_rate_' . sanitize_key($campaign) . '_' . ltw_client_ip_hash();
    return (bool) get_transient($key);
}

function ltw_set_rate_limit($campaign, $seconds = 300) {
    $key = 'ltw_rate_' . sanitize_key($campaign) . '_' . ltw_client_ip_hash();
    set_transient($key, 1, $seconds);
}
