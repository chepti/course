<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('ltw/v1', '/tips', [
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => '__return_true',
        'args'                => [
            'campaign' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
            'since' => [
                'default'           => 0,
                'sanitize_callback' => 'absint',
            ],
        ],
        'callback' => function (WP_REST_Request $request) {
            $campaign = $request->get_param('campaign');
            $since = (int) $request->get_param('since');
            $include_hidden = current_user_can('manage_options')
                && $request->get_param('admin') === '1';

            return rest_ensure_response([
                'tips' => ltw_get_tips($campaign, $since, $include_hidden),
            ]);
        },
    ]);

    register_rest_route('ltw/v1', '/tips', [
        'methods'             => WP_REST_Server::CREATABLE,
        'permission_callback' => '__return_true',
        'callback'            => 'ltw_rest_create_tip',
    ]);

    register_rest_route('ltw/v1', '/tips/(?P<id>\d+)/status', [
        'methods'             => WP_REST_Server::EDITABLE,
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'args' => [
            'id' => [
                'validate_callback' => function ($value) {
                    return is_numeric($value) && (int) $value > 0;
                },
            ],
            'status' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_key',
            ],
        ],
        'callback' => function (WP_REST_Request $request) {
            $id = (int) $request['id'];
            $status = $request->get_param('status');
            if (!in_array($status, ['visible', 'hidden'], true)) {
                return new WP_Error('ltw_bad_status', 'סטטוס לא תקין.', ['status' => 400]);
            }
            if (!ltw_set_tip_status($id, $status)) {
                return new WP_Error('ltw_not_found', 'הטיפ לא נמצא.', ['status' => 404]);
            }
            return rest_ensure_response(['ok' => true, 'id' => $id, 'status' => $status]);
        },
    ]);
});

function ltw_rest_create_tip(WP_REST_Request $request) {
    if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
        return new WP_Error('ltw_bad_nonce', 'בקשה לא תקינה.', ['status' => 403]);
    }

    $honeypot = $request->get_param('website_url');
    if (!empty($honeypot)) {
        return new WP_Error('ltw_spam', 'נחסם.', ['status' => 400]);
    }

    $campaign = sanitize_key($request->get_param('campaign') ?: 'summer-2026');
    if ($campaign === '') {
        return new WP_Error('ltw_bad_campaign', 'קמפיין לא תקין.', ['status' => 400]);
    }

    if (ltw_is_rate_limited($campaign)) {
        return new WP_Error('ltw_rate_limit', 'rate_limit', ['status' => 429]);
    }

    $tip = sanitize_textarea_field((string) $request->get_param('tip'));
    $tip = mb_substr(trim($tip), 0, 280, 'UTF-8');
    if ($tip === '') {
        return new WP_Error('ltw_empty_tip', 'חסר טיפ.', ['status' => 400]);
    }

    $stars = (int) $request->get_param('stars');
    if ($stars < 1 || $stars > 5) {
        return new WP_Error('ltw_bad_stars', 'דירוג לא תקין.', ['status' => 400]);
    }

    $name = sanitize_text_field((string) $request->get_param('name'));
    $name = mb_substr(trim($name), 0, 50, 'UTF-8');
    if ($name === '') {
        return new WP_Error('ltw_empty_name', 'חסר שם.', ['status' => 400]);
    }
    $initials_only = (bool) $request->get_param('initials_only');

    $tip_row = ltw_insert_tip([
        'campaign'      => $campaign,
        'tip_text'      => $tip,
        'display_name'  => $name,
        'initials_only' => $initials_only,
        'stars'         => $stars,
        'color'         => ltw_pick_color(),
        'ip_hash'       => ltw_client_ip_hash(),
    ]);

    if (!$tip_row) {
        return new WP_Error('ltw_db_error', 'שגיאת שמירה.', ['status' => 500]);
    }

    ltw_set_rate_limit($campaign);

    return rest_ensure_response(['tip' => $tip_row]);
}
