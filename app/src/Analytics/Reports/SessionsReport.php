<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;

/**
 * Individual visits: what each anonymous visitor viewed and did, in order.
 * Nothing in them identifies a person: no IP address, and a visitor code that
 * changes every month.
 */
final class SessionsReport
{
    /** Context sent with every event, reported once per visit rather than as event properties. */
    private const CONTEXT_KEYS = ['path', 'title', 'hostname', 'referrer', 'language', 'screen', 'viewport', 'browser', 'os', 'device', 'country', 'country_code', 'region', 'city', '_ml_tracking_mode'];

    public function __construct(private readonly ReportQuery $query) {}

    /**
     * The latest visits of the period, newest first, each with its steps.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit = 10, int $stepsPerVisit = 30): array
    {
        $sessions = $this->query->periodRows(
            'SELECT session_id, MIN(timestamp) AS started_at FROM user_activity WHERE timestamp >= :start_date AND timestamp <= :end_date'
            . $this->query->condition() . ' GROUP BY session_id ORDER BY started_at DESC LIMIT ' . max(1, $limit),
        );
        return array_values(array_filter(array_map(
            fn(array $row): ?array => $this->visit((string) $row['session_id'], $stepsPerVisit),
            $sessions,
        )));
    }

    /**
     * One visit with all its steps, or null when there is no such visit.
     *
     * @return array<string, mixed>|null
     */
    public function visit(string $sessionId, int $maxSteps = 200): ?array
    {
        $rows = $this->query->rows('SELECT visitor_id, action, timestamp FROM user_activity WHERE session_id = :session ORDER BY timestamp, id', [':session' => $sessionId]);
        if ($rows === []) {
            return null;
        }
        $visitorId = (string) ($rows[0]['visitor_id'] ?? $sessionId);
        $first = json_decode((string) $rows[0]['action'], true)['data'] ?? [];
        $steps = [];
        $pageviews = $events = 0;
        $exitPage = null;
        foreach ($rows as $row) {
            $action = json_decode((string) $row['action'], true) ?: [];
            $name = (string) ($action['name'] ?? '');
            $data = is_array($action['data'] ?? null) ? $action['data'] : [];
            if ($name === '' || str_starts_with($name, '_ml_')) {
                continue;
            }
            $isPage = $name === 'pageview';
            if ($isPage) {
                $pageviews++;
                $exitPage = (string) ($data['path'] ?? '/');
            } else {
                $events++;
            }
            if (count($steps) >= $maxSteps) {
                continue;
            }
            $step = ['time' => gmdate('c', (int) strtotime($row['timestamp'] . ' UTC'))];
            $step += $isPage
                ? ['page' => (string) ($data['path'] ?? '/'), 'title' => (string) ($data['title'] ?? '')]
                : ['event' => $name, 'page' => (string) ($data['path'] ?? '')];
            $properties = array_diff_key($data, array_flip(self::CONTEXT_KEYS));
            if (!$isPage && $properties !== []) {
                $step['properties'] = $properties;
            }
            $steps[] = $step;
        }
        $start = (int) strtotime($rows[0]['timestamp'] . ' UTC');
        $end = (int) strtotime($rows[count($rows) - 1]['timestamp'] . ' UTC');

        return [
            'visit_id' => $sessionId,
            'visitor_id' => $visitorId,
            'visit_number' => $this->visitNumber($visitorId, $rows[0]['timestamp']),
            'started_at' => gmdate('c', $start),
            'duration_seconds' => max(0, $end - $start),
            'pageviews' => $pageviews,
            'events' => $events,
            'entry_page' => (string) ($first['path'] ?? '/'),
            'exit_page' => $exitPage,
            'referrer' => !empty($first['referrer']) ? (string) $first['referrer'] : 'Direct / None',
            'country' => (string) ($first['country'] ?? 'Unknown'),
            'city' => (string) ($first['city'] ?? ''),
            'browser' => (string) ($first['browser'] ?? 'Other'),
            'os' => (string) ($first['os'] ?? 'Other'),
            'device' => (string) ($first['device'] ?? 'Desktop'),
            'language' => (string) ($first['language'] ?? ''),
            'steps' => $steps,
            'steps_omitted' => max(0, $pageviews + $events - count($steps)),
        ];
    }

    /** 1 for a visitor's first visit, 2 when they had come once before, and so on (within one month at most). */
    private function visitNumber(string $visitorId, string $startedAt): int
    {
        $rows = $this->query->rows(
            'SELECT COUNT(DISTINCT session_id) AS earlier FROM user_activity WHERE visitor_id = :visitor AND timestamp < :started',
            [':visitor' => $visitorId, ':started' => $startedAt],
        );
        return (int) ($rows[0]['earlier'] ?? 0) + 1;
    }
}
