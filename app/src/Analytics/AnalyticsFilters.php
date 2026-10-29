<?php

declare(strict_types=1);

namespace Petitstats\Analytics;

use Petitstats\Database\DatabaseConnection;

/**
 * Shared dashboard filters (Overview + Sessions).
 *
 * Filters are applied at the SESSION level: a session matches when at least one
 * of its pageviews matches (for `event`, one of its events). Values of the same
 * dimension are combined with OR, different dimensions are combined with AND.
 *
 *   ?filter_page[]=/pricing&filter_page[]=/blog&filter_referrer[]=google.com
 *   => sessions that viewed (/pricing OR /blog) AND came from google.com
 */
final class AnalyticsFilters
{
    /** Dimension => SQL expression evaluated on a pageview row. */
    private const EXPRESSIONS = [
        'page'     => "COALESCE(json_extract(action, '$.data.path'), '/')",
        'referrer' => "ml_ref_domain(json_extract(action, '$.data.referrer'))",
        'browser'  => "COALESCE(json_extract(action, '$.data.browser'), 'Unknown')",
        'os'       => "COALESCE(json_extract(action, '$.data.os'), 'Unknown')",
        'device'   => "COALESCE(json_extract(action, '$.data.device'), 'Unknown')",
        'country'  => "COALESCE(json_extract(action, '$.data.country'), 'Unknown')",
        'event'    => "json_extract(action, '$.name')",
    ];

    /** Dimensions matched against every event of a session, not only its pageviews. */
    private const ANY_EVENT = ['event'];

    private const MAX_VALUES_PER_DIMENSION = 50;

    /**
     * Reads `filter_<dimension>` (string or array) parameters from the request.
     * @return array<string, string[]>
     */
    public static function fromRequest(array $source): array
    {
        $filters = [];
        foreach (array_keys(self::EXPRESSIONS) as $dimension) {
            $raw = $source['filter_' . $dimension] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $values = is_array($raw) ? $raw : [$raw];
            $clean = [];
            foreach ($values as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $value = trim($value);
                if ($value !== '') {
                    $clean[$value] = true;
                }
            }
            if ($clean) {
                $filters[$dimension] = array_slice(array_keys($clean), 0, self::MAX_VALUES_PER_DIMENSION);
            }
        }
        return $filters;
    }

    /**
     * Same normalisation as the referrers breakdown in stats.php so that a value
     * clicked in the list matches exactly.
     */
    public static function referrerDomain($raw): string
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return 'direct';
        }
        $host = parse_url($raw, PHP_URL_HOST);
        $clean = $host ?: $raw;
        return str_replace('www.', '', $clean);
    }

    /**
     * Materialises the matching session ids in a temp table and returns a SQL
     * fragment (starting with " AND ") restricting `session_id` to them.
     * Returns an empty string when no filter is active.
     */
    public static function apply(DatabaseConnection $db, array $filters, string $startDate, string $endDate): string
    {
        if (!$filters) {
            return '';
        }

        $db->createFunction('ml_ref_domain', static fn($raw) => self::referrerDomain($raw), 1, SQLITE3_DETERMINISTIC);
        $db->exec('DROP TABLE IF EXISTS temp.ml_filtered_sessions');
        $db->exec('CREATE TEMP TABLE ml_filtered_sessions (session_id TEXT PRIMARY KEY)');

        $selects = [];
        $params = [];
        $i = 0;
        foreach ($filters as $dimension => $values) {
            $expression = self::EXPRESSIONS[$dimension] ?? null;
            if ($expression === null || !$values) {
                continue;
            }

            $placeholders = [];
            foreach ($values as $value) {
                $name = ':fv' . $i++;
                $placeholders[] = $name;
                $params[$name] = $value;
            }
            $pageviewsOnly = in_array($dimension, self::ANY_EVENT, true) ? '' : "AND json_extract(action, '$.name') = 'pageview'";
            $selects[] = "SELECT session_id FROM user_activity
                          WHERE timestamp >= :f_start AND timestamp <= :f_end
                            {$pageviewsOnly}
                            AND {$expression} IN (" . implode(', ', $placeholders) . ")";
        }

        if (!$selects) {
            return '';
        }

        $stmt = $db->prepare('INSERT OR IGNORE INTO ml_filtered_sessions (session_id) ' . implode(' INTERSECT ', $selects));
        $stmt->bindValue(':f_start', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':f_end', $endDate, SQLITE3_TEXT);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, SQLITE3_TEXT);
        }
        $stmt->execute();

        return ' AND session_id IN (SELECT session_id FROM temp.ml_filtered_sessions)';
    }
}
