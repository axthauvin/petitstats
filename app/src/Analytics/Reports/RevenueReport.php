<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;

/**
 * Money brought in by the events sent with
 * `petitstats.revenue("purchase", 49.9, { currency: "EUR", order_id: "1042" })`,
 * which store the amount as `_ml_revenue`. A plain `revenue` property is not money.
 */
final class RevenueReport
{
    /** Breakdowns read from the session's first pageview, see AcquisitionReport::attribution(). */
    private const ATTRIBUTED = ['channels', 'sources', 'campaigns', 'landing_pages'];

    public function __construct(
        private readonly ReportQuery $query,
        private readonly SummaryReport $summary,
    ) {}

    /**
     * Totals (with the previous period), trend, breakdowns and latest orders.
     *
     * Amounts are only added up in one currency: the one of most orders.
     * Orders without a currency count in it, orders in another one are only
     * listed in `other_currencies`. An `order_id` is counted once.
     */
    public function get(): array
    {
        $period = $this->query->period;
        $hasPrevious = !$period->isAll();
        $orders = $this->orders($hasPrevious ? gmdate('Y-m-d H:i:s', $period->previousStart()) : $period->startText());

        $byCurrency = [];
        foreach ($orders as $order) {
            if ($order['currency'] !== '' && $order['time'] >= $period->start) {
                $byCurrency[$order['currency']] = ($byCurrency[$order['currency']] ?? 0) + 1;
            }
        }
        arsort($byCurrency);
        $currency = (string) (array_key_first($byCurrency) ?? '');
        $orders = array_filter($orders, static fn(array $order): bool => $order['currency'] === '' || $order['currency'] === $currency);
        $current = array_values(array_filter($orders, static fn(array $order): bool => $order['time'] >= $period->start));
        $previous = array_values(array_filter($orders, static fn(array $order): bool => $order['time'] < $period->start));

        $summary = $this->summary->get();
        return [
            'currency' => $currency !== '' ? $currency : null,
            'other_currencies' => array_map(
                static fn(string $code, int $count): array => ['currency' => $code, 'orders' => $count],
                array_keys(array_slice($byCurrency, 1, null, true)),
                array_values(array_slice($byCurrency, 1, null, true)),
            ),
            'summary' => self::totals($current, $summary['visitors']) + [
                'previous' => $hasPrevious ? self::totals($previous, (int) ($summary['previous']['visitors'] ?? 0)) : null,
            ],
            'timeseries' => $this->timeseries($current),
            'breakdowns' => $this->breakdowns($current),
            'latest' => array_map(
                static fn(array $order): array => [
                    'timestamp' => gmdate('Y-m-d H:i:s', $order['time']),
                    'event' => $order['event'],
                    'revenue' => $order['revenue'],
                    'order_id' => $order['order_id'],
                    'path' => $order['path'],
                    'country_code' => $order['country_code'],
                    'session_id' => $order['session_id'],
                ],
                array_slice(array_reverse($current), 0, 10),
            ),
        ];
    }

    /**
     * Events with a positive amount from $start to the end of the period,
     * oldest first, each `order_id` kept once.
     *
     * @return list<array{time: int, event: string, revenue: float, currency: string, order_id: ?string, path: string, country: string, country_code: string, session_id: string, visitor: string}>
     */
    private function orders(string $start): array
    {
        $rows = $this->query->rows(
            "SELECT session_id, visitor_id, timestamp, action FROM user_activity
             WHERE timestamp >= :start AND timestamp <= :end
               AND json_extract(action, '$.data._ml_revenue') IS NOT NULL
               AND " . ReportQuery::NOT_INTERNAL . $this->query->condition() . '
             ORDER BY timestamp, id',
            [':start' => $start, ':end' => $this->query->period->endText()],
        );
        $orders = [];
        $seen = [];
        foreach ($rows as $row) {
            $action = json_decode((string) $row['action'], true);
            $data = is_array($action['data'] ?? null) ? $action['data'] : [];
            $revenue = $data['_ml_revenue'] ?? null;
            if (!is_numeric($revenue) || (float) $revenue <= 0 || !is_finite((float) $revenue)) {
                continue;
            }
            $orderId = is_scalar($data['order_id'] ?? null) && (string) $data['order_id'] !== '' ? (string) $data['order_id'] : null;
            if ($orderId !== null) {
                if (isset($seen[$orderId])) {
                    continue;
                }
                $seen[$orderId] = true;
            }
            $currency = strtoupper(trim((string) (is_scalar($data['currency'] ?? null) ? $data['currency'] : '')));
            $orders[] = [
                'time' => (int) strtotime($row['timestamp'] . ' UTC'),
                'event' => (string) ($action['name'] ?? ''),
                'revenue' => round((float) $revenue, 2),
                'currency' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : '',
                'order_id' => $orderId,
                'path' => (string) ($data['path'] ?? '/'),
                'country' => (string) ($data['country'] ?? 'Unknown'),
                'country_code' => (string) ($data['country_code'] ?? 'UN'),
                'session_id' => (string) $row['session_id'],
                'visitor' => (string) ($row['visitor_id'] ?? $row['session_id']),
            ];
        }
        return $orders;
    }

