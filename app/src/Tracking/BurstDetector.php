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
 * On a busy website, a common profile (French visitors on iPhone) brings
 * that many direct visits every minute. So the burst must also stand far
 * above the profile's usual rate, measured over the previous hour: a
 * crawler is a sudden surge of a profile that is otherwise rare.
 *
 * Nothing is added to what is stored: the profile is read from the events
 * already recorded.
 */
final class BurstDetector
{
    public const REASON = 'burst';
    public const WINDOW_SECONDS = 60;
    /** New visitors, and distinct pages, that make a burst at the very least. */
    public const MIN_VISITS = 6;
    /** The period before the window giving the profile's usual rate. */
    public const BASELINE_SECONDS = 3600;
    /** How many times its usual rate a profile must reach in the window. */
    public const SURGE_FACTOR = 10;

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
        $now ??= time();
        $profile = self::profile($data);
        $since = gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);

        // New visitors of the same profile, each with a single page view.
        $visitors = [];
        [$sameProfile, $params] = self::profileCondition($profile);
        $stmt = $this->db->prepare("SELECT session_id, visitor_id, action FROM user_activity WHERE timestamp >= :since AND visitor_id IN (SELECT visitor_id FROM user_activity WHERE timestamp >= :since AND {$sameProfile}) ORDER BY id");
        foreach ([':since' => $since] + $params as $name => $value) {
            $stmt->bindValue($name, $value, SQLITE3_TEXT);
        }
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
        $visits = count($burst) + 1;
        if ($visits >= self::MIN_VISITS && count(array_unique($pages)) >= self::MIN_VISITS && $visits >= self::SURGE_FACTOR * $this->usualRate($profile, $since, $now)) {
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

    /**
     * SQL condition keeping the page views of this profile.
     *
     * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed} $profile
     * @return array{0: string, 1: array<string, string>}
     */
    private static function profileCondition(array $profile): array
    {
        $condition = "json_extract(action, '$.name') = 'pageview'";
        $params = [];
        foreach (['country_code', 'os', 'browser', 'language'] as $i => $key) {
            $condition .= " AND COALESCE(json_extract(action, '$.data.{$key}'), '') = :p{$i}";
            $params[":p{$i}"] = is_scalar($profile[$i]) ? (string) $profile[$i] : '';
        }
        return [$condition, $params];
    }

    /**
     * Page views of this profile in a window, on average over the hour
     * before it. Only measured once a burst seems to be under way.
     *
     * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed} $profile
     */
    private function usualRate(array $profile, string $since, int $now): float
    {
        [$sameProfile, $params] = self::profileCondition($profile);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_activity WHERE timestamp >= :from AND timestamp < :since AND {$sameProfile}");
        foreach ([':from' => gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS - self::BASELINE_SECONDS), ':since' => $since] + $params as $name => $value) {
            $stmt->bindValue($name, $value, SQLITE3_TEXT);
        }
        $row = $stmt->execute()->fetchArray(SQLITE3_NUM);
        return (int) ($row[0] ?? 0) * self::WINDOW_SECONDS / self::BASELINE_SECONDS;
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
