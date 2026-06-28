<?php
if (!defined('ABSPATH')) {
    exit;
}

function ltw_live_tips_wall_shortcode($atts) {
    $atts = shortcode_atts([
        'campaign' => 'summer-2026',
    ], $atts);

    ltw_enqueue_wall_assets($atts['campaign']);

    $campaign = esc_attr(sanitize_key($atts['campaign']));

    ob_start();
    ?>
    <div class="ltw-root" data-campaign="<?php echo $campaign; ?>" dir="rtl">
        <div class="ltw-split">
            <section class="ltw-wall-section" aria-labelledby="ltw-wall-title">
                <h3 id="ltw-wall-title" class="ltw-wall-title"></h3>
                <div class="ltw-wall" role="list"></div>
                <p class="ltw-wall-empty" hidden></p>
            </section>

            <aside class="ltw-form-aside" aria-labelledby="ltw-form-title">
                <div class="ltw-form-card">
                    <span class="ltw-pin" aria-hidden="true">📌</span>
                    <p class="ltw-form-ask"></p>
                    <h3 id="ltw-form-title" class="ltw-form-title"></h3>
                    <p class="ltw-form-hint"></p>
                    <form class="ltw-form" novalidate>
                        <input type="text" name="website_url" class="ltw-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
                        <label class="ltw-field ltw-field-tip">
                            <span class="ltw-sr-only ltw-label-tip"></span>
                            <textarea name="tip" rows="2" maxlength="280" required placeholder=""></textarea>
                            <span class="ltw-char-count"><span class="ltw-char-current">0</span>/280</span>
                        </label>
                        <div class="ltw-name-row">
                            <label class="ltw-field ltw-field-name">
                                <span class="ltw-sr-only ltw-label-name"></span>
                                <input type="text" name="name" maxlength="50" autocomplete="name" required placeholder="">
                            </label>
                            <label class="ltw-check ltw-check-mini" title="">
                                <input type="checkbox" name="initials_only">
                                <span class="ltw-label-initials"></span>
                            </label>
                        </div>
                        <div class="ltw-form-footer">
                            <fieldset class="ltw-stars-field">
                                <legend class="ltw-sr-only ltw-label-stars"></legend>
                                <div class="ltw-stars-input" role="radiogroup" aria-label="דירוג">
                                    <?php for ($i = 5; $i >= 1; $i--) : ?>
                                        <label class="ltw-star-btn">
                                            <input type="radio" name="stars" value="<?php echo $i; ?>" <?php echo $i === 5 ? 'checked' : ''; ?>>
                                            <span aria-hidden="true">★</span>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                            </fieldset>
                            <button type="submit" class="ltw-submit"></button>
                        </div>
                        <p class="ltw-msg" role="status" aria-live="polite"></p>
                    </form>
                </div>
            </aside>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('live_tips_wall', 'ltw_live_tips_wall_shortcode');

function ltw_render_managers_offer($whatsapp_url = '') {
    $wa = esc_url($whatsapp_url ?: LTW_MANAGERS_WHATSAPP);
    ob_start();
    ?>
    <section class="ltw-managers-offer" aria-labelledby="ltw-managers-title">
        <p class="ltw-managers-eyebrow">הצעה למנהלים</p>
        <h2 id="ltw-managers-title" class="ltw-managers-title">ומה אחרי שלושת השבועות של האתגר?</h2>
        <p class="ltw-managers-lead">
            הקורס שוב נסגר, אבל אתם יכולים לפתוח אותו — ולא רק לכם אלא לכל חדר המורים שלכם!
        </p>
        <p class="ltw-managers-tagline">
            חבילה מיוחדת להפוך את הבינה המלאכותית לשפה בית ספרית גם אצלכם.
        </p>
        <ul class="ltw-managers-list">
            <li>
                <strong>הקורס נפתח במלואו</strong> לכל צוות המורים בבית הספר. כולל עדכונים במהלך השנה.
            </li>
            <li>
                <strong>קבוצת אתגר בכל יום למשך חודש</strong> — פרטית לחדר המורים — שבה מתפרסם בכל יום טיפ ואתגר, המורים משתפים תוצרים, דילמות ומקבלים מענה מהיר לשאלות.
            </li>
            <li>
                <strong>הרצאת השראה</strong> — לאן הבינה לוקחת אותנו? איך עולם החינוך יכול להיראות בעוד שנתיים? איך להגן ולהכין את הלומדים?
            </li>
            <li>
                <strong>מבנה ליום סמינר לצוות המורים</strong> — שילוב של למידה ובחירה, יישום ויצירה משותפת של יחידות לימוד לשימוש מיידי.
            </li>
        </ul>
        <p class="ltw-managers-cta-text">
            דברו איתי לפרטים נוספים ולהרכיב את המענה המדויק עבורכם
        </p>
        <p class="ltw-managers-cta-wrap">
            <a href="<?php echo $wa; ?>" class="ltw-cta ltw-cta-whatsapp ltw-managers-wa" target="_blank" rel="noopener">
                וואטסאפ · 054-447-7081
            </a>
        </p>
    </section>
    <?php
    return ob_get_clean();
}

function ltw_summer_challenge_shortcode($atts) {
    $atts = shortcode_atts([
        'campaign'         => 'summer-2026',
        'join_url'         => '',
        'join_text'        => 'להצטרפות לאתגר',
        'whatsapp_url'     => LTW_DEFAULT_WHATSAPP,
        'whatsapp_text'    => 'להצטרפות לקבוצת התמיכה בוואטסאפ',
        'managers_wa_url'  => LTW_MANAGERS_WHATSAPP,
    ], $atts);

    ltw_enqueue_wall_assets($atts['campaign']);

    $join_url = esc_url($atts['join_url']);
    $join_text = esc_html($atts['join_text']);
    $whatsapp_url = esc_url($atts['whatsapp_url']);
    $whatsapp_text = esc_html($atts['whatsapp_text']);

    ob_start();
    ?>
    <div class="ltw-landing" dir="rtl">
        <header class="ltw-hero">
            <p class="ltw-badge">🎯 חוזר ומשודרג</p>
            <h1 class="ltw-hero-title">אתגר הבינה המלאכותית לקיץ</h1>
            <p class="ltw-hero-lead">
                שנה שעברה הצטרפו יותר מאלף מורים. אז לא מתאפקת ופותחת את כולו מחדש — משודרג ומעודכן.
            </p>
        </header>

        <div class="ltw-landing-card">
            <ul class="ltw-features">
                <li>למשך <strong>שלושה שבועות</strong> הקורס פתוח לחלוטין, ויש גם קבוצת וואטסאפ לתמיכה.</li>
                <li><strong>תעודת סיום אישית</strong> למי שמיישם — ומעלה תוצרים שבנה בעקבות הלמידה אל מאגר "חומר פתוח".</li>
                <li>אני עושה את זה כי אני מאמינה ש<strong>כל מורה בישראל</strong> אמור ללמוד בינה מלאכותית בלי שזה יעלה לו כסף.</li>
            </ul>

            <aside class="ltw-footnote">
                <strong>כוכבית:</strong>
                מי שיכיר את הקורס ויראה בו ערך — מוזמן לפנות למנהל שלו ולהציע לרכוש את הקורס לכלל הצוות.
                זה יעזור לי להמשיך לייצר תכנים חינמיים. אבל זה לא תנאי לכלום.
            </aside>

            <p class="ltw-dates">
                מתחילים היום, <strong>28.6.26</strong> עד <strong>16.7.26</strong> — י״ג תמוז עד ב׳ אב.
            </p>

            <div class="ltw-cta-wrap ltw-cta-group">
                <?php if ($join_url) : ?>
                    <a href="<?php echo $join_url; ?>" class="ltw-cta" target="_blank" rel="noopener"><?php echo $join_text; ?></a>
                <?php endif; ?>
                <?php if ($whatsapp_url) : ?>
                    <a href="<?php echo $whatsapp_url; ?>" class="ltw-cta ltw-cta-whatsapp" target="_blank" rel="noopener"><?php echo $whatsapp_text; ?></a>
                <?php endif; ?>
            </div>

            <p class="ltw-share-hint">אפשר להעביר הלאה — למורים שייהנו מההזדמנות 💚</p>
        </div>

        <?php echo ltw_live_tips_wall_shortcode(['campaign' => $atts['campaign']]); ?>

        <?php echo ltw_render_managers_offer($atts['managers_wa_url']); ?>

        <footer class="ltw-signature">
            <span class="ltw-signature-line"></span>
            <p class="ltw-signature-name">חפציה בן ארצי</p>
        </footer>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('summer_challenge_2026', 'ltw_summer_challenge_shortcode');
