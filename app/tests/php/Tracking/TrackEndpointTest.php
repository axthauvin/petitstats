<?php

declare(strict_types=1);

namespace Petitstats\Tests\Tracking;

use Petitstats\Database\Connections;
use Petitstats\Sites\SiteRegistry;
use Petitstats\Tests\PhpServer;
use Petitstats\Tracking\CloudflareTrust;
use PHPUnit\Framework\TestCase;

/**
 * Sends real HTTP requests to track.php through PHP's built-in server, so
 * these tests keep passing however the endpoint is organised internally.
 *
 * The default server is a direct install; the "cloudflare" one sets
 * PETITSTATS_TRUST_CLOUDFLARE, like an install behind Cloudflare.
 */
final class TrackEndpointTest extends TestCase
{
    private const ORIGIN = 'https://example.com';
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    /** @var array<string, PhpServer> */
    private static array $servers = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            $server->stop();
        }
        self::$servers = [];
    }

    protected function tearDown(): void
    {
        SiteRegistry::update('test_site', ['allowed_domains' => ['example.com'], 'allow_localhost' => false, 'internal_ips' => ['10.0.0.99']]);
        CloudflareTrust::save(false);
    }

    protected function setUp(): void
    {
        $db = Connections::forSite('test_site');
        $db->exec('DELETE FROM user_activity');
        $db->exec('DELETE FROM bot_activity');
        $db->exec('DELETE FROM rate_limits');
    }

    public function testOnlyPostIsAccepted(): void
    {
        $this->assertSame(405, $this->request('GET')['status']);
    }

    public function testRejectsAPayloadWithoutNameAndData(): void
    {
        $this->assertSame(400, $this->track(['site_id' => 'test_site', 'site_key' => 'test-key'])['status']);
    }

    public function testRejectsAnUnknownWebsite(): void
    {
        $this->assertSame(404, $this->track($this->event(['site_id' => 'nope']))['status']);
    }

    public function testRejectsAnOriginOutsideTheAllowedDomains(): void
    {
        $this->assertSame(403, $this->track($this->event(), ['Origin: https://evil.example'])['status']);
        $this->assertSame([], $this->storedEvents());
    }

    public function testExplainsARejectedOriginToTheInstallingPage(): void
    {
        $response = $this->track($this->event(), ['Origin: https://www.example.org']);

        $this->assertSame('https://www.example.org', $response['headers']['access-control-allow-origin'] ?? null);
        $this->assertStringContainsString('www.example.org is not an allowed domain', $response['body']['error']);
        $this->assertStringContainsString('Settings → Tracking → Allowed domains', $response['body']['error']);
        $this->assertStringNotContainsString('example.com', $response['body']['error'], 'The allowed domains must stay private.');
    }

    public function testLocalhostIsRejectedByDefaultWithAHint(): void
    {
        $response = $this->track($this->event(), ['Origin: http://localhost:3000']);

        $this->assertSame(403, $response['status']);
        $this->assertStringContainsString('Accept events from localhost', $response['body']['error']);
    }

    public function testLocalhostIsAcceptedOnceEnabled(): void
    {
        SiteRegistry::update('test_site', ['allow_localhost' => true]);

        foreach (['http://localhost:3000', 'http://127.0.0.1:8080', 'http://blog.localhost'] as $origin) {
            $this->assertSame(200, $this->track($this->event(), ["Origin: {$origin}"])['status'], $origin);
        }
        $this->assertSame(403, $this->track($this->event(), ['Origin: https://evil.example'])['status']);
    }

    public function testLocalhostListedAsAnAllowedDomainKeepsWorking(): void
    {
        SiteRegistry::update('test_site', ['allowed_domains' => ['example.com', 'localhost']]);

        $this->assertSame(200, $this->track($this->event(), ['Origin: http://localhost:3000'])['status']);
    }

    public function testRejectsARequestWithoutOrigin(): void
    {
        $this->assertSame(403, $this->track($this->event(), ['Origin: '])['status']);
    }

    public function testRejectsAWrongTrackingKey(): void
    {
        $this->assertSame(403, $this->track($this->event(['site_key' => 'wrong']))['status']);
        $this->assertSame([], $this->storedEvents());
    }

    public function testStoresAPageviewWithoutQueryStringOrSensitiveFields(): void
    {
        $response = $this->track($this->event(['data' => [
            'path' => 'pricing',
            'url' => 'https://example.com/pricing?email=a@b.c',
            'search' => '?email=a@b.c',
            'referrer' => 'https://www.google.com/search?q=petitstats',
            'email' => 'visitor@example.com',
            'plan' => 'pro',
        ]]));

        $this->assertSame(200, $response['status']);
        $events = $this->storedEvents();
        $this->assertCount(1, $events);
        $data = $events[0]['action']['data'];
        $this->assertSame('pageview', $events[0]['action']['name']);
        $this->assertSame('/pricing', $data['path']);
        $this->assertSame('www.google.com', $data['referrer']);
        $this->assertSame('pro', $data['plan']);
        $this->assertSame('Chrome', $data['browser']);
        $this->assertSame('strict', $data['_ml_tracking_mode']);
        foreach (['url', 'search', 'email'] as $removed) {
            $this->assertArrayNotHasKey($removed, $data);
        }
    }

    public function testEventNamesKeepSpacesButLoseControlCharacters(): void
    {
        $this->track($this->event(['name' => 'Add to cart']));
        $this->track($this->event(['name' => "Sign\x01up"]));

        $this->assertSame(['Add to cart', 'Signup'], array_map(static fn(array $e): string => $e['action']['name'], $this->storedEvents()));
    }

    public function testRejectsAnEmptyEventName(): void
    {
        $this->assertSame(400, $this->track($this->event(['name' => " \x02 "]))['status']);
        $this->assertSame([], $this->storedEvents());
    }

    public function testUsesTheCountryFromTheProxyHeader(): void
    {
        $this->track($this->event(), ['CF-IPCountry: FR']);

        $this->assertSame('FR', $this->storedEvents()[0]['action']['data']['country_code']);
    }

    public function testStrictModeGroupsEventsFromTheSameVisitorWithoutClientIds(): void
    {
        $this->track($this->event(['session_id' => 'client-session', 'visitor_id' => 'client-visitor']));
        $this->track($this->event(['name' => 'signup']));

        [$first, $second] = $this->storedEvents();
        $this->assertSame($first['session_id'], $second['session_id']);
        $this->assertSame($first['visitor_id'], $second['visitor_id']);
        $this->assertNotSame('client-session', $first['session_id']);
        $this->assertNotSame('client-visitor', $first['visitor_id']);
    }

    public function testStrictModeKeepsSimultaneousEventsInOneVisit(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('PHP\'s built-in server only runs several workers on Unix.');
        }
        // The tracking script sends the page view and the events it held back
        // all at once on the first interaction.
        for ($round = 0; $round < 3; $round++) {
            Connections::forSite('test_site')->exec('DELETE FROM user_activity');
            $this->trackAtOnce(array_map(fn(int $i): array => $this->event(['name' => "event_{$i}"]), range(1, 8)));

            $sessions = array_unique(array_column($this->storedEvents(), 'session_id'));
            $this->assertCount(8, $this->storedEvents());
            $this->assertCount(1, $sessions, 'Simultaneous events of a new visitor must share one visit.');
        }
    }

    public function testEnrichedModeKeepsTheClientSessionButRecognisesTheVisitorOnTheServer(): void
    {
        $enriched = ['path' => '/', 'tracking_mode' => 'enriched'];
        $this->track($this->event(['session_id' => 'session-1', 'visitor_id' => 'visitor-1', 'data' => $enriched]));
        $this->track($this->event(['session_id' => 'session-2', 'data' => $enriched]));
        [$first, $second] = $this->storedEvents();

        $this->assertSame('session-1', $first['session_id']);
        $this->assertSame('session-2', $second['session_id']);
        $this->assertNotSame('visitor-1', $first['visitor_id']);
        $this->assertSame($first['visitor_id'], $second['visitor_id'], 'A returning visitor is recognised in enriched mode too.');
    }

    public function testEnrichedModeWithoutASessionFallsBackToTheServerSession(): void
    {
        $this->track($this->event(['data' => ['path' => '/', 'tracking_mode' => 'enriched']]));

        $event = $this->storedEvents()[0];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $event['session_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $event['visitor_id']);
    }

    public function testBotsAreLoggedSeparately(): void
    {
        $response = $this->track($this->event(), ['User-Agent: Googlebot/2.1 (+http://www.google.com/bot.html)']);

        $this->assertSame(['ignored' => 'bot'], $response['body']);
        $this->assertSame([], $this->storedEvents());
        $this->assertSame(1, (int) Connections::forSite('test_site')->querySingle('SELECT COUNT(*) FROM bot_activity'));
    }

    public function testBotsAreLoggedWithTheirCountry(): void
    {
        $this->track($this->event(), ['User-Agent: Googlebot/2.1 (+http://www.google.com/bot.html)', 'CF-IPCountry: US']);

        $this->assertSame('US', Connections::forSite('test_site')->querySingle('SELECT country_code FROM bot_activity'));
    }

    public function testBrowsersReportingAutomationAreLoggedAsBots(): void
    {
        $response = $this->track($this->event(['name' => '_ml_bot', 'data' => ['reason' => 'webdriver']]));

        $this->assertSame(['ignored' => 'bot'], $response['body']);
        $this->assertSame([], $this->storedEvents());
        $this->assertSame('client:webdriver', Connections::forSite('test_site')->querySingle('SELECT reason FROM bot_activity'));
    }

    public function testInternalTrafficIsIgnored(): void
    {
        SiteRegistry::update('test_site', ['internal_ips' => ['127.0.0.1']]);

        $response = $this->track($this->event());

        $this->assertSame(['ignored' => 'internal'], $response['body']);
        $this->assertSame([], $this->storedEvents());
    }

    public function testACloudflareIpHeaderIsIgnoredByDefault(): void
    {
        $response = $this->track($this->event(), ['CF-Connecting-IP: 10.0.0.99']);

        $this->assertSame(['success' => true], $response['body'], 'A forged header must not pass for internal traffic.');
    }

    public function testForgedIpHeadersDoNotCreateNewVisitors(): void
    {
        $this->track($this->event(), ['CF-Connecting-IP: 203.0.113.1']);
        $this->track($this->event(), ['CF-Connecting-IP: 203.0.113.2']);

        [$first, $second] = $this->storedEvents();
        $this->assertSame($first['visitor_id'], $second['visitor_id']);
    }

    public function testBehindCloudflareTheVisitorIpComesFromItsHeader(): void
    {
        $response = $this->track($this->event(), ['CF-Connecting-IP: 10.0.0.99'], 'cloudflare');

        $this->assertSame(['ignored' => 'internal'], $response['body']);
    }

    public function testTheDashboardSettingTrustsCloudflareToo(): void
    {
        CloudflareTrust::save(true);

        $response = $this->track($this->event(), ['CF-Connecting-IP: 10.0.0.99']);

        $this->assertSame(['ignored' => 'internal'], $response['body']);
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): array
    {
        return $overrides + ['site_id' => 'test_site', 'site_key' => 'test-key', 'name' => 'pageview', 'data' => ['path' => '/']];
    }

    /**
     * @param list<string> $headers replace the defaults sharing their name
     * @return array{status: int, body: mixed, headers: array<string, string>}
     */
    private function track(array $payload, array $headers = [], string $server = 'default'): array
    {
        $defaults = ['Origin' => 'Origin: ' . self::ORIGIN, 'User-Agent' => 'User-Agent: ' . self::BROWSER];
        foreach ($headers as $header) {
            $defaults[strstr($header, ':', true)] = $header;
        }
        // "Origin: " with no value means the request carries no Origin header.
        $defaults = array_filter($defaults, static fn(string $header): bool => trim((string) substr(strstr($header, ':'), 1)) !== '');
        return $this->request('POST', (string) json_encode($payload), array_values($defaults), $server);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: mixed, headers: array<string, string>}
     */
    private function request(string $method, string $body = '', array $headers = [], string $server = 'default'): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", ['Content-Type: application/json', ...$headers]),
            'content' => $body,
            'ignore_errors' => true,
        ]]);
        $response = file_get_contents(self::serverUrl($server) . '/track.php', false, $context);
        preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $match);
        $responseHeaders = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
        return ['status' => (int) ($match[1] ?? 0), 'body' => json_decode((string) $response, true), 'headers' => $responseHeaders];
    }

    /**
     * Sends the events in parallel, to a server running several workers.
     *
     * @param list<array<string, mixed>> $payloads
     */
    private function trackAtOnce(array $payloads): void
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($payloads as $payload) {
            $handle = curl_init(self::serverUrl('workers') . '/track.php');
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . self::ORIGIN, 'User-Agent: ' . self::BROWSER],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);
        foreach ($handles as $handle) {
            $this->assertSame(200, curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
    }

    /** @return list<array{session_id: string, visitor_id: string, action: array<string, mixed>}> */
    private function storedEvents(): array
    {
        $result = Connections::forSite('test_site')->query('SELECT session_id, visitor_id, action FROM user_activity ORDER BY id');
        $events = [];
        while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $events[] = ['session_id' => $row['session_id'], 'visitor_id' => $row['visitor_id'], 'action' => json_decode($row['action'], true)];
        }
        return $events;
    }

    /** Starts the named server on first use. */
    private static function serverUrl(string $name): string
    {
        if (!isset(self::$servers[$name])) {
            $env = getenv();
            unset($env['PETITSTATS_TRUST_CLOUDFLARE']);
            if ($name === 'cloudflare') {
                $env['PETITSTATS_TRUST_CLOUDFLARE'] = '1';
            }
            if ($name === 'workers') {
                $env['PHP_CLI_SERVER_WORKERS'] = '8';
            }
            self::$servers[$name] = PhpServer::start(dirname(__DIR__, 3), $env);
        }
        return self::$servers[$name]->url;
    }
}
