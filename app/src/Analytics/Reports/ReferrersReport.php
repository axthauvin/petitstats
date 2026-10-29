<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;
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
        $referrers = [];
        foreach ($this->query->periodRows("
            WITH landings AS (
                SELECT
                    json_extract(action, '$.data.referrer') as referrer,
                    ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY timestamp, id) as n
                FROM user_activity
                WHERE timestamp >= :start_date AND timestamp <= :end_date
                  AND json_extract(action, '$.name') = 'pageview'
                  {$this->query->condition()}
            )
            SELECT referrer, COUNT(*) as count, SUM(COUNT(*)) OVER () as total
            FROM landings
            WHERE n = 1
            GROUP BY referrer
            ORDER BY count DESC
            LIMIT 150
        ") as $row) {
            $rawRef = trim((string) ($row['referrer'] ?? ''));
            $cleanRef = 'Direct / None';
            $domain = 'direct';

            // MySQL/MariaDB's JSON_UNQUOTE(JSON_EXTRACT(...)) turns a JSON null
            // into the literal string "null", unlike SQLite which returns SQL
            // NULL. Treat both representations as a direct visit.
            if ($rawRef !== '' && strtolower($rawRef) !== 'null') {
                $parsed = parse_url($rawRef, PHP_URL_HOST);
                $cleanRef = $parsed ?: $rawRef;
                $domain = str_replace('www.', '', $cleanRef);

                // Filter out exact self-referrals (matching the site's own domain)
                $normRef = self::normalizeDomain($cleanRef);
                if ($normRef !== '' && isset($ownDomains[$normRef])) {
                    continue;
                }
            }

            $count = (int) $row['count'];
            $total = (int) $row['total'];
            $referrers[] = [
                'name' => $cleanRef,
                'domain' => $domain,
                'raw' => $rawRef,
                'visits' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
            if (count($referrers) >= 100) {
                break;
            }
        }
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
