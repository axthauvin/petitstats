<?php

declare(strict_types=1);

namespace Petitstats\Importers;

use InvalidArgumentException;
use Petitstats\Analytics\SavedFunnels;
use Petitstats\Database\DatabaseConnection;
use Petitstats\Database\Schema;
use Petitstats\Export\Exporter;
use Petitstats\Sites\SiteRegistry;
use RuntimeException;
use SQLite3;
use ZipArchive;

/**
 * Reads back an archive made by Exporter, from this instance or another one,
 * into SQLite or MySQL. Visits already present are skipped, so importing the
 * same archive twice changes nothing.
 */
class PetitstatsImporter extends BaseImporter
{
    private const BATCH = 5000;

    /** @var array<int, string> temporary file of each open archive database */
    private array $paths = [];

    public function getId(): string
    {
        return 'petitstats';
    }

    public function getName(): string
    {
        return 'Petitstats';
    }

    public function getDescription(): string
    {
        return 'Move a website from another Petitstats instance with an archive exported from Settings → Database.';
    }

    public function getStatus(): string
    {
        return 'ready';
    }

    public function getBadge(): string
    {
        return 'Available';
    }

    public function getIcon(): string
    {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>';
    }

    public function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function getSupportedFormats(): array
    {
        return ['.zip'];
    }

