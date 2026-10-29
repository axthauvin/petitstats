<?php

declare(strict_types=1);

namespace Petitstats\Auth;

use Delight\Auth\Auth as AuthEngine;
use Delight\Auth\Role;
use Petitstats\Sites\DemoTraffic;
use Petitstats\Sites\SiteRegistry;
use PDO;
use Throwable;

/**
 * Authentication helpers for the private dashboard.
 *
 * Accounts, sessions and login throttling are handled by delight-im/auth;
 * this class keeps the dashboard's small API ("admin" / "member" roles).
 */
final class Auth
{
    private static ?AuthEngine $engine = null;

    public static function hasDatabase(): bool
    {
        return AuthDatabase::exists();
    }

    public static function startSession(): void
    {
        // Before onboarding there is no auth database to attach the engine to.
        if (!self::hasDatabase()) {
            self::configureSession();
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            return;
        }
        self::engine();
    }

    public static function engine(): AuthEngine
    {
        if (self::$engine === null) {
            self::configureSession();
            // Creates the database and its tables before the engine uses them.
            AuthDatabase::connection();
            $pdo = new PDO('sqlite:' . AuthDatabase::path());
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout = 5000;');
            // Resync on every request so role changes and deleted accounts apply immediately.
            self::$engine = new AuthEngine($pdo, null, null, null, 0);
        }
        return self::$engine;
    }

    private static function configureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('petitstats_session');
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
        }
    }

    public static function isAuthenticated(): bool
    {
        return self::hasDatabase() && self::engine()->isLoggedIn();
    }
    public static function user(): ?array
    {
        if (!self::isAuthenticated()) {
            return null;
        }
        $auth = self::engine();
        return [
            'id' => (int) $auth->getUserId(),
            'email' => (string) $auth->getEmail(),
            'role' => $auth->hasRole(Role::ADMIN) ? 'admin' : 'member',
        ];
    }
    public static function requireLogin(): void
    {
        if (!self::hasDatabase()) {
            http_response_code(409);
            self::jsonError('Onboarding is required.');
        }
        if (!self::user()) {
            http_response_code(401);
            self::jsonError('Authentication required.');
        }
        // Every state-changing dashboard API call goes through here.
        Csrf::requireValid();
    }
    /**
     * Read access to a website's analytics: members see every site, anonymous
     * visitors only the sites flagged "is_public" (live demo).
     */
    public static function requireSiteAccess(?string $siteId): void
    {
        if (!self::hasDatabase()) {
            http_response_code(409);
            self::jsonError('Onboarding is required.');
        }
        if (!self::user() && !SiteRegistry::isPublic((string) $siteId)) {
            http_response_code(401);
            self::jsonError('Authentication required.');
        }
        // The demo website fills itself in as it is read, so it never shows stale data.
        try {
            DemoTraffic::refresh((string) $siteId);
        } catch (Throwable $e) {
            error_log('Petitstats demo refresh failed: ' . $e->getMessage());
        }
    }
    public static function isGuest(): bool
    {
        return self::user() === null;
    }
    public static function requireAdmin(): array
    {
        self::requireLogin();
        $user = self::user();
        if (($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            self::jsonError('Administrator access required.');
        }
        return $user;
    }
    public static function jsonError(string $message): never
    {
        echo json_encode(['error' => $message]);
        exit;
    }
    /** Creates a verified account and returns its ID. */
    public static function createUser(string $email, string $password, string $role): int
    {
        $admin = self::engine()->admin();
        $id = (int) $admin->createUser($email, $password);
        if ($role === 'admin') {
            $admin->addRoleForUserById($id, Role::ADMIN);
        }
        return $id;
    }
    public static function loginById(int $id): void
    {
        self::engine()->admin()->logInAsUserById($id);
    }
    public static function logout(): void
    {
        if (self::hasDatabase()) {
            self::engine()->logOut();
            self::engine()->destroySession();
            return;
        }
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }
    public static function validEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    public static function passwordError(string $password): ?string
    {
        if (strlen($password) < 10) {
            return 'Your password must contain at least 10 characters.';
        }
        if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^a-zA-Z\d]/', $password)) {
            return 'Use uppercase, lowercase, a number, and a symbol.';
        }
        return null;
    }
}
