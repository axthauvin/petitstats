<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;

/** Custom events, overall and one at a time. */
final class EventsReport
{
    /** Fields every event carries (see petitstats.js and track.php): the other `data` fields are the event's own properties. */
    private const CONTEXT_FIELDS = [
        'tracking_mode', '_ml_tracking_mode', '_ml_revenue', 'path', 'title', 'hostname', 'referrer', 'language', 'screen', 'viewport',
        'utm', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'browser', 'os', 'device', 'country', 'country_code', 'region', 'city',
    ];

    /** Occurrences read to break an event down by property. */
    private const EVENT_SAMPLE = 5000;

    public function __construct(private readonly ReportQuery $query) {}

    /**
     * Custom events (everything but pageviews and the tracker's internal
     * signals), with their share of the 100 most frequent ones. `$search` keeps the events whose name contains it.
     */
    public function top(int $limit = 100, ?string $search = null): array
    {
        [$searchSql, $searchParams] = ReportQuery::search("json_extract(action, '$.name')", $search);
        $rows = $this->query->periodRows("
            SELECT
                json_extract(action, '$.name') as event_name,
                COUNT(*) as count
            FROM user_activity
            WHERE timestamp >= :start_date AND timestamp <= :end_date
              AND json_extract(action, '$.name') != 'pageview'
              AND " . ReportQuery::NOT_INTERNAL . "
              {$searchSql}
              {$this->query->condition()}
            GROUP BY event_name
            ORDER BY count DESC
            LIMIT 100
        ", $searchParams);
        $total = array_sum(array_map(static fn(array $row): int => (int) $row['count'], $rows));
        $events = [];
        foreach ($rows as $row) {
            $count = (int) $row['count'];
            $events[] = [
                'name' => (string) $row['event_name'],
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
        }
        return array_slice($events, 0, $limit);
    }

    /**
     * One custom event in detail: how often it fired, how it evolved, on which
     * pages, and the values of its own properties (the `data` fields the site
     * sent, without the context every event carries). Properties are read
     * from the most recent occurrences only, to keep the report fast.
     */
    public function details(string $event, ?string $property = null, int $limit = 10): array
    {
        $where = "timestamp >= :start_date AND timestamp <= :end_date AND json_extract(action, '$.name') = :event" . $this->query->condition();
        $params = [':event' => $event];
        $totals = $this->query->periodRows("SELECT COUNT(*) AS occurrences, COUNT(DISTINCT COALESCE(visitor_id, session_id)) AS visitors, COUNT(DISTINCT session_id) AS sessions FROM user_activity WHERE {$where}", $params)[0] ?? [];
        $occurrences = (int) ($totals['occurrences'] ?? 0);
        $details = ['event' => $event, 'occurrences' => $occurrences, 'visitors' => (int) ($totals['visitors'] ?? 0), 'sessions' => (int) ($totals['sessions'] ?? 0)];
        if ($occurrences === 0) {
            return $details;
        }

        $slot = $this->query->period->end - $this->query->period->start <= 2 * 86400 ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d';
        $details['trend'] = array_map(
            static fn(array $row): array => ['date' => (string) $row['slot'], 'occurrences' => (int) $row['occurrences']],
            $this->query->periodRows("SELECT strftime('{$slot}', timestamp) AS slot, COUNT(*) AS occurrences FROM user_activity WHERE {$where} GROUP BY slot ORDER BY slot", $params),
        );
        $details['pages'] = array_map(
            static fn(array $row): array => ['path' => (string) $row['path'], 'occurrences' => (int) $row['occurrences']],
            $this->query->periodRows("SELECT COALESCE(json_extract(action, '$.data.path'), '/') AS path, COUNT(*) AS occurrences FROM user_activity WHERE {$where} GROUP BY path ORDER BY occurrences DESC LIMIT " . max(1, $limit), $params),
        );

        $samples = $this->query->periodRows("SELECT action FROM user_activity WHERE {$where} ORDER BY timestamp DESC LIMIT " . self::EVENT_SAMPLE, $params);
        $counts = [];
        foreach ($samples as $row) {
            $data = json_decode((string) $row['action'], true)['data'] ?? null;
            foreach (is_array($data) ? $data : [] as $key => $value) {
                if (in_array($key, self::CONTEXT_FIELDS, true) || ($property !== null && $key !== $property)) {
                    continue;
                }
                $label = is_string($value) ? $value : (string) json_encode($value);
                $label = substr($label, 0, 200);
                $counts[$key][$label] = ($counts[$key][$label] ?? 0) + 1;
            }
        }
        $details['properties'] = [];
        foreach ($counts as $key => $values) {
            arsort($values);
            $details['properties'][$key] = array_map(
                static fn($value, int $count): array => ['value' => (string) $value, 'count' => $count],
                array_keys(array_slice($values, 0, $limit, true)),
                array_values(array_slice($values, 0, $limit, true)),
            );
        }
        $details['properties_based_on'] = count($samples);
        return $details;
    }
}
