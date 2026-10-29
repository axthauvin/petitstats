<?php

declare(strict_types=1);

namespace Petitstats\Sites;

use InvalidArgumentException;
use Petitstats\Database\Connections;
use Petitstats\Database\DatabaseConfig;
use Petitstats\Database\DataDirectory;
use RuntimeException;

/** The tracked websites and their settings, kept in sites.json. */
final class SiteRegistry
{
    /** Days of visits a website keeps: about 13 months by default, about 25 at most. */
    public const DEFAULT_RETENTION_DAYS = 395;
    public const MAX_RETENTION_DAYS = 760;

    public static function clampRetention(int $days): int
    {
        return max(1, min(self::MAX_RETENTION_DAYS, $days));
    }

    /** Every website, with the settings added in later versions filled in. */
    public static function all(): array
    {
        $dataDir = DataDirectory::path();
        $sitesFile = self::path();
        $sites = [];

        if (file_exists($sitesFile)) {
            $sites = json_decode(file_get_contents($sitesFile), true) ?: [];
        }

        // Backfill configuration for installations created before protected
        // collection was introduced. The generated key is only exposed to admins.
        $sitesChanged = false;
        foreach ($sites as &$site) {
            if (empty($site['write_key'])) {
                $site['write_key'] = bin2hex(random_bytes(24));
                $sitesChanged = true;
            }
            if (!isset($site['allowed_domains'])) {
                $site['allowed_domains'] = array_filter([self::normalizeHost((string) ($site['domain'] ?? ''))]);
                $sitesChanged = true;
            }
            if (!isset($site['internal_ips'])) {
                $site['internal_ips'] = [];
                $sitesChanged = true;
            }
            if (!isset($site['retention_days'])) {
                $site['retention_days'] = self::DEFAULT_RETENTION_DAYS;
                $sitesChanged = true;
            }
        }
        unset($site);
        if ($sitesChanged) {
            self::save($sites);
        }

        // Auto-discover any .db files inside data/
        $dbFiles = glob("{$dataDir}/*.db") ?: [];
        $knownIds = array_column($sites, 'id');

        foreach ($dbFiles as $file) {
            $base = basename($file, '.db');
            if ($base === 'auth') {
                continue;
            }
            if (!in_array($base, $knownIds)) {
                $sites[] = [
                    'id' => $base,
                    'name' => ucwords(str_replace(['_', '-'], ' ', $base)),
                    'domain' => '',
                    'allowed_domains' => [],
                    'internal_ips' => [],
                    'retention_days' => self::DEFAULT_RETENTION_DAYS,
                    'write_key' => bin2hex(random_bytes(24)),
                    'created_at' => gmdate('Y-m-d H:i:s', filemtime($file)),
                ];
                $sitesChanged = true;
            }
        }
        if ($sitesChanged) {
            self::save($sites);
        }

        return $sites;
    }

    public static function save(array $sites): void
    {
        file_put_contents(self::path(), json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** The website with this ID, or null when there is none. */
    public static function find(string $siteId): ?array
    {
        $cleanId = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($siteId));
        foreach (self::all() as $site) {
            if (($site['id'] ?? '') === $cleanId) {
                return $site;
            }
        }
        return null;
    }

    /** The cleaned ID of an existing website, or an error a visitor can read. */
    public static function requireId(?string $siteId): string
    {
        if (empty($siteId) || $siteId === 'all') {
            throw new InvalidArgumentException('Select a website before accessing analytics.');
        }

        $cleanSiteId = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($siteId));
        if ($cleanSiteId === '') {
            throw new InvalidArgumentException('A valid website must be selected before accessing analytics.');
        }

        foreach (self::all() as $site) {
            if (($site['id'] ?? '') === $cleanSiteId) {
                return $cleanSiteId;
            }
        }

        throw new InvalidArgumentException('The selected website does not exist.');
    }

    /** Public sites can be browsed read-only by anonymous visitors (live demo). */
    public static function isPublic(?string $siteId): bool
    {
        return self::findPublic($siteId) !== null;
    }

