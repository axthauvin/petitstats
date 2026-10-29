<?php

declare(strict_types=1);

use Petitstats\Analytics\SiteOverview;
use Petitstats\Auth\Auth;
use Petitstats\Sites\SiteRegistry;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
require_once __DIR__ . '/../../../vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Guests may list public demo sites; every other action requires an account.
if (!($method === 'GET' && empty($_GET['action']) && Auth::hasDatabase() && Auth::isGuest())) {
    Auth::requireLogin();
}

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}


try {
    $action = $_GET['action'] ?? '';
    // Tracking secrets and configuration are administrator-only.
    if ($action === 'tracking-config') {
        Auth::requireAdmin();
        $site = SiteRegistry::find((string) ($_GET['id'] ?? ''));
        if (!$site) {
            throw new InvalidArgumentException('Website not found.');
        }
        echo json_encode(['success' => true, 'site' => $site]);
        exit;
    }
    if ($method === 'PATCH' || $action === 'update-config') {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $site = SiteRegistry::update((string) ($body['id'] ?? $_GET['id'] ?? ''), $body);
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        echo json_encode(['success' => true, 'site' => $site, 'snippet' => SiteRegistry::trackingSnippet($site, "$scheme://$host/petitstats.js")], JSON_UNESCAPED_SLASHES);
        exit;
    }
    // 1. DELETE site
    if ($method === 'DELETE' || (isset($_GET['action']) && $_GET['action'] === 'delete')) {
        Auth::requireAdmin();
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        $siteId = trim($body['id'] ?? $_GET['id'] ?? '');

        if (empty($siteId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Website identifier is required for deletion']);
            exit;
        }

        SiteRegistry::delete($siteId);
        echo json_encode([
            'success' => true,
            'message' => "Website '{$siteId}' and its database were deleted successfully.",
        ]);
        exit;
    }

    // 2. POST: Create new website
    if ($method === 'POST') {
        Auth::requireAdmin();
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: $_POST;

        $id = trim($body['id'] ?? '');
        $name = trim($body['name'] ?? '');
        $domain = trim($body['domain'] ?? '');

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Website identifier (site ID) is required']);
            exit;
        }
        if (SiteRegistry::normalizeHost($domain) === '') {
            http_response_code(400);
            echo json_encode(['error' => 'A production domain is required to protect the tracking endpoint.']);
            exit;
        }

        $site = SiteRegistry::create($id, $name, $domain);

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $scriptUrl = "{$protocol}{$host}/petitstats.js";
        $trackingSnippet = SiteRegistry::trackingSnippet($site, $scriptUrl);

        echo json_encode([
            'success' => true,
            'site' => $site,
            'snippet' => $trackingSnippet,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 3. GET: List all websites with live statistics
    $sites = SiteOverview::all();
    if (Auth::isGuest()) {
        // Guests only see public demo sites, without tracking secrets.
        $sites = array_values(array_map([SiteRegistry::class, 'guestView'], array_filter($sites, static fn(array $s): bool => !empty($s['is_public']))));
    }
    echo json_encode([
        'success' => true,
        'sites' => $sites,
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
