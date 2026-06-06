<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/jobs.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['error'=>'invalid JSON body']); exit; }

$date = $in['date'] ?? '';
if (!preg_match('/^\d{8}$/', $date)) { http_response_code(400); echo json_encode(['error'=>'invalid date']); exit; }

$color = $in['color'] ?? '#316CA4';  // measured studio background
if (!preg_match('/^#?[0-9a-fA-F]{6}$/', $color)) { http_response_code(400); echo json_encode(['error'=>'invalid color']); exit; }

$boxes = $in['boxes'] ?? [];
if (!is_array($boxes) || count($boxes) === 0) { http_response_code(400); echo json_encode(['error'=>'no boxes']); exit; }

$names = $in['filenames'] ?? [];
if (!is_array($names) || count($names) === 0) { http_response_code(400); echo json_encode(['error'=>'no files selected']); exit; }
foreach ($names as $n) {
    if (vbf_post_path($n) === null) { http_response_code(400); echo json_encode(['error'=>"invalid or missing file: $n"]); exit; }
}

$job = vbf_job_create($date, $color, $boxes, $names);

// Detach the worker so the HTTP request returns immediately.
// Use php CLI; PHP_BINARY under php-fpm points to the fpm binary, so prefer /usr/bin/php.
$php    = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
// Every interpolated component below is escapeshellarg()'d. The job id is additionally
// constrained to /^[0-9A-Za-z-]+$/ by vbf_job_create, so $jobId and the $log path built
// from it contain no shell metacharacters. The bare >, 2>&1, & are intentional shell syntax.
$worker = escapeshellarg(__DIR__ . '/../worker.php');
$jobId  = escapeshellarg($job['id']);
$log    = escapeshellarg(vbf_jobs_dir() . '/' . $job['id'] . '.log');
// setsid fully detaches the worker into its own session so it keeps running after the
// HTTP request ends and after the browser/tab is closed (survives php-fpm recycling).
$setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
exec($setsid . escapeshellarg($php) . " $worker $jobId > $log 2>&1 &");

echo json_encode(['job' => $job['id'], 'count' => count($names)]);
