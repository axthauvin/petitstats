<?php

declare(strict_types=1);

use Petitstats\Auth\McpTokens;
use Petitstats\Auth\BearerTokens;
use Petitstats\Http\Http;
use Petitstats\Mcp\AnalyticsTools;
use Petitstats\Mcp\McpServer;
use Petitstats\OAuth\OAuthServer;

/**
 * MCP endpoint giving AI assistants access to the analytics. They only read,
 * unless the user allowed the connection to edit funnels, goals and journeys.
 *
 * Streamable HTTP transport, answering every request with a single JSON
 * response (no SSE stream). Requests carry `Authorization: Bearer <token>`:
 * either an OAuth token obtained through the consent screen (Claude, ChatGPT,
 * Cursor… sign in by themselves), or an access token from Settings > AI assistants.
 */
require_once __DIR__ . '/vendor/autoload.php';
Http::allowCors('POST');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'This MCP endpoint only accepts POST requests.']);
    exit;
}

$token = BearerTokens::fromRequest();
$user = $token === null ? null : (OAuthServer::authenticate($token) ?? McpTokens::authenticate($token));
if ($user === null) {
    // The 401 points clients to the OAuth metadata, which starts the sign-in flow.
    http_response_code(401);
    header('WWW-Authenticate: Bearer resource_metadata="' . OAuthServer::resourceMetadataUrl() . '", scope="' . OAuthServer::SCOPE . '"' . ($token === null ? '' : ', error="invalid_token"'));
    echo json_encode(['error' => 'Sign in to Petitstats, or send an access token: Authorization: Bearer <token>.']);
    exit;
}

$message = json_decode((string) file_get_contents('php://input'), true);
$response = json_last_error() === JSON_ERROR_NONE
    ? (new McpServer(AnalyticsTools::all(), $user['can_write']))->handle($message)
    : McpServer::error(null, -32700, 'Parse error.');

if ($response === null) {
    http_response_code(202);
    exit;
}
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
