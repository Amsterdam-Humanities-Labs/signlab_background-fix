<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/jobs.php';
header('Content-Type: application/json');

$id = $_GET['job'] ?? '';
$job = vbf_job_read($id);
if ($job === null) { http_response_code(404); echo json_encode(['error'=>'job not found']); exit; }

$counts = ['queued'=>0,'processing'=>0,'done'=>0,'error'=>0];
foreach ($job['items'] as $it) { $counts[$it['status']] = ($counts[$it['status']] ?? 0) + 1; }
$total = count($job['items']);
$finished = $counts['done'] + $counts['error'];

echo json_encode([
    'id' => $job['id'], 'total' => $total, 'finished' => $finished,
    'counts' => $counts, 'complete' => $finished >= $total, 'items' => $job['items'],
]);
