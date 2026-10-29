<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Petitstats\Database\Connections;
use Throwable;

/**
 * Public ingestion for track.php: registered sites, per-site keys, allowed
 * origins only.
 */
final class Tracker
{
    /** @param array<string, mixed> $server the request's $_SERVER */
    public static function handle(array $server, string $body): TrackingResponse
    {
        try {
            $payload = TrackingPayload::fromJson($body);
            $origin = (string) ($server['HTTP_ORIGIN'] ?? $server['HTTP_REFERER'] ?? '');
            $site = TrackingAccess::authorize($payload, $origin);
            return new TrackingResponse(200, self::track($site, $payload, $server, TrackingAccess::host($origin)));
        } catch (TrackingException $e) {
            return new TrackingResponse($e->status, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Records the event, unless it comes from a bot or from the website's own team.
     *
     * @param array<string, mixed> $site
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private static function track(array $site, TrackingPayload $payload, array $server, string $originHost): array
    {
        $db = Connections::forSite($site['id']);
        $store = new ActivityStore($db);
        $userAgent = new UserAgent((string) ($server['HTTP_USER_AGENT'] ?? ''));
        $ip = self::clientIp($server);

        if (!(new RateLimiter($db))->allow($ip)) {
            throw new TrackingException('Rate limit exceeded.', 429);
        }
        if (($reason = $userAgent->botReason()) !== null) {
            $store->recordIgnored($reason, $userAgent->value, $originHost, $ip, self::countryCode($server, $ip));
            return ['ignored' => 'bot'];
        }
        if ($payload->name() === '_ml_bot') {
            // The tracking script detected an automated browser and says so instead of sending analytics.
            $reason = substr((string) preg_replace('/[^a-zA-Z0-9_$]/', '', (string) ($payload->data['reason'] ?? '')), 0, 60);
            $store->recordIgnored('client:' . ($reason !== '' ? $reason : 'unknown'), $userAgent->value, $originHost, $ip, self::countryCode($server, $ip));
            return ['ignored' => 'bot'];
        }
        if (in_array($ip, (array) ($site['internal_ips'] ?? []), true)) {
            $store->recordIgnored('internal_traffic', $userAgent->value, $originHost, $ip, self::countryCode($server, $ip));
            return ['ignored' => 'internal'];
        }

        $name = $payload->name();
        $data = EventData::sanitize($payload->data, $userAgent);
        try {
            $data = array_merge($data, VisitorLocation::resolve($server, $ip));
        } catch (Throwable $error) {
            error_log('[Petitstats] GeoIP enrichment failed: ' . $error->getMessage());
            $data = array_merge($data, ['country' => 'Unknown', 'country_code' => 'UN', 'region' => '', 'city' => '']);
        }

        // The visitor is always recognised by the server, with a salt that changes every month.
        // With consent, the browser keeps the visit together when the network changes.
        $visitor = VisitorIdentity::visitorId((string) $site['id'], $ip, $userAgent->value);
        $action = ['site_id' => $site['id'], 'name' => $name, 'data' => $data];
        if ($data['_ml_tracking_mode'] === 'enriched' && $payload->sessionId !== '') {
            $store->record($payload->sessionId, $visitor, $action);
        } else {
            $store->recordInActiveSession($visitor, $action);
        }
        return ['success' => true];
    }

    /**
     * The country of a rejected request, shown in the bot report. A failed
     * lookup only loses the country, never the record.
     *
     * @param array<string, mixed> $server
     */
    private static function countryCode(array $server, string $ip): ?string
    {
        try {
            return VisitorLocation::resolve($server, $ip)['country_code'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The visitor's IP address. Behind Cloudflare, REMOTE_ADDR is Cloudflare's
     * address and the visitor's is in CF-Connecting-IP; anyone can send that
     * header, so it is only read when the administrator says so.
     *
     * @param array<string, mixed> $server
     */
    private static function clientIp(array $server): string
    {
        if (CloudflareTrust::isEnabled() && !empty($server['HTTP_CF_CONNECTING_IP'])) {
            return trim((string) $server['HTTP_CF_CONNECTING_IP']);
        }
        return trim((string) ($server['REMOTE_ADDR'] ?? ''));
    }
}
