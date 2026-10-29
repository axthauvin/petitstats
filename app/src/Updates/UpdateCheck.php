<?php

declare(strict_types=1);

namespace Petitstats\Updates;

use Closure;
use Petitstats\Database\DataDirectory;
use Throwable;

/**
 * The installed Petitstats version and the latest one published on GitHub.
 *
 * Release archives carry their tag in src/VERSION (see scripts/build-archive.sh);
 * a Git checkout has none and is reported as a development version. GitHub is
 * asked at most every 12 hours, from the server, only when an administrator
 * opens the dashboard.
 */
final class UpdateCheck
{
    public const RELEASES_URL = 'https://github.com/axthauvin/petitstats/releases';
    private const LATEST_RELEASE_API = 'https://api.github.com/repos/axthauvin/petitstats/releases/latest';
    private const CACHE_SECONDS = 12 * 3600;
    private const RETRY_SECONDS = 3600;

    /** @param Closure(): ?array{version: string, url: string, published_at: string} $fetchLatest */
    public function __construct(
        private readonly string $versionFile,
        private readonly string $cacheFile,
        private readonly Closure $fetchLatest,
    ) {}

    public static function create(): self
    {
        return new self(dirname(__DIR__) . '/VERSION', DataDirectory::path() . '/update-check.json', self::fetchFromGitHub(...));
    }

    /** The installed version, such as "v1.1.2-beta", or null for a development checkout. */
    public function installed(): ?string
    {
        $version = is_file($this->versionFile) ? trim((string) file_get_contents($this->versionFile)) : '';
        return $version !== '' ? $version : null;
    }

    /**
     * The latest published release, or null when GitHub cannot be reached.
     *
     * @return array{version: string, url: string, published_at: string}|null
     */
    public function latest(bool $refresh = false): ?array
    {
        $cache = is_file($this->cacheFile) ? json_decode((string) file_get_contents($this->cacheFile), true) : null;
        if (!$refresh && is_array($cache) && ($cache['checked_at'] ?? 0) > time() - (empty($cache['release']) ? self::RETRY_SECONDS : self::CACHE_SECONDS)) {
            return $cache['release'] ?: null;
        }
        try {
            $release = ($this->fetchLatest)();
        } catch (Throwable) {
            $release = null;
        }
        @file_put_contents($this->cacheFile, json_encode(['checked_at' => time(), 'release' => $release], JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $release;
    }

    /** Whether `$latest` is a newer version than `$installed`. A development checkout is never outdated. */
    public static function isNewer(?string $latest, ?string $installed): bool
    {
        if ($latest === null || $installed === null) {
            return false;
        }
        return version_compare(ltrim($latest, 'vV'), ltrim($installed, 'vV'), '>');
    }

    /** @return array{version: string, url: string, published_at: string}|null */
    private static function fetchFromGitHub(): ?array
    {
        $context = stream_context_create(['http' => [
            'header' => "User-Agent: Petitstats\r\nAccept: application/vnd.github+json",
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(self::LATEST_RELEASE_API, false, $context);
        $release = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($release) || !is_string($release['tag_name'] ?? null)) {
            return null;
        }
        return [
            'version' => $release['tag_name'],
            'url' => is_string($release['html_url'] ?? null) ? $release['html_url'] : self::RELEASES_URL,
            'published_at' => (string) ($release['published_at'] ?? ''),
        ];
    }
}
