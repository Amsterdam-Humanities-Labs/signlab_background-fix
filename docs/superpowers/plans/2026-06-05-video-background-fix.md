# Video Background Fix Tool Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A web tool to mask unwanted background regions in studio videos with solid colored rectangles, with frame→video→batch approval checkpoints, applied directly to local media.

**Architecture:** Plain PHP 8.3 + vanilla JS served by existing Apache at `https://signcollect.nl/videoBackgroundFix/`. ffmpeg `drawbox` burns opaque rectangles; batch runs in a detached PHP CLI worker; frontend polls a JSON job file for progress. Originals are backed up once before any overwrite; commits are atomic renames.

**Tech Stack:** PHP 8.3 (Apache module + CLI), ffmpeg/ffprobe 6.1, vanilla HTML/CSS/JS. Zero-dependency PHP test harness (no Composer).

---

## Environment facts (do not re-derive)

- Tool dir: `/web/videoBackgroundFix/` (this is a git repo; served at `https://signcollect.nl/videoBackgroundFix/`).
- Media: `/web/gebarenoverleg_media/studioFilesMini/post/` (local, writable, `www-data:www-data`).
- Upstream API: `https://signcollect.nl/studioIndex/api.php?date=YYYYMMDD` → array of `{id, m_transcription, glos, date, files:[{post,raw,filename}]}`. Camera = first letter of filename.
- Frame ratio constant (~1.15:1, e.g. 1440×1252) → normalized box coords map identically across a batch.
- ffmpeg color syntax: `0xRRGGBB` (verified). drawbox: `drawbox=x=..:y=..:w=..:h=..:color=0x0000FF@1.0:t=fill`.

## File structure

```
/web/videoBackgroundFix/
  index.html              # Task 7
  assets/app.js           # Task 7
  assets/style.css        # Task 7
  lib/paths.php           # Task 1  (env-overridable path config + filename validation)
  lib/ffmpeg.php          # Task 2  (color conversion, drawbox chain, probe, run)
  lib/jobs.php            # Task 5  (atomic job JSON read/write)
  api/list.php            # Task 3
  api/preview_frame.php   # Task 4
  api/preview_video.php   # Task 4
  api/process.php         # Task 6
  api/status.php          # Task 6
  worker.php              # Task 6
  tests/harness.php       # Task 0
  tests/run.php           # Task 0
  tests/paths_test.php    # Task 1
  tests/ffmpeg_test.php   # Task 2
  tests/jobs_test.php     # Task 5
  tests/worker_test.php   # Task 6
  tests/fixtures/         # Task 0 (tiny generated mp4)
  jobs/                   # runtime (git-ignored)
  tmp/                    # runtime (git-ignored)
```

Media-side (created on demand): `studioFilesMini/post_backup/`.

---

### Task 0: Test harness + fixture

**Files:**
- Create: `tests/harness.php`
- Create: `tests/run.php`
- Create: `tests/make_fixture.sh`
- Create: `tests/fixtures/.gitkeep`

- [ ] **Step 1: Write the harness**

Create `tests/harness.php`:

```php
<?php
// Minimal zero-dependency test harness.
$GLOBALS['vbf_t'] = ['pass' => 0, 'fail' => 0, 'group' => ''];

function group(string $name): void {
    $GLOBALS['vbf_t']['group'] = $name;
    echo "# $name\n";
}
function ok(bool $cond, string $msg): void {
    if ($cond) { $GLOBALS['vbf_t']['pass']++; echo "  ok   - $msg\n"; }
    else       { $GLOBALS['vbf_t']['fail']++; echo "  FAIL - $msg\n"; }
}
function eq($got, $want, string $msg): void {
    $pass = $got === $want;
    if (!$pass) {
        $msg .= "\n        got:  " . var_export($got, true)
              . "\n        want: " . var_export($want, true);
    }
    ok($pass, $msg);
}
function vbf_done(): void {
    $t = $GLOBALS['vbf_t'];
    echo "\n{$t['pass']} passed, {$t['fail']} failed\n";
    exit($t['fail'] > 0 ? 1 : 0);
}
```

- [ ] **Step 2: Write the runner**

Create `tests/run.php`:

```php
<?php
require __DIR__ . '/harness.php';
foreach (glob(__DIR__ . '/*_test.php') as $f) {
    require $f;
}
vbf_done();
```

- [ ] **Step 3: Write the fixture generator**

Create `tests/make_fixture.sh`:

```bash
#!/usr/bin/env bash
# Generates a tiny 1440x1252 1-second test clip with audio, matching production ratio.
set -e
DIR="$(dirname "$0")/fixtures"
mkdir -p "$DIR"
ffmpeg -hide_banner -loglevel error -y \
  -f lavfi -i "testsrc=size=1440x1252:rate=25:duration=1" \
  -f lavfi -i "sine=frequency=440:duration=1" \
  -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest \
  "$DIR/R00000000_1.mp4"
echo "wrote $DIR/R00000000_1.mp4"
```

Create empty `tests/fixtures/.gitkeep`.

- [ ] **Step 4: Generate the fixture and verify harness runs**

Run:
```bash
cd /web/videoBackgroundFix && bash tests/make_fixture.sh && php tests/run.php
```
Expected: prints `wrote .../R00000000_1.mp4` then `0 passed, 0 failed` (no test files yet).

- [ ] **Step 5: Commit**

```bash
cd /web/videoBackgroundFix
echo 'tests/fixtures/*.mp4' >> .gitignore
git add tests/harness.php tests/run.php tests/make_fixture.sh tests/fixtures/.gitkeep .gitignore
git commit -m "test: add zero-dependency PHP test harness and fixture generator"
```

---

### Task 1: Path config + filename validation (`lib/paths.php`)

**Files:**
- Create: `lib/paths.php`
- Test: `tests/paths_test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/paths_test.php`:

