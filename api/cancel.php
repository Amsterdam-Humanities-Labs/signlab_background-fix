<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/jobs.php';
header('Content-Type: application/json');

$in  = json_decode(file_get_contents('php://input'), true);
$id  = is_array($in) ? ($in['job'] ?? '') : ($_GET['job'] ?? '');
$job = vbf_job_read($id);
if ($job === null) { http_response_code(404); echo json_encode(['error' => 'job not found']); exit; }

// 1) Flag cancelled so the worker stops at its next iteration.
$job['cancelled'] = true;
vbf_job_write($job);

// 2) Kill the worker process group if it's still alive (worker + its ffmpeg child).
//    cancel.php runs as the same user (www-data) that spawned the worker, so no sudo.
$pid = (int) ($job['pid'] ?? 0);
if ($pid > 1) {
    @exec('kill -TERM -' . $pid . ' 2>/dev/null');   // whole group (setsid -> pgid==pid)
    @exec('kill -KILL -' . $pid . ' 2>/dev/null');
    @exec('kill -KILL ' . $pid . ' 2>/dev/null');
}

// 3) Mark anything not finished as cancelled so the queue is terminal (no stuck "processing").
$job = vbf_job_read($id);
foreach ($job['items'] as &$it) {
    if (in_array($it['status'], ['queued', 'processing'], true)) { $it['status'] = 'cancelled'; $it['error'] = null; }
}
unset($it);
$job['cancelled'] = true;
vbf_job_write($job);

$done = 0; foreach ($job['items'] as $i) if ($i['status'] === 'done') $done++;
echo json_encode(['ok' => true, 'done' => $done, 'total' => count($job['items'])]);
