<?php
require_once __DIR__ . '/../lib/ffmpeg.php';
group('ffmpeg');

// Color conversion
eq(vbf_hex_to_ffmpeg('#0000FF'), '0x0000FF', 'hash hex to ffmpeg');
eq(vbf_hex_to_ffmpeg('00ff00'),  '0x00FF00', 'bare hex uppercased');
eq(vbf_hex_to_ffmpeg('#0000FG'), null,        'rejects non-hex');
eq(vbf_hex_to_ffmpeg('#fff'),    null,        'rejects 3-digit hex');

// Drawbox chain: normalized boxes -> pixel filter string (1440x1252 frame)
$boxes = [
    ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 0.25],
    ['x' => 0.5, 'y' => 0.5, 'w' => 0.5, 'h' => 0.5],
];
eq(
    vbf_build_drawbox($boxes, 1440, 1252, '0x0000FF'),
    'drawbox=x=0:y=0:w=720:h=313:color=0x0000FF@1.0:t=fill,'
  . 'drawbox=x=720:y=626:w=720:h=626:color=0x0000FF@1.0:t=fill',
    'builds two-box chain with rounded pixels'
);
eq(vbf_build_drawbox([], 1440, 1252, '0x0000FF'), '', 'empty boxes -> empty chain');

// Probe real fixture dimensions
$dims = vbf_probe_dims(__DIR__ . '/fixtures/R00000000_1.mp4');
eq($dims, ['w' => 1440, 'h' => 1252], 'probes fixture dimensions');

// needs-norm: 1.15:1 is fine, other ratios need widening
eq(vbf_needs_norm(['w' => 1440, 'h' => 1252]), false, '1.15 ratio needs no widen');
eq(vbf_needs_norm(['w' => 1000, 'h' => 1000]), true,  'square needs widen');
eq(vbf_target_w(1000), 1150, 'target width for h=1000 is 1150');

// widen a 1000x1000 clip -> 1.15:1, height unchanged
$sq = sys_get_temp_dir() . '/vbf_sq_' . bin2hex(random_bytes(3)) . '.mp4';
$out = sys_get_temp_dir() . '/vbf_sqo_' . bin2hex(random_bytes(3)) . '.mp4';
exec('ffmpeg -hide_banner -loglevel error -y -f lavfi -i color=c=0x316CA4:s=1000x1000:d=0.3:r=25 '
   . '-c:v libx264 -pix_fmt yuv420p ' . escapeshellarg($sq));
[$wok, $werr] = vbf_process_video($sq, $out, [['x' => 0, 'y' => 0, 'w' => 0.1, 'h' => 0.1]], '0x316CA4');
ok($wok, 'widen render ok' . ($wok ? '' : ": $werr"));
$wd = vbf_probe_dims($out);
eq($wd['h'], 1000, 'widen keeps height (no scaling)');
ok($wd && abs($wd['w'] / $wd['h'] - 1.15) < 0.01, 'widened to 1.15 ratio (got ' . ($wd ? "{$wd['w']}x{$wd['h']}" : 'null') . ')');
@unlink($sq); @unlink($out);
