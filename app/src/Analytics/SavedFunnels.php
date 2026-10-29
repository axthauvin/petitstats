<?php

declare(strict_types=1);

namespace Petitstats\Analytics;

use InvalidArgumentException;
use Petitstats\Database\DatabaseConnection;

/**
 * Saves the funnels, goals and journeys of a website, for the dashboard and
 * for AI assistants. FunnelsReport reads them back.
 */
final class SavedFunnels
{
    public const KINDS = ['funnel', 'goal', 'journey'];
    public const MAX_STEPS = 12;

    public static function ensureTable(DatabaseConnection $db): void
    {
        if ($db->isMysql()) {
            $db->exec("CREATE TABLE IF NOT EXISTS funnels (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'funnel', steps JSON NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS funnels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'funnel', steps TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $columns = $db->query('PRAGMA table_info(funnels)');
        $hasKind = false;
        while ($column = $columns->fetchArray(SQLITE3_ASSOC)) {
            if ($column['name'] === 'kind') {
                $hasKind = true;
            }
        }
        if (!$hasKind) {
            $db->exec("ALTER TABLE funnels ADD COLUMN kind TEXT NOT NULL DEFAULT 'funnel'");
        }
    }

    /**
     * Validates steps: each one a page (`pageview`) or a custom event, with an
     * optional label.
     *
     * @return list<array{type: string, value: string, label: string}>
     */
    private static function cleanSteps(mixed $steps): array
    {
        if (!is_array($steps) || count($steps) < 1 || count($steps) > self::MAX_STEPS) {
            throw new InvalidArgumentException('Add between 1 and ' . self::MAX_STEPS . ' steps.');
        }
        $out = [];
        foreach ($steps as $step) {
            $type = ($step['type'] ?? '') === 'pageview' ? 'pageview' : 'event';
            $value = trim((string) ($step['value'] ?? ''));
            $label = trim((string) ($step['label'] ?? ''));
            if ($value === '') {
                throw new InvalidArgumentException('Every step needs an event or page.');
            }
            $out[] = ['type' => $type, 'value' => mb_substr($value, 0, 180), 'label' => mb_substr($label ?: $value, 0, 80)];
        }
        return $out;
    }

    /**
     * Creates a funnel, goal or journey, or replaces the one with this ID, and
     * returns its ID. A goal keeps its first step, a journey its first two
     * (the entry and the exit).
     *
     * @param mixed $steps validated by cleanSteps()
     */
    public static function save(DatabaseConnection $db, ?int $id, string $name, string $kind, mixed $steps): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Give this funnel a name.');
        }
        $steps = self::cleanSteps($steps);
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'funnel';
        if ($kind === 'goal') {
            $steps = [reset($steps)];
        }
        if ($kind === 'journey') {
            if (count($steps) < 2) {
                throw new InvalidArgumentException('A journey needs an entry and an exit.');
            }
            $steps = [$steps[0], $steps[1]];
        }
        self::ensureTable($db);
        if ($id) {
            $st = $db->prepare('UPDATE funnels SET name=:name, kind=:kind, steps=:steps WHERE id=:id');
            $st->bindValue(':id', $id, SQLITE3_INTEGER);
        } else {
            $st = $db->prepare('INSERT INTO funnels (name, kind, steps) VALUES (:name, :kind, :steps)');
        }
        $st->bindValue(':name', mb_substr($name, 0, 80), SQLITE3_TEXT);
        $st->bindValue(':kind', $kind, SQLITE3_TEXT);
        $st->bindValue(':steps', json_encode($steps), SQLITE3_TEXT);
        $st->execute();
        return $id ?: $db->lastInsertRowID();
    }
}
