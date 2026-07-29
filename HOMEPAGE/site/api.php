<?php
/**
 * API קטן לבית 360 — עריכת נקודות למנהלת האתר בלבד.
 * הזדהות דרך הלוגין של וורדפרס (wp-load.php בתיקיית האב).
 * הצפייה עצמה לא עוברת כאן — data.json נטען ישירות כקובץ סטטי.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$dataFile = __DIR__ . '/data.json';

// טעינת וורדפרס בלי התבנית — רק לאימות משתמש
$wpLoad = dirname(__DIR__) . '/wp-load.php';
if (!file_exists($wpLoad)) {
    http_response_code(500);
    echo json_encode(array('error' => 'wp-load.php not found'));
    exit;
}
define('WP_USE_THEMES', false);
require_once $wpLoad;

if (function_exists('nocache_headers')) {
    nocache_headers();
}

// חשוב: קוראים את הפרמטר רק אחרי טעינת וורדפרס — תוספים שרצים
// בסקופ הגלובלי דורסים משתנים גנריים כמו $action
$home360_action = isset($_GET['action']) ? $_GET['action'] : '';

$loggedIn = function_exists('is_user_logged_in') && is_user_logged_in();
$isAdmin = function_exists('current_user_can') && current_user_can('manage_options');

/**
 * מעדכן את בלוק HOME360 ב-.htaccess של השורש לכתובות קצרות /learn /works וכו'.
 */
function home360_update_root_rewrites($spaces) {
    $htaccess = dirname(__DIR__) . '/.htaccess';
    if (!is_readable($htaccess) || !is_writable($htaccess)) {
        return false;
    }

    $reserved = array(
        'home', 'edit', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json',
        'lottie', 'radio', 'maps', 'mora', 'rashi', 'shvut', 'tasks', 'tikshuv',
        'build', 'agol', 'ca', 'domains', 'dvash', 'geg', '9bav', 'wilk',
        'xmlrpc.php', 'favicon.ico', 'robots.txt'
    );
    $reservedMap = array_fill_keys($reserved, true);

    $slugs = array('edit');
    if (is_array($spaces)) {
        foreach ($spaces as $sp) {
            if (empty($sp['id'])) continue;
            $id = strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $sp['id']));
            if ($id === '' || isset($reservedMap[$id])) continue;
            if (!in_array($id, $slugs, true)) $slugs[] = $id;
        }
    }
    // תמיד לכלול גם entrance/learn/works אם קיימים בנתונים — הסדר לא משנה
    $pattern = implode('|', array_map(function ($s) {
        return preg_quote($s, '/');
    }, $slugs));

    $block = "# BEGIN HOME360\n"
        . "# דף הבית 360 — שורש + כתובות קצרות למרחבים (chepti.com/learn)\n"
        . "<IfModule mod_rewrite.c>\n"
        . "RewriteEngine On\n"
        . "RewriteRule ^\$ /home/index.html [L]\n"
        . "RewriteRule ^({$pattern})/?\$ /home/index.html [L]\n"
        . "</IfModule>\n"
        . "# END HOME360\n";

    $raw = file_get_contents($htaccess);
    if ($raw === false) return false;

    if (strpos($raw, '# BEGIN HOME360') !== false && strpos($raw, '# END HOME360') !== false) {
        $updated = preg_replace(
            '/# BEGIN HOME360.*?# END HOME360\s*/s',
            $block . "\n",
            $raw,
            1
        );
    } else {
        $updated = $block . "\n" . $raw;
    }

    if ($updated === null || $updated === $raw && strpos($raw, "RewriteRule ^({$pattern})") !== false) {
        // אם זהה למעט רווחים — עדיין נכתוב אם הבלוק השתנה
    }
    return file_put_contents($htaccess, $updated) !== false;
}

if ($home360_action === 'me') {
    $user = $loggedIn && function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    echo json_encode(array(
        'admin' => $isAdmin,
        'loggedIn' => $loggedIn,
        'user' => ($user && !empty($user->user_login)) ? $user->user_login : '',
        'loginUrl' => function_exists('wp_login_url')
            ? wp_login_url((isset($_GET['redirect']) ? $_GET['redirect'] : home_url('/edit')))
            : '/wp-login.php',
        'nonce' => $isAdmin ? wp_create_nonce('home360_save') : ''
    ));
    exit;
}

