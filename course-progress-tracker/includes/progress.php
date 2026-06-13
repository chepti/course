<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Progress calculation - driven by the course manifest (see manifest.php),
 * with fallback to the legacy section-name heuristics for units that are
 * not defined in the manifest yet.
 */

// Helper: color for group dot (consistent per group name)
function cpt_group_color($group_name) {
    if (empty($group_name)) {
        return '#999';
    }
    $h = abs(crc32($group_name)) % 360;
    return 'hsl(' . $h . ', 65%, 45%)';
}

// Helper function to render progress bars
function cpt_render_progress_bar($percentage) {
    $gradient = 'linear-gradient(to left, #f7d979, #3a757f)';

    return sprintf(
        '<div style="background-color: #e9ecef; border-radius: 5px; overflow: hidden; width: 100%%; height: 22px; direction: ltr;">
            <div style="background: %s; width: %d%%; height: 100%%; text-align: center; color: white; line-height: 22px; font-weight: bold; font-size: 12px;">
                %d%%
            </div>
        </div>',
        $gradient,
        $percentage,
        round($percentage)
    );
}

/**
 * Calculate section progress (0-100).
 * Order: manifest requirements if the unit+section is defined there,
 * otherwise the legacy name-pattern heuristics (backward compatible).
 */
function cpt_calculate_section_progress($post_id, $section_id, $activities) {
    if (empty($activities)) {
        return 0;
    }

    // Group activities by type
    $activity_by_type = [];
    foreach ($activities as $activity) {
        $type = $activity->activity_type;
        if (!isset($activity_by_type[$type])) {
            $activity_by_type[$type] = [];
        }
        $activity_data = is_string($activity->activity_data) ? json_decode($activity->activity_data, true) : $activity->activity_data;
        $activity_by_type[$type][] = $activity_data;
    }

    // --- Manifest-driven requirements ---
    $section_def = cpt_manifest_get_section($post_id, $section_id);
    if ($section_def && !empty($section_def['require']) && is_array($section_def['require'])) {
        $total_required = 0;
        $total_done = 0;
        foreach ($section_def['require'] as $type => $count) {
            $count = max(1, intval($count));
            $done = isset($activity_by_type[$type]) ? count($activity_by_type[$type]) : 0;
            // video_watch: count distinct videos, not raw rows
            if ($type === 'video_watch' && !empty($activity_by_type[$type])) {
                $distinct = [];
                foreach ($activity_by_type[$type] as $data) {
                    $vid = is_array($data) && !empty($data['video_id']) ? $data['video_id'] : ('row_' . count($distinct));
                    $distinct[$vid] = true;
                }
                $done = count($distinct);
            }
            $total_required += $count;
            $total_done += min($done, $count);
        }
        if ($total_required === 0) {
            return 100; // section defined with no requirements = informational, any visit counts
        }
        return (int) round(min(100, ($total_done / $total_required) * 100));
    }

    // --- Legacy heuristics fallback ---
    if (strpos($section_id, 'overview') !== false || $section_id === 'overview') {
        $video_watches = isset($activity_by_type['video_watch']) ? count($activity_by_type['video_watch']) : 0;
        return min(100, ($video_watches / 1) * 100);
    }

    if (strpos($section_id, 'tools_demo') !== false || strpos($section_id, 'tools_intermediaries') !== false) {
        $video_watches = isset($activity_by_type['video_watch']) ? count($activity_by_type['video_watch']) : 0;
        return min(100, ($video_watches / 4) * 100);
    }

    if (strpos($section_id, 'tools') !== false) {
        $video_watches = isset($activity_by_type['video_watch']) ? count($activity_by_type['video_watch']) : 0;
        if ($video_watches > 0) {
            return min(100, (int) round(($video_watches / 3) * 100)); // 3 סרטונים = 100%
        }
        return 0;
    }

    if (strpos($section_id, 'discussion') !== false || $section_id === 'discussion') {
        $has_comment = isset($activity_by_type['comment']) && count($activity_by_type['comment']) > 0;
        return $has_comment ? 100 : 0;
    }

    if (strpos($section_id, 'task') !== false || strpos($section_id, 'assignment') !== false || $section_id === 'task' || $section_id === 'assignment') {
        $has_check = isset($activity_by_type['manual_check']) && count($activity_by_type['manual_check']) > 0;
        return $has_check ? 100 : 0;
    }

    // Default: count any activity as progress
    $total_activities = count($activities);
    return min(100, $total_activities * 20);
}

