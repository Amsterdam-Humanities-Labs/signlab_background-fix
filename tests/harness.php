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
