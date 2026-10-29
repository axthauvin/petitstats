<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use InvalidArgumentException;
use Petitstats\Analytics\ReportQuery;

/** Browsers, operating systems and devices. */
final class EnvironmentReport
{
    public const FIELDS = ['browser', 'os', 'device'];

    public function __construct(
        private readonly ReportQuery $query,
        private readonly SummaryReport $summary,
    ) {}

    /**
     * Browsers, operating systems or devices. These are audience dimensions:
     * a visitor is counted once for each value they used during the period.
     */
    public function get(string $field, int $limit = 50): array
    {
        if (!in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException('Unknown environment dimension: ' . $field);
        }
        $totalVisitors = $this->summary->get()['visitors'];
        $list = [];
        foreach ($this->query->periodRows("
            SELECT
                COALESCE(json_extract(action, '$.data.{$field}'), 'Unknown') as label,
                COUNT(DISTINCT COALESCE(visitor_id, session_id)) as count
            FROM user_activity
            WHERE timestamp >= :start_date AND timestamp <= :end_date
              AND json_extract(action, '$.name') = 'pageview'
              {$this->query->condition()}
            GROUP BY label
            ORDER BY count DESC
            LIMIT 50
        ") as $row) {
            $count = (int) $row['count'];
            $list[] = [
                'name' => (string) $row['label'],
                'count' => $count,
                'percentage' => $totalVisitors > 0 ? round(($count / $totalVisitors) * 100, 1) : 0,
            ];
        }
        return array_slice($list, 0, $limit);
    }
}
