<?php

declare(strict_types=1);

namespace Petitstats\Tests\Updates;

use Closure;
use FilesystemIterator;
use Petitstats\Updates\Updater;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Updates an install from v1.0.0 to v2.0.0, built like scripts/build-archive.sh
 * builds releases, and breaks the update at every step to check that the
 * installed files always end up either fully updated or exactly as before.
 */
final class UpdaterTest extends TestCase
{
    private const INSTALLED = [
        '.htaccess' => 'v1 rules',
        'index.php' => 'v1 index',
        'src/Shared.php' => 'v1 shared',
        'src/Removed.php' => 'dropped in v2',
        'vendor/lib/Removed/Gone.php' => 'dropped in v2 with its folder',
        'vendor/lib/a.php' => 'v1 library',
    ];
    private const RELEASE = [
        '.htaccess' => 'v2 rules',
        'index.php' => 'v2 index',
        'src/New/Feature.php' => 'added in v2',
        'src/Shared.php' => 'v2 shared',
        'vendor/lib/a.php' => 'v2 library',
    ];

    private const CHILD_SCRIPT = <<<'PHP'
        <?php
        require $argv[1];
        [, , $app, $work, $halfway, $mode] = $argv;
        $stop = static function (string $path) use ($halfway, $mode): void {
            if ($path !== 'src/Shared.php') {
                return;
            }
            if ($mode === 'exit') {
                exit(1);
            }
            if ($mode === 'hang') {
                file_put_contents($halfway, 'yes');
                sleep(60);
            }
        };
        $updater = new Petitstats\Updates\Updater($app, $work, static fn() => null, static fn() => null, $stop);
        $updater->install();

        PHP;

    private string $root;
    private string $app;
    /** @var array<string, string> what GitHub serves, by URL */
    private array $remote = [];
    /** @var Closure(string): ?string */
    private Closure $health;
    private Updater $updater;
    /** @var array<string, string> */
    private array $before;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/petitstats-updater-' . bin2hex(random_bytes(4));
        $this->app = $this->root . '/app';
        foreach (self::INSTALLED + ['src/VERSION' => "v1.0.0\n"] as $path => $content) {
            $this->write($path, $content);
        }
        $this->write('src/MANIFEST', self::manifest(self::INSTALLED + ['src/VERSION' => "v1.0.0\n"]));
        $this->write('index.html', 'A landing page that is not part of Petitstats');
        $this->before = $this->snapshot();

        $this->release('v2.0.0', self::RELEASE);
        // A working server: the new files answer with the version they install.
        $this->health = function (string $token): ?string {
            $this->assertTrue($this->updater->isUnderMaintenance());
            $this->assertTrue($this->updater->acceptsHealthCheck($token));
            $this->assertFalse($this->updater->acceptsHealthCheck('another-token'));
            return trim((string) file_get_contents($this->app . '/src/VERSION'));
        };
        $this->updater = $this->updater();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            @chmod($item->getPathname(), 0755);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testInstallsTheNewReleaseFileByFile(): void
    {
        $this->update();

        $expected = self::RELEASE + [
            'src/VERSION' => "v2.0.0\n",
            'src/MANIFEST' => self::manifest(self::RELEASE + ['src/VERSION' => "v2.0.0\n"]),
            'index.html' => 'A landing page that is not part of Petitstats',
        ];
        ksort($expected);
        $this->assertSame($expected, $this->snapshot());
        $this->assertDirectoryDoesNotExist($this->app . '/vendor/lib/Removed');
        $this->assertEquals(['status' => 'installed', 'from' => 'v1.0.0', 'to' => 'v2.0.0', 'verified' => true, 'previous' => 'v1.0.0'], $this->updater->status());
        $this->assertFalse($this->updater->isUnderMaintenance());
        $this->assertDirectoryDoesNotExist($this->root . '/data/updates/staging');
    }

    /** Release tags look like "v1.1.2-beta". */
    public function testInstallsABetaRelease(): void
    {
        $this->release('v2.0.0-beta', self::RELEASE);

        $this->updater->download('v2.0.0-beta');
        $this->updater->backup();
        $this->updater->install();

        $this->assertSame("v2.0.0-beta\n", file_get_contents($this->app . '/src/VERSION'));
    }

