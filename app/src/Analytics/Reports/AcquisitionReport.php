<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;

/** How visits start and end: sources, campaigns, channels, landing and exit pages. */
final class AcquisitionReport
{
    public function __construct(private readonly ReportQuery $query) {}

    /**
     * Sessions per source, medium, campaign, channel, landing and exit page.
     * Each session is attributed from its first pageview (exit page: its last).
     *
     * @return array<string, list<array{name: string, sessions: int}>>
     */
    public function get(): array
    {
        $rows = $this->query->rows(
            "WITH pageviews AS (SELECT session_id, timestamp, id, action, ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY timestamp,id) AS first_n, ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY timestamp DESC,id DESC) AS last_n FROM user_activity WHERE timestamp BETWEEN :start AND :end AND json_extract(action,'$.name')='pageview'" . $this->query->condition() . ') SELECT session_id, action, first_n, last_n FROM pageviews WHERE first_n=1 OR last_n=1',
            [':start' => $this->query->period->startText(), ':end' => $this->query->period->endText()],
        );
        $reports = ['sources' => [],'mediums' => [],'campaigns' => [],'contents' => [],'terms' => [],'channels' => [],'landing_pages' => [],'exit_pages' => []];
        $inc = function (string $report, string $key) use (&$reports): void {
            $key = trim($key) ?: '(not set)';
            $reports[$report][$key] = ($reports[$report][$key] ?? 0) + 1;
        };
        foreach ($rows as $row) {
            $data = (json_decode($row['action'], true)['data'] ?? []);
            if ((int) $row['first_n'] === 1) {
                foreach (self::attribution($data) as $report => $key) {
                    $inc($report, $key);
                }
            }
            if ((int) $row['last_n'] === 1) {
                $inc('exit_pages', (string) ($data['path'] ?? '/'));
            }
        }
        foreach ($reports as $key => $list) {
            arsort($list);
            $reports[$key] = array_map(fn($name, $count) => ['name' => $name,'sessions' => $count], array_keys(array_slice($list, 0, 100, true)), array_values(array_slice($list, 0, 100, true)));
        }
        return $reports;
    }

    /**
     * Where a session comes from, read from its first pageview: one value per
     * report (sources, mediums, campaigns, contents, terms, landing_pages, channels).
     *
     * @param array<string, mixed> $data the `data` of the session's first pageview
     * @return array<string, string>
     */
    public static function attribution(array $data): array
    {
        $utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];
        $source = (string) ($data['utm_source'] ?? $utm['utm_source'] ?? '');
        $medium = (string) ($data['utm_medium'] ?? $utm['utm_medium'] ?? '');
        $ref = (string) ($data['referrer'] ?? '');
        $channel = $medium ? ucfirst(strtolower($medium)) : ($ref ? 'Referral' : 'Direct');
        // If the source or referrer is a search engine, attribute the session to the Organic Search channel.
        if (preg_match('/google|bing|duckduckgo|yahoo/', $source . ' ' . $ref)) {
            $channel = 'Organic Search';
        } elseif (preg_match('/facebook|instagram|linkedin|twitter|tiktok/', $source . ' ' . $ref)) {
            $channel = 'Social';
        }
        // Webmail clients commonly appear as referrers without UTM
        // parameters. Attribute those sessions to Email rather than the
        // generic Referral channel.
        elseif (preg_match('/email|newsletter/', $medium) || preg_match('/(^|\.)(mail\.google|gmail|outlook|outlook\.office|mail\.yahoo|mail\.proton|protonmail|mail\.icloud)\./i', $ref)) {
            $channel = 'Email';
        } elseif (preg_match('/cpc|ppc|paid|display/', $medium)) {
            $channel = 'Paid';
        }
        return [
            'sources' => $source ?: ($ref ?: 'Direct'),
            'mediums' => $medium ?: ($ref ? 'referral' : '(none)'),
            'campaigns' => (string) ($data['utm_campaign'] ?? $utm['utm_campaign'] ?? ''),
            'contents' => (string) ($data['utm_content'] ?? $utm['utm_content'] ?? ''),
            'terms' => (string) ($data['utm_term'] ?? $utm['utm_term'] ?? ''),
            'landing_pages' => (string) ($data['path'] ?? '/'),
            'channels' => $channel,
        ];
    }
}
