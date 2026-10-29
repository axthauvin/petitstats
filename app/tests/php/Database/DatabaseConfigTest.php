<?php

declare(strict_types=1);

namespace Petitstats\Tests\Database;

use InvalidArgumentException;
use Petitstats\Database\Connections;
use Petitstats\Database\DatabaseConfig;
use Petitstats\Database\DataDirectory;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    private const MYSQL = ['driver' => 'mysql', 'host' => 'db.example.com', 'port' => 3306, 'database' => 'analytics', 'username' => 'petitstats', 'password' => 'secret'];

    protected function tearDown(): void
    {
        // The other tests use the default SQLite storage.
        @unlink(DataDirectory::path() . '/database.json');
        Connections::closeAll();
    }

    public function testUsesSqliteByDefault(): void
    {
        $this->assertSame(['driver' => 'sqlite', 'host' => '', 'port' => 3306, 'database' => '', 'username' => '', 'password' => ''], DatabaseConfig::load());
    }

    public function testNeverShowsThePassword(): void
    {
        $this->write(self::MYSQL);

        $view = DatabaseConfig::publicView();

        $this->assertArrayNotHasKey('password', $view);
        $this->assertTrue($view['configured']);
    }

    public function testARemoteDatabaseWithoutHostIsNotConfigured(): void
    {
        $this->write(['host' => ''] + self::MYSQL);

        $this->assertFalse(DatabaseConfig::publicView()['configured']);
    }

    public function testSwitchingBackToSqliteForgetsTheRemoteSettings(): void
    {
        $this->write(self::MYSQL);

        DatabaseConfig::save(['driver' => 'sqlite', 'host' => 'ignored']);

        $this->assertSame(['driver' => 'sqlite', 'host' => '', 'port' => 3306, 'database' => '', 'username' => '', 'password' => ''], DatabaseConfig::load());
    }

    public function testRefusesAnUnknownDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver.');

        DatabaseConfig::save(['driver' => 'postgres']);
    }

    public function testARemoteDatabaseNeedsAHostANameAndAUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Host, database name and username are required.');

        DatabaseConfig::save(['driver' => 'mariadb', 'host' => 'db.example.com']);
    }

    public function testRefusesADatabaseNameThatCouldEscapeTheQuery(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Needs the pdo_mysql extension.');
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database names may contain only letters, numbers, underscores and dollar signs.');

        DatabaseConfig::test(['database' => 'analytics`; DROP DATABASE x; --'] + self::MYSQL);
    }

    /** @param array<string, mixed> $config */
    private function write(array $config): void
    {
        file_put_contents(DataDirectory::path() . '/database.json', json_encode($config));
    }
}
