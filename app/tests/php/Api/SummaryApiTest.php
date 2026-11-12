<?php

declare(strict_types=1);

namespace Petitstats\Tests\Api;

use Petitstats\Api\SummaryApi;
use Petitstats\Auth\Auth;
use Petitstats\Auth\McpTokens;
use Petitstats\Auth\SiteTokens;
use Petitstats\Database\Connections;
use Petitstats\Mcp\AnalyticsTools;
use Petitstats\Mcp\Tool;
use Petitstats\Sites\SiteRegistry;
use Petitstats\Tests\PhpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stats API, through real HTTP requests to /api/v1/sites/{site_id}/summary
 * (rewritten by the development router, like .htaccess does on Apache).
 */
final class SummaryApiTest extends TestCase
{
    private static ?PhpServer $server = null;
    private static string $token = '';
    private static string $otherToken = '';

    public static function setUpBeforeClass(): void
    {
        if (SiteRegistry::find('other_site') === null) {
            SiteRegistry::create('other_site', 'Other site', 'other.example');
        }
        self::$token = SiteTokens::create('test_site', 'Sponsor page')['token'];
        self::$otherToken = SiteTokens::create('other_site', 'Other')['token'];
        self::$server = PhpServer::start(dirname(__DIR__, 3), null, dirname(__DIR__, 4) . '/scripts/dev-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        SiteRegistry::delete('other_site');
    }

    protected function setUp(): void
    {
        $db = Connections::forSite('test_site');
        $db->exec('DELETE FROM user_activity');
        $db->exec('DELETE FROM rate_limits');
    }

    public function testRequiresAToken(): void
    {
        $response = $this->get('/api/v1/sites/test_site/summary', null);

        $this->assertSame(401, $response['status']);
        $this->assertSame('Bearer', $response['headers']['www-authenticate'] ?? null);
        $this->assertSame('no-store', $response['headers']['cache-control'] ?? null);
    }

    public function testRejectsAnUnknownOrRevokedToken(): void
    {
        $this->assertSame(401, $this->get('/api/v1/sites/test_site/summary', 'pss_' . str_repeat('0', 48))['status']);

        $revoked = SiteTokens::create('test_site', 'Revoked');
        SiteTokens::revoke('test_site', $revoked['id']);
        $this->assertSame(401, $this->get('/api/v1/sites/test_site/summary', $revoked['token'])['status']);
    }

    public function testAnAssistantTokenCannotReadTheApi(): void
    {
        $user = Auth::createUser('api-' . uniqid() . '@petitstats.test', 'Test-Passw0rd!', 'admin');
        $mcpToken = McpTokens::create($user, 'Assistant')['token'];

        $this->assertSame(401, $this->get('/api/v1/sites/test_site/summary', $mcpToken)['status']);
    }

    public function testATokenOnlyReadsItsOwnWebsite(): void
    {
        $this->assertSame(200, $this->get('/api/v1/sites/test_site/summary')['status']);
        $this->assertSame(403, $this->get('/api/v1/sites/other_site/summary')['status']);
        $this->assertSame(403, $this->get('/api/v1/sites/test_site/summary', self::$otherToken)['status']);
        $this->assertSame(200, $this->get('/api/v1/sites/other_site/summary', self::$otherToken)['status']);
    }

    public function testAnUnknownWebsiteIsNotFound(): void
    {
        $this->assertSame(404, $this->get('/api/v1/sites/nope/summary')['status']);
    }

    public function testTokensDisappearWithTheirWebsite(): void
    {
        SiteRegistry::create('short_lived', 'Short lived');
        $token = SiteTokens::create('short_lived', 'Gone soon')['token'];
        SiteRegistry::delete('short_lived');
        SiteRegistry::create('short_lived', 'Same ID, new website');

        $this->assertNull(SiteTokens::authenticate($token));
        SiteRegistry::delete('short_lived');
    }

    #[DataProvider('invalidPeriods')]
    public function testRejectsAnInvalidPeriod(string $query): void
    {
        $response = $this->get('/api/v1/sites/test_site/summary?' . $query);

        $this->assertSame(400, $response['status'], $query);
        $this->assertIsString($response['body']['error'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPeriods(): iterable
    {
        yield 'from without to' => ['from=2026-01-01'];
        yield 'not a date' => ['from=yesterday&to=today'];
        yield 'impossible date' => ['from=2026-02-30&to=2026-03-02'];
        yield 'reversed' => ['from=2026-03-10&to=2026-03-01'];
        yield 'over 90 days' => ['from=2026-01-01&to=2026-04-01'];
        yield 'in the future' => ['from=' . gmdate('Y-m-d') . '&to=' . gmdate('Y-m-d', time() + 86400)];
    }

    public function testAcceptsAPeriodOf90Days(): void
    {
        $response = $this->get('/api/v1/sites/test_site/summary?from=' . self::day(-90) . '&to=' . self::day(-1));

        $this->assertSame(200, $response['status']);
    }

    public function testAnswersWithTheSummaryAndCachingHeaders(): void
    {
        $this->record('s1', 'v1', 'pageview', self::day(-2) . ' 10:00:00');
        $this->record('s1', 'v1', 'pageview', self::day(-2) . ' 10:01:00');
        $this->record('s1', 'v1', 'pageview', self::day(-2) . ' 10:02:00');
        $this->record('s2', 'v2', 'pageview', self::day(-1) . ' 09:00:00');

        $response = $this->get('/api/v1/sites/test_site/summary');
        $body = $response['body'];

        $this->assertSame(200, $response['status']);
        $this->assertSame('private, max-age=300', $response['headers']['cache-control'] ?? null);
        $this->assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '');
        $this->assertSame(['site_id' => 'test_site', 'from' => self::day(-2), 'to' => self::day(-1), 'visitors' => 2, 'visits' => 2, 'pageviews' => 4, 'pages_per_visit' => 2.0, 'avg_duration_seconds' => 60, 'bounce_rate' => 0.5], array_intersect_key($body, array_flip(['site_id', 'from', 'to', 'visitors', 'visits', 'pageviews', 'pages_per_visit', 'avg_duration_seconds', 'bounce_rate'])));
        $this->assertSame([['date' => self::day(-2), 'visitors' => 1, 'pageviews' => 3], ['date' => self::day(-1), 'visitors' => 1, 'pageviews' => 1]], $body['daily']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['generated_at']);
    }

    public function testDefaultsToTheLast30CompleteDays(): void
    {
        $this->record('old', 'v1', 'pageview', self::day(-45) . ' 12:00:00');
        $this->record('in', 'v2', 'pageview', self::day(-30) . ' 00:00:00');
        $this->record('today', 'v3', 'pageview', self::day(0) . ' 00:00:01');

        $body = $this->summary();

        $this->assertSame(self::day(-30), $body['from']);
        $this->assertSame(self::day(-1), $body['to']);
        $this->assertCount(30, $body['daily']);
        $this->assertSame(1, $body['visitors'], 'Only the visit 30 days ago counts: older ones and today are left out.');
    }

    public function testStartsOnTheFirstDayOfData(): void
    {
        $this->record('first', 'v1', 'pageview', self::day(-10) . ' 18:30:00');
        $this->record('last', 'v2', 'pageview', self::day(-1) . ' 08:00:00');

        $body = $this->summary();

        $this->assertSame(self::day(-10), $body['from']);
        $this->assertCount(10, $body['daily']);
        $this->assertSame(self::day(-10), $body['daily'][0]['date']);
    }

    public function testDaysWithoutVisitsAreZeroWithoutGaps(): void
    {
        $this->record('a', 'v1', 'pageview', self::day(-6) . ' 12:00:00');
        $this->record('b', 'v2', 'pageview', self::day(-2) . ' 12:00:00');

        $daily = $this->summary()['daily'];

        $this->assertSame(array_map(self::day(...), range(-6, -1)), array_column($daily, 'date'));
        $this->assertSame([1, 0, 0, 0, 1, 0], array_column($daily, 'visitors'));
    }

    public function testAShortPeriodStaysDaily(): void
    {
        $this->record('a', 'v1', 'pageview', self::day(-1) . ' 12:00:00');

        $body = $this->summary(['from' => self::day(-1), 'to' => self::day(-1)]);

        $this->assertSame([['date' => self::day(-1), 'visitors' => 1, 'pageviews' => 1]], $body['daily']);
    }

    public function testAWebsiteWithoutDataHasAnEmptySeries(): void
    {
        $body = $this->summary();

        $this->assertSame(0, $body['visitors']);
        $this->assertSame(0.0, $body['pages_per_visit']);
        $this->assertSame([], $body['daily']);
    }

    public function testMatchesTheGetOverviewTool(): void
    {
        // Two visits of one visitor (30 minutes apart), a bounce, a custom event and a tab left open overnight.
        $this->record('s1', 'v1', 'pageview', self::day(-5) . ' 10:00:00');
        $this->record('s1', 'v1', 'pageview', self::day(-5) . ' 10:03:00');
        $this->record('s1', 'v1', 'signup', self::day(-5) . ' 10:04:30');
        $this->record('s1', 'v1', 'pageview', self::day(-5) . ' 11:00:00');
        $this->record('s2', 'v2', 'pageview', self::day(-4) . ' 23:59:00');
        $this->record('s2', 'v2', 'pageview', self::day(-3) . ' 00:01:00');
        $this->record('s3', 'v3', 'pageview', self::day(-3) . ' 15:00:00');
        $this->record('s4', 'v4', 'pageview', self::day(-2) . ' 08:00:00');
        $this->record('s4', 'v4', '_ml_engaged', self::day(-2) . ' 08:00:40');
        $this->record('s5', null, 'pageview', self::day(-1) . ' 20:00:00');
        $this->record('s5', null, 'pageview', self::day(-1) . ' 20:07:00');
        $from = self::day(-6);
        $to = self::day(-1);

        $api = $this->summary(['from' => $from, 'to' => $to]);
        $overview = $this->tool('get_overview')->call(['site_id' => 'test_site', 'range' => 'custom', 'from' => $from, 'to' => $to])['summary'];

        $this->assertSame(self::day(-5), $api['from'], 'The period starts on the first day of data.');
        $this->assertSame($overview['visitors']['value'], $api['visitors']);
        $this->assertSame($overview['visits']['value'], $api['visits']);
        $this->assertSame($overview['pageviews']['value'], $api['pageviews']);
        $this->assertSame(round($overview['pageviews']['value'] / $overview['visits']['value'], 1), $api['pages_per_visit']);
        $this->assertSame((int) round($overview['avg_duration_seconds']['value']), $api['avg_duration_seconds']);
        $this->assertSame(round($overview['bounce_rate']['value'] / 100, 3), $api['bounce_rate']);
        $this->assertSame([5, 6], [$api['visitors'], $api['visits']], 'Sanity check of the data above.');
    }

    public function testLimitsTheRequestsOfAToken(): void
    {
        $id = SiteTokens::authenticate(self::$token)['id'] ?? 0;
        // Fill this minute and the next one, in case the test runs across a minute boundary.
        $db = Connections::forSite('test_site');
        foreach ([time(), time() + 60] as $time) {
            $db->exec("INSERT INTO rate_limits (bucket, ip_hash, count) VALUES ('" . gmdate('YmdHi', $time) . "', '" . hash('sha256', "api-token:{$id}") . "', " . SummaryApi::REQUESTS_PER_MINUTE . ')');
        }

        $response = $this->get('/api/v1/sites/test_site/summary');

        $this->assertSame(429, $response['status']);
        $this->assertGreaterThanOrEqual(1, (int) ($response['headers']['retry-after'] ?? 0));
        $this->assertLessThanOrEqual(60, (int) ($response['headers']['retry-after'] ?? 0));
        $this->assertSame(200, $this->get('/api/v1/sites/other_site/summary', self::$otherToken)['status'], 'Other tokens keep their own limit.');
    }

    public function testOnlyAcceptsGet(): void
    {
        $this->assertSame(405, $this->get('/api/v1/sites/test_site/summary', self::$token, 'POST')['status']);
    }

    /** @param array<string, string> $query */
    private function summary(array $query = []): array
    {
        $response = SummaryApi::respond('GET', ['site_id' => 'test_site'] + $query, self::$token);
        $this->assertSame(200, $response['status'], json_encode($response['body']) ?: '');
        return $response['body'];
    }

    private function tool(string $name): Tool
    {
        foreach (AnalyticsTools::all() as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }
        $this->fail("No {$name} tool.");
    }

    private static function day(int $offset): string
    {
        return gmdate('Y-m-d', (int) strtotime('today midnight UTC') + $offset * 86400);
    }

    private function record(string $sessionId, ?string $visitorId, string $name, string $timestamp): void
    {
        $stmt = Connections::forSite('test_site')->prepare('INSERT INTO user_activity (session_id, visitor_id, action, timestamp) VALUES (:session, :visitor, :action, :timestamp)');
        $stmt->bindValue(':session', $sessionId, SQLITE3_TEXT);
        $stmt->bindValue(':visitor', $visitorId, $visitorId === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':action', (string) json_encode(['name' => $name, 'data' => ['path' => '/']]), SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
        $stmt->execute();
    }

    /** @return array{status: int, body: mixed, headers: array<string, string>} */
    private function get(string $path, ?string $token = '', string $method = 'GET'): array
    {
        $token = $token === '' ? self::$token : $token;
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $token === null ? '' : "Authorization: Bearer {$token}",
            'ignore_errors' => true,
        ]]);
        $response = file_get_contents(self::$server->url . $path, false, $context);
        preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $match);
        $headers = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }
        return ['status' => (int) ($match[1] ?? 0), 'body' => json_decode((string) $response, true), 'headers' => $headers];
    }
}
