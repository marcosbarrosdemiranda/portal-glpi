<?php
// Mini-harness: o portal não tem PHPUnit. Roda com `php wpp/tests/run.php`.
$GLOBALS['__t_fail'] = 0;
$GLOBALS['__t_pass'] = 0;

function t_ok(bool $cond, string $msg): void {
    if ($cond) { $GLOBALS['__t_pass']++; echo "  ok  $msg\n"; }
    else       { $GLOBALS['__t_fail']++; echo "  FAIL $msg\n"; }
}

function t_eq($a, $b, string $msg): void {
    t_ok($a === $b, $msg . "  (esperado " . var_export($b, true) . ", veio " . var_export($a, true) . ")");
}

function t_report(): int {
    echo "\n{$GLOBALS['__t_pass']} ok, {$GLOBALS['__t_fail']} falhas\n";
    return $GLOBALS['__t_fail'];
}
