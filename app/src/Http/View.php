<?php

declare(strict_types=1);

namespace Petitstats\Http;

use Throwable;

/**
 * Renders the PHP templates of the templates/ folder. A page handles its
 * request first, then hands the values to display to a template, which only
 * outputs HTML.
 */
final class View
{
    /**
     * `$template` is a path in templates/ without its extension, such as
     * "auth/login". With `$layout`, the rendered template is passed to that
     * layout as `$content`, along with the same `$data`.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $html = self::capture(self::path($template), $data);
        return $layout === null ? $html : self::capture(self::path($layout), ['content' => $html] + $data);
    }

    /** Absolute path of a template, for templates that include another one. */
    public static function path(string $template): string
    {
        return dirname(__DIR__, 2) . '/templates/' . $template . '.php';
    }

    /** @param array<string, mixed> $data */
    private static function capture(string $file, array $data): string
    {
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data);
                require $__file;
            })($file, $data);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
