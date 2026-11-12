<?php

declare(strict_types=1);

use Petitstats\Analytics\Period;
use Petitstats\Analytics\ReportQuery;
use Petitstats\Auth\Auth;
use Petitstats\Database\Connections;
use Petitstats\Sites\SiteRegistry;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../../../vendor/autoload.php';
// Public demo sites are readable without an account.
Auth::requireSiteAccess((string) ($_GET['site_id'] ?? $_GET['site'] ?? ''));


try {
    $siteId = $_GET['site_id'] ?? $_GET['site'] ?? null;
    $cleanSite = SiteRegistry::requireId($siteId);
    $db = Connections::forSite($cleanSite);

    $search = trim($_GET['search'] ?? '');
    $eventName = trim($_GET['event_name'] ?? '');
    $sessionId = trim($_GET['session_id'] ?? '');
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $period = Period::fromRequest($_GET);
    $range = $period->range;
    $now = $period->now;
    $startUnix = $period->start;
    $endUnix = $period->end;
    $startDateStr = $period->startText();
    $endDateStr = $period->endText();
    // The Events Explorer is reserved for product/custom events. Pageviews
    // belong to the traffic overview and would otherwise flatten every other
    // series on this multi-event chart.
    $eventOnlyClause = ReportQuery::NOT_INTERNAL . " AND json_extract(action, '$.name') <> 'pageview'";

    // Build WHERE clauses
    $where = ["timestamp >= :start_date AND timestamp <= :end_date", $eventOnlyClause];
    $params = [
        ':start_date' => $startDateStr,
        ':end_date' => $endDateStr,
    ];

    // Selected event names: a JSON array ("events") or a single "event_name".
    $selectedEvents = json_decode((string) ($_GET['events'] ?? '[]'), true);
    $selectedEvents = is_array($selectedEvents) ? array_values(array_filter($selectedEvents, 'is_string')) : [];
    if ($eventName !== '' && $eventName !== 'all') {
        $selectedEvents[] = $eventName;
    }
    $selectedEvents = array_slice(array_values(array_unique($selectedEvents)), 0, 200);
    $selectionClause = '';
    $selectionParams = [];
    if ($selectedEvents) {
        $placeholders = [];
        foreach ($selectedEvents as $i => $name) {
            $placeholders[] = ':event_' . $i;
            $selectionParams[':event_' . $i] = $name;
        }
        $selectionClause = "json_extract(action, '$.name') IN (" . implode(', ', $placeholders) . ')';
        $where[] = $selectionClause;
        $params += $selectionParams;
    }

    if ($sessionId !== '') {
        $where[] = "session_id = :session_id";
        $params[':session_id'] = $sessionId;
    }

    if ($search !== '') {
        $where[] = "(session_id LIKE :search OR json_extract(action, '$.name') LIKE :search OR action LIKE :search_wild)";
        $params[':search'] = "%{$search}%";
        $params[':search_wild'] = "%{$search}%";
    }

    $whereClause = implode(' AND ', $where);

    // 1. Total matching count
    $countSql = "SELECT COUNT(*) FROM user_activity WHERE {$whereClause}";
    $cStmt = $db->prepare($countSql);
    foreach ($params as $k => $v) {
        $cStmt->bindValue($k, $v, SQLITE3_TEXT);
    }
    $totalCount = (int) $cStmt->execute()->fetchArray(SQLITE3_NUM)[0];

    // 2. Fetch events paginated
    $eventsSql = "SELECT id, session_id, visitor_id, action, timestamp 
                  FROM user_activity 
                  WHERE {$whereClause} 
                  ORDER BY timestamp DESC, id DESC 
                  LIMIT :limit OFFSET :offset";
    $eStmt = $db->prepare($eventsSql);
    foreach ($params as $k => $v) {
        $eStmt->bindValue($k, $v, SQLITE3_TEXT);
    }
    $eStmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $eStmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $eRes = $eStmt->execute();

    $events = [];
    while ($row = $eRes->fetchArray(SQLITE3_ASSOC)) {
        $actionData = json_decode($row['action'], true) ?: [];
        $tsUnix = strtotime($row['timestamp'] . ' UTC');
        $diff = max(0, $now - $tsUnix);

        if ($diff < 60) {
            $timeAgo = "just now";
        } elseif ($diff < 3600) {
            $timeAgo = floor($diff / 60) . "m ago";
        } elseif ($diff < 86400) {
            $timeAgo = floor($diff / 3600) . "h ago";
        } else {
            $timeAgo = floor($diff / 86400) . "d ago";
        }

        $events[] = [
            'id' => (int) $row['id'],
            'session_id' => $row['session_id'],
            'visitor_id' => $row['visitor_id'] ?? $row['session_id'],
            'name' => $actionData['name'] ?? 'unknown',
            'site_id' => $actionData['site_id'] ?? 'default_site',
            'data' => $actionData['data'] ?? [],
            'timestamp' => $row['timestamp'],
            'time_ago' => $timeAgo,
        ];
    }

    // The previous period has the same length and ends where this one starts.
    // "All time" has nothing before it to compare with.
    $hasPrevious = $range !== 'all';
    $prevStartStr = gmdate('Y-m-d H:i:s', max(0, $startUnix - ($endUnix - $startUnix)));

    // 3. Per-event breakdown (always every event, so any of them can be selected)
    $typesSql = "SELECT json_extract(action, '$.name') as name, COUNT(*) as count,
                        COUNT(DISTINCT COALESCE(visitor_id, session_id)) as visitors,
                        COUNT(DISTINCT session_id) as sessions
                 FROM user_activity
                 WHERE timestamp >= :start_date AND timestamp <= :end_date
                   AND {$eventOnlyClause}
                 GROUP BY name
                 ORDER BY count DESC";
    $tStmt = $db->prepare($typesSql);
    $tStmt->bindValue(':start_date', $startDateStr, SQLITE3_TEXT);
    $tStmt->bindValue(':end_date', $endDateStr, SQLITE3_TEXT);
    $tRes = $tStmt->execute();

    $previousCounts = [];
    if ($hasPrevious) {
        $pStmt = $db->prepare("SELECT json_extract(action, '$.name') as name, COUNT(*) as count
                               FROM user_activity
                               WHERE timestamp >= :start_date AND timestamp < :end_date AND {$eventOnlyClause}
                               GROUP BY name");
        $pStmt->bindValue(':start_date', $prevStartStr, SQLITE3_TEXT);
        $pStmt->bindValue(':end_date', $startDateStr, SQLITE3_TEXT);
        $pRes = $pStmt->execute();
        while ($pr = $pRes->fetchArray(SQLITE3_ASSOC)) {
            $previousCounts[(string) $pr['name']] = (int) $pr['count'];
        }
    }

    $types = [];
    while ($tr = $tRes->fetchArray(SQLITE3_ASSOC)) {
        if (!empty($tr['name'])) {
            $types[] = [
                'name' => $tr['name'],
                'count' => (int) $tr['count'],
                'visitors' => (int) $tr['visitors'],
                'sessions' => (int) $tr['sessions'],
                'previous_count' => $hasPrevious ? ($previousCounts[$tr['name']] ?? 0) : null,
            ];
        }
    }

    // Totals for the selection (or every event). Visitors are counted once even
    // when they triggered several selected events, so they cannot be summed per event.
    $summarize = function (string $start, string $end, bool $endInclusive) use ($db, $where, $params): array {
        $sql = 'SELECT COUNT(*), COUNT(DISTINCT COALESCE(visitor_id, session_id)), COUNT(DISTINCT session_id) FROM user_activity WHERE '
            . implode(' AND ', $where);
        if (!$endInclusive) {
            $sql = str_replace('timestamp <= :end_date', 'timestamp < :end_date', $sql);
        }
        $stmt = $db->prepare($sql);
        foreach (array_merge($params, [':start_date' => $start, ':end_date' => $end]) as $k => $v) {
            $stmt->bindValue($k, $v, SQLITE3_TEXT);
        }
        $row = $stmt->execute()->fetchArray(SQLITE3_NUM) ?: [0, 0, 0];
        return ['events' => (int) $row[0], 'visitors' => (int) $row[1], 'sessions' => (int) $row[2]];
    };
    $summary = $summarize($startDateStr, $endDateStr, true);
    $summary['previous'] = $hasPrevious ? $summarize($prevStartStr, $startDateStr, false) : null;
    $series = [];
    $seriesByName = [];
    foreach ($types as $index => $type) {
        $key = 'event_' . $index;
        $series[] = ['key' => $key, 'name' => $type['name'], 'count' => $type['count']];
        $seriesByName[$type['name']] = $key;
    }

    // 4. Chart data: continuous time-series matching overview.js style
    $useHourly = in_array($range, ['today', '24h']);
    $intervalHours = $useHourly ? 1 : 24;
    $chartGroupFmt = $useHourly ? "%Y-%m-%d %H:00" : "%Y-%m-%d";

    $chartWhere = ["timestamp >= :chart_start AND timestamp <= :chart_end", $eventOnlyClause];
    $chartParams = [
        ':chart_start' => $startDateStr,
        ':chart_end' => $endDateStr,
    ];
    if ($sessionId !== '') {
        $chartWhere[] = "session_id = :chart_session_id";
        $chartParams[':chart_session_id'] = $sessionId;
    }
    $chartSql = "SELECT strftime('{$chartGroupFmt}', timestamp) as bucket,
                        json_extract(action, '$.name') as name,
                        COUNT(*) as count
                 FROM user_activity
                 WHERE " . implode(' AND ', $chartWhere) . "
                 GROUP BY bucket, name
                 ORDER BY bucket ASC";
    $chStmt = $db->prepare($chartSql);
    foreach ($chartParams as $k => $v) {
        $chStmt->bindValue($k, $v, SQLITE3_TEXT);
    }
    $chRes = $chStmt->execute();

    $slotMap = [];
    while ($cr = $chRes->fetchArray(SQLITE3_ASSOC)) {
        $key = $seriesByName[$cr['name'] ?? ''] ?? null;
        if ($key !== null) {
            $slotMap[$cr['bucket']][$key] = (int) $cr['count'];
        }
    }

    if ($range === 'today' || $range === '24h') {
        $effectiveStart = ($range === 'today') ? strtotime('today midnight UTC') : floor(($now - 86400) / 3600) * 3600;
        $endStep = ceil($now / 3600) * 3600;
    } elseif ($range === '7d') {
        $effectiveStart = strtotime('6 days ago midnight UTC');
        $endStep = strtotime('today midnight UTC');
    } elseif ($range === '30d') {
        $effectiveStart = strtotime('29 days ago midnight UTC');
        $endStep = strtotime('today midnight UTC');
    } elseif ($range === '90d') {
        $effectiveStart = strtotime('89 days ago midnight UTC');
        $endStep = strtotime('today midnight UTC');
    } elseif ($range === '6m' || $range === '180d') {
        $effectiveStart = strtotime('179 days ago midnight UTC');
        $endStep = strtotime('today midnight UTC');
    } elseif ($range === 'custom') {
        $effectiveStart = strtotime(gmdate('Y-m-d', $startUnix) . ' 00:00:00 UTC');
        $endStep = strtotime(gmdate('Y-m-d', $endUnix) . ' 00:00:00 UTC');
    } elseif ($range === 'all') {
        $minDbTime = $db->querySingle("SELECT MIN(timestamp) FROM user_activity WHERE timestamp IS NOT NULL");
        $effectiveStart = $minDbTime ? strtotime(substr((string) $minDbTime, 0, 10) . ' 00:00:00 UTC') : strtotime('6 days ago midnight UTC');
        $endStep = strtotime('today midnight UTC');
    } else {
        $effectiveStart = strtotime('6 days ago midnight UTC');
        $endStep = strtotime('today midnight UTC');
    }
    $stepSeconds = $intervalHours * 3600;

    $chartData = [];
    $currStep = $effectiveStart;
    while ($currStep <= $endStep) {
        $point = ['timestamp' => $currStep];
        foreach ($series as $item) {
            $point[$item['key']] = 0;
        }

        // The SQL query already returns a bucket per hour or per day.  A daily
        // bucket must be added once — repeating it for all 24 hours inflated
        // every daily count by 24 (for example 80 incorrectly became 1920).
        $bucketKey = gmdate($useHourly ? 'Y-m-d H:00' : 'Y-m-d', (int) $currStep);
        if (isset($slotMap[$bucketKey])) {
            foreach ($slotMap[$bucketKey] as $key => $count) {
                $point[$key] += $count;
            }
        }

        if ($range === 'today' || $range === '24h') {
            $timeLabel = gmdate('h A', (int) $currStep);
        } elseif ($range === '7d') {
            $timeLabel = gmdate('D, j M', (int) $currStep);
        } else {
            $timeLabel = gmdate('M d', (int) $currStep);
        }

        $fullLabel = ($intervalHours === 1)
            ? gmdate('l, F j, Y \a\t h:i A', (int) $currStep)
            : gmdate('l, F j, Y', (int) $currStep);

        $point['label'] = $timeLabel;
        $point['full_label'] = $fullLabel;
        $chartData[] = $point;

        $currStep += $stepSeconds;
    }

    echo json_encode([
        'success'     => true,
        'events'      => $events,
        'types'       => $types,
        'summary'     => $summary,
        'selected'    => $selectedEvents,
        'series'      => $series,
        'chart_data'  => $chartData,
        'total'       => $totalCount,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => max(1, (int) ceil($totalCount / $limit)),
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
