# videoBackgroundFix
Masks background junk in studio clips and widens them to a uniform 1.15:1 with the signer centred, as a batch job over one day's takes.

## What it does
- UI (`index.html`, `assets/app.js`): pick date + camera, draw mask boxes (normalised 0..1, default fill `#316CA4`), preview a frame, preview a video, queue the batch, watch / cancel / restore.
- `api/process.php` detaches `setsid php worker.php <jobId>`, so renders survive the request and php-fpm recycling.
- `lib/ffmpeg.php`: `drawbox` masks; if not within 0.005 of 1.15:1, trim black borders, centre on the signer (`lib/detect_center.py`, falls back to frame middle) on a blue canvas. No vertical crop or scaling; fps kept; libx264 CRF 18, audio copied.
- First overwrite copies the original mp4 + jpg to `post_backup/`; replacement is atomic within `post/`; `api/restore.php` puts it back. Filenames must match `/^[A-Z]\d{8}_\d+\.mp4$/`.
- `docs/superpowers/` describes an earlier crop/scale design; trust the code.

## Where it runs
Production core server only: `/web/videoBackgroundFix`, https://signcollect.nl/videoBackgroundFix/. It rewrites `/web/gebarenoverleg_media/studioFilesMini/post/` on local disk.
Not in the stack's `repos.tsv`, so demo hosts do not get it. TODO: confirm that is deliberate and how production got its checkout.

## Status
Production.

## How to run / deploy
No build step. Needs PHP 8, `ffmpeg`/`ffprobe`, `python3` with OpenCV + NumPy, `/usr/bin/setsid`, CLI PHP at `/usr/bin/php`.
`jobs/` and `tmp/` must be writable by the web user.
Tests (never touch production media; keep every dir env-overridable):
```bash
tests/make_fixture.sh   # once: 1440x1252 1-second clip
php tests/run.php
```

## Configuration
No credentials or database. Defaults in `lib/paths.php`, overridable by env:
| Variable | Default |
|---|---|
| `VBF_POST_DIR` | `/web/gebarenoverleg_media/studioFilesMini/post` |
| `VBF_BACKUP_DIR` | `/web/gebarenoverleg_media/studioFilesMini/post_backup` |
| `VBF_TMP_DIR` / `VBF_JOBS_DIR` | `<repo>/tmp` / `<repo>/jobs` |
| `VBF_API_BASE` | `https://signcollect.nl/studioIndex/api.php` |

## Dependencies
- `signlab_studioIndex` `api.php`: date list (no date → 400 + `available_dates`) and clips (`?date=YYYYMMDD`).
- Writable media on local disk: `studioFilesMini/post/` and `post_backup/`.
- No authentication and no `/userProtect.js`: anyone who can reach the URL can overwrite studio media.
- Deploy context: [signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).
