<?php

declare(strict_types=1);

namespace Petitstats\Tracking;

use Petitstats\Database\DatabaseConnection;
use Petitstats\Database\Schema;

/**
 * Recognises a crawl spread over many addresses: within a minute, several
 * new visitors sharing one profile (country, system, browser, language),
 * each landing directly on a different page, viewing only that page and
 * never interacting. People arriving together, from a newsletter for
 * instance, interact and mostly open the same page.
 *
 * Nothing is added to what is stored: the profile is read from the events
 * already recorded.
 */
final class BurstDetector
{
    public const REASON = 'burst';
    public const WINDOW_SECONDS = 60;
    /** New visitors, and distinct pages, that make a burst. */
    public const MIN_VISITS = 6;

    public function __construct(private readonly DatabaseConnection $db) {}

    /**
     * Whether this page view belongs to a burst, and the visits of the burst
     * recorded so far, to move out of the analytics. An empty list with
     * true means the burst was already caught: its visits are gone.
     *
     * @param array<string, mixed> $data the event's data, as it would be stored
     * @return list<string>|null the session IDs of the burst's recorded visits, or null when it is no burst
     */
    public function detect(string $visitorId, string $name, array $data, string $userAgent, string $origin, ?int $now = null): ?array
    {
        if ($name !== 'pageview' || !self::isCandidate($data) || $this->hasVisited($visitorId)) {
            return null;
        }
        $profile = self::profile($data);
        $since = gmdate('Y-m-d H:i:s', ($now ?? time()) - self::WINDOW_SECONDS);

        // New visitors of the same profile, each with a single page view.
        $visitors = [];
        $stmt = $this->db->prepare('SELECT session_id, visitor_id, action FROM user_activity WHERE timestamp >= :since ORDER BY id');
        $stmt->bindValue(':since', $since, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $action = json_decode((string) $row['action'], true);
            $rowData = is_array($action['data'] ?? null) ? $action['data'] : [];
            $key = (string) $row['visitor_id'];
            $visitors[$key] ??= ['session' => (string) $row['session_id'], 'pages' => [], 'other' => false, 'match' => true];
            if (($action['name'] ?? null) === 'pageview') {
                $visitors[$key]['pages'][] = (string) ($rowData['path'] ?? '');
                $visitors[$key]['match'] = $visitors[$key]['match'] && self::isCandidate($rowData) && self::profile($rowData) === $profile;
            } elseif (!str_starts_with((string) ($action['name'] ?? ''), '_ml_')) {
                $visitors[$key]['other'] = true;
            }
        }
        $burst = array_filter($visitors, static fn(array $v): bool => $v['match'] && count($v['pages']) === 1 && !$v['other']);
        $burst = array_filter($burst, fn(array $v, string $visitor): bool => !$this->visitedBefore($visitor, $since), ARRAY_FILTER_USE_BOTH);

        $pages = [(string) ($data['path'] ?? '')];
        foreach ($burst as $visitor) {
            array_push($pages, ...$visitor['pages']);
        }
        if (count($burst) + 1 >= self::MIN_VISITS && count(array_unique($pages)) >= self::MIN_VISITS) {
            return array_column($burst, 'session');
        }
        // A burst caught a moment ago goes on: its visits have left the analytics already.
        return $this->recentlyCaught($userAgent, $origin, $data['country_code'] ?? null, $since) ? [] : null;
    }

    /** A direct landing, not confirmed by an interaction. */
    private static function isCandidate(array $data): bool
    {
        $referrer = $data['referrer'] ?? null;
        return ($referrer === null || $referrer === '') && ($data['_ml_confirm'] ?? null) !== 'interaction';
    }

    /** @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed} */
    private static function profile(array $data): array
    {
        return [$data['country_code'] ?? null, $data['os'] ?? null, $data['browser'] ?? null, $data['language'] ?? null];
    }

    private function hasVisited(string $visitorId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM user_activity WHERE visitor_id = :visitor LIMIT 1');
        $stmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        return $stmt->execute()->fetchArray(SQLITE3_NUM) !== false;
    }

    private function visitedBefore(string $visitorId, string $since): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM user_activity WHERE visitor_id = :visitor AND timestamp < :since LIMIT 1');
        $stmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        $stmt->bindValue(':since', $since, SQLITE3_TEXT);
        return $stmt->execute()->fetchArray(SQLITE3_NUM) !== false;
    }

    /** Whether a burst from the same browser and country was caught since then. */
    private function recentlyCaught(string $userAgent, string $origin, mixed $countryCode, string $since): bool
    {
        $located = Schema::hasBotCountry($this->db) && is_string($countryCode) && $countryCode !== 'UN';
        $stmt = $this->db->prepare('SELECT 1 FROM bot_activity WHERE reason = :reason AND user_agent = :ua AND origin = :origin AND timestamp >= :since'
            . ($located ? ' AND country_code = :country' : '') . ' LIMIT 1');
        $stmt->bindValue(':reason', self::REASON, SQLITE3_TEXT);
        $stmt->bindValue(':ua', substr($userAgent, 0, 500), SQLITE3_TEXT);
        $stmt->bindValue(':origin', substr($origin, 0, 255), SQLITE3_TEXT);
        $stmt->bindValue(':since', $since, SQLITE3_TEXT);
        if ($located) {
            $stmt->bindValue(':country', $countryCode, SQLITE3_TEXT);
        }
        return $stmt->execute()->fetchArray(SQLITE3_NUM) !== false;
    }
}