    /**
     * @param list<array{revenue: float, visitor: string}> $orders
     * @return array{revenue: float, orders: int, average_order: float, buyers: int, visitors: int, conversion_rate: float, revenue_per_visitor: float}
     */
    private static function totals(array $orders, int $visitors): array
    {
        $revenue = round(array_sum(array_column($orders, 'revenue')), 2);
        $buyers = count(array_unique(array_column($orders, 'visitor')));
        return [
            'revenue' => $revenue,
            'orders' => count($orders),
            'average_order' => $orders ? round($revenue / count($orders), 2) : 0.0,
            'buyers' => $buyers,
            'visitors' => $visitors,
            'conversion_rate' => $visitors > 0 ? round(min(100, $buyers / $visitors * 100), 2) : 0.0,
            'revenue_per_visitor' => $visitors > 0 ? round($revenue / $visitors, 2) : 0.0,
        ];
    }

    /**
     * Revenue and orders on the slots of the traffic chart.
     *
     * @param list<array{time: int, revenue: float}> $orders
     */
    private function timeseries(array $orders): array
    {
        $points = array_map(
            static fn(array $slot): array => [
                'timestamp' => $slot['timestamp'],
                'label' => $slot['label'],
                'full_label' => $slot['full_label'],
                'revenue' => 0.0,
                'orders' => 0,
            ],
            (new TimeseriesReport($this->query))->get(),
        );
        $index = count($points) - 1;
        foreach (array_reverse($orders) as $order) {
            while ($index > 0 && $points[$index]['timestamp'] > $order['time']) {
                $index--;
            }
            if ($index >= 0 && $points[$index]['timestamp'] <= $order['time']) {
                $points[$index]['revenue'] = round($points[$index]['revenue'] + $order['revenue'], 2);
                $points[$index]['orders']++;
            }
        }
        return $points;
    }

    /**
     * Revenue and orders per event, country, and per channel, source, campaign
     * and landing page of the session the order was made in.
     *
     * @param list<array{event: string, revenue: float, country: string, session_id: string}> $orders
     * @return array<string, list<array{name: string, revenue: float, orders: int}>>
     */
    private function breakdowns(array $orders): array
    {
        $attribution = [];
        if ($orders) {
            $rows = $this->query->periodRows(
                "WITH firsts AS (SELECT session_id, action, ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY timestamp, id) AS n
                 FROM user_activity WHERE json_extract(action, '$.name') = 'pageview' AND session_id IN (
                     SELECT session_id FROM user_activity WHERE timestamp >= :start_date AND timestamp <= :end_date AND json_extract(action, '$.data._ml_revenue') IS NOT NULL
                 ))
                 SELECT session_id, action FROM firsts WHERE n = 1",
            );
            foreach ($rows as $row) {
                $data = json_decode((string) $row['action'], true)['data'] ?? [];
                $attribution[$row['session_id']] = AcquisitionReport::attribution(is_array($data) ? $data : []);
            }
        }

        $reports = array_fill_keys(['events', 'countries', ...self::ATTRIBUTED], []);
        $add = static function (string $report, string $name, float $revenue) use (&$reports): void {
            $name = trim($name) ?: '(not set)';
            $reports[$report][$name] ??= ['name' => $name, 'revenue' => 0.0, 'orders' => 0];
            $reports[$report][$name]['revenue'] += $revenue;
            $reports[$report][$name]['orders']++;
        };
        foreach ($orders as $order) {
            $add('events', $order['event'], $order['revenue']);
            $add('countries', $order['country'], $order['revenue']);
            foreach (self::ATTRIBUTED as $report) {
                // Sessions without a pageview (sent from a single-page checkout, for example) have no attribution.
                $add($report, $attribution[$order['session_id']][$report] ?? '(unknown)', $order['revenue']);
            }
        }
        foreach ($reports as $key => $rows) {
            usort($rows, static fn(array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
            $reports[$key] = array_map(static fn(array $row): array => ['revenue' => round($row['revenue'], 2)] + $row, array_slice($rows, 0, 100));
        }
        return $reports;
    }
}
