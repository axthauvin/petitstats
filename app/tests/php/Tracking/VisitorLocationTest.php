<?php

declare(strict_types=1);

namespace Petitstats\Tests\Tracking;

use Petitstats\Tracking\VisitorLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisitorLocationTest extends TestCase
{
    /** @param array<string, string> $server */
    #[DataProvider('proxies')]
    public function testReadsTheCountryFromManagedProxies(array $server, string $region): void
    {
        $location = VisitorLocation::resolve($server, '203.0.113.7');

        $this->assertSame('FR', $location['country_code']);
        $this->assertSame($region, $location['region']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function proxies(): iterable
    {
        yield 'Cloudflare' => [['HTTP_CF_IPCOUNTRY' => 'fr', 'HTTP_CF_REGION_CODE' => 'IDF'], 'IDF'];
        yield 'Vercel' => [['HTTP_X_VERCEL_IP_COUNTRY' => 'FR', 'HTTP_X_VERCEL_IP_COUNTRY_REGION' => 'IDF'], 'IDF'];
        yield 'CloudFront' => [['HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'FR'], ''];
        yield 'Fastly' => [['HTTP_FASTLY_CLIENT_COUNTRY_CODE' => 'FR'], ''];
        yield 'GeoIP module' => [['GEOIP_COUNTRY_CODE' => 'FR', 'GEOIP_REGION_NAME' => 'IDF'], 'IDF'];
    }

    public function testIgnoresTheUnknownCountryCode(): void
    {
        $this->assertSame('Local development', VisitorLocation::resolve(['HTTP_CF_IPCOUNTRY' => 'XX'], '127.0.0.1')['country']);
    }

    public function testPrivateAddressesAreLocalDevelopment(): void
    {
        foreach (['127.0.0.1', '::1', '192.168.1.20', '10.0.0.1'] as $ip) {
            $this->assertSame('Local development', VisitorLocation::resolve([], $ip)['country'], $ip);
        }
    }
}
