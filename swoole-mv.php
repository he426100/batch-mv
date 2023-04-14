<?php

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
ini_set('memory_limit', '1G');

error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

use Swoole\Coroutine\System;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Barrier;
use function Swoole\Coroutine\run;
use function Swoole\Coroutine\go;

run(function () {
    global $argv;
    $sourcePath = $argv[1] ?? '';
    $destPath = $argv[2] ?? '';
    $namePattern = $argv[3] ?? '*';
    $parallelNumber = $argv[4] ?? 3;
    $watch = $argv[5] ?? 0;

    if (empty($sourcePath) || empty($destPath)) {
        echo '使用方法: ' . PHP_EOL . 'swoole-mv 源路径 目的路径 文件名 [并发数量] [是否监听]', PHP_EOL;
        return;
    }
    if (!file_exists($sourcePath) || !file_exists($destPath)) {
        echo '源路径或目的路径不存在', PHP_EOL;
        return;
    }

    if (is_file($namePattern)) {
        echo '确认按 ' . $namePattern . ' mv ' . $sourcePath . ' 到 ' . $destPath . ' 吗？(yes/no) [no]', PHP_EOL;
    } else {
        echo '确认mv ' . $sourcePath . '/' . $namePattern . ' 到 ' . $destPath . ' 吗？(yes/no) [no]', PHP_EOL;
    }

    $input = rtrim(fgets(STDIN));
    if ($input != 'yes') {
        echo '已取消', PHP_EOL;
        return;
    }

    if ($watch && !is_file($namePattern)) {
        while (1) {
            echo date('Y-m-d H:i:s') . ' start moving...', PHP_EOL;
            move($sourcePath, $destPath, $namePattern, $parallelNumber);
            sleep(60);
        }
    } else {
        echo date('Y-m-d H:i:s') . ' start moving...', PHP_EOL;
        move($sourcePath, $destPath, $namePattern, $parallelNumber);
    }
});

function move($sourcePath, $destPath, $namePattern, $parallelNumber)
{
    static $tasks = [];
    $barrier = Barrier::make();
    $chan = new Channel($parallelNumber);
    $files = is_file($namePattern) ? getList($namePattern) : glob($sourcePath . '/' . $namePattern);
    foreach ($files as $v) {
        // 防止对同一个文件并发操作
        if (isset($tasks[$v])) {
            echo date('Y-m-d H:i:s') . ' ' . $v . ' moving', PHP_EOL;
            continue;
        }
        $tasks[$v] = 1;

        $chan->push(true);
        go(function () use ($barrier, $chan, $v, $destPath, &$tasks) {
            try {
                $source = $v;
                $file = basename($v);
                $size = filesize($v);
                $dest1 = $destPath . "/{$file}.tmp";
                $dest2 = $destPath . "/{$file}";

                $start = time();
                $cmd = "mv $source $dest1 && mv $dest1 $dest2";
                echo date('Y-m-d H:i:s') . ' ' . $cmd, PHP_EOL;
                $ret = System::exec($cmd);

                $cost = time() - $start;
                if ($ret['code'] == 0) {
                    $speed = round($size / pow(1024, 2) / ($cost ?: 1), 2);
                    echo date('Y-m-d H:i:s') . ' ' . $file . ' moved, time cost: ' . $cost . ' seconds, speed: ' . $speed . ' MiB/s', PHP_EOL;
                } else {
                    echo date('Y-m-d H:i:s') . ' ' . $file . ' move failed , cost: ' . $cost . ' seconds, error: ' . $ret['output'], PHP_EOL;
                }
            } catch (Throwable $throwable) {
                echo date('Y-m-d H:i:s') . ' ' . $throwable->getMessage(), PHP_EOL;
            } finally {
                unset($tasks[$v]);
                $chan->pop();
            }
        });
    }
    Barrier::wait($barrier);
}

function getList(string $file): array
{
    $list = [];
    $file = new SplFileObject($file, 'r');
    $file->setFlags(SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
    while (!$file->eof()) {
        $line = $file->fgets();
        if (empty($line)) {
            continue;
        }
        if (!is_file($line)) {
            throw new \Exception($line . ' not exists');
        }
        $list[] = $line;
    }
    return $list;
}
