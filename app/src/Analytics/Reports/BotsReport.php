<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;
use Petitstats\Database\Schema;
use Petitstats\Tracking\KnownBots;

/** Requests rejected as bot traffic. */
final class BotsReport
{
    public function __construct(private readonly ReportQuery $query) {}

    /** Robots listed per category on the dashboard. */
    private const BOTS_PER_CATEGORY = 10;

    /** Rejected requests that came from people, not robots. */
    private const NOT_ROBOTS = ['internal_traffic' => 'internal', 'client:no_interaction' => 'left'];

    /**
     * Overview of the rejected requests: totals, why they were rejected, which
     * robots sent them (see KnownBots), when, and the most recent ones.
     *
     * @return array{
     *     total: int,
     *     internal: int,
     *     left: int,
     *     reasons: list<array{reason: string, count: int}>,
     *     categories: list<array{category: string, count: int, reasons: list<array{reason: string, count: int}>}>,
     *     bots: list<array{name: string, operator: string, domain: string, category: string, count: int}>,
     *     series: list<array{time: int, category: string, name: string, count: int}>,
     *     origins: list<array{origin: string, count: int}>,
     *     recent: list<array<string, mixed>>
     * }
     */
    public function summary(): array
    {
        $totals = $this->rows('SELECT COUNT(*) AS total FROM bot_activity WHERE timestamp BETWEEN :start AND :end')[0] ?? [];
        return [
            'total' => (int) ($totals['total'] ?? 0),
            'reasons' => $this->top('reason'),
            'origins' => $this->top('origin'),
            'recent' => $this->recent(),
        ] + $this->robots();
    }

    /** The 50 most recent requests rejected as bot traffic, with the robot that sent them and its country. */
    public function recent(): array
    {
        $country = Schema::hasBotCountry($this->query->db) ? 'country_code' : 'NULL AS country_code';
        $rows = $this->rows("SELECT reason, user_agent, origin, {$country}, timestamp FROM bot_activity WHERE timestamp BETWEEN :start AND :end ORDER BY id DESC LIMIT 50");
        return array_map(static fn(array $row): array => $row + [
            'bot' => isset(self::NOT_ROBOTS[$row['reason']]) ? null : KnownBots::identify((string) $row['user_agent'], (string) $row['reason']),
        ], $rows);
    }

    /**
     * The rejected requests grouped by robot and by category, with an hourly
     * series per robot. Internal traffic and visitors who left without
     * interacting are counted apart: they are people.
     *
     * @return array{internal: int, left: int, categories: list<array{category: string, count: int, reasons: list<array{reason: string, count: int}>}>, bots: list<array<string, mixed>>, series: list<array{time: int, category: string, name: string, count: int}>}
     */
    private function robots(): array
    {
        $categories = array_fill_keys(KnownBots::CATEGORIES, 0);
        $reasons = array_fill_keys(KnownBots::CATEGORIES, []);
        $bots = $series = [];
        $people = ['internal' => 0, 'left' => 0];
        foreach ($this->rows("SELECT reason, user_agent, strftime('%Y-%m-%d %H:00:00', timestamp) AS hour, COUNT(*) AS count FROM bot_activity WHERE timestamp BETWEEN :start AND :end GROUP BY reason, user_agent, hour") as $row) {
            $count = (int) $row['count'];
            if (isset(self::NOT_ROBOTS[$row['reason']])) {
                $people[self::NOT_ROBOTS[$row['reason']]] += $count;
                continue;
            }
            $bot = KnownBots::identify((string) $row['user_agent'], (string) $row['reason']);
            $category = $bot['category'];
            $categories[$category] += $count;
            $reasons[$category][$row['reason']] = ($reasons[$category][$row['reason']] ?? 0) + $count;
            $key = $category . '|' . $bot['name'];
            $bots[$key] ??= $bot + ['count' => 0];
            $bots[$key]['count'] += $count;
            $time = (int) strtotime($row['hour'] . ' UTC');
            $series[$time . '|' . $key] ??= ['time' => $time, 'category' => $category, 'name' => $bot['name'], 'count' => 0];
            $series[$time . '|' . $key]['count'] += $count;
        }

        usort($bots, static fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
        $listed = [];
        $bots = array_values(array_filter($bots, static function (array $bot) use (&$listed): bool {
            return ($listed[$bot['category']] = ($listed[$bot['category']] ?? 0) + 1) <= self::BOTS_PER_CATEGORY;
        }));
        ksort($series);

        return $people + [
            'categories' => array_map(static function (string $category) use ($categories, $reasons): array {
                arsort($reasons[$category]);
                return [
                    'category' => $category,
                    'count' => $categories[$category],
                    'reasons' => array_map(static fn(string $reason, int $count): array => ['reason' => $reason, 'count' => $count], array_map('strval', array_keys($reasons[$category])), $reasons[$category]),
                ];
            }, KnownBots::CATEGORIES),
            'bots' => $bots,
            'series' => array_values($series),
        ];
    }

    /** @return list<array<string, mixed>> the 8 most frequent values of a column, with their counts */
    private function top(string $column): array
    {
        $rows = $this->rows("SELECT {$column} AS value, COUNT(*) AS count FROM bot_activity WHERE timestamp BETWEEN :start AND :end AND {$column} IS NOT NULL AND {$column} <> '' GROUP BY {$column} ORDER BY count DESC LIMIT 8");
        return array_map(static fn(array $row): array => [$column => (string) $row['value'], 'count' => (int) $row['count']], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        return $this->query->rows($sql, [':start' => $this->query->period->startText(), ':end' => $this->query->period->endText()]);
    }
}
