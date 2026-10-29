<?php

declare(strict_types=1);

namespace Petitstats\Auth;

use Petitstats\Database\DataDirectory;
use SQLite3;

/**
 * The connection to auth.db (accounts, invitations, MCP and OAuth tokens),
 * opened once per request and shared by every class that stores data there.
 */
final class AuthDatabase
{
    private static ?SQLite3 $connection = null;

    public static function path(): string
    {
        return DataDirectory::path() . '/auth.db';
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** Opens the database, creating it and its tables when they do not exist yet. */
    public static function connection(): SQLite3
    {
        if (self::$connection === null) {
            $db = new SQLite3(self::path());
            $db->busyTimeout(5000);
            $db->exec('PRAGMA journal_mode = WAL;');
            AuthSchema::ensure($db);
            self::$connection = $db;
        }
        return self::$connection;
    }

    public static function close(): void
    {
        self::$connection?->close();
        self::$connection = null;
    }
}
