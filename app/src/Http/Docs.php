<?php

declare(strict_types=1);

namespace Petitstats\Http;

/** Links to the documentation website, built from docs/ by scripts/build-docs.php. */
final class Docs
{
    public const BASE_URL = 'https://minilytics.axthauvin.fr/docs/';

    /** Docs::url('operations', 'large-imports') → https://minilytics.axthauvin.fr/docs/operations/#large-imports */
    public static function url(string $page = '', string $section = ''): string
    {
        $page = trim($page, '/');
        return self::BASE_URL . ($page === '' ? '' : $page . '/') . ($section === '' ? '' : '#' . $section);
    }
}
