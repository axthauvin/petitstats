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
        // Matched by sourceCondition(): a source is named in PHP, from these columns.
        'source'   => '',
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
        $db->exec('CREATE TEMP TABLE ml_filtered_sessions (session_id VARCHAR(64) PRIMARY KEY)');

        $selects = [];
        $params = [];
        $i = 0;
        foreach ($filters as $dimension => $values) {
            $expression = self::EXPRESSIONS[$dimension] ?? null;
            if ($expression === null || !$values) {
                continue;
            }

            if ($dimension === 'source') {
                $condition = self::sourceCondition($db, $values, $startDate, $endDate, $params, $i);
            } else {
                $placeholders = [];
                foreach ($values as $value) {
                    $name = ':fv' . $i++;
                    $placeholders[] = $name;
                    $params[$name] = $value;
                }
                $condition = "{$expression} IN (" . implode(', ', $placeholders) . ')';
            }
            $pageviewsOnly = in_array($dimension, self::ANY_EVENT, true) ? '' : "AND json_extract(action, '$.name') = 'pageview'";
            $selects[] = "SELECT session_id FROM user_activity
                          WHERE timestamp >= :f_start AND timestamp <= :f_end
                            {$pageviewsOnly}
                            AND ({$condition})";
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

    private const REFERRER = "json_extract(action, '$.data.referrer')";
    private const UTM_SOURCE = "COALESCE(json_extract(action, '$.data.utm_source'), json_extract(action, '$.data.utm.utm_source'))";

    /**
     * SQL condition on a pageview matching any of the given traffic sources.
     *
     * Sources are named by TrafficSource in PHP, and the same SQL has to run on
     * SQLite and MySQL, so the referrers and UTM sources of the period are read
     * first and those that name one of the sources are matched by value. A
     * pageview's source comes from its referrer, or from its UTM source when it
     * has no referrer, as in TrafficSource::fromData().
     *
     * @param string[] $sourceIds
     * @param array<string, string> $params placeholders added to the query
     */
    private static function sourceCondition(DatabaseConnection $db, array $sourceIds, string $startDate, string $endDate, array &$params, int &$i): string
    {
        $wanted = array_fill_keys($sourceIds, true);
        $placeholder = static function (string $value) use (&$params, &$i): string {
            $name = ':fv' . $i++;
            $params[$name] = $value;
            return $name;
        };
        $distinct = static function (string $expression, string $where = '', array $whereParams = []) use ($db, $startDate, $endDate): array {
            $stmt = $db->prepare("SELECT DISTINCT {$expression} as value FROM user_activity
                                  WHERE timestamp >= :start AND timestamp <= :end
                                    AND json_extract(action, '$.name') = 'pageview' {$where}");
            $stmt->bindValue(':start', $startDate, SQLITE3_TEXT);
            $stmt->bindValue(':end', $endDate, SQLITE3_TEXT);
            foreach ($whereParams as $name => $value) {
                $stmt->bindValue($name, $value, SQLITE3_TEXT);
            }
            $result = $stmt->execute();
            $values = [];
            while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
                $values[] = $row['value'] === null ? null : (string) $row['value'];
            }
            return $values;
        };
        $hasReferrer = static fn(?string $value): bool => $value !== null && trim($value) !== '' && strtolower(trim($value)) !== 'null';

        $referrers = [];
        $emptyReferrers = [];
        foreach ($distinct(self::REFERRER) as $referrer) {
            if (!$hasReferrer($referrer)) {
                if ($referrer !== null) {
                    $emptyReferrers[] = $placeholder($referrer);
                }
            } elseif (isset($wanted[TrafficSource::fromData(['referrer' => $referrer])['id']])) {
                $referrers[] = $placeholder($referrer);
            }
        }

        $noReferrer = self::REFERRER . ' IS NULL' . ($emptyReferrers ? ' OR ' . self::REFERRER . ' IN (' . implode(', ', $emptyReferrers) . ')' : '');
        $utmSources = [];
        $withoutUtmSource = false;
        $emptyReferrerParams = array_intersect_key($params, array_flip($emptyReferrers));
        foreach ($distinct(self::UTM_SOURCE, "AND ({$noReferrer})", $emptyReferrerParams) as $utmSource) {
            if (!isset($wanted[TrafficSource::fromData(['utm_source' => $utmSource])['id']])) {
                continue;
            }
            if ($utmSource === null) {
                $withoutUtmSource = true;
            } else {
                $utmSources[] = $placeholder($utmSource);
            }
        }

        $utmConditions = [];
        if ($utmSources) {
            $utmConditions[] = self::UTM_SOURCE . ' IN (' . implode(', ', $utmSources) . ')';
        }
        if ($withoutUtmSource) {
            $utmConditions[] = self::UTM_SOURCE . ' IS NULL';
        }

        $conditions = [];
        if ($referrers) {
            $conditions[] = self::REFERRER . ' IN (' . implode(', ', $referrers) . ')';
        }
        if ($utmConditions) {
            $conditions[] = "(({$noReferrer}) AND (" . implode(' OR ', $utmConditions) . '))';
        }
        // None of the period's pageviews comes from these sources.
        return $conditions ? implode(' OR ', $conditions) : '1 = 0';
    }
}
