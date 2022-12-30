<?php

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
ini_set('memory_limit', '1G');

error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

use Swow\Channel;
use Swow\Coroutine;
use Swow\Sync\WaitReference;

$sourcePath = $argv[1] ?? '';
$destPath = $argv[2] ?? '';
$namePattern = $argv[3] ?? '*';
$parallelNumber = $argv[4] ?? 3;

if (empty($sourcePath) || empty($destPath)) {
    echo '请输入源路径和目的路径', PHP_EOL;
    return;
}
if (!file_exists($sourcePath) || !file_exists($destPath)) {
    echo '源路径或目的路径不存在', PHP_EOL;
    return;
}

echo '确认mv ' . $sourcePath . '/' . $namePattern . ' 到 ' . $destPath . ' 吗？(yes/no) [no]', PHP_EOL;
$input = rtrim(fgets(STDIN));
if ($input != 'yes') {
    echo '已取消', PHP_EOL;
    return;
}

$wr = new WaitReference();

$chan = new Channel($parallelNumber);
$files = glob($sourcePath . '/' . $namePattern);
foreach ($files as $v) {
    $chan->push(true);
    Coroutine::run(static function () use ($wr, $chan, $v, $destPath) {
        try {
            $source = $v;
            $file = basename($v);
            $size = filesize($v);
            $dest1 = $destPath . "/${file}.tmp";
            $dest2 = $destPath . "/${file}";

            $start = time();
            $cmd = "mv $source $dest1 && mv $dest1 $dest2";
            echo date('Y-m-d H:i:s') . ' ' . $cmd, PHP_EOL;
            $ret = exec($cmd, $output, $code);

            $cost = time() - $start;
            if ($code == 0) {
                $speed = round($size / pow(1024, 2) / ($cost ?: 1), 2);
                echo date('Y-m-d H:i:s') . ' ' . $file . ' moved, time cost: ' . $cost . ' seconds, speed: ' . $speed . ' MiB/s', PHP_EOL;
            } else {
                echo date('Y-m-d H:i:s') . ' ' . $file . ' move failed , cost: ' . $cost . ' seconds, error: ' . $ret, PHP_EOL;
            }
        } catch (Throwable $throwable) {
            echo date('Y-m-d H:i:s') . ' ' . $throwable->getMessage(), PHP_EOL;
        } finally {
            $chan->pop();
        }
    });
}

WaitReference::wait($wr);
