<?php

declare(strict_types=1);

namespace Petitstats\Tests\Analytics;

use Petitstats\Analytics\Period;
use Petitstats\Analytics\SiteAnalytics;
use Petitstats\Database\Connections;
use PHPUnit\Framework\TestCase;

final class SiteAnalyticsTest extends TestCase
{
    private int $now;

    protected function setUp(): void
    {
        $this->now = time();
        Connections::forSite('test_site')->exec('DELETE FROM user_activity');
        Connections::forSite('test_site')->exec('DELETE FROM bot_activity');
    }

    public function testSummaryCountsVisitorsSessionsAndPageviews(): void
    {
        $this->record('s1', 'v1', 'pageview', ['path' => '/'], 3600);
        $this->record('s1', 'v1', 'pageview', ['path' => '/pricing'], 3500);
        $this->record('s2', 'v1', 'pageview', ['path' => '/'], 1800);
        $this->record('s3', 'v2', 'signup', [], 600);

        $summary = $this->analytics('7d')->summary();

        $this->assertSame(2, $summary['visitors']);
        $this->assertSame(3, $summary['sessions']);
        $this->assertSame(3, $summary['pageviews']);
        $this->assertSame(4, $summary['events']);
    }

    public function testVisitorsSeenInAnEarlierVisitAreReturning(): void
    {
        $this->record('earlier', 'v1', 'pageview', ['path' => '/'], 9 * 86400);
        $this->record('again', 'v1', 'pageview', ['path' => '/'], 3600);
        $this->record('first', 'v2', 'pageview', ['path' => '/'], 7200);
        $this->record('second', 'v2', 'pageview', ['path' => '/'], 1800);
        $this->record('once', 'v3', 'pageview', ['path' => '/'], 600);
        $this->record('gone', 'v4', 'pageview', ['path' => '/'], 9 * 86400);
        $this->record('gone_too', 'v4', 'pageview', ['path' => '/'], 8 * 86400);

        $summary = $this->analytics('7d')->summary();

        $this->assertSame(3, $summary['visitors']);
        $this->assertSame(2, $summary['returning_visitors'], 'v1 came back from an earlier period, v2 within it; v3 came once and v4 not at all.');
        $this->assertSame(1, $summary['previous']['returning_visitors']);
    }

    public function testVisitsWithASingleActionCountAsBounces(): void
    {
        $this->record('s1', 'v1', 'pageview', ['path' => '/'], 3600);
        $this->record('s1', 'v1', 'pageview', ['path' => '/pricing'], 3540);
        $this->record('s2', 'v2', 'pageview', ['path' => '/'], 600);

        $summary = $this->analytics('7d')->summary();

        $this->assertSame(50.0, $summary['bounce_rate']);
        $this->assertSame(30.0, $summary['avg_duration_seconds']);
    }

    public function testEventsOutsideThePeriodAreIgnored(): void
    {
        $this->record('old', 'v1', 'pageview', ['path' => '/'], 10 * 86400);
        $this->record('new', 'v2', 'pageview', ['path' => '/'], 3600);

        $this->assertSame(1, $this->analytics('7d')->summary()['visitors']);
    }

    public function testSummaryComparesWithThePreviousPeriod(): void
    {
        $this->record('before', 'v1', 'pageview', ['path' => '/'], 8 * 86400);
        $this->record('now1', 'v2', 'pageview', ['path' => '/'], 3600);
        $this->record('now2', 'v3', 'pageview', ['path' => '/'], 1800);

        $this->assertSame('+1', $this->analytics('7d')->summary()['deltas']['visitors']);
    }

    public function testTopEventsLeaveOutTheTrackersInternalSignals(): void
    {
        $this->record('s1', 'v1', '_ml_engaged', ['path' => '/'], 3600);
        $this->record('s1', 'v1', 'signup', ['path' => '/'], 3500);
        // "_" is a single-character wildcard in LIKE: "xml_export" must not pass for internal.
        $this->record('s1', 'v1', 'xml_export', ['path' => '/'], 3400);

        $events = $this->analytics('7d')->topEvents();

        $this->assertEqualsCanonicalizing(['signup', 'xml_export'], array_column($events, 'name'));
        $this->assertSame([50.0, 50.0], array_column($events, 'percentage'));
    }

    public function testInternalSignalsDoNotSaveAVisitFromBouncing(): void
    {
        $this->record('engaged', 'v1', 'pageview', ['path' => '/'], 3600);
        $this->record('engaged', 'v1', '_ml_engaged', ['path' => '/'], 3590);
        $this->record('exported', 'v2', 'pageview', ['path' => '/'], 1800);
        $this->record('exported', 'v2', 'xml_export', ['path' => '/'], 1790);

        $this->assertSame(50.0, $this->analytics('7d')->summary()['bounce_rate']);
    }

