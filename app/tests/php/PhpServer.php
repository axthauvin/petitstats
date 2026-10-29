<?php

declare(strict_types=1);

namespace Petitstats\Tests;

use RuntimeException;

/** PHP's built-in server, for tests that send real HTTP requests. */
final class PhpServer
{
    /** @param resource $process */
    private function __construct(private $process, public readonly string $url) {}

    /** @param array<string, string>|null $env the environment of the server, the test's own by default */
    public static function start(string $docroot, ?array $env = null): self
    {
        $port = self::freePort();
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot], [['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $pipes, null, $env ?? getenv());
        if ($process === false) {
            throw new RuntimeException('Could not start the PHP built-in server.');
        }
        $server = new self($process, "http://127.0.0.1:{$port}");
        for ($i = 0; $i < 50; $i++) {
            if ($socket = @fsockopen('127.0.0.1', $port)) {
                fclose($socket);
                return $server;
            }
            usleep(100_000);
        }
        $server->stop();
        throw new RuntimeException('The PHP built-in server did not start.');
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        return $port;
    }
}
