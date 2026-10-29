<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Petitstats\Database\DataDirectory;
use RuntimeException;

/**
 * Server-side, cookie-free visitor identity: a hash of the website, the IP
 * address and the user agent with a random salt that changes every month.
 * The previous salt is discarded, so an identifier can never be linked to a
 * visitor again once its month is over.
 */
final class VisitorIdentity
{
    private const SALT_FILE = '.visitor-salt';

    /** Replaced by the monthly salt; deleted so that older identifiers cannot be recomputed. */
    private const LEGACY_SECRET_FILE = '.tracking-secret';

    public static function visitorId(string $websiteId, string $ip, string $userAgent): string
    {
        $material = implode("\n", [$websiteId, $ip, $userAgent]);
        return substr(hash_hmac('sha256', $material, self::salt()), 0, 32);
    }

    public static function sessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** The salt of the current month, created when the month starts. */
    public static function salt(?string $period = null): string
    {
        $period ??= gmdate('Y-m');
        $dir = DataDirectory::path();
        $handle = @fopen($dir . '/' . self::SALT_FILE, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to store the Petitstats visitor salt. Make the data directory writable.');
        }
        try {
            flock($handle, LOCK_EX);
            $stored = json_decode((string) stream_get_contents($handle), true);
            if (is_array($stored) && ($stored['period'] ?? null) === $period && is_string($stored['salt'] ?? null) && strlen($stored['salt']) >= 32) {
                return $stored['salt'];
            }
            // Overwriting the file is what discards the previous month's salt.
            $salt = bin2hex(random_bytes(32));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(['period' => $period, 'salt' => $salt]));
            fflush($handle);
            @chmod($dir . '/' . self::SALT_FILE, 0600);
            @unlink($dir . '/' . self::LEGACY_SECRET_FILE);
            return $salt;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
