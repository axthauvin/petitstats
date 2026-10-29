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
        $statement = $this->connection->prepare($this->translate($sql));
        return $statement === false ? false : new DatabaseStatement($statement, $this->isMysql());
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
