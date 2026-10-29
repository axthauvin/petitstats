<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;

/** Where visitors come from. */
final class CountriesReport
{
    public function __construct(
        private readonly ReportQuery $query,
        private readonly SummaryReport $summary,
    ) {}

    /** Visitors per country. */
    public function get(int $limit = 100): array
    {
        $totalVisitors = $this->summary->get()['visitors'];
        $countries = [];
        foreach ($this->query->periodRows("
            SELECT
                COALESCE(json_extract(action, '$.data.country'), 'Unknown') as country,
                COALESCE(json_extract(action, '$.data.country_code'), 'UN') as country_code,
                COUNT(DISTINCT COALESCE(visitor_id, session_id)) as count
            FROM user_activity
            WHERE timestamp >= :start_date AND timestamp <= :end_date
              AND json_extract(action, '$.name') = 'pageview'
              {$this->query->condition()}
            GROUP BY country, country_code
            ORDER BY count DESC
            LIMIT 100
        ") as $row) {
            $count = (int) $row['count'];
            $countries[] = [
                'name' => (string) $row['country'],
                'code' => strtoupper((string) $row['country_code']),
                'count' => $count,
                'percentage' => $totalVisitors > 0 ? round(($count / $totalVisitors) * 100, 1) : 0,
            ];
        }
        return array_slice($countries, 0, $limit);
    }
}
