<?php

declare(strict_types=1);

use Petitstats\Analytics\Period;
use Petitstats\Analytics\SiteAnalytics;
use Petitstats\Auth\Auth;

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../vendor/autoload.php';
Auth::requireSiteAccess((string) ($_GET['site_id'] ?? ''));
try {
    $analytics = SiteAnalytics::open($_GET['site_id'] ?? null, Period::fromRequest($_GET));
    echo json_encode(['success' => true,'reports' => $analytics->acquisition()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
