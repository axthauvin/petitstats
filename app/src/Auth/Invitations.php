<?php

declare(strict_types=1);

namespace Petitstats\Auth;

/**
 * Invitation links sent by administrators. Only a SHA-256 hash of each token
 * is stored: the link itself is shown once, when it is created.
 */
final class Invitations
{
    private const TTL = 7 * 86400;

    /**
     * Creates an invitation for an email address, replacing any pending one.
     *
     * @return array{token: string, expires_at: int}
     */
    public static function create(string $email, int $createdBy): array
    {
        $db = AuthDatabase::connection();
        $stmt = $db->prepare('UPDATE invitations SET accepted_at = CURRENT_TIMESTAMP WHERE email = :email AND accepted_at IS NULL');
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->execute();

        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + self::TTL;
        $stmt = $db->prepare('INSERT INTO invitations (email, token_hash, expires_at, created_by) VALUES (:email, :hash, :expires, :by)');
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':hash', hash('sha256', $token), SQLITE3_TEXT);
        $stmt->bindValue(':expires', gmdate('Y-m-d H:i:s', $expiresAt), SQLITE3_TEXT);
        $stmt->bindValue(':by', $createdBy, SQLITE3_INTEGER);
        $stmt->execute();
        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * The invitation a token belongs to, unless it was used or has expired.
     *
     * @return array{id: int, email: string}|null
     */
    public static function findPending(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = AuthDatabase::connection()->prepare('SELECT id, email FROM invitations WHERE token_hash = :hash AND accepted_at IS NULL AND expires_at > CURRENT_TIMESTAMP');
        $stmt->bindValue(':hash', hash('sha256', $token), SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row ? ['id' => (int) $row['id'], 'email' => (string) $row['email']] : null;
    }

    public static function markAccepted(int $id): void
    {
        $stmt = AuthDatabase::connection()->prepare('UPDATE invitations SET accepted_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    /** Keeps the invitations a user sent once their account is deleted. */
    public static function forgetCreator(int $userId): void
    {
        $stmt = AuthDatabase::connection()->prepare('UPDATE invitations SET created_by = NULL WHERE created_by = :id');
        $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }
}
