<?php

declare(strict_types=1);

use Petitstats\Importers\ImporterRegistry;

/**
 * Petitstats Pure-PHP CLI Import Tool
 * Runs in terminal via `php bin/import.php --zip export.zip`
 * 100% PHP, zero Python dependency!
 */

if (php_sapi_name() !== 'cli') {
    header('Location: /dashboard/');
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

echo "\n" . str_repeat('=', 60) . "\n";
echo "   Petitstats CLI Data Importer (100% Pure PHP)\n";
echo str_repeat('=', 60) . "\n\n";

$options = getopt('', ['provider:', 'zip:', 'file:', 'folder:', 'site-id:', 'site-name:', 'domain:', 'retention-days:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php bin/import.php [options]\n\n";
    echo "Options:\n";
    echo "  --provider <id>     Service the export comes from, umami (default) or matomo\n";
    echo "  --zip <path>        Path to a .zip export archive\n";
    echo "  --file <path>       Path to a single export file (Matomo .json or .csv)\n";
    echo "  --folder <path>     Path to a folder of export files\n";
    echo "  --site-id <id>      Target Petitstats site ID (e.g. example)\n";
    echo "  --site-name <name>  Display name for the website (e.g. Example)\n";
    echo "  --domain <domain>   Domain name (e.g. example.com)\n";
    echo "  --retention-days <n>  Days of visits the website keeps, up to 760. Older visits are not imported\n";
    echo "  --help             Display this help message\n\n";
    exit(0);
}

$source = $options['zip'] ?? $options['file'] ?? $options['folder'] ?? null;

if (!$source) {
    if (file_exists(dirname(__DIR__) . '/umami-export-sample.zip')) {
        $source = dirname(__DIR__) . '/umami-export-sample.zip';
    } elseif (is_dir(dirname(__DIR__) . '/umami-import')) {
        $source = dirname(__DIR__) . '/umami-import';
    } else {
        fwrite(STDERR, "Error: Please specify --zip <path>, --file <path> or --folder <path>\n");
        fwrite(STDERR, "Run `php bin/import.php --help` for usage details.\n\n");
        exit(1);
    }
}

if (!file_exists($source)) {
    fwrite(STDERR, "Error: Source path not found: {$source}\n");
    exit(1);
}

$siteId = $options['site-id'] ?? '';
$siteName = $options['site-name'] ?? '';
$domain = $options['domain'] ?? '';

$providerId = $options['provider'] ?? 'umami';
$importer = ImporterRegistry::getImporter($providerId);
if (!$importer || !$importer->isAvailable() || !method_exists($importer, 'inspect')) {
    fwrite(STDERR, "Error: '{$providerId}' cannot be imported yet. Available providers are umami and matomo.\n");
    exit(1);
}

echo "[1/3] Inspecting source: " . basename($source) . "...\n";
$meta = $importer->inspect($source);

if (empty($siteId)) {
    $siteId = $meta['suggested_site_id'] ?: 'imported_site';
}
if (empty($siteName)) {
    $siteName = $meta['suggested_name'] ?: ucwords(str_replace(['_', '-'], ' ', $siteId));
}
if (empty($domain)) {
    $domain = $meta['detected_host'] ?: '';
}

echo "      Target Site: '{$siteName}' (ID: {$siteId}, Domain: " . ($domain ?: 'none') . ")\n";
echo "[2/3] Processing events and inserting into the data directory ({$siteId}.db)...\n";

$start = microtime(true);
$importOptions = ['name' => $siteName, 'domain' => $domain];
if (isset($options['retention-days'])) {
    $importOptions['retention_days'] = (int) $options['retention-days'];
}
$result = $importer->import($source, $siteId, $importOptions);
$elapsed = round(microtime(true) - $start, 2);

echo "[3/3] Import successfully completed in {$elapsed}s!\n\n";
echo str_repeat('=', 60) . "\n";
echo sprintf("  Target Website:      %s (ID: %s)\n", $result['site_name'], $result['site_id']);
echo sprintf("  Database:            %s.db (in the data directory)\n", $result['site_id']);
echo sprintf("  Total Events:        %s\n", number_format($result['total_imported']));
echo sprintf("  Pageviews:           %s\n", number_format($result['pageviews']));
echo sprintf("  Custom Events:       %s\n", number_format($result['custom_events']));
echo sprintf("  Distinct Sessions:   %s\n", number_format($result['sessions']));
if (!empty($result['duplicate_sessions'])) {
    echo sprintf("  Already imported:    %s sessions (skipped)\n", number_format($result['duplicate_sessions']));
}
if (!empty($result['skipped_sessions'])) {
    echo sprintf("  Too old to keep:     %s sessions (skipped, see --retention-days)\n", number_format($result['skipped_sessions']));
}
if (!empty($result['date_start']) && !empty($result['date_end'])) {
    echo sprintf("  Date Range:          %s to %s\n", $result['date_start'], $result['date_end']);
}
echo str_repeat('=', 60) . "\n\n";
echo "View in Dashboard: http://localhost:8080/dashboard/?site={$siteId}\n\n";
