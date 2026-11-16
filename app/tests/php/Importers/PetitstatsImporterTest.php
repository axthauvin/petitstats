<?php

declare(strict_types=1);

namespace Petitstats\Tests\Importers;

use InvalidArgumentException;
use Petitstats\Analytics\SavedFunnels;
use Petitstats\Database\Connections;
use Petitstats\Export\Exporter;
use Petitstats\Importers\PetitstatsImporter;
use Petitstats\Sites\SiteRegistry;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Exports websites with Exporter and reads them back with PetitstatsImporter. */
final class PetitstatsImporterTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testAWebsiteComesBackWithItsVisitsBlockedRequestsAndFunnels(): void
    {
        $this->seed('export_source');
        $archive = $this->export(['export_source'], 'archive');

        $result = (new PetitstatsImporter())->import($archive, 'export_copy');

        $this->assertSame(3, $result['total_imported']);
        $this->assertSame(2, $result['pageviews']);
        $this->assertSame(1, $result['custom_events']);
        $this->assertSame(2, $result['sessions']);
        $this->assertSame(1, $result['blocked']);
        $this->assertSame(1, $result['funnels']);
        $this->assertSame($this->events('export_source'), $this->events('export_copy'));
        $this->assertSame('Export source', SiteRegistry::find('export_copy')['name'], 'A new website takes the name of the exported one.');
        $action = json_decode((string) Connections::forSite('export_copy')->querySingle('SELECT action FROM user_activity LIMIT 1'), true);
        $this->assertSame('export_copy', $action['site_id'], 'Events carry the ID of the website they were imported into.');
    }

    public function testImportingTheSameArchiveTwiceAddsNothing(): void
    {
        $this->seed('twice_source');
        $archive = $this->export(['twice_source'], 'archive');
        $importer = new PetitstatsImporter();
        $importer->import($archive, 'twice_copy');

        $again = $importer->import($archive, 'twice_copy');

        $this->assertSame(0, $again['total_imported']);
        $this->assertSame(2, $again['duplicate_sessions']);
        $this->assertSame(0, $again['blocked']);
        $this->assertSame(0, $again['funnels']);
        $this->assertSame(3, (int) Connections::forSite('twice_copy')->querySingle('SELECT COUNT(*) FROM user_activity'));
    }

    public function testRestoreBringsBackMissingWebsitesWithTheirSettingsAndTrackingKey(): void
    {
        $this->seed('restore_me');
        SiteRegistry::update('restore_me', ['internal_ips' => ['192.0.2.7'], 'retention_days' => 500, 'allow_localhost' => true]);
        $before = SiteRegistry::find('restore_me');
        $archive = $this->export(['restore_me'], 'archive');
        SiteRegistry::delete('restore_me');

        $result = (new PetitstatsImporter())->restore($archive);

        $this->assertSame(1, $result['created_sites']);
        $this->assertSame(3, $result['total_imported']);
        $after = SiteRegistry::find('restore_me');
        foreach (['write_key', 'allowed_domains', 'internal_ips', 'retention_days', 'allow_localhost', 'created_at'] as $setting) {
            $this->assertSame($before[$setting], $after[$setting], $setting);
        }
    }

    public function testCsvExportFlattensEachEvent(): void
    {
        $this->seed('csv_site');
        $zip = new ZipArchive();
        $zip->open($this->export(['csv_site'], 'csv'));
        $lines = array_map(static fn(string $line): array => str_getcsv($line, escape: ''), explode("\n", trim((string) $zip->getFromName('csv_site/events.csv'))));
        $zip->close();

        $rows = array_map(static fn(array $line): array => array_combine($lines[0], $line), array_slice($lines, 1));
        $this->assertCount(3, $rows);
        $this->assertSame('pageview', $rows[0]['event']);
        $this->assertSame('/pricing', $rows[0]['path']);
        $this->assertSame('France', $rows[0]['country']);
        $this->assertSame('signup', $rows[2]['event']);
        $this->assertSame('{"plan":"pro"}', $rows[2]['properties']);
    }

    public function testOnlyArchivesCanBeImported(): void
    {
        $this->seed('csv_only');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV and JSON exports cannot be imported');

        (new PetitstatsImporter())->import($this->export(['csv_only'], 'csv'), 'csv_only_copy');
    }

    public function testArchivesFromANewerVersionAreRefused(): void
    {
        $this->seed('future_site');
        $archive = $this->export(['future_site'], 'archive');
        $zip = new ZipArchive();
        $zip->open($archive);
        $manifest = json_decode((string) $zip->getFromName(Exporter::MANIFEST), true);
        $zip->addFromString(Exporter::MANIFEST, (string) json_encode(['version' => Exporter::ARCHIVE_VERSION + 1] + $manifest));
        $zip->close();

        $this->expectExceptionMessage('newer version of Petitstats');
        (new PetitstatsImporter())->inspect($archive);
    }

    /** Two visits: a pageview each, one with a signup. Also a blocked request and a funnel. */
    private function seed(string $siteId): void
    {
        SiteRegistry::create($siteId, ucfirst(str_replace('_', ' ', $siteId)), 'example.org');
        $db = Connections::forSite($siteId);
        $now = time();
        $events = [
            ['s1', 'v1', 'pageview', ['path' => '/pricing', 'country' => 'France', 'browser' => 'Firefox'], $now - 3600],
            ['s2', 'v2', 'pageview', ['path' => '/', 'country' => 'Spain'], $now - 1800],
            ['s2', 'v2', 'signup', ['path' => '/', 'plan' => 'pro'], $now - 1700],
        ];
        foreach ($events as [$session, $visitor, $name, $data, $time]) {
            $stmt = $db->prepare('INSERT INTO user_activity (session_id, visitor_id, action, timestamp) VALUES (:s, :v, :a, :t)');
            $stmt->bindValue(':s', $session);
            $stmt->bindValue(':v', $visitor);
            $stmt->bindValue(':a', json_encode(['site_id' => $siteId, 'name' => $name, 'data' => $data]));
            $stmt->bindValue(':t', gmdate('Y-m-d H:i:s', $time));
            $stmt->execute();
        }
        $db->exec("INSERT INTO bot_activity (reason, user_agent, ip_hash, timestamp) VALUES ('bot', 'curl/8', 'abc', '" . gmdate('Y-m-d H:i:s', $now - 60) . "')");
        SavedFunnels::ensureTable($db);
        $db->exec("INSERT INTO funnels (name, kind, steps) VALUES ('Signup', 'funnel', '[{\"type\":\"pageview\",\"value\":\"/pricing\",\"label\":\"/pricing\"}]')");
    }

    private function export(array $siteIds, string $format): string
    {
        return $this->files[] = (new Exporter())->export($siteIds, $format);
    }

    /** @return list<array{string, string, string}> */
    private function events(string $siteId): array
    {
        $rows = [];
        $result = Connections::forSite($siteId)->query('SELECT session_id, timestamp, action FROM user_activity ORDER BY id');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $action = json_decode($row['action'], true);
            unset($action['site_id']);
            $rows[] = [$row['session_id'], $row['timestamp'], json_encode($action)];
        }
        return $rows;
    }
}
