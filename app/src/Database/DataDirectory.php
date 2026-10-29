<?php

declare(strict_types=1);

namespace Petitstats\Database;

use RuntimeException;

/** The folder where Petitstats keeps its data, outside the web root. */
final class DataDirectory
{
    /**
     * Uses PETITSTATS_DATA_DIR when set, otherwise a "petitstats-data" folder
     * next to the web root. Installs made before the project was renamed keep
     * their MINILYTICS_DATA_DIR variable and their "minilytics-data" folder.
     */
    public static function path(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $configured = getenv('PETITSTATS_DATA_DIR') ?: getenv('MINILYTICS_DATA_DIR');
        $dir = is_string($configured) && trim($configured) !== ''
            ? rtrim(trim($configured), '/\\')
            : self::defaultPath(dirname(__DIR__, 3));

        if ((is_dir($dir) || @mkdir($dir, 0770, true) || is_dir($dir)) && is_writable($dir)) {
            self::migrateLegacyData(dirname(__DIR__, 2) . '/data', $dir);

            return $resolved = $dir;
        }
        throw new RuntimeException('No writable data directory. Set PETITSTATS_DATA_DIR to a writable path outside the web root.');
    }

    /** "petitstats-data" in `$parent`, unless only the folder of an install made before the rename exists there. */
    public static function defaultPath(string $parent): string
    {
        $dir = $parent . '/petitstats-data';
        $renamed = $parent . '/minilytics-data';

        return !is_dir($dir) && is_dir($renamed) ? $renamed : $dir;
    }

    /**
     * Up to v1.0.0-beta the data lived in a "data" folder inside the web root.
     * Moves it to the data directory, but only when that one is still empty so
     * nothing is ever overwritten, then removes the files from the web root.
     */
    public static function migrateLegacyData(string $legacyDir, string $dir): void
    {
        if (!is_dir($legacyDir) || (glob($dir . '/*') ?: []) !== [] || (glob($dir . '/.tracking-secret') ?: []) !== []) {
            return;
        }

        $files = array_merge(glob($legacyDir . '/*.db') ?: [], [$legacyDir . '/sites.json', $legacyDir . '/database.json', $legacyDir . '/.tracking-secret']);
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            // Copy first so a failure never loses data; the original goes once the copy is in place.
            if (@copy($file, $dir . '/' . basename($file))) {
                @unlink($file);
            }
        }
    }
}