    public function testTopPagesAreSortedByViews(): void
    {
        $this->record('s1', 'v1', 'pageview', ['path' => '/pricing', 'title' => 'Pricing'], 3600);
        $this->record('s2', 'v2', 'pageview', ['path' => '/pricing', 'title' => 'Pricing'], 3000);
        $this->record('s2', 'v2', 'pageview', ['path' => '/'], 2900);

        $pages = $this->analytics('7d')->topPages();

        $this->assertSame(['/pricing', '/'], array_column($pages, 'path'));
        $this->assertSame('Pricing', $pages[0]['title']);
        $this->assertSame(2, $pages[0]['views']);
        $this->assertSame(66.7, $pages[0]['percentage']);
    }

    public function testReferrersCountEachVisitOnceFromItsFirstPageview(): void
    {
        $this->record('s1', 'v1', 'pageview', ['path' => '/', 'referrer' => 'google.com'], 3600);
        $this->record('s1', 'v1', 'pageview', ['path' => '/pricing', 'referrer' => 'google.com'], 3500);
        $this->record('s1', 'v1', 'pageview', ['path' => '/about', 'referrer' => 'google.com'], 3400);
        $this->record('s2', 'v2', 'pageview', ['path' => '/', 'referrer' => 'news.ycombinator.com'], 1800);
        $this->record('s3', 'v3', 'pageview', ['path' => '/'], 900);
        $this->record('s3', 'v3', 'pageview', ['path' => '/pricing', 'referrer' => 'google.com'], 800);

        $referrers = $this->analytics('7d')->topReferrers();

        $this->assertEquals(['google.com' => 1, 'news.ycombinator.com' => 1, 'direct' => 1], array_column($referrers, 'visits', 'domain'));
        $this->assertSame([33.3, 33.3, 33.3], array_column($referrers, 'percentage'));
    }

    public function testBotActivityIsSummarisedByReasonAndAgent(): void
    {
        $this->recordBot('known_bot_user_agent', 'Googlebot/2.1', 'ip-1', 3600);
        $this->recordBot('known_bot_user_agent', 'Googlebot/2.1', 'ip-2', 1800);
        $this->recordBot('webdriver', 'Mozilla/5.0', 'ip-1', 600);
        $this->recordBot('webdriver', 'Old', 'ip-3', 10 * 86400);

        $bots = $this->analytics('7d')->botActivity();

        $this->assertSame(3, $bots['total']);
        $this->assertSame([['reason' => 'known_bot_user_agent', 'count' => 2], ['reason' => 'webdriver', 'count' => 1]], $bots['reasons']);
        $this->assertCount(3, $bots['recent']);
    }

