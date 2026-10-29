<?php

declare(strict_types=1);

namespace Petitstats\Analytics;

use Petitstats\Database\DatabaseConnection;

/**
 * What every report queries: one website's database over one period, with the
 * dashboard filters applied. Reports built on the same instance share the
 * filtered sessions, which are only materialised once.
 */
final class ReportQuery
{
    /**
     * SQL condition keeping the actions sent by the tracker itself, such as
     * _ml_engaged, out of the custom events. Not a LIKE: "_" is a wildcard there.
     */
    public const NOT_INTERNAL = "substr(json_extract(action, '$.name'), 1, 4) <> '_ml_'";

    private ?string $condition = null;

    /** @param array<string, string[]> $filters see AnalyticsFilters::fromRequest() */
    public function __construct(
        public readonly DatabaseConnection $db,
        public readonly string $siteId,
        public readonly Period $period,
        public readonly array $filters = [],
    ) {}

    /** SQL fragment (starting with " AND ") restricting a query to the sessions matching the filters. */
    public function condition(): string
    {
        // The matching window also covers the previous period used for deltas.
        return $this->condition ??= AnalyticsFilters::apply(
            $this->db,
            $this->filters,
            gmdate('Y-m-d H:i:s', $this->period->previousStart()),
            $this->period->endText(),
        );
    }

    /**
     * Runs a query bound to the current period (`:start_date` / `:end_date`).
     *
     * @param array<string, string> $params other placeholders of the query
     * @return list<array<string, mixed>>
     */
    public function periodRows(string $sql, array $params = []): array
    {
        return $this->rows($sql, [':start_date' => $this->period->startText(), ':end_date' => $this->period->endText()] + $params);
    }

    /**
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, SQLITE3_TEXT);
        }
        $result = $stmt->execute();
        $rows = [];
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Case-insensitive "contains" condition on an SQL expression, or nothing
     * without a search. instr() avoids escaping LIKE wildcards.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function search(string $expression, ?string $search): array
    {
        $search = trim((string) $search);
        return $search === '' ? ['', []] : ["AND instr(lower({$expression}), lower(:search)) > 0", [':search' => $search]];
    }
}
