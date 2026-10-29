<?php

declare(strict_types=1);

namespace Petitstats\Tests\Http;

use Petitstats\Http\Asset;
use Petitstats\Tests\PhpServer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class AssetTest extends TestCase
{
    private const APP_JS = '/dashboard/src/assets/js/app.js';

    public function testVersionsAnAssetByTheTimeItLastChanged(): void
    {
        $this->assertSame(self::APP_JS . '?v=' . filemtime(dirname(__DIR__, 3) . self::APP_JS), Asset::url(self::APP_JS));
    }

    public function testLeavesAMissingFileAlone(): void
    {
        $this->assertSame('/dashboard/src/assets/js/missing.js', Asset::url('/dashboard/src/assets/js/missing.js'));
    }

    public function testPagesLoadVersionedAssets(): void
    {
        $server = PhpServer::start(dirname(__DIR__, 3));
        try {
            $page = (string) file_get_contents($server->url . '/dashboard/onboarding.php');
        } finally {
            $server->stop();
        }

        $this->assertStringContainsString('/dashboard/src/assets/css/auth.css?v=', $page);
    }

    /** A page that links a script or stylesheet directly would keep serving it from the browser cache after an update. */
    public function testEveryPageUsesVersionedAssets(): void
    {
        $app = dirname(__DIR__, 3);
        $unversioned = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = strtr(substr($file->getPathname(), strlen($app) + 1), '\\', '/');
            if ($file->getExtension() !== 'php' || preg_match('#^(vendor|tests)/#', $path)) {
                continue;
            }
            if (preg_match('#(href|src)="/dashboard/src/assets/[^"]+\.(css|js)"#', (string) file_get_contents($file->getPathname()))) {
                $unversioned[] = $path;
            }
        }

        $this->assertSame([], $unversioned, 'Wrap these asset URLs in Petitstats\Http\Asset::url().');
    }
}
