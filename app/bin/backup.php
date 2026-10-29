<?php

/**
 * Run from a scheduler: php bin/backup.php --destination path/to/backups
 * Prefer a destination on another disk or machine than the data directory.
 */
declare(strict_types=1);

use Petitstats\Database\DataDirectory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dataDir = DataDirectory::path();
$args = getopt('', ['destination:']);
$destination = $args['destination'] ?? ($dataDir . '/backups');
if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create backup directory.');
}
foreach (glob($dataDir . '/*.db') ?: [] as $source) {
    $db = new SQLite3($source);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA wal_checkpoint(FULL)');
    $db->close();
    $target = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR . basename($source, '.db') . '-' . gmdate('Ymd-His') . '.db';
    if (!copy($source, $target)) {
        throw new RuntimeException('Backup failed for ' . basename($source));
    } chmod($target, 0600);
    echo "Saved $target\n";
}
