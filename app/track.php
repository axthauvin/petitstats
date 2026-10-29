<?php

declare(strict_types=1);

use Petitstats\Tracking\Tracker;

/** Collection endpoint called by petitstats.js. The work happens in Petitstats\Tracking\Tracker. */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
// CORS only decides whether the page can read the response: the allowlist is
// enforced on the server, before anything is stored. Every origin may read it,
// so whoever installs the snippet sees why an event was rejected.
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

$response = Tracker::handle($_SERVER, (string) file_get_contents('php://input'));
http_response_code($response->status);
echo json_encode($response->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
