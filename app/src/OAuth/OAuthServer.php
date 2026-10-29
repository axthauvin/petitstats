<?php

declare(strict_types=1);

namespace Petitstats\OAuth;

use Petitstats\Auth\AuthDatabase;
use Petitstats\Auth\BearerTokens;
use Petitstats\Http\Docs;
use SQLite3;

/**
 * OAuth 2.1 authorization server for the MCP endpoint, as the MCP
 * authorization specification describes it: assistants such as Claude or
 * ChatGPT register themselves (Dynamic Client Registration), send the user to
 * a consent screen, and receive a token bound to one account. Tokens only
 * read until the user lets the assistant edit funnels in the settings.
 *
 * Tokens are opaque and stored as SHA-256 hashes. They are only accepted by
 * mcp.php, which is both the protected resource and the token issuer.
 */
final class OAuthServer
{
    public const SCOPE = 'read';
    private const ACCESS_TOKEN_TTL = 3600;
    private const REFRESH_TOKEN_TTL = 90 * 86400;
    private const CODE_TTL = 300;
    private const UNUSED_CLIENT_TTL = 30 * 86400;
    private const ACCESS_PREFIX = 'mla_';
    private const REFRESH_PREFIX = 'mlr_';

    public static function baseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** The MCP endpoint: the resource the tokens grant access to, and their issuer. */
    public static function resource(): string
    {
        return self::baseUrl() . '/mcp.php';
    }

    /**
     * Both metadata documents are real PHP files under /.well-known/, at the
     * paths clients derive from the resource and issuer URLs, so they work on
     * any web server without rewrite rules.
     */
    public static function resourceMetadataUrl(): string
    {
        return self::baseUrl() . '/.well-known/oauth-protected-resource/mcp.php';
    }

    /** RFC 9728 protected resource metadata. */
    public static function protectedResourceMetadata(): array
    {
        return [
            'resource' => self::resource(),
            'authorization_servers' => [self::resource()],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Petitstats',
            'resource_documentation' => Docs::url('mcp'),
        ];
    }

