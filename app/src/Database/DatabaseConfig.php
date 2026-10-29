<?php

declare(strict_types=1);

namespace Petitstats\Database;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use SQLite3;

/**
 * Where analytics are stored: one SQLite file per website (the default), or a
 * MySQL or MariaDB database shared by all websites. Kept in database.json.
 */
final class DatabaseConfig
{
    /** @return array{driver: string, host: string, port: int, database: string, username: string, password: string} */
    public static function load(): array
    {
        $config = is_file(self::path()) ? json_decode((string) file_get_contents(self::path()), true) : [];
        $config = is_array($config) ? $config : [];
        return array_merge(['driver' => 'sqlite', 'host' => '', 'port' => 3306, 'database' => '', 'username' => '', 'password' => ''], $config);
    }

    /** The configuration without its password, for the dashboard. */
    public static function publicView(): array
    {
        $config = self::load();
        unset($config['password']);
        $config['configured'] = $config['driver'] === 'sqlite' || ($config['host'] !== '' && $config['database'] !== '' && $config['username'] !== '');
        return $config;
    }

    /** Tests the connection, then saves it. An empty password keeps the saved one. */
    public static function save(array $input): array
    {
        $driver = strtolower(trim((string) ($input['driver'] ?? 'sqlite')));
        if (!in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('Unsupported database driver.');
        }
        $current = self::load();
        $config = ['driver' => $driver, 'host' => trim((string) ($input['host'] ?? '')), 'port' => max(1, min(65535, (int) ($input['port'] ?? 3306))), 'database' => trim((string) ($input['database'] ?? '')), 'username' => trim((string) ($input['username'] ?? '')), 'password' => array_key_exists('password', $input) && $input['password'] !== '' ? (string) $input['password'] : $current['password']];
        if ($driver === 'sqlite') {
            $config = ['driver' => 'sqlite', 'host' => '', 'port' => 3306, 'database' => '', 'username' => '', 'password' => ''];
        }
        if ($driver !== 'sqlite' && ($config['host'] === '' || $config['database'] === '' || $config['username'] === '')) {
            throw new InvalidArgumentException('Host, database name and username are required.');
        }
        self::test(array_merge($config, ['create_database' => !empty($input['create_database'])]));
        file_put_contents(self::path(), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod(self::path(), 0600);
        Connections::closeAll();
        return self::publicView();
    }

    /** Connects with these settings, and creates the database first when asked to. */
    public static function test(array $input): array
    {
        $driver = strtolower((string) ($input['driver'] ?? 'sqlite'));
        if ($driver === 'sqlite') {
            if (!class_exists('SQLite3')) {
                throw new RuntimeException('The SQLite3 PHP extension is not enabled.');
            }
            return ['driver' => 'sqlite', 'version' => SQLite3::version()['versionString']];
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('The PDO MySQL extension (pdo_mysql) is not enabled on this server.');
        }
        $host = trim((string) ($input['host'] ?? ''));
        $database = trim((string) ($input['database'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        if ($host === '' || $database === '' || $username === '') {
            throw new InvalidArgumentException('Host, database name and username are required.');
        }
        if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $database)) {
            throw new InvalidArgumentException('Database names may contain only letters, numbers, underscores and dollar signs.');
        }
        $port = max(1, min(65535, (int) ($input['port'] ?? 3306)));
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
        $password = (string) ($input['password'] ?? '');
        $created = false;
        if (!empty($input['create_database'])) {
            // Connect without a schema first: this permits creating a missing
            // schema but still requires the caller's explicit CREATE privilege.
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, $options);
            $check = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :database');
            $check->execute([':database' => $database]);
            $created = $check->fetchColumn() === false;
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, $options);
        return ['driver' => $driver, 'version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'database_created' => $created];
    }

    private static function path(): string
    {
        return DataDirectory::path() . '/database.json';
    }
}
