<?php

/**
 * Exports websites to a ZIP file:
 *   php bin/export.php                                  every website, as a Petitstats archive
 *   php bin/export.php --site blog --format csv         one website, as CSV files
 *   php bin/export.php --output /backups/petitstats.zip
 * Restore an archive with: php bin/import.php --provider petitstats --zip <archive>
 */
declare(strict_types=1);

use Petitstats\Export\Exporter;

if (PHP_SAPI !== 'cli') {
    exit;
}
require_once dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['site:', 'format:', 'output:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/export.php [--site <id>] [--format archive|csv|json] [--output <file.zip>]\n\n";
    echo "  --site <id>      Website to export. Every website when left out\n";
    echo "  --format <name>  archive (default): to move the websites to another Petitstats, SQLite or MySQL\n";
    echo "                   csv or json: the visits, blocked requests and funnels, to read elsewhere\n";
    echo "  --output <path>  Where to write the ZIP file. Defaults to the current folder\n\n";
    exit(0);
}

$siteIds = isset($options['site']) ? [(string) $options['site']] : [];
$format = (string) ($options['format'] ?? 'archive');
$output = (string) ($options['output'] ?? getcwd() . '/' . Exporter::fileName($siteIds, $format));

try {
    (new Exporter())->export($siteIds, $format, $output);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Saved {$output}\n";
