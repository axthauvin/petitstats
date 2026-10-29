<?php

declare(strict_types=1);

/*
 * Runs the tests against a throwaway data directory, so they never touch a
 * real install. It holds a single website, "test_site", tracked from
 * example.com with the key "test-key".
 */

require __DIR__ . '/../../vendor/autoload.php';

$dataDir = sys_get_temp_dir() . '/petitstats-phpunit-' . bin2hex(random_bytes(4));
mkdir($dataDir, 0770, true);
file_put_contents($dataDir . '/sites.json', json_encode([[
    'id' => 'test_site',
    'name' => 'Test site',
    'domain' => 'example.com',
    'write_key' => 'test-key',
    'allowed_domains' => ['example.com'],
    'internal_ips' => ['10.0.0.99'],
    'retention_days' => 395,
]]));
putenv('PETITSTATS_DATA_DIR=' . $dataDir);

register_shutdown_function(static function () use ($dataDir): void {
    // Close the cached SQLite connections first: Windows cannot delete open files.
    Petitstats\Database\Connections::closeAll();
    Petitstats\Auth\AuthDatabase::close();
    (new ReflectionProperty(Petitstats\Auth\Auth::class, 'engine'))->setValue(null, null);
    gc_collect_cycles();

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dataDir);
});