```php
<?php
require_once __DIR__ . '/../lib/paths.php';
group('paths');

eq(vbf_valid_filename('R20260526_6393.mp4'), true,  'accepts normal name');
eq(vbf_valid_filename('L20260526_3752.mp4'), true,  'accepts L camera');
eq(vbf_valid_filename('../R20260526_6393.mp4'), false, 'rejects traversal prefix');
eq(vbf_valid_filename('R20260526_6393.txt'), false, 'rejects wrong extension');
eq(vbf_valid_filename('r20260526_6393.mp4'), false, 'rejects lowercase camera');
eq(vbf_valid_filename('R20260526_6393.mp4/../x'), false, 'rejects embedded slash');

// Point the post dir at the fixtures dir for path resolution tests.
putenv('VBF_POST_DIR=' . __DIR__ . '/fixtures');
eq(vbf_post_path('R00000000_1.mp4'), __DIR__ . '/fixtures/R00000000_1.mp4', 'resolves existing fixture');
eq(vbf_post_path('R99999999_9.mp4'), null, 'returns null for missing file');
eq(vbf_post_path('../etc/passwd'),   null, 'returns null for invalid name');
putenv('VBF_POST_DIR'); // reset
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /web/videoBackgroundFix && php tests/run.php`
Expected: FAIL — `Call to undefined function vbf_valid_filename()` (fatal) or failing assertions.

- [ ] **Step 3: Write the implementation**

Create `lib/paths.php`:

```php
<?php
// Path configuration and filename validation. Safe to include repeatedly; no side effects.
// All directories are env-overridable so tests never touch production media.

function vbf_post_dir(): string   { return getenv('VBF_POST_DIR')   ?: '/web/gebarenoverleg_media/studioFilesMini/post'; }
function vbf_backup_dir(): string { return getenv('VBF_BACKUP_DIR') ?: '/web/gebarenoverleg_media/studioFilesMini/post_backup'; }
function vbf_tmp_dir(): string    { return getenv('VBF_TMP_DIR')    ?: __DIR__ . '/../tmp'; }
function vbf_jobs_dir(): string   { return getenv('VBF_JOBS_DIR')   ?: __DIR__ . '/../jobs'; }
function vbf_api_base(): string   { return getenv('VBF_API_BASE')   ?: 'https://signcollect.nl/studioIndex/api.php'; }

const VBF_FILENAME_RE = '/^[A-Z]\d{8}_\d+\.mp4$/';

function vbf_valid_filename(string $name): bool {
    return (bool) preg_match(VBF_FILENAME_RE, $name);
}

// Absolute path to a live post file, or null if name is invalid or file is missing.
function vbf_post_path(string $name): ?string {
    if (!vbf_valid_filename($name)) return null;
    $p = vbf_post_dir() . '/' . $name;
    return is_file($p) ? $p : null;
}

// Absolute backup path (file may or may not exist). Null if name invalid.
function vbf_backup_path(string $name): ?string {
    if (!vbf_valid_filename($name)) return null;
    return vbf_backup_dir() . '/' . $name;
}

// Ensure a runtime dir exists (tmp/jobs). Returns the path.
function vbf_ensure_dir(string $dir): string {
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /web/videoBackgroundFix && php tests/run.php`
Expected: all `paths` assertions print `ok`, exit `... passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
cd /web/videoBackgroundFix
git add lib/paths.php tests/paths_test.php
git commit -m "feat: add path config and filename validation"
```

---

### Task 2: Color + drawbox chain + ffmpeg helpers (`lib/ffmpeg.php`)

**Files:**
- Create: `lib/ffmpeg.php`
- Test: `tests/ffmpeg_test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/ffmpeg_test.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /web/videoBackgroundFix && php tests/run.php`
Expected: FAIL — undefined `vbf_hex_to_ffmpeg`.

- [ ] **Step 3: Write the implementation**

Create `lib/ffmpeg.php`:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /web/videoBackgroundFix && php tests/run.php`
Expected: all `ffmpeg` assertions `ok`, `0 failed`.

- [ ] **Step 5: Commit**

```bash
cd /web/videoBackgroundFix
git add lib/ffmpeg.php tests/ffmpeg_test.php
git commit -m "feat: add color/drawbox/ffmpeg helpers"
```

---

### Task 3: Date listing proxy (`api/list.php`)

**Files:**
- Create: `api/list.php`

This endpoint is integration-tested manually (it depends on the upstream API). It maps `post` URLs to local paths and flags whether a backup already exists.

- [ ] **Step 1: Write the implementation**

Create `api/list.php`:

```php
<?php
require_once __DIR__ . '/../lib/paths.php';
header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{8}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid date (expected YYYYMMDD)']);
    exit;
}

$url = vbf_api_base() . '?date=' . urlencode($date);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream API failed', 'status' => $httpCode]);
    exit;
}

$records = json_decode($body, true);
if (!is_array($records)) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream returned non-JSON']);
    exit;
}

// Annotate each file: camera letter, local availability, already-fixed flag.
foreach ($records as &$rec) {
    if (!isset($rec['files']) || !is_array($rec['files'])) continue;
    foreach ($rec['files'] as &$f) {  // NOTE: iterate the real array, not (?? []) — by-ref over a temp expression does not write back
        $name = $f['filename'] ?? '';
        $f['camera']        = ($name !== '' && vbf_valid_filename($name)) ? $name[0] : null;
        $f['local']         = vbf_post_path($name) !== null;
        $bp = vbf_backup_path($name);
        $f['already_fixed'] = $bp !== null && is_file($bp);
        // URL the browser can load for drawing/preview (same host).
        $f['view_url']      = $f['post'] ?? null;
    }
    unset($f);
}
unset($rec);

