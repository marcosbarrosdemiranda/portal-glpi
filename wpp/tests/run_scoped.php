<?php
// Scoped test runner: runs only the specified test file(s).
// Usage: php run_scoped.php wpp/tests/test_mytest.php [more files...]

$files = array_slice($argv, 1);
if (empty($files)) {
    echo "Usage: php run_scoped.php wpp/tests/test_name.php\n";
    exit(1);
}

require __DIR__ . '/assert.php';

foreach ($files as $f) {
    $path = __DIR__ . '/' . basename($f);
    if (!file_exists($path)) {
        echo "FAIL: File not found: $path\n";
        exit(1);
    }
    echo "\n== " . basename($path) . " ==\n";
    require $path;
}

exit(t_report() === 0 ? 0 : 1);