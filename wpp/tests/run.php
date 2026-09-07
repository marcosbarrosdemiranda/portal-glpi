<?php
require __DIR__ . '/assert.php';
foreach (glob(__DIR__ . '/test_*.php') as $f) {
    echo "\n== " . basename($f) . " ==\n";
    require $f;
}
exit(t_report() === 0 ? 0 : 1);
