<?php

declare(strict_types=1);

namespace Petitstats\Export;

use InvalidArgumentException;
use Petitstats\Analytics\SavedFunnels;
use Petitstats\Database\Connections;
use Petitstats\Database\DatabaseConnection;
use Petitstats\Database\Schema;
use Petitstats\Sites\SiteRegistry;
use RuntimeException;
use SQLite3;
use ZipArchive;

/**
 * Writes the analytics of one or several websites to a ZIP file:
 * - "archive": a SQLite database per website and their settings, which
 *   PetitstatsImporter reads back on any instance, SQLite or MySQL;
 * - "csv" or "json": the visits, blocked requests and funnels of each website,
 *   to open in a spreadsheet or a script.
 * Database settings and the tracking secret are never exported.
 */
final class Exporter
{
    public const FORMATS = ['archive', 'csv', 'json'];
    /** Raised when the archive layout changes in a way older versions cannot read. */
    public const ARCHIVE_VERSION = 1;
    public const MANIFEST = 'petitstats-export.json';

    /** Website settings kept in an archive, so that a restored website tracks like before. */
    public const SITE_SETTINGS = ['id', 'name', 'domain', 'allowed_domains', 'internal_ips', 'retention_days', 'allow_localhost', 'is_public', 'write_key', 'created_at'];

