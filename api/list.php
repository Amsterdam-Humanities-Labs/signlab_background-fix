<?php
require_once __DIR__ . '/../lib/paths.php';
header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{8}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid date (expected YYYYMMDD)']);
    exit;
}

$url = vbf_api_base() . '?date=' . urlencode($date);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream API failed', 'status' => $httpCode]);
    exit;
}

$records = json_decode($body, true);
if (!is_array($records)) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream returned non-JSON']);
    exit;
}

// Annotate each file: camera letter, local availability, already-fixed flag.
foreach ($records as &$rec) {
    if (!isset($rec['files'])) continue;
    foreach ($rec['files'] as &$f) {
        $name = $f['filename'] ?? '';
        $f['camera']        = ($name !== '' && vbf_valid_filename($name)) ? $name[0] : null;
        $f['local']         = vbf_post_path($name) !== null;
        $bp = vbf_backup_path($name);
        $f['already_fixed'] = $bp !== null && is_file($bp);
        // URL the browser can load for drawing/preview (same host).
        $f['view_url']      = $f['post'] ?? null;
    }
    unset($f);
}
unset($rec);

echo json_encode(['date' => $date, 'records' => $records]);
