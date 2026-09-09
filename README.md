# videoBackgroundFix

Background and framing correction for studio clips: mask out junk in the
background with solid rectangles, widen the frame to a uniform 1.15:1 with the
signer centred, and apply it to a whole day's takes as a background job.

## What it does

One camera (usually **R**) sometimes catches objects that the automatic crop
cannot remove, and clips do not all come out at the same aspect ratio. This
tool fixes both, in place, with a preview-then-commit workflow so nothing is
destroyed by accident.

The workflow the UI (`index.html` + `assets/app.js`) enforces:

1. **Pick a date**, then a camera letter, from the clips recorded that day.
2. **Draw boxes** on a reference clip and choose the fill colour (default
   `#316CA4`, the measured studio blue). Box coordinates are normalised 0..1
   and applied identically to every clip in the batch.
3. **Preview a single frame**, then **preview a full video**. Previews run the
   exact same pipeline as the final render, so what you approve is what you
   get. Output lands in `tmp/` and is served from there.
4. **Queue the batch.** `api/process.php` writes a job file and detaches a
   `setsid php worker.php <jobId>` process, so long ffmpeg runs survive the
   HTTP request, the browser tab, and php-fpm recycling.
5. **Watch, cancel, restore.** Status polling shows per-item state; cancel
   flags the job and kills the worker; restore puts the pristine originals back.

**The fix itself** (`lib/ffmpeg.php`): mask boxes are drawn with `drawbox`; then,
if the clip is not already within 0.005 of 1.15:1, black edge borders are
trimmed, `lib/detect_center.py` finds the signer's horizontal centre by
subtracting the known background colour, and the clip is overlaid on a blue
canvas of the target width with the person centred. Vertical is never touched —
no top/bottom crop, no scaling — and the source frame rate is preserved.
Re-encode is libx264 CRF 18, audio copied.

**Nothing is lost.** Before the first overwrite of a clip, the true original
(video *and* its `.jpg` thumbnail) is copied once into
`studioFilesMini/post_backup/`. Replacement is atomic: because `tmp/` and
`post/` are on different filesystems, the worker copies into a hidden sibling
inside `post/` and renames within that filesystem, so Apache never serves a
half-written file. The thumbnail is regenerated from the middle of the fixed
video. `api/restore.php` copies the backup back and deletes it, which also
clears the "already fixed" flag.

### Endpoints

| Endpoint | Does |
|---|---|
| `api/dates.php` | The list of available dates, proxied from the upstream studio API |
| `api/list.php` | The clips for one date, annotated per file with camera letter, whether it exists locally, and whether a backup already exists (`already_fixed`) |
| `api/preview_frame.php` | One rendered PNG frame at `at` seconds → `tmp/` |
| `api/preview_video.php` | One fully rendered clip → `tmp/` |
| `api/process.php` | Create a job and detach the worker |
| `api/status.php` | Per-item status for one job |
| `api/jobs.php` | The 50 newest jobs, so history survives a reload |
| `api/cancel.php` | Flag the job cancelled and stop the worker |
| `api/restore.php` | Put the originals back and drop the backups |

Filenames are validated everywhere against `/^[A-Z]\d{8}_\d+\.mp4$/`, which is
also what keeps path traversal out.

## Where it runs

- **Production:** the signcollect core server (production VPS), served from
  `/web/videoBackgroundFix` at <https://signcollect.nl/videoBackgroundFix/>.
  It must run *on* that machine: it reads and rewrites the real media under
  `/web/gebarenoverleg_media/studioFilesMini/post/` directly on disk.
- **Demo hosts:** **not deployed.** This repo is not listed in the stack's
  `repos.tsv`, so dev2 and dev-1 do not get it. That is consistent with what it
  does — a tool that overwrites production media has no place on a demo host.
  TODO: confirm this is deliberate rather than an oversight.

## Status

Production. The design notes under `docs/superpowers/` describe an earlier
behaviour (crop/scale/vertical reposition, eye detection); the current
behaviour is the widen-to-1.15:1 described above — read the code, and the
git log, before trusting the specs.

## Deploying it

No build step: PHP 8, plus `ffmpeg`/`ffprobe` and `python3` with OpenCV and
NumPy on the host. Deployment elsewhere in this estate is driven by
`interface_deploy/scripts/repos.tsv` in
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack),
which clones the repo onto the host — but this repo has no entry there.
TODO: confirm how production got its copy; it predates that deploy and is
presumably a hand-placed clone or checkout.

`jobs/` and `tmp/` must exist and be writable by the web server user; both are
gitignored apart from their `.gitkeep`.

### Tests

A dependency-free harness, run from the repo root:

```bash
tests/make_fixture.sh     # once: generates a 1440x1252 1-second clip
php tests/run.php
```

Every directory is env-overridable (`VBF_POST_DIR`, `VBF_BACKUP_DIR`,
`VBF_TMP_DIR`, `VBF_JOBS_DIR`, `VBF_API_BASE`) precisely so the tests never
touch production media. Keep it that way.

## Configuration

There are no credential files and no database: this tool holds no config that
is not either a default in `lib/paths.php` or an environment variable.

| Variable | Default |
|---|---|
| `VBF_POST_DIR` | `/web/gebarenoverleg_media/studioFilesMini/post` |
| `VBF_BACKUP_DIR` | `/web/gebarenoverleg_media/studioFilesMini/post_backup` |
| `VBF_TMP_DIR` | `<repo>/tmp` |
| `VBF_JOBS_DIR` | `<repo>/jobs` |
| `VBF_API_BASE` | `https://signcollect.nl/studioIndex/api.php` |

Note the defaults are absolute `/web/...` paths, not resolved through
signcollect-lib's install root — on a host whose docroot is not `/web` they
must be set explicitly.

## Dependencies

- **`signlab_studioIndex`'s `api.php`** is the source of truth for what was
  recorded. `api/dates.php` calls it with no date (which answers HTTP 400 plus
  `available_dates`) and `api/list.php` with `?date=YYYYMMDD`. If that endpoint
  moves or changes shape, this tool has no date list and no clips.
- **The media on local disk**, writable: `studioFilesMini/post/` and its
  sibling `post_backup/`.
- **`ffmpeg` and `ffprobe`** on `PATH` (developed against ffmpeg 6.1).
- **`python3` with OpenCV (`cv2`) and NumPy** for `lib/detect_center.py`. If it
  is missing the widen still runs, but falls back to centring on the geometric
  middle of the frame instead of on the signer.
- **`setsid`** at `/usr/bin/setsid` and a CLI PHP at `/usr/bin/php` (falls back
  to `PHP_BINARY`) for detaching the worker.
- **No authentication.** The design notes state this explicitly as a non-goal:
  it runs on a trusted internal host. Unlike the rest of the estate this page
  does not even load `/userProtect.js`, so anything that can reach the URL can
  overwrite studio media. Treat network reachability as the access control.
