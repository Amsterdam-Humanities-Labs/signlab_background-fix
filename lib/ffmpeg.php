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

// Composite $src (optionally masked with $boxes) onto a $tw x $th canvas filled with
// $canvasHex, placed at ($offx,$offy) — pads where the source is smaller and crops
// where larger (overlay accepts negative offsets). Used to canonicalise dimensions
// while keeping the person positioned. Returns [bool, err].
function vbf_render_canvas(string $src, string $dst, array $boxes, string $ffColor,
                          int $tw, int $th, int $offx, int $offy, string $canvasHex): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    $chain = vbf_build_drawbox($boxes, $dims['w'], $dims['h'], $ffColor);
    $fg = $chain === '' ? '[0:v]copy[fg]' : "[0:v]{$chain}[fg]";
    $fc = "color=c={$canvasHex}:s={$tw}x{$th}[bg];{$fg};[bg][fg]overlay=x={$offx}:y={$offy}:shortest=1[v]";
    $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $src,
             '-filter_complex', $fc, '-map', '[v]', '-map', '0:a?',
             '-c:v', 'libx264', '-crf', '18', '-preset', 'veryfast',
             '-pix_fmt', 'yuv420p', '-c:a', 'copy', '-movflags', '+faststart', $dst];
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) return [false, substr(trim($err), -2000)];
    return [true, ''];
}

// ---- Canonical canvas / normalization (shared by worker + preview) ----
// Matches reference clip M20241209_9819. Off-spec clips are composited onto this
// size with the person centred and head anchored.
const VBF_TARGET_W = 1764;
const VBF_TARGET_H = 1534;
const VBF_TARGET_HEAD_TOP = 125;   // target y of head-top (≈8.15% of height, from reference)

function vbf_needs_norm(array $dims): bool {
    return $dims['w'] != VBF_TARGET_W || $dims['h'] != VBF_TARGET_H;
}

// Detect person centre + head-top (source px) via lib/detect_center.py.
// Returns ['cx'=>float,'head_top'=>float] or null on failure.
function vbf_detect_center(string $src): ?array {
    [$code, $out] = vbf_exec(['python3', __DIR__ . '/detect_center.py', $src]);
    if ($code !== 0) return null;
    $info = json_decode(trim($out), true);
    if (!is_array($info) || empty($info['ok'])) return null;
    return ['cx' => (float) $info['cx'], 'head_top' => (float) $info['head_top']];
}

// Overlay offsets that centre the person horizontally and anchor the head; falls
// back to plain centring if detection fails.
function vbf_norm_offsets(string $src, array $dims): array {
    $c = vbf_detect_center($src);
    if ($c !== null) {
        return [(int) round(VBF_TARGET_W / 2 - $c['cx']),
                (int) round(VBF_TARGET_HEAD_TOP - $c['head_top'])];
    }
    return [(int) round((VBF_TARGET_W - $dims['w']) / 2),
            (int) round((VBF_TARGET_H - $dims['h']) / 2)];
}

// Single-frame variant of vbf_render_canvas (outputs one frame, e.g. a PNG).
function vbf_render_canvas_frame(string $src, string $dst, array $boxes, string $ffColor,
                                int $tw, int $th, int $offx, int $offy, string $canvasHex,
                                float $at = 0.0): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    $chain = vbf_build_drawbox($boxes, $dims['w'], $dims['h'], $ffColor);
    $fg = $chain === '' ? '[0:v]copy[fg]' : "[0:v]{$chain}[fg]";
    $fc = "color=c={$canvasHex}:s={$tw}x{$th}[bg];{$fg};[bg][fg]overlay=x={$offx}:y={$offy}[v]";
    $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-ss', (string) $at, '-i', $src,
             '-filter_complex', $fc, '-map', '[v]', '-frames:v', '1', $dst];
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) return [false, substr(trim($err), -2000)];
    return [true, ''];
}

// Unified video fix = mask + normalize-if-off-spec. Used by worker AND preview.
function vbf_process_video(string $src, string $dst, array $boxes, string $ffColor): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    if (vbf_needs_norm($dims)) {
        [$ox, $oy] = vbf_norm_offsets($src, $dims);
        return vbf_render_canvas($src, $dst, $boxes, $ffColor,
                   VBF_TARGET_W, VBF_TARGET_H, $ox, $oy, $ffColor);
    }
    return vbf_render($src, $dst, $boxes, $ffColor);
}

// Unified single-frame fix = mask + normalize-if-off-spec. Used by frame preview.
function vbf_process_frame(string $src, string $dst, array $boxes, string $ffColor, float $at = 0.0): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    if (vbf_needs_norm($dims)) {
        [$ox, $oy] = vbf_norm_offsets($src, $dims);
        return vbf_render_canvas_frame($src, $dst, $boxes, $ffColor,
                   VBF_TARGET_W, VBF_TARGET_H, $ox, $oy, $ffColor, $at);
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
