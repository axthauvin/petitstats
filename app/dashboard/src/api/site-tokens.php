<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Auth\SiteTokens;
use Petitstats\Sites\SiteRegistry;

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../vendor/autoload.php';

// Stats API tokens are managed by administrators, from a signed-in session only.
$user = Auth::requireAdmin();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = $method === 'GET' ? [] : (json_decode((string) file_get_contents('php://input'), true) ?: []);

$site = SiteRegistry::find((string) ($body['site_id'] ?? $_GET['site_id'] ?? ''));
if ($site === null) {
    http_response_code(404);
    Auth::jsonError('Website not found.');
}
$siteId = (string) $site['id'];

if ($method === 'GET') {
    echo json_encode(['tokens' => SiteTokens::forSite($siteId)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '' || strlen($name) > 80) {
        http_response_code(400);
        Auth::jsonError('Give the token a name (80 characters at most).');
    }
    echo json_encode(['success' => true, 'token' => SiteTokens::create($siteId, $name, (int) $user['id'])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'DELETE') {
    if (!SiteTokens::revoke($siteId, (int) ($body['id'] ?? 0))) {
        http_response_code(404);
        Auth::jsonError('Token not found.');
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
Auth::jsonError('Method not allowed.');