echo json_encode(['date' => $date, 'records' => $records]);
```

- [ ] **Step 2: Verify manually**

Run:
```bash
curl -s "https://signcollect.nl/videoBackgroundFix/api/list.php?date=20260526" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo "records: ".count($d["records"])."\n"; print_r($d["records"][0]["files"][0]);'
```
Expected: prints a record count > 0 and a file entry containing `camera`, `local:true`, `already_fixed:false`, `view_url`.

Also verify the error path:
```bash
curl -s "https://signcollect.nl/videoBackgroundFix/api/list.php?date=bad" 
```
Expected: `{"error":"invalid date (expected YYYYMMDD)"}`.

- [ ] **Step 3: Commit**

```bash
cd /web/videoBackgroundFix
git add api/list.php
git commit -m "feat: add date listing proxy with local-path annotation"
```

---

### Task 4: Frame + video preview endpoints

**Files:**
- Create: `api/preview_frame.php`
- Create: `api/preview_video.php`

Both accept POST JSON `{filename, boxes:[{x,y,w,h}], color:"#RRGGBB", at?:seconds}`, validate, render to `tmp/`, and return a URL.

- [ ] **Step 1: Implement `preview_frame.php`**

Create `api/preview_frame.php`:

```php
<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/ffmpeg.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['error'=>'invalid JSON body']); exit; }

$src = vbf_post_path($in['filename'] ?? '');
if ($src === null) { http_response_code(400); echo json_encode(['error'=>'invalid or missing filename']); exit; }

$ff = vbf_hex_to_ffmpeg($in['color'] ?? '#0000FF');
if ($ff === null) { http_response_code(400); echo json_encode(['error'=>'invalid color']); exit; }

$boxes = $in['boxes'] ?? [];
if (!is_array($boxes) || count($boxes) === 0) { http_response_code(400); echo json_encode(['error'=>'no boxes']); exit; }

$at = isset($in['at']) ? (float) $in['at'] : 0.0;
vbf_ensure_dir(vbf_tmp_dir());
$out = vbf_tmp_dir() . '/frame_' . bin2hex(random_bytes(6)) . '.png';

[$ok, $err] = vbf_render_frame($src, $out, $boxes, $ff, $at);
if (!$ok) { http_response_code(500); echo json_encode(['error'=>'render failed','detail'=>$err]); exit; }

echo json_encode(['url' => 'tmp/' . basename($out)]);
```

- [ ] **Step 2: Implement `preview_video.php`**

Create `api/preview_video.php`:

```php
<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/ffmpeg.php';
header('Content-Type: application/json');
set_time_limit(300);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['error'=>'invalid JSON body']); exit; }

$src = vbf_post_path($in['filename'] ?? '');
if ($src === null) { http_response_code(400); echo json_encode(['error'=>'invalid or missing filename']); exit; }

$ff = vbf_hex_to_ffmpeg($in['color'] ?? '#0000FF');
if ($ff === null) { http_response_code(400); echo json_encode(['error'=>'invalid color']); exit; }

$boxes = $in['boxes'] ?? [];
if (!is_array($boxes) || count($boxes) === 0) { http_response_code(400); echo json_encode(['error'=>'no boxes']); exit; }

vbf_ensure_dir(vbf_tmp_dir());
$out = vbf_tmp_dir() . '/preview_' . bin2hex(random_bytes(6)) . '.mp4';

[$ok, $err] = vbf_render($src, $out, $boxes, $ff);
if (!$ok) { http_response_code(500); echo json_encode(['error'=>'render failed','detail'=>$err]); exit; }

echo json_encode(['url' => 'tmp/' . basename($out)]);
```

- [ ] **Step 3: Verify manually**

Run:
```bash
cd /web/videoBackgroundFix && vbf_ensure_test() { :; }
curl -s -X POST "https://signcollect.nl/videoBackgroundFix/api/preview_frame.php" \
  -H 'Content-Type: application/json' \
  -d '{"filename":"R20260526_6393.mp4","color":"#0000FF","boxes":[{"x":0.0,"y":0.0,"w":0.3,"h":0.2}]}'
```
Expected: `{"url":"tmp/frame_XXXX.png"}`. Then confirm the PNG exists and shows a blue box:
```bash
ls -la /web/videoBackgroundFix/tmp/frame_*.png
```
Repeat for `preview_video.php`; expect `{"url":"tmp/preview_XXXX.mp4"}` and a playable file.

Verify rejection:
```bash
curl -s -X POST "https://signcollect.nl/videoBackgroundFix/api/preview_frame.php" \
  -H 'Content-Type: application/json' -d '{"filename":"../../etc/passwd","boxes":[{"x":0,"y":0,"w":1,"h":1}]}'
```
Expected: `{"error":"invalid or missing filename"}`.

- [ ] **Step 4: Commit**

```bash
cd /web/videoBackgroundFix
git add api/preview_frame.php api/preview_video.php
git commit -m "feat: add frame and video preview endpoints"
```

---

### Task 5: Job model (`lib/jobs.php`)

**Files:**
- Create: `lib/jobs.php`
- Test: `tests/jobs_test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/jobs_test.php`:

```php
<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/jobs.php';
group('jobs');

$tmpJobs = sys_get_temp_dir() . '/vbf_jobs_' . bin2hex(random_bytes(4));
putenv('VBF_JOBS_DIR=' . $tmpJobs);

$job = vbf_job_create('20260526', '#0000FF',
        [['x'=>0.0,'y'=>0.0,'w'=>0.3,'h'=>0.2]],
        ['R20260526_6393.mp4', 'R20260526_6390.mp4']);

ok(preg_match('/^20260526-/', $job['id']) === 1, 'job id is date-prefixed');
eq(count($job['items']), 2, 'two items queued');
eq($job['items'][0]['status'], 'queued', 'items start queued');

$read = vbf_job_read($job['id']);
eq($read['id'], $job['id'], 'job round-trips from disk');

