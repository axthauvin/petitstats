<?php

declare(strict_types=1);

namespace Petitstats\Tests\Sites;

use InvalidArgumentException;
use Petitstats\Database\DataDirectory;
use Petitstats\Sites\SiteRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SiteRegistryTest extends TestCase
{
    private string $sites;

    protected function setUp(): void
    {
        $this->sites = (string) file_get_contents(DataDirectory::path() . '/sites.json');
    }

    protected function tearDown(): void
    {
        foreach (['new_site', 'discovered_site'] as $site) {
            @unlink(DataDirectory::path() . "/{$site}.db");
        }
        file_put_contents(DataDirectory::path() . '/sites.json', $this->sites);
    }

    public function testFillsInTheSettingsOfWebsitesFromOlderVersions(): void
    {
        SiteRegistry::save([['id' => 'old_site', 'name' => 'Old', 'domain' => 'https://Old.example.com/blog']]);

        $site = SiteRegistry::find('old_site');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $site['write_key'] ?? '');
        $this->assertSame(['old.example.com'], $site['allowed_domains']);
        $this->assertSame([], $site['internal_ips']);
        $this->assertSame(395, $site['retention_days']);
        $this->assertStringContainsString($site['write_key'], (string) file_get_contents(DataDirectory::path() . '/sites.json'));
    }

    public function testListsADatabaseFileThatHasNoSettings(): void
    {
        touch(DataDirectory::path() . '/discovered_site.db');

        $this->assertSame('Discovered Site', SiteRegistry::find('discovered_site')['name'] ?? null);
        $this->assertNull(SiteRegistry::find('auth'));
    }

    #[DataProvider('invalidIds')]
    public function testExplainsWhyAWebsiteCannotBeSelected(?string $siteId, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        SiteRegistry::requireId($siteId);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function invalidIds(): iterable
    {
        yield 'no website' => [null, 'Select a website before accessing analytics.'];
        yield 'every website' => ['all', 'Select a website before accessing analytics.'];
        yield 'only invalid characters' => ['!!!', 'A valid website must be selected before accessing analytics.'];
        yield 'unknown website' => ['missing', 'The selected website does not exist.'];
    }

    public function testCleansTheIdOfAnExistingWebsite(): void
    {
        $this->assertSame('test_site', SiteRegistry::requireId('TEST_SITE'));
        $this->assertSame('test_site', SiteRegistry::find('Test_Site!')['id'] ?? null);
    }

    public function testCreatesAWebsiteAndItsStorage(): void
    {
        $site = SiteRegistry::create('New_Site', 'New site', 'https://www.Example.org/');

        $this->assertSame('new_site', $site['id']);
        $this->assertSame(['www.example.org'], $site['allowed_domains']);
        $this->assertSame(395, $site['retention_days']);
        $this->assertFileExists(DataDirectory::path() . '/new_site.db');
        $this->assertSame($site, SiteRegistry::find('new_site'));
    }

    public function testRefusesTwoWebsitesWithTheSameId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("A website with ID 'test_site' already exists.");

        SiteRegistry::create('test_site', 'Again');
    }

    public function testCleansTheSettingsItSaves(): void
    {
        $before = SiteRegistry::find('test_site');

        $site = SiteRegistry::update('test_site', [
            'allowed_domains' => 'https://A.com/page, b.org',
            'internal_ips' => "10.0.0.1 10.0.0.2,10.0.0.1",
            'retention_days' => 9999,
            'rotate_key' => true,
        ]);

        $this->assertSame(['a.com', 'b.org'], $site['allowed_domains']);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $site['internal_ips']);
        $this->assertSame(760, $site['retention_days']);
        $this->assertNotSame($before['write_key'], $site['write_key']);
        $this->assertSame($site, SiteRegistry::find('test_site'));
    }

    public function testDeletesAWebsiteAndItsData(): void
    {
        SiteRegistry::create('new_site', 'New site');

        SiteRegistry::delete('new_site');

        $this->assertNull(SiteRegistry::find('new_site'));
        $this->assertFileDoesNotExist(DataDirectory::path() . '/new_site.db');
    }

    public function testOnlyPublicWebsitesAreOpenToGuests(): void
    {
        $this->assertNull(SiteRegistry::findPublic());
        $this->assertFalse(SiteRegistry::isPublic('test_site'));

        SiteRegistry::update('test_site', ['is_public' => true]);

        $this->assertSame('test_site', SiteRegistry::findPublic()['id'] ?? null);
        $this->assertTrue(SiteRegistry::isPublic('TEST_SITE'));
    }

    public function testGuestsDoNotSeeTheTrackingSettings(): void
    {
        $view = SiteRegistry::guestView((array) SiteRegistry::find('test_site'));

        $this->assertSame([], array_intersect(array_keys($view), ['write_key', 'allowed_domains', 'allow_localhost', 'internal_ips', 'retention_days']));
        $this->assertSame('Test site', $view['name']);
    }

    #[DataProvider('hosts')]
    public function testKeepsOnlyTheHostOfADomainOrUrl(string $value, string $host): void
    {
        $this->assertSame($host, SiteRegistry::normalizeHost($value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function hosts(): iterable
    {
        yield 'a URL' => ['https://Example.com/blog?x=1', 'example.com'];
        yield 'a domain with spaces' => ['  WWW.Site.org ', 'www.site.org'];
        yield 'a port' => ['example.com:8080', 'example.com'];
        yield 'a trailing dot' => ['example.com.', 'example.com'];
        yield 'nothing' => ['', ''];
    }
}
