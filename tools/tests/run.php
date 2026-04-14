<?php

$tests = [
    __DIR__.'/TestNormalizationAndMatching.php',
    __DIR__.'/TestRunAllDry.php',
];

$okCount = 0;
$koCount = 0;

foreach ($tests as $file) {
    require_once $file;
    $className = basename($file, '.php');

    try {
        $test = new $className();
        $test->run();
        $okCount++;
        print '[OK] '.$className.PHP_EOL;
    } catch (Throwable $e) {
        $koCount++;
        print '[KO] '.$className.' -> '.$e->getMessage().PHP_EOL;
    }
}

print PHP_EOL.'Tests: '.$okCount.' OK / '.$koCount.' KO'.PHP_EOL;
exit($koCount > 0 ? 1 : 0);
