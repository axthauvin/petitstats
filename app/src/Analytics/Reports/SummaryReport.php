<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;

/** Headline numbers: visitors, returning visitors, visits, pageviews, bounce rate and duration, live visitors. */
final class SummaryReport
{
    private ?array $result = null;

    public function __construct(private readonly ReportQuery $query) {}

    /** Distinct visitors active in the last 5 minutes. */
    public function liveVisitors(): int
    {
        $rows = $this->query->rows(
            'SELECT COUNT(DISTINCT COALESCE(visitor_id, session_id)) AS visitors FROM user_activity WHERE timestamp >= :live_time' . $this->query->condition(),
            [':live_time' => gmdate('Y-m-d H:i:s', $this->query->period->now - 300)],
        );
        return (int) ($rows[0]['visitors'] ?? 0);
    }

    /** Headline numbers for the period, with deltas and raw values of the previous period of identical length (null for all time). */
    public function get(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }
        $start = $this->query->period->startText();
        $end = $this->query->period->endText();
        $totals = $this->totals($start, $end, '<=');
        $visits = $this->visitMetrics($start, $end, '<=');

        $visitors = (int) ($totals['visitors'] ?? 0);
        $returning = $this->returningVisitors($start, $end, '<=');
        $pageviews = (int) ($totals['pageviews'] ?? 0);
        $sessions = (int) ($visits['total_sessions'] ?? $totals['sessions'] ?? 0);
        $avgDuration = round((float) ($visits['avg_duration'] ?? 0), 1);
        $bounceRate = $sessions > 0 ? round((float) ($visits['bounce_rate'] ?? 0), 1) : 0.0;

        $deltas = ['visitors' => null, 'returning_visitors' => null, 'sessions' => null, 'pageviews' => null, 'bounce_rate' => null, 'duration' => null];
        $previous = null;
        if (!$this->query->period->isAll()) {
            $previousStart = gmdate('Y-m-d H:i:s', $this->query->period->previousStart());
            $previousTotals = $this->totals($previousStart, $start, '<');
            $previousVisits = $this->visitMetrics($previousStart, $start, '<');
            $previousSessions = (int) ($previousVisits['total_sessions'] ?? 0);
            $previousBounceRate = $previousSessions > 0 ? round((float) ($previousVisits['bounce_rate'] ?? 0), 1) : 0.0;
            $previousDuration = round((float) ($previousVisits['avg_duration'] ?? 0), 1);
            $previousReturning = $this->returningVisitors($previousStart, $start, '<');
            $previous = [
                'visitors' => (int) ($previousTotals['visitors'] ?? 0),
                'returning_visitors' => $previousReturning,
                'sessions' => $previousSessions,
                'pageviews' => (int) ($previousTotals['pageviews'] ?? 0),
                'events' => (int) ($previousTotals['total_events'] ?? 0),
                'bounce_rate' => $previousBounceRate,
                'avg_duration_seconds' => $previousDuration,
            ];
            $deltas = [
                'visitors' => self::formatDiff($visitors - (int) ($previousTotals['visitors'] ?? 0)),
                'returning_visitors' => self::formatDiff($returning - $previousReturning),
                'sessions' => self::formatDiff($sessions - $previousSessions),
                'pageviews' => self::formatDiff($pageviews - (int) ($previousTotals['pageviews'] ?? 0)),
                'bounce_rate' => self::formatDiff(round($bounceRate - $previousBounceRate, 1), '%'),
                'duration' => self::formatDiff(round($avgDuration - $previousDuration), 's'),
            ];
        }

