<?php

declare(strict_types=1);

use Petitstats\Auth\McpTokens;
use Petitstats\Auth\Auth;
use Petitstats\OAuth\OAuthServer;

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../vendor/autoload.php';

// access tokens and connected assistants are managed from a signed-in session only: a token cannot manage tokens.
Auth::requireLogin();
$userId = (int) Auth::user()['id'];
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($method === 'GET') {
        echo json_encode(['mcp_url' => OAuthServer::resource(), 'apps' => OAuthServer::connectedApps($userId), 'tokens' => McpTokens::forUser($userId)], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];

    if ($method === 'POST') {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || strlen($name) > 80) {
            http_response_code(400);
            Auth::jsonError('Give the token a name (80 characters at most).');
        }
        echo json_encode(['success' => true, 'token' => McpTokens::create($userId, $name)]);
        exit;
    }

    if ($method === 'PATCH') {
        $allowed = ($body['can_write'] ?? false) === true;
        $found = isset($body['app'])
            ? OAuthServer::allowWrite($userId, (string) $body['app'], $allowed)
            : McpTokens::allowWrite($userId, (int) ($body['id'] ?? 0), $allowed);
        if (!$found) {
            http_response_code(404);
            Auth::jsonError(isset($body['app']) ? 'Application not found.' : 'Token not found.');
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'DELETE' && isset($body['app'])) {
        if (!OAuthServer::disconnect($userId, (string) $body['app'])) {
            http_response_code(404);
            Auth::jsonError('Application not found.');
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        if (!McpTokens::revoke($userId, (int) ($body['id'] ?? $_GET['id'] ?? 0))) {
            http_response_code(404);
            Auth::jsonError('Token not found.');
        }
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    Auth::jsonError('Method not allowed.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
