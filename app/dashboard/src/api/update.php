<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Updates\UpdateCheck;
use Petitstats\Updates\Updater;

/** Installs the latest release from Settings → Updates, one step per request (see Petitstats\Updates\Updater). */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../../vendor/autoload.php';

$updater = Updater::create();

// The installer asks the files it just wrote which version they run, before
// keeping them. Every release must keep answering this request.
if (isset($_GET['health'])) {
    $token = $_GET['health'];
    if (!is_string($token) || !$updater->acceptsHealthCheck($token)) {
        http_response_code(404);
        Auth::jsonError('No update is being checked.');
    }
    echo json_encode(Updater::healthAnswer($token, UpdateCheck::create()->installed()), JSON_UNESCAPED_SLASHES);
    exit;
}

Auth::requireAdmin();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    echo json_encode($updater->status(), JSON_UNESCAPED_SLASHES);
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    Auth::jsonError('Method not allowed.');
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
try {
    switch ($body['step'] ?? '') {
        case 'check':
            echo json_encode(['problems' => $updater->problems(), 'status' => $updater->status()], JSON_UNESCAPED_SLASHES);
            exit;
        case 'download':
            $check = UpdateCheck::create();
            $latest = $check->latest();
            if ($latest === null) {
                throw new RuntimeException('GitHub could not be reached. Try again later.');
            }
            if (!UpdateCheck::isNewer($latest['version'], $check->installed())) {
                throw new RuntimeException('Petitstats is already up to date.');
            }
            $updater->download($latest['version']);
            break;
        case 'backup':
            $updater->backup();
            break;
        case 'install':
            $updater->install();
            break;
        case 'rollback':
            $updater->rollback();
            break;
        default:
            http_response_code(400);
            Auth::jsonError('Unknown update step.');
    }
    echo json_encode(['success' => true, 'status' => $updater->status()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage(), 'status' => $updater->status()], JSON_UNESCAPED_SLASHES);
}
