<?php

declare(strict_types=1);

use Petitstats\Database\Connections;
use Petitstats\Database\DatabaseConnection;
use Petitstats\Sites\DemoTraffic;
use Petitstats\Sites\SiteRegistry;

/**
 * Petitstats live demo seeder.
 *
 * Turns a website into the public demo: anonymous visitors can browse it and
 * it holds a rolling window of made-up traffic so every dashboard tab
 * (Overview, Acquisition, Events, Sessions, Funnels, Revenue) has data. Run it once:
 * the website then fills itself in each time someone looks at it, and drops
 * the visits older than the window (see Petitstats\Sites\DemoTraffic).
 *
 * Usage:
 *   php bin/seed-demo.php [--site=demo_site] [--days=30] [--reset]
 *                         [--domain=example.com ...]
 *
 *   --days    Length of the rolling window, in days.
 *   --reset   Delete the site's existing events and funnels first.
 *   --domain  Extra hostname allowed to send tracking hits (repeatable),
 *             e.g. the production domain serving the landing page.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['site::', 'days::', 'reset', 'domain::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/seed-demo.php [--site=demo_site] [--days=30] [--reset] [--domain=example.com]\n");
    exit(0);
}
$siteId = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($options['site'] ?? 'demo_site')));
$days = max(1, min(DemoTraffic::MAX_DAYS, (int) ($options['days'] ?? DemoTraffic::DEFAULT_DAYS)));
$extraDomains = array_filter((array) ($options['domain'] ?? []), 'is_string');

// ---------------------------------------------------------------------------
// 1. Website configuration: create if missing, flag as public demo, allow local hosts
// ---------------------------------------------------------------------------
if (!SiteRegistry::find($siteId)) {
    SiteRegistry::create($siteId, 'Petitstats Demo', 'localhost');
    fwrite(STDOUT, "Created website '{$siteId}'.\n");
}
$site = SiteRegistry::find($siteId);
$allowed = array_merge((array) ($site['allowed_domains'] ?? []), ['localhost', '127.0.0.1'], $extraDomains);
SiteRegistry::update($siteId, ['is_public' => true, 'demo_days' => $days, 'allowed_domains' => $allowed]);
fwrite(STDOUT, "Website '{$siteId}' is a public demo keeping {$days} days (allowed domains: " . implode(', ', array_unique(array_map([SiteRegistry::class, 'normalizeHost'], $allowed))) . ").\n");

$db = Connections::forSite($siteId);
ensureFunnelsTable($db);

if (isset($options['reset'])) {
    DemoTraffic::clear($siteId);
    $db->exec('DELETE FROM funnels');
    fwrite(STDOUT, "Existing analytics data deleted.\n");
}

// ---------------------------------------------------------------------------
// 2. Traffic: fills the window up to now (only the missing hours when data exists)
// ---------------------------------------------------------------------------
$visits = DemoTraffic::refresh($siteId);

// ---------------------------------------------------------------------------
// 3. Predefined funnels
// ---------------------------------------------------------------------------
$funnels = [
    ['Install conversion', 'funnel', [
        ['type' => 'pageview', 'value' => '/', 'label' => 'Landing page'],
        ['type' => 'pageview', 'value' => '/install.html', 'label' => 'Install guide'],
        ['type' => 'event', 'value' => 'copy_snippet', 'label' => 'Copied command'],
        ['type' => 'event', 'value' => 'download_zip', 'label' => 'Downloaded'],
    ]],
    ['Download', 'goal', [
        ['type' => 'event', 'value' => 'download_zip', 'label' => 'Downloaded Petitstats'],
    ]],
    ['Docs to install', 'journey', [
        ['type' => 'pageview', 'value' => '/docs/', 'label' => 'Documentation'],
        ['type' => 'pageview', 'value' => '/install.html', 'label' => 'Install guide'],
    ]],
];
$existing = [];
$result = $db->query('SELECT name FROM funnels');
while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    $existing[$row['name']] = true;
}
$funnelInsert = $db->prepare('INSERT INTO funnels (name, kind, steps) VALUES (:name, :kind, :steps)');
$createdFunnels = 0;
foreach ($funnels as [$name, $kind, $steps]) {
    if (isset($existing[$name])) {
        continue;
    }
    $funnelInsert->bindValue(':name', $name, SQLITE3_TEXT);
    $funnelInsert->bindValue(':kind', $kind, SQLITE3_TEXT);
    $funnelInsert->bindValue(':steps', json_encode($steps), SQLITE3_TEXT);
    $funnelInsert->execute();
    $createdFunnels++;
}

fwrite(STDOUT, "Added {$visits} visits and {$createdFunnels} funnels to '{$siteId}'.\n");

function ensureFunnelsTable(DatabaseConnection $db): void
{
    // Mirrors SavedFunnels::ensureTable().
    if ($db->isMysql()) {
        $db->exec("CREATE TABLE IF NOT EXISTS funnels (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'funnel', steps JSON NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS funnels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'funnel', steps TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
}
