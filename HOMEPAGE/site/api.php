<?php
/**
 * API קטן לבית 360 — עריכת נקודות למנהלת האתר בלבד.
 * הזדהות דרך הלוגין של וורדפרס (wp-load.php בתיקיית האב).
 * הצפייה עצמה לא עוברת כאן — data.json נטען ישירות כקובץ סטטי.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

// חשוב: קוראים את הפרמטר רק אחרי טעינת וורדפרס — תוספים שרצים
// בסקופ הגלובלי דורסים משתנים גנריים כמו $action
$home360_action = isset($_GET['action']) ? $_GET['action'] : '';

$isAdmin = function_exists('current_user_can') && current_user_can('manage_options');

if ($home360_action === 'me') {
    echo json_encode(array(
        'admin' => $isAdmin,
        'nonce' => $isAdmin ? wp_create_nonce('home360_save') : ''
    ));
    exit;
}

if ($home360_action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
    if (!is_array($json) || !isset($json['spaces'])) {
        http_response_code(400);
        echo json_encode(array('error' => 'bad json'));
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
    echo json_encode(array('ok' => true));
    exit;
}

http_response_code(400);
echo json_encode(array('error' => 'unknown action'));
