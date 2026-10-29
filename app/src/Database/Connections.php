<?php

declare(strict_types=1);

namespace Petitstats\Database;

use Petitstats\Sites\SiteRegistry;
use PDO;
use SQLite3;

/**
 * The connections to each website's analytics, opened once per request with
 * the connector of database.json.
 */
final class Connections
{
    /** @var array<string, DatabaseConnection> */
    private static array $open = [];

    /**
     * The analytics of an existing website. The first connection of a request
     * also deletes the data older than the website's retention period.
     */
    public static function forSite(?string $siteId): DatabaseConnection
    {
        $siteId = SiteRegistry::requireId($siteId);
        if (!isset(self::$open[$siteId])) {
            self::$open[$siteId] = self::open($siteId);
            self::deleteExpiredData(self::$open[$siteId], SiteRegistry::find($siteId) ?? []);
        }
        return self::$open[$siteId];
    }

    /** Opens a connection and creates the tables of the website when they do not exist yet. */
    public static function open(string $siteId): DatabaseConnection
    {
        $config = DatabaseConfig::load();
        if ($config['driver'] === 'sqlite') {
            $db = new SQLite3(DataDirectory::path() . "/{$siteId}.db");
            $db->busyTimeout(5000);
            $db->exec('PRAGMA journal_mode = WAL;');
            $connection = new DatabaseConnection($db, 'sqlite', $siteId);
        } else {
            DatabaseConfig::test($config);
            $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5, PDO::ATTR_EMULATE_PREPARES => false]);
            $connection = new DatabaseConnection($pdo, $config['driver'], $siteId);
        }
        Schema::ensure($connection);
        return $connection;
    }

    public static function close(string $siteId): void
    {
        if (isset(self::$open[$siteId])) {
            self::$open[$siteId]->close();
            unset(self::$open[$siteId]);
        }
    }

    /** Forgets every connection, for instance after switching to another connector. */
    public static function closeAll(): void
    {
        self::$open = [];
    }

    private static function deleteExpiredData(DatabaseConnection $db, array $site): void
    {
        $days = SiteRegistry::clampRetention((int) ($site['retention_days'] ?? SiteRegistry::DEFAULT_RETENTION_DAYS));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $stmt = $db->prepare('DELETE FROM user_activity WHERE timestamp < :cutoff');
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
        $stmt->execute();
        $stmt = $db->prepare('DELETE FROM bot_activity WHERE timestamp < :cutoff');
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
        $stmt->execute();
        $db->exec("DELETE FROM rate_limits WHERE bucket < '" . gmdate('YmdHi', time() - 7200) . "'");
    }
}