    public function testRollbackBringsBackThePreviousVersion(): void
    {
        $this->update();
        $this->updater->rollback();

        $this->assertSame($this->before, $this->snapshot());
        $this->assertEquals(['status' => 'rolled_back', 'from' => 'v2.0.0', 'to' => 'v1.0.0', 'previous' => null], $this->updater->status());
    }

    public function testRefusesAnArchiveWhoseChecksumDoesNotMatch(): void
    {
        $this->remote[sprintf(Updater::DOWNLOAD_URL, 'v2.0.0') . '.sha256'] = str_repeat('0', 64) . "  petitstats.tar.gz\n";

        $this->assertUpdateFails('SHA-256 does not match');
        $this->assertSame('idle', $this->updater->status()['status']);
    }

    public function testRefusesAnArchiveWithADamagedFile(): void
    {
        $this->release('v2.0.0', self::RELEASE, tampered: ['src/Shared.php' => 'changed after the release was built']);

        $this->assertUpdateFails('src/Shared.php in the archive is missing or damaged');
    }

    public function testRefusesAnArchiveWithAFileMissingFromItsList(): void
    {
        $this->release('v2.0.0', self::RELEASE, extra: self::tarEntry('./src/Unlisted.php', '0', 'not in the manifest'));

        $this->assertUpdateFails('src/Unlisted.php, which is missing from its file list');
    }

    public function testRefusesAnArchiveWritingOutsideItsFolder(): void
    {
        $this->release('v2.0.0', self::RELEASE, extra: self::tarEntry('../../outside.php', '0', 'escaped'));

        $this->assertUpdateFails('unsafe path');
        $this->assertFileDoesNotExist($this->root . '/outside.php');
    }

    public function testRefusesAnArchiveWithALink(): void
    {
        $this->release('v2.0.0', self::RELEASE, extra: self::tarEntry('./src/link.php', '2'));

        $this->assertUpdateFails('not a regular file');
    }

    public function testRefusesAnArchiveOfAnotherVersion(): void
    {
        $this->release('v2.0.0', self::RELEASE, version: 'v1.9.0');

        $this->assertUpdateFails('contains version "v1.9.0" instead of v2.0.0');
    }

    public function testRestoresTheBackupWhenAFileCannotBeReplaced(): void
    {
        $this->updater = $this->updater(static function (string $path): void {
            if ($path === 'src/Shared.php') {
                throw new RuntimeException('Disk full.');
            }
        });

        $this->assertUpdateFails('The update failed, so Petitstats went back to v1.0.0. Disk full.');
        $this->assertEquals(['status' => 'failed', 'from' => 'v1.0.0', 'to' => 'v2.0.0', 'error' => 'Disk full.', 'previous' => null], $this->updater->status());
        $this->assertFalse($this->updater->isUnderMaintenance());
    }

    public function testRestoresTheBackupWhenTheNewVersionDoesNotStart(): void
    {
        $this->health = static fn(): ?string => throw new RuntimeException('The new version did not start correctly (HTTP 500).');
        $this->updater = $this->updater();

        $this->assertUpdateFails('went back to v1.0.0. The new version did not start correctly (HTTP 500).');
    }

    public function testRestoresTheBackupWhenTheServerStillRunsTheOldVersion(): void
    {
        $this->health = static fn(): ?string => 'v1.0.0';
        $this->updater = $this->updater();

        $this->assertUpdateFails('the server still reports v1.0.0');
    }

    public function testKeepsTheUpdateWhenTheServerCannotReachItself(): void
    {
        $this->health = static fn(): ?string => null;
        $this->updater = $this->updater();

        $this->update();

        $this->assertEquals(['status' => 'installed', 'from' => 'v1.0.0', 'to' => 'v2.0.0', 'verified' => false, 'previous' => 'v1.0.0'], $this->updater->status());
    }

    /** PHP stops on its own (a timeout, a fatal error, exit): its shutdown function puts the backup back. */
    public function testRestoresTheBackupWhenPhpStopsHalfway(): void
    {
        $this->updater->download('v2.0.0');
        $this->updater->backup();

        $this->waitFor($this->installInChildProcess('exit'));

        $this->assertSame($this->before, $this->snapshot());
        $this->assertSame('failed', $this->updater->status()['status']);
        $this->assertSame('PHP stopped before the update finished.', $this->updater->status()['error'] ?? null);
        $this->assertFalse($this->updater->isUnderMaintenance());
    }

