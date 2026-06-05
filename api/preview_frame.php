<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/ffmpeg.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['error'=>'invalid JSON body']); exit; }

$src = vbf_post_path($in['filename'] ?? '');
if ($src === null) { http_response_code(400); echo json_encode(['error'=>'invalid or missing filename']); exit; }

$ff = vbf_hex_to_ffmpeg($in['color'] ?? '#316CA4');
if ($ff === null) { http_response_code(400); echo json_encode(['error'=>'invalid color']); exit; }

$boxes = $in['boxes'] ?? [];
if (!is_array($boxes) || count($boxes) === 0) { http_response_code(400); echo json_encode(['error'=>'no boxes']); exit; }

$at = isset($in['at']) ? (float) $in['at'] : 0.0;
vbf_ensure_dir(vbf_tmp_dir());
$out = vbf_tmp_dir() . '/frame_' . bin2hex(random_bytes(6)) . '.png';

[$ok, $err] = vbf_render_frame($src, $out, $boxes, $ff, $at);
if (!$ok) { http_response_code(500); echo json_encode(['error'=>'render failed','detail'=>$err]); exit; }

echo json_encode(['url' => 'tmp/' . basename($out)]);