    /** RFC 8414 authorization server metadata. */
    public static function authorizationServerMetadata(): array
    {
        $base = self::baseUrl();
        return [
            'issuer' => self::resource(),
            'authorization_endpoint' => $base . '/oauth/authorize.php',
            'token_endpoint' => $base . '/oauth/token.php',
            'registration_endpoint' => $base . '/oauth/register.php',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE, 'offline_access'],
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => Docs::url('mcp'),
        ];
    }

    /**
     * RFC 7591 dynamic client registration. Every client is public: it proves
     * itself with PKCE instead of a secret.
     */
    public static function registerClient(array $request): array
    {
        $uris = $request['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 10 || !array_is_list($uris)) {
            throw new OAuthException('invalid_redirect_uri', 'Provide between 1 and 10 redirect_uris.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || strlen($uri) > 2000 || !self::isAcceptableRedirectUri($uri)) {
                throw new OAuthException('invalid_redirect_uri', 'Redirect URIs must use HTTPS, a loopback address or an application scheme.');
            }
        }
        $name = is_string($request['client_name'] ?? null) ? trim(substr($request['client_name'], 0, 100)) : '';
        $name = $name !== '' ? $name : (string) parse_url($uris[0], PHP_URL_HOST);
        $clientId = 'mlc_' . bin2hex(random_bytes(16));
        $now = time();

        $db = AuthDatabase::connection();
        self::prune($db);
        $stmt = $db->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at) VALUES (:id, :name, :uris, :now)');
        $stmt->bindValue(':id', $clientId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':uris', json_encode($uris, JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
        $stmt->execute();

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => $now,
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /** @return array{client_id: string, client_name: string, redirect_uris: list<string>}|null */
    public static function client(string $clientId): ?array
    {
        if ($clientId === '' || !AuthDatabase::exists()) {
            return null;
        }
        $stmt = AuthDatabase::connection()->prepare('SELECT client_id, client_name, redirect_uris FROM oauth_clients WHERE client_id = :id');
        $stmt->bindValue(':id', $clientId, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return null;
        }
        return ['client_id' => (string) $row['client_id'], 'client_name' => (string) $row['client_name'], 'redirect_uris' => json_decode((string) $row['redirect_uris'], true) ?: []];
    }

    /**
     * Validates an authorization request. Errors about the client or its
     * redirect URI are shown to the user; the others are sent back to the client.
     *
     * @return array{client: array{client_id: string, client_name: string, redirect_uris: list<string>}, redirect_uri: string, state: ?string, code_challenge: string}
     */
    public static function authorizationRequest(array $params): array
    {
        $client = self::client((string) ($params['client_id'] ?? ''));
        if ($client === null) {
            throw new OAuthException('invalid_client', 'This application is not registered. Remove it from your assistant and add it again.');
        }
        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        if (!self::redirectUriMatches($client['redirect_uris'], $redirectUri)) {
            throw new OAuthException('invalid_request', 'The redirect address does not match the one this application registered.');
        }
        $state = isset($params['state']) && is_string($params['state']) ? $params['state'] : null;
        $fail = static fn(string $error, string $description) => new OAuthException($error, $description, 400, $redirectUri, $state);

        if (($params['response_type'] ?? '') !== 'code') {
            throw $fail('unsupported_response_type', 'Only the authorization code flow is supported.');
        }
        $challenge = (string) ($params['code_challenge'] ?? '');
        if (($params['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            throw $fail('invalid_request', 'PKCE with the S256 method is required.');
        }
        if (isset($params['resource']) && !self::isOwnResource((string) $params['resource'])) {
            throw $fail('invalid_target', 'Tokens can only be issued for ' . self::resource() . '.');
        }
        return ['client' => $client, 'redirect_uri' => $redirectUri, 'state' => $state, 'code_challenge' => $challenge];
    }

    /** Issues a single-use authorization code for an approved request. */
    public static function createAuthorizationCode(array $request, int $userId): string
    {
        $code = bin2hex(random_bytes(32));
        $stmt = AuthDatabase::connection()->prepare('INSERT INTO oauth_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, expires_at) VALUES (:hash, :client, :user, :redirect, :challenge, :expires)');
        $stmt->bindValue(':hash', hash('sha256', $code), SQLITE3_TEXT);
        $stmt->bindValue(':client', $request['client']['client_id'], SQLITE3_TEXT);
        $stmt->bindValue(':user', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':redirect', $request['redirect_uri'], SQLITE3_TEXT);
        $stmt->bindValue(':challenge', $request['code_challenge'], SQLITE3_TEXT);
        $stmt->bindValue(':expires', time() + self::CODE_TTL, SQLITE3_INTEGER);
        $stmt->execute();
        return $code;
    }

    /** The client's redirect URI with the authorization response (RFC 9207 `iss` included). */
    public static function redirectWith(string $redirectUri, array $params): string
    {
        $params = array_filter($params + ['iss' => self::resource()], static fn($value): bool => $value !== null);
        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * Token endpoint: exchanges an authorization code or a refresh token.
     * Clients are public, so they send their `client_id` in the request body.
     */
    public static function token(array $request): array
    {
        $client = self::client((string) ($request['client_id'] ?? ''));
        if ($client === null) {
            throw new OAuthException('invalid_client', 'Unknown client.', 401);
        }
        if (isset($request['resource']) && !self::isOwnResource((string) $request['resource'])) {
            throw new OAuthException('invalid_target', 'Tokens can only be issued for ' . self::resource() . '.');
        }
        return match ($request['grant_type'] ?? '') {
            'authorization_code' => self::exchangeCode($client['client_id'], $request),
            'refresh_token' => self::refresh($client['client_id'], (string) ($request['refresh_token'] ?? '')),
            default => throw new OAuthException('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    /**
     * Returns the account behind a valid, unexpired access token.
     *
     * @return array{id: int, email: string, can_write: bool}|null
     */
    public static function authenticate(string $token): ?array
    {
        if (!str_starts_with($token, self::ACCESS_PREFIX)) {
            return null;
        }
        return BearerTokens::owner('oauth_tokens', 'access_hash', $token, ' AND t.access_expires_at > ' . time());
    }

    /**
     * Applications the user connected through OAuth.
     *
     * @return list<array{client_id: string, name: string, connected_at: string, last_used_at: ?string, can_write: bool}>
     */
    public static function connectedApps(int $userId): array
    {
        $stmt = AuthDatabase::connection()->prepare('SELECT c.client_id, c.client_name, MIN(t.created_at) AS connected_at, MAX(t.last_used_at) AS last_used_at, MAX(t.can_write) AS can_write FROM oauth_tokens t JOIN oauth_clients c ON c.client_id = t.client_id WHERE t.user_id = :user AND t.refresh_expires_at > :now GROUP BY c.client_id, c.client_name ORDER BY connected_at DESC');
        $stmt->bindValue(':user', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
        $result = $stmt->execute();
        $apps = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $apps[] = ['client_id' => (string) $row['client_id'], 'name' => (string) $row['client_name'], 'connected_at' => (string) BearerTokens::date($row['connected_at']), 'last_used_at' => BearerTokens::date($row['last_used_at']), 'can_write' => (bool) $row['can_write']];
        }
        return $apps;
    }

    /** Lets an application edit the user's funnels, goals and journeys, or not. Returns false when it is not connected. */
    public static function allowWrite(int $userId, string $clientId, bool $allowed): bool
    {
        $db = AuthDatabase::connection();
        $stmt = $db->prepare('UPDATE oauth_tokens SET can_write = :allowed WHERE user_id = :user AND client_id = :client');
        $stmt->bindValue(':allowed', (int) $allowed, SQLITE3_INTEGER);
        $stmt->bindValue(':user', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':client', $clientId, SQLITE3_TEXT);
        $stmt->execute();
        return $db->changes() > 0;
    }

    /** Revokes every token the user granted to an application. */
    public static function disconnect(int $userId, string $clientId): bool
    {
        $db = AuthDatabase::connection();
        $stmt = $db->prepare('DELETE FROM oauth_tokens WHERE user_id = :user AND client_id = :client');
        $stmt->bindValue(':user', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':client', $clientId, SQLITE3_TEXT);
        $stmt->execute();
        return $db->changes() > 0;
    }

    public static function revokeAllForUser(int $userId): void
    {
        $db = AuthDatabase::connection();
        foreach (['oauth_tokens', 'oauth_codes'] as $table) {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE user_id = :user");
            $stmt->bindValue(':user', $userId, SQLITE3_INTEGER);
            $stmt->execute();
        }
    }

    /**
     * True when the code goes to a program on the user's computer: a loopback
     * address or an application scheme such as cursor://. Any local program
     * can claim these, and any client can register one under any name.
     */
    public static function redirectsToLocalApp(string $uri): bool
    {
        return strtolower((string) parse_url($uri, PHP_URL_SCHEME)) !== 'https';
    }

    /** True for loopback redirect URIs (http://localhost, 127.0.0.1 or [::1]). */
    public static function isLoopbackRedirect(string $uri): bool
    {
        return strtolower((string) parse_url($uri, PHP_URL_SCHEME)) === 'http' && self::isLoopback((string) parse_url($uri, PHP_URL_HOST));
    }

    private static function exchangeCode(string $clientId, array $request): array
    {
        $db = AuthDatabase::connection();
        $hash = hash('sha256', (string) ($request['code'] ?? ''));
        $stmt = $db->prepare('SELECT client_id, user_id, redirect_uri, code_challenge, expires_at FROM oauth_codes WHERE code_hash = :hash');
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $code = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        // Codes are single-use, whatever the outcome of this exchange.
        $delete = $db->prepare('DELETE FROM oauth_codes WHERE code_hash = :hash');
        $delete->bindValue(':hash', $hash, SQLITE3_TEXT);
        $delete->execute();

        if (!$code || $code['client_id'] !== $clientId || (int) $code['expires_at'] < time()) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid or expired.');
        }
        if (($request['redirect_uri'] ?? null) !== $code['redirect_uri']) {
            throw new OAuthException('invalid_grant', 'The redirect URI does not match the authorization request.');
        }
        $verifier = (string) ($request['code_verifier'] ?? '');
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals((string) $code['code_challenge'], $expected)) {
            throw new OAuthException('invalid_grant', 'The PKCE code verifier is invalid.');
        }

        [$access, $refresh] = self::newTokens();
        $now = time();
        // Signing in again keeps the permission the user gave this connected application in the settings.
        $stmt = $db->prepare('INSERT INTO oauth_tokens (client_id, user_id, access_hash, access_expires_at, refresh_hash, refresh_expires_at, created_at, can_write) VALUES (:client, :user, :access, :access_expires, :refresh, :refresh_expires, :now, (SELECT COALESCE(MAX(can_write), 0) FROM oauth_tokens WHERE client_id = :client AND user_id = :user AND refresh_expires_at > :now))');
        $stmt->bindValue(':client', $clientId, SQLITE3_TEXT);
        $stmt->bindValue(':user', (int) $code['user_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':access', hash('sha256', $access), SQLITE3_TEXT);
        $stmt->bindValue(':access_expires', $now + self::ACCESS_TOKEN_TTL, SQLITE3_INTEGER);
        $stmt->bindValue(':refresh', hash('sha256', $refresh), SQLITE3_TEXT);
        $stmt->bindValue(':refresh_expires', $now + self::REFRESH_TOKEN_TTL, SQLITE3_INTEGER);
        $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
        $stmt->execute();
        return self::tokenResponse($access, $refresh);
    }

    /** Rotates a refresh token: the old one stops working as soon as the new pair is issued. */
    private static function refresh(string $clientId, string $refreshToken): array
    {
        $db = AuthDatabase::connection();
        $stmt = $db->prepare('SELECT id, refresh_expires_at FROM oauth_tokens WHERE refresh_hash = :hash AND client_id = :client');
        $stmt->bindValue(':hash', hash('sha256', $refreshToken), SQLITE3_TEXT);
        $stmt->bindValue(':client', $clientId, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row || (int) $row['refresh_expires_at'] < time()) {
            throw new OAuthException('invalid_grant', 'The refresh token is invalid or expired. Sign in again.');
        }
        [$access, $refresh] = self::newTokens();
        $now = time();
        $stmt = $db->prepare('UPDATE oauth_tokens SET access_hash = :access, access_expires_at = :access_expires, refresh_hash = :refresh, refresh_expires_at = :refresh_expires WHERE id = :id');
        $stmt->bindValue(':access', hash('sha256', $access), SQLITE3_TEXT);
        $stmt->bindValue(':access_expires', $now + self::ACCESS_TOKEN_TTL, SQLITE3_INTEGER);
        $stmt->bindValue(':refresh', hash('sha256', $refresh), SQLITE3_TEXT);
        $stmt->bindValue(':refresh_expires', $now + self::REFRESH_TOKEN_TTL, SQLITE3_INTEGER);
        $stmt->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
        $stmt->execute();
        return self::tokenResponse($access, $refresh);
    }

    /** @return array{0: string, 1: string} */
    private static function newTokens(): array
    {
        return [self::ACCESS_PREFIX . bin2hex(random_bytes(32)), self::REFRESH_PREFIX . bin2hex(random_bytes(32))];
    }

    private static function tokenResponse(string $access, string $refresh): array
    {
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TOKEN_TTL, 'refresh_token' => $refresh, 'scope' => self::SCOPE];
    }

    private static function isOwnResource(string $resource): bool
    {
        return strtolower(rtrim($resource, '/')) === strtolower(self::resource());
    }

    private static function isAcceptableRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!$parts || empty($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return !empty($parts['host']);
        }
        if ($scheme === 'http') {
            return self::isLoopback($parts['host'] ?? '');
        }
        // Desktop apps such as Cursor receive the code on their own URI scheme (RFC 8252).
        return !in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'about', 'blob'], true);
    }

    /**
     * Exact match, except for loopback redirects: native apps such as Claude
     * Code listen on a random port (RFC 8252 section 7.3), and may register
     * `localhost` but redirect to `127.0.0.1`, or the other way around.
     */
    private static function redirectUriMatches(array $registered, string $uri): bool
    {
        if (in_array($uri, $registered, true)) {
            return true;
        }
        if (!self::isLoopbackRedirect($uri)) {
            return false;
        }
        $loopback = static fn(string $value): string => (string) preg_replace('#^http://(?:\[[^\]]+\]|[^/:?]+)(?::\d+)?#i', 'http://loopback', $value);
        foreach ($registered as $candidate) {
            if (is_string($candidate) && self::isLoopbackRedirect($candidate) && $loopback($candidate) === $loopback($uri)) {
                return true;
            }
        }
        return false;
    }

    private static function isLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    /** Removes expired codes and tokens, and clients that were registered but never used. */
    private static function prune(SQLite3 $db): void
    {
        $now = time();
        $db->exec("DELETE FROM oauth_codes WHERE expires_at < {$now}");
        $db->exec("DELETE FROM oauth_tokens WHERE refresh_expires_at < {$now}");
        $db->exec('DELETE FROM oauth_clients WHERE created_at < ' . ($now - self::UNUSED_CLIENT_TTL) . ' AND client_id NOT IN (SELECT client_id FROM oauth_tokens)');
    }
}
