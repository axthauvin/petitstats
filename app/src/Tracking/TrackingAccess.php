<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Petitstats\Sites\SiteRegistry;

/**
 * Accepts events only from a website's allowed domains, signed with its key.
 *
 * Rejections explain how to fix the setup: they are read by whoever is
 * installing the snippet, in the browser's network panel or console.
 */
final class TrackingAccess
{
    /**
     * Returns the registered website the payload is for, after checking where
     * it comes from and its key.
     *
     * @return array<string, mixed>
     */
    public static function authorize(TrackingPayload $payload, string $origin): array
    {
        $site = SiteRegistry::find($payload->siteId);
        if (!$site) {
            throw new TrackingException("Unknown website \"{$payload->siteId}\". Copy the snippet again from the Websites page of your Petitstats dashboard.", 404);
        }
        $originHost = self::host($origin);
        if ($originHost === '') {
            throw new TrackingException('The request has no Origin or Referer header, so the website it comes from cannot be checked.', 403);
        }
        if (!self::isAllowed($site, $originHost)) {
            throw new TrackingException(self::isLocal($originHost)
                ? "{$originHost} is not allowed to send events for this website. To test locally, enable \"Accept events from localhost\" in Settings → Tracking."
                : "{$originHost} is not an allowed domain for this website. Add it in Settings → Tracking → Allowed domains.", 403);
        }
        if (!hash_equals((string) ($site['write_key'] ?? ''), $payload->siteKey)) {
            throw new TrackingException('Invalid tracking key. Copy the current snippet from the Websites page of your Petitstats dashboard.', 403);
        }
        return $site;
    }

    /** Host name of a URL, or of a bare host. */
    public static function host(string $value): string
    {
        return SiteRegistry::normalizeHost((string) (parse_url($value, PHP_URL_HOST) ?: $value));
    }

    /** @param array<string, mixed> $site */
    private static function isAllowed(array $site, string $host): bool
    {
        $allowed = array_filter(array_map([SiteRegistry::class, 'normalizeHost'], (array) ($site['allowed_domains'] ?? [])));
        return in_array($host, $allowed, true) || (self::isLocal($host) && !empty($site['allow_localhost']));
    }

    private static function isLocal(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($host, '.localhost');
    }
}
