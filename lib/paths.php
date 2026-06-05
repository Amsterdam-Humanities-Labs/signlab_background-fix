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
