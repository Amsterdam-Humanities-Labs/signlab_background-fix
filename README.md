# signlab_videoBackgroundFix
A batch tool for one day of studio recordings. It masks unwanted background and widens each video to 1.15:1 with the signer in the centre.

## What it does
- The page (`index.html`, `assets/app.js`): choose a date and a camera, draw mask boxes (0..1 coordinates, default colour `#316CA4`), preview a frame or a video, start the batch, then watch, cancel or restore.
- `api/process.php` starts `worker.php <jobId>` with `setsid`. The render keeps running after the request ends and when php-fpm restarts.
- `lib/ffmpeg.php` draws the masks with `drawbox`. If a video is more than 0.005 away from 1.15:1, it trims black borders and pads left and right with studio blue. `lib/detect_center.py` finds the signer to centre on; if that fails it uses the middle of the frame. There is no vertical crop and no scaling. Frame rate stays the same; video is libx264 CRF 18, audio is copied.
- Before the first overwrite it copies the original `.mp4` and `.jpg` to `post_backup/`. The swap in `post/` is atomic. `api/restore.php` puts the original back. File names must match `/^[A-Z]\d{8}_\d+\.mp4$/`.

## Where it runs
Core server only: `/web/videoBackgroundFix`, https://signcollect.nl/videoBackgroundFix/. It rewrites `/web/gebarenoverleg_media/studioFilesMini/post/` on local disk.
It is not in the stack's `repos.tsv`, so demo hosts do not get it. Open question: is that deliberate, and how did the core server get its checkout?

## Status
Production.

## How to run / deploy
There is no build step. It needs PHP 8, `ffmpeg` and `ffprobe`, `python3` with OpenCV and NumPy, `/usr/bin/setsid`, and the PHP CLI at `/usr/bin/php`.
The web user must be able to write to `jobs/` and `tmp/`.
Tests must never touch production media, so every folder can be set by an environment variable:
```bash
php tests/run.php         # runs every tests/*_test.php; needs ffmpeg
tests/make_fixture.sh     # optional: rebuilds the 1-second 1440x1252 clip in tests/fixtures/ (it is in git)
```

## Configuration
No credentials and no database. `lib/paths.php` holds the defaults; environment variables override them.
| Variable | Default |
|---|---|
| `VBF_POST_DIR` | `/web/gebarenoverleg_media/studioFilesMini/post` |
| `VBF_BACKUP_DIR` | `/web/gebarenoverleg_media/studioFilesMini/post_backup` |
| `VBF_TMP_DIR` / `VBF_JOBS_DIR` | `<repo>/tmp` / `<repo>/jobs` |
| `VBF_API_BASE` | `https://signcollect.nl/studioIndex/api.php` |

## Dependencies
- [signlab_studioIndex](https://github.com/Amsterdam-Humanities-Labs/signlab_studioIndex) `api.php`: the list of dates (a request without a date returns 400 with `available_dates`) and the videos of a date (`?date=YYYYMMDD`).
- Write access to `studioFilesMini/post/` and `post_backup/` on local disk.
- There is no login and no `/userProtect.js`. Anyone who can reach the URL can overwrite studio media.
- Deploy context: [signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).
