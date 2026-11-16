<?php

declare(strict_types=1);

namespace Petitstats\Tests\Http;

use Petitstats\Tests\PhpServer;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard is private, so search engines must not index it. The landing
 * page, deployed next to the application, stays indexable.
 */
final class SearchEnginesTest extends TestCase
{
    private const NOINDEX = '<meta name="robots" content="noindex, nofollow">';

    public function testTheDashboardPagesAreNotIndexed(): void
    {
        $app = dirname(__DIR__, 3);
        foreach (['dashboard/index', 'layouts/auth'] as $template) {
            $this->assertStringContainsString(self::NOINDEX, (string) file_get_contents($app . '/templates/' . $template . '.php'), $template);
        }

        $server = PhpServer::start($app, null, dirname($app) . '/scripts/dev-router.php');
        try {
            $login = (string) file_get_contents($server->url . '/dashboard/login.php', false, stream_context_create(['http' => ['ignore_errors' => true]]));
            $this->assertStringContainsString(self::NOINDEX, $login);

            $landing = @file_get_contents($server->url . '/');
            if (is_string($landing) && str_contains($landing, '<html')) {
                $this->assertStringNotContainsString('noindex', $landing);
            }
        } finally {
            $server->stop();
        }
    }

    public function testApacheSendsTheNoindexHeaderForTheApplicationOnly(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 3) . '/.htaccess');

        $this->assertMatchesRegularExpression('#<FilesMatch "\\\\\.php\$">\s*Header always set X-Robots-Tag "noindex, nofollow"#', $htaccess);
    }
}