    /** What the archive holds: its websites, their visits and period. */
    public function inspect(string $sourcePath): array
    {
        [$zip, $manifest] = $this->open($sourcePath);
        try {
            $visits = 0;
            $start = null;
            $end = null;
            $sites = [];
            foreach ($manifest['sites'] as $site) {
                $db = $this->siteDatabase($zip, $site['id']);
                try {
                    $visits += (int) $db->querySingle('SELECT COUNT(DISTINCT session_id) FROM user_activity');
                    $min = $db->querySingle('SELECT MIN(timestamp) FROM user_activity');
                    $max = $db->querySingle('SELECT MAX(timestamp) FROM user_activity');
                } finally {
                    $this->closeSiteDatabase($db);
                }
                $start = $min !== null && ($start === null || $min < $start) ? (string) $min : $start;
                $end = $max !== null && ($end === null || $max > $end) ? (string) $max : $end;
                $sites[] = ['id' => $site['id'], 'name' => $site['name'] ?? $site['id'], 'domain' => $site['domain'] ?? ''];
            }
            $first = $manifest['sites'][0];
            return [
                'visits' => $visits,
                'date_start' => $start,
                'date_end' => $end,
                'detected_host' => (string) ($first['domain'] ?? ''),
                'suggested_site_id' => $first['id'],
                'suggested_name' => (string) ($first['name'] ?? $first['id']),
                'sites' => $sites,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Imports one website of the archive into $siteId. An archive of several
     * websites needs $options['source'], the ID of the one to import, unless
     * $siteId is one of them.
     */
    public function import(string $sourcePath, string $siteId, array $options = []): array
    {
        [$zip, $manifest] = $this->open($sourcePath);
        try {
            $ids = array_column($manifest['sites'], 'id');
            $source = (string) ($options['source'] ?? (count($ids) === 1 ? $ids[0] : (in_array($siteId, $ids, true) ? $siteId : '')));
            $site = $manifest['sites'][array_search($source, $ids, true)] ?? null;
            if ($site === null) {
                throw new InvalidArgumentException('This archive holds ' . count($ids) . ' websites (' . implode(', ', $ids) . '). Restore them all from Settings → Database, or choose one.');
            }
            return $this->importSite($zip, $site, $this->cleanSiteId($siteId), $options);
        } finally {
            $zip->close();
        }
    }

    /**
     * Brings back every website of the archive with its settings and tracking
     * key, for instance on a new server or after switching to MySQL. Websites
     * that already exist here keep their settings and only get the visits
     * they miss.
     */
    public function restore(string $sourcePath): array
    {
        [$zip, $manifest] = $this->open($sourcePath);
        try {
            $results = [];
            foreach ($manifest['sites'] as $site) {
                $created = SiteRegistry::find($site['id']) === null;
                if ($created) {
                    SiteRegistry::restore($site);
                }
                $results[] = $this->importSite($zip, $site, $site['id'], []) + ['created' => $created];
            }
            return [
                'success' => true,
                'provider' => 'petitstats',
                'sites' => $results,
                'total_imported' => array_sum(array_column($results, 'total_imported')),
                'created_sites' => count(array_filter(array_column($results, 'created'))),
            ];
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $site the website as described in the manifest */
    private function importSite(ZipArchive $zip, array $site, string $targetId, array $options): array
    {
        [$siteName, $siteDomain] = $this->ensureSite($targetId, [
            'name' => ($options['name'] ?? '') ?: (string) ($site['name'] ?? ''),
            'domain' => ($options['domain'] ?? '') ?: (string) ($site['domain'] ?? ''),
        ]);
        $this->mergeSettings($targetId, $site);
        $cutoff = $this->retentionCutoff($targetId, $options);

        $source = $this->siteDatabase($zip, $site['id']);
        $db = $this->openForBulkInsert($targetId);
        try {
            $visits = $this->copyVisits($source, $db, $targetId, $cutoff);
            $blocked = $this->copyBlocked($source, $db, $cutoff);
            $funnels = $this->copyFunnels($source, $db);
        } finally {
            $this->finishBulkInsert($db);
            $this->closeSiteDatabase($source);
        }

        return [
            'success' => true,
            'provider' => 'petitstats',
            'site_id' => $targetId,
            'site_name' => $siteName,
            'site_domain' => $siteDomain,
            ...$visits,
            'blocked' => $blocked,
            'funnels' => $funnels,
        ];
    }

    /** Adds the allowed domains and internal IPs of the archive to the website's. */
    private function mergeSettings(string $siteId, array $site): void
    {
        $current = SiteRegistry::find($siteId) ?? [];
        $merge = static fn(string $key): array => array_values(array_unique(array_merge((array) ($current[$key] ?? []), (array) ($site[$key] ?? []))));
        SiteRegistry::update($siteId, ['allowed_domains' => $merge('allowed_domains'), 'internal_ips' => $merge('internal_ips')]);
    }

    private function copyVisits(SQLite3 $source, DatabaseConnection $db, string $siteId, string $cutoff): array
    {
        $known = $this->knownSessions($db);
        $insert = $db->prepare('INSERT INTO user_activity (session_id, visitor_id, action, timestamp) VALUES (:session, :visitor, :action, :timestamp)');
        $stats = ['total_imported' => 0, 'pageviews' => 0, 'custom_events' => 0, 'date_start' => null, 'date_end' => null];
        $sessions = [];
        $duplicates = [];
        $expired = [];

        $this->begin($db);
        $result = $source->query('SELECT session_id, visitor_id, action, timestamp FROM user_activity ORDER BY id');
        while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $session = (string) $row['session_id'];
            if (isset($known[$session])) {
                $duplicates[$session] = true;
                continue;
            }
            $timestamp = (string) $row['timestamp'];
            if ($timestamp < $cutoff) {
                $expired[$session] = true;
                continue;
            }
            $action = (string) $row['action'];
            $decoded = json_decode($action, true);
            if (!is_array($decoded)) {
                continue;
            }
            // Events carry the ID of their website, which may change on the way.
            if (($decoded['site_id'] ?? $siteId) !== $siteId) {
                $decoded['site_id'] = $siteId;
                $action = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES);
            }
            $insert->bindValue(':session', $session, SQLITE3_TEXT);
            $insert->bindValue(':visitor', $row['visitor_id'], $row['visitor_id'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $insert->bindValue(':action', $action, SQLITE3_TEXT);
            $insert->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
            $insert->execute();

            $sessions[$session] = true;
            $stats[($decoded['name'] ?? '') === 'pageview' ? 'pageviews' : 'custom_events']++;
            $stats['date_start'] = $stats['date_start'] === null || $timestamp < $stats['date_start'] ? $timestamp : $stats['date_start'];
            $stats['date_end'] = $stats['date_end'] === null || $timestamp > $stats['date_end'] ? $timestamp : $stats['date_end'];
            if (++$stats['total_imported'] % self::BATCH === 0) {
                $db->exec('COMMIT');
                $this->begin($db);
            }
        }
        $db->exec('COMMIT');

        return $stats + [
            'sessions' => count($sessions),
            'duplicate_sessions' => count($duplicates),
            'skipped_sessions' => count(array_diff_key($expired, $sessions)),
        ];
    }

    /** Copies the blocked requests the website does not have yet. */
    private function copyBlocked(SQLite3 $source, DatabaseConnection $db, string $cutoff): int
    {
        $known = [];
        $result = $db->query('SELECT timestamp, reason, ip_hash FROM bot_activity');
        while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $known[$row['timestamp'] . '|' . $row['reason'] . '|' . $row['ip_hash']] = true;
        }
        $country = Schema::hasBotCountry($db);
        $insert = $db->prepare('INSERT INTO bot_activity (reason, user_agent, origin, ip_hash, ' . ($country ? 'country_code, ' : '') . 'timestamp) VALUES (:reason, :agent, :origin, :ip, ' . ($country ? ':country, ' : '') . ':timestamp)');

        $count = 0;
        $this->begin($db);
        $result = $source->query('SELECT * FROM bot_activity ORDER BY id');
        while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $key = $row['timestamp'] . '|' . $row['reason'] . '|' . $row['ip_hash'];
            if (isset($known[$key]) || (string) $row['timestamp'] < $cutoff) {
                continue;
            }
            $known[$key] = true;
            foreach ([':reason' => 'reason', ':agent' => 'user_agent', ':origin' => 'origin', ':ip' => 'ip_hash', ':country' => 'country_code', ':timestamp' => 'timestamp'] as $name => $column) {
                $value = $row[$column] ?? null;
                $insert->bindValue($name, $value, $value === null ? SQLITE3_NULL : SQLITE3_TEXT);
            }
            $insert->execute();
            if (++$count % self::BATCH === 0) {
                $db->exec('COMMIT');
                $this->begin($db);
            }
        }
        $db->exec('COMMIT');
        return $count;
    }

    /** Copies the funnels, goals and journeys the website does not have yet. */
    private function copyFunnels(SQLite3 $source, DatabaseConnection $db): int
    {
        SavedFunnels::ensureTable($db);
        $key = static fn(array $row): string => $row['kind'] . '|' . $row['name'] . '|' . json_encode(json_decode((string) $row['steps'], true));
        $known = [];
        $result = $db->query('SELECT name, kind, steps FROM funnels');
        while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $known[$key($row)] = true;
        }

        $count = 0;
        $insert = $db->prepare('INSERT INTO funnels (name, kind, steps, created_at) VALUES (:name, :kind, :steps, :created)');
        $result = @$source->query("SELECT name, COALESCE(kind, 'funnel') AS kind, steps, created_at FROM funnels ORDER BY id");
        while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            if (isset($known[$key($row)]) || !in_array($row['kind'], SavedFunnels::KINDS, true)) {
                continue;
            }
            $known[$key($row)] = true;
            $insert->bindValue(':name', $row['name'], SQLITE3_TEXT);
            $insert->bindValue(':kind', $row['kind'], SQLITE3_TEXT);
            $insert->bindValue(':steps', $row['steps'], SQLITE3_TEXT);
            $insert->bindValue(':created', $row['created_at'] ?? gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
            $insert->execute();
            $count++;
        }
        return $count;
    }

    private function begin(DatabaseConnection $db): void
    {
        $db->exec($db->isMysql() ? 'START TRANSACTION' : 'BEGIN TRANSACTION');
    }

    /**
     * Opens the archive and checks its manifest.
     *
     * @return array{ZipArchive, array{sites: list<array<string, mixed>>}}
     */
    private function open(string $path): array
    {
        if (!$this->isZipArchiveFile($path)) {
            throw new InvalidArgumentException('Choose the .zip archive exported from Petitstats.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This archive cannot be opened.');
        }
        $manifest = json_decode((string) $zip->getFromName(Exporter::MANIFEST), true);
        $error = match (true) {
            !is_array($manifest) || ($manifest['format'] ?? '') !== 'petitstats-export' => 'This is not a Petitstats export.',
            (int) ($manifest['version'] ?? 0) > Exporter::ARCHIVE_VERSION => 'This archive comes from a newer version of Petitstats. Update Petitstats first.',
            ($manifest['content'] ?? '') !== 'archive' => 'CSV and JSON exports cannot be imported. Export the websites as a Petitstats archive instead.',
            default => null,
        };
        if ($error !== null) {
            $zip->close();
            throw new InvalidArgumentException($error);
        }

        $sites = [];
        foreach ((array) ($manifest['sites'] ?? []) as $site) {
            $id = is_array($site) ? (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($site['id'] ?? ''))) : '';
            if ($id !== '' && $zip->locateName('sites/' . $id . '.db') !== false) {
                $sites[] = ['id' => $id] + $site;
            }
        }
        if ($sites === []) {
            $zip->close();
            throw new InvalidArgumentException('This archive holds no website.');
        }
        $manifest['sites'] = $sites;
        return [$zip, $manifest];
    }

    /** The website's database from the archive, copied to a temporary file and opened read-only. */
    private function siteDatabase(ZipArchive $zip, string $siteId): SQLite3
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'petitstats-import-');
        $in = $zip->getStream('sites/' . $siteId . '.db');
        $out = fopen($path, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException("Cannot read the database of {$siteId} in the archive.");
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $this->paths[spl_object_id($db)] = $path;
        if ($db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('user_activity', 'bot_activity')") !== 2) {
            $this->closeSiteDatabase($db);
            throw new InvalidArgumentException("The database of {$siteId} in the archive is not a Petitstats database.");
        }
        return $db;
    }

    private function closeSiteDatabase(SQLite3 $db): void
    {
        $id = spl_object_id($db);
        $db->close();
        if (isset($this->paths[$id])) {
            @unlink($this->paths[$id]);
            unset($this->paths[$id]);
        }
    }
}
