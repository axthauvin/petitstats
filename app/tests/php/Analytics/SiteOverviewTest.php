<?php

declare(strict_types=1);

namespace Petitstats\Tests\Analytics;

use Petitstats\Analytics\SiteOverview;
use Petitstats\Database\Connections;
use PHPUnit\Framework\TestCase;

final class SiteOverviewTest extends TestCase
{
    private int $now;

    protected function setUp(): void
    {
        $this->now = time();
        Connections::forSite('test_site')->exec('DELETE FROM user_activity');
    }

    protected function tearDown(): void
    {
        Connections::forSite('test_site')->exec('DELETE FROM user_activity');
    }

    public function testCountsVisitorsRatherThanSessions(): void
    {
        $this->record('s1', 'v1', 'pageview', 60);
        $this->record('s2', 'v1', 'pageview', 120);
        $this->record('s3', 'v2', 'signup', 2 * 86400);
        $this->record('s4', 'v3', 'pageview', 30 * 86400);

        $site = $this->testSite();

        $this->assertSame(3, $site['pageviews']);
        $this->assertSame(3, $site['visitors']);
        $this->assertSame(2, $site['visitors_7d']);
        $this->assertSame(1, $site['live_visitors']);
        // One visitor a day, on the days of the two recent visits (which can fall on the same day just after midnight).
        $expected = array_fill(0, 7, 0);
        foreach ([60, 2 * 86400] as $secondsAgo) {
            $expected[6 - $this->daysAgo($secondsAgo)] = 1;
        }
        $this->assertSame($expected, $site['sparkline']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now - 60), $site['last_active']);
    }

    public function testAWebsiteWithoutVisitsShowsZeros(): void
    {
        $site = $this->testSite();

        $this->assertSame(0, $site['visitors']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $site['sparkline']);
        $this->assertNull($site['last_active']);
    }

    private function testSite(): array
    {
        foreach (SiteOverview::all() as $site) {
            if ($site['id'] === 'test_site') {
                return $site;
            }
        }
        $this->fail('test_site is missing from the overview.');
    }

    private function record(string $sessionId, string $visitorId, string $name, int $secondsAgo): void
    {
        $stmt = Connections::forSite('test_site')->prepare('INSERT INTO user_activity (session_id, visitor_id, action, timestamp) VALUES (:session, :visitor, :action, :timestamp)');
        $stmt->bindValue(':session', $sessionId, SQLITE3_TEXT);
        $stmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        $stmt->bindValue(':action', (string) json_encode(['name' => $name, 'data' => []]), SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', gmdate('Y-m-d H:i:s', $this->now - $secondsAgo), SQLITE3_TEXT);
        $stmt->execute();
    }

    /** How many UTC calendar days before today a moment `$secondsAgo` falls. */
    private function daysAgo(int $secondsAgo): int
    {
        return (int) ((strtotime(gmdate('Y-m-d', $this->now) . ' UTC') - strtotime(gmdate('Y-m-d', $this->now - $secondsAgo) . ' UTC')) / 86400);
    }
}
