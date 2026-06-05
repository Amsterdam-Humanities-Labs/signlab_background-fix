<?php
require __DIR__ . '/harness.php';
foreach (glob(__DIR__ . '/*_test.php') as $f) {
    require $f;
}
vbf_done();
