<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Http\Http;
use Petitstats\OAuth\OAuthException;
use Petitstats\OAuth\OAuthServer;

// RFC 7591 dynamic client registration: assistants register themselves before sending the user to the consent screen.
require_once __DIR__ . '/../vendor/autoload.php';
Http::allowCors('POST');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    Http::json(['error' => 'invalid_request', 'error_description' => 'Use POST.'], 405);
}
if (!Auth::hasDatabase()) {
    Http::json(['error' => 'temporarily_unavailable', 'error_description' => 'Petitstats is not set up yet.'], 503);
}
$request = json_decode((string) file_get_contents('php://input'), true);
try {
    Http::json(OAuthServer::registerClient(is_array($request) ? $request : []), 201);
} catch (OAuthException $e) {
    Http::json($e->toArray(), $e->status);
}
