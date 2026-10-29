<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Updates\Changelog;

/** What changed in the releases newer than `after`, up to and including `up_to` (both optional). */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../vendor/autoload.php';
Auth::requireLogin();

$version = static fn(string $name): ?string => is_string($_GET[$name] ?? null) && $_GET[$name] !== '' ? $_GET[$name] : null;
$releases = Changelog::create()->between($version('after'), $version('up_to'), !empty($_GET['refresh']));

echo json_encode(['releases' => $releases ?? [], 'reachable' => $releases !== null], JSON_UNESCAPED_SLASHES);
