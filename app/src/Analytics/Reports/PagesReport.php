<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;

/** Most viewed pages. */
final class PagesReport
{
    public function __construct(
        private readonly ReportQuery $query,
        private readonly SummaryReport $summary,
    ) {}

    /**
     * Most viewed pages, with their share of all pageviews. `$search` keeps the
     * pages whose path contains it (case-insensitive).
     */
    public function top(int $limit = 100, ?string $search = null): array
    {
        $totalPageviews = $this->summary->get()['pageviews'];
        [$searchSql, $searchParams] = ReportQuery::search("COALESCE(json_extract(action, '$.data.path'), '/')", $search);
        $pages = [];
        foreach ($this->query->periodRows("
            SELECT
                COALESCE(json_extract(action, '$.data.path'), '/') as path,
                MAX(json_extract(action, '$.data.title')) as title,
                COUNT(*) as views,
                COUNT(DISTINCT COALESCE(visitor_id, session_id)) as visitors
            FROM user_activity
            WHERE timestamp >= :start_date AND timestamp <= :end_date
              AND json_extract(action, '$.name') = 'pageview'
              {$searchSql}
              {$this->query->condition()}
            GROUP BY path
            ORDER BY views DESC
            LIMIT 100
        ", $searchParams) as $row) {
            $views = (int) $row['views'];
            $pages[] = [
                'path' => $row['path'] ?: '/',
                'title' => $row['title'] ?: $row['path'],
                'views' => $views,
                'visitors' => (int) $row['visitors'],
                'percentage' => $totalPageviews > 0 ? round(($views / $totalPageviews) * 100, 1) : 0,
            ];
        }
        return array_slice($pages, 0, $limit);
    }
}
