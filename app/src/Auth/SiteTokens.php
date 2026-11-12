<?php

declare(strict_types=1);

namespace Petitstats\Auth;

/**
 * Stats API tokens: read-only access to the summary of a single website, for a
 * server that shows its own audience (a sponsor page, for example).
 *
 * Unlike MCP tokens, they belong to the website rather than to an account:
 * any administrator manages them, they keep working when the person who
 * created them leaves, and they disappear with the website. Their own prefix
 * keeps them out of the MCP endpoint, and MCP tokens out of the stats API.
 */
final class SiteTokens
{
    private const PREFIX = 'pss_';

    /**
     * Creates a token for a website and returns it with its plain-text value, shown only this once.
     *
     * @return array{id: int, site_id: string, name: string, hint: string, created_at: string, last_used_at: null, token: string}
     */
    public static function create(string $siteId, string $name, ?int $createdBy = null): array
    {
        ['token' => $token, 'hint' => $hint, 'hash' => $hash] = BearerTokens::generate(self::PREFIX);
        $now = time();
        $db = AuthDatabase::connection();
        $stmt = $db->prepare('INSERT INTO site_tokens (site_id, name, token_hash, hint, created_by, created_at) VALUES (:site, :name, :hash, :hint, :user, :now)');
        $stmt->bindValue(':site', $siteId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':hint', $hint, SQLITE3_TEXT);
        $stmt->bindValue(':user', $createdBy, $createdBy === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
        $stmt->execute();
        return ['id' => $db->lastInsertRowID(), 'site_id' => $siteId, 'name' => $name, 'hint' => $hint, 'created_at' => (string) BearerTokens::date($now), 'last_used_at' => null, 'token' => $token];
    }

    /** @return list<array{id: int, name: string, hint: string, created_at: string, last_used_at: ?string}> */
    public static function forSite(string $siteId): array
    {
        $stmt = AuthDatabase::connection()->prepare('SELECT id, name, hint, created_at, last_used_at FROM site_tokens WHERE site_id = :site ORDER BY id DESC');
        $stmt->bindValue(':site', $siteId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $tokens = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tokens[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'hint' => (string) $row['hint'], 'created_at' => (string) BearerTokens::date($row['created_at']), 'last_used_at' => BearerTokens::date($row['last_used_at'])];
        }
        return $tokens;
    }

    /** Deletes one of the website's tokens. Returns false when it does not exist. */
    public static function revoke(string $siteId, int $id): bool
    {
        $db = AuthDatabase::connection();
        $stmt = $db->prepare('DELETE FROM site_tokens WHERE id = :id AND site_id = :site');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->bindValue(':site', $siteId, SQLITE3_TEXT);
        $stmt->execute();
        return $db->changes() > 0;
    }

    /** Deletes every token of a website, when the website itself is deleted. */
    public static function revokeAllForSite(string $siteId): void
    {
        if (!AuthDatabase::exists()) {
            return;
        }
        $stmt = AuthDatabase::connection()->prepare('DELETE FROM site_tokens WHERE site_id = :site');
        $stmt->bindValue(':site', $siteId, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * The token and the website it reads, or null for anything else (an MCP
     * token, a revoked or made-up one). Records the token's use.
     *
     * @return array{id: int, site_id: string}|null
     */
    public static function authenticate(string $token): ?array
    {
        if (!str_starts_with($token, self::PREFIX) || !AuthDatabase::exists()) {
            return null;
        }
        $stmt = AuthDatabase::connection()->prepare('SELECT id, site_id, last_used_at FROM site_tokens WHERE token_hash = :hash');
        $stmt->bindValue(':hash', hash('sha256', $token), SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return null;
        }
        BearerTokens::touch('site_tokens', (int) $row['id'], $row['last_used_at']);
        return ['id' => (int) $row['id'], 'site_id' => (string) $row['site_id']];
    }
}