// העלאת תמונה מודבקת/נגררת — נשמרת ישר כקובץ, לעולם לא נכנסת ל-data.json כטקסט
if ($home360_action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(array('error' => 'not admin'));
        exit;
    }
    $nonce = isset($_SERVER['HTTP_X_HOME360_NONCE']) ? $_SERVER['HTTP_X_HOME360_NONCE'] : '';
    if (!wp_verify_nonce($nonce, 'home360_save')) {
        http_response_code(403);
        echo json_encode(array('error' => 'bad nonce'));
        exit;
    }

    $body = file_get_contents('php://input');
    $json = json_decode($body, true);
    $data = isset($json['data']) ? $json['data'] : '';
    $slug = isset($json['slug']) ? $json['slug'] : 'img';
    $slug = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', $slug));
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'img';

    if (!preg_match('#^data:(image/(?:jpeg|jpg|png|webp|gif));base64,(.+)$#s', $data, $m)) {
        http_response_code(400);
        echo json_encode(array('error' => 'bad image data'));
        exit;
    }
    $mime = $m[1];
    $ext = array('image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
    $ext = isset($ext[$mime]) ? $ext[$mime] : 'jpg';
    $raw = base64_decode($m[2], true);
    if ($raw === false) {
        http_response_code(400);
        echo json_encode(array('error' => 'bad base64'));
        exit;
    }

    $dir = __DIR__ . '/img/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // שם ייחודי לכל העלאה — קובץ חדש לגמרי בכל פעם, כדי לא ליפול שוב במלכודת
    // קאש התמונות של הדפדפן (ר' תיעוד: reuse של אותו שם קובץ = תמונה ישנה נתקעת לצמיתות)
    $fname = $slug . '-' . substr(uniqid(), -8) . '.' . $ext;
    $path = $dir . '/' . $fname;
    if (file_put_contents($path, $raw) === false) {
        http_response_code(500);
        echo json_encode(array('error' => 'write failed'));
        exit;
    }

    echo json_encode(array('ok' => true, 'path' => 'img/uploads/' . $fname));
    exit;
}

// גרסת הנתונים הנוכחית בשרת (זמן שינוי הקובץ) — לזיהוי דריסה
if ($home360_action === 'version') {
    clearstatcache(true, $dataFile);
    echo json_encode(array('version' => file_exists($dataFile) ? (int) filemtime($dataFile) : 0));
    exit;
}

if ($home360_action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(array('error' => 'not admin', 'loggedIn' => $loggedIn));
        exit;
    }
    $nonce = isset($_SERVER['HTTP_X_HOME360_NONCE']) ? $_SERVER['HTTP_X_HOME360_NONCE'] : '';
    if (!wp_verify_nonce($nonce, 'home360_save')) {
        http_response_code(403);
        echo json_encode(array('error' => 'bad nonce'));
        exit;
    }

    $body = file_get_contents('php://input');
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['spaces'])) {
        http_response_code(400);
        echo json_encode(array('error' => 'bad json'));
        exit;
    }

    // ——— הגנה מפני דריסה: אם הקובץ בשרת עודכן מאז שהדפדפן טען אותו, לא לשמור ———
    // הגרסה בשרת נקבעת לפי זמן השינוי של הקובץ, כדי שגם עדכון ידני (scp) ייספר.
    $serverVersion = file_exists($dataFile) ? (int) filemtime($dataFile) : 0;
    $clientVersion = isset($_SERVER['HTTP_X_HOME360_VERSION']) ? (int) $_SERVER['HTTP_X_HOME360_VERSION'] : 0;
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    if (!$force && $serverVersion > 0 && $clientVersion !== $serverVersion) {
        http_response_code(409);
        echo json_encode(array(
            'error' => 'stale',
            'serverVersion' => $serverVersion,
            'clientVersion' => $clientVersion
        ));
        exit;
    }

    if (file_exists($dataFile)) {
        copy($dataFile, __DIR__ . '/data.backup.json');
    }
    $ok = file_put_contents(
        $dataFile,
        json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
    if ($ok === false) {
        http_response_code(500);
        echo json_encode(array('error' => 'write failed'));
        exit;
    }

    clearstatcache(true, $dataFile);
    $rewrote = home360_update_root_rewrites($json['spaces']);
    echo json_encode(array(
        'ok' => true,
        'rewrites' => $rewrote,
        'version' => (int) filemtime($dataFile)
    ));
    exit;
}

http_response_code(400);
echo json_encode(array('error' => 'unknown action'));
