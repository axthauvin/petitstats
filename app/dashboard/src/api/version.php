<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Updates\UpdateCheck;
use Petitstats\Updates\Updater;

/** The installed version, and for administrators whether a newer release exists. */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../vendor/autoload.php';
Auth::requireLogin();

$check = UpdateCheck::create();
$installed = $check->installed();
$response = ['installed' => $installed, 'releases_url' => UpdateCheck::RELEASES_URL];

if ((Auth::user()['role'] ?? '') === 'admin') {
    $latest = $check->latest(!empty($_GET['refresh']));
    $response['latest'] = $latest;
    $response['update_available'] = UpdateCheck::isNewer($latest['version'] ?? null, $installed);
    $response['install'] = Updater::create()->status();
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
