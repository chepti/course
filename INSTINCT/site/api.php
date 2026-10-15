<?php
/**
 * API של "מתכונים למורה עם אינסטינקט" — chepti.com/instinct
 *
 * ציבורי:  help, list, submit (מתכון חדש → ממתין לאישור), hit (מונה העתקות), feedback
 * מנהלת:   me, list&all=1, state, ops (יצירה/עריכה/אישור/דחייה/מחיקה/הסתרה/הדגשה), export, feedback_list
 * הזדהות מנהלת: לוגין של וורדפרס (+ nonce בכותרת X-Instinct-Nonce בכתיבה)
 *               או מפתח סוכן: כותרת X-Key (או ?key=) — ראו config.sample.php
 *
 * הנתונים: data/recipes.json (כל המתכונים כולל ממתינים), data/feedback.json. התיקייה חסומה לגישה ישירה.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ---------- קונפיג ----------
$inst_cfg = array();
if (is_readable(__DIR__ . '/config.php')) {
    $inst_loaded = include __DIR__ . '/config.php';
    if (is_array($inst_loaded)) $inst_cfg = $inst_loaded;
}
$inst_agentKey = isset($inst_cfg['agent_key']) ? (string) $inst_cfg['agent_key'] : '';
$inst_siteUrl  = isset($inst_cfg['site_url']) ? rtrim($inst_cfg['site_url'], '/') : 'https://chepti.com/instinct';

// ---------- טעינת וורדפרס רק כשצריך (אימות מנהלת / שליחת מייל) ----------
// חשוב: משתני-על עם קידומת inst_ — תוספים דורסים משתנים גנריים כמו $action
$inst_actionName = isset($_GET['action']) ? (string) $_GET['action'] : '';
$inst_hasWpCookie = false;
foreach ($_COOKIE as $inst_ck => $inst_cv) {
    if (strpos($inst_ck, 'wordpress_logged_in_') === 0) { $inst_hasWpCookie = true; break; }
}
$inst_wpLoaded = false;
if ($inst_hasWpCookie || in_array($inst_actionName, array('submit', 'feedback'), true)) {
    $inst_wpLoad = dirname(__DIR__) . '/wp-load.php';
    if (file_exists($inst_wpLoad)) {
        define('WP_USE_THEMES', false);
        require_once $inst_wpLoad;
        $inst_wpLoaded = true;
        if (function_exists('nocache_headers')) nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
    }
}
$inst_actionName = isset($_GET['action']) ? (string) $_GET['action'] : '';

const INST_CATS = array(
    'teach' => 'הוראה ותוכן לימודי',
    'video' => 'וידאו, מולטימדיה ויצירה',
    'design' => 'תמונות, עיצוב ומסמכים',
    'dev' => 'פיתוח, אפליקציות ומשחקים',
    'auto' => 'אוטומציה ומעקב',
    'research' => 'מחקר, תרגום ותכנון',
    'team' => 'ניהול צוות וקהילה מקצועית',
);
const INST_LEVELS = array(1 => 'בסיסי', 2 => 'בינוני', 3 => 'מתקדם');
const INST_STATUSES = array('published', 'pending', 'hidden');
const INST_LIMITS = array(
    'title' => 140, 'gives' => 400, 'sheetTitle' => 200, 'prep' => 500, 'example' => 1500,
    'prompt' => 8000, 'tip' => 1000, 'tipLabel' => 40, 'limits' => 1000, 'author' => 80, 'email' => 120,
);
// שדות שגולש יכול לשלוח (שאר השדות — רק מנהלת)
const INST_PUBLIC_FIELDS = array('title', 'gives', 'prep', 'prompt', 'tip', 'author', 'email', 'cat', 'level');
// שדות שלא יוצאים לציבור
const INST_PRIVATE = array('email', 'copies', 'order', 'created', 'updated');

// ---------- עזרים ----------
function inst_out($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function inst_err($msg, $code = 400, $extra = array()) {
    inst_out(array_merge(array('ok' => false, 'error' => $msg), $extra), $code);
}
function inst_body() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : array();
}
function inst_len($s) { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }
function inst_cut($s, $n) { return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }
function inst_lower($s) { return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s); }
function inst_has($hay, $needle) {
    return $needle === '' || strpos(inst_lower($hay), inst_lower($needle)) !== false;
}
function inst_new_id() { return 'u' . substr(str_replace('.', '', uniqid('', true)), -9); }

function inst_path($name) { return __DIR__ . '/data/' . $name . '.json'; }

/** קריאה-שינוי-כתיבה אטומית של קובץ JSON. $fn מקבל את המערך בהפניה ומחזיר תוצאה. */
function inst_update($name, $fn) {
    $path = inst_path($name);
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
    $fh = fopen($path, 'c+');
    if (!$fh) inst_err('storage unavailable', 500);
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = $raw !== '' && $raw !== false ? json_decode($raw, true) : array();
    if (!is_array($data)) $data = array();
    $before = $data;
    $res = $fn($data);
    if ($data !== $before) {
        if ($raw) @file_put_contents(dirname($path) . '/' . $name . '.backup.json', $raw);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode(array_values($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $res;
}
function inst_read($name) {
    $path = inst_path($name);
    if (!is_readable($path)) return array();
    $j = json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : array();
}

// ---------- הרשאות ----------
function inst_key_given() {
    $k = isset($_SERVER['HTTP_X_KEY']) ? $_SERVER['HTTP_X_KEY'] : (isset($_GET['key']) ? $_GET['key'] : '');
    return (string) $k;
}
function inst_is_admin($needNonce = false) {
    global $inst_agentKey, $inst_wpLoaded;
    $given = inst_key_given();
    if ($given !== '' && $inst_agentKey !== '' && hash_equals($inst_agentKey, $given)) return true;
    if ($inst_wpLoaded && function_exists('current_user_can') && current_user_can('manage_options')) {
        if (!$needNonce) return true;
        $nonce = isset($_SERVER['HTTP_X_INSTINCT_NONCE']) ? $_SERVER['HTTP_X_INSTINCT_NONCE'] : '';
        return (bool) wp_verify_nonce($nonce, 'instinct_admin');
    }
    return false;
}
function inst_require_admin($write = false) {
    if (!inst_is_admin($write)) {
        inst_err('admin only — התחברי לוורדפרס או שלחי X-Key', 403, array('loggedIn' => function_exists('is_user_logged_in') && is_user_logged_in()));
    }
}

// ---------- ניקוי קלט ----------
function inst_clean_field($k, $v) {
    if ($k === 'cat') return isset(INST_CATS[$v]) ? $v : '';
    if ($k === 'level') { $n = (int) $v; return ($n >= 1 && $n <= 3) ? $n : 1; }
    if ($k === 'draft' || $k === 'featured') return (bool) $v;
    if ($k === 'status') return in_array($v, INST_STATUSES, true) ? $v : 'pending';
    $s = str_replace("\r\n", "\n", trim((string) $v));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return inst_cut($s, INST_LIMITS[$k]);
}
function inst_count_urls($s) { return preg_match_all('#https?://|www\.#i', $s); }

// ---------- מצב הנתונים כלפי חוץ ----------
function inst_public_view($r) {
    foreach (INST_PRIVATE as $k) unset($r[$k]);
    unset($r['status']);
    return $r;
}
function inst_sorted_public($all) {
    $pub = array_values(array_filter($all, function ($r) { return isset($r['status']) && $r['status'] === 'published'; }));
    $catIdx = array_flip(array_keys(INST_CATS));
    usort($pub, function ($a, $b) use ($catIdx) {
        $ca = isset($catIdx[$a['cat']]) ? $catIdx[$a['cat']] : 99;
        $cb = isset($catIdx[$b['cat']]) ? $catIdx[$b['cat']] : 99;
        if ($ca !== $cb) return $ca - $cb;
        $fa = !empty($a['featured']) ? 0 : 1; $fb = !empty($b['featured']) ? 0 : 1;
        if ($fa !== $fb) return $fa - $fb;
        if ((int) $a['level'] !== (int) $b['level']) return (int) $a['level'] - (int) $b['level'];
        return (isset($a['order']) ? $a['order'] : 0) - (isset($b['order']) ? $b['order'] : 0);
    });
    return $pub;
}

// ---------- איתור מתכון לפי id או חלק מהכותרת ----------
function inst_find(&$all, $op) {
    if (!empty($op['id'])) {
        foreach ($all as $i => $r) if ($r['id'] === $op['id']) return array($i, null);
        return array(null, array('error' => 'not_found', 'id' => $op['id']));
    }
    if (isset($op['match']) && trim((string) $op['match']) !== '') {
        $hits = array();
        foreach ($all as $i => $r) {
            if (!empty($op['status']) && $r['status'] !== $op['status']) continue;
            if (inst_has($r['title'], (string) $op['match'])) $hits[] = $i;
        }
        if (count($hits) === 1) return array($hits[0], null);
        if (!$hits) return array(null, array('error' => 'not_found', 'match' => $op['match']));
        $cands = array();
        foreach ($hits as $i) $cands[] = array('id' => $all[$i]['id'], 'title' => $all[$i]['title'], 'status' => $all[$i]['status']);
        return array(null, array('error' => 'ambiguous', 'message' => 'כמה מתכונים תואמים — שלחי id מהרשימה', 'candidates' => $cands));
    }
    return array(null, array('error' => 'need id or match'));
}

function inst_next_order($all) {
    $m = 0;
    foreach ($all as $r) if (isset($r['order']) && $r['order'] > $m) $m = $r['order'];
    return $m + 1;
}
function inst_apply_fields(&$r, $src) {
    foreach (array_merge(array_keys(INST_LIMITS), array('cat', 'level', 'draft', 'featured')) as $k) {
        if (array_key_exists($k, $src)) $r[$k] = inst_clean_field($k, $src[$k]);
    }
}

/** מבצע פעולה אחת. מחזיר מערך תוצאה (ok או error). */
function inst_apply_op(&$all, $op) {
    $name = isset($op['op']) ? (string) $op['op'] : '';
    $hasFields = isset($op['fields']) && is_array($op['fields']);
    $fields = $hasFields ? $op['fields'] : $op;
    $now = time();

    if ($name === 'create') {
        $r = array('id' => inst_new_id(), 'cat' => '', 'level' => 1, 'title' => '', 'gives' => '', 'sheetTitle' => '', 'prep' => '',
            'example' => '', 'prompt' => '', 'tip' => '', 'limits' => '', 'author' => '', 'featured' => false,
            'status' => 'published', 'created' => $now, 'updated' => $now, 'copies' => 0, 'order' => inst_next_order($all));
        inst_apply_fields($r, $fields);
        if (isset($fields['status'])) $r['status'] = inst_clean_field('status', $fields['status']);
        if ($r['title'] === '' || $r['prompt'] === '') return array('ok' => false, 'error' => 'title and prompt are required');
        if ($r['cat'] === '') return array('ok' => false, 'error' => 'cat is required', 'cats' => array_keys(INST_CATS));
        $all[] = $r;
        return array('ok' => true, 'id' => $r['id'], 'title' => $r['title'], 'status' => $r['status']);
    }

    if (in_array($name, array('update', 'approve', 'reject', 'delete', 'hide', 'show', 'feature'), true)) {
        list($i, $e) = inst_find($all, $op);
        if ($e) return array_merge(array('ok' => false), $e);
        $r = &$all[$i];
        $title = $r['title'];
        if ($name === 'delete' || $name === 'reject') {
            if ($name === 'reject' && $r['status'] !== 'pending') return array('ok' => false, 'error' => 'reject applies to pending only (use hide/delete)', 'id' => $r['id']);
            $id = $r['id'];
            unset($r);
            array_splice($all, $i, 1);
            return array('ok' => true, 'id' => $id, 'title' => $title, 'deleted' => true);
        }
        if ($name === 'update' || $name === 'approve') {
            inst_apply_fields($r, $fields);
            if ($hasFields && isset($fields['status']) && $name === 'update') $r['status'] = inst_clean_field('status', $fields['status']);
        }
        if ($name === 'approve') {
            if ($r['cat'] === '') return array('ok' => false, 'error' => 'needs_cat', 'message' => 'חסרה קטגוריה — שלחי fields.cat', 'id' => $r['id'], 'cats' => array_keys(INST_CATS));
            if ($r['title'] === '' || $r['prompt'] === '') return array('ok' => false, 'error' => 'title and prompt are required', 'id' => $r['id']);
            $r['status'] = 'published';
            $r['order'] = inst_next_order($all);
        }
        if ($name === 'hide') $r['status'] = 'hidden';
        if ($name === 'show') $r['status'] = 'published';
        if ($name === 'feature') $r['featured'] = array_key_exists('value', $op) ? (bool) $op['value'] : true;
        $r['updated'] = $now;
        return array('ok' => true, 'id' => $r['id'], 'title' => $r['title'], 'status' => $r['status'], 'featured' => !empty($r['featured']));
    }

    return array('ok' => false, 'error' => 'unknown op: ' . $name, 'ops' => array('create', 'update', 'approve', 'reject', 'delete', 'hide', 'show', 'feature', 'feedback_delete'));
}

// ---------- הגבלת קצב ושליחת מייל ----------
function inst_rate_ok($bucket, $maxHour, $maxDay) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
    $key = substr(sha1($ip . '|inst|' . $bucket), 0, 16);
    $now = time();
    return inst_update('rate', function (&$d) use ($key, $now, $maxHour, $maxDay) {
        // המבנה: רשימת רשומות {k,t}
        $d = array_values(array_filter($d, function ($e) use ($now) { return $now - $e['t'] < 86400; }));
        $h = 0; $dd = 0;
        foreach ($d as $e) if ($e['k'] === $key) { $dd++; if ($now - $e['t'] < 3600) $h++; }
        if ($h >= $maxHour || $dd >= $maxDay) return false;
        $d[] = array('k' => $key, 't' => $now);
        return true;
    });
}
function inst_notify($subject, $body) {
    $to = function_exists('get_option') ? get_option('admin_email') : '';
    if (!$to) return;
    $body .= "\n\n" . $GLOBALS['inst_siteUrl'] . '/';
    if (function_exists('wp_mail')) @wp_mail($to, $subject, $body);
    else @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "Content-Type: text/plain; charset=UTF-8");
}

// ============ ניתוב ============
$inst_method = $_SERVER['REQUEST_METHOD'];

if ($inst_actionName === 'help' || $inst_actionName === '') {
    inst_out(array(
        'name' => 'מתכונים למורה עם אינסטינקט',
        'about' => 'ספריית בקשות מוכנות לסוכן אישי. גולשים מציעים מתכונים (מחכים לאישור), המנהלת מאשרת/עורכת.',
        'base' => $inst_siteUrl . '/api.php',
        'auth' => 'פעולות מנהלת: כותרת X-Key: <מפתח> (או ?key=). בדפדפן — לוגין וורדפרס. פעולות ציבוריות ללא הזדהות.',
        'enums' => array('cat' => INST_CATS, 'level' => INST_LEVELS, 'status' => INST_STATUSES),
        'recipe_fields' => array(
            'title' => 'שם המתכון (חובה)', 'prompt' => 'הבקשה לסוכן (חובה). [סוגריים מרובעים] = שדות להשלמה',
            'cat' => 'קטגוריה (חובה לפרסום)', 'level' => '1-3', 'gives' => 'מה מקבלים בסוף', 'prep' => 'מה צריך להכין',
            'tip' => 'טיפ', 'tipLabel' => 'כותרת לטיפ (ברירת מחדל: טיפ מהניסיון)', 'limits' => 'שימו לב', 'example' => 'איך זה נראה בפועל',
            'sheetTitle' => 'שם משני לחיפוש', 'author' => 'קרדיט', 'draft' => 'טרם נוסה (bool)', 'featured' => 'מומלץ (bool)',
        ),
        'actions' => array(
            'help' => 'GET — המסמך הזה',
            'list' => 'GET — המתכונים המאושרים. מנהלת: &all=1 מחזיר גם ממתינים ומוסתרים עם שדות פרטיים',
            'state' => 'GET (מנהלת) — תמונת מצב קומפקטית: ספירות, ממתינים, רשימת כותרות',
            'submit' => 'POST (ציבורי) — {title,prompt,cat?,level?,gives?,prep?,tip?,author?,email?,ok:true} → נכנס כ"ממתין". עד 4 בשעה לכתובת',
            'hit' => 'POST (ציבורי) — {id} מגדיל מונה העתקות',
            'tried' => 'POST (ציבורי) — {id, on:true|false} "ניסיתי!": מגדיל/מקטין מונה של מי שסימנו שהמתכון עבד. מחזיר {tried}. השדה tried מופיע גם ב-list',
            'feedback' => 'POST (ציבורי) — {message,contact?}',
            'feedback_list' => 'GET (מנהלת)',
            'export' => 'GET (מנהלת) — גיבוי מלא',
            'ops' => 'POST (מנהלת) — {"ops":[{op,...},...]}: כל פעולה מדווחת בנפרד. אפשר גם אובייקט בודד עם op.',
        ),
        'ops' => array(
            'create' => '{op:"create", fields:{title,prompt,cat,...}} (מתפרסם מיד; fields.status:"pending" להשאיר ממתין)',
            'update' => '{op:"update", id|match, fields:{...}}',
            'approve' => '{op:"approve", id|match, fields?:{cat,...}} — מפרסם ממתין',
            'reject' => '{op:"reject", id|match} — מוחק ממתין',
            'delete' => '{op:"delete", id|match}',
            'hide' => '{op:"hide", id|match} — מסתיר מהציבור',
            'show' => '{op:"show", id|match}',
            'feature' => '{op:"feature", id|match, value?:true|false}',
            'feedback_delete' => '{op:"feedback_delete", id}',
        ),
        'match' => '{"match":"חלק מהכותרת"} במקום id. בהתנגשות: error=ambiguous + candidates[]; אפשר לצמצם עם "status":"pending".',
        'examples' => array(
            'list_pending' => 'GET ?action=state  (עם X-Key)',
            'approve_two' => 'POST ?action=ops {"ops":[{"op":"approve","match":"דף עבודה","status":"pending","fields":{"cat":"teach"}},{"op":"reject","id":"u123abc"}]}',
        ),
    ));
}

if ($inst_actionName === 'me') {
    $inst_loginUrl = $inst_wpLoaded && function_exists('wp_login_url') ? wp_login_url($inst_siteUrl . '/') : '/wp-login.php?redirect_to=' . urlencode($inst_siteUrl . '/');
    $inst_admin = inst_is_admin();
    inst_out(array(
        'admin' => $inst_admin,
        'loggedIn' => $inst_wpLoaded && function_exists('is_user_logged_in') && is_user_logged_in(),
        'loginUrl' => $inst_loginUrl,
        'nonce' => ($inst_admin && $inst_wpLoaded) ? wp_create_nonce('instinct_admin') : '',
    ));
}

if ($inst_actionName === 'list') {
    $all = inst_read('recipes');
    if (isset($_GET['all']) && $_GET['all'] === '1') {
        inst_require_admin();
        inst_out(array('ok' => true, 'recipes' => array_values($all), 'cats' => INST_CATS, 'levels' => INST_LEVELS));
    }
    $pub = array_map('inst_public_view', inst_sorted_public($all));
    inst_out(array(
        'ok' => true, 'recipes' => $pub, 'cats' => INST_CATS, 'levels' => INST_LEVELS,
        'maybeAdmin' => $inst_hasWpCookie || inst_key_given() !== '',
    ));
}

if ($inst_actionName === 'state') {
    inst_require_admin();
    $all = inst_read('recipes');
    $counts = array('published' => 0, 'pending' => 0, 'hidden' => 0);
    $pending = array(); $titles = array();
    foreach ($all as $r) {
        $counts[$r['status']]++;
        if ($r['status'] === 'pending') {
            $pending[] = array('id' => $r['id'], 'title' => $r['title'], 'cat' => $r['cat'], 'author' => $r['author'], 'created' => date('Y-m-d H:i', $r['created']));
        }
        $titles[] = array('id' => $r['id'], 't' => $r['title'], 'cat' => $r['cat'], 's' => $r['status'], 'f' => !empty($r['featured']) ? 1 : 0);
    }
    inst_out(array('ok' => true, 'counts' => $counts, 'feedback' => count(inst_read('feedback')), 'pending' => $pending, 'recipes' => $titles));
}

if ($inst_actionName === 'export') {
    inst_require_admin();
    inst_out(array('ok' => true, 'exported' => date('c'), 'recipes' => inst_read('recipes'), 'feedback' => inst_read('feedback')));
}

if ($inst_actionName === 'feedback_list') {
    inst_require_admin();
    inst_out(array('ok' => true, 'feedback' => inst_read('feedback')));
}

if ($inst_actionName === 'submit' && $inst_method === 'POST') {
    $b = inst_body();
    if (!empty($b['website'])) inst_out(array('ok' => true));          // honeypot — בוט
    if (empty($b['ok'])) inst_err('יש לאשר שאין פרטים מזהים של תלמידים');
    $clean = array();
    foreach (INST_PUBLIC_FIELDS as $k) $clean[$k] = isset($b[$k]) ? inst_clean_field($k, $b[$k]) : inst_clean_field($k, '');
    if ($clean['title'] === '') inst_err('חסר שם למתכון');
    if ($clean['prompt'] === '') inst_err('חסרה הבקשה לסוכן');
    $urls = 0; foreach ($clean as $v) if (is_string($v)) $urls += inst_count_urls($v);
    if ($urls > 2) inst_err('יותר מדי קישורים בהגשה');
    if (!inst_rate_ok('submit', 4, 12)) inst_err('נשלחו הרבה מתכונים בזמן קצר. אפשר לנסות שוב מאוחר יותר.', 429);
    $isDup = inst_update('recipes', function (&$all) use ($clean) {
        foreach ($all as $r) if ($r['status'] === 'pending' && $r['title'] === $clean['title'] && $r['prompt'] === $clean['prompt']) return true;
        $now = time();
        $all[] = array_merge(array('id' => inst_new_id(), 'sheetTitle' => '', 'example' => '', 'limits' => '', 'featured' => false,
            'status' => 'pending', 'created' => $now, 'updated' => $now, 'copies' => 0, 'order' => inst_next_order($all)), $clean);
        return false;
    });
    if (!$isDup) {
        inst_notify('מתכון חדש ממתין לאישור: ' . $clean['title'],
            "הוגש מתכון חדש לספריית המתכונים.\n\nשם: " . $clean['title'] . "\nקרדיט: " . $clean['author'] . "\n\nלאישור: " . $inst_siteUrl . '/#/pending');
    }
    inst_out(array('ok' => true));
}

if ($inst_actionName === 'hit' && $inst_method === 'POST') {
    $b = inst_body();
    $id = isset($b['id']) ? (string) $b['id'] : '';
    inst_update('recipes', function (&$all) use ($id) {
        foreach ($all as &$r) if ($r['id'] === $id && $r['status'] === 'published') { $r['copies'] = (isset($r['copies']) ? $r['copies'] : 0) + 1; break; }
    });
    inst_out(array('ok' => true));
}

// "ניסיתי!" — סימון שמתכון עבד. הסימון האישי נשמר בדפדפן; כאן רק המונה המצטבר (on:false = ביטול סימון)
if ($inst_actionName === 'tried' && $inst_method === 'POST') {
    $b = inst_body();
    $id = isset($b['id']) ? (string) $b['id'] : '';
    $delta = (isset($b['on']) && !$b['on']) ? -1 : 1;
    if (!inst_rate_ok('tried', 40, 150)) inst_err('יותר מדי סימונים בזמן קצר', 429);
    $n = inst_update('recipes', function (&$all) use ($id, $delta) {
        foreach ($all as &$r) {
            if ($r['id'] === $id && $r['status'] === 'published') {
                $r['tried'] = max(0, (isset($r['tried']) ? (int) $r['tried'] : 0) + $delta);
                return $r['tried'];
            }
        }
        return null;
    });
    if ($n === null) inst_err('not_found', 404);
    inst_out(array('ok' => true, 'tried' => $n));
}

if ($inst_actionName === 'feedback' && $inst_method === 'POST') {
    $b = inst_body();
    if (!empty($b['website'])) inst_out(array('ok' => true));
    $msg = inst_cut(trim(isset($b['message']) ? (string) $b['message'] : ''), 2000);
    $contact = inst_cut(trim(isset($b['contact']) ? (string) $b['contact'] : ''), 120);
    if ($msg === '') inst_err('אין הודעה');
    if (inst_count_urls($msg) > 2) inst_err('יותר מדי קישורים');
    if (!inst_rate_ok('feedback', 3, 8)) inst_err('נשלחו הרבה הודעות. אפשר לנסות שוב מאוחר יותר.', 429);
    inst_update('feedback', function (&$d) use ($msg, $contact) {
        $d[] = array('id' => inst_new_id(), 'message' => $msg, 'contact' => $contact, 'created' => time());
    });
    inst_notify('משוב חדש על מתכוני אינסטינקט', $msg . ($contact !== '' ? "\n\nליצירת קשר: " . $contact : ''));
    inst_out(array('ok' => true));
}

if (($inst_actionName === 'ops' || $inst_actionName === 'op') && $inst_method === 'POST') {
    inst_require_admin(true);
    $b = inst_body();
    $ops = isset($b['ops']) && is_array($b['ops']) ? $b['ops'] : (isset($b['op']) ? array($b) : array());
    if (!$ops) inst_err('no ops — send {"ops":[...]}');
    if (count($ops) > 100) inst_err('too many ops (max 100)');
    $results = array();
    foreach ($ops as $n => $op) {
        if (!is_array($op)) { $results[] = array('ok' => false, 'error' => 'op must be an object'); continue; }
        if (isset($op['op']) && $op['op'] === 'feedback_delete') {
            $id = isset($op['id']) ? (string) $op['id'] : '';
            $results[] = inst_update('feedback', function (&$d) use ($id) {
                foreach ($d as $i => $f) if ($f['id'] === $id) { array_splice($d, $i, 1); return array('ok' => true, 'id' => $id); }
                return array('ok' => false, 'error' => 'not_found', 'id' => $id);
            });
            continue;
        }
        $results[] = inst_update('recipes', function (&$all) use ($op) { return inst_apply_op($all, $op); });
    }
    $okN = count(array_filter($results, function ($r) { return !empty($r['ok']); }));
    inst_out(array('ok' => $okN === count($results), 'done' => $okN, 'failed' => count($results) - $okN, 'results' => $results));
}

inst_err('unknown action — see ?action=help', 400);