    /** Returns the requested public site, or the first public site when no ID is given. */
    public static function findPublic(?string $siteId = null): ?array
    {
        $cleanId = $siteId === null ? null : preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($siteId));
        foreach (self::all() as $site) {
            if (empty($site['is_public'])) {
                continue;
            }
            if ($cleanId === null || ($site['id'] ?? '') === $cleanId) {
                return $site;
            }
        }
        return null;
    }

    /** Strips tracking secrets and private configuration before exposing a site to guests. */
    public static function guestView(array $site): array
    {
        return array_diff_key($site, array_flip(['write_key', 'allowed_domains', 'allow_localhost', 'internal_ips', 'retention_days']));
    }

    public static function trackingSnippet(array $site, string $scriptUrl): string
    {
        return '<script defer src="' . htmlspecialchars($scriptUrl, ENT_QUOTES) . '" data-site-id="' . htmlspecialchars($site['id'], ENT_QUOTES) . '" data-site-key="' . htmlspecialchars($site['write_key'], ENT_QUOTES) . '" data-privacy-mode="strict"></script>';
    }

    public static function create(string $siteId, string $name, string $domain = ''): array
    {
        $cleanId = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower(trim($siteId)));
        if (empty($cleanId)) {
            throw new InvalidArgumentException("Site ID must contain only letters, numbers, hyphens or underscores.");
        }

        $sites = self::all();
        foreach ($sites as $s) {
            if ($s['id'] === $cleanId) {
                throw new RuntimeException("A website with ID '{$cleanId}' already exists.");
            }
        }

        // Create the site's storage schema in the selected connector.
        Connections::open($cleanId);

        $newSite = [
            'id' => $cleanId,
            'name' => trim($name) ?: $cleanId,
            'domain' => trim($domain),
            'allowed_domains' => array_filter([self::normalizeHost($domain)]),
            'internal_ips' => [],
            'retention_days' => self::DEFAULT_RETENTION_DAYS,
            'write_key' => bin2hex(random_bytes(24)),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        $sites[] = $newSite;
        self::save($sites);

        return $newSite;
    }

    public static function update(string $siteId, array $input): array
    {
        $cleanId = self::requireId($siteId);
        $sites = self::all();
        foreach ($sites as &$site) {
            if ($site['id'] !== $cleanId) {
                continue;
            }
            if (array_key_exists('domain', $input)) {
                $site['domain'] = trim((string) $input['domain']);
            }
            if (array_key_exists('allowed_domains', $input)) {
                $raw = is_array($input['allowed_domains']) ? $input['allowed_domains'] : explode(',', (string) $input['allowed_domains']);
                $site['allowed_domains'] = array_values(array_unique(array_filter(array_map([self::class, 'normalizeHost'], $raw))));
            }
            if (array_key_exists('internal_ips', $input)) {
                $raw = is_array($input['internal_ips']) ? $input['internal_ips'] : preg_split('/[\s,]+/', (string) $input['internal_ips']);
                $site['internal_ips'] = array_values(array_unique(array_filter(array_map('trim', $raw))));
            }
            if (array_key_exists('retention_days', $input)) {
                $site['retention_days'] = self::clampRetention((int) $input['retention_days']);
            }
            if (array_key_exists('allow_localhost', $input)) {
                $site['allow_localhost'] = filter_var($input['allow_localhost'], FILTER_VALIDATE_BOOLEAN);
            }
            if (array_key_exists('is_public', $input)) {
                $site['is_public'] = filter_var($input['is_public'], FILTER_VALIDATE_BOOLEAN);
            }
            if (array_key_exists('demo_days', $input)) {
                $site['demo_days'] = DemoTraffic::days(['demo_days' => $input['demo_days']]);
                if ($site['demo_days'] === 0) {
                    unset($site['demo_days']);
                }
            }
            if (!empty($input['rotate_key'])) {
                $site['write_key'] = bin2hex(random_bytes(24));
            }
            self::save($sites);
            return $site;
        }
        throw new InvalidArgumentException('Website not found.');
    }

    public static function delete(string $siteId): bool
    {
        $cleanId = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower(trim($siteId)));
        if (empty($cleanId)) {
            throw new InvalidArgumentException("Invalid site ID.");
        }

        Connections::close($cleanId);

        // A remote connector is shared by all sites: deletion removes only the
        // registry entry, never another site's analytics tables.
        if (DatabaseConfig::load()['driver'] === 'sqlite') {
            $dbFile = DataDirectory::path() . "/{$cleanId}.db";
            if (file_exists($dbFile)) {
                @unlink($dbFile);
            }
            if (file_exists("{$dbFile}-wal")) {
                @unlink("{$dbFile}-wal");
            }
            if (file_exists("{$dbFile}-shm")) {
                @unlink("{$dbFile}-shm");
            }
        }

        $sites = self::all();
        $filtered = array_values(array_filter($sites, fn($s) => $s['id'] !== $cleanId));
        self::save($filtered);

        return true;
    }

    /** The host of a domain or URL typed by a user, such as "example.com" for "https://Example.com/blog". */
    public static function normalizeHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $parsed = parse_url(str_contains($value, '://') ? $value : '//' . $value, PHP_URL_HOST);
        $value = $parsed ?: preg_replace('#^https?://#', '', $value);
        $value = preg_replace('#/.*$#', '', (string) $value);
        return trim((string) $value, '.');
    }

    private static function path(): string
    {
        return DataDirectory::path() . '/sites.json';
    }
}
