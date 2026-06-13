<?php
if (!defined('ABSPATH')) { exit; }

/**
 * REST API for the course tracker (namespace course/v1).
 *
 * Page caching makes nonces printed into the page unreliable (a cached page
 * can carry another user's stale nonce). Solution: the JS first calls the
 * admin-ajax 'cpt_session' endpoint (admin-ajax is never page-cached) to get
 * a fresh REST nonce for the logged-in user, then calls REST with X-WP-Nonce.
 */

// Session bootstrap - fresh nonce + user state, always uncached
function cpt_session_callback() {
    nocache_headers();
    if (!is_user_logged_in()) {
        wp_send_json_error(['logged_in' => false], 401);
        return;
    }
    wp_send_json_success([
        'logged_in' => true,
        'user_id' => get_current_user_id(),
        'rest_url' => esc_url_raw(rest_url('course/v1/')),
        'rest_nonce' => wp_create_nonce('wp_rest'),
    ]);
}
add_action('wp_ajax_cpt_session', 'cpt_session_callback');
add_action('wp_ajax_nopriv_cpt_session', 'cpt_session_callback');

function cpt_rest_permission() {
    return is_user_logged_in();
}

add_action('rest_api_init', function () {

    // Full state of a unit for the current user - one call instead of four
    register_rest_route('course/v1', '/state', [
        'methods' => 'GET',
        'permission_callback' => 'cpt_rest_permission',
        'args' => [
            'post_id' => ['required' => true, 'validate_callback' => 'is_numeric'],
        ],
        'callback' => function (WP_REST_Request $request) {
            $user_id = get_current_user_id();
            $post_id = intval($request['post_id']);

            global $wpdb;
            $completed = $wpdb->get_col($wpdb->prepare(
                "SELECT section_id FROM " . CPT_TABLE_NAME . " WHERE user_id = %d AND post_id = %d",
                $user_id, $post_id
            ));

            $section_progress = cpt_get_unit_section_progress($user_id, $post_id);

            // Backfill: sections that reach 100% via calculation but aren't yet in
            // wp_course_progress (happens for users whose data pre-dates v3 REST API).
            // Write to DB so future calls are consistent, and include in this response.
            foreach ($section_progress as $sid => $pct) {
                if ($pct >= 100 && !in_array($sid, $completed, true)) {
                    $completed[] = $sid;
                    $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO " . CPT_TABLE_NAME . " (user_id, post_id, section_id, completed_at) VALUES (%d, %d, %s, NOW())",
                        $user_id, $post_id, $sid
                    ));
                }
            }

            $last_in_unit = cpt_get_last_position($user_id, $post_id);
            $last_anywhere = cpt_get_last_position($user_id);

            $resume = null;
            if ($last_anywhere) {
                $resume = [
                    'post_id' => intval($last_anywhere->post_id),
                    'post_title' => preg_replace('/^פרטי:\s*/', '', get_the_title($last_anywhere->post_id)),
                    'post_url' => get_permalink($last_anywhere->post_id),
                    'section_id' => $last_anywhere->section_id,
                    'updated_at' => $last_anywhere->updated_at,
                ];
            }

            return rest_ensure_response([
                'completed_sections' => $completed,
                'section_progress' => $section_progress,
                'unit_percent' => cpt_get_unit_overall_progress($user_id, $post_id, $section_progress),
                'last_position' => $last_in_unit ? $last_in_unit->section_id : null,
                'resume' => $resume,
            ]);
        },
    ]);

    // Record an activity (video watch, click, scroll, comment, manual check)
    register_rest_route('course/v1', '/activity', [
        'methods' => 'POST',
        'permission_callback' => 'cpt_rest_permission',
        'args' => [
            'post_id' => ['required' => true, 'validate_callback' => 'is_numeric'],
            'section_id' => ['required' => true],
            'activity_type' => ['required' => true],
        ],
        'callback' => function (WP_REST_Request $request) {
            $user_id = get_current_user_id();
            $post_id = intval($request['post_id']);
            $section_id = sanitize_text_field($request['section_id']);
            $activity_type = sanitize_text_field($request['activity_type']);
            $allowed_types = ['video_watch', 'button_click', 'scroll', 'comment', 'manual_check'];
            if (!in_array($activity_type, $allowed_types, true)) {
                return new WP_Error('cpt_bad_type', 'Unknown activity type.', ['status' => 400]);
            }
            $activity_data = $request->get_param('activity_data');
            if (is_array($activity_data)) {
                $activity_data = wp_json_encode($activity_data);
            } else {
                $activity_data = null;
            }

            global $wpdb;
            $table = CPT_ACTIVITY_TABLE_NAME;

            // Skip duplicates of the same activity within 5 minutes
            $recent = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table
                 WHERE user_id = %d AND post_id = %d AND section_id = %s AND activity_type = %s
                 AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1",
                $user_id, $post_id, $section_id, $activity_type
            ));
            if (!$recent) {
                $wpdb->insert($table, [
                    'user_id' => $user_id,
                    'post_id' => $post_id,
                    'section_id' => $section_id,
                    'activity_type' => $activity_type,
                    'activity_data' => $activity_data,
                ], ['%d', '%d', '%s', '%s', '%s']);
                cpt_check_and_mark_section_complete($user_id, $post_id, $section_id);
            }

            $section_progress = cpt_get_unit_section_progress($user_id, $post_id);

            // Return completed_sections so the JS can mark circles even for sections
            // not currently in the manifest (e.g. 'task' recorded under its own ID
            // but absent from an auto-built manifest).
            $completed_after = $wpdb->get_col($wpdb->prepare(
                "SELECT section_id FROM " . CPT_TABLE_NAME . " WHERE user_id = %d AND post_id = %d",
                $user_id, $post_id
            ));
            // Also promote any section that just hit 100% (covers manifest path)
            foreach ($section_progress as $sid => $pct) {
                if ($pct >= 100 && !in_array($sid, $completed_after, true)) {
                    $completed_after[] = $sid;
                    $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO " . CPT_TABLE_NAME . " (user_id, post_id, section_id, completed_at) VALUES (%d, %d, %s, NOW())",
                        $user_id, $post_id, $sid
                    ));
                }
            }

            return rest_ensure_response([
                'saved' => !$recent,
                'duplicate' => (bool) $recent,
                'section_progress' => $section_progress,
                'completed_sections' => $completed_after,
                'unit_percent' => cpt_get_unit_overall_progress($user_id, $post_id, $section_progress),
            ]);
        },
    ]);

    // Save last position (resume point)
    register_rest_route('course/v1', '/position', [
        'methods' => 'POST',
        'permission_callback' => 'cpt_rest_permission',
        'args' => [
            'post_id' => ['required' => true, 'validate_callback' => 'is_numeric'],
            'section_id' => ['required' => true],
        ],
        'callback' => function (WP_REST_Request $request) {
            $user_id = get_current_user_id();
            $post_id = intval($request['post_id']);
            $section_id = sanitize_text_field($request['section_id']);

            global $wpdb;
            $table = CPT_LAST_POSITION_TABLE_NAME;
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (user_id, post_id, section_id, updated_at)
                 VALUES (%d, %d, %s, NOW())
                 ON DUPLICATE KEY UPDATE section_id = %s, updated_at = NOW()",
                $user_id, $post_id, $section_id, $section_id
            ));
            if ($result === false) {
                return new WP_Error('cpt_db', 'Failed to save position.', ['status' => 500]);
            }
            return rest_ensure_response(['saved' => true]);
        },
    ]);

    // Check whether the user commented on this unit's discussion
    register_rest_route('course/v1', '/comment-status', [
        'methods' => 'GET',
        'permission_callback' => 'cpt_rest_permission',
        'args' => [
            'post_id' => ['required' => true, 'validate_callback' => 'is_numeric'],
        ],
        'callback' => function (WP_REST_Request $request) {
            $user_id = get_current_user_id();
            $post_id = intval($request['post_id']);
            $post_id = apply_filters('cpt_comment_check_post_id', $post_id);
            $count = get_comments([
                'post_id' => $post_id,
                'user_id' => $user_id,
                'count' => true,
            ]);
            return rest_ensure_response(['has_comment' => $count > 0, 'post_id_checked' => $post_id]);
        },
    ]);

    // Course-wide summary for the learner dashboard
    register_rest_route('course/v1', '/summary', [
        'methods' => 'GET',
        'permission_callback' => 'cpt_rest_permission',
        'callback' => function () {
            $user_id = get_current_user_id();
            $last = cpt_get_last_position($user_id);
            return rest_ensure_response([
                'units' => cpt_get_course_summary($user_id),
                'resume' => $last ? [
                    'post_id' => intval($last->post_id),
                    'post_title' => preg_replace('/^פרטי:\s*/', '', get_the_title($last->post_id)),
                    'post_url' => get_permalink($last->post_id),
                    'section_id' => $last->section_id,
                    'updated_at' => $last->updated_at,
                ] : null,
            ]);
        },
    ]);
});
