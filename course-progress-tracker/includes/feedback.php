<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Learner feedback: a button in the unit footer opens a small form; the
 * message is e-mailed to the course owner together with exactly where it was
 * sent from (page URL, unit, chapter, sub-chapter, a deep link back to that
 * spot). Logged-in learners only; nonce + rate limit; the last messages are
 * also kept in an option so nothing is lost if the mail lands in spam.
 */

/** Where feedback goes: option cpt_feedback_email, else the site admin e-mail. */
function cpt_feedback_recipient() {
    $to = apply_filters('cpt_feedback_to', get_option('cpt_feedback_email', ''));
    if (!is_email($to)) {
        $to = get_option('admin_email');
    }
    return $to;
}

/** Footer markup (credit + feedback button) rendered at the bottom of the shell. */
function cpt_render_course_footer() {
    $html  = '<footer class="course-footer">';
    $html .= '<a class="course-footer-credit" href="https://chepti.com" target="_blank" rel="noopener" title="האתר של חולמים תקשוב">חולמים תקשוב</a>';
    if (is_user_logged_in()) {
        $html .= '<button type="button" class="course-feedback-open" id="course-feedback-open" title="שאלה, הערה או תיקון - נשלחים אליי ישירות, יחד עם המקום שבו אתם נמצאים בקורס">';
        $html .= '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v6M9 10h6"/></svg>';
        $html .= '<span>משוב</span></button>';
    }
    $html .= '</footer>';
    return $html;
}

/** Feedback script: only for logged-in learners on unit pages. */
add_action('wp_enqueue_scripts', function () {
    if (!is_singular() || !is_user_logged_in()) { return; }
    $post = get_post();
    if (!$post || !has_shortcode($post->post_content, 'course_unit')) { return; }
    wp_enqueue_script('cpt-course-feedback', CPT_PLUGIN_URL . 'assets/course-feedback.js', [], CPT_VERSION, true);
    wp_localize_script('cpt-course-feedback', 'cptFeedback', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('cpt_feedback'),
        'postId'  => (int) $post->ID,
    ]);
});

add_action('wp_ajax_cpt_feedback', 'cpt_feedback_callback');
function cpt_feedback_callback() {
    check_ajax_referer('cpt_feedback', 'nonce');
    $uid = get_current_user_id();
    if (!$uid) { wp_send_json_error(['message' => 'יש להתחבר כדי לשלוח משוב'], 401); }

    // Honeypot: bots fill every field. Pretend success, send nothing.
    if (!empty($_POST['website'])) { wp_send_json_success(); }

    // Rate limit: at least 15s apart, at most 6 per 10 minutes per user.
    $rl_key = 'cpt_fb_rl_' . $uid;
    $stamps = get_transient($rl_key);
    $stamps = is_array($stamps) ? $stamps : [];
    $now    = time();
    $stamps = array_values(array_filter($stamps, function ($t) use ($now) { return $now - $t < 600; }));
    if (!empty($stamps) && $now - end($stamps) < 15) {
        wp_send_json_error(['message' => 'רגע, הודעה נשלחה עכשיו. נסו שוב בעוד כמה שניות'], 429);
    }
    if (count($stamps) >= 6) {
        wp_send_json_error(['message' => 'נשלחו כבר כמה הודעות. נסו שוב בעוד כמה דקות'], 429);
    }

    $message = trim(sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')));
    $len = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($len < 3)    { wp_send_json_error(['message' => 'כתבו לפחות כמה מילים'], 400); }
    if ($len > 3000) { wp_send_json_error(['message' => 'ההודעה ארוכה מדי (עד 3000 תווים)'], 400); }

    $types = ['question' => 'שאלה', 'comment' => 'הערה', 'fix' => 'תיקון'];
    $type  = sanitize_key($_POST['type'] ?? 'comment');
    if (!isset($types[$type])) { $type = 'comment'; }

    // One-line context labels (also used in the subject: strip line breaks).
    $line = function ($key, $max = 200) {
        $v = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
        $v = preg_replace('/[\r\n]+/', ' ', $v);
        return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
    };
    $unit_label = $line('unit');
    $main_label = $line('main_label');
    $main_id    = sanitize_key($_POST['main_id'] ?? '');
    $sub_label  = $line('sub_label');
    $sub_id     = sanitize_key($_POST['sub_id'] ?? '');
    $heading    = $line('heading');
    $viewport   = $line('viewport', 30);

    // Page URL: only accept our own host (the value is shown to the owner).
    $url  = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!$host || strcasecmp($host, wp_parse_url(home_url(), PHP_URL_HOST)) !== 0) { $url = ''; }

    $post_id = absint($_POST['post_id'] ?? 0);
    $permalink = ($post_id && get_post_status($post_id)) ? get_permalink($post_id) : '';
    $section_id = $sub_id !== '' ? $sub_id : $main_id;
    $deep = $permalink && $section_id !== '' ? add_query_arg('cpt_resume', $section_id, $permalink) : '';

    $user = get_userdata($uid);
    $user_name  = $user ? $user->display_name : ('#' . $uid);
    $user_email = $user ? $user->user_email : '';

    $where = $unit_label ?: ($post_id ? get_the_title($post_id) : 'יחידה');
    if ($main_label !== '') { $where .= ' › ' . $main_label; }
    if ($sub_label !== '')  { $where .= ' › ' . $sub_label; }

    $subject = '[משוב קורס] ' . $types[$type] . ' - ' . $where;
    $subject = function_exists('mb_substr') ? mb_substr($subject, 0, 180) : substr($subject, 0, 180);

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $body  = $types[$type] . ' מ' . $user_name . ($user_email ? ' <' . $user_email . '>' : '') . "\n\n";
    $body .= $message . "\n\n";
    $body .= "-----\n";
    $body .= 'איפה: ' . $where . "\n";
    if ($heading !== '') { $body .= 'כותרת בעמוד: ' . $heading . "\n"; }
    if ($deep)           { $body .= 'קישור ישיר למקום: ' . $deep . "\n"; }
    if ($url && $url !== $deep) { $body .= 'כתובת בדפדפן: ' . $url . "\n"; }
    if ($main_id !== '' || $sub_id !== '') {
        $body .= 'מזהי פרק: ' . $main_id . ($sub_id !== '' ? ' / ' . $sub_id : '') . "\n";
    }
    $body .= 'מכשיר: ' . ($viewport !== '' ? $viewport . ' · ' : '') . $ua . "\n";
    $body .= 'זמן: ' . wp_date('d.m.Y H:i') . "\n";

    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if ($user_email && is_email($user_email)) {
        $safe_name = str_replace(['"', "\r", "\n", '<', '>'], '', $user_name);
        $headers[] = 'Reply-To: ' . $safe_name . ' <' . $user_email . '>';
    }

    $sent = wp_mail(cpt_feedback_recipient(), $subject, $body, $headers);

    // Keep the last 50 messages, so nothing is lost if mail delivery fails.
    $log = get_option('cpt_feedback_log', []);
    $log = is_array($log) ? $log : [];
    $log[] = [
        'time' => current_time('mysql'), 'user' => $uid, 'type' => $type, 'where' => $where,
        'url' => $deep ?: $url, 'message' => $message, 'mailed' => $sent ? 1 : 0,
    ];
    update_option('cpt_feedback_log', array_slice($log, -50), false);

    $stamps[] = $now;
    set_transient($rl_key, $stamps, 900);

    if (!$sent) {
        wp_send_json_error(['message' => 'השליחה נכשלה. ההודעה נשמרה, אבל כדאי לנסות שוב מאוחר יותר'], 500);
    }
    wp_send_json_success();
}
