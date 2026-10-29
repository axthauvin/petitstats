<?php

declare(strict_types=1);

namespace Petitstats\Http;

/**
 * URLs of the dashboard's scripts and stylesheets, versioned by the time each
 * file last changed. An update rewrites the files it replaces, so browsers
 * load the new ones instead of keeping outdated copies in their cache. This
 * also works in a development checkout, where each edit changes the URL.
 */
final class Asset
{
    /** `$path` is relative to the site root, such as "/dashboard/src/assets/js/app.js". */
    public static function url(string $path): string
    {
        $changed = @filemtime(dirname(__DIR__, 2) . $path);
        return $changed === false ? $path : $path . '?v=' . $changed;
    }
}
