#!/usr/bin/env php
<?php
/**
 * This file is part of Swow
 *
 * @link    https://github.com/swow/swow
 * @contact twosee <twosee@php.net>
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code
 */

declare(strict_types=1);

namespace DontDie;

use InvalidArgumentException;
use RuntimeException;
use Swow\Coroutine;
use Swow\Signal;
use Swow\Sync\WaitReference;
use Swow\SyncException;

use function array_slice;
use function date;
use function extension_loaded;
use function feof;
use function file_put_contents;
use function fread;
use function fwrite;
use function getcwd;
use function getenv;
use function getopt;
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
use const STDERR;

/** @param string[] $command */
function dontDie(array $command, string $nickname = '', int $maxExecutionTime = -1): void
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
        if ($nickname === '') {
            $logFilename = $cwd . '/.dontdie.log';
        } else {
            $logFilename = $cwd . '/.dontdie.' . $nickname . '.log';
        }
        $log = static function (string|array $contents) use ($pid, $logFilename): void {
            $line =
                json_encode([
                    'pid' => $pid,
                    'time' => date('Y-m-d H:i:s'),
                    'contents' => $contents,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($logFilename, $line, FILE_APPEND);
        };
        $trace = static function (string|array $contents) use ($log, $enableTrace): void {
            if ($enableTrace) {
                $log($contents);
            }
        };
        $log('process started');
        $processInfo = ['command' => $command];
        if ($nickname !== '') {
            $processInfo['nickname'] = $nickname;
        }
        $log($processInfo);
        $wr = new WaitReference();
        $stderr = $pipes[2];
        $stderrWorker = Coroutine::run(static function () use ($stderr, $trace, $wr): void {
            $trace('redirect stderr start');
            do {
                $data = @fread($stderr, 8192);
                @fwrite(STDERR, $data, 8192);
            } while (!feof($stderr));
            $trace('redirect stderr done');
        });
        $mainCoroutine = Coroutine::getCurrent();
        $signalProxy = static function (int $signal) use ($pid, &$running, $mainCoroutine, $trace): void {
            $trace(sprintf('wait for signal %d start', $signal));
            Signal::wait($signal);
            $trace(sprintf('wait for signal %d done', $signal));
            Signal::kill($pid, $signal);
            $running = false;
            $mainCoroutine->resume();
        };
        $sigintWorker = Coroutine::run($signalProxy, Signal::INT);
        $sigtermWorker = Coroutine::run($signalProxy, Signal::TERM);

        $trace('wait for process');
        try {
            $wr::wait($wr, $maxExecutionTime);
            $trace('wait for process done');
        } catch (SyncException) {
            $trace('wait for process timeout or canceled');
            Signal::kill($status['pid'], Signal::TERM);
        }

        if ($stderrWorker->isExecuting()) {
            $stderrWorker->kill();
        }
        if ($sigintWorker->isExecuting()) {
            $sigintWorker->kill();
        }
        if ($sigtermWorker->isExecuting()) {
            $sigtermWorker->kill();
        }
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
            Signal::kill($status['pid'], Signal::KILL);
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

if (!extension_loaded('swow')) {
    throw new RuntimeException('Swow extension is required');
}

if ($argc <= 1) {
    throw new InvalidArgumentException('No command specified');
}

$options = getopt('', ['nickname:'], $restIndex);
$command = array_slice($argv, $restIndex);
dontDie(command: $command, nickname: $options['nickname'] ?? '');