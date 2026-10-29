<?php

use Petitstats\Auth\Auth;
use Petitstats\Http\View;
use Petitstats\Sites\SiteRegistry;

require_once __DIR__ . '/../vendor/autoload.php';
Auth::startSession();
if (!Auth::hasDatabase()) {
    header('Location: /dashboard/onboarding.php');
    exit;
}
$authUser = Auth::user();
$isGuest = false;
$publicSite = null;
if (!$authUser) {
    // Live demo: anonymous visitors may browse a public website read-only
    // (?demo=1 picks the first public site, ?site=<id> a specific one).
    $requestedSite = $_GET['site'] ?? $_GET['site_id'] ?? null;
    if ($requestedSite !== null || !empty($_GET['demo'])) {
        $publicSite = SiteRegistry::findPublic($requestedSite !== null ? (string) $requestedSite : null);
    }
    if (!$publicSite) {
        header('Location: /dashboard/login.php');
        exit;
    }
    $isGuest = true;
    if ($requestedSite === null) {
        // Pin the URL to the demo site so the front-end opens it directly.
        $query = $_GET;
        unset($query['demo']);
        $query['site'] = $publicSite['id'];
        header('Location: /dashboard/?' . http_build_query($query) . '#overview');
        exit;
    }
}
$isEmbed = !empty($_GET['embed']);
// Ensure trailing slash when accessed as /dashboard to prevent relative path resolution issues
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($reqUri, PHP_URL_PATH);
if ($path === '/dashboard') {
    $query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /dashboard/' . $query, true, 301);
    exit;
}
echo View::render('dashboard/index', [
    'authUser' => $authUser,
    'isGuest' => $isGuest,
    'isEmbed' => $isEmbed,
    'publicSite' => $publicSite,
]);
