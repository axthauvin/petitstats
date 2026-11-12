<?php

declare(strict_types=1);

namespace Petitstats\Api;

use InvalidArgumentException;
use Petitstats\Analytics\Period;
use Petitstats\Analytics\SiteAnalytics;
use Petitstats\Auth\SiteTokens;
use Petitstats\Database\Connections;
use Petitstats\Sites\DemoTraffic;
use Petitstats\Sites\SiteRegistry;
use Petitstats\Tracking\RateLimiter;
use Throwable;

/**
 * GET /api/v1/sites/{site_id}/summary: the audience of one website over whole
 * UTC days, for a server that shows it on its own pages. Read-only, and
 * authenticated by a stats API token of that website (SiteTokens).
 *
 * The numbers come from the same reports as the dashboard and the MCP
 * get_overview tool, so they always match.
 */
final class SummaryApi
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 90;
    public const REQUESTS_PER_MINUTE = 60;

    /**
     * Answers a request: its status, headers and JSON body.
     *
     * @param array<string, mixed> $query the query string, with `site_id` taken from the URL
     * @return array{status: int, headers: array<string, string>, body: array<string, mixed>}
     */
    public static function respond(string $method, array $query, ?string $token, ?int $now = null): array
    {
        $now ??= time();
        if ($method !== 'GET') {
            return self::error(405, 'This endpoint only accepts GET requests.', ['Allow' => 'GET']);
        }

        $access = $token === null ? null : SiteTokens::authenticate($token);
        if ($access === null) {
            return self::error(
                401,
                $token === null ? 'Send a stats API token: Authorization: Bearer <token>.' : 'This token is not valid, or it was revoked.',
                ['WWW-Authenticate' => 'Bearer' . ($token === null ? '' : ' error="invalid_token"')],
            );
        }

        // Counted per token, before anything else, so that failed requests count too.
        if (!(new RateLimiter(Connections::forSite($access['site_id']), self::REQUESTS_PER_MINUTE))->allow('api-token:' . $access['id'])) {
            return self::error(429, 'Too many requests: ' . self::REQUESTS_PER_MINUTE . ' per minute at most for one token.', ['Retry-After' => (string) RateLimiter::retryAfter($now)]);
        }

        $requested = is_string($query['site_id'] ?? null) ? $query['site_id'] : '';
        $site = $requested === '' ? null : SiteRegistry::find($requested);
        if ($site === null) {
            return self::error(404, 'This website does not exist.');
        }
        $siteId = (string) $site['id'];
        if ($siteId !== $access['site_id']) {
            return self::error(403, 'This token can only read its own website.');
        }

        try {
            [$from, $to] = self::days($query, $now);
        } catch (InvalidArgumentException $e) {
            return self::error(400, $e->getMessage());
        }

        try {
            // The demo website fills itself in as it is read, like in the dashboard.
            DemoTraffic::refresh($siteId, $now);
        } catch (Throwable $e) {
            error_log('Petitstats demo refresh failed: ' . $e->getMessage());
        }

        return ['status' => 200, 'headers' => ['Cache-Control' => 'private, max-age=300'], 'body' => self::summary($siteId, $from, $to, $now)];
    }

    /**
     * The first and last day asked for: `from` and `to` (both included), or by
     * default the last 30 complete days, yesterday included.
     *
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string} two dates formatted as YYYY-MM-DD
     */
    public static function days(array $query, int $now): array
    {
        $today = gmdate('Y-m-d', $now);
        $from = $query['from'] ?? null;
        $to = $query['to'] ?? null;
        if ($from === null && $to === null) {
            return [self::shift($today, -self::DEFAULT_DAYS), self::shift($today, -1)];
        }
        if (!self::isDate($from) || !self::isDate($to)) {
            throw new InvalidArgumentException('Pass both from and to as dates formatted as YYYY-MM-DD, or neither for the last 30 days.');
        }
        if ($from > $to) {
            throw new InvalidArgumentException('from must be on or before to.');
        }
        if ($to > $today) {
            throw new InvalidArgumentException("to cannot be after today ({$today}, UTC).");
        }
        if (self::length($from, $to) > self::MAX_DAYS) {
            throw new InvalidArgumentException('The period can cover ' . self::MAX_DAYS . ' days at most.');
        }
        return [$from, $to];
    }

    /**
     * The response body. The period starts on the website's first day of data
     * at the earliest, and every day after it has an entry, even without visits.
     *
     * @return array<string, mixed>
     */
    public static function summary(string $siteId, string $from, string $to, int $now): array
    {
        $first = SiteAnalytics::open($siteId, Period::from('custom', $from, $to, $now))->firstActivity();
        $firstDay = $first === null ? null : substr($first, 0, 10);
        if ($firstDay !== null && $firstDay > $from) {
            $from = min($firstDay, $to);
        }
        $analytics = SiteAnalytics::open($siteId, Period::from('custom', $from, $to, $now));
        $summary = $analytics->summary();
        $visits = (int) $summary['sessions'];
        $pageviews = (int) $summary['pageviews'];

        $daily = [];
        if ($firstDay !== null && $firstDay <= $to) {
            foreach ($analytics->dailyTimeseries() as $day) {
                $daily[] = ['date' => gmdate('Y-m-d', (int) $day['timestamp']), 'visitors' => (int) $day['visitors'], 'pageviews' => (int) $day['pageviews']];
            }
        }

        return [
            'site_id' => $siteId,
            'from' => $from,
            'to' => $to,
            'visitors' => (int) $summary['visitors'],
            'visits' => $visits,
            'pageviews' => $pageviews,
            'pages_per_visit' => $visits > 0 ? round($pageviews / $visits, 1) : 0.0,
            'avg_duration_seconds' => (int) round((float) $summary['avg_duration_seconds']),
            // The dashboard shows a percentage with one decimal: keep the same precision.
            'bounce_rate' => round((float) $summary['bounce_rate'] / 100, 3),
            'daily' => $daily,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: array{error: string}}
     */
    private static function error(int $status, string $message, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers + ['Cache-Control' => 'no-store'], 'body' => ['error' => $message]];
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function shift(string $day, int $days): string
    {
        return gmdate('Y-m-d', (int) strtotime("{$day} 00:00:00 UTC") + $days * 86400);
    }

    /** Number of days from `$from` to `$to`, both included. */
    private static function length(string $from, string $to): int
    {
        return (int) round((strtotime("{$to} 00:00:00 UTC") - strtotime("{$from} 00:00:00 UTC")) / 86400) + 1;
    }
}
