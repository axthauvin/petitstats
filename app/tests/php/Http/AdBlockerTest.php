<?php

declare(strict_types=1);

namespace Petitstats\Tests\Http;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ad blockers load EasyPrivacy, which blocks every URL containing "/event" or
 * "/track" on hosts named analytics.* or tracking.* (rules such as
 * "://analytics.*\/event"). The dashboard is often served from such a host, so
 * its own scripts and API endpoints must avoid these words at the start of a
 * file or folder name.
 */
final class AdBlockerTest extends TestCase
{
    public function testDashboardFilesAreNotBlockedOnAnAnalyticsDomain(): void
    {
        $app = dirname(__DIR__, 3);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app . '/dashboard', RecursiveDirectoryIterator::SKIP_DOTS));
        $blocked = [];
        foreach ($files as $file) {
            $path = substr(str_replace('\\', '/', $file->getPathname()), strlen($app));
            if (preg_match('#/(event|track)#i', $path)) {
                $blocked[] = $path;
            }
        }

        $this->assertSame([], $blocked, 'Ad blockers block these URLs when Petitstats runs on analytics.* or tracking.*.');
    }
}
