<?php

declare(strict_types=1);

use Petitstats\Api\SummaryApi;
use Petitstats\Auth\BearerTokens;

/**
 * Stats API: GET /api/v1/sites/{site_id}/summary, rewritten to this file with
 * `site_id` in the query string (.htaccess, or the Nginx and Caddy rules of
 * docs/operations). Called by servers only: no CORS, no cookies.
 */
require_once __DIR__ . '/../../vendor/autoload.php';

$response = SummaryApi::respond(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_GET, BearerTokens::fromRequest());

http_response_code($response['status']);
header('Content-Type: application/json; charset=utf-8');
foreach ($response['headers'] as $name => $value) {
    header("{$name}: {$value}");
}
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
