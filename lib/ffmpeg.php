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
