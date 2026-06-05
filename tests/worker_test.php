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
