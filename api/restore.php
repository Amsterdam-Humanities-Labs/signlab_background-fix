<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/ffmpeg.php';
header('Content-Type: application/json');
set_time_limit(0);

// Restore one or more files to their pristine originals from post_backup/, then
// drop the backup so they are no longer flagged as fixed. Video + thumbnail.
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['error' => 'invalid JSON body']); exit; }

$names = $in['filenames'] ?? [];
if (!is_array($names) || count($names) === 0) { http_response_code(400); echo json_encode(['error' => 'no files']); exit; }

$results = [];
foreach ($names as $name) {
    if (!vbf_valid_filename($name)) { $results[$name] = ['ok' => false, 'error' => 'invalid name']; continue; }

    $post = vbf_post_dir() . '/' . $name;
    $bmp4 = vbf_backup_path($name);
    if ($bmp4 === null || !is_file($bmp4)) { $results[$name] = ['ok' => false, 'error' => 'no backup']; continue; }

    // Restore the video atomically (copy backup -> sibling in post/ -> rename over live).
    $sib = vbf_post_dir() . '/.vbf_rst_' . bin2hex(random_bytes(5)) . '.mp4';
    if (!@copy($bmp4, $sib) || !@rename($sib, $post)) {
        @unlink($sib);
        $results[$name] = ['ok' => false, 'error' => 'restore failed'];
        continue;
    }

    // Restore the thumbnail: from backup if present, else regenerate from the restored video.
    $jpg  = preg_replace('/\.mp4$/i', '.jpg', $post);
    $bjpg = preg_replace('/\.mp4$/i', '.jpg', $bmp4);
    $sj   = vbf_post_dir() . '/.vbf_rst_' . bin2hex(random_bytes(5)) . '.jpg';
    if (is_file($bjpg)) {
        if (@copy($bjpg, $sj)) { @rename($sj, $jpg); } else { @unlink($sj); }
    } else {
        [$ok] = vbf_extract_thumb($post, $sj);
        if ($ok) { @rename($sj, $jpg); } else { @unlink($sj); }
    }

    // Drop the backups so the file is no longer "fixed".
    @unlink($bmp4);
    if (is_file($bjpg)) @unlink($bjpg);

    $results[$name] = ['ok' => true];
}

echo json_encode(['results' => $results]);