    /** A killed process runs nothing more: the next request finds the update unfinished and puts the backup back. */
    public function testTheNextRequestRestoresTheBackupWhenPhpIsKilledHalfway(): void
    {
        $this->updater->download('v2.0.0');
        $this->updater->backup();

        $process = $this->installInChildProcess('hang');
        $deadline = microtime(true) + 20;
        while (!is_file($this->root . '/halfway') && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertFileExists($this->root . '/halfway');

        // While the update runs, requests are turned away and nothing is restored.
        $this->assertTrue($this->updater->isUnderMaintenance());
        $this->assertFalse($this->updater->recoverInterruptedUpdate());

        proc_terminate($process, 9);
        $this->waitFor($process);
        $this->assertNotSame($this->before, $this->snapshot());
        $this->assertSame('installing', $this->updater->status()['status']);

        $this->assertTrue($this->updater->recoverInterruptedUpdate());
        $this->assertSame($this->before, $this->snapshot());
        $this->assertSame('The update was interrupted before it finished.', $this->updater->status()['error'] ?? null);
        $this->assertFalse($this->updater->isUnderMaintenance());
    }

    /** Without any request in between to notice it, the next update step finishes the work of the killed process. */
    public function testTheNextStepRestoresTheBackupWhenPhpIsKilledHalfway(): void
    {
        $this->updater->download('v2.0.0');
        $this->updater->backup();
        $process = $this->installInChildProcess('hang');
        $deadline = microtime(true) + 20;
        while (!is_file($this->root . '/halfway') && microtime(true) < $deadline) {
            usleep(20_000);
        }
        proc_terminate($process, 9);
        $this->waitFor($process);
        unlink($this->root . '/data/updates/maintenance');

        $this->updater->download('v2.0.0');

        $this->assertSame($this->before, $this->snapshot());
        $this->assertSame('downloaded', $this->updater->status()['status']);
    }

    /** The script running the update is one of the files it replaces, like dashboard/src/api/update.php. */
    public function testReplacesTheScriptRunningTheUpdate(): void
    {
        $this->write('runner.php', self::CHILD_SCRIPT);
        $this->write('src/MANIFEST', self::manifest(self::INSTALLED + ['runner.php' => self::CHILD_SCRIPT, 'src/VERSION' => "v1.0.0\n"]));
        $this->release('v2.0.0', self::RELEASE + ['runner.php' => self::CHILD_SCRIPT . "// v2\n"]);
        $this->updater->download('v2.0.0');
        $this->updater->backup();

        $this->waitFor($this->installInChildProcess('finish', $this->app . '/runner.php'));

        $this->assertSame('installed', $this->updater->status()['status'], (string) file_get_contents($this->root . '/child.log'));
        $this->assertSame(self::CHILD_SCRIPT . "// v2\n", file_get_contents($this->app . '/runner.php'));
    }

    public function testExplainsWhichFilesPhpCannotChange(): void
    {
        chmod($this->app . '/src/Shared.php', 0444);
        clearstatcache();
        if (is_writable($this->app . '/src/Shared.php')) {
            $this->markTestSkipped('This user can write to read-only files.');
        }

        $this->assertSame(['PHP is not allowed to change src/Shared.php.'], $this->updater->problems());
        $this->assertUpdateFails('PHP is not allowed to change src/Shared.php.');
    }

    public function testADevelopmentCheckoutCannotUpdateItself(): void
    {
        unlink($this->app . '/src/VERSION');

        $this->assertSame(['This copy runs from the source code, so update it with Git instead.'], $this->updater->problems());
    }

    public function testStepsRunInOrder(): void
    {
        $this->expectExceptionMessage('Download the new version first.');

        $this->updater->backup();
    }

    private function update(): void
    {
        $this->updater->download('v2.0.0');
        $this->updater->backup();
        $this->updater->install();
    }

    /** The update stops with `$message`, and the installed files are exactly as before. */
    private function assertUpdateFails(string $message): void
    {
        try {
            $this->update();
            $this->fail('The update did not fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame($this->before, $this->snapshot());
    }

    /** @param (Closure(string): void)|null $afterEachFile */
    private function updater(?Closure $afterEachFile = null): Updater
    {
        $download = function (string $url, string $target): void {
            if (!isset($this->remote[$url])) {
                throw new RuntimeException('Not found: ' . $url);
            }
            file_put_contents($target, $this->remote[$url]);
        };
        return new Updater($this->app, $this->root . '/data/updates', $download, fn(string $token): ?string => ($this->health)($token), $afterEachFile);
    }

    /**
     * Runs install() in another PHP process, from CHILD_SCRIPT. The process
     * stops when it reaches src/Shared.php: it exits ("exit") or waits to be
     * killed ("hang"). With "finish", it installs everything.
     *
     * @return resource
     */
    private function installInChildProcess(string $mode, ?string $script = null)
    {
        $script ??= $this->root . '/install.php';
        if (!is_file($script)) {
            file_put_contents($script, self::CHILD_SCRIPT);
        }
        $command = [PHP_BINARY, $script, dirname(__DIR__, 3) . '/vendor/autoload.php', $this->app, $this->root . '/data/updates', $this->root . '/halfway', $mode];
        $process = proc_open($command, [1 => ['file', $this->root . '/child.log', 'w'], 2 => ['file', $this->root . '/child.log', 'a']], $pipes);
        $this->assertIsResource($process);
        return $process;
    }

    /** @param resource $process */
    private function waitFor($process): void
    {
        while (proc_get_status($process)['running']) {
            usleep(20_000);
        }
        proc_close($process);
    }

    /**
     * Publishes a release the way the release workflow does: a .tar.gz with
     * "./" paths, its src/MANIFEST, and a .sha256 file next to it.
     *
     * @param array<string, string> $files
     * @param array<string, string> $tampered files changed after the manifest was written
     */
    private function release(string $tag, array $files, array $tampered = [], string $extra = '', ?string $version = null): void
    {
        $files['src/VERSION'] = ($version ?? $tag) . "\n";
        $files['src/MANIFEST'] = self::manifest($files);
        ksort($files);
        $tar = self::tarEntry('./', '5') . self::tarEntry('./data/', '5');
        foreach ($tampered + $files as $path => $content) {
            $tar .= self::tarEntry('./' . $path, '0', $content);
        }
        $archive = (string) gzencode($tar . $extra . str_repeat("\0", 1024));
        $url = sprintf(Updater::DOWNLOAD_URL, $tag);
        $this->remote[$url] = $archive;
        $this->remote[$url . '.sha256'] = hash('sha256', $archive) . "  petitstats.tar.gz\n";
    }

    /** @param array<string, string> $files */
    private static function manifest(array $files): string
    {
        ksort($files, SORT_STRING);
        $lines = '';
        foreach ($files as $path => $content) {
            $lines .= hash('sha256', $content) . '  ' . $path . "\n";
        }
        return $lines;
    }

    /** One entry of a tar archive, in the GNU format of the release workflow. */
    public static function tarEntry(string $name, string $type, string $content = ''): string
    {
        $entry = '';
        if (strlen($name) > 100) {
            $entry = self::tarEntry('././@LongLink', 'L', $name . "\0");
            $name = substr($name, 0, 100);
        }
        $header = pack('a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12', $name, '0000644', '0001750', '0001750', sprintf('%011o', strlen($content)), sprintf('%011o', 0), '', $type, '', 'ustar ', ' ', 'runner', 'runner', '', '', '', '');
        $header = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $header = substr_replace($header, sprintf('%06o', array_sum((array) unpack('C*', $header))) . "\0 ", 148, 8);
        return $entry . $header . str_pad($content, (int) ceil(strlen($content) / 512) * 512, "\0");
    }

    private function write(string $path, string $content): void
    {
        $file = $this->app . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $content);
    }

    /** @return array<string, string> every file of the install, by path */
    private function snapshot(): array
    {
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->app, FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[strtr(substr($file->getPathname(), strlen($this->app) + 1), '\\', '/')] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);
        return $files;
    }
}
