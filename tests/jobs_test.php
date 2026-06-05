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
