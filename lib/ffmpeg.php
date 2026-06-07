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

// ---- Widen to 1.15:1 (shared by worker + preview) ----
// Each clip is padded LEFT/RIGHT ONLY with studio blue until W:H = 1.15, with the
// person horizontally centred. Vertical is untouched (no top/bottom change, no scaling).
// Left/right black edge borders are trimmed first (so they don't show or skew centring).
const VBF_TARGET_RATIO = 1.15;

// Even target width for a given height.
function vbf_target_w(int $h): int { $w = (int) round(VBF_TARGET_RATIO * $h); return $w + ($w % 2); }

// True if the clip's aspect ratio isn't already ~1.15:1 (needs widening).
function vbf_needs_norm(array $dims): bool {
    return abs($dims['w'] / $dims['h'] - VBF_TARGET_RATIO) > 0.005;
}

// Run lib/detect_center.py -> ['crop_x','crop_w','cx'] (cropped coords) or null.
function vbf_detect_center(string $src): ?array {
    [$code, $out] = vbf_exec(['python3', __DIR__ . '/detect_center.py', $src]);
    if ($code !== 0) return null;
    $i = json_decode(trim($out), true);
    if (!is_array($i) || empty($i['ok'])) return null;
    return ['crop_x' => (int) $i['crop_x'], 'crop_w' => (int) $i['crop_w'], 'cx' => (float) $i['cx']];
}

// Plan the widen: left/right crop, target width, and left pad to centre the person.
function vbf_widen_plan(string $src, array $dims): array {
    $d = vbf_detect_center($src);
    $cropX = $d ? $d['crop_x'] : 0;
    $cropW = $d ? $d['crop_w'] : $dims['w'];
    $cx    = $d ? $d['cx'] : $cropW / 2;
    $tw = max(vbf_target_w($dims['h']), $cropW + ($cropW % 2));   // never shrink below content
    $left = (int) round($tw / 2 - $cx);
    $left = max(0, min($left, $tw - $cropW));                     // keep content on-canvas
    return ['crop_x' => $cropX, 'crop_w' => $cropW, 'target_w' => $tw, 'left' => $left];
}

// filter_complex: optional mask (orig coords) -> crop L/R -> overlay on blue canvas.
function vbf_widen_fc(string $src, array $boxes, string $ffColor, int $h, array $p,
                      string $canvasHex, ?string $fps): string {
    $dims = vbf_probe_dims($src);
    $chain = $dims ? vbf_build_drawbox($boxes, $dims['w'], $dims['h'], $ffColor) : '';
    $cropf = "crop={$p['crop_w']}:{$h}:{$p['crop_x']}:0";
    $pre = $chain === '' ? $cropf : "{$chain},{$cropf}";
    $rate = $fps ? ":r={$fps}" : '';
    return "color=c={$canvasHex}:s={$p['target_w']}x{$h}{$rate}[bg];[0:v]{$pre}[fg];"
         . "[bg][fg]overlay=x={$p['left']}:y=0:shortest=1[v]";
}

function vbf_render_widen(string $src, string $dst, array $boxes, string $ffColor,
                         int $h, array $p, string $canvasHex): array {
    $fps = vbf_probe_fps($src) ?: '25';
    $fc = vbf_widen_fc($src, $boxes, $ffColor, $h, $p, $canvasHex, $fps);
    $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $src,
             '-filter_complex', $fc, '-map', '[v]', '-map', '0:a?',
             '-c:v', 'libx264', '-crf', '18', '-preset', 'veryfast',
             '-pix_fmt', 'yuv420p', '-c:a', 'copy', '-movflags', '+faststart', $dst];
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) return [false, substr(trim($err), -2000)];
    return [true, ''];
}

function vbf_render_widen_frame(string $src, string $dst, array $boxes, string $ffColor,
                               int $h, array $p, string $canvasHex, float $at = 0.0): array {
    $fc = vbf_widen_fc($src, $boxes, $ffColor, $h, $p, $canvasHex, null);
    $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-ss', (string) $at, '-i', $src,
             '-filter_complex', $fc, '-map', '[v]', '-frames:v', '1', $dst];
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) return [false, substr(trim($err), -2000)];
    return [true, ''];
}

// Unified video fix = mask + widen-to-1.15-if-needed. Used by worker AND preview.
function vbf_process_video(string $src, string $dst, array $boxes, string $ffColor): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    if (vbf_needs_norm($dims)) {
        $p = vbf_widen_plan($src, $dims);
        return vbf_render_widen($src, $dst, $boxes, $ffColor, $dims['h'], $p, $ffColor);
    }
    return vbf_render($src, $dst, $boxes, $ffColor);
}

// Unified single-frame fix = mask + widen-to-1.15-if-needed. Used by frame preview.
function vbf_process_frame(string $src, string $dst, array $boxes, string $ffColor, float $at = 0.0): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    if (vbf_needs_norm($dims)) {
        $p = vbf_widen_plan($src, $dims);
        return vbf_render_widen_frame($src, $dst, $boxes, $ffColor, $dims['h'], $p, $ffColor, $at);
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
