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