vbf_job_update_item($job['id'], 'R20260526_6393.mp4', 'done', null);
$read2 = vbf_job_read($job['id']);
$byName = [];
foreach ($read2['items'] as $it) { $byName[$it['filename']] = $it; }
eq($byName['R20260526_6393.mp4']['status'], 'done', 'item status updated on disk');
eq($byName['R20260526_6390.mp4']['status'], 'queued', 'other item untouched');

array_map('unlink', glob($tmpJobs . '/*'));
@rmdir($tmpJobs);
putenv('VBF_JOBS_DIR');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /web/videoBackgroundFix && php tests/run.php`
Expected: FAIL — undefined `vbf_job_create`.

- [ ] **Step 3: Write the implementation**

Create `lib/jobs.php`:

```php
<?php
require_once __DIR__ . '/paths.php';

// Create and persist a new batch job. Returns the job array.
function vbf_job_create(string $date, string $color, array $boxes, array $filenames): array {
    vbf_ensure_dir(vbf_jobs_dir());
    $id = $date . '-' . date('His') . '-' . bin2hex(random_bytes(2));
    $items = [];
    foreach ($filenames as $name) {
        $items[] = ['filename' => $name, 'status' => 'queued', 'error' => null];
    }
    $job = [
        'id'      => $id,
        'date'    => $date,
        'color'   => $color,
        'boxes'   => $boxes,
        'created' => date('c'),
        'items'   => $items,
    ];
    vbf_job_write($job);
    return $job;
}

function vbf_job_path(string $id): ?string {
    if (!preg_match('/^[0-9A-Za-z-]+$/', $id)) return null; // no traversal
    return vbf_jobs_dir() . '/' . $id . '.json';
}

function vbf_job_write(array $job): void {
    $path = vbf_job_path($job['id']);
    $tmp  = $path . '.tmp';
    file_put_contents($tmp, json_encode($job, JSON_PRETTY_PRINT));
    rename($tmp, $path); // atomic
}

