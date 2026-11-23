<?php
/**
 * נקודת פריסה לריפו chepti/course. GitHub Actions קורא לכאן אחרי כל דחיפה ל-master,
 * והשרת מושך בעצמו את הקוד מ-GitHub (Hostinger חוסם SSH מכתובות של GitHub Actions, HTTPS עובד).
 *
 * מיקום בשרת: ~/public_html/tikshuv/course-deploy.php  (מועלה ידנית ב-scp; הקובץ הזה לא נפרס אוטומטית)
 * POST  כותרת X-Deploy-Token: <התוכן של ~/.course-deploy-token>   גוף: sha=<40 hex> (אופציונלי)
 *
 * ממפה תיקיות בריפו לתיקיות בשרת. לא מוחק קבצים. לא נוגע ב-config.php וב-data/ של instinct.
 */
header('Content-Type: application/json; charset=utf-8');
function reply($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['error' => 'POST only']);

$wp  = realpath(__DIR__);                 // .../public_html/tikshuv
$pub = dirname($wp);                      // .../public_html
$home = null;
foreach ([getenv('HOME'), dirname($wp, 3), dirname($wp, 4), dirname($wp, 2)] as $h) {
    if ($h && is_file($h . '/.course-deploy-token')) { $home = $h; break; }
}
if (!$home) reply(503, ['error' => 'deploy not configured']);
$DEPLOY_TOKEN = trim((string)file_get_contents($home . '/.course-deploy-token'));
if (strlen($DEPLOY_TOKEN) < 24) reply(503, ['error' => 'deploy not configured']);
$given = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if (!is_string($given) || !hash_equals($DEPLOY_TOKEN, $given)) { usleep(400000); reply(401, ['error' => 'unauthorized']); }

const REPO = 'chepti/course';
// תיקיית מקור בריפו => [יעד בשרת, קבצים/תיקיות מוגנים יחסית למקור]
$MAP = [
    'course-progress-tracker/' => [$wp . '/wp-content/plugins/course-progress-tracker', []],
    'course-registration/'     => [$wp . '/wp-content/plugins/course-registration', []],
    'live-tips-wall/'          => [$wp . '/wp-content/plugins/live-tips-wall', []],
    'INSTINCT/site/'           => [$pub . '/instinct', ['config.php', 'data/']],
];

$sha = (string)($_POST['sha'] ?? '');
$ref = preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : 'refs/heads/master';

$lock = fopen(sys_get_temp_dir() . '/course-deploy.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) reply(409, ['error' => 'deploy already running']);

set_time_limit(120);
$tmpZip = tempnam(sys_get_temp_dir(), 'coursedeploy');
$err = null; $zip = null;
for ($try = 1; $try <= 4; $try++) {
    $ch = curl_init('https://codeload.github.com/' . REPO . '/zip/' . $ref);
    $fh = fopen($tmpZip, 'wb');
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60,
                            CURLOPT_USERAGENT => 'course-deploy', CURLOPT_FAILONERROR => true]);
    $ok = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fh);
    if ($ok && filesize($tmpZip) > 1000) { $zip = new ZipArchive(); if ($zip->open($tmpZip) === true) break; $zip = null; }
    $err = "download failed (HTTP $http)";
    sleep(3);
}
if (!$zip) { @unlink($tmpZip); reply(502, ['error' => $err ?: 'download failed']); }

$first = $zip->getNameIndex(0);
$prefix = substr($first, 0, strpos($first, '/') + 1);
if ($prefix === '' || $prefix === '/') { @unlink($tmpZip); reply(500, ['error' => 'unexpected zip layout']); }

$changed = []; $same = 0; $skipped = 0; $plan = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (substr($name, -1) === '/' || strpos($name, $prefix) !== 0) continue;
    $rel = substr($name, strlen($prefix));
    if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') continue;
    $dest = null;
    foreach ($MAP as $src => [$dir, $protect]) {
        if (strpos($rel, $src) !== 0) continue;
        $sub = substr($rel, strlen($src));
        foreach ($protect as $p) {
            if ($sub === $p || (substr($p, -1) === '/' && strpos($sub, $p) === 0)) { $skipped++; continue 3; }
        }
        $dest = $dir . '/' . $sub;
        break;
    }
    if ($dest === null) continue;           // קבצים מחוץ למפה (מסמכים, snapshot וכו') לא נפרסים
    $data = $zip->getFromIndex($i);
    if ($data === false) continue;
    if (is_file($dest) && md5_file($dest) === md5($data)) { $same++; continue; }
    $plan[] = [$rel, $dest, $data];
}

$backupDir = is_dir($home . '/backups-private') ? $home . '/backups-private' : null;
$backupFile = null;
if ($plan && $backupDir) {
    $backupFile = $backupDir . '/course-predeploy-' . date('Ymd-His') . '.zip';
    $bz = new ZipArchive();
    if ($bz->open($backupFile, ZipArchive::CREATE) === true) {
        foreach ($plan as [$rel, $dest]) if (is_file($dest)) $bz->addFile($dest, $rel);
        $bz->close();
    } else { $backupFile = null; }
    $old = glob($backupDir . '/course-predeploy-*.zip'); sort($old);
    foreach (array_slice($old, 0, max(0, count($old) - 10)) as $f) @unlink($f);
}

foreach ($plan as [$rel, $dest, $data]) {
    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) reply(500, ['error' => "mkdir failed: $rel", 'changed' => $changed]);
    $tmp = $dest . '.deploytmp';
    if (file_put_contents($tmp, $data) === false || !rename($tmp, $dest)) {
        @unlink($tmp);
        reply(500, ['error' => "write failed: $rel", 'changed' => $changed]);
    }
    $changed[] = $rel;
}
$zip->close(); @unlink($tmpZip);
if ($backupDir) @file_put_contents($backupDir . '/course-last-deploy.json', json_encode(['at' => date('c'), 'ref' => $ref, 'changed' => $changed], JSON_UNESCAPED_UNICODE));
reply(200, ['ok' => true, 'ref' => $ref, 'changed' => $changed, 'unchanged' => $same, 'skipped_protected' => $skipped,
            'backup' => $backupFile ? basename($backupFile) : null]);
