<?php

declare(strict_types=1);

namespace Petitstats\Tests\Tracking;

use Petitstats\Database\DataDirectory;
use Petitstats\Tracking\VisitorIdentity;
use PHPUnit\Framework\TestCase;

final class VisitorIdentityTest extends TestCase
{
    public function testTheSameVisitorKeepsTheSameIdentifierWithinTheMonth(): void
    {
        $first = VisitorIdentity::visitorId('site', '203.0.113.7', 'Firefox');

        $this->assertSame($first, VisitorIdentity::visitorId('site', '203.0.113.7', 'Firefox'));
        $this->assertNotSame($first, VisitorIdentity::visitorId('other_site', '203.0.113.7', 'Firefox'));
        $this->assertNotSame($first, VisitorIdentity::visitorId('site', '203.0.113.8', 'Firefox'));
        $this->assertNotSame($first, VisitorIdentity::visitorId('site', '203.0.113.7', 'Chrome'));
    }

    public function testANewMonthDiscardsThePreviousSalt(): void
    {
        $october = VisitorIdentity::salt('2026-10');
        $this->assertSame($october, VisitorIdentity::salt('2026-10'));

        $november = VisitorIdentity::salt('2026-11');
        $this->assertNotSame($october, $november);
        $this->assertStringNotContainsString($october, (string) file_get_contents(DataDirectory::path() . '/.visitor-salt'));
        $this->assertNotSame($october, VisitorIdentity::salt('2026-10'), 'A past month can never be recomputed.');
    }

    public function testTheFormerPermanentSecretIsDeleted(): void
    {
        $legacy = DataDirectory::path() . '/.tracking-secret';
        file_put_contents($legacy, str_repeat('a', 64));

        VisitorIdentity::salt('2027-01');

        $this->assertFileDoesNotExist($legacy);
    }
}
