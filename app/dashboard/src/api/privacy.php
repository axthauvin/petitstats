<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Database\Connections;
use Petitstats\Sites\SiteRegistry;

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../vendor/autoload.php';
Auth::requireAdmin();
try {
    $site = SiteRegistry::requireId($_GET['site_id'] ?? null);
    $visitor = trim((string) ($_GET['visitor_id'] ?? ''));
    if ($visitor === '') {
        throw new InvalidArgumentException('visitor_id is required.');
    }
    $db = Connections::forSite($site);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'DELETE') {
        $s = $db->prepare('DELETE FROM user_activity WHERE visitor_id=:visitor');
        $s->bindValue(':visitor', $visitor, SQLITE3_TEXT);
        $s->execute();
        echo json_encode(['success' => true,'deleted' => $db->changes()]);
        exit;
    }
    $s = $db->prepare('SELECT action,timestamp FROM user_activity WHERE visitor_id=:visitor ORDER BY timestamp');
    $s->bindValue(':visitor', $visitor, SQLITE3_TEXT);
    $r = $s->execute();
    $events = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $events[] = ['timestamp' => $row['timestamp'],'action' => json_decode($row['action'], true)];
    }echo json_encode(['site_id' => $site,'visitor_id' => $visitor,'events' => $events], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
