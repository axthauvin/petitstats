<?php

/**
 * Moves the visits of a crawler that got through the filters to the blocked
 * traffic, after the fact. Shows what would move unless --apply is given,
 * and even then asks before changing anything.
 *
 *   php bin/requalify-bots.php --site ecrismalettre --date 2026-11-25 \
 *       --country US --os Linux --browser Chrome --language en-US
 *
 * Only visits a crawler leaves behind can match: a first visit, landing
 * directly, one page view, no event, no interaction, 10 seconds at most
 * (see Petitstats\Tracking\FingerprintCleanup).
 *
 * With --apply, the website's database is first copied next to it, and the
 * events about to move are written to a JSON file: nothing is lost for good.
 */
declare(strict_types=1);

use Petitstats\Database\Connections;
use Petitstats\Database\DataDirectory;
use Petitstats\Sites\SiteRegistry;
use Petitstats\Tracking\FingerprintCleanup;

if (PHP_SAPI !== 'cli') {
    exit;
}
require_once dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['site:', 'date:', 'country:', 'os:', 'browser:', 'language:', 'max-duration:', 'apply', 'help']);
if (isset($options['help']) || !isset($options['site'], $options['date'])) {
    echo "Usage: php bin/requalify-bots.php --site <id> --date <YYYY-MM-DD> [profile] [--apply]\n\n";
    echo "  --site <id>           Website to clean up\n";
    echo "  --date <YYYY-MM-DD>   Day the visits started, in UTC\n";
    echo "  --country <code>      Country code, such as US\n";
    echo "  --os <name>           System, such as Linux\n";
    echo "  --browser <name>      Browser, such as Chrome\n";
    echo "  --language <tag>      Browser language, such as en-US\n";
    echo "  --max-duration <s>    Longest visit to consider, in seconds (default " . FingerprintCleanup::MAX_DURATION . ")\n";
    echo "  --apply               Move the visits, after a backup and a confirmation. Without it, only shows them\n\n";
    exit(isset($options['help']) ? 0 : 1);
}

$siteId = (string) $options['site'];
if (SiteRegistry::find($siteId) === null) {
    fwrite(STDERR, "Unknown website \"{$siteId}\".\n");
    exit(1);
}
$profile = array_filter([
    'country' => isset($options['country']) ? strtoupper((string) $options['country']) : null,
    'os' => $options['os'] ?? null,
    'browser' => $options['browser'] ?? null,
    'language' => $options['language'] ?? null,
], static fn($value): bool => is_string($value) && $value !== '');
if ($profile === []) {
    fwrite(STDERR, "Give at least one of --country, --os, --browser or --language: every short direct visit of the day would match otherwise.\n");
    exit(1);
}

$db = Connections::forSite($siteId);
$cleanup = new FingerprintCleanup($db);
try {
    $matches = $cleanup->matches((string) $options['date'], $profile, (int) ($options['max-duration'] ?? FingerprintCleanup::MAX_DURATION));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$described = implode(', ', array_map(static fn(string $key, string $value): string => "{$key} {$value}", array_keys($profile), $profile));
echo "Website: {$siteId}\nDay (UTC): {$options['date']}\nProfile: {$described}, direct, one page, no interaction\n\n";
echo count($matches) . " matching visit" . (count($matches) === 1 ? '' : 's') . " (" . array_sum(array_column($matches, 'rows')) . " stored events)\n";
if ($matches === []) {
    exit(0);
}

$hours = [];
$pages = [];
foreach ($matches as $match) {
    $hour = substr($match['started_at'], 11, 2) . ':00';
    $hours[$hour] = ($hours[$hour] ?? 0) + 1;
    $pages[$match['path']] = ($pages[$match['path']] ?? 0) + 1;
}
ksort($hours);
arsort($pages);
echo "\nBy hour (UTC):\n";
foreach ($hours as $hour => $count) {
    printf("  %s  %3d\n", $hour, $count);
}
echo "\nPages (" . count($pages) . " different):\n";
foreach (array_slice($pages, 0, 15, true) as $path => $count) {
    printf("  %3d  %s\n", $count, $path);
}
if (count($pages) > 15) {
    echo '  … and ' . (count($pages) - 15) . " more\n";
}

if (!isset($options['apply'])) {
    echo "\nDry run: nothing changed. Run again with --apply to move these visits to the blocked traffic.\n";
    exit(0);
}

// A copy of the database, and the events in a readable form, before anything moves.
$stamp = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
$backups = DataDirectory::path() . '/backups';
if (!is_dir($backups) && !mkdir($backups, 0700, true) && !is_dir($backups)) {
    fwrite(STDERR, "Cannot create {$backups}. Nothing changed.\n");
    exit(1);
}
$export = "{$backups}/{$siteId}-requalified-{$options['date']}-{$stamp}.json";
file_put_contents($export, json_encode($cleanup->rows($matches), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
chmod($export, 0600);
if ($db->isMysql()) {
    echo "\nThe events about to move are saved in {$export}.\nThis website uses MySQL: back it up with your usual tools before going on.\n";
} else {
    $copy = "{$backups}/{$siteId}-before-requalify-{$stamp}.db";
    if ($db->exec("VACUUM INTO '" . str_replace("'", "''", $copy) . "'") === false || !is_file($copy)) {
        fwrite(STDERR, "The backup failed. Nothing changed.\n");
        exit(1);
    }
    chmod($copy, 0600);
    echo "\nBackup of the database: {$copy}\nEvents about to move: {$export}\n";
}

echo "\nMove these " . count($matches) . " visits out of the analytics, to the blocked traffic? Type yes to confirm: ";
$answer = trim((string) fgets(STDIN));
if ($answer !== 'yes') {
    echo "Cancelled: nothing changed.\n";
    exit(1);
}
$moved = $cleanup->apply($matches);
echo "{$moved} visits moved to the blocked traffic (reason " . FingerprintCleanup::REASON . ").\n";
