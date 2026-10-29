<?php

declare(strict_types=1);

namespace Petitstats\Analytics;

/**
 * Reporting period shared by every analytics endpoint.
 *
 * Presets ("7d", "30d", "all"…) end now; "custom" spans whole UTC days. Any
 * request carrying both `from` and `to` is treated as custom.
 */
final class Period
{
    public const RANGES = ['today', '24h', '7d', '30d', '90d', '6m', '180d', 'all', 'custom'];

    public function __construct(
        public readonly string $range,
        public readonly int $start,
        public readonly int $end,
        public readonly int $now,
    ) {}

    /** Reads `range`, `from` and `to` from the query string. */
    public static function fromRequest(array $query, ?int $now = null): self
    {
        $from = $query['from'] ?? null;
        $to = $query['to'] ?? null;
        return self::from((string) ($query['range'] ?? '7d'), is_string($from) ? $from : null, is_string($to) ? $to : null, $now);
    }

    public static function from(string $range, ?string $from = null, ?string $to = null, ?int $now = null): self
    {
        $now ??= time();
        if ($range === 'custom' || (!empty($from) && !empty($to))) {
            // Swap reversed dates first, so both days stay whole.
            if (!empty($from) && !empty($to) && strtotime($from) > strtotime($to)) {
                [$from, $to] = [$to, $from];
            }
            $start = !empty($from) ? (strtotime($from . ' 00:00:00 UTC') ?: $now - 30 * 86400) : $now - 30 * 86400;
            $end = !empty($to) ? (strtotime($to . ' 23:59:59 UTC') ?: $now) : $now;
            return new self('custom', min($start, $end), max($start, $end), $now);
        }
        $start = match ($range) {
            'today' => (int) strtotime('today midnight UTC', $now),
            '24h' => $now - 86400,
            '7d' => $now - 7 * 86400,
            '30d' => $now - 30 * 86400,
            '90d' => $now - 90 * 86400,
            '6m', '180d' => $now - 180 * 86400,
            'all' => 0,
            default => $now - 7 * 86400,
        };
        return new self($range, $start, $now, $now);
    }

    public function isAll(): bool
    {
        return $this->range === 'all';
    }

    /** Start of the previous period of identical length, used for deltas. */
    public function previousStart(): int
    {
        return max(0, $this->start - max(3600, $this->end - $this->start));
    }

    public function startText(): string
    {
        return gmdate('Y-m-d H:i:s', $this->start);
    }

    public function endText(): string
    {
        return gmdate('Y-m-d H:i:s', $this->end);
    }
}
