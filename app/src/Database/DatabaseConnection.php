<?php

declare(strict_types=1);

namespace Petitstats\Database;

use PDO;
use SQLite3;

/** Connection wrapper translating SQLite-flavoured SQL for MySQL/MariaDB. */
final class DatabaseConnection
{
    public function __construct(private SQLite3|PDO $connection, private string $driver, private string $siteId = '') {}
    public function isMysql(): bool
    {
        return $this->driver !== 'sqlite';
    }
    public function siteId(): string
    {
        return $this->siteId;
    }
    public function prepare(string $sql): DatabaseStatement|false
    {
        $sql = $this->translate($sql);
        $placeholders = [];
        if ($this->isMysql()) {
            [$sql, $placeholders] = self::uniquePlaceholders($sql);
        }
        $statement = $this->connection->prepare($sql);
        return $statement === false ? false : new DatabaseStatement($statement, $this->isMysql(), $placeholders);
    }

    /**
     * SQLite lets a query use a named parameter several times, and ignores a
     * value bound to a parameter it does not use. PDO's MySQL driver, with real
     * prepared statements, accepts neither. This renames each repeat of a
     * parameter (":start", ":start__2"…), outside string literals.
     *
     * @return array{0: string, 1: array<string, string[]>} the SQL, and each parameter's names in it
     */
    public static function uniquePlaceholders(string $sql): array
    {
        $placeholders = [];
        $sql = (string) preg_replace_callback(
            "/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\"|(?<![:\\w]):([A-Za-z_]\\w*)/",
            static function (array $match) use (&$placeholders): string {
                if (!isset($match[1])) {
                    return $match[0];
                }
                $name = ':' . $match[1];
                $count = count($placeholders[$name] ?? []);
                $alias = $count === 0 ? $name : $name . '__' . ($count + 1);
                $placeholders[$name][] = $alias;
                return $alias;
            },
            $sql,
        );
        return [$sql, $placeholders];
    }
    public function query(string $sql): DatabaseResult|false
    {
        $result = $this->connection->query($this->translate($sql));
        return $result === false ? false : new DatabaseResult($result);
    }
    public function exec(string $sql): int|bool
    {
        // SQLite pragmas have no MySQL equivalent and are intentionally no-ops.
        if ($this->isMysql() && preg_match('/^\s*PRAGMA\b/i', $sql)) {
            return true;
        }
        return $this->connection->exec($this->translate($sql));
    }
    public function querySingle(string $sql): mixed
    {
        $result = $this->query($sql);
        if ($result === false) {
            return null;
        }
        $row = $result->fetchArray(SQLITE3_NUM);
        return $row === false ? null : $row[0];
    }
    public function changes(): int
    {
        return $this->connection instanceof SQLite3 ? $this->connection->changes() : $this->connection->query('SELECT ROW_COUNT()')->fetchColumn();
    }
    public function lastInsertRowID(): int
    {
        return $this->connection instanceof SQLite3 ? $this->connection->lastInsertRowID() : (int) $this->connection->lastInsertId();
    }
    public function close(): bool
    {
        if ($this->connection instanceof SQLite3) {
            return $this->connection->close();
        }
        return true;
    }
    public function createFunction(string $name, callable $callback, int $argumentCount, int $flags = 0): bool
    {
        return $this->connection instanceof SQLite3 ? $this->connection->createFunction($name, $callback, $argumentCount, $flags) : true;
    }
    private function translate(string $sql): string
    {
        if (!$this->isMysql()) {
            return $sql;
        }
        // Remote connectors are shared, so physical table names stay isolated
        // per website just as they are with individual SQLite files.
        $prefix = 'ml_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($this->siteId)) . '_';
        foreach (['user_activity', 'bot_activity', 'rate_limits', 'funnels'] as $table) {
            $sql = preg_replace('/\b' . $table . '\b/i', $prefix . $table, $sql);
        }
        $sql = preg_replace_callback("/json_extract\(([^,]+),\s*('\\$[^']*')\)/i", static fn($m) => "JSON_UNQUOTE(JSON_EXTRACT({$m[1]}, {$m[2]}))", $sql);

        // Translate SQLite's strftime() to MySQL's DATE_FORMAT() or UNIX_TIMESTAMP().
        $sql = preg_replace_callback(
            "/strftime\('([^']+)',\s*(timestamp|previous_timestamp|MAX\(timestamp\)|MIN\(timestamp\))\)/i",
            static function (array $m): string {
                $format = $m[1];
                $expression = $m[2];
                if ($format === '%s') {
                    return "UNIX_TIMESTAMP({$expression})";
                }

                // SQLite's %M means minutes, while MySQL's %i means minutes.
                return "DATE_FORMAT({$expression}, '" . str_replace('%M', '%i', $format) . "')";
            },
            $sql,
        );
        $sql = str_ireplace('INSERT OR IGNORE', 'INSERT IGNORE', $sql);
        $sql = str_ireplace('temp.ml_filtered_sessions', 'ml_filtered_sessions', $sql);
        $sql = preg_replace('/CREATE\s+TEMP\s+TABLE/i', 'CREATE TEMPORARY TABLE', $sql);
        $sql = preg_replace('/DROP\s+TABLE\s+IF\s+EXISTS\s+temp\./i', 'DROP TEMPORARY TABLE IF EXISTS ', $sql);
        // Both MySQL and MariaDB accept this native upsert syntax.
        $sql = preg_replace('/ON\s+CONFLICT\s*\(([^)]+)\)\s*DO\s*UPDATE\s*SET\s*count\s*=\s*count\s*\+\s*1/i', 'ON DUPLICATE KEY UPDATE count = count + 1', $sql);
        $sql = str_ireplace('ml_ref_domain(JSON_UNQUOTE(JSON_EXTRACT(action, \'$.data.referrer\')))', "COALESCE(NULLIF(NULLIF(REPLACE(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(action, '$.data.referrer')), '/', 1), 'www.', ''), ''), 'null'), 'direct')", $sql);
        return $sql;
    }
}
