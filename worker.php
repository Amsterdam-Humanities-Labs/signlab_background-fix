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

        // Back up the TRUE original exactly once (video + its original thumbnail).
        $bp = vbf_backup_path($name);
        if ($bp !== null && !is_file($bp)) {
            if (!@copy($src, $bp)) { @unlink($out); vbf_job_update_item($jobId, $name, 'error', 'backup failed'); continue; }
            $srcJpg0 = preg_replace('/\.mp4$/i', '.jpg', $src);
            $bpJpg0  = preg_replace('/\.mp4$/i', '.jpg', $bp);
            if (is_file($srcJpg0) && !is_file($bpJpg0)) @copy($srcJpg0, $bpJpg0);
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

        // Regenerate the thumbnail from the middle of the FIXED video so the masked
        // result shows everywhere (grid, batch). Best-effort: never fails the item.
        $jpg = preg_replace('/\.mp4$/i', '.jpg', $src);
        $thumbTmp = dirname($src) . '/.vbf_thumb_' . bin2hex(random_bytes(6)) . '.jpg';
        [$tok] = vbf_extract_thumb($src, $thumbTmp);
        if ($tok) { if (!@rename($thumbTmp, $jpg)) @unlink($thumbTmp); }
        else { @unlink($thumbTmp); }

        vbf_job_update_item($jobId, $name, 'done', null);
    }
}

// CLI entry: `php worker.php <jobId>`
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    vbf_worker_run($argv[1]);
}
