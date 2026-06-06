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
    $chain = vbf_build_drawbox($boxes, $dims['w'], $dims['h'], $ffColor);
    if ($chain === '') return [false, 'no boxes provided'];
    $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
             '-i', $src, '-vf', $chain,
             '-c:v', 'libx264', '-crf', '18', '-preset', 'veryfast',
             '-pix_fmt', 'yuv420p', '-c:a', 'copy', '-movflags', '+faststart', $dst];
    [$code, , $err] = vbf_exec($argv);
    if ($code !== 0) return [false, substr(trim($err), -2000)];
    return [true, ''];
}

// Render a single frame (at $atSeconds) with boxes to a PNG $dst. Returns [bool,$err].
function vbf_render_frame(string $src, string $dst, array $boxes, string $ffColor, float $atSeconds = 0.0): array {
    $dims = vbf_probe_dims($src);
    if ($dims === null) return [false, "probe failed for $src"];
    $chain = vbf_build_drawbox($boxes, $dims['w'], $dims['h'], $ffColor);
    if ($chain === '') return [false, 'no boxes provided'];
    $argv = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
             '-ss', (string) $atSeconds, '-i', $src, '-vf', $chain,
             '-frames:v', '1', $dst];
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
