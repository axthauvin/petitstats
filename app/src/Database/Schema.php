<?php

declare(strict_types=1);

namespace Petitstats\Database;

use PDOException;

/** The tables of a website's analytics, created and upgraded when a connection opens. */
final class Schema
{
    /** Raised whenever ensure() adds a table, a column or an index, so that SQLite databases are upgraded once. */
    private const VERSION = 1;

    /** @var array<int, bool> whether each open connection's bot_activity table has a country_code column */
    private static array $botCountry = [];

    public static function ensure(DatabaseConnection $db): void
    {
        if ($db->isMysql()) {
            $db->exec("CREATE TABLE IF NOT EXISTS user_activity (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NULL, action JSON NOT NULL, timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_ua_timestamp (timestamp), INDEX idx_ua_session (session_id), INDEX idx_ua_visitor (visitor_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("CREATE TABLE IF NOT EXISTS bot_activity (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, reason VARCHAR(100) NOT NULL, user_agent VARCHAR(500) NULL, origin VARCHAR(255) NULL, ip_hash VARCHAR(64) NULL, country_code CHAR(2) NULL, timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_bot_timestamp (timestamp)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::$botCountry[spl_object_id($db)] = self::addBotCountry($db);
            $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (bucket VARCHAR(20) NOT NULL, ip_hash VARCHAR(64) NOT NULL, count INT NOT NULL DEFAULT 0, PRIMARY KEY(bucket, ip_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }
        // An up-to-date database needs none of the statements below.
        if ((int) $db->querySingle('PRAGMA user_version') >= self::VERSION) {
            self::$botCountry[spl_object_id($db)] = true;
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS user_activity (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT NOT NULL, visitor_id TEXT, action TEXT NOT NULL, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE IF NOT EXISTS bot_activity (id INTEGER PRIMARY KEY AUTOINCREMENT, reason TEXT NOT NULL, user_agent TEXT, origin TEXT, ip_hash TEXT, country_code TEXT, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT NOT NULL, ip_hash TEXT NOT NULL, count INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(bucket, ip_hash))");
        self::addVisitorId($db);
        $upgraded = self::$botCountry[spl_object_id($db)] = self::addBotCountry($db);
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ua_timestamp ON user_activity(timestamp)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ua_session ON user_activity(session_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ua_visitor ON user_activity(visitor_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bot_timestamp ON bot_activity(timestamp)');
        if ($upgraded) {
            $db->exec('PRAGMA user_version = ' . self::VERSION);
        }
    }

    /** Whether rejected requests can be stored with their country (see addBotCountry). */
    public static function hasBotCountry(DatabaseConnection $db): bool
    {
        return self::$botCountry[spl_object_id($db)] ?? true;
    }

    /**
     * Databases created before blocked requests were located have no
     * country_code. Tells whether the column is there once done.
     */
    private static function addBotCountry(DatabaseConnection $db): bool
    {
        $columns = $db->query($db->isMysql() ? "SHOW COLUMNS FROM bot_activity LIKE 'country_code'" : 'PRAGMA table_info(bot_activity)');
        while ($columns !== false && ($column = $columns->fetchArray(SQLITE3_ASSOC)) !== false) {
            if (($column['name'] ?? $column['Field'] ?? null) === 'country_code') {
                return true;
            }
        }
        try {
            return @$db->exec('ALTER TABLE bot_activity ADD COLUMN country_code ' . ($db->isMysql() ? 'CHAR(2) NULL' : 'TEXT')) !== false;
        } catch (PDOException) {
            // Another request may have added it first; otherwise the account cannot alter tables.
            $check = $db->query("SHOW COLUMNS FROM bot_activity LIKE 'country_code'");
            return $check !== false && $check->fetchArray(SQLITE3_ASSOC) !== false;
        }
    }

    /** SQLite databases created before visitors were told apart from sessions have no visitor_id. */
    private static function addVisitorId(DatabaseConnection $db): void
    {
        $columns = $db->query('PRAGMA table_info(user_activity)');
        while ($columns !== false && ($column = $columns->fetchArray(SQLITE3_ASSOC)) !== false) {
            if ($column['name'] === 'visitor_id') {
                return;
            }
        }
        @$db->exec('ALTER TABLE user_activity ADD COLUMN visitor_id TEXT');
        @$db->exec('UPDATE user_activity SET visitor_id = session_id WHERE visitor_id IS NULL');
    }
}
