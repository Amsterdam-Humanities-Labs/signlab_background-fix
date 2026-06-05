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
