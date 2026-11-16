<?php
if (!defined('ABSPATH')) { exit; }

/**
 * HTTP API for AI agents - read and edit the course without a browser.
 *
 *   https://tikshuv.chepti.com/course-api/?action=help      (no key needed)
 *
 * Self-describing (action=help), key auth by header or parameter (never a
 * cookie), a compact state, full read of any chapter / sub-chapter / item, and
 * batched writes with a per-operation report. Targets can be addressed by id or
 * by a readable {"match": "part of the title"}.
 *
 * The key lives in the database (option cpt_agent_key), never in the code or
 * in git. It is shown under  התקדמות בקורס > גישה לסוכנים  in the WP admin.
 */

function cpt_agent_base_url() {
    return home_url('/course-api/');
}

function cpt_agent_key() {
    $key = get_option('cpt_agent_key', '');
    if (!is_string($key) || strlen($key) < 20) {
        $key = wp_generate_password(40, false, false);
        update_option('cpt_agent_key', $key, false);
    }
    return $key;
}

function cpt_agent_send($payload, $status = 200) {
    // drop anything already buffered (a stray BOM from another plugin would break strict JSON parsers)
    while (ob_get_level() > 0) { ob_end_clean(); }
    nocache_headers();
    status_header($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cpt_agent_fail($message, $status = 400, $extra = []) {
    cpt_agent_send(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

/** Route: /course-api/ (pretty URL) - handled before WordPress resolves a page. */
add_action('init', function () {
    $path = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '';
    $path = rtrim((string) $path, '/');
    $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($path === $base . '/course-api/AGENT.md') {
        nocache_headers();
        header('Content-Type: text/markdown; charset=utf-8');
        readfile(CPT_PLUGIN_DIR . 'AGENT.md');
        exit;
    }
    if ($path === $base . '/course-api') {
        cpt_agent_dispatch(isset($_GET['action']) ? sanitize_key($_GET['action']) : 'help');
    }
}, 1);

/** Fallback route through admin-ajax: ?action=cpt_api&do=help */
function cpt_agent_ajax() {
    cpt_agent_dispatch(isset($_REQUEST['do']) ? sanitize_key($_REQUEST['do']) : 'help');
}
add_action('wp_ajax_cpt_api', 'cpt_agent_ajax');
add_action('wp_ajax_nopriv_cpt_api', 'cpt_agent_ajax');

function cpt_agent_dispatch($action) {
    if ($action === '' || $action === 'help') {
        cpt_agent_send(cpt_agent_help());
    }
    cpt_agent_auth();
    if ($action === 'state')    { cpt_agent_send(cpt_agent_state()); }
    if ($action === 'get')      { cpt_agent_send(cpt_agent_get()); }
    if ($action === 'progress') { cpt_agent_send(cpt_agent_progress()); }
    if ($action === 'ops')      { cpt_agent_send(cpt_agent_ops()); }
    cpt_agent_fail('פעולה לא מוכרת: ' . $action . '. ראו action=help', 404);
}

function cpt_agent_auth() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $tkey = 'cpt_agent_fail_' . md5($ip);
    $fails = (int) get_transient($tkey);
    if ($fails >= 20) { cpt_agent_fail('יותר מדי ניסיונות עם מפתח שגוי. נסו שוב בעוד כמה דקות.', 429); }

    $given = '';
    if (!empty($_SERVER['HTTP_X_KEY'])) { $given = (string) $_SERVER['HTTP_X_KEY']; }
    elseif (isset($_GET['key'])) { $given = (string) wp_unslash($_GET['key']); }
    elseif (isset($_POST['key'])) { $given = (string) wp_unslash($_POST['key']); }

    if ($given === '' || !hash_equals(cpt_agent_key(), trim($given))) {
        set_transient($tkey, $fails + 1, 10 * MINUTE_IN_SECONDS);
        cpt_agent_fail('נדרש מפתח: כותרת X-Key או פרמטר key. action=help פתוח בלי מפתח.', 401);
    }
}

// ---------------------------------------------------------------- units

/** [unit number => ['n','slug','title','url','post_id']] for units that have v4 content. */
function cpt_agent_units() {
    $posts = [];
    foreach (cpt_manifest_get_units() as $u) {
        if (!empty($u['number'])) { $posts[(string) $u['number']] = (int) $u['post_id']; }
    }
    $out = [];
    foreach (cpt_course_units() as $u) {
        $n = (string) $u['n'];
        if (!cpt_v4_get($n)) { continue; }
        $out[$n] = ['n' => (int) $u['n'], 'slug' => $u['slug'], 'title' => $u['title'], 'url' => $u['url'],
                    'post_id' => isset($posts[$n]) ? $posts[$n] : 0];
    }
    return $out;
}

function cpt_agent_unit_param($value) {
    $units = cpt_agent_units();
    $value = strtolower(trim((string) $value));
    if (isset($units[$value])) { return $units[$value]; }
    foreach ($units as $u) {
        if ($u['slug'] === $value) { return $u; }
    }
    cpt_agent_fail('יחידה לא מוכרת: "' . $value . '". ערכים אפשריים: ' . implode(', ', array_keys($units)), 404);
}

/**
 * Resolve a target of $type ('item' | 'group' | 'chapter') by id or by a
 * readable match on the title. Returns ['id' => ..] or ['error' => .., 'candidates' => [...]].
 */
function cpt_agent_resolve($data, $type, $id, $match) {
    $all = [];
    foreach ($data['chapters'] as $ch) {
        if ($type === 'chapter') { $all[] = ['id' => $ch['id'], 'title' => $ch['title'], 'path' => $ch['title']]; }
        foreach ((isset($ch['groups']) ? $ch['groups'] : []) as $g) {
            if ($type === 'group') { $all[] = ['id' => $g['id'], 'title' => $g['title'], 'path' => $ch['title'] . ' › ' . $g['title']]; }
            foreach ((isset($g['items']) ? $g['items'] : []) as $it) {
                if ($type === 'item') { $all[] = ['id' => $it['id'], 'title' => $it['title'], 'path' => $ch['title'] . ' › ' . $g['title'] . ' › ' . $it['title']]; }
            }
        }
    }
    $id = trim((string) $id);
    if ($id !== '') {
        foreach ($all as $e) { if ($e['id'] === $id) { return ['id' => $id]; } }
        return ['error' => 'לא נמצא ' . $type . ' עם id "' . $id . '"', 'candidates' => []];
    }
    $match = trim((string) $match);
    if ($match === '') { return ['error' => 'חסר id או match', 'candidates' => []]; }
    $exact = []; $part = [];
    foreach ($all as $e) {
        if (mb_strtolower($e['title']) === mb_strtolower($match)) { $exact[] = $e; }
        if (mb_stripos($e['path'], $match) !== false) { $part[] = $e; }
    }
    if (count($exact) === 1) { return ['id' => $exact[0]['id']]; }
    $pool = $exact ? $exact : $part;
    if (count($pool) === 1) { return ['id' => $pool[0]['id']]; }
    if (!$pool) { return ['error' => 'לא נמצא ' . $type . ' שמתאים ל-"' . $match . '"', 'candidates' => []]; }
    return ['error' => 'יותר מהתאמה אחת ל-"' . $match . '". בחרו id מתוך candidates.', 'candidates' => array_slice($pool, 0, 15)];
}

// ---------------------------------------------------------------- actions

function cpt_agent_help() {
    $base = cpt_agent_base_url();
    return [
        'ok' => true,
        'name' => 'קורס הבינה המלאכותית למורים - חולמים תקשוב',
        'about' => 'API לקריאה ולעריכה של מבנה הקורס ותוכנו. המבנה: יחידה (unit) > פרק (chapter) > תת-פרק (group, עם כלל השלמה) > פריט (item). שינויים נכנסים לאתר החי מיד.',
        'base_url' => $base,
        'auth' => 'כל פעולה חוץ מ-help דורשת מפתח: כותרת "X-Key: <מפתח>" או פרמטר "?key=<מפתח>". המפתח מוצג בניהול האתר: התקדמות בקורס > גישה לסוכנים.',
        'docs' => $base . 'AGENT.md',
        'fallback_url' => admin_url('admin-ajax.php') . '?action=cpt_api&do=<action>  (אותו API, אם הכתובת הראשית חסומה)',
        'units' => array_values(array_map(function ($u) { return ['unit' => $u['n'], 'slug' => $u['slug'], 'title' => $u['title']]; }, cpt_agent_units())),
        'actions' => [
            'help' => ['method' => 'GET', 'key' => false, 'desc' => 'המסמך הזה.'],
            'state' => [
                'method' => 'GET', 'key' => true,
                'params' => ['unit' => 'אופציונלי: מספר יחידה (1-10) או slug. בלעדיו: כל היחידות ברמת תת-פרק בלבד.'],
                'desc' => 'תמונת מצב קומפקטית של המבנה. עם unit: כולל פריטים, כל פריט כ-[id, כותרת, סוג].',
                'example' => $base . '?action=state&unit=5',
            ],
            'get' => [
                'method' => 'GET', 'key' => true,
                'params' => ['unit' => 'חובה', 'type' => 'item (ברירת מחדל) | group | chapter', 'id' => 'מזהה', 'match' => 'במקום id: חלק מהכותרת'],
                'desc' => 'התוכן המלא (HTML גולמי) של פריט, או הפתיח של תת-פרק / פרק.',
                'example' => $base . '?action=get&unit=5&match=קנבה קוד',
            ],
            'progress' => [
                'method' => 'GET', 'key' => true,
                'params' => ['unit' => 'חובה', 'detail' => 'אופציונלי: 1 = שורה לכל לומד (שם תצוגה ואחוז)'],
                'desc' => 'סיכום התקדמות הלומדים ביחידה: כמה התחילו, כמה השלימו, ממוצע, והתפלגות.',
                'example' => $base . '?action=progress&unit=5',
            ],
            'ops' => [
                'method' => 'POST', 'key' => true,
                'body' => 'JSON: {"ops": [ {...}, {...} ]}  (Content-Type: application/json), או שדה טופס ops עם אותו JSON. עד 50 פעולות.',
                'desc' => 'מערך פעולות בבקשה אחת. כל פעולה מדווחת הצלחה או כשל בנפרד; כשל באחת לא עוצר את האחרות. כל פעולה מקבלת unit, ואת היעד לפי id או match.',
                'ops' => [
                    'item_save'     => 'עדכון פריט. שדות: title, html, kind (auto|discussion|task), comments (true|false). רק מה שנשלח משתנה.',
                    'item_add'      => 'פריט חדש בסוף תת-פרק. היעד הוא תת-הפרק (id / match). שדות: title, html.',
                    'item_move'     => 'הזזת פריט בתוך תת-הפרק. dir: up | down.',
                    'item_delete'   => 'מחיקת פריט. בלתי הפיך.',
                    'group_save'    => 'עדכון תת-פרק. שדות: title, html (פתיח), rule (one | all | optional | מספר).',
                    'group_add'     => 'תת-פרק חדש בסוף פרק. היעד הוא הפרק. שדות: title, html (פתיח).',
                    'group_move'    => 'הזזת תת-פרק בתוך הפרק. dir: up | down.',
                    'group_delete'  => 'מחיקת תת-פרק ריק בלבד.',
                    'chapter_save'  => 'עדכון פרק. שדות: title, html (פתיח).',
                    'chapter_add'   => 'פרק חדש בסוף היחידה. בלי יעד. שדות: title, html.',
                    'chapter_move'  => 'הזזת פרק. dir: up | down.',
                    'chapter_delete' => 'מחיקת פרק ריק בלבד.',
                    'unit_save'     => 'הגדרות יחידה. בלי יעד. שדות: title (כותרת התפריט), blocked_url (תיקיית סרטונים לחסומים).',
                ],
                'match' => 'במקום id אפשר {"match": "חלק מהכותרת"}. החיפוש הוא בנתיב המלא (פרק › תת-פרק › פריט). אם יש יותר מהתאמה אחת, הפעולה נכשלת ומחזירה candidates עם id לכל מועמד.',
                'example' => [
                    'ops' => [
                        ['op' => 'item_save', 'unit' => 5, 'match' => 'קנבה קוד', 'title' => 'קנבה קוד (מעודכן)'],
                        ['op' => 'item_add', 'unit' => 5, 'match' => 'משחקים ולומדות', 'title' => 'כלי חדש', 'html' => '<p>הסבר קצר</p>[video: https://youtu.be/XXXXXXXXXXX | הדגמה]'],
                        ['op' => 'group_save', 'unit' => 5, 'id' => 'tools_bots', 'rule' => 'one'],
                    ],
                ],
            ],
        ],
        'content_format' => [
            'html' => 'HTML רגיל (p, h4, ul, a, img, table, iframe). הכותרת של הפריט נפרדת מה-html.',
            'tokens' => [
                '[video: URL | כותרת]' => 'סרטון יוטיוב. הפריט מקבל וי אחרי צפייה בחצי מהסרטון או 3 דקות.',
                '[prompt]טקסט[/prompt]' => 'תיבת פרומפט עם כפתור העתקה.',
                '[canva: URL]' => 'הטמעת מצגת קנבה.',
                '[done-button: טקסט]' => 'תיבת סיום למשימה; הפריט מקבל וי כשמסמנים אותה.',
            ],
            'kinds' => 'video | read | embed נקבעים אוטומטית מהתוכן. discussion (וי אחרי תגובה) ו-task נקבעים ב-kind.',
            'rules' => 'rule של תת-פרק: one = מספיק פריט אחד, all = כל הפריטים, optional = רשות (לא נספר), מספר = כמות פריטים נדרשת.',
        ],
    ];
}

function cpt_agent_outline($data, $with_items) {
    $chapters = [];
    foreach ($data['chapters'] as $ch) {
        $c = ['id' => $ch['id'], 'title' => $ch['title'], 'groups' => []];
        foreach ((isset($ch['groups']) ? $ch['groups'] : []) as $g) {
            $items = isset($g['items']) ? $g['items'] : [];
            $gg = ['id' => $g['id'], 'title' => $g['title'], 'rule' => isset($g['rule']) ? $g['rule'] : 'one'];
            if ($with_items) {
                $gg['items'] = array_map(function ($it) { return [$it['id'], $it['title'], isset($it['kind']) ? $it['kind'] : 'read']; }, $items);
            } else {
                $gg['items'] = count($items);
            }
            $c['groups'][] = $gg;
        }
        $chapters[] = $c;
    }
    return $chapters;
}

function cpt_agent_state() {
    $out = ['ok' => true, 'live' => cpt_v4_enabled(), 'item_format' => '[id, title, kind]'];
    if (isset($_GET['unit']) && $_GET['unit'] !== '') {
        $u = cpt_agent_unit_param(wp_unslash($_GET['unit']));
        $data = cpt_v4_get((string) $u['n']);
        $out['unit'] = $u['n'];
        $out['title'] = $u['title'];
        $out['url'] = $u['url'];
        $out['sidebar_title'] = isset($data['sidebar_title']) ? $data['sidebar_title'] : '';
        $out['blocked_url'] = isset($data['blocked_url']) ? $data['blocked_url'] : '';
        $out['chapters'] = cpt_agent_outline($data, true);
        return $out;
    }
    $out['note'] = 'items = מספר הפריטים בתת-הפרק. לפירוט פריטים: state&unit=N';
    $out['units'] = [];
    foreach (cpt_agent_units() as $n => $u) {
        $out['units'][] = ['unit' => $u['n'], 'slug' => $u['slug'], 'title' => $u['title'], 'url' => $u['url'],
                           'chapters' => cpt_agent_outline(cpt_v4_get($n), false)];
    }
    return $out;
}

function cpt_agent_get() {
    $u = cpt_agent_unit_param(isset($_GET['unit']) ? wp_unslash($_GET['unit']) : '');
    $data = cpt_v4_get((string) $u['n']);
    $type = isset($_GET['type']) ? sanitize_key($_GET['type']) : 'item';
    if (!in_array($type, ['item', 'group', 'chapter'], true)) { cpt_agent_fail('type חייב להיות item, group או chapter'); }
    $r = cpt_agent_resolve($data, $type,
        isset($_GET['id']) ? wp_unslash($_GET['id']) : '',
        isset($_GET['match']) ? wp_unslash($_GET['match']) : '');
    if (isset($r['error'])) { cpt_agent_fail($r['error'], 404, ['candidates' => $r['candidates']]); }
    foreach ($data['chapters'] as $ch) {
        if ($type === 'chapter' && $ch['id'] === $r['id']) {
            return ['ok' => true, 'unit' => $u['n'], 'type' => 'chapter', 'id' => $ch['id'], 'title' => $ch['title'],
                    'html' => isset($ch['intro']) ? $ch['intro'] : '', 'groups' => cpt_agent_outline(['chapters' => [$ch]], true)[0]['groups']];
        }
        foreach ((isset($ch['groups']) ? $ch['groups'] : []) as $g) {
            if ($type === 'group' && $g['id'] === $r['id']) {
                return ['ok' => true, 'unit' => $u['n'], 'type' => 'group', 'id' => $g['id'], 'title' => $g['title'], 'chapter' => $ch['id'],
                        'rule' => isset($g['rule']) ? $g['rule'] : 'one', 'html' => isset($g['intro']) ? $g['intro'] : '',
                        'items' => array_map(function ($it) { return [$it['id'], $it['title'], isset($it['kind']) ? $it['kind'] : 'read']; }, isset($g['items']) ? $g['items'] : [])];
            }
            foreach ((isset($g['items']) ? $g['items'] : []) as $it) {
                if ($type === 'item' && $it['id'] === $r['id']) {
                    return ['ok' => true, 'unit' => $u['n'], 'type' => 'item', 'id' => $it['id'], 'title' => $it['title'],
                            'chapter' => $ch['id'], 'group' => $g['id'], 'kind' => isset($it['kind']) ? $it['kind'] : 'read',
                            'comments' => !empty($it['comments']), 'html' => isset($it['html']) ? $it['html'] : '',
                            'url' => $u['url'] . '#/i/' . rawurlencode($it['id'])];
                }
            }
        }
    }
    cpt_agent_fail('לא נמצא', 404);
}

function cpt_agent_progress() {
    $u = cpt_agent_unit_param(isset($_GET['unit']) ? wp_unslash($_GET['unit']) : '');
    if (!$u['post_id']) { cpt_agent_fail('ליחידה אין עמוד מקושר במניפסט', 404); }
    $data = cpt_v4_get((string) $u['n']);
    $idx = cpt_v4_index($data);
    global $wpdb;
    $users = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM " . CPT_ACTIVITY_TABLE_NAME . " WHERE post_id = %d
         UNION SELECT user_id FROM " . CPT_ITEM_TABLE . " WHERE post_id = %d",
        $u['post_id'], $u['post_id']
    ));
    $detail = !empty($_GET['detail']);
    $buckets = ['1-24' => 0, '25-49' => 0, '50-74' => 0, '75-99' => 0, '100' => 0];
    $sum = 0; $n = 0; $rows = [];
    $chapter_done = array_fill_keys(array_keys($idx['chapters']), 0);
    foreach ((array) $users as $uid) {
        $uid = (int) $uid;
        $s = cpt_v4_state($uid, $u['post_id'], $data, $idx);
        unset($GLOBALS['cpt_v4_state_cache'][$uid . ':' . $u['post_id']]);
        $p = (int) $s['percent'];
        if ($p <= 0) { continue; }
        $n++; $sum += $p;
        $buckets[$p >= 100 ? '100' : ($p >= 75 ? '75-99' : ($p >= 50 ? '50-74' : ($p >= 25 ? '25-49' : '1-24')))]++;
        foreach ($s['chapters'] as $cid => $c) { if (!empty($c['complete'])) { $chapter_done[$cid]++; } }
        if ($detail) {
            $user = get_userdata($uid);
            $rows[] = [$user ? $user->display_name : ('#' . $uid), $p, $s['items_done']];
        }
    }
    $chapters = [];
    foreach ($idx['chapters'] as $cid => $c) { $chapters[] = ['id' => $cid, 'title' => $c['title'], 'completed_by' => $chapter_done[$cid]]; }
    $out = ['ok' => true, 'unit' => $u['n'], 'title' => $u['title'], 'learners' => $n, 'completed' => $buckets['100'],
            'average_percent' => $n ? (int) round($sum / $n) : 0, 'distribution' => $buckets, 'chapters' => $chapters];
    if ($detail) {
        usort($rows, function ($a, $b) { return $b[1] <=> $a[1]; });
        $out['row_format'] = '[display_name, percent, items_done]';
        $out['rows'] = $rows;
    }
    return $out;
}

function cpt_agent_ops() {
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') { cpt_agent_fail('ops דורש POST', 405); }
    $raw = file_get_contents('php://input');
    $body = json_decode((string) $raw, true);
    if (!is_array($body) && isset($_POST['ops'])) {
        $body = ['ops' => json_decode(wp_unslash($_POST['ops']), true)];
    }
    $ops = (is_array($body) && isset($body['ops']) && is_array($body['ops'])) ? $body['ops'] : null;
    if ($ops === null) { cpt_agent_fail('הגוף חייב להיות JSON בצורה {"ops": [...]}'); }
    if (count($ops) > 50) { cpt_agent_fail('עד 50 פעולות בבקשה'); }

    // friendly op names -> engine ops, and the kind of target each op addresses
    $alias  = ['item_add' => 'group_additem', 'group_add' => 'chapter_addgroup'];
    $target = [
        'item_save' => 'item', 'item_move' => 'item', 'item_delete' => 'item',
        'group_additem' => 'group', 'group_save' => 'group', 'group_move' => 'group', 'group_delete' => 'group',
        'chapter_addgroup' => 'chapter', 'chapter_save' => 'chapter', 'chapter_move' => 'chapter', 'chapter_delete' => 'chapter',
        'chapter_add' => '', 'unit_save' => '',
    ];

    $results = []; $okc = 0;
    $cache = []; // unit => data (ops on the same unit build on each other)
    foreach (array_values($ops) as $i => $o) {
        $res = ['index' => $i, 'op' => is_array($o) && isset($o['op']) ? (string) $o['op'] : '', 'ok' => false];
        if (!is_array($o) || $res['op'] === '') { $res['error'] = 'חסר op'; $results[] = $res; continue; }
        $op = isset($alias[$res['op']]) ? $alias[$res['op']] : sanitize_key($res['op']);
        if (!isset($target[$op])) { $res['error'] = 'פעולה לא מוכרת. ראו action=help'; $results[] = $res; continue; }

        $units = cpt_agent_units();
        $un = isset($o['unit']) ? strtolower(trim((string) $o['unit'])) : '';
        if (!isset($units[$un])) {
            foreach ($units as $k => $uu) { if ($uu['slug'] === $un) { $un = (string) $k; break; } }
        }
        if (!isset($units[$un])) { $res['error'] = 'חסר unit תקין (1-10 או slug)'; $results[] = $res; continue; }
        if (!isset($cache[$un])) { $cache[$un] = cpt_v4_get($un); }
        $data = $cache[$un];

        $a = [];
        foreach (['title', 'html', 'dir', 'rule', 'kind', 'blocked_url'] as $k) {
            if (isset($o[$k]) && is_scalar($o[$k])) { $a[$k] = (string) $o[$k]; }
        }
        if (isset($o['comments'])) { $a['comments'] = filter_var($o['comments'], FILTER_VALIDATE_BOOLEAN); }

        if ($target[$op] !== '') {
            $r = cpt_agent_resolve($data, $target[$op], isset($o['id']) ? $o['id'] : '', isset($o['match']) ? $o['match'] : '');
            if (isset($r['error'])) {
                $res['error'] = $r['error'];
                if (!empty($r['candidates'])) { $res['candidates'] = $r['candidates']; }
                $results[] = $res; continue;
            }
            $a['id'] = $r['id'];
            $res['id'] = $r['id'];
        }

        $done = cpt_v4_apply_edit($data, $op, $a);
        if (!$done['ok']) { $res['error'] = $done['message']; $results[] = $res; continue; }
        cpt_v4_save($un, $done['data']);
        $cache[$un] = $done['data'];
        $res['ok'] = true;
        $res['unit'] = (int) $un;
        if (in_array($op, ['group_additem', 'chapter_addgroup', 'chapter_add'], true)) { $res['new_id'] = $done['focus']; }
        $okc++;
        $results[] = $res;
    }
    return ['ok' => $okc === count($results), 'succeeded' => $okc, 'failed' => count($results) - $okc, 'results' => $results];
}

// ---------------------------------------------------------------- admin page (the key)

add_action('admin_menu', function () {
    add_submenu_page('course-progress-tracker', 'גישה לסוכני AI', 'גישה לסוכנים', 'manage_options', 'cpt-agent-access', 'cpt_agent_admin_page');
}, 20);

function cpt_agent_admin_page() {
    if (!current_user_can('manage_options')) { return; }
    if (isset($_POST['cpt_agent_regen']) && check_admin_referer('cpt_agent_regen')) {
        delete_option('cpt_agent_key');
        echo '<div class="notice notice-success"><p>נוצר מפתח חדש. המפתח הקודם כבר לא עובד.</p></div>';
    }
    $key  = cpt_agent_key();
    $base = cpt_agent_base_url();
    $prompt = "יש לך גישה ל-API של קורס הבינה המלאכותית למורים (חולמים תקשוב).\n"
            . "כתובת הבסיס: {$base}\n"
            . "המפתח: {$key} (שולחים בכותרת X-Key בכל בקשה חוץ מ-help)\n"
            . "לפני כל דבר קרא את {$base}?action=help כדי ללמוד את הפעולות.\n"
            . "לקריאת מבנה: action=state (ועם unit=N לפירוט פריטים). לתוכן מלא של פריט: action=get.\n"
            . "לשינויים: POST ל-action=ops עם {\"ops\":[...]}. שינויים נכנסים לאתר החי מיד, ולכן לפני מחיקה או שינוי גדול הצג לי מה אתה עומד לעשות וחכה לאישור.";
    echo '<div class="wrap" dir="rtl"><h1>גישה לסוכני AI</h1>';
    echo '<p>סוכן AI יכול לקרוא ולערוך את הקורס דרך הכתובת הזו, בלי דפדפן ובלי התחברות, בעזרת המפתח שלמטה. המפתח נותן הרשאת עריכה מלאה לתוכן הקורס, אז שתפי אותו רק עם סוכן שאת סומכת עליו.</p>';
    echo '<table class="form-table"><tr><th>כתובת</th><td><code dir="ltr">' . esc_html($base) . '?action=help</code></td></tr>';
    echo '<tr><th>מפתח</th><td><input type="text" readonly class="regular-text code" dir="ltr" style="width:420px" onclick="this.select()" value="' . esc_attr($key) . '"></td></tr></table>';
    echo '<h2>הוראה מוכנה להדבקה לסוכן</h2><textarea readonly rows="8" style="width:100%;max-width:820px" onclick="this.select()">' . esc_textarea($prompt) . '</textarea>';
    echo '<form method="post" style="margin-top:18px">';
    wp_nonce_field('cpt_agent_regen');
    echo '<button type="submit" name="cpt_agent_regen" value="1" class="button" onclick="return confirm(\'ליצור מפתח חדש? המפתח הנוכחי יפסיק לעבוד.\')">יצירת מפתח חדש</button></form></div>';
}