    public function testBotActivityIsGroupedByRobotAndCategory(): void
    {
        $chrome = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
        $this->recordBot('known_bot_user_agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'ip-1', 3600);
        $this->recordBot('known_bot_user_agent', 'Googlebot-Image/1.0', 'ip-2', 1800);
        $this->recordBot('known_bot_user_agent', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot)', 'ip-3', 1800);
        $this->recordBot('client:platform_mismatch', $chrome, 'ip-4', 600, 'DE');
        $this->recordBot('internal_traffic', $chrome, 'ip-5', 600);
        $this->recordBot('client:no_interaction', $chrome, 'ip-6', 300);

        $bots = $this->analytics('7d')->botActivity();

        $this->assertSame(1, $bots['internal']);
        $this->assertSame(1, $bots['left']);
        $this->assertSame(['search' => 2, 'automated' => 1, 'other' => 1], array_column($bots['categories'], 'count', 'category'));
        $this->assertSame([['reason' => 'client:platform_mismatch', 'count' => 1]], $bots['categories'][1]['reasons']);
        $this->assertSame(['Google' => 2, 'ChatGPT' => 1, 'Chrome · Linux' => 1], array_column($bots['bots'], 'count', 'name'));
        $this->assertSame(4, array_sum(array_column($bots['series'], 'count')));
        $this->assertSame('Chrome · Linux', $bots['recent'][2]['bot']['name']);
        $this->assertSame('DE', $bots['recent'][2]['country_code']);
        $this->assertNull($bots['recent'][0]['bot']);
        $this->assertNull($bots['recent'][1]['bot']);
    }

    public function testRevenueAddsUpOrdersAndCountsAnOrderIdOnce(): void
    {
        $this->record('s1', 'v1', 'pageview', ['path' => '/shop'], 3600);
        $this->record('s1', 'v1', 'purchase', ['_ml_revenue' => 40, 'currency' => 'eur', 'order_id' => 'A1'], 3500);
        $this->record('s1', 'v1', 'purchase', ['_ml_revenue' => 40, 'currency' => 'EUR', 'order_id' => 'A1'], 3400);
        $this->record('s2', 'v2', 'pageview', ['path' => '/'], 1800);
        $this->record('s2', 'v2', 'purchase', ['_ml_revenue' => '20.5'], 1700);
        $this->record('s3', 'v3', 'pageview', ['path' => '/'], 900);
        $this->record('s3', 'v3', 'signup', ['revenue' => 75], 800);

        $revenue = $this->analytics('7d')->revenue();

        $this->assertSame('EUR', $revenue['currency']);
        $this->assertSame(60.5, $revenue['summary']['revenue'], 'The reloaded A1 counts once, an order without currency counts in EUR, a plain revenue property is not money.');
        $this->assertSame(2, $revenue['summary']['orders']);
        $this->assertSame(30.25, $revenue['summary']['average_order']);
        $this->assertSame(66.67, $revenue['summary']['conversion_rate'], '2 of the 3 visitors bought.');
        $this->assertSame(60.5, array_sum(array_column($revenue['timeseries'], 'revenue')));
    }

    public function testRevenueOnlyAddsUpTheMainCurrency(): void
    {
        $this->record('s1', 'v1', 'purchase', ['_ml_revenue' => 10, 'currency' => 'EUR'], 3600);
        $this->record('s2', 'v2', 'purchase', ['_ml_revenue' => 15, 'currency' => 'EUR'], 1800);
        $this->record('s3', 'v3', 'purchase', ['_ml_revenue' => 99, 'currency' => 'USD'], 600);

        $revenue = $this->analytics('7d')->revenue();

        $this->assertSame(25.0, $revenue['summary']['revenue']);
        $this->assertSame([['currency' => 'USD', 'orders' => 1]], $revenue['other_currencies']);
    }

    public function testRevenueIsAttributedToTheSessionsFirstPageview(): void
    {
        $this->record('s1', 'v1', 'pageview', ['path' => '/blog', 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'launch'], 3600);
        $this->record('s1', 'v1', 'pageview', ['path' => '/checkout'], 3500);
        $this->record('s1', 'v1', 'purchase', ['_ml_revenue' => 30, 'path' => '/checkout', 'country' => 'France'], 3400);
        $this->record('s2', 'v2', 'pageview', ['path' => '/before'], 9 * 86400);
        $this->record('s2', 'v2', 'purchase', ['_ml_revenue' => 5], 8 * 86400);

        $revenue = $this->analytics('7d')->revenue();

        $this->assertSame([['revenue' => 30.0, 'name' => 'launch', 'orders' => 1]], $revenue['breakdowns']['campaigns']);
        $this->assertSame('Email', $revenue['breakdowns']['channels'][0]['name']);
        $this->assertSame('/blog', $revenue['breakdowns']['landing_pages'][0]['name']);
        $this->assertSame('France', $revenue['breakdowns']['countries'][0]['name']);
        $this->assertSame(5.0, $revenue['summary']['previous']['revenue']);
    }

    private function analytics(string $range): SiteAnalytics
    {
        return SiteAnalytics::open('test_site', Period::from($range, now: $this->now));
    }

    /** Stores an event the way track.php does, `$secondsAgo` before now. */
    private function record(string $sessionId, string $visitorId, string $name, array $data, int $secondsAgo): void
    {
        $stmt = Connections::forSite('test_site')->prepare('INSERT INTO user_activity (session_id, visitor_id, action, timestamp) VALUES (:session, :visitor, :action, :timestamp)');
        $stmt->bindValue(':session', $sessionId, SQLITE3_TEXT);
        $stmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        $stmt->bindValue(':action', (string) json_encode(['name' => $name, 'data' => $data]), SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', gmdate('Y-m-d H:i:s', $this->now - $secondsAgo), SQLITE3_TEXT);
        $stmt->execute();
    }

    private function recordBot(string $reason, string $userAgent, string $ip, int $secondsAgo, ?string $country = null): void
    {
        $stmt = Connections::forSite('test_site')->prepare('INSERT INTO bot_activity (reason, user_agent, origin, ip_hash, country_code, timestamp) VALUES (:reason, :ua, :origin, :ip, :country, :timestamp)');
        $stmt->bindValue(':country', $country, $country === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
        $stmt->bindValue(':ua', $userAgent, SQLITE3_TEXT);
        $stmt->bindValue(':origin', 'example.com', SQLITE3_TEXT);
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', gmdate('Y-m-d H:i:s', $this->now - $secondsAgo), SQLITE3_TEXT);
        $stmt->execute();
    }
}
