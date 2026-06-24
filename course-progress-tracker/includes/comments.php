<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Hebrew comment UI strings.
 *
 * Themes call comment_reply_link(); WordPress core runs the
 * 'comment_reply_link_args' filter on the FINAL merged args, so this wins
 * over whatever reply_text the theme passed (English "Reply" -> "השב").
 */
add_filter('comment_reply_link_args', function ($args) {
    $args['reply_text'] = 'השב';
    return $args;
});
