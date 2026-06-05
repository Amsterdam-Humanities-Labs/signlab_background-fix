# Video Background Fix Tool — Design

**Date:** 2026-06-05
**Status:** Approved (design phase)

## Problem

Studio videos are auto-cropped, but one camera (typically **R**) sometimes leaves
unwanted objects in the background that MediaPipe-based cropping cannot remove.
Today these are fixed manually one by one. We want a web tool to mask those
regions with solid rectangles and batch-apply the fix across a day's takes
(up to ~300), with explicit approval checkpoints before committing.

## Goals

- Browse a day's takes from the existing studio API by date.
- Pick a reference video, draw one or more rectangles over the junk, choose a fill color.
- Approve a **single rendered frame**, then approve a **single full rendered video**,
  then run the **whole selected batch**.
- Apply the **same box coordinates** to every selected video (one problem camera).
- Never destroy originals (back them up before overwriting).

## Non-Goals

- No per-video box adjustment in batch (boxes are defined once).
- No time-ranged boxes (boxes apply to the whole duration).
- No chroma-key / compositing semantics (boxes are opaque covers).
- No authentication layer (runs on trusted internal host).

## Environment / Facts

- Host **is** signcollect.nl's server. Apache `DocumentRoot /web`; tool served at
  `https://signcollect.nl/videoBackgroundFix/`.
- Media is **local and writable**: `/web/gebarenoverleg_media/studioFilesMini/post/`.
- Source of truth API: `https://signcollect.nl/studioIndex/api.php?date=YYYYMMDD`
  returns an array of records: `{id, m_transcription, glos, date, files:[{post, raw, filename}, ...]}`.
  Cameras are encoded by filename prefix letter (L, M, R, A, B).
- Frame ratio is constant (~1.15:1, e.g. **1440×1252**). All videos in a batch
  share dimensions → normalized box coords map identically across the batch.
- Tooling: `php 8.3` (CLI + Apache module), `ffmpeg 6.1`, `ffprobe`.

## Architecture

Plain PHP + vanilla JS/HTML/CSS. No framework, no build step.

```
/web/videoBackgroundFix/
  index.html            # SPA shell
  assets/
    app.js              # all frontend logic
    style.css
  api/
    list.php            # proxy: date -> API JSON, annotated with local paths + existing fix/backup state
    preview_frame.php   # render boxes onto one extracted frame -> PNG
    preview_video.php   # render boxes onto the reference clip -> temp preview mp4
    process.php         # enqueue a batch job, spawn background worker
    status.php          # read job JSON -> progress
  lib/
    paths.php           # filename validation, path resolution (post/, backup/, tmp/, jobs/)
    ffmpeg.php          # build + run drawbox filter chains
  worker.php            # CLI background worker: drains a batch job queue
  jobs/                 # one JSON file per batch job (git-ignored)
  tmp/                  # preview frames + preview videos (git-ignored, periodically cleaned)
```

Media-side directories (under `studioFilesMini/`):
- `post/` — live files (overwritten on commit).
- `post_backup/` — originals moved here before first overwrite (skip if already present).

### Data flow

1. **List**: frontend calls `api/list.php?date=YYYYMMDD`. PHP fetches the upstream API,
   maps each `post` URL to its local path, flags whether a backup already exists
   (i.e. already fixed once), and returns JSON. Frontend renders takes grouped by
   `id`, filterable by camera prefix.
2. **Define boxes**: user clicks a reference video. It loads in `<video>` with a
   `<canvas>` overlay sized to the displayed video. Drag = draw rectangle; multiple
   allowed; delete individual boxes; color picker (default `#0000FF`). Boxes stored
   as normalized `{x, y, w, h}` in 0–1.
3. **Approve frame**: `preview_frame.php` receives `{filename, boxes, color}`,
   extracts one frame with ffmpeg, burns the drawbox chain, returns a PNG. User approves.
4. **Approve video**: `preview_video.php` renders the reference clip to `tmp/` and
   returns its URL. User approves.
5. **Batch**: user checkbox-selects takes to fix. `process.php` writes a job JSON
   (list of filenames + boxes + color), spawns `worker.php` detached, returns job id.
   Frontend polls `status.php?job=ID` and shows a per-file progress list.

### ffmpeg

- Box coords converted from normalized to integer pixels at render time using the
  probed width/height of each input.
- Filter chain: one `drawbox=x=PX:y=PY:w=PW:h=PH:color=COLOR@1.0:t=fill` per box,
  comma-joined.
- Encode: `-c:v libx264 -crf 18 -preset veryfast -pix_fmt yuv420p -c:a copy -movflags +faststart`.
- Commit is atomic: render to `tmp/UNIQUE.mp4`; on success, if `post_backup/FILE`
  does not exist, `rename(post/FILE -> post_backup/FILE)`; then `rename(tmp -> post/FILE)`.
  If a backup already exists, the original in `post/` is the prior fixed output and is
  simply replaced (backup is never overwritten — it always holds the true original).

### Job model

`jobs/JOBID.json`:
```json
{
  "id": "20260526-153012-ab12",
  "date": "20260526",
  "color": "#0000FF",
  "boxes": [{"x":0.1,"y":0.0,"w":0.2,"h":0.3}],
  "created": "2026-06-05T15:30:12",
  "items": [
    {"filename":"R20260526_6393.mp4","status":"queued|processing|done|error","error":null}
  ]
}
```
Worker updates the file after each item (write-temp + rename for atomicity).

## Error handling

- **Path safety**: `lib/paths.php` validates every filename against `^[A-Z]\d{8}_\d+\.mp4$`
  and confirms it exists in `post/`. Any other input is rejected. No user-supplied
  absolute paths ever reach ffmpeg.
- **ffmpeg failure**: non-zero exit → item marked `error` with captured stderr tail;
  batch continues with remaining items. Original is untouched (atomic rename only on success).
- **Upstream API failure**: `list.php` returns an error payload; UI shows a retry.
- **Concurrent safety**: one detached worker per job; atomic file renames avoid partial reads.
- **Backup invariant**: `post_backup/FILE` is written once and never overwritten, so the
  true original is always recoverable.

## Testing strategy

- `lib/paths.php`: unit tests for filename validation (valid, traversal attempts,
  wrong extension, missing file).
- `lib/ffmpeg.php`: assert the generated filter chain string for known box sets
  (no actual encode needed); one integration test that renders a frame from a fixture mp4.
- Worker: integration test on a copy of a small fixture — verify backup created,
  output replaced, job JSON transitions to `done`, original recoverable from backup.
- Manual: full UI walkthrough on date 20260526, camera R, reference `R20260526_6393.mp4`.

## Open defaults (chosen, changeable)

- Default fill color: `#0000FF`.
- Backup dir: `studioFilesMini/post_backup/`.
- Encode preset `veryfast`, CRF 18.
- `tmp/` previews cleaned on a best-effort basis (e.g. older than 1 day) at job start.
