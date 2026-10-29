<?php

declare(strict_types=1);

namespace Petitstats\Tests\Importers;

use Petitstats\Database\Connections;
use Petitstats\Importers\MatomoImporter;
use Petitstats\Sites\SiteRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Imports tests/php/fixtures/matomo: the same two visits as a Live API JSON export, as an API
 * CSV export (UTF-16, columns like actionDetails_0_url) and as a CSV exported from the Matomo
 * interface (columns like "url (actionDetails 0)"). The first visit has eight actions of every
 * common type, the second a pageview and an abandoned cart.
 */
final class MatomoImporterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/matomo';
    /** "Today" for the importer, a month after the fixture visits. */
    private const NOW = 1770000000; // 2026-02-02

    public function testInspectDetectsTheWebsite(): void
    {
        $inspection = (new MatomoImporter(self::NOW))->inspect(self::FIXTURES . '/visits.json');

        $this->assertSame('shop.example.com', $inspection['detected_host']);
        $this->assertSame('shop', $inspection['suggested_site_id']);
        $this->assertSame('Example Shop', $inspection['suggested_name'], 'The name given in Matomo.');
    }

    #[DataProvider('formats')]
    public function testImportReportsWhatItImported(string $file): void
    {
        $result = (new MatomoImporter(self::NOW))->import(self::FIXTURES . '/' . $file, 'matomo_report_' . str_replace(['.', '-'], '_', $file));

        $this->assertSame(10, $result['total_imported']);
        $this->assertSame(3, $result['pageviews']);
        $this->assertSame(7, $result['custom_events']);
        $this->assertSame(2, $result['sessions']);
        $this->assertSame('2026-01-05 10:00:00', $result['date_start']);
        $this->assertSame('2026-01-06 10:32:00', $result['date_end']);
    }

    /** @return iterable<array{string}> */
    public static function formats(): iterable
    {
        yield 'json' => ['visits.json'];
        yield 'csv' => ['visits.csv'];
        yield 'interface csv' => ['visits-interface.csv'];
    }

    #[DataProvider('csvFormats')]
    public function testCsvAndJsonExportsGiveTheSameEvents(string $csv): void
    {
        $site = 'matomo_same_' . str_replace('-', '_', pathinfo($csv, PATHINFO_FILENAME));
        $importer = new MatomoImporter(self::NOW);
        $importer->import(self::FIXTURES . '/visits.json', $site . '_json');
        $importer->import(self::FIXTURES . '/' . $csv, $site);

        $this->assertEquals($this->storedEvents($site . '_json', false), $this->storedEvents($site, false));
    }

    /** @return iterable<array{string}> */
    public static function csvFormats(): iterable
    {
        yield 'api csv' => ['visits.csv'];
        yield 'interface csv' => ['visits-interface.csv'];
    }

    public function testImportCreatesTheWebsiteWithTheDetectedDomain(): void
    {
        (new MatomoImporter(self::NOW))->import(self::FIXTURES . '/visits.json', 'matomo_site', ['name' => 'My shop']);

        $site = array_values(array_filter(SiteRegistry::all(), static fn(array $s): bool => $s['id'] === 'matomo_site'))[0] ?? null;
        $this->assertSame('My shop', $site['name'] ?? null);
        $this->assertSame('shop.example.com', $site['domain'] ?? null);
    }

    public function testPageviewsKeepTheVisitDetails(): void
    {
        (new MatomoImporter(self::NOW))->import(self::FIXTURES . '/visits.csv', 'matomo_pageviews');

        [$home, $pricing] = $this->storedEvents('matomo_pageviews');
        $this->assertSame('pageview', $home['name']);
        $this->assertSame('/', $home['data']['path']);
        $this->assertSame('ref=nl', $home['data']['search']);
        $this->assertSame('Home', $home['data']['title']);
        $this->assertSame('https://www.google.fr/', $home['data']['referrer']);
        $this->assertSame('Chrome', $home['data']['browser']);
        $this->assertSame('macOS', $home['data']['os']);
        $this->assertSame('Desktop', $home['data']['device']);
        $this->assertSame('1920×1080', $home['data']['screen']);
        $this->assertSame('fr', $home['data']['language']);
        $this->assertSame('France', $home['data']['country']);
        $this->assertSame('FR', $home['data']['country_code']);
        $this->assertSame('Paris', $home['data']['city']);
        $this->assertSame('pro', $home['data']['plan']);
        $this->assertSame('member', $home['data']['dimension1']);
        $this->assertSame('landing', $home['data']['dimension2']);

        $this->assertSame('/pricing', $pricing['data']['path']);
        $this->assertSame('#plans', $pricing['data']['hash']);
        $this->assertSame('shop.example.com', $pricing['data']['referrer'], 'Only the landing page keeps the external referrer.');
    }

    public function testEveryActionTypeBecomesAnEvent(): void
    {
        (new MatomoImporter(self::NOW))->import(self::FIXTURES . '/visits.json', 'matomo_actions');

        $events = $this->storedEvents('matomo_actions');
        $this->assertSame(
            ['pageview', 'pageview', 'play', 'outbound_click', 'file_download', 'site_search', 'Newsletter signup', 'purchase', 'pageview', 'abandoned_cart'],
            array_column($events, 'name'),
        );
        [, , $play, $outlink, $download, $search, $goal, $order] = $events;

        $this->assertSame(['Video', 'Intro', 30], [$play['data']['category'], $play['data']['label'], $play['data']['value']]);
        $this->assertSame('github.com', $outlink['data']['target_host']);
        $this->assertSame('/pricing', $outlink['data']['path'], 'An outlink happens on the last page viewed.');
        $this->assertSame('guide.pdf', $download['data']['file_name']);
        $this->assertSame(['boots', 4], [$search['data']['keyword'], $search['data']['results_count']]);
        $this->assertSame([2, 5], [$goal['data']['goal_id'], $goal['data']['revenue']]);
        $this->assertSame('/newsletter', $goal['data']['path']);
        $this->assertSame('A-1001', $order['data']['order_id']);
        $this->assertSame(54.95, $order['data']['revenue']);
        $this->assertSame('Hiking Boots, Wool Socks', $order['data']['products']);
    }

    public function testActionTimesAreConvertedToUtc(): void
    {
        (new MatomoImporter(self::NOW))->import(self::FIXTURES . '/visits.json', 'matomo_times');

        $times = $this->column('matomo_times', 'timestamp');
        $this->assertSame('2026-01-05 10:00:00', $times[0]);
        $this->assertSame('2026-01-05 10:04:00', $times[7]);
    }

    public function testCampaignVisitsAndUnknownVisitorDetails(): void
    {
        (new MatomoImporter(self::NOW))->import(self::FIXTURES . '/visits.csv', 'matomo_campaign');

        [$post, $cart] = array_slice($this->storedEvents('matomo_campaign'), 8);
        $this->assertSame('-5% on boots', $post['data']['title'], 'The CSV quote that blocks spreadsheet formulas is removed.');
        $this->assertSame(['newsletter', 'email', 'spring_sale'], [$post['data']['utm_source'], $post['data']['utm_medium'], $post['data']['utm_campaign']]);
        $this->assertNull($post['data']['referrer']);
        $this->assertSame(['Safari', 'iOS', 'Mobile'], [$post['data']['browser'], $post['data']['os'], $post['data']['device']]);
        $this->assertSame(['Unknown', 'UN'], [$post['data']['country'], $post['data']['country_code']]);
        $this->assertNull($post['data']['screen']);
        $this->assertSame(19.9, $cart['data']['revenue']);
    }

    public function testVisitorsAreIdentifiedWithoutStoringTheirUserId(): void
    {
        (new MatomoImporter(self::NOW))->import(self::FIXTURES . '/visits.csv', 'matomo_visitors');

        $visitors = array_values(array_unique($this->column('matomo_visitors', 'visitor_id')));
        $this->assertSame('matomo_a1b2c3d4e5f60718', $visitors[0]);
        $this->assertStringStartsWith('matomo_', $visitors[1]);
        $this->assertStringNotContainsString('jane', $visitors[1]);
        $this->assertCount(2, $visitors);
    }

    public function testAZipOfOverlappingExportsImportsEachVisitOnce(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The zip extension is not installed.');
        }
        $archive = tempnam(sys_get_temp_dir(), 'matomo');
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::OVERWRITE);
        $zip->addFile(self::FIXTURES . '/visits.json', 'export/2026-01.json');
        $zip->addFile(self::FIXTURES . '/visits.csv', 'export/2026-01.csv');
        $zip->close();

        try {
            $result = (new MatomoImporter(self::NOW))->import($archive, 'matomo_zip');
            $this->assertSame(10, $result['total_imported']);
            $this->assertSame(2, $result['sessions']);
        } finally {
            @unlink($archive);
        }
    }

    public function testImportingTheSameExportTwiceAddsNothing(): void
    {
        $importer = new MatomoImporter(self::NOW);
        $importer->import(self::FIXTURES . '/visits.json', 'matomo_twice');
        $again = $importer->import(self::FIXTURES . '/visits.csv', 'matomo_twice');

        $this->assertSame(0, $again['total_imported']);
        $this->assertSame(2, $again['duplicate_sessions']);
        $this->assertCount(10, $this->storedEvents('matomo_twice'));
    }

    public function testSkipsVisitsOlderThanTheWebsiteKeeps(): void
    {
        // 395 days after the night between the two visits, only the second one is still kept.
        $later = strtotime('2027-02-05 00:00:00 UTC');
        $result = (new MatomoImporter($later))->import(self::FIXTURES . '/visits.json', 'matomo_retention');

        $this->assertSame(1, $result['sessions']);
        $this->assertSame(1, $result['skipped_sessions']);
        $this->assertSame('2026-01-06 10:30:00', $result['date_start']);
    }

    public function testCanKeepMoreDaysToImportTheWholeHistory(): void
    {
        $later = strtotime('2027-02-05 00:00:00 UTC');
        $result = (new MatomoImporter($later))->import(self::FIXTURES . '/visits.json', 'matomo_longer', ['retention_days' => 760]);

        $this->assertSame(2, $result['sessions']);
        $this->assertSame(0, $result['skipped_sessions']);
        $this->assertSame(760, SiteRegistry::find('matomo_longer')['retention_days'] ?? null);
    }

    public function testInspectSummarisesTheWholeExport(): void
    {
        $inspection = (new MatomoImporter(self::NOW))->inspect(self::FIXTURES . '/visits-interface.csv');

        $this->assertSame(2, $inspection['visits']);
        $this->assertSame('2026-01-05 10:00:00', $inspection['date_start']);
        $this->assertSame('2026-01-06 10:32:00', $inspection['date_end']);
    }

    public function testRejectsAMatomoErrorResponse(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'matomo') . '.json';
        file_put_contents($file, '{"result":"error","message":"You can\'t access this resource."}');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("You can't access this resource.");
            (new MatomoImporter(self::NOW))->import($file, 'matomo_error');
        } finally {
            @unlink($file);
        }
    }

    /** @return list<array<string, mixed>> */
    private function storedEvents(string $siteId, bool $withSite = true): array
    {
        $events = array_map(static fn(string $action): array => json_decode($action, true), $this->column($siteId, 'action'));
        if (!$withSite) {
            foreach ($events as &$event) {
                unset($event['site_id']);
            }
        }
        return $events;
    }

    /** @return list<string> */
    private function column(string $siteId, string $column): array
    {
        $result = Connections::forSite($siteId)->query("SELECT {$column} FROM user_activity ORDER BY timestamp, id");
        $values = [];
        while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $values[] = $row[$column];
        }
        return $values;
    }
}
