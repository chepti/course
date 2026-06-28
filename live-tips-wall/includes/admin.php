<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_menu_page(
        'קיר טיפים',
        'קיר טיפים',
        'manage_options',
        'live-tips-wall',
        'ltw_admin_page',
        'dashicons-format-status',
        58
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_live-tips-wall') {
        return;
    }
    wp_enqueue_style('ltw-admin', LTW_PLUGIN_URL . 'assets/admin.css', [], LTW_VERSION);
    wp_enqueue_script('ltw-admin', LTW_PLUGIN_URL . 'assets/admin.js', [], LTW_VERSION, true);
    wp_localize_script('ltw-admin', 'ltwAdmin', [
        'restUrl' => esc_url_raw(rest_url('ltw/v1/')),
        'nonce'   => wp_create_nonce('wp_rest'),
        'i18n'    => [
            'hide'   => 'הסתר',
            'show'   => 'הצג שוב',
            'hidden' => 'מוסתר',
            'visible'=> 'גלוי',
            'error'  => 'שגיאה — נסו שוב',
        ],
    ]);
});

function ltw_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table = LTW_TABLE_NAME;
    $campaign = isset($_GET['campaign']) ? sanitize_key(wp_unslash($_GET['campaign'])) : 'summer-2026';

    $campaigns = $wpdb->get_col("SELECT DISTINCT campaign FROM $table ORDER BY campaign ASC");
    if (empty($campaigns)) {
        $campaigns = ['summer-2026'];
    }

    $tips = $wpdb->get_results($wpdb->prepare(
        "SELECT id, tip_text, display_name, initials_only, stars, color, status, created_at
         FROM $table
         WHERE campaign = %s
         ORDER BY id DESC
         LIMIT 500",
        $campaign
    ), ARRAY_A);

    ?>
    <div class="wrap ltw-admin-wrap" dir="rtl">
        <h1>קיר טיפים — ניהול</h1>
        <p>טיפים מפורסמים מיד. כאן אפשר להסתיר פתקים שלא מתאימים.</p>

        <form method="get" class="ltw-admin-filter">
            <input type="hidden" name="page" value="live-tips-wall">
            <label>
                קמפיין:
                <select name="campaign" onchange="this.form.submit()">
                    <?php foreach ($campaigns as $c) : ?>
                        <option value="<?php echo esc_attr($c); ?>" <?php selected($campaign, $c); ?>>
                            <?php echo esc_html($c); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <?php if (empty($tips)) : ?>
            <p>עדיין אין טיפים בקמפיין זה.</p>
        <?php else : ?>
            <table class="widefat striped ltw-admin-table">
                <thead>
                    <tr>
                        <th>תאריך</th>
                        <th>קמפיין</th>
                        <th>שם (מקור)</th>
                        <th>טיפ</th>
                        <th>כוכבים</th>
                        <th>סטטוס</th>
                        <th>פעולה</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tips as $tip) :
                        $name = ltw_format_display_name($tip['display_name'], (bool) $tip['initials_only']);
                        $raw_name = trim($tip['display_name']);
                        $is_hidden = ($tip['status'] === 'hidden');
                        ?>
                        <tr data-id="<?php echo (int) $tip['id']; ?>" class="<?php echo $is_hidden ? 'ltw-row-hidden' : ''; ?>">
                            <td><?php echo esc_html($tip['created_at']); ?></td>
                            <td><code><?php echo esc_html($tip['campaign']); ?></code></td>
                            <td>
                                <?php echo esc_html($name !== '' ? $name : '—'); ?>
                                <?php if ($raw_name !== '' && $name !== $raw_name) : ?>
                                    <br><small>מקור: <?php echo esc_html($raw_name); ?></small>
                                <?php elseif ($raw_name === '') : ?>
                                    <br><small>חסר שם</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ltw-color-dot" style="background:<?php echo esc_attr($tip['color']); ?>"></span>
                                <?php echo esc_html($tip['tip_text']); ?>
                            </td>
                            <td><?php echo str_repeat('★', (int) $tip['stars']); ?></td>
                            <td class="ltw-status-cell">
                                <?php echo $is_hidden ? 'מוסתר' : 'גלוי'; ?>
                                <?php if (!$is_hidden && $tip['status'] !== 'visible' && $tip['status'] !== '') : ?>
                                    <br><small><code><?php echo esc_html($tip['status']); ?></code></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button"
                                    class="button ltw-toggle-btn"
                                    data-status="<?php echo esc_attr($tip['status']); ?>">
                                    <?php echo $tip['status'] === 'hidden' ? 'הצג שוב' : 'הסתר'; ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <hr>
        <h2>שימוש</h2>
        <p>דף נחיתה: <code>[summer_challenge_2026]</code></p>
        <p>הצעה למנהלים (נפרד — אפשר לשים תמונה לפניו): <code>[managers_offer]</code></p>
        <p>קיר טיפים בלבד: <code>[live_tips_wall campaign="summer-2026"]</code></p>
    </div>
    <?php
}