    /** Visit columns of the CSV export, read from each event; the other event data goes in "properties". */
    private const EVENT_COLUMNS = ['path', 'title', 'referrer', 'hostname', 'browser', 'os', 'device', 'country', 'country_code', 'region', 'city', 'language', 'screen', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    private const PAGE_SIZE = 2000;

    /** @var list<string> temporary files to delete once the ZIP is written */
    private array $temp = [];

    /**
     * @param list<string> $siteIds websites to export, every website when empty
     * @return string the path of the ZIP file, to delete once sent
     */
    public function export(array $siteIds, string $format, ?string $zipPath = null): string
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException('Choose an archive, CSV or JSON export.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Exporting needs the PHP zip extension.');
        }
        $sites = $this->sites($siteIds);
        $zipPath ??= (string) tempnam(sys_get_temp_dir(), 'petitstats-export-');

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the export file.');
        }
        try {
            $manifest = [];
            foreach ($sites as $site) {
                $db = Connections::forSite($site['id']);
                match ($format) {
                    'archive' => $zip->addFile($this->sqliteCopy($db), 'sites/' . $site['id'] . '.db'),
                    'csv' => $this->addCsv($zip, $db, $site['id']),
                    'json' => $this->addJson($zip, $db, $site['id']),
                };
                $manifest[] = array_intersect_key($site, array_flip(self::SITE_SETTINGS));
            }
            $zip->addFromString(self::MANIFEST, (string) json_encode([
                'format' => 'petitstats-export',
                'version' => self::ARCHIVE_VERSION,
                'content' => $format,
                'petitstats' => self::version(),
                'exported_at' => gmdate('Y-m-d H:i:s'),
                'sites' => $manifest,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $zip->close();
        } finally {
            foreach ($this->temp as $file) {
                @unlink($file);
            }
            $this->temp = [];
        }
        return $zipPath;
    }

    /** A name for the downloaded file, such as petitstats-blog-2026-11-16.zip. */
    public static function fileName(array $siteIds, string $format): string
    {
        $what = count($siteIds) === 1 ? $siteIds[0] : 'all';
        $suffix = $format === 'archive' ? '' : '-' . $format;
        return 'petitstats-' . $what . $suffix . '-' . gmdate('Y-m-d') . '.zip';
    }

    /** @return list<array<string, mixed>> */
    private function sites(array $siteIds): array
    {
        $all = SiteRegistry::all();
        if ($siteIds === []) {
            if ($all === []) {
                throw new InvalidArgumentException('There is no website to export.');
            }
            return $all;
        }
        $sites = [];
        foreach ($siteIds as $siteId) {
            $id = SiteRegistry::requireId($siteId);
            foreach ($all as $site) {
                if ($site['id'] === $id) {
                    $sites[] = $site;
                }
            }
        }
        return $sites;
    }

    /** A new SQLite database holding the analytics of a website, whichever database it lives in. */
    private function sqliteCopy(DatabaseConnection $source): string
    {
        $path = $this->tempFile();
        $sqlite = new SQLite3($path);
        $target = new DatabaseConnection($sqlite, 'sqlite', $source->siteId());
        Schema::ensure($target);
        SavedFunnels::ensureTable($source);
        SavedFunnels::ensureTable($target);

        foreach (['user_activity', 'bot_activity', 'funnels'] as $table) {
            $sqlite->exec('BEGIN');
            $insert = null;
            foreach ($this->rows($source, $table) as $row) {
                if ($insert === null) {
                    $columns = array_keys($row);
                    $insert = $sqlite->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
                }
                foreach ($row as $column => $value) {
                    $insert->bindValue(':' . $column, $value, is_int($value) ? SQLITE3_INTEGER : ($value === null ? SQLITE3_NULL : SQLITE3_TEXT));
                }
                $insert->execute();
                $insert->reset();
            }
            $sqlite->exec('COMMIT');
        }
        $sqlite->close();
        return $path;
    }

    private function addCsv(ZipArchive $zip, DatabaseConnection $db, string $siteId): void
    {
        $events = $this->tempFile();
        $out = fopen($events, 'w');
        fputcsv($out, ['id', 'timestamp', 'session_id', 'visitor_id', 'event', ...self::EVENT_COLUMNS, 'properties'], escape: '');
        foreach ($this->rows($db, 'user_activity') as $row) {
            $action = json_decode((string) $row['action'], true) ?: [];
            $data = is_array($action['data'] ?? null) ? $action['data'] : [];
            $line = [$row['id'], $row['timestamp'], $row['session_id'], $row['visitor_id'], $action['name'] ?? ''];
            foreach (self::EVENT_COLUMNS as $column) {
                $line[] = is_scalar($data[$column] ?? null) ? $data[$column] : '';
                unset($data[$column]);
            }
            unset($data['_ml_tracking_mode']);
            $line[] = $data === [] ? '' : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            fputcsv($out, $line, escape: '');
        }
        fclose($out);
        $zip->addFile($events, $siteId . '/events.csv');

        foreach (['bot_activity' => 'blocked.csv', 'funnels' => 'funnels.csv'] as $table => $name) {
            $file = $this->tempFile();
            $out = fopen($file, 'w');
            $header = false;
            foreach ($this->rows($db, $table) as $row) {
                if (!$header) {
                    fputcsv($out, array_keys($row), escape: '');
                    $header = true;
                }
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
            $zip->addFile($file, $siteId . '/' . $name);
        }
    }

    private function addJson(ZipArchive $zip, DatabaseConnection $db, string $siteId): void
    {
        foreach (['user_activity' => 'events.json', 'bot_activity' => 'blocked.json', 'funnels' => 'funnels.json'] as $table => $name) {
            $file = $this->tempFile();
            $out = fopen($file, 'w');
            fwrite($out, '[');
            $first = true;
            foreach ($this->rows($db, $table) as $row) {
                foreach (['action', 'steps'] as $column) {
                    if (isset($row[$column])) {
                        $row[$column] = json_decode((string) $row[$column], true);
                    }
                }
                fwrite($out, ($first ? "\n" : ",\n") . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $first = false;
            }
            fwrite($out, "\n]\n");
            fclose($out);
            $zip->addFile($file, $siteId . '/' . $name);
        }
    }

    /**
     * Every row of a table, read a page at a time so that a large website does
     * not fill the memory (MySQL buffers a whole result).
     *
     * @return \Generator<array<string, mixed>>
     */
    private function rows(DatabaseConnection $db, string $table): \Generator
    {
        if ($table === 'funnels') {
            SavedFunnels::ensureTable($db);
        }
        $last = 0;
        do {
            $stmt = $db->prepare('SELECT * FROM ' . $table . ' WHERE id > :last ORDER BY id LIMIT ' . self::PAGE_SIZE);
            $stmt->bindValue(':last', $last, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $count = 0;
            while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $row['id'] = (int) $row['id'];
                $last = $row['id'];
                $count++;
                yield $row;
            }
        } while ($count === self::PAGE_SIZE);
    }

    private function tempFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'petitstats-export-');
        $this->temp[] = $file;
        return $file;
    }

    /** The Petitstats version, from src/VERSION in release archives. */
    private static function version(): string
    {
        return trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: 'dev';
    }
}
