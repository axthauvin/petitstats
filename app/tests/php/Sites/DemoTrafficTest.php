<?php

declare(strict_types=1);

namespace Petitstats\Tests\Sites;

use Petitstats\Database\Connections;
use Petitstats\Database\DatabaseConnection;
use Petitstats\Database\DataDirectory;
use Petitstats\Sites\DemoTraffic;
use Petitstats\Sites\SiteRegistry;
use PHPUnit\Framework\TestCase;

final class DemoTrafficTest extends TestCase
{
    private string $sites;

    protected function setUp(): void
    {
        $this->sites = (string) file_get_contents(DataDirectory::path() . '/sites.json');
        SiteRegistry::create('demo_test', 'Demo');
        SiteRegistry::update('demo_test', ['is_public' => true, 'demo_days' => 7]);
    }

    protected function tearDown(): void
    {
        DemoTraffic::clear('demo_test');
        Connections::close('demo_test');
        @unlink(DataDirectory::path() . '/demo_test.db');
        file_put_contents(DataDirectory::path() . '/sites.json', $this->sites);
    }

    public function testFillsTheWholeWindowOnTheFirstRefresh(): void
    {
        $now = time();

        $this->assertGreaterThan(100, DemoTraffic::refresh('demo_test', $now));

        $db = Connections::forSite('demo_test');
        $this->assertGreaterThanOrEqual(gmdate('Y-m-d H:i:s', $now - 7 * 86400), $db->querySingle('SELECT MIN(timestamp) FROM user_activity'));
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s', $now), $db->querySingle('SELECT MAX(timestamp) FROM user_activity'));
        $this->assertGreaterThanOrEqual(2, $this->liveVisitors($db, $now));
        $this->assertGreaterThan(0, (int) $db->querySingle('SELECT COUNT(*) FROM bot_activity'));
    }

    public function testKeepsTheWindowCurrentAsTimeGoesBy(): void
    {
        $now = time();
        DemoTraffic::refresh('demo_test', $now);
        $later = $now + 2 * 86400;

        $this->assertGreaterThan(0, DemoTraffic::refresh('demo_test', $later));

        $db = Connections::forSite('demo_test');
        $this->assertGreaterThanOrEqual(gmdate('Y-m-d H:i:s', $later - 7 * 86400), $db->querySingle('SELECT MIN(timestamp) FROM user_activity'));
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s', $later - 86400), $db->querySingle('SELECT MAX(timestamp) FROM user_activity'));
        $this->assertGreaterThanOrEqual(2, $this->liveVisitors($db, $later));
    }

    public function testRefreshesAtMostOncePerMinute(): void
    {
        $now = time();
        DemoTraffic::refresh('demo_test', $now);

        $this->assertSame(0, DemoTraffic::refresh('demo_test', $now + 30));
    }

    public function testLeavesOtherWebsitesAlone(): void
    {
        $this->assertSame(0, DemoTraffic::refresh('test_site'));
        $this->assertSame(0, (int) Connections::forSite('test_site')->querySingle("SELECT COUNT(*) FROM user_activity WHERE visitor_id LIKE 'vid_%'"));
    }

    public function testTurningTheDemoOffRemovesTheSetting(): void
    {
        SiteRegistry::update('demo_test', ['demo_days' => 0]);

        $this->assertArrayNotHasKey('demo_days', SiteRegistry::find('demo_test'));
        $this->assertSame(0, DemoTraffic::refresh('demo_test'));
    }

    private function liveVisitors(DatabaseConnection $db, int $now): int
    {
        return (int) $db->querySingle("SELECT COUNT(DISTINCT session_id) FROM user_activity WHERE timestamp >= '" . gmdate('Y-m-d H:i:s', $now - 300) . "'");
    }
}
