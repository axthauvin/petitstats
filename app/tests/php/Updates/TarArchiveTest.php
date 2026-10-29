<?php

declare(strict_types=1);

namespace Petitstats\Tests\Updates;

use FilesystemIterator;
use Petitstats\Updates\TarArchive;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class TarArchiveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/petitstats-tar-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/out', 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    public function testExtractsGnuTarPathsLongerThanAHundredCharacters(): void
    {
        $long = 'vendor/' . str_repeat('a', 60) . '/' . str_repeat('b', 60) . '/File.php';

        $this->extract(UpdaterTest::tarEntry('./', '5') . UpdaterTest::tarEntry('./' . $long, '0', 'long') . UpdaterTest::tarEntry('./index.php', '0', 'short'));

        $this->assertSame('long', file_get_contents($this->dir . '/out/' . $long));
        $this->assertSame('short', file_get_contents($this->dir . '/out/index.php'));
    }

    public function testExtractsPosixTarPaths(): void
    {
        $long = 'vendor/' . str_repeat('c', 120) . '.php';
        $record = ' path=' . $long . "\n";
        $record = (strlen($record) + 3) . $record;

        $this->extract(UpdaterTest::tarEntry('PaxHeader/x', 'x', $record) . UpdaterTest::tarEntry(substr($long, 0, 100), '0', 'pax'));

        $this->assertSame('pax', file_get_contents($this->dir . '/out/' . $long));
    }

    public function testRefusesADamagedHeader(): void
    {
        $entry = UpdaterTest::tarEntry('./index.php', '0', 'x');

        $this->expectExceptionMessage('The archive is damaged.');
        $this->extract(substr_replace($entry, 'X', 2, 1));
    }

    public function testRefusesATruncatedArchive(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The archive is incomplete.');

        $this->extract(substr(UpdaterTest::tarEntry('./index.php', '0', str_repeat('x', 2000)), 0, 1024), end: false);
    }

    private function extract(string $tar, bool $end = true): void
    {
        file_put_contents($this->dir . '/a.tar.gz', gzencode($tar . ($end ? str_repeat("\0", 1024) : '')));
        TarArchive::extract($this->dir . '/a.tar.gz', $this->dir . '/out');
    }
}
