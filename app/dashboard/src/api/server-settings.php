<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Tracking\CloudflareTrust;

/** Server-wide tracking settings, shared by every website. */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../vendor/autoload.php';
Auth::requireAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    CloudflareTrust::save(filter_var($body['trust_cloudflare'] ?? false, FILTER_VALIDATE_BOOLEAN));
}

echo json_encode([
    'trust_cloudflare' => CloudflareTrust::isEnabled(),
    'forced_by_environment' => CloudflareTrust::isForcedByEnvironment(),
    // A hint only: this request reached the server through Cloudflare (or claims to).
    'request_via_cloudflare' => !empty($_SERVER['HTTP_CF_CONNECTING_IP']) && !empty($_SERVER['HTTP_CF_RAY']),
]);
