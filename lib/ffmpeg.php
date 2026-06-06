<?php
// ffmpeg/ffprobe helpers: color conversion, drawbox filter chain, probing, and command execution.

function vbf_hex_to_ffmpeg(string $hex): ?string {
    if (!preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) return null;
    return '0x' . strtoupper($m[1]);
}

// $boxes: list of ['x','y','w','h'] normalized 0..1. Returns a comma-joined drawbox chain (or '').
function vbf_build_drawbox(array $boxes, int $w, int $h, string $color): string {
    $parts = [];
    foreach ($boxes as $b) {
        $x  = (int) round($b['x'] * $w);
        $y  = (int) round($b['y'] * $h);
        $bw = (int) round($b['w'] * $w);
        $bh = (int) round($b['h'] * $h);
        $parts[] = "drawbox=x=$x:y=$y:w=$bw:h=$bh:color={$color}@1.0:t=fill";
    }
    return implode(',', $parts);
}

// Returns the video's r_frame_rate as an ffmpeg-compatible string (e.g. "2997/50"),
// or null on failure. Used to keep the normalize canvas at the source frame rate.
function vbf_probe_fps(string $path): ?string {
    [$code, $out] = vbf_exec(['ffprobe', '-v', 'error', '-select_streams', 'v:0',
                              '-show_entries', 'stream=r_frame_rate', '-of', 'csv=p=0', $path]);
    if ($code !== 0) return null;
    $r = trim($out);
    return ($r === '' || $r === '0/0') ? null : $r;
}

// Returns ['w'=>int,'h'=>int] or null on failure.
function vbf_probe_dims(string $path): ?array {
    $cmd = ['ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $path];
    [$code, $out] = vbf_exec($cmd);
    if ($code !== 0) return null;
    $parts = explode(',', trim($out));
    if (count($parts) < 2) return null;
    return ['w' => (int) $parts[0], 'h' => (int) $parts[1]];
}

// Execute a command given as an argv array (no shell). Returns [exitCode, stdout, stderr].
function vbf_exec(array $argv): array {
    $cmd = implode(' ', array_map('escapeshellarg', $argv));
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) return [1, '', 'proc_open failed'];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $out, $err];
}

// Render $src to $dst applying $boxes/$color for the WHOLE duration.
// Returns [true,''] on success or [false, stderrTail] on failure. Does not move files.
function vbf_render(string $src, string $dst, array $boxes, string $ffColor): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    $chain = vbf_build_drawbox($boxes, $dims['w'], $dims['h'], $ffColor);  // '' when no boxes
    $vf = $chain === '' ? [] : ['-vf', $chain];
    $argv = array_merge(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $src], $vf,
             ['-c:v', 'libx264', '-crf', '18', '-preset', 'veryfast',
             '-pix_fmt', 'yuv420p', '-c:a', 'copy', '-movflags', '+faststart', $dst]);
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) return [false, substr(trim($err), -2000)];
    return [true, ''];
}

// Render a single frame (at $atSeconds) with boxes to a PNG $dst. Returns [bool,$err].
function vbf_render_frame(string $src, string $dst, array $boxes, string $ffColor, float $atSeconds = 0.0): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    $chain = vbf_build_drawbox($boxes, $dims['w'], $dims['h'], $ffColor);  // '' when no boxes
    $vf = $chain === '' ? [] : ['-vf', $chain];
    $argv = array_merge(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
             '-ss', (string) $atSeconds, '-i', $src], $vf, ['-frames:v', '1', $dst]);
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) return [false, substr(trim($err), -2000)];
    return [true, ''];
}

// Build the filter_complex that masks (optional), crops black borders, and composites
// the source onto a $tw x $th canvas of $canvasHex at ($offx,$offy). $crop is the
// content rectangle in ORIGINAL pixels (drawbox runs first, in original coords).
function vbf_canvas_fc(string $src, array $boxes, string $ffColor, int $tw, int $th,
                       array $crop, int $offx, int $offy, string $canvasHex, ?string $fps): string {
    $dims = vbf_probe_dims($src);
    $chain = $dims ? vbf_build_drawbox($boxes, $dims['w'], $dims['h'], $ffColor) : '';
    $cropf = "crop={$crop['w']}:{$crop['h']}:{$crop['x']}:{$crop['y']}";
    $pre = $chain === '' ? $cropf : "{$chain},{$cropf}";
    $rate = $fps ? ":r={$fps}" : '';
    return "color=c={$canvasHex}:s={$tw}x{$th}{$rate}[bg];[0:v]{$pre}[fg];"
         . "[bg][fg]overlay=x={$offx}:y={$offy}:shortest=1[v]";
}

// Composite a (masked, border-cropped) source onto the canvas. Returns [bool, err].
function vbf_render_canvas(string $src, string $dst, array $boxes, string $ffColor,
                          int $tw, int $th, array $crop, int $offx, int $offy, string $canvasHex): array {
    if (vbf_probe_dims($src) === null) return [false, "probe failed for $src"];
    $fps = vbf_probe_fps($src) ?: '25';   // keep canvas at source frame rate (else overlay forces 25)
    $fc = vbf_canvas_fc($src, $boxes, $ffColor, $tw, $th, $crop, $offx, $offy, $canvasHex, $fps);
    $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $src,
             '-filter_complex', $fc, '-map', '[v]', '-map', '0:a?',
             '-c:v', 'libx264', '-crf', '18', '-preset', 'veryfast',
             '-pix_fmt', 'yuv420p', '-c:a', 'copy', '-movflags', '+faststart', $dst];
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) return [false, substr(trim($err), -2000)];
    return [true, ''];
}

