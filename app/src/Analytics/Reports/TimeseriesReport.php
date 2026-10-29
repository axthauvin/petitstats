<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;

/** Traffic over time, for the dashboard chart. */
final class TimeseriesReport
{
    public function __construct(private readonly ReportQuery $query) {}

    /** Traffic per hour, day or week (depending on the period length), with empty slots filled in. */
    public function get(): array
    {
        $range = $this->query->period->range;
        $now = $this->query->period->now;
        $startUnix = $this->query->period->start;
        $endUnix = $this->query->period->end;
        $intervalHours = 24;
        $stepSeconds = 86400;
        $slotFormat = '%Y-%m-%d';
        $effectiveStart = strtotime('6 days ago midnight UTC', $now);
        $endStep = strtotime('today midnight UTC', $now);

        if ($range === 'today' || $range === '24h') {
            $intervalHours = 1;
            $stepSeconds = 3600;
            $effectiveStart = ($range === 'today') ? strtotime('today midnight UTC', $now) : floor(($now - 86400) / 3600) * 3600;
            $endStep = ceil($now / 3600) * 3600;
            $slotFormat = '%Y-%m-%d %H:00:00';
        } elseif ($range === '30d') {
            $effectiveStart = strtotime('29 days ago midnight UTC', $now);
        } elseif ($range === '90d') {
            $effectiveStart = strtotime('89 days ago midnight UTC', $now);
        } elseif ($range === '6m' || $range === '180d') {
            $effectiveStart = strtotime('179 days ago midnight UTC', $now);
        } elseif ($range === 'custom') {
            $effectiveStart = strtotime(gmdate('Y-m-d', $startUnix) . ' 00:00:00 UTC');
            $endStep = strtotime(gmdate('Y-m-d', $endUnix) . ' 00:00:00 UTC');
            $spanDays = max(1, (int) round(($endStep - $effectiveStart) / 86400));
            if ($spanDays <= 2) {
                $intervalHours = 1;
                $stepSeconds = 3600;
                $effectiveStart = floor($startUnix / 3600) * 3600;
                $endStep = ceil($endUnix / 3600) * 3600;
                $slotFormat = '%Y-%m-%d %H:00:00';
            } elseif ($spanDays > 365) {
                // Long range > 1 year: group weekly
                $intervalHours = 168;
                $stepSeconds = 7 * 86400;
            }
        } elseif ($range === 'all') {
            $minDbTime = $this->query->db->querySingle('SELECT MIN(timestamp) FROM user_activity WHERE timestamp IS NOT NULL' . $this->query->condition());
            $maxDbTime = $this->query->db->querySingle('SELECT MAX(timestamp) FROM user_activity WHERE timestamp IS NOT NULL' . $this->query->condition());

            if ($minDbTime) {
                $effectiveStart = strtotime(substr((string) $minDbTime, 0, 10) . ' 00:00:00 UTC');
                $maxDbUnix = strtotime(substr((string) $maxDbTime, 0, 10) . ' 00:00:00 UTC');
                $endStep = max(strtotime('today midnight UTC', $now), $maxDbUnix);

                $spanDays = max(1, (int) round(($endStep - $effectiveStart) / 86400));
                if ($spanDays <= 2) {
                    $intervalHours = 1;
                    $stepSeconds = 3600;
                    $endStep = ceil($now / 3600) * 3600;
                    $slotFormat = '%Y-%m-%d %H:00:00';
                } elseif ($spanDays > 730) {
                    // For long historical data (> 2 years), group weekly
                    $intervalHours = 168;
                    $stepSeconds = 7 * 86400;
                }
            }
        }

        $slotMap = [];
        foreach ($this->query->periodRows("
            SELECT
                strftime('{$slotFormat}', timestamp) as slot,
                SUM(CASE WHEN json_extract(action, '$.name') = 'pageview' THEN 1 ELSE 0 END) as views,
                COUNT(DISTINCT session_id) as sessions,
                COUNT(DISTINCT COALESCE(visitor_id, session_id)) as visitors,
                SUM(CASE WHEN json_extract(action, '$.name') != 'pageview' THEN 1 ELSE 0 END) as events,
                COUNT(*) as total_actions
            FROM user_activity
            WHERE timestamp >= :start_date AND timestamp <= :end_date {$this->query->condition()}
            GROUP BY slot
            ORDER BY slot ASC
        ") as $r) {
            $slotMap[$r['slot']] = [
                'views' => (int) $r['views'],
                'sessions' => (int) $r['sessions'],
                'visitors' => (int) $r['visitors'],
                'events' => (int) $r['events'],
            ];
        }

        // Build timeline sequence
        $spanDays = max(1, (int) round(($endStep - $effectiveStart) / 86400));
        $timeseries = [];
        $currStep = $effectiveStart;

        while ($currStep <= $endStep) {
            $views = 0;
            $sessions = 0;
            $visitors = 0;
            $events = 0;

            if ($intervalHours === 1) {
                $subKey = gmdate('Y-m-d H:00:00', (int) $currStep);
                if (isset($slotMap[$subKey])) {
                    $views = $slotMap[$subKey]['views'];
                    $sessions = $slotMap[$subKey]['sessions'];
                    $visitors = $slotMap[$subKey]['visitors'];
                    $events = $slotMap[$subKey]['events'];
                }
                $timeLabel = gmdate('h A', (int) $currStep);
                $dateLabel = gmdate('M d, Y', (int) $currStep);
                $fullLabel = gmdate('l, F j, Y \a\t h:i A', (int) $currStep);
            } elseif ($intervalHours === 24) {
                $dayKey = gmdate('Y-m-d', (int) $currStep);
                if (isset($slotMap[$dayKey])) {
                    $views = $slotMap[$dayKey]['views'];
                    $sessions = $slotMap[$dayKey]['sessions'];
                    $visitors = $slotMap[$dayKey]['visitors'];
                    $events = $slotMap[$dayKey]['events'];
                }
                if ($range === '7d') {
                    $timeLabel = gmdate('D, j M', (int) $currStep);
                } elseif ($range === '30d') {
                    $timeLabel = gmdate('M d', (int) $currStep);
                } else {
                    $timeLabel = ($spanDays > 180) ? gmdate('M y', (int) $currStep) : gmdate('M d', (int) $currStep);
                }
                $dateLabel = gmdate('M d, Y', (int) $currStep);
                $fullLabel = gmdate('l, F j, Y', (int) $currStep);
            } else {
                // Multi-day interval (e.g. weekly)
                $intervalDays = (int) ($intervalHours / 24);
                for ($sub = 0; $sub < $intervalDays; $sub++) {
                    $dayKey = gmdate('Y-m-d', (int) ($currStep + $sub * 86400));
                    if (isset($slotMap[$dayKey])) {
                        $views += $slotMap[$dayKey]['views'];
                        $sessions += $slotMap[$dayKey]['sessions'];
                        $visitors += $slotMap[$dayKey]['visitors'];
                        $events += $slotMap[$dayKey]['events'];
                    }
                }
                $timeLabel = gmdate('M d', (int) $currStep);
                $dateLabel = gmdate('M d, Y', (int) $currStep);
                $fullLabel = 'Week of ' . gmdate('l, F j, Y', (int) $currStep);
            }

            $timeseries[] = [
                'timestamp' => $currStep,
                'interval_hours' => $intervalHours,
                'label' => $timeLabel,
                'date_label' => $dateLabel,
                'full_label' => $fullLabel,
                'pageviews' => $views,
                'sessions' => $sessions,
                'visitors' => $visitors,
                'events' => $events,
            ];

            $currStep += $stepSeconds;
        }
        return $timeseries;
    }
}
