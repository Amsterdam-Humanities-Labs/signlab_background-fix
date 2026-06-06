<?php
require_once __DIR__ . '/../lib/paths.php';
header('Content-Type: application/json');

// List all persisted batch jobs (summary only) so the queue/history survives re-login.
$out = [];
foreach (glob(vbf_jobs_dir() . '/*.json') as $path) {
    $j = json_decode(@file_get_contents($path), true);
    if (!is_array($j) || !isset($j['items']) || !is_array($j['items'])) continue;

    $counts = ['queued' => 0, 'processing' => 0, 'done' => 0, 'error' => 0, 'cancelled' => 0];
    foreach ($j['items'] as $it) {
        $s = $it['status'] ?? 'queued';
        $counts[$s] = ($counts[$s] ?? 0) + 1;
    }
    $total = count($j['items']);
    $finished = $counts['done'] + $counts['error'] + $counts['cancelled'];
    $cancelled = !empty($j['cancelled']);

    $out[] = [
        'id'        => $j['id'] ?? basename($path, '.json'),
        'date'      => $j['date'] ?? '',
        'created'   => $j['created'] ?? '',
        'total'     => $total,
        'finished'  => $finished,
        'counts'    => $counts,
        'cancelled' => $cancelled,
        'complete'  => ($finished >= $total) || $cancelled,
    ];
}

// Newest first; cap to the most recent 50 to keep the payload small.
usort($out, fn($a, $b) => strcmp($b['created'], $a['created']));
$out = array_slice($out, 0, 50);

echo json_encode(['jobs' => $out]);
