<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Petitstats\Database\DatabaseConnection;
use Petitstats\Database\Schema;
use Throwable;

/** Writes tracked and ignored events to a website's database. */
final class ActivityStore
{
    /** A visit ends after 30 minutes without an event. */
    private const SESSION_TIMEOUT = 1800;

    public function __construct(private readonly DatabaseConnection $db) {}

    /** The visitor's current session, if they sent an event in the last 30 minutes. */
    public function activeSessionId(string $visitorId): ?string
    {
        $stmt = $this->db->prepare('SELECT session_id FROM user_activity WHERE visitor_id = :visitor AND timestamp >= :cutoff ORDER BY timestamp DESC, id DESC LIMIT 1');
        $stmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        $stmt->bindValue(':cutoff', gmdate('Y-m-d H:i:s', time() - self::SESSION_TIMEOUT), SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return is_array($row) && !empty($row['session_id']) ? (string) $row['session_id'] : null;
    }

    /**
     * Records the event in the visitor's current session, or starts one. The
     * script sends the events it held back all at once: without the lock,
     * each would find no session yet and start a visit of its own.
     *
     * @param array<string, mixed> $action
     */
    public function recordInActiveSession(string $visitorId, array $action): void
    {
        if (!$this->db->isMysql()) {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $this->record($this->activeSessionId($visitorId) ?? VisitorIdentity::sessionId(), $visitorId, $action);
                $this->db->exec('COMMIT');
            } catch (Throwable $e) {
                $this->db->exec('ROLLBACK');
                throw $e;
            }
            return;
        }
        // MySQL locks are shared by the whole server, hence the website in the name (64 characters at most).
        $lock = 'petitstats:' . sha1($this->db->siteId() . ':' . $visitorId);
        $stmt = $this->db->prepare('SELECT GET_LOCK(:lock, 5)');
        $stmt->bindValue(':lock', $lock, SQLITE3_TEXT);
        $stmt->execute();
        try {
            // Should the lock time out, the event is still recorded, at worst in a visit of its own.
            $this->record($this->activeSessionId($visitorId) ?? VisitorIdentity::sessionId(), $visitorId, $action);
        } finally {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:lock)');
            $stmt->bindValue(':lock', $lock, SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    /** @param array<string, mixed> $action */
    public function record(string $sessionId, string $visitorId, array $action): void
    {
        $stmt = $this->db->prepare('INSERT INTO user_activity (session_id, visitor_id, action) VALUES (:session,:visitor,:action)');
        $stmt->bindValue(':session', $sessionId, SQLITE3_TEXT);
        $stmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        $stmt->bindValue(':action', json_encode($action, JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * Logs a request kept out of the analytics (bot, internal traffic). Only a
     * hash of the IP is stored, with the country it comes from.
     */
    public function recordIgnored(string $reason, string $userAgent, string $origin, string $ip, ?string $countryCode = null): void
    {
        $this->insertIgnored($reason, $userAgent, $origin, hash('sha256', $ip), $countryCode, null);
    }

    /**
     * Moves visits already recorded to the blocked traffic, once they turn out
     * to come from a robot: each visit becomes one rejected request, at the
     * time it started, and its events leave the analytics.
     *
     * @param list<string> $sessionIds
     * @param string $userAgent the robot's user agent when known; visits do not keep theirs
     * @return int the number of visits moved
     */
    public function requalify(array $sessionIds, string $reason, string $userAgent = ''): int
    {
        $sessionIds = array_values(array_unique($sessionIds));
        if ($sessionIds === []) {
            return 0;
        }
        $this->db->exec($this->db->isMysql() ? 'START TRANSACTION' : 'BEGIN IMMEDIATE');
        try {
            $moved = 0;
            foreach (array_chunk($sessionIds, 200) as $chunk) {
                $placeholders = implode(',', array_map(static fn(int $i): string => ':s' . $i, array_keys($chunk)));
                $visits = [];
                $stmt = $this->db->prepare("SELECT session_id, action, timestamp FROM user_activity WHERE session_id IN ({$placeholders}) ORDER BY timestamp, id");
                foreach ($chunk as $i => $sessionId) {
                    $stmt->bindValue(':s' . $i, $sessionId, SQLITE3_TEXT);
                }
                $result = $stmt->execute();
                while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
                    // The first event of each visit stands for it.
                    $visits[$row['session_id']] ??= $row;
                }
                foreach ($visits as $visit) {
                    $data = json_decode((string) $visit['action'], true)['data'] ?? [];
                    $this->insertIgnored($reason, $userAgent, (string) ($data['hostname'] ?? ''), null, $data['country_code'] ?? null, (string) $visit['timestamp']);
                }
                $delete = $this->db->prepare("DELETE FROM user_activity WHERE session_id IN ({$placeholders})");
                foreach ($chunk as $i => $sessionId) {
                    $delete->bindValue(':s' . $i, $sessionId, SQLITE3_TEXT);
                }
                $delete->execute();
                $moved += count($visits);
            }
            $this->db->exec('COMMIT');
            return $moved;
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    private function insertIgnored(string $reason, string $userAgent, string $origin, ?string $ipHash, mixed $countryCode, ?string $timestamp): void
    {
        // Without the column (an account that cannot alter tables), the request is kept without its country.
        $located = Schema::hasBotCountry($this->db);
        $columns = ['reason' => $reason, 'user_agent' => substr($userAgent, 0, 500), 'origin' => substr($origin, 0, 255), 'ip_hash' => $ipHash];
        if ($located) {
            $columns['country_code'] = is_string($countryCode) && preg_match('/^[A-Z]{2}$/', $countryCode) && $countryCode !== 'UN' ? $countryCode : null;
        }
        if ($timestamp !== null) {
            $columns['timestamp'] = $timestamp;
        }
        $names = array_keys($columns);
        $stmt = $this->db->prepare('INSERT INTO bot_activity (' . implode(', ', $names) . ') VALUES (:' . implode(', :', $names) . ')');
        foreach ($columns as $name => $value) {
            $stmt->bindValue(':' . $name, $value, $value === null ? SQLITE3_NULL : SQLITE3_TEXT);
        }
        $stmt->execute();
    }
}
