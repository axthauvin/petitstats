<?php

declare(strict_types=1);

namespace Petitstats\Tests\Database;

use Petitstats\Database\DataDirectory;
use PHPUnit\Framework\TestCase;

final class DataDirectoryTest extends TestCase
{
    private string $legacy;
    private string $dir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/petitstats-migrate-' . bin2hex(random_bytes(4));
        $this->legacy = $base . '/data';
        $this->dir = $base . '/petitstats-data';
        mkdir($this->legacy, 0770, true);
        mkdir($this->dir, 0770, true);
        foreach (['site.db', 'sites.json', 'database.json', '.tracking-secret'] as $file) {
            file_put_contents($this->legacy . '/' . $file, $file);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->legacy, $this->dir] as $folder) {
            foreach (array_merge(glob($folder . '/*') ?: [], glob($folder . '/.tracking-secret') ?: []) as $file) {
                unlink($file);
            }
            rmdir($folder);
        }
        rmdir(dirname($this->legacy));
    }

    public function testMovesLegacyFilesIntoAnEmptyDataDirectory(): void
    {
        DataDirectory::migrateLegacyData($this->legacy, $this->dir);

        foreach (['site.db', 'sites.json', 'database.json', '.tracking-secret'] as $file) {
            $this->assertSame($file, file_get_contents($this->dir . '/' . $file));
            $this->assertFileDoesNotExist($this->legacy . '/' . $file);
        }
    }

    public function testLeavesAnExistingDataDirectoryAlone(): void
    {
        file_put_contents($this->dir . '/sites.json', 'current');

        DataDirectory::migrateLegacyData($this->legacy, $this->dir);

        $this->assertSame('current', file_get_contents($this->dir . '/sites.json'));
        $this->assertFileExists($this->legacy . '/site.db');
        $this->assertFileDoesNotExist($this->dir . '/site.db');
    }

    public function testDoesNothingWithoutALegacyFolder(): void
    {
        DataDirectory::migrateLegacyData($this->legacy . '-missing', $this->dir);

        $this->assertSame([], glob($this->dir . '/*'));
    }

    public function testKeepsTheFolderOfAnInstallMadeBeforeTheRename(): void
    {
        $parent = dirname($this->dir);
        $this->assertSame($parent . '/petitstats-data', DataDirectory::defaultPath($parent));

        mkdir($parent . '/minilytics-data');
        try {
            $this->assertSame($parent . '/petitstats-data', DataDirectory::defaultPath($parent), 'the new folder wins once it exists');
            rmdir($this->dir);
            $this->assertSame($parent . '/minilytics-data', DataDirectory::defaultPath($parent));
        } finally {
            rmdir($parent . '/minilytics-data');
            @mkdir($this->dir);
        }
    }
}
