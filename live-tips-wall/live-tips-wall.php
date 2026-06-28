<?php
/**
 * Plugin Name: Live Tips Wall
 * Description: דף נחיתה לאתגר הקיץ וקיר טיפים חי — פתקים צבעוניים עם דירוג כוכבים, ללא הרשמה. פרסום מיידי + הסתרה לאדמין.
 * Version: 1.0.6
 * Author: Chepti
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LTW_VERSION', '1.0.6');
define('LTW_DEFAULT_WHATSAPP', 'https://chat.whatsapp.com/Gl65KQDZUApFdpXiwniKMD');
define('LTW_MANAGERS_WHATSAPP', 'https://wa.me/972544477081');
define('LTW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LTW_PLUGIN_URL', plugin_dir_url(__FILE__));

global $wpdb;
define('LTW_TABLE_NAME', $wpdb->prefix . 'live_tips');

require_once LTW_PLUGIN_DIR . 'includes/db.php';
require_once LTW_PLUGIN_DIR . 'includes/api.php';
require_once LTW_PLUGIN_DIR . 'includes/shortcodes.php';
require_once LTW_PLUGIN_DIR . 'includes/admin.php';

register_activation_hook(__FILE__, 'ltw_activate');

add_action('wp_enqueue_scripts', 'ltw_maybe_enqueue_assets');

function ltw_maybe_enqueue_assets() {
    if (!is_singular()) {
        return;
    }
    global $post;
    if (!$post || (
        !has_shortcode($post->post_content, 'live_tips_wall')
        && !has_shortcode($post->post_content, 'summer_challenge_2026')
        && !has_shortcode($post->post_content, 'managers_offer')
    )) {
        return;
    }
    ltw_enqueue_wall_assets();
}

function ltw_enqueue_wall_assets($campaign = 'summer-2026') {
    wp_enqueue_style(
        'ltw-rubik-font',
        'https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;700&display=swap',
        [],
        null
    );
    wp_enqueue_style('ltw-wall', LTW_PLUGIN_URL . 'assets/wall.css', [], LTW_VERSION);
    wp_enqueue_script('ltw-wall', LTW_PLUGIN_URL . 'assets/wall.js', [], LTW_VERSION, true);
    wp_localize_script('ltw-wall', 'ltwConfig', [
        'restUrl'   => esc_url_raw(rest_url('ltw/v1/')),
        'nonce'     => wp_create_nonce('wp_rest'),
        'campaign'  => sanitize_key($campaign),
        'pollMs'    => 15000,
        'maxTipLen' => 280,
        'i18n'      => [
            'submit'        => '✨ שלחו',
            'submitting'    => 'שולח…',
            'thanks'        => 'תודה! הטיפ על הקיר 💛',
            'error'         => 'משהו השתבש. נסו שוב.',
            'rateLimit'     => 'כבר שלחתם טיפ לאחרונה. נסו שוב בעוד כמה דקות.',
            'tipRequired'   => 'כתבו טיפ לפני השליחה.',
            'nameRequired'  => 'כתבו את שמכם.',
            'starsRequired' => 'בחרו דירוג כוכבים.',
            'anonymous'     => 'מורה/ה',
            'wallTitle'     => 'טיפים ממורים שכבר למדו',
            'wallEmpty'     => 'היו הראשונים לשתף טיפ!',
            'formAsk'       => 'כבר למדתם אצל חפציה?',
            'formTitle'     => 'הוסיפו פתק!',
            'formHint'      => 'טיפ קצר — איך הכי כדאי ללמוד?',
            'nameLabel'     => 'השם שלכם',
            'namePlaceholder'=> 'השם שלכם',
            'tipPlaceholder'=> 'למשל: ליישם כל יחידה מיד…',
            'initialsLabel' => 'ראשי תיבות בלבד',
            'tipLabel'      => 'הטיפ שלכם',
            'starsLabel'    => 'דירוג',
        ],
    ]);
}
