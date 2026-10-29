<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Petitstats\Database\DatabaseConnection;

/** Caps the events accepted from one IP address per minute. */
final class RateLimiter
{
    private const EVENTS_PER_MINUTE = 240;

    public function __construct(private readonly DatabaseConnection $db) {}

    /** Counts a request from `$ip` and tells whether it stays within the limit. */
    public function allow(string $ip): bool
    {
        $bucket = gmdate('YmdHi');
        $hash = hash('sha256', $ip);
        $stmt = $this->db->prepare('INSERT INTO rate_limits (bucket,ip_hash,count) VALUES (:bucket,:hash,1) ON CONFLICT(bucket,ip_hash) DO UPDATE SET count=count+1');
        $stmt->bindValue(':bucket', $bucket, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->execute();
        $check = $this->db->prepare('SELECT count FROM rate_limits WHERE bucket=:bucket AND ip_hash=:hash');
        $check->bindValue(':bucket', $bucket, SQLITE3_TEXT);
        $check->bindValue(':hash', $hash, SQLITE3_TEXT);
        return (int) $check->execute()->fetchArray(SQLITE3_NUM)[0] <= self::EVENTS_PER_MINUTE;
    }
}
