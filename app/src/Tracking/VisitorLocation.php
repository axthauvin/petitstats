<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Locale;
use Petitstats\Geo\GeoLocation;
use Throwable;

/** Server-side, privacy-preserving location of a visitor: country, region and city only. */
final class VisitorLocation
{
    /**
     * Different managed proxies expose the same ISO country code under
     * different header names. X-Forwarded-For is not used: it is an IP chain,
     * not a country code.
     */
    private const PROXY_HEADERS = [
        ['country' => 'HTTP_CF_IPCOUNTRY', 'region' => 'HTTP_CF_REGION_CODE', 'city' => 'HTTP_CF_IPCITY'],
        ['country' => 'HTTP_X_VERCEL_IP_COUNTRY', 'region' => 'HTTP_X_VERCEL_IP_COUNTRY_REGION', 'city' => 'HTTP_X_VERCEL_IP_CITY'],
        ['country' => 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'region' => 'HTTP_CLOUDFRONT_VIEWER_COUNTRY_REGION', 'city' => 'HTTP_CLOUDFRONT_VIEWER_CITY'],
        ['country' => 'HTTP_FASTLY_CLIENT_COUNTRY_CODE', 'region' => '', 'city' => ''],
        ['country' => 'GEOIP_COUNTRY_CODE', 'region' => 'GEOIP_REGION_NAME', 'city' => 'GEOIP_CITY'],
    ];

    private const UNKNOWN = ['country' => 'Unknown', 'country_code' => 'UN', 'region' => '', 'city' => ''];

    /**
     * @param array<string, mixed> $server the request's $_SERVER
     * @return array{country: string, country_code: string, region: string, city: string}
     */
    public static function resolve(array $server, string $ip): array
    {
        foreach (self::PROXY_HEADERS as $headers) {
            $code = strtoupper(trim((string) ($server[$headers['country']] ?? '')));
            if (preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX') {
                $name = class_exists('Locale') ? Locale::getDisplayRegion('und_' . $code, 'en') : $code;
                return ['country' => $name ?: $code, 'country_code' => $code, 'region' => trim((string) ($server[$headers['region']] ?? '')), 'city' => trim((string) ($server[$headers['city']] ?? ''))];
            }
        }
        if ($ip === '::1' || str_starts_with($ip, '127.') || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['country' => 'Local development', 'country_code' => 'UN', 'region' => '', 'city' => ''];
        }
        // GeoIP enrichment is optional: a missing database or extension must never
        // make the public collection endpoint return a 500.
        try {
            $location = GeoLocation::lookup($ip);
            if (is_array($location)) {
                return $location;
            }
        } catch (Throwable $error) {
            error_log('[Petitstats] GeoIP lookup failed: ' . $error->getMessage());
        }
        return self::UNKNOWN;
    }
}
