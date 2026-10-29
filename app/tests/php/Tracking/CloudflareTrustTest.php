<?php

declare(strict_types=1);

namespace Petitstats\Tests\Tracking;

use Petitstats\Tracking\CloudflareTrust;
use PHPUnit\Framework\TestCase;

final class CloudflareTrustTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PETITSTATS_TRUST_CLOUDFLARE');
        CloudflareTrust::save(false);
    }

    public function testIsOffByDefault(): void
    {
        $this->assertFalse(CloudflareTrust::isEnabled());
    }

    public function testTheDashboardSettingIsSaved(): void
    {
        CloudflareTrust::save(true);
        $this->assertTrue(CloudflareTrust::isEnabled());

        CloudflareTrust::save(false);
        $this->assertFalse(CloudflareTrust::isEnabled());
    }

    public function testTheEnvironmentVariableOverridesTheDashboard(): void
    {
        putenv('PETITSTATS_TRUST_CLOUDFLARE=1');

        $this->assertTrue(CloudflareTrust::isForcedByEnvironment());
        $this->assertTrue(CloudflareTrust::isEnabled());
    }
}
