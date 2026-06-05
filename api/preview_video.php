<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/ffmpeg.php';
header('Content-Type: application/json');
set_time_limit(300);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['error'=>'invalid JSON body']); exit; }

$src = vbf_post_path($in['filename'] ?? '');
if ($src === null) { http_response_code(400); echo json_encode(['error'=>'invalid or missing filename']); exit; }

$ff = vbf_hex_to_ffmpeg($in['color'] ?? '#316CA4');
if ($ff === null) { http_response_code(400); echo json_encode(['error'=>'invalid color']); exit; }

$boxes = $in['boxes'] ?? [];
if (!is_array($boxes) || count($boxes) === 0) { http_response_code(400); echo json_encode(['error'=>'no boxes']); exit; }

vbf_ensure_dir(vbf_tmp_dir());
$out = vbf_tmp_dir() . '/preview_' . bin2hex(random_bytes(6)) . '.mp4';

[$ok, $err] = vbf_render($src, $out, $boxes, $ff);
if (!$ok) { http_response_code(500); echo json_encode(['error'=>'render failed','detail'=>$err]); exit; }

echo json_encode(['url' => 'tmp/' . basename($out)]);