function vbf_job_read(string $id): ?array {
    $path = vbf_job_path($id);
    if ($path === null || !is_file($path)) return null;
    $j = json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

function vbf_job_update_item(string $id, string $filename, string $status, ?string $error): void {
    $job = vbf_job_read($id);
    if ($job === null) return;
    foreach ($job['items'] as &$it) {
        if ($it['filename'] === $filename) { $it['status'] = $status; $it['error'] = $error; }
    }
    unset($it);
    vbf_job_write($job);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /web/videoBackgroundFix && php tests/run.php`
Expected: all `jobs` assertions `ok`, `0 failed`.

- [ ] **Step 5: Commit**

```bash
cd /web/videoBackgroundFix
git add lib/jobs.php tests/jobs_test.php
git commit -m "feat: add atomic job model"
```

---

### Task 6: Batch worker + process/status endpoints

**Files:**
- Create: `worker.php`
- Create: `api/process.php`
- Create: `api/status.php`
- Test: `tests/worker_test.php`

The worker drains a job: for each item it renders to tmp, backs up the original once, then atomically replaces the live file.

- [ ] **Step 1: Write the failing test (worker logic on fixtures)**

Create `tests/worker_test.php`:

```php
<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/jobs.php';
require_once __DIR__ . '/../worker.php';
group('worker');

// Isolated sandbox dirs.
$root   = sys_get_temp_dir() . '/vbf_w_' . bin2hex(random_bytes(4));
$post   = "$root/post"; $backup = "$root/backup"; $tmp = "$root/tmp"; $jobs = "$root/jobs";
foreach ([$post,$backup,$tmp,$jobs] as $d) { mkdir($d, 0775, true); }
putenv("VBF_POST_DIR=$post"); putenv("VBF_BACKUP_DIR=$backup");
putenv("VBF_TMP_DIR=$tmp");  putenv("VBF_JOBS_DIR=$jobs");

// Seed one fixable file (copy of fixture) using a valid production-style name.
$name = 'R00000000_2.mp4';
copy(__DIR__ . '/fixtures/R00000000_1.mp4', "$post/$name");
$origSize = filesize("$post/$name");

$job = vbf_job_create('00000000', '#0000FF', [['x'=>0,'y'=>0,'w'=>0.3,'h'=>0.2]], [$name]);
vbf_worker_run($job['id']);

$done = vbf_job_read($job['id']);
eq($done['items'][0]['status'], 'done', 'item marked done');
ok(is_file("$backup/$name"), 'original backed up');
ok(is_file("$post/$name"), 'live file present after replace');
eq(filesize("$backup/$name"), $origSize, 'backup equals original size');

// Re-run a second job on same file: backup must NOT be overwritten (true original preserved).
$backupSize1 = filesize("$backup/$name");
$job2 = vbf_job_create('00000000', '#00FF00', [['x'=>0,'y'=>0,'w'=>0.3,'h'=>0.2]], [$name]);
vbf_worker_run($job2['id']);
eq(filesize("$backup/$name"), $backupSize1, 'backup untouched on second fix');

// Error path: missing file -> status error, no crash.
$job3 = vbf_job_create('00000000', '#0000FF', [['x'=>0,'y'=>0,'w'=>0.3,'h'=>0.2]], ['R00000000_9.mp4']);
vbf_worker_run($job3['id']);
$err = vbf_job_read($job3['id']);
eq($err['items'][0]['status'], 'error', 'missing source -> error');

// Cleanup
array_map('unlink', glob("$post/*")); array_map('unlink', glob("$backup/*"));
array_map('unlink', glob("$tmp/*")); array_map('unlink', glob("$jobs/*"));
foreach ([$post,$backup,$tmp,$jobs,$root] as $d) { @rmdir($d); }
foreach (['VBF_POST_DIR','VBF_BACKUP_DIR','VBF_TMP_DIR','VBF_JOBS_DIR'] as $e) { putenv($e); }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /web/videoBackgroundFix && php tests/run.php`
Expected: FAIL — undefined `vbf_worker_run`.

- [ ] **Step 3: Implement `worker.php`**

Create `worker.php`:

```php
<?php
require_once __DIR__ . '/lib/paths.php';
require_once __DIR__ . '/lib/ffmpeg.php';
require_once __DIR__ . '/lib/jobs.php';

// Process every queued item in a job. Safe to call in-process (tests) or via CLI.
function vbf_worker_run(string $jobId): void {
    $job = vbf_job_read($jobId);
    if ($job === null) return;
    $ff = vbf_hex_to_ffmpeg($job['color']) ?? '0x0000FF';
    vbf_ensure_dir(vbf_tmp_dir());
    vbf_ensure_dir(vbf_backup_dir());

    foreach ($job['items'] as $item) {
        $name = $item['filename'];
        if (!in_array($item['status'], ['queued', 'error'], true)) continue;
        vbf_job_update_item($jobId, $name, 'processing', null);

        $src = vbf_post_path($name);
        if ($src === null) { vbf_job_update_item($jobId, $name, 'error', 'source missing'); continue; }

        $out = vbf_tmp_dir() . '/out_' . bin2hex(random_bytes(6)) . '.mp4';
        [$ok, $err] = vbf_render($src, $out, $job['boxes'], $ff);
        if (!$ok) { @unlink($out); vbf_job_update_item($jobId, $name, 'error', $err); continue; }

        // Back up the TRUE original exactly once.
        $bp = vbf_backup_path($name);
        if ($bp !== null && !is_file($bp)) {
            if (!@copy($src, $bp)) { @unlink($out); vbf_job_update_item($jobId, $name, 'error', 'backup failed'); continue; }
        }
        // Atomically replace the live file (fall back to copy across filesystems —
        // tmp/ and post/ may be on different mounts, where rename() raises EXDEV).
        if (!@rename($out, $src)) {
            if (@copy($out, $src)) { @unlink($out); }
            else { @unlink($out); vbf_job_update_item($jobId, $name, 'error', 'replace failed'); continue; }
        }

        vbf_job_update_item($jobId, $name, 'done', null);
    }
}

// CLI entry: `php worker.php <jobId>`
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    vbf_worker_run($argv[1]);
}
```

Note on backup: the worker uses `copy()` (not `rename()`) for the original so the live file remains in place if the subsequent rename fails — but only when no backup exists yet, preserving the true-original invariant.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /web/videoBackgroundFix && php tests/run.php`
Expected: all `worker` assertions `ok`, `0 failed`.

- [ ] **Step 5: Implement `api/process.php` (enqueue + detach worker)**

Create `api/process.php`:

```php
<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/jobs.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['error'=>'invalid JSON body']); exit; }

$date = $in['date'] ?? '';
if (!preg_match('/^\d{8}$/', $date)) { http_response_code(400); echo json_encode(['error'=>'invalid date']); exit; }

$color = $in['color'] ?? '#0000FF';
if (!preg_match('/^#?[0-9a-fA-F]{6}$/', $color)) { http_response_code(400); echo json_encode(['error'=>'invalid color']); exit; }

$boxes = $in['boxes'] ?? [];
if (!is_array($boxes) || count($boxes) === 0) { http_response_code(400); echo json_encode(['error'=>'no boxes']); exit; }

$names = $in['filenames'] ?? [];
if (!is_array($names) || count($names) === 0) { http_response_code(400); echo json_encode(['error'=>'no files selected']); exit; }
foreach ($names as $n) {
    if (vbf_post_path($n) === null) { http_response_code(400); echo json_encode(['error'=>"invalid or missing file: $n"]); exit; }
}

$job = vbf_job_create($date, $color, $boxes, $names);

// Detach the worker so the HTTP request returns immediately.
// Use php CLI; under php-fpm, PHP_BINARY points to the fpm binary, so prefer /usr/bin/php.
$php    = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
$worker = escapeshellarg(__DIR__ . '/../worker.php');
$jobId  = escapeshellarg($job['id']);
$log    = escapeshellarg(vbf_jobs_dir() . '/' . $job['id'] . '.log');
exec(escapeshellarg($php) . " $worker $jobId > $log 2>&1 &");

echo json_encode(['job' => $job['id'], 'count' => count($names)]);
```

- [ ] **Step 6: Implement `api/status.php`**

Create `api/status.php`:

```php
<?php
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/jobs.php';
header('Content-Type: application/json');

$id = $_GET['job'] ?? '';
$job = vbf_job_read($id);
if ($job === null) { http_response_code(404); echo json_encode(['error'=>'job not found']); exit; }

$counts = ['queued'=>0,'processing'=>0,'done'=>0,'error'=>0];
foreach ($job['items'] as $it) { $counts[$it['status']] = ($counts[$it['status']] ?? 0) + 1; }
$total = count($job['items']);
$finished = $counts['done'] + $counts['error'];

echo json_encode([
    'id' => $job['id'], 'total' => $total, 'finished' => $finished,
    'counts' => $counts, 'complete' => $finished >= $total, 'items' => $job['items'],
]);
```

- [ ] **Step 7: Verify the batch end-to-end via HTTP**

Run (uses one real file; it WILL modify it, but the original is backed up and recoverable):
```bash
JOB=$(curl -s -X POST "https://signcollect.nl/videoBackgroundFix/api/process.php" \
  -H 'Content-Type: application/json' \
  -d '{"date":"20260526","color":"#0000FF","boxes":[{"x":0.0,"y":0.0,"w":0.25,"h":0.15}],"filenames":["R20260526_6393.mp4"]}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN),true)["job"];')
echo "job=$JOB"
sleep 5
curl -s "https://signcollect.nl/videoBackgroundFix/api/status.php?job=$JOB"
```
Expected: status JSON with `"complete":true` and the item `"status":"done"`. Confirm backup exists:
```bash
ls -la /web/gebarenoverleg_media/studioFilesMini/post_backup/R20260526_6393.mp4
```

- [ ] **Step 8: Restore the test file (leave production clean)**

Run:
```bash
cp /web/gebarenoverleg_media/studioFilesMini/post_backup/R20260526_6393.mp4 \
   /web/gebarenoverleg_media/studioFilesMini/post/R20260526_6393.mp4
```
Expected: live file restored to the true original (it was a test render).

- [ ] **Step 9: Commit**

```bash
cd /web/videoBackgroundFix
git add worker.php api/process.php api/status.php tests/worker_test.php
git commit -m "feat: add batch worker and process/status endpoints"
```

---

### Task 7: Frontend (`index.html`, `assets/style.css`, `assets/app.js`)

**Files:**
- Create: `index.html`
- Create: `assets/style.css`
- Create: `assets/app.js`

UI flow: enter date → list (filter by camera) → click reference video → draw boxes on canvas overlay → preview frame → preview video → select batch → process → poll progress.

- [ ] **Step 1: Create `index.html`**

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Video Background Fix</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header>
    <h1>Video Background Fix</h1>
    <div class="toolbar">
      <input id="date" type="text" placeholder="YYYYMMDD" maxlength="8" value="20260526">
      <select id="camera"><option value="">All cameras</option>
        <option>L</option><option>M</option><option>R</option><option>A</option><option>B</option></select>
      <button id="load">Load</button>
      <span id="status-msg"></span>
    </div>
  </header>

  <main>
    <section id="list-pane"><h2>Takes</h2><div id="list"></div></section>

    <section id="edit-pane" hidden>
      <h2>Editor — <span id="edit-name"></span></h2>
      <div class="editor-controls">
        <label>Color <input id="color" type="color" value="#0000FF"></label>
        <button id="clear-boxes">Clear boxes</button>
        <button id="preview-frame">Preview frame</button>
        <button id="preview-video">Preview video</button>
        <span id="box-count">0 boxes</span>
      </div>
      <div class="stage">
        <video id="video" controls crossorigin="anonymous"></video>
        <canvas id="canvas"></canvas>
      </div>
      <div id="preview-out"></div>
    </section>

    <section id="batch-pane" hidden>
      <h2>Batch</h2>
      <p>Boxes defined on the reference clip apply to every checked file below.</p>
      <div class="batch-controls">
        <button id="select-cam">Select all (current camera filter)</button>
        <button id="run-batch">Process selected</button>
      </div>
      <div id="batch-list"></div>
      <div id="progress"></div>
    </section>
  </main>

  <script src="assets/app.js"></script>
</body>
</html>
```

- [ ] **Step 2: Create `assets/style.css`**

```css
* { box-sizing: border-box; }
body { font-family: system-ui, sans-serif; margin: 0; color: #1a1a1a; }
header { padding: 12px 16px; background: #0b1f3a; color: #fff; position: sticky; top: 0; z-index: 10; }
header h1 { margin: 0 0 8px; font-size: 18px; }
.toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.toolbar input, .toolbar select, button { padding: 6px 10px; font-size: 14px; }
button { cursor: pointer; border: 1px solid #2b4a7a; background: #1d3a6b; color: #fff; border-radius: 4px; }
button:hover { background: #2b4a7a; }
main { display: grid; grid-template-columns: 320px 1fr; gap: 16px; padding: 16px; }
#edit-pane, #batch-pane { grid-column: 1 / -1; }
#list { display: flex; flex-direction: column; gap: 4px; max-height: 70vh; overflow:auto; }
.take { border: 1px solid #ddd; border-radius: 4px; padding: 6px 8px; }
.take h4 { margin: 0 0 4px; font-size: 13px; }
.file { display: flex; gap: 6px; align-items: center; font-size: 12px; padding: 2px 0; }
.file button { padding: 2px 6px; font-size: 11px; }
.badge { font-size: 10px; padding: 1px 5px; border-radius: 8px; background: #e0e0e0; }
.badge.fixed { background: #b8e0c2; }
.stage { position: relative; display: inline-block; max-width: 100%; }
#video { max-width: 100%; display: block; }
#canvas { position: absolute; left: 0; top: 0; cursor: crosshair; }
.batch-controls { display: flex; gap: 8px; margin-bottom: 8px; }
#batch-list { display: flex; flex-direction: column; gap: 2px; max-height: 50vh; overflow: auto; }
.batch-row { display: flex; gap: 8px; align-items: center; font-size: 13px; }
.pstat { font-size: 12px; }
.pstat.done { color: #1a7f37; } .pstat.error { color: #b00; } .pstat.processing { color: #b36b00; }
#preview-out img, #preview-out video { max-width: 100%; border: 2px solid #0b1f3a; margin-top: 8px; }
.row-actions { margin-top: 8px; display: flex; gap: 8px; }
```

- [ ] **Step 3: Create `assets/app.js`**

```javascript
'use strict';

const $ = (sel) => document.querySelector(sel);
const state = {
  records: [],          // from list.php
  current: null,        // {filename, view_url}
  boxes: [],            // normalized {x,y,w,h}
  drawing: null,        // in-progress box (display px)
  frameApproved: false,
  videoApproved: false,
};

function msg(text) { $('#status-msg').textContent = text; }

// ---- Listing ----
async function loadDate() {
  const date = $('#date').value.trim();
  if (!/^\d{8}$/.test(date)) { msg('Date must be YYYYMMDD'); return; }
  msg('Loading…');
  const res = await fetch(`api/list.php?date=${date}`);
  const data = await res.json();
  if (data.error) { msg('Error: ' + data.error); return; }
  state.records = data.records;
  renderList();
  renderBatch();
  msg(`${data.records.length} takes`);
}

function camFilter() { return $('#camera').value; }

function renderList() {
  const cam = camFilter();
  const wrap = $('#list');
  wrap.innerHTML = '';
  for (const rec of state.records) {
    const files = (rec.files || []).filter(f => f.local && (!cam || f.camera === cam));
    if (files.length === 0) continue;
    const div = document.createElement('div');
    div.className = 'take';
    div.innerHTML = `<h4>#${rec.id} — ${rec.glos || rec.m_transcription || ''}</h4>`;
    for (const f of files) {
      const row = document.createElement('div');
      row.className = 'file';
      const fixed = f.already_fixed ? '<span class="badge fixed">fixed</span>' : '';
      row.innerHTML = `<span class="badge">${f.camera}</span>
        <span>${f.filename}</span> ${fixed}`;
      const btn = document.createElement('button');
      btn.textContent = 'Edit';
      btn.onclick = () => openEditor(f);
      row.appendChild(btn);
      div.appendChild(row);
    }
    wrap.appendChild(div);
  }
}

// ---- Editor ----
function openEditor(f) {
  state.current = f;
  state.boxes = [];
  state.frameApproved = false;
  state.videoApproved = false;
  $('#edit-pane').hidden = false;
  $('#edit-name').textContent = f.filename;
  $('#preview-out').innerHTML = '';
  const v = $('#video');
  v.src = f.view_url;
  v.onloadedmetadata = sizeCanvas;
  $('#edit-pane').scrollIntoView({ behavior: 'smooth' });
  updateBoxCount();
}

function sizeCanvas() {
  const v = $('#video'), c = $('#canvas');
  c.width = v.clientWidth;
  c.height = v.clientHeight;
  redraw();
}
window.addEventListener('resize', () => { if (!$('#edit-pane').hidden) sizeCanvas(); });

function redraw() {
  const c = $('#canvas'), ctx = c.getContext('2d');
  ctx.clearRect(0, 0, c.width, c.height);
  const color = $('#color').value;
  const drawBox = (b) => {
    ctx.fillStyle = color + 'cc';
    ctx.strokeStyle = '#fff';
    ctx.fillRect(b.x * c.width, b.y * c.height, b.w * c.width, b.h * c.height);
    ctx.strokeRect(b.x * c.width, b.y * c.height, b.w * c.width, b.h * c.height);
  };
  state.boxes.forEach(drawBox);
  if (state.drawing) {
    const d = state.drawing;
    ctx.fillStyle = $('#color').value + '66';
    ctx.fillRect(d.x, d.y, d.w, d.h);
  }
}

function updateBoxCount() { $('#box-count').textContent = `${state.boxes.length} boxes`; }

// Canvas mouse drawing (display px -> normalized on mouseup)
(function bindCanvas() {
  const c = $('#canvas');
  let start = null;
  c.addEventListener('mousedown', (e) => {
    const r = c.getBoundingClientRect();
    start = { x: e.clientX - r.left, y: e.clientY - r.top };
  });
  c.addEventListener('mousemove', (e) => {
    if (!start) return;
    const r = c.getBoundingClientRect();
    const x = e.clientX - r.left, y = e.clientY - r.top;
    state.drawing = { x: Math.min(start.x, x), y: Math.min(start.y, y),
                      w: Math.abs(x - start.x), h: Math.abs(y - start.y) };
    redraw();
  });
  c.addEventListener('mouseup', () => {
    if (state.drawing && state.drawing.w > 4 && state.drawing.h > 4) {
      const d = state.drawing;
      state.boxes.push({ x: d.x / c.width, y: d.y / c.height,
                         w: d.w / c.width, h: d.h / c.height });
      // New geometry invalidates prior approvals.
      state.frameApproved = false; state.videoApproved = false;
    }
    state.drawing = null; start = null;
    updateBoxCount(); redraw();
  });
})();

function reqBody() {
  return JSON.stringify({
    filename: state.current.filename,
    color: $('#color').value,
    boxes: state.boxes,
  });
}

async function previewFrame() {
  if (state.boxes.length === 0) { msg('Draw at least one box'); return; }
  msg('Rendering frame…');
  const res = await fetch('api/preview_frame.php',
    { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: reqBody() });
  const data = await res.json();
  if (data.error) { msg('Frame error: ' + (data.detail || data.error)); return; }
  msg('Frame ready — approve to continue');
  $('#preview-out').innerHTML =
    `<p>Preview frame — does this look right?</p><img src="${data.url}?t=${Date.now()}">
     <div class="row-actions"><button id="approve-frame">Approve frame</button></div>`;
  $('#approve-frame').onclick = () => { state.frameApproved = true; msg('Frame approved — now preview the video'); };
}

async function previewVideo() {
  if (!state.frameApproved) { msg('Approve the frame first'); return; }
  msg('Rendering video (may take a few seconds)…');
  const res = await fetch('api/preview_video.php',
    { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: reqBody() });
  const data = await res.json();
  if (data.error) { msg('Video error: ' + (data.detail || data.error)); return; }
  msg('Video ready — approve to enable batch');
  $('#preview-out').innerHTML =
    `<p>Preview video — does this look right?</p>
     <video src="${data.url}?t=${Date.now()}" controls autoplay></video>
     <div class="row-actions"><button id="approve-video">Approve video</button></div>`;
  $('#approve-video').onclick = () => {
    state.videoApproved = true;
    $('#batch-pane').hidden = false;
    renderBatch();
    msg('Approved — select files and process the batch');
    $('#batch-pane').scrollIntoView({ behavior: 'smooth' });
  };
}

// ---- Batch ----
function renderBatch() {
  const cam = camFilter();
  const wrap = $('#batch-list');
  wrap.innerHTML = '';
  for (const rec of state.records) {
    for (const f of (rec.files || [])) {
      if (!f.local || (cam && f.camera !== cam)) continue;
      const row = document.createElement('label');
      row.className = 'batch-row';
      row.innerHTML = `<input type="checkbox" value="${f.filename}">
        <span class="badge">${f.camera}</span> ${f.filename}
        ${f.already_fixed ? '<span class="badge fixed">fixed</span>' : ''}
        <span class="pstat" data-file="${f.filename}"></span>`;
      wrap.appendChild(row);
    }
  }
}

function selectedFiles() {
  return [...document.querySelectorAll('#batch-list input:checked')].map(c => c.value);
}

async function runBatch() {
  if (!state.videoApproved) { msg('Approve a preview video first'); return; }
  const files = selectedFiles();
  if (files.length === 0) { msg('Select at least one file'); return; }
  if (!confirm(`Process ${files.length} file(s)? Originals are backed up.`)) return;
  msg('Submitting batch…');
  const res = await fetch('api/process.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      date: $('#date').value.trim(), color: $('#color').value,
      boxes: state.boxes, filenames: files,
    }),
  });
  const data = await res.json();
  if (data.error) { msg('Batch error: ' + data.error); return; }
  pollStatus(data.job);
}

async function pollStatus(jobId) {
  const res = await fetch(`api/status.php?job=${jobId}`);
  const data = await res.json();
  if (data.error) { msg('Status error: ' + data.error); return; }
  $('#progress').textContent =
    `Progress: ${data.finished}/${data.total} (done ${data.counts.done}, error ${data.counts.error})`;
  for (const it of data.items) {
    const el = document.querySelector(`.pstat[data-file="${it.filename}"]`);
    if (el) { el.textContent = it.status; el.className = 'pstat ' + it.status; }
  }
  if (!data.complete) { setTimeout(() => pollStatus(jobId), 1500); }
  else { msg(`Batch complete: ${data.counts.done} done, ${data.counts.error} error`); }
}

// ---- Wire up ----
$('#load').onclick = loadDate;
$('#camera').onchange = () => { renderList(); renderBatch(); };
$('#color').oninput = redraw;
$('#clear-boxes').onclick = () => { state.boxes = []; state.frameApproved = false; state.videoApproved = false; updateBoxCount(); redraw(); };
$('#preview-frame').onclick = previewFrame;
$('#preview-video').onclick = previewVideo;
$('#select-cam').onclick = () => document.querySelectorAll('#batch-list input').forEach(c => c.checked = true);
$('#run-batch').onclick = runBatch;
```

- [ ] **Step 4: Verify the UI loads**

Run:
```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://signcollect.nl/videoBackgroundFix/index.html"
curl -s -o /dev/null -w "%{http_code}\n" "https://signcollect.nl/videoBackgroundFix/assets/app.js"
```
Expected: `200` for both.

Then open `https://signcollect.nl/videoBackgroundFix/` in a browser and confirm: Load lists takes for `20260526`; filtering to camera **R** shows R files; clicking Edit loads the video with a draw-able overlay.

- [ ] **Step 5: Commit**

```bash
cd /web/videoBackgroundFix
git add index.html assets/style.css assets/app.js
git commit -m "feat: add frontend editor, batch UI, and progress polling"
```

---

### Task 8: Full end-to-end manual verification + cleanup

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `cd /web/videoBackgroundFix && php tests/run.php`
Expected: `... passed, 0 failed`.

- [ ] **Step 2: Manual browser walkthrough**

In a browser at `https://signcollect.nl/videoBackgroundFix/`:
1. Date `20260526`, camera `R`, Load.
2. Edit `R20260526_6393.mp4`, draw a box over a background corner.
3. Preview frame → blue box appears → Approve frame.
4. Preview video → plays with box burned in → Approve video.
5. In Batch, select just `R20260526_6393.mp4`, Process selected.
6. Progress reaches `1/1 done`.

Expected: the live `post/R20260526_6393.mp4` now shows the box; `post_backup/R20260526_6393.mp4` holds the original.

- [ ] **Step 3: Restore the manually-tested file**

Run:
```bash
cp /web/gebarenoverleg_media/studioFilesMini/post_backup/R20260526_6393.mp4 \
   /web/gebarenoverleg_media/studioFilesMini/post/R20260526_6393.mp4
```
Expected: live file restored (this was a verification run, not a real fix).

- [ ] **Step 4: Confirm permissions note**

The Apache user must be able to write to `post/` and `post_backup/`. If `process.php` returns errors about writing, run once:
```bash
sudo chgrp -R www-data /web/gebarenoverleg_media/studioFilesMini/post_backup 2>/dev/null || true
sudo chmod g+ws /web/gebarenoverleg_media/studioFilesMini/post /web/gebarenoverleg_media/studioFilesMini/post_backup 2>/dev/null || true
```
Expected: subsequent batches write without permission errors. (Media is already `www-data:www-data`, so this is usually a no-op.)

- [ ] **Step 5: Final commit (docs/state)**

```bash
cd /web/videoBackgroundFix
git add -A
git commit -m "chore: video background fix tool complete" || echo "nothing to commit"
```

---

## Self-review notes

- **Spec coverage:** listing (Task 3), draw boxes/color (Task 7), preview frame approval (Task 4/7), preview video approval (Task 4/7), batch same-box (Task 6/7), backup-once invariant (Task 6, tested), atomic replace (Task 6), path safety (Task 1, tested), job tracking (Task 5/6). All covered.
- **Backup invariant:** worker uses `copy()` to backup only when no backup exists, then `rename()` of the rendered temp over the live file — original always recoverable.
- **Type consistency:** function names match across tasks (`vbf_post_path`, `vbf_render`, `vbf_render_frame`, `vbf_build_drawbox`, `vbf_hex_to_ffmpeg`, `vbf_job_create`, `vbf_job_read`, `vbf_job_update_item`, `vbf_worker_run`).
- **Known constraint:** `process.php` detaches the worker with `exec(... &)`; if PHP runs under a restrictive `disable_functions`, fall back to running `php worker.php <jobId>` synchronously inside `process.php` (slower response but same result).