// Helper function to check and mark section as complete
function cpt_check_and_mark_section_complete($user_id, $post_id, $section_id) {
    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;
    $progress_table = CPT_TABLE_NAME;

    $activities = $wpdb->get_results($wpdb->prepare(
        "SELECT activity_type, activity_data FROM $activity_table
         WHERE user_id = %d AND post_id = %d AND section_id = %s",
        $user_id, $post_id, $section_id
    ));

    $progress = cpt_calculate_section_progress($post_id, $section_id, $activities);

    if ($progress >= 100) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $progress_table WHERE user_id = %d AND post_id = %d AND section_id = %s",
            $user_id, $post_id, $section_id
        ));

        if (!$exists) {
            $wpdb->insert(
                $progress_table,
                [
                    'user_id' => $user_id,
                    'post_id' => $post_id,
                    'section_id' => $section_id,
                    'completed_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%s']
            );
        }
    }
}

/**
 * Per-section progress map for a user in a unit: ['section_id' => 0-100].
 * When the unit is in the manifest, every defined section appears (untouched = 0).
 */
function cpt_get_unit_section_progress($user_id, $post_id) {
    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT section_id, activity_type, activity_data FROM $activity_table WHERE user_id = %d AND post_id = %d",
        $user_id, $post_id
    ));

    $by_section = [];
    foreach ($rows as $row) {
        $by_section[$row->section_id][] = (object) [
            'activity_type' => $row->activity_type,
            'activity_data' => $row->activity_data,
        ];
    }

    $unit_def = cpt_manifest_get_unit($post_id);
    $result = [];

    if ($unit_def && !empty($unit_def['sections'])) {
        foreach ($unit_def['sections'] as $section_def) {
            $sid = $section_def['id'];
            // Aggregate activities of the section and its sub-sections (prefix match)
            $acts = isset($by_section[$sid]) ? $by_section[$sid] : [];
            foreach ($by_section as $other_sid => $other_acts) {
                if ($other_sid !== $sid && strpos($other_sid, $sid . '_') === 0 && !cpt_manifest_get_section($post_id, $other_sid)) {
                    $acts = array_merge($acts, $other_acts);
                }
            }
            $result[$sid] = cpt_calculate_section_progress($post_id, $sid, $acts);
        }

        return $result;
    }

    // Legacy: only sections with activity
    foreach ($by_section as $sid => $acts) {
        $result[$sid] = cpt_calculate_section_progress($post_id, $sid, $acts);
    }
    return $result;
}

/**
 * Overall unit progress (0-100) for a user.
 * Manifest units: average of all defined sections (equal weight, or 'weight' key).
 * Legacy units: the old 4x25% (overview/tools/discussion/task) calculation.
 */
function cpt_get_unit_overall_progress($user_id, $post_id, $section_progress = null) {
    if ($section_progress === null) {
        $section_progress = cpt_get_unit_section_progress($user_id, $post_id);
    }

    $unit_def = cpt_manifest_get_unit($post_id);
    if ($unit_def && !empty($unit_def['sections']) && cpt_manifest_unit_is_curated($unit_def)) {
        $total_weight = 0;
        $weighted = 0;
        foreach ($unit_def['sections'] as $section_def) {
            $w = isset($section_def['weight']) ? floatval($section_def['weight']) : 1;
            if ($w <= 0) { continue; }
            $sid = $section_def['id'];
            $p = isset($section_progress[$sid]) ? $section_progress[$sid] : 0;
            $weighted += $p * $w;
            $total_weight += $w;
        }
        return $total_weight > 0 ? (int) round($weighted / $total_weight) : 0;
    }

    // Legacy 4x25%: overview(/intro), tools*, discussion, task/assignment
    $main_progress = ['overview' => 0, 'tools' => 0, 'discussion' => 0, 'task' => 0];
    foreach ($section_progress as $sid => $p) {
        if ($sid === 'overview' || $sid === 'intro' || strpos($sid, 'overview') === 0 || strpos($sid, 'intro') === 0) {
            $main_progress['overview'] = max($main_progress['overview'], $p);
        } elseif ($sid === 'task' || $sid === 'assignment' || strpos($sid, 'task') === 0 || strpos($sid, 'assignment') === 0) {
            $main_progress['task'] = max($main_progress['task'], $p);
        } elseif ($sid === 'discussion' || strpos($sid, 'discussion') === 0) {
            $main_progress['discussion'] = max($main_progress['discussion'], $p);
        } elseif (strpos($sid, 'tools') === 0) {
            $main_progress['tools'] = max($main_progress['tools'], $p);
        }
    }
    $total = 0;
    foreach ($main_progress as $p) {
        $total += ($p / 100) * 25;
    }
    return (int) round($total);
}

