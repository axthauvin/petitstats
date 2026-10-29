<?php

declare(strict_types=1);

namespace Petitstats\Auth;

use Delight\Auth\Auth as AuthEngine;
use ReflectionClass;
use RuntimeException;
use SQLite3;

/** Creates the auth database tables and migrates installs that predate delight-im/auth. */
final class AuthSchema
{
    public static function ensure(SQLite3 $db): void
    {
        if (!in_array('roles_mask', self::columns($db, 'users'), true)) {
            // Keep the invitations foreign key pointing at "users" while the legacy table is swapped.
            $db->exec('PRAGMA foreign_keys = OFF; PRAGMA legacy_alter_table = ON;');
            $db->exec('BEGIN IMMEDIATE');
            try {
                // Re-check under the write lock: a concurrent request may have done it already.
                $columns = self::columns($db, 'users');
                if (in_array('password_hash', $columns, true)) {
                    self::migrateLegacyUsers($db);
                } elseif ($columns === []) {
                    self::createEngineTables($db);
                }
                $db->exec('COMMIT');
            } catch (\Throwable $e) {
                $db->exec('ROLLBACK');
                throw $e;
            } finally {
                $db->exec('PRAGMA legacy_alter_table = OFF;');
            }
        }
        $db->exec('CREATE TABLE IF NOT EXISTS invitations (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL COLLATE NOCASE, token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INTEGER, accepted_at TEXT, FOREIGN KEY(created_by) REFERENCES users(id))');
        $db->exec('CREATE TABLE IF NOT EXISTS mcp_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, hint TEXT NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER, can_write INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
        // OAuth clients (AI assistants), authorization codes and tokens.
        $db->exec('CREATE TABLE IF NOT EXISTS oauth_clients (client_id TEXT PRIMARY KEY, client_name TEXT NOT NULL, redirect_uris TEXT NOT NULL, created_at INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS oauth_codes (code_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL, user_id INTEGER NOT NULL, redirect_uri TEXT NOT NULL, code_challenge TEXT NOT NULL, expires_at INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS oauth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT NOT NULL, user_id INTEGER NOT NULL, access_hash TEXT NOT NULL UNIQUE, access_expires_at INTEGER NOT NULL, refresh_hash TEXT NOT NULL UNIQUE, refresh_expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER, can_write INTEGER NOT NULL DEFAULT 0)');
        // Assistants only read until the user lets a connection edit funnels, goals and journeys.
        foreach (['mcp_tokens', 'oauth_tokens'] as $table) {
            if (!in_array('can_write', self::columns($db, $table), true)) {
                // A concurrent request may add it first: its "duplicate column" warning is harmless.
                @$db->exec("ALTER TABLE {$table} ADD COLUMN can_write INTEGER NOT NULL DEFAULT 0");
            }
        }
    }

    /**
     * Migrates accounts from the pre-delight-im/auth "users" table to the library's schema.
     * TODO: remove once every install has been migrated.
     */
    private static function migrateLegacyUsers(SQLite3 $db): void
    {
        $db->exec('ALTER TABLE users RENAME TO users_legacy');
        self::createEngineTables($db);
        $db->exec("INSERT INTO users (id, email, password, verified, roles_mask, registered)
            SELECT id, email, password_hash, 1, CASE role WHEN 'admin' THEN 1 ELSE 0 END, CAST(strftime('%s', created_at) AS INTEGER)
            FROM users_legacy");
        $db->exec('DROP TABLE users_legacy');
    }

    private static function createEngineTables(SQLite3 $db): void
    {
        $file = dirname((string) (new ReflectionClass(AuthEngine::class))->getFileName(), 2) . '/Database/SQLite.sql';
        $sql = is_file($file) ? file_get_contents($file) : false;
        if ($sql === false || !$db->exec($sql)) {
            throw new RuntimeException('Unable to create the authentication tables.');
        }
    }

    /** @return list<string> */
    private static function columns(SQLite3 $db, string $table): array
    {
        $result = $db->query('PRAGMA table_info(' . $table . ')');
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = (string) $row['name'];
        }
        return $columns;
    }
}
