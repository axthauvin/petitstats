<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Petitstats\Database\DatabaseConnection;

/**
 * Finds, after the fact, the visits of a crawler that got through the
 * filters, from the profile they share, and moves them to the blocked
 * traffic (see bin/requalify-bots.php).
 *
 * Only visits a crawler leaves behind can match: a first visit, landing
 * directly, a single page view, no event, no interaction, a few seconds at
 * most. A visitor who came back, clicked or read for a while is never
 * touched, whatever their profile.
 */
final class FingerprintCleanup
{
    public const REASON = 'manual:fingerprint';
    public const MAX_DURATION = 10;

    public function __construct(private readonly DatabaseConnection $db) {}

    /**
     * The visits started that day (UTC) matching the profile.
     *
     * @param array{country?: string, os?: string, browser?: string, language?: string} $profile values compared exactly, case aside; left out, anything goes
     * @return list<array{session_id: string, visitor_id: string, started_at: string, path: string, duration: int, rows: int}>
     */
    public function matches(string $day, array $profile, int $maxDuration = self::MAX_DURATION): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new \InvalidArgumentException("Expected a day as YYYY-MM-DD, got \"{$day}\".");
        }
        $wanted = array_filter([
            'country_code' => $profile['country'] ?? null,
            'os' => $profile['os'] ?? null,
            'browser' => $profile['browser'] ?? null,
            'language' => $profile['language'] ?? null,
        ], static fn(?string $value): bool => $value !== null && $value !== '');

        $visits = [];
        $stmt = $this->db->prepare('SELECT session_id, visitor_id, action, timestamp FROM user_activity WHERE session_id IN (SELECT session_id FROM user_activity WHERE timestamp BETWEEN :start AND :end) ORDER BY timestamp, id');
        $stmt->bindValue(':start', $day . ' 00:00:00', SQLITE3_TEXT);
        $stmt->bindValue(':end', $day . ' 23:59:59', SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $action = json_decode((string) $row['action'], true);
            $data = is_array($action['data'] ?? null) ? $action['data'] : [];
            $name = (string) ($action['name'] ?? '');
            $session = (string) $row['session_id'];
            $visit = $visits[$session] ?? ['session_id' => $session, 'visitor_id' => (string) $row['visitor_id'], 'started_at' => (string) $row['timestamp'], 'ended_at' => '', 'pages' => [], 'events' => 0, 'interaction' => false, 'first' => null, 'rows' => 0];
            $visit['ended_at'] = (string) $row['timestamp'];
            $visit['rows']++;
            $visit['interaction'] = $visit['interaction'] || ($data['_ml_confirm'] ?? null) === 'interaction';
            if ($name === 'pageview') {
                $visit['pages'][] = (string) ($data['path'] ?? '');
                $visit['first'] ??= $data;
            } elseif (!str_starts_with($name, '_ml_')) {
                $visit['events']++;
            }
            $visits[$session] = $visit;
        }

        $matches = [];
        foreach ($visits as $visit) {
            $first = $visit['first'];
            $duration = (int) (strtotime($visit['ended_at'] . ' UTC') - strtotime($visit['started_at'] . ' UTC'));
            if ($first === null || count($visit['pages']) !== 1 || $visit['events'] > 0 || $visit['interaction'] || $duration > $maxDuration
                || !in_array($first['referrer'] ?? null, [null, ''], true) || !self::sameProfile($first, $wanted)
                || $this->cameBack($visit['visitor_id'], $visit['session_id'])) {
                continue;
            }
            $matches[] = ['session_id' => $visit['session_id'], 'visitor_id' => $visit['visitor_id'], 'started_at' => $visit['started_at'], 'path' => $visit['pages'][0], 'duration' => $duration, 'rows' => $visit['rows']];
        }
        return $matches;
    }

    /**
     * The stored events of these visits, as they are before they move.
     *
     * @param list<array{session_id: string}> $matches
     * @return list<array<string, mixed>>
     */
    public function rows(array $matches): array
    {
        $rows = [];
        foreach (array_chunk(array_column($matches, 'session_id'), 200) as $chunk) {
            $placeholders = implode(',', array_map(static fn(int $i): string => ':s' . $i, array_keys($chunk)));
            $stmt = $this->db->prepare("SELECT id, session_id, visitor_id, action, timestamp FROM user_activity WHERE session_id IN ({$placeholders}) ORDER BY id");
            foreach ($chunk as $i => $sessionId) {
                $stmt->bindValue(':s' . $i, $sessionId, SQLITE3_TEXT);
            }
            $result = $stmt->execute();
            while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Moves the visits to the blocked traffic.
     *
     * @param list<array{session_id: string}> $matches
     * @return int the number of visits moved
     */
    public function apply(array $matches): int
    {
        return (new ActivityStore($this->db))->requalify(array_column($matches, 'session_id'), self::REASON);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $wanted
     */
    private static function sameProfile(array $data, array $wanted): bool
    {
        foreach ($wanted as $key => $value) {
            if (strcasecmp((string) ($data[$key] ?? ''), $value) !== 0) {
                return false;
            }
        }
        return true;
    }

    /** Whether the visitor has another visit: a crawler never comes back. */
    private function cameBack(string $visitorId, string $sessionId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM user_activity WHERE visitor_id = :visitor AND session_id <> :session LIMIT 1');
        $stmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        $stmt->bindValue(':session', $sessionId, SQLITE3_TEXT);
        return $stmt->execute()->fetchArray(SQLITE3_NUM) !== false;
    }
}