/**
 * Course-wide summary for a user: list of units with progress.
 * Manifest course: all defined units in order (including not-started).
 * Legacy: units derived from the activity table.
 */
function cpt_get_course_summary($user_id) {
    global $wpdb;
    $activity_table = CPT_ACTIVITY_TABLE_NAME;

    $units = [];
    $manifest_units = cpt_manifest_get_units();

    if (!empty($manifest_units)) {
        foreach ($manifest_units as $unit_def) {
            $post_id = intval($unit_def['post_id']);
            if (!$post_id) { continue; }
            $section_progress = cpt_get_unit_section_progress($user_id, $post_id);
            $units[] = [
                'post_id' => $post_id,
                'number' => isset($unit_def['number']) ? $unit_def['number'] : null,
                'title' => !empty($unit_def['title']) ? $unit_def['title'] : preg_replace('/^פרטי:\s*/', '', get_the_title($post_id)),
                'url' => get_permalink($post_id),
                'percent' => cpt_get_unit_overall_progress($user_id, $post_id, $section_progress),
                'sections' => $section_progress,
                'section_titles' => cpt_manifest_unit_is_curated($unit_def) ? wp_list_pluck($unit_def['sections'], 'title', 'id') : [],
            ];
        }
        return $units;
    }

    // Legacy: only units with recorded activity
    $unit_rows = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM $activity_table WHERE user_id = %d ORDER BY post_id ASC",
        $user_id
    ));
    foreach ($unit_rows as $post_id) {
        $post_title = get_the_title($post_id);
        if (strpos($post_title, 'פרק בדיקה') !== false ||
            (strpos($post_title, 'בדיקה') !== false && strpos($post_title, 'יחידה') === false)) {
            continue;
        }
        $number = 999;
        if (preg_match('/יחידה\s*(\d+)/', $post_title, $m)) {
            $number = intval($m[1]);
        }
        $section_progress = cpt_get_unit_section_progress($user_id, $post_id);
        $units[] = [
            'post_id' => intval($post_id),
            'number' => $number,
            'title' => preg_replace('/^פרטי:\s*/', '', $post_title),
            'url' => get_permalink($post_id),
            'percent' => cpt_get_unit_overall_progress($user_id, $post_id, $section_progress),
            'sections' => $section_progress,
            'section_titles' => [],
        ];
    }
    usort($units, function ($a, $b) {
        return $a['number'] <=> $b['number'];
    });
    return $units;
}

/**
 * Last position of a user: for a specific unit, or the most recent across the course.
 */
function cpt_get_last_position($user_id, $post_id = 0) {
    global $wpdb;
    $table_name = CPT_LAST_POSITION_TABLE_NAME;

    if ($post_id) {
        return $wpdb->get_row($wpdb->prepare(
            "SELECT post_id, section_id, updated_at FROM $table_name WHERE user_id = %d AND post_id = %d",
            $user_id, $post_id
        ));
    }
    return $wpdb->get_row($wpdb->prepare(
        "SELECT post_id, section_id, updated_at FROM $table_name WHERE user_id = %d ORDER BY updated_at DESC LIMIT 1",
        $user_id
    ));
}
