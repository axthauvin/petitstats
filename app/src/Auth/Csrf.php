<?php

declare(strict_types=1);

namespace Petitstats\Auth;

/**
 * Per-session token proving that a state-changing request comes from a
 * Petitstats page and not from a form or script on another site.
 *
 * Forms send it as the hidden "csrf" field, the dashboard API client as the
 * X-CSRF-Token header.
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        Auth::startSession();
        if (!is_string($_SESSION[self::SESSION_KEY] ?? null)) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Hidden form field carrying the token. */
    public static function field(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::token() . '">';
    }

    /** True for safe methods, or when the request carries the session's token. */
    public static function isValid(): bool
    {
        if (in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? null;
        return is_string($expected) && is_string($sent) && hash_equals($expected, $sent);
    }

    public static function requireValid(): void
    {
        if (!self::isValid()) {
            http_response_code(403);
            // Lets the dashboard tell a stale page apart from a missing permission.
            header('X-Petitstats-Error: csrf');
            Auth::jsonError('Invalid or missing CSRF token. Reload the page and try again.');
        }
    }
}