        return $this->result = [
            'visitors' => $visitors,
            'returning_visitors' => $returning,
            'sessions' => $sessions,
            'session_count' => (int) ($totals['sessions'] ?? 0),
            'pageviews' => $pageviews,
            'events' => (int) ($totals['total_events'] ?? 0),
            'bounce_rate' => $bounceRate,
            'avg_duration_seconds' => $avgDuration,
            'live_visitors' => $this->liveVisitors(),
            'deltas' => $deltas,
            'previous' => $previous,
        ];
    }

    /** Pageview, session and visitor counts between two dates. */
    private function totals(string $start, string $end, string $endOperator): array
    {
        $rows = $this->query->rows("
            SELECT
                COUNT(*) as total_events,
                SUM(CASE WHEN json_extract(action, '$.name') = 'pageview' THEN 1 ELSE 0 END) as pageviews,
                COUNT(DISTINCT session_id) as sessions,
                COUNT(DISTINCT COALESCE(visitor_id, session_id)) as visitors
            FROM user_activity
            WHERE timestamp >= :start_date AND timestamp {$endOperator} :end_date {$this->query->condition()}
        ", [':start_date' => $start, ':end_date' => $end]);
        return $rows[0] ?? [];
    }

    /**
     * Visitors active between two dates who had already come before, in an
     * earlier visit. Visitors are recognised for a month at most, since their
     * identifier changes with it.
     */
    private function returningVisitors(string $start, string $end, string $endOperator): int
    {
        // An identifier lives one calendar month at most, so earlier visits are never further back.
        $historyStart = gmdate('Y-m-d H:i:s', (int) strtotime($start . ' UTC') - 31 * 86400);
        $rows = $this->query->rows("
            SELECT COUNT(*) AS returning_visitors FROM (
                SELECT visitor_id
                FROM user_activity
                WHERE visitor_id IS NOT NULL AND timestamp >= :history_start AND timestamp {$endOperator} :end_date {$this->query->condition()}
                GROUP BY visitor_id
                HAVING MAX(timestamp) >= :start_date AND COUNT(DISTINCT session_id) > 1
            ) returning_visitors
        ", [':history_start' => $historyStart, ':start_date' => $start, ':end_date' => $end]);
        return (int) ($rows[0]['returning_visitors'] ?? 0);
    }

    /**
     * Visit count, average duration and bounce rate between two dates. A visit
     * expires after 30 minutes without an event, so a browser tab left open for
     * hours does not inflate the average visit duration.
     */
    private function visitMetrics(string $start, string $end, string $endOperator): array
    {
        $rows = $this->query->rows("
            WITH event_gaps AS (
                SELECT id,
                       session_id,
                       timestamp,
                       action,
                       LAG(timestamp) OVER (
                           PARTITION BY session_id
                           ORDER BY timestamp, id
                       ) AS previous_timestamp
                FROM user_activity
                WHERE timestamp >= :start_date AND timestamp {$endOperator} :end_date {$this->query->condition()}
            ),
            sessionized_events AS (
                SELECT id,
                       session_id,
                       timestamp,
                       action,
                       SUM(CASE
                           WHEN previous_timestamp IS NULL
                             OR strftime('%s', timestamp) - strftime('%s', previous_timestamp) >= 1800
                           THEN 1 ELSE 0
                       END) OVER (
                           PARTITION BY session_id
                           ORDER BY timestamp, id
                           ROWS UNBOUNDED PRECEDING
                       ) AS visit_number
                FROM event_gaps
            ),
            visits AS (
                SELECT session_id,
                       visit_number,
                       SUM(CASE WHEN " . ReportQuery::NOT_INTERNAL . " THEN 1 ELSE 0 END) AS action_count,
                       strftime('%s', MAX(timestamp)) - strftime('%s', MIN(timestamp)) AS duration
                FROM sessionized_events
                GROUP BY session_id, visit_number
            )
            SELECT
                COUNT(*) as total_sessions,
                AVG(duration) as avg_duration,
                100.0 * SUM(CASE WHEN action_count = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0) as bounce_rate
            FROM visits
        ", [':start_date' => $start, ':end_date' => $end]);
        return $rows[0] ?? [];
    }

    private static function formatDiff(int|float $diff, string $suffix = ''): string
    {
        if ($diff > 0) {
            return "+{$diff}{$suffix}";
        }
        if ($diff < 0) {
            return "{$diff}{$suffix}";
        }
        return "0{$suffix}";
    }
}
