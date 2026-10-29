<?php

declare(strict_types=1);

namespace Petitstats\Auth;

use Delight\Auth\Role;

/**
 * Read access to the dashboard accounts stored by delight-im/auth. Changes
 * go through the engine (Auth::engine()->admin()).
 */
final class Users
{
    /** @return list<array{id: int, email: string, role: string, created_at: string}> */
    public static function all(): array
    {
        $result = AuthDatabase::connection()->query('SELECT id, email, roles_mask, registered FROM users ORDER BY registered ASC');
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = self::fromRow($row);
        }
        return $users;
    }

    /** @return array{id: int, email: string, role: string, created_at: string}|null */
    public static function find(int $id): ?array
    {
        $stmt = AuthDatabase::connection()->prepare('SELECT id, email, roles_mask, registered FROM users WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row ? self::fromRow($row) : null;
    }

    public static function emailExists(string $email): bool
    {
        $stmt = AuthDatabase::connection()->prepare('SELECT 1 FROM users WHERE email = :email');
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        return (bool) $stmt->execute()->fetchArray();
    }

    public static function adminCount(): int
    {
        return (int) AuthDatabase::connection()->querySingle('SELECT COUNT(*) FROM users WHERE roles_mask & ' . Role::ADMIN);
    }

    /** @return array{id: int, email: string, role: string, created_at: string} */
    private static function fromRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'role' => ((int) $row['roles_mask'] & Role::ADMIN) ? 'admin' : 'member',
            'created_at' => gmdate('Y-m-d H:i:s', (int) $row['registered']),
        ];
    }
}
