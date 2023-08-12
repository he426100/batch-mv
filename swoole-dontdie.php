<?php

declare(strict_types=1);

namespace DontDie;

use InvalidArgumentException;
use RuntimeException;
use Swoole\Process;
use Swoole\Coroutine;
use Swoole\Coroutine\System;
use Swoole\ExitException;
use Throwable;

use function Swoole\Coroutine\run;
use function Swoole\Coroutine\go;

use function array_slice;
use function date;
use function extension_loaded;
use function file_put_contents;
use function getcwd;
use function getenv;
use function json_encode;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function sleep;
use function sprintf;
use function usleep;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

function dontDie(array $command): void
{
    $running = true;
    while (true) {
        $descriptorType = 'pipe'; /* PHP_OS_FAMILY === 'Windows' ? 'pipe' : 'pty'; */
        $proc = @proc_open(
            $command,
            [0 => ['redirect', 0], 1 => ['redirect', 1], 2 => [$descriptorType, 'w']],
            $pipes, null, null
        );
        if ($proc === false) {
            sleep(1);
            continue;
        }
        $status = proc_get_status($proc);
        $pid = $status['pid'];
        $cwd = getcwd();
        $enableTrace = getenv('DONTDIE_TRACE') === '1';
        $log = static function (string|array $contents) use ($pid, $cwd): void {
            $line =
                json_encode([
                    'pid' => $pid,
                    'time' => date('Y-m-d H:i:s'),
                    'contents' => $contents,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents(sprintf($cwd . '/.dontdie.log', $pid), $line, FILE_APPEND);
        };
        $trace = static function (string|array $contents) use ($log, $enableTrace): void {
            if ($enableTrace) {
                $log($contents);
            }
        };
        $log('process started');
        $signalProxy = static function (int $signal) use ($pid, &$running, $trace): void {
            $trace(sprintf('wait for signal %d start', $signal));
            System::waitSignal($signal);
            // 区分信号和取消协程
            if (Coroutine::isCanceled()) {
                return;
            }
            $trace(sprintf('wait for signal %d done', $signal));
            @Process::kill($pid, $signal);
            $running = false;
        };
        $sigintWorker = go($signalProxy, SIGINT);
        $sigtermWorker = go($signalProxy, SIGTERM);

        $trace('wait for process');
        try {
            $wait = System::wait();
            assert($wait['pid'] === $pid);
            $trace('wait status: ' . json_encode($wait));
            $trace('wait for process done');
        } catch (Throwable) {
            $trace('wait for process canceled');
        }

        if (Coroutine::exists($sigintWorker)) {
            Coroutine::cancel($sigintWorker);
            $trace('canceld sigint: ' . $sigintWorker);
        }
        assert(!Coroutine::exists($sigintWorker));

        if (Coroutine::exists($sigtermWorker)) {
            Coroutine::cancel($sigtermWorker);
            $trace('canceld sigterm: ' . $sigtermWorker);
        }
        assert(!Coroutine::exists($sigtermWorker));

        $trace('wait for process exit');
        for ($i = 0; $i < 110; $i++) {
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            $status = proc_get_status($proc);
            usleep(($i < 100 ? 1 : 10) * 1000);
        }
        if (!isset($exitCode)) {
            Process::kill($status['pid'], SIGKILL);
            $exitCode = -1;
            $log('process killed');
        } else {
            $log('process exited');
            $log($status);
        }
        proc_close($proc);
        if (!$running) {
            $log('process totally exited');
            exit($exitCode);
        }
        $log('process restart...');
    }
}

if (!extension_loaded('swoole')) {
    throw new RuntimeException('Swoole extension is required');
}

if ($argc <= 1) {
    throw new InvalidArgumentException('No command specified');
}

run(function () {
    try {
        dontDie([...array_slice($GLOBALS['argv'], 1)]);
    } catch (ExitException $e) {
        echo 'exit with ' . $e->getStatus(), PHP_EOL;
    }
});