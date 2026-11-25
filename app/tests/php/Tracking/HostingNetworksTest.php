<?php

declare(strict_types=1);

namespace Petitstats\Tests\Tracking;

use Petitstats\Tracking\HostingNetworks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostingNetworksTest extends TestCase
{
    #[DataProvider('clouds')]
    public function testRecognisesCloudProviders(int $asn, string $provider): void
    {
        $this->assertSame($provider, HostingNetworks::provider($asn));
    }

    /** @return iterable<string, array{int, string}> */
    public static function clouds(): iterable
    {
        yield 'Google Cloud' => [396982, 'google_cloud'];
        yield 'Google' => [15169, 'google_cloud'];
        yield 'AWS' => [16509, 'aws'];
        yield 'Azure' => [8075, 'azure'];
        yield 'OVH' => [16276, 'ovh'];
        yield 'Hetzner' => [24940, 'hetzner'];
        yield 'DigitalOcean' => [14061, 'digitalocean'];
    }

    #[DataProvider('people')]
    public function testLeavesNetworksCarryingPeopleAlone(int $asn): void
    {
        $this->assertNull(HostingNetworks::provider($asn));
    }

    /** @return iterable<string, array{int}> */
    public static function people(): iterable
    {
        yield 'Orange' => [3215];
        yield 'Free' => [12322];
        yield 'SFR' => [15557];
        yield 'Bouygues Telecom' => [5410];
        yield 'Cloudflare (WARP, iCloud Private Relay)' => [13335];
        yield 'Akamai (iCloud Private Relay)' => [20940];
        yield 'Fastly (iCloud Private Relay)' => [54113];
    }

    public function testAnUnknownNetworkIsNoProvider(): void
    {
        $this->assertNull(HostingNetworks::provider(null));
    }

    public function testLocalAddressesAreNeverLookedUp(): void
    {
        foreach (['127.0.0.1', '::1', '10.0.0.1', '192.168.1.20', 'not an ip', ''] as $ip) {
            $this->assertNull(HostingNetworks::lookup($ip), $ip);
        }
    }
}