// Single-frame variant (outputs one frame, e.g. a PNG).
function vbf_render_canvas_frame(string $src, string $dst, array $boxes, string $ffColor,
                                int $tw, int $th, array $crop, int $offx, int $offy,
                                string $canvasHex, float $at = 0.0): array {
    if (vbf_probe_dims($src) === null) return [false, "probe failed for $src"];
    $fc = vbf_canvas_fc($src, $boxes, $ffColor, $tw, $th, $crop, $offx, $offy, $canvasHex, null);
    $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-ss', (string) $at, '-i', $src,
             '-filter_complex', $fc, '-map', '[v]', '-frames:v', '1', $dst];
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) return [false, substr(trim($err), -2000)];
    return [true, ''];
}

// ---- Canonical canvas / normalization (shared by worker + preview) ----
// Matches reference clip M20241209_9819 (1764x1534). Off-spec clips are border-cropped
// (removes black auto-crop wedges) then composited with the person centred and the
// eyes anchored VBF_TARGET_EYE_Y px from the top.
const VBF_TARGET_W = 1764;
const VBF_TARGET_H = 1534;
const VBF_TARGET_HEAD_TOP = 200;   // desired margin between top of head and top of frame

function vbf_needs_norm(array $dims): bool {
    return $dims['w'] != VBF_TARGET_W || $dims['h'] != VBF_TARGET_H;
}

// Run lib/detect_center.py -> ['cx'=>float,'head_top'=>float,'crop'=>[x,y,w,h]] or null.
// cx/head_top are in CROPPED coordinates.
function vbf_detect_center(string $src): ?array {
    [$code, $out] = vbf_exec(['python3', __DIR__ . '/detect_center.py', $src]);
    if ($code !== 0) return null;
    $i = json_decode(trim($out), true);
    if (!is_array($i) || empty($i['ok']) || !isset($i['crop'])) return null;
    return ['cx' => (float) $i['cx'], 'head_top' => (float) $i['head_top'], 'crop' => $i['crop']];
}

// Plan: crop rectangle + overlay offsets (centre person, anchor top-of-head). Falls
// back to no-crop plain centring if detection fails.
function vbf_norm_plan(string $src, array $dims): array {
    $d = vbf_detect_center($src);
    if ($d !== null) {
        return ['crop' => $d['crop'],
                'offx' => (int) round(VBF_TARGET_W / 2 - $d['cx']),
                'offy' => (int) round(VBF_TARGET_HEAD_TOP - $d['head_top'])];
    }
    return ['crop' => ['x' => 0, 'y' => 0, 'w' => $dims['w'], 'h' => $dims['h']],
            'offx' => (int) round((VBF_TARGET_W - $dims['w']) / 2),
            'offy' => (int) round((VBF_TARGET_H - $dims['h']) / 2)];
}

// Unified video fix = mask + normalize-if-off-spec. Used by worker AND preview.
function vbf_process_video(string $src, string $dst, array $boxes, string $ffColor): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    if (vbf_needs_norm($dims)) {
        $p = vbf_norm_plan($src, $dims);
        return vbf_render_canvas($src, $dst, $boxes, $ffColor,
                   VBF_TARGET_W, VBF_TARGET_H, $p['crop'], $p['offx'], $p['offy'], $ffColor);
    }
    return vbf_render($src, $dst, $boxes, $ffColor);
}

// Unified single-frame fix = mask + normalize-if-off-spec. Used by frame preview.
function vbf_process_frame(string $src, string $dst, array $boxes, string $ffColor, float $at = 0.0): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    if (vbf_needs_norm($dims)) {
        $p = vbf_norm_plan($src, $dims);
        return vbf_render_canvas_frame($src, $dst, $boxes, $ffColor,
                   VBF_TARGET_W, VBF_TARGET_H, $p['crop'], $p['offx'], $p['offy'], $ffColor, $at);
    }
    return vbf_render_frame($src, $dst, $boxes, $ffColor, $at);
}

// Extract a representative thumbnail (middle frame) from $src to a JPG $dst.
// Falls back to the first frame if the midpoint seek fails. Returns [bool, err].
function vbf_extract_thumb(string $src, string $dst): array {
    $mid = 0.0;
    [$c, $o] = vbf_exec(['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
                         '-of', 'csv=p=0', $src]);
    if ($c === 0) { $d = (float) trim($o); if ($d > 0) $mid = $d / 2.0; }

    $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
             '-ss', (string) $mid, '-i', $src, '-frames:v', '1', '-q:v', '3', $dst];
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) { // fallback: first frame
        $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
                 '-i', $src, '-frames:v', '1', '-q:v', '3', $dst];
        [$code, , $err] = vbf_exec($argv);
    }
    return $code === 0 ? [true, ''] : [false, substr(trim($err), -2000)];
}
