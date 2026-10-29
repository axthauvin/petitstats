<?php

declare(strict_types=1);

use Petitstats\Analytics\Period;
use Petitstats\Analytics\SavedFunnels;
use Petitstats\Analytics\SiteAnalytics;
use Petitstats\Auth\Auth;
use Petitstats\Database\Connections;
use Petitstats\Sites\SiteRegistry;

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../vendor/autoload.php';
// Funnels of public demo sites are readable; creating or deleting them needs an account.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    Auth::requireSiteAccess((string) ($_GET['site_id'] ?? ''));
} else {
    Auth::requireLogin();
}

try {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $siteId = $_GET['site_id'] ?? $payload['site_id'] ?? null;
    $site = SiteRegistry::requireId($siteId);
    $db = Connections::forSite($site);
    SavedFunnels::ensureTable($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = SavedFunnels::save($db, (int) ($payload['id'] ?? 0), (string) ($payload['name'] ?? ''), (string) ($payload['kind'] ?? 'funnel'), $payload['steps'] ?? []);
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        $st = $db->prepare('DELETE FROM funnels WHERE id=:id');
        $st->bindValue(':id', $id, SQLITE3_INTEGER);
        $st->execute();
        echo json_encode(['success' => true]);
        exit;
    }
    $period = Period::fromRequest($_GET);
    [$start, $end] = [$period->startText(), $period->endText()];
    $analytics = new SiteAnalytics($db, $site, $period);
    $items = [];
    $result = $db->query('SELECT * FROM funnels ORDER BY created_at DESC, id DESC');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $steps = json_decode($row['steps'], true) ?: [];
        $kind = $row['kind'] ?: 'funnel';
        $item = ['id' => (int) $row['id'], 'name' => $row['name'], 'kind' => $kind, 'steps' => $steps, 'progress' => $analytics->funnelProgress($steps)];
        if ($kind === 'journey') {
            $item['journey'] = $analytics->journey($steps);
            if (($item['journey']['completed'] ?? 0) === 0) {
                $item['journey']['fallback'] = $analytics->journey($steps, true);
            }
        } $items[] = $item;
    }
    // The builder exposes the site's complete vocabulary. The selected period
    // only affects the funnel's figures, never which event/page can be chosen.
    $events = [];
    $er = $db->query("SELECT DISTINCT json_extract(action, '$.name') AS name FROM user_activity ORDER BY name");
    while ($r = $er->fetchArray(SQLITE3_ASSOC)) {
        // Internal signals such as _ml_engaged are not something a visitor does.
        if ($r['name'] && $r['name'] !== 'pageview' && !str_starts_with($r['name'], '_ml_')) {
            $events[] = $r['name'];
        }
    }
    $pages = [];
    $pr = $db->query("SELECT DISTINCT json_extract(action, '$.data.path') AS path FROM user_activity WHERE json_extract(action, '$.name')='pageview' ORDER BY path");
    while ($r = $pr->fetchArray(SQLITE3_ASSOC)) {
        if ($r['path'] !== null && $r['path'] !== '') {
            $pages[] = $r['path'];
        }
    }
    $audienceStmt = $db->prepare('SELECT COUNT(DISTINCT COALESCE(visitor_id, session_id)) FROM user_activity WHERE timestamp >= :start AND timestamp <= :end');
    $audienceStmt->bindValue(':start', $start, SQLITE3_TEXT);
    $audienceStmt->bindValue(':end', $end, SQLITE3_TEXT);
    $audience = (int) $audienceStmt->execute()->fetchArray(SQLITE3_NUM)[0];
    echo json_encode(['success' => true,'funnels' => $items,'audience' => $audience,'events' => $events,'pages' => $pages]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
