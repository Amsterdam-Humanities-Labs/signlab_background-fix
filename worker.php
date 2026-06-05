<?php
require_once __DIR__ . '/lib/paths.php';
require_once __DIR__ . '/lib/ffmpeg.php';
require_once __DIR__ . '/lib/jobs.php';

// Process every queued item in a job. Safe to call in-process (tests) or via CLI.
function vbf_worker_run(string $jobId): void {
    $job = vbf_job_read($jobId);
    if ($job === null) return;
    $ff = vbf_hex_to_ffmpeg($job['color']) ?? '0x316CA4';  // measured studio background
    vbf_ensure_dir(vbf_tmp_dir());
    vbf_ensure_dir(vbf_backup_dir());

    foreach ($job['items'] as $item) {
        $name = $item['filename'];
        if (!in_array($item['status'], ['queued', 'error'], true)) continue;
        vbf_job_update_item($jobId, $name, 'processing', null);

        $src = vbf_post_path($name);
        if ($src === null) { vbf_job_update_item($jobId, $name, 'error', 'source missing'); continue; }

        $out = vbf_tmp_dir() . '/out_' . bin2hex(random_bytes(6)) . '.mp4';
        [$ok, $err] = vbf_render($src, $out, $job['boxes'], $ff);
        if (!$ok) { @unlink($out); vbf_job_update_item($jobId, $name, 'error', $err); continue; }

        // Back up the TRUE original exactly once.
        $bp = vbf_backup_path($name);
        if ($bp !== null && !is_file($bp)) {
            if (!@copy($src, $bp)) { @unlink($out); vbf_job_update_item($jobId, $name, 'error', 'backup failed'); continue; }
        }

        // Replace the live file atomically. tmp/ and post/ are on different filesystems
        // here, so a direct rename() raises EXDEV; in that case copy into a sibling temp
        // *inside* post/ and rename within the same filesystem (atomic, never exposes a
        // half-written file to a concurrent Apache read).
        if (!@rename($out, $src)) {
            $sibling = dirname($src) . '/.vbf_tmp_' . bin2hex(random_bytes(6)) . '.mp4';
            if (@copy($out, $sibling) && @rename($sibling, $src)) {
                @unlink($out);
            } else {
                @unlink($sibling); @unlink($out);
                vbf_job_update_item($jobId, $name, 'error', 'replace failed'); continue;
            }
        }

        vbf_job_update_item($jobId, $name, 'done', null);
    }
}

// CLI entry: `php worker.php <jobId>`
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    vbf_worker_run($argv[1]);
}
