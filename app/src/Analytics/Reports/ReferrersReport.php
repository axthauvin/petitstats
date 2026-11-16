<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;
use Petitstats\Analytics\TrafficSource;
use Petitstats\Sites\SiteRegistry;
use Throwable;

/** Referring domains. */
final class ReferrersReport
{
    public function __construct(private readonly ReportQuery $query) {}

    /**
     * Referring domains by visits, excluding the website's own domains. Each
     * visit is attributed once, from the referrer of its first pageview.
     */
    public function top(int $limit = 100): array
    {
        $ownDomains = $this->ownDomains();
        // Grouped by what names the source, not by the whole action: one row
        // per page, browser or city would split a source and hide most visits.
        $sources = [];
        $total = 0;
        foreach ($this->query->periodRows("
            WITH landings AS (
                SELECT
                    json_extract(action, '$.data.referrer') as referrer,
                    json_extract(action, '$.data.utm_source') as utm_source,
                    json_extract(action, '$.data.utm.utm_source') as nested_utm_source,
                    ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY timestamp, id) as n
                FROM user_activity
                WHERE timestamp >= :start_date AND timestamp <= :end_date
                  AND json_extract(action, '$.name') = 'pageview'
                  {$this->query->condition()}
            )
            SELECT referrer, utm_source, nested_utm_source, COUNT(*) as count
            FROM landings
            WHERE n = 1
            GROUP BY referrer, utm_source, nested_utm_source
        ") as $row) {
            $count = (int) $row['count'];
            $total += $count;
            $rawRef = trim((string) ($row['referrer'] ?? ''));
            $source = TrafficSource::fromData([
                'referrer' => $rawRef,
                'utm_source' => $row['utm_source'] ?? null,
                'utm' => ['utm_source' => $row['nested_utm_source'] ?? null],
            ]);

            // MySQL/MariaDB's JSON_UNQUOTE(JSON_EXTRACT(...)) turns a JSON null
            // into the literal string "null", unlike SQLite which returns SQL
            // NULL. Treat both representations as a direct visit.
            if ($rawRef !== '' && strtolower($rawRef) !== 'null') {
                // Filter out exact self-referrals (matching the site's own domain)
                $normRef = self::normalizeDomain($rawRef);
                if ($normRef !== '' && isset($ownDomains[$normRef])) {
                    continue;
                }
            }

            $id = $source['id'];
            if (!isset($sources[$id])) {
                $sources[$id] = [
                    'id' => $id,
                    'name' => $source['name'],
                    'domain' => $source['domain'] ?? $id,
                    'url' => $source['url'],
                    'category' => $source['category'],
                    'raw' => $rawRef,
                    'visits' => 0,
                ];
            }
            $sources[$id]['visits'] += $count;
        }

        $referrers = array_values($sources);
        usort($referrers, static fn(array $a, array $b): int => $b['visits'] <=> $a['visits'] ?: strcmp($a['id'], $b['id']));
        foreach ($referrers as &$referrer) {
            $referrer['percentage'] = $total > 0 ? round(($referrer['visits'] / $total) * 100, 1) : 0;
        }
        unset($referrer);
        return array_slice($referrers, 0, $limit);
    }

    /** Hostnames the website itself is served from, to exclude self-referrals. */
    private function ownDomains(): array
    {
        $ownDomains = [];
        foreach (SiteRegistry::all() as $site) {
            if (($site['id'] ?? '') === $this->query->siteId) {
                $domain = self::normalizeDomain($site['domain'] ?? '');
                if ($domain !== '') {
                    $ownDomains[$domain] = true;
                }
                $siteId = self::normalizeDomain($site['id'] ?? '');
                if ($siteId !== '' && str_contains($siteId, '.')) {
                    $ownDomains[$siteId] = true;
                }
            }
        }
        try {
            $hosts = $this->query->db->query("
                SELECT DISTINCT json_extract(action, '$.data.hostname') as h
                FROM user_activity
                WHERE json_extract(action, '$.data.hostname') IS NOT NULL
                LIMIT 25
            ");
            if ($hosts) {
                while ($row = $hosts->fetchArray(SQLITE3_ASSOC)) {
                    $host = self::normalizeDomain((string) $row['h']);
                    if ($host !== '') {
                        $ownDomains[$host] = true;
                    }
                }
            }
        } catch (Throwable) {
        }
        return $ownDomains;
    }

    /** Normalizes a domain or URL for exact comparison (no scheme, port or "www."). */
    private static function normalizeDomain(?string $raw): string
    {
        if (!$raw) {
            return '';
        }
        $raw = trim(strtolower($raw));
        if (!str_contains($raw, '://')) {
            $raw = 'http://' . $raw;
        }
        $host = parse_url($raw, PHP_URL_HOST) ?: '';
        $host = preg_replace('/:\d+$/', '', $host); // strip port
        $host = preg_replace('/^www\./', '', $host); // strip www.
        return trim($host, '/');
    }
}
