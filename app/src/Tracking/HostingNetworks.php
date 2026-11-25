<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Petitstats\Geo\GeoLocation;
use Throwable;

/**
 * Recognises requests sent from a cloud or hosting provider's network, where
 * page views come from scripts and headless browsers rather than people.
 *
 * The address is matched against its autonomous system (GeoLite2 ASN, kept
 * up to date with the City database) during the request only: neither the
 * address nor the network is stored, only the provider's name as the reason
 * the request was rejected.
 *
 * To add a provider, add its AS numbers below (bgp.he.net or PeeringDB list
 * them). Networks that carry people's browsing are left out on purpose:
 * Cloudflare, Akamai and Fastly relay iCloud Private Relay, WARP and Edge's
 * VPN, and consumer VPN providers are not hosting companies.
 */
final class HostingNetworks
{
    /** Provider => its autonomous systems. */
    private const PROVIDERS = [
        'google_cloud' => [15169, 19527, 396982, 139070, 139190],
        'aws' => [16509, 14618, 8987],
        'azure' => [8075],
        'oracle_cloud' => [31898],
        'alibaba_cloud' => [45102],
        'tencent_cloud' => [132203],
        'huawei_cloud' => [136907],
        'ovh' => [16276],
        'hetzner' => [24940, 212317, 213230],
        'digitalocean' => [14061],
        'linode' => [63949],
        'vultr' => [20473],
        'scaleway' => [12876],
        'contabo' => [51167],
        'ionos' => [8560],
        'leaseweb' => [60781],
        'hostinger' => [47583],
    ];

    /** The hosting provider running this autonomous system, if it is one. */
    public static function provider(?int $asn): ?string
    {
        if ($asn === null) {
            return null;
        }
        foreach (self::PROVIDERS as $provider => $networks) {
            if (in_array($asn, $networks, true)) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * The hosting provider this address belongs to. Private and local
     * addresses, and failed lookups, are never a provider: a missing
     * database must not keep visitors out.
     */
    public static function lookup(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        try {
            return self::provider(GeoLocation::asn($ip));
        } catch (Throwable $error) {
            error_log('[Petitstats] ASN lookup failed: ' . $error->getMessage());
            return null;
        }
    }
}
