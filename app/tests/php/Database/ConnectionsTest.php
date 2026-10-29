<?php

declare(strict_types=1);

namespace Petitstats\Tests\Database;

use Petitstats\Database\Connections;
use Petitstats\Database\DataDirectory;
use Petitstats\Sites\SiteRegistry;
use PHPUnit\Framework\TestCase;
use SQLite3;

final class ConnectionsTest extends TestCase
{
    private const SITE = 'legacy_site';

    protected function tearDown(): void
    {
        SiteRegistry::delete(self::SITE);
    }

    /**
     * Databases created before visitors were told apart from sessions have no
     * visitor_id column, and before blocked requests were located no country_code.
     */
    public function testUpgradesADatabaseFromAnOlderVersion(): void
    {
        $legacy = new SQLite3(DataDirectory::path() . '/' . self::SITE . '.db');
        $legacy->exec('CREATE TABLE user_activity (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT NOT NULL, action TEXT NOT NULL, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $legacy->exec("INSERT INTO user_activity (session_id, action, timestamp) VALUES ('session-1', '{\"name\":\"pageview\"}', datetime('now'))");
        $legacy->exec('CREATE TABLE bot_activity (id INTEGER PRIMARY KEY AUTOINCREMENT, reason TEXT NOT NULL, user_agent TEXT, origin TEXT, ip_hash TEXT, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $legacy->exec("INSERT INTO bot_activity (reason) VALUES ('known_bot_user_agent')");
        $legacy->close();

        $db = Connections::forSite(self::SITE);

        $this->assertSame('session-1', $db->querySingle('SELECT visitor_id FROM user_activity'));
        $this->assertSame(1, $db->querySingle('SELECT COUNT(*) FROM bot_activity WHERE country_code IS NULL'));
        $this->assertGreaterThan(0, $db->querySingle('PRAGMA user_version'), 'the upgrade only runs once');
        $this->assertSame('idx_ua_visitor', $db->querySingle("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_ua_visitor'"));
    }

    public function testRefusesAWebsiteThatDoesNotExist(): void
    {
        $this->expectExceptionMessage('The selected website does not exist.');

        Connections::forSite('missing_site');
    }
}
