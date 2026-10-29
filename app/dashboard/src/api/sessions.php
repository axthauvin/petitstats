<?php

declare(strict_types=1);

use Petitstats\Analytics\AnalyticsFilters;
use Petitstats\Analytics\Period;
use Petitstats\Auth\Auth;
use Petitstats\Database\Connections;
use Petitstats\Sites\SiteRegistry;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
require_once __DIR__ . '/../../../vendor/autoload.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    // Public demo sites are readable without an account; deletion stays admin-only below.
    Auth::requireSiteAccess((string) ($_GET['site_id'] ?? $_GET['site'] ?? ''));
} else {
    Auth::requireLogin();
}


$formatDurationLabel = static function (int $seconds): string {
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return "{$seconds}s";
    }

    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = "{$days}d";
    }
    if ($hours > 0 || $days > 0) {
        $parts[] = "{$hours}h";
    }
    if ($minutes > 0 || $hours > 0 || $days > 0) {
        $parts[] = "{$minutes}m";
    }
    if ($seconds > 0 || empty($parts)) {
        $parts[] = "{$seconds}s";
    }

    return implode(' ', $parts);
};

$avatarUrl = static fn(string $seed): string => 'https://api.dicebear.com/10.x/glyphs/svg?seed=' . rawurlencode($seed);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    $siteId = $_GET['site_id'] ?? $_GET['site'] ?? null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'DELETE') {
        Auth::requireAdmin();
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $siteId = $input['site_id'] ?? $siteId;
        $sessionId = trim((string) ($input['session_id'] ?? $_GET['session_id'] ?? ''));
        if ($sessionId === '' || strlen($sessionId) > 255) {
            throw new InvalidArgumentException('A valid session ID is required.');
        }
        $cleanSite = SiteRegistry::requireId($siteId);
        $db = Connections::forSite($cleanSite);
        // The dashboard shows a visitor's visits together, so they are deleted together.
        $visitorStmt = $db->prepare('SELECT visitor_id FROM user_activity WHERE session_id = :session_id AND visitor_id IS NOT NULL LIMIT 1');
        $visitorStmt->bindValue(':session_id', $sessionId, SQLITE3_TEXT);
        $visitor = $visitorStmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
        if (is_string($visitor) && $visitor !== '') {
            $delete = $db->prepare('DELETE FROM user_activity WHERE visitor_id = :visitor OR session_id = :session_id');
            $delete->bindValue(':visitor', $visitor, SQLITE3_TEXT);
        } else {
            $delete = $db->prepare('DELETE FROM user_activity WHERE session_id = :session_id');
        }
        $delete->bindValue(':session_id', $sessionId, SQLITE3_TEXT);
        $delete->execute();
        $deleted = $db->changes();
        if ($deleted === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Session not found']);
            exit;
        }
        echo json_encode(['success' => true, 'deleted_events' => $deleted]);
        exit;
    }
    $cleanSite = SiteRegistry::requireId($siteId);
    $db = Connections::forSite($cleanSite);

    $specificSessionId = trim($_GET['session_id'] ?? '');

    // 1. A visitor's journey: every visit of the visitor behind this session, oldest first.
    if ($specificSessionId !== '') {
        $visitorStmt = $db->prepare('SELECT visitor_id FROM user_activity WHERE session_id = :session_id ORDER BY timestamp DESC, id DESC LIMIT 1');
        $visitorStmt->bindValue(':session_id', $specificSessionId, SQLITE3_TEXT);
        $visitorRow = $visitorStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!is_array($visitorRow)) {
            http_response_code(404);
            echo json_encode(['error' => 'Session not found']);
            exit;
        }
        $visitorId = (string) ($visitorRow['visitor_id'] ?: $specificSessionId);

        // Visitors are recognised for a month at most, so this stays small.
        $dStmt = $db->prepare('SELECT id, session_id, action, timestamp FROM user_activity WHERE '
            . ($visitorRow['visitor_id'] ? 'visitor_id = :visitor' : 'session_id = :visitor')
            . ' ORDER BY timestamp ASC, id ASC LIMIT 2000');
        $dStmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        $res = $dStmt->execute();

        $events = [];
        $visits = [];
        $context = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $act = json_decode($row['action'], true) ?: [];
            $data = $act['data'] ?? [];
            $name = $act['name'] ?? 'unknown';
            $sid = (string) $row['session_id'];
            $ts = strtotime($row['timestamp'] . ' UTC');

            if (!isset($visits[$sid])) {
                $visits[$sid] = [
                    'session_id' => $sid,
                    'visit_number' => count($visits) + 1,
                    'started_at' => $row['timestamp'],
                    'ended_at' => $row['timestamp'],
                    'start_ts' => $ts,
                    'duration_seconds' => 0,
                    'entry_page' => $data['path'] ?? '/',
                    'referrer' => !empty($data['referrer']) ? $data['referrer'] : 'Direct',
                    'pageview_count' => 0,
                    'event_count' => 0,
                ];
                // The device and location shown are those of the latest visit.
                $context = $data;
            }
            $visit = &$visits[$sid];
            $visit['ended_at'] = $row['timestamp'];
            $visit['duration_seconds'] = max(0, $ts - $visit['start_ts']);
            $offsetSec = $ts - $visit['start_ts'];

            // Engagement is an internal timing signal, not a visitor-facing event.
            if ($name === '_ml_engaged') {
                unset($visit);
                continue;
            }
            $visit[$name === 'pageview' ? 'pageview_count' : 'event_count']++;
            $events[] = [
                'id' => (int) $row['id'],
                'session_id' => $sid,
                'visit_number' => $visit['visit_number'],
                'name' => $name,
                'site_id' => $act['site_id'] ?? $cleanSite,
                'data' => $data,
                'timestamp' => $row['timestamp'],
                'offset_label' => '+' . $formatDurationLabel($offsetSec),
                'offset_seconds' => $offsetSec,
            ];
            unset($visit);
        }

        $visits = array_values(array_map(static function (array $visit) use ($formatDurationLabel): array {
            unset($visit['start_ts']);
            return $visit + ['duration_label' => $formatDurationLabel($visit['duration_seconds'])];
        }, $visits));
        $first = $visits[0];
        $latest = $visits[count($visits) - 1];
        $durationSec = array_sum(array_column($visits, 'duration_seconds'));
        $current = array_values(array_filter($visits, static fn(array $visit): bool => $visit['session_id'] === $specificSessionId))[0] ?? $latest;

        echo json_encode([
            'success' => true,
            'session' => [
                'session_id' => $specificSessionId,
                'visitor_id' => $visitorId,
                'started_at' => $first['started_at'],
                'ended_at' => $latest['ended_at'],
                'duration_seconds' => $durationSec,
                'duration_label' => $formatDurationLabel($durationSec),
                'event_count' => count($events),
                'pageview_count' => array_sum(array_column($visits, 'pageview_count')),
                'custom_event_count' => array_sum(array_column($visits, 'event_count')),
                'entry_page' => $current['entry_page'],
                'referrer' => $current['referrer'],
                'browser' => $context['browser'] ?? 'Other',
                'os' => $context['os'] ?? 'Other',
                'device' => $context['device'] ?? 'Desktop',
                'country' => $context['country'] ?? 'Unknown',
                'country_code' => $context['country_code'] ?? 'UN',
                'city' => $context['city'] ?? '',
                'tracking_mode' => $context['_ml_tracking_mode'] ?? 'unknown',
                'visit_count' => count($visits),
                'visits' => $visits,
                'avatar_url' => $avatarUrl($visitorId),
                'events' => $events,
            ],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 2. Listing sessions with aggregations
    $search = trim($_GET['search'] ?? '');
    $day = trim($_GET['day'] ?? $_GET['date'] ?? '');
    $eventName = trim($_GET['event_name'] ?? $_GET['event'] ?? '');
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 25)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $startDateStr = $day . ' 00:00:00';
        $endDateStr = $day . ' 23:59:59';
    } else {
        $period = Period::fromRequest($_GET);
        $startDateStr = $period->startText();
        $endDateStr = $period->endText();
    }

    $where = ["timestamp >= :start_date AND timestamp <= :end_date"];
    $params = [
        ':start_date' => $startDateStr,
        ':end_date' => $endDateStr,
    ];

    if ($eventName !== '' && $eventName !== 'all') {
        $where[] = "session_id IN (
            SELECT DISTINCT sub_ua.session_id 
            FROM user_activity sub_ua 
            WHERE json_extract(sub_ua.action, '$.name') = :event_name
              AND sub_ua.timestamp >= :start_date AND sub_ua.timestamp <= :end_date
        )";
        $params[':event_name'] = $eventName;
    }

    if ($search !== '') {
        $where[] = "(session_id LIKE :search OR visitor_id LIKE :search OR action LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    // Overview filters (pages, referrers, environment, countries)
    $filterCondition = AnalyticsFilters::apply($db, AnalyticsFilters::fromRequest($_GET), $startDateStr, $endDateStr);
    if ($filterCondition !== '') {
        $where[] = preg_replace('/^\s*AND\s+/', '', $filterCondition);
    }

    $whereClause = implode(' AND ', $where);

    // One row per visitor, their visits merged, and the latest activity first.
    $visitsSql = "
        SELECT
            session_id,
            COALESCE(MAX(visitor_id), session_id) as visitor_key,
            MIN(timestamp) as started_at,
            MAX(timestamp) as last_active_at,
            SUM(CASE WHEN json_extract(action, '$.name') = 'pageview' THEN 1 ELSE 0 END) as pageview_count,
            SUM(CASE WHEN json_extract(action, '$.name') NOT IN ('pageview', '_ml_engaged') THEN 1 ELSE 0 END) as event_count,
            SUM(CASE WHEN json_extract(action, '$.data._ml_revenue') > 0 THEN json_extract(action, '$.data._ml_revenue') + 0 ELSE 0 END) as revenue,
            MAX(CASE WHEN json_extract(action, '$.data._ml_revenue') > 0 THEN json_extract(action, '$.data.currency') END) as currency,
            (strftime('%s', MAX(timestamp)) - strftime('%s', MIN(timestamp))) as duration_seconds
        FROM user_activity
        WHERE {$whereClause}
        GROUP BY session_id
    ";

    $cntStmt = $db->prepare("SELECT COUNT(DISTINCT visitor_key) FROM ({$visitsSql}) visits");
    foreach ($params as $k => $v) {
        $cntStmt->bindValue($k, $v, SQLITE3_TEXT);
    }
    $totalVisitors = (int) $cntStmt->execute()->fetchArray(SQLITE3_NUM)[0];

    $sessSql = "
        SELECT
            visitor_key as visitor_id,
            COUNT(*) as visit_count,
            MIN(started_at) as started_at,
            MAX(last_active_at) as last_active_at,
            SUM(pageview_count) as pageview_count,
            SUM(event_count) as event_count,
            SUM(revenue) as revenue,
            MAX(currency) as currency,
            SUM(duration_seconds) as duration_seconds
        FROM ({$visitsSql}) visits
        GROUP BY visitor_key
        ORDER BY last_active_at DESC, started_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $sStmt = $db->prepare($sessSql);
    foreach ($params as $k => $v) {
        $sStmt->bindValue($k, $v, SQLITE3_TEXT);
    }
    $sStmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $sStmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $sRes = $sStmt->execute();

    $sessions = [];
    while ($sr = $sRes->fetchArray(SQLITE3_ASSOC)) {
        $visitorId = (string) $sr['visitor_id'];
        // The card opens on the visitor's latest visit, whose device and location it shows.
        $latestStmt = $db->prepare("SELECT session_id FROM user_activity WHERE (visitor_id = :visitor OR session_id = :visitor) AND timestamp <= :end_date ORDER BY timestamp DESC, id DESC LIMIT 1");
        $latestStmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        $latestStmt->bindValue(':end_date', $endDateStr, SQLITE3_TEXT);
        $sId = (string) ($latestStmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? $visitorId);
        // All the visits of the visitor, as their journey shows them, including those outside the period.
        $totalStmt = $db->prepare("SELECT COUNT(DISTINCT session_id) FROM user_activity WHERE visitor_id = :visitor OR session_id = :visitor");
        $totalStmt->bindValue(':visitor', $visitorId, SQLITE3_TEXT);
        $totalVisits = (int) $totalStmt->execute()->fetchArray(SQLITE3_NUM)[0];
        $durSec = max(0, (int) $sr['duration_seconds']);
        $durLabel = $formatDurationLabel($durSec);

        // Fetch real metadata and journey flow for this session (up to 8 items)
        $flowStmt = $db->prepare("SELECT action, timestamp FROM user_activity WHERE session_id = :sid ORDER BY timestamp ASC, id ASC LIMIT 8");
        $flowStmt->bindValue(':sid', $sId, SQLITE3_TEXT);
        $flowRes = $flowStmt->execute();

        $flow = [];
        $entryPage = null;
        $referrer = null;
        $browser = 'Other';
        $os = 'Other';
        $device = 'Desktop';
        $country = 'Unknown';
        $countryCode = 'UN';
        $city = '';

        while ($fr = $flowRes->fetchArray(SQLITE3_ASSOC)) {
            $act = json_decode($fr['action'], true) ?: [];
            $name = $act['name'] ?? 'action';
            $data = $act['data'] ?? [];

            if ($entryPage === null) {
                if (!empty($data['path'])) {
                    $entryPage = $data['path'];
                }
                if (!empty($data['referrer'])) {
                    $referrer = $data['referrer'];
                }
                if (!empty($data['browser'])) {
                    $browser = $data['browser'];
                }
                if (!empty($data['os'])) {
                    $os = $data['os'];
                }
                if (!empty($data['device'])) {
                    $device = $data['device'];
                }
                if (!empty($data['country'])) {
                    $country = $data['country'];
                }
                if (!empty($data['country_code'])) {
                    $countryCode = $data['country_code'];
                }
                if (!empty($data['city'])) {
                    $city = $data['city'];
                }
            }

            if ($name === '_ml_engaged') {
                continue;
            }
            if ($name === 'pageview') {
                $p = $data['path'] ?? '/';
                $flow[] = ['type' => 'pageview', 'label' => $p];
            } else {
                $flow[] = ['type' => 'event', 'label' => $name];
            }
        }

        // Relative time ago calculation, from the latest activity
        $startTs = strtotime($sr['last_active_at'] . ' UTC');
        $diff = time() - $startTs;
        if ($diff < 60) {
            $timeAgo = 'just now';
        } elseif ($diff < 3600) {
            $timeAgo = floor($diff / 60) . 'm ago';
        } elseif ($diff < 86400) {
            $timeAgo = floor($diff / 3600) . 'h ago';
        } else {
            $timeAgo = floor($diff / 86400) . 'd ago';
        }

        $sessions[] = [
            'session_id' => $sId,
            'visitor_id' => $visitorId,
            'visit_count' => (int) $sr['visit_count'],
            'total_visit_count' => max($totalVisits, (int) $sr['visit_count']),
            'started_at' => $sr['started_at'],
            'last_active_at' => $sr['last_active_at'],
            'time_ago' => $timeAgo,
            'duration_seconds' => $durSec,
            'duration_label' => $durLabel,
            'pageview_count' => (int) $sr['pageview_count'],
            'event_count' => (int) $sr['event_count'],
            'revenue' => round((float) $sr['revenue'], 2),
            'currency' => $sr['currency'] !== null ? strtoupper((string) $sr['currency']) : null,
            'entry_page' => $entryPage ?: '/',
            'referrer' => $referrer ?: 'Direct',
            'browser' => $browser,
            'os' => $os,
            'device' => $device,
            'country' => $country,
            'country_code' => strtoupper($countryCode),
            'city' => $city,
            'avatar_url' => $avatarUrl($visitorId),
            'flow' => $flow,
        ];
    }

    // Fetch distinct event names for the filter dropdown
    $evtTypesStmt = $db->prepare("
        SELECT json_extract(action, '$.name') as name, COUNT(*) as count 
        FROM user_activity 
        WHERE timestamp >= :start_date AND timestamp <= :end_date
        GROUP BY name 
        ORDER BY count DESC
    ");
    $evtTypesStmt->bindValue(':start_date', $startDateStr, SQLITE3_TEXT);
    $evtTypesStmt->bindValue(':end_date', $endDateStr, SQLITE3_TEXT);
    $evtRes = $evtTypesStmt->execute();
    $availableEvents = [];
    while ($er = $evtRes->fetchArray(SQLITE3_ASSOC)) {
        if (!empty($er['name']) && $er['name'] !== '_ml_engaged') {
            $availableEvents[] = [
                'name' => $er['name'],
                'count' => (int) $er['count'],
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'sessions' => $sessions,
        'total' => $totalVisitors,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => max(1, (int) ceil($totalVisitors / $limit)),
        'available_events' => $availableEvents,
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
