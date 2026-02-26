<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (app('router')->getMiddleware() as $k => $v) {
    echo $k . '=' . $v . PHP_EOL;
}
