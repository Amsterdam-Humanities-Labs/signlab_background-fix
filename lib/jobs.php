<?php
require_once __DIR__ . '/paths.php';

// Create and persist a new batch job. Returns the job array.
function vbf_job_create(string $date, string $color, array $boxes, array $filenames): array {
    vbf_ensure_dir(vbf_jobs_dir());
    $id = $date . '-' . date('His') . '-' . bin2hex(random_bytes(2));
    $items = [];
    foreach ($filenames as $name) {
        $items[] = ['filename' => $name, 'status' => 'queued', 'error' => null];
    }
    $job = [
        'id'      => $id,
        'date'    => $date,
        'color'   => $color,
        'boxes'   => $boxes,
        'created' => date('c'),
        'items'   => $items,
    ];
    vbf_job_write($job);
    return $job;
}

function vbf_job_path(string $id): ?string {
    if (!preg_match('/^[0-9A-Za-z-]+$/', $id)) return null; // no traversal
    return vbf_jobs_dir() . '/' . $id . '.json';
}

function vbf_job_write(array $job): void {
    $path = vbf_job_path($job['id']);
    $tmp  = $path . '.tmp';
    file_put_contents($tmp, json_encode($job, JSON_PRETTY_PRINT));
    rename($tmp, $path); // atomic
}

function vbf_job_read(string $id): ?array {
    $path = vbf_job_path($id);
    if ($path === null || !is_file($path)) return null;
    $j = json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

function vbf_job_update_item(string $id, string $filename, string $status, ?string $error): void {
    $job = vbf_job_read($id);
    if ($job === null) return;
    foreach ($job['items'] as &$it) {
        if ($it['filename'] === $filename) { $it['status'] = $status; $it['error'] = $error; }
    }
    unset($it);
    vbf_job_write($job);
}
