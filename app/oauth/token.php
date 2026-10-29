<?php

declare(strict_types=1);

use Petitstats\Http\Http;
use Petitstats\OAuth\OAuthException;
use Petitstats\OAuth\OAuthServer;

// OAuth token endpoint: authorization code (with PKCE) and refresh token grants.
require_once __DIR__ . '/../vendor/autoload.php';
Http::allowCors('POST');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    Http::json(['error' => 'invalid_request', 'error_description' => 'Use POST.'], 405);
}
try {
    Http::json(OAuthServer::token($_POST));
} catch (OAuthException $e) {
    Http::json($e->toArray(), $e->status);
}
