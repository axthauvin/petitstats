<?php

declare(strict_types=1);

namespace Petitstats\Auth;

use Delight\Auth\Status;

/**
 * Bearer tokens accepted by the MCP endpoint: access tokens and OAuth
 * access tokens. Both are stored as SHA-256 hashes next to their owner.
 */
final class BearerTokens
{
    /** The bearer token sent with the current request, if any. */
    public static function fromRequest(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $header = array_change_key_case(getallheaders())['authorization'] ?? '';
        }
        return preg_match('/^Bearer\s+(\S+)$/i', trim((string) $header), $m) ? $m[1] : null;
    }

    /**
     * Returns the active account owning a token stored in `$table`, with whether
     * the token may edit funnels, goals and journeys, and records
     * the token's use at most once a minute, so read-heavy clients do not write
     * on every request.
     *
     * @param string $condition extra SQL on the token row `t`, such as an expiry check
     * @return array{id: int, email: string, can_write: bool}|null
     */
    public static function owner(string $table, string $hashColumn, string $token, string $condition = ''): ?array
    {
        if (!AuthDatabase::exists()) {
            return null;
        }
        $db = AuthDatabase::connection();
        $stmt = $db->prepare("SELECT t.id, t.last_used_at, t.can_write, u.id AS user_id, u.email FROM {$table} t JOIN users u ON u.id = t.user_id WHERE t.{$hashColumn} = :hash AND u.status = " . Status::NORMAL . $condition);
        $stmt->bindValue(':hash', hash('sha256', $token), SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return null;
        }
        $now = time();
        if ($row['last_used_at'] === null || (int) $row['last_used_at'] < $now - 60) {
            $db->exec("UPDATE {$table} SET last_used_at = {$now} WHERE id = " . (int) $row['id']);
        }
        return ['id' => (int) $row['user_id'], 'email' => (string) $row['email'], 'can_write' => (bool) $row['can_write']];
    }

    /** Formats a stored Unix timestamp for the dashboard (UTC, like every other date it receives). */
    public static function date(mixed $timestamp): ?string
    {
        return $timestamp === null ? null : gmdate('Y-m-d H:i:s', (int) $timestamp);
    }
}
