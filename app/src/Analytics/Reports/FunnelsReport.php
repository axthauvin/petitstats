<?php

declare(strict_types=1);

namespace Petitstats\Analytics\Reports;

use Petitstats\Analytics\ReportQuery;
use Throwable;

/** Funnels, goals and journeys: the saved ones and how far visits went through them. */
final class FunnelsReport
{
    public function __construct(private readonly ReportQuery $query) {}

    /**
     * How many visits reached each step of a funnel, in order. A step is a page
     * (`pageview`) or a custom event; a visit counts for a step once it went
     * through every previous step first.
     *
     * @param list<array{type: string, value: string}> $steps
     * @return list<int>
     */
    public function progress(array $steps): array
    {
        $result = $this->query->db->prepare('SELECT session_id, action FROM user_activity WHERE timestamp >= :start AND timestamp <= :end' . $this->query->condition() . ' ORDER BY session_id, timestamp, id');
        $result->bindValue(':start', $this->query->period->startText(), SQLITE3_TEXT);
        $result->bindValue(':end', $this->query->period->endText(), SQLITE3_TEXT);
        $rows = $result->execute();
        // Number of consecutive steps each session has reached so far.
        $reached = [];
        while ($rows && ($row = $rows->fetchArray(SQLITE3_ASSOC))) {
            $session = $row['session_id'];
            $at = $reached[$session] ?? 0;
            if ($at >= count($steps)) {
                continue;
            }
            $action = json_decode($row['action'], true) ?: [];
            $name = (string) ($action['name'] ?? '');
            $path = (string) ($action['data']['path'] ?? '');
            $wanted = $steps[$at];
            $matches = $wanted['type'] === 'pageview' ? ($name === 'pageview' && $path === $wanted['value']) : ($name === $wanted['value']);
            if ($matches) {
                $reached[$session] = $at + 1;
            }
        }
        $progress = array_fill(0, count($steps), 0);
        foreach ($reached as $at) {
            for ($i = 0; $i < $at; $i++) {
                $progress[$i]++;
            }
        }
        return $progress;
    }

    /**
     * The paths visits took from an entry step to an exit step: how many visits
     * entered, how many reached the exit, and the most common steps in between
     * as a tree (the 3 most common next steps at each level, 12 steps at most).
     *
     * @param list<array{type: string, value: string}> $steps the entry and the exit
     * @param bool $allTime over all the data instead of the period
     * @return array{entered: int, completed: int, tree: array<string, mixed>}
     */
    public function journey(array $steps, bool $allTime = false): array
    {
        if (count($steps) < 2) {
            return ['entered' => 0, 'completed' => 0, 'tree' => ['count' => 0, 'children' => []]];
        }
        $stmt = $this->query->db->prepare('SELECT session_id, action FROM user_activity WHERE timestamp >= :start AND timestamp <= :end' . $this->query->condition() . ' ORDER BY session_id, timestamp, id');
        $stmt->bindValue(':start', $allTime ? '1970-01-01 00:00:00' : $this->query->period->startText(), SQLITE3_TEXT);
        $stmt->bindValue(':end', $allTime ? '9999-12-31 23:59:59' : $this->query->period->endText(), SQLITE3_TEXT);
        $res = $stmt->execute();
        [$entry, $exit] = $steps;
        $currentSession = null;
        $hasEntered = false;
        $capturing = false;
        $branchPath = [];
        $entered = 0;
        $completed = 0;
        $tree = ['count' => 0, 'children' => []];
        while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
            if ($currentSession !== $row['session_id']) {
                $currentSession = $row['session_id'];
                $hasEntered = false;
                $capturing = false;
                $branchPath = [];
            }
            $action = json_decode($row['action'], true) ?: [];
            $name = (string) ($action['name'] ?? '');
            $actionPath = (string) ($action['data']['path'] ?? '');
            if (!$hasEntered && self::matches($entry, $name, $actionPath)) {
                $entered++;
                $hasEntered = true;
                $capturing = true;
                $branchPath = [];
                continue;
            }
            if (!$capturing) {
                continue;
            }
            $type = $name === 'pageview' ? 'pageview' : 'event';
            $value = $type === 'pageview' ? ($actionPath ?: '/') : $name;
            // Internal signals such as _ml_engaged are not steps the visitor took.
            if ($value === '' || str_starts_with($name, '_ml_')) {
                continue;
            }
            $branchPath[] = ['key' => $type . '|' . $value, 'type' => $type, 'value' => $value];
            if (self::matches($exit, $name, $actionPath)) {
                $completed++;
                self::addPath($tree, $branchPath);
                $capturing = false;
            } elseif (count($branchPath) >= 12) {
                $capturing = false;
            }
        }
        $trim = static function (array $node) use (&$trim): array {
            $children = array_values($node['children'] ?? []);
            usort($children, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
            $node['children'] = array_map($trim, array_slice($children, 0, 3));
            return $node;
        };
        return ['entered' => $entered, 'completed' => $completed, 'tree' => $trim($tree)];
    }

    /** @param array{type: string, value: string} $step */
    private static function matches(array $step, string $name, string $path): bool
    {
        return $step['type'] === 'pageview' ? ($name === 'pageview' && $path === $step['value']) : ($name === $step['value']);
    }

    /** Adds one completed journey to the tree, counting each step along its branch. */
    private static function addPath(array &$tree, array $branchPath): void
    {
        $node = &$tree;
        $node['count']++;
        foreach ($branchPath as $branch) {
            if (!isset($node['children'][$branch['key']])) {
                $node['children'][$branch['key']] = ['type' => $branch['type'], 'value' => $branch['value'], 'count' => 0, 'children' => []];
            }
            $node = &$node['children'][$branch['key']];
            $node['count']++;
        }
        unset($node);
    }

    /**
     * Funnels, goals and journeys saved in the dashboard.
     *
     * @return list<array{id: int, name: string, kind: string, steps: list<array{type: string, value: string, label?: string}>}>
     */
    public function saved(): array
    {
        // The table only exists once someone opened the funnels page: SQLite then
        // warns and returns false, PDO (MySQL) throws.
        try {
            $result = @$this->query->db->query('SELECT id, name, kind, steps FROM funnels ORDER BY created_at DESC, id DESC');
        } catch (Throwable) {
            return [];
        }
        $funnels = [];
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $funnels[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'kind' => (string) ($row['kind'] ?: 'funnel'), 'steps' => json_decode((string) $row['steps'], true) ?: []];
        }
        return $funnels;
    }
}
