<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Petitstats\Database\DatabaseConnection;

/**
 * Caps the requests accepted per minute from one client: the events of one IP
 * address for the tracker, the calls of one token for the stats API.
 */
final class RateLimiter
{
    public const EVENTS_PER_MINUTE = 240;

    public function __construct(private readonly DatabaseConnection $db, private readonly int $perMinute = self::EVENTS_PER_MINUTE) {}

    /** Counts a request from `$client` (an IP address, a token ID…) and tells whether it stays within the limit. */
    public function allow(string $client): bool
    {
        $bucket = gmdate('YmdHi');
        $hash = hash('sha256', $client);
        $stmt = $this->db->prepare('INSERT INTO rate_limits (bucket,ip_hash,count) VALUES (:bucket,:hash,1) ON CONFLICT(bucket,ip_hash) DO UPDATE SET count=count+1');
        $stmt->bindValue(':bucket', $bucket, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->execute();
        $check = $this->db->prepare('SELECT count FROM rate_limits WHERE bucket=:bucket AND ip_hash=:hash');
        $check->bindValue(':bucket', $bucket, SQLITE3_TEXT);
        $check->bindValue(':hash', $hash, SQLITE3_TEXT);
        return (int) $check->execute()->fetchArray(SQLITE3_NUM)[0] <= $this->perMinute;
    }

    /** Seconds until the current minute ends and the count starts again. */
    public static function retryAfter(?int $now = null): int
    {
        return 60 - ($now ?? time()) % 60;
    }
}
