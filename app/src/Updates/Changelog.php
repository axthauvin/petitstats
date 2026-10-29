<?php

declare(strict_types=1);

namespace Petitstats\Updates;

use Closure;
use Petitstats\Database\DataDirectory;
use Throwable;

/**
 * What changed in each release, read from the notes GitHub generates for it.
 *
 * Each "* <title> by @<author> in <pull request URL>" line of the "What's
 * Changed" section becomes one change, sorted by its conventional commit
 * prefix (feat, fix, …). Like UpdateCheck, GitHub is asked at most every 12
 * hours, from the server.
 */
final class Changelog
{
    private const RELEASES_API = 'https://api.github.com/repos/axthauvin/petitstats/releases?per_page=30';
    private const CACHE_SECONDS = 12 * 3600;
    private const RETRY_SECONDS = 3600;
    private const MAX_RELEASES = 10;

    /** @param Closure(): ?list<mixed> $fetchReleases The releases as the GitHub API returns them, newest first. */
    public function __construct(
        private readonly string $cacheFile,
        private readonly Closure $fetchReleases,
    ) {}

    public static function create(): self
    {
        return new self(DataDirectory::path() . '/changelog.json', self::fetchFromGitHub(...));
    }

    /**
     * The releases newer than `$after` up to and including `$upTo`, newest
     * first. Null leaves that end open. Null as a whole when GitHub cannot be reached.
     *
     * @return list<array{version: string, url: string, published_at: string, changes: list<array{type: string, text: string, url: ?string}>}>|null
     */
    public function between(?string $after, ?string $upTo, bool $refresh = false): ?array
    {
        $releases = $this->releases($refresh);
        if ($releases === null) {
            return null;
        }
        $selected = array_filter($releases, static fn(array $release): bool
            => ($after === null || UpdateCheck::isNewer($release['version'], $after))
            && ($upTo === null || !UpdateCheck::isNewer($release['version'], $upTo)));
        return array_slice(array_values($selected), 0, self::MAX_RELEASES);
    }

    /**
     * The changes listed in the notes of one release.
     *
     * @return list<array{type: string, text: string, url: ?string}>
     */
    public static function parseNotes(string $notes): array
    {
        $section = preg_split('/^##\s+What\'s Changed\s*$/mi', $notes);
        if (!is_array($section) || count($section) < 2) {
            return [];
        }
        $changes = [];
        foreach (preg_split('/\R/', $section[1]) ?: [] as $line) {
            if (preg_match('/^##/', $line)) {
                break;
            }
            if (!preg_match('/^\s*[*-]\s+(.+?)(?:\s+by\s+@\S+)?(?:\s+in\s+(https:\/\/\S+))?\s*$/', $line, $match)) {
                continue;
            }
            $changes[] = self::change($match[1], $match[2] ?? null);
        }
        return $changes;
    }

    /** @return array{type: string, text: string, url: ?string} */
    private static function change(string $title, ?string $url): array
    {
        $type = 'improved';
        if (preg_match('/^(\w+)(?:\([^)]*\))?!?:\s*(.+)$/', $title, $match)) {
            $type = match (strtolower($match[1])) {
                'feat' => 'new',
                'fix' => 'fixed',
                default => 'improved',
            };
            $title = $match[2];
        }
        // "(closes #108)" and "(#109)" point at GitHub; the link to the pull request says enough.
        $title = trim((string) preg_replace('/\s*\((?:closes?|fixes?|resolves?)?\s*#\d+\)/i', '', $title));
        return ['type' => $type, 'text' => ucfirst($title), 'url' => $url !== '' ? $url : null];
    }

    /** @return list<array{version: string, url: string, published_at: string, changes: list<array{type: string, text: string, url: ?string}>}>|null */
    private function releases(bool $refresh): ?array
    {
        $cache = is_file($this->cacheFile) ? json_decode((string) file_get_contents($this->cacheFile), true) : null;
        if (!$refresh && is_array($cache) && ($cache['checked_at'] ?? 0) > time() - (empty($cache['releases']) ? self::RETRY_SECONDS : self::CACHE_SECONDS)) {
            return $cache['releases'] ?: null;
        }
        try {
            $raw = ($this->fetchReleases)();
        } catch (Throwable) {
            $raw = null;
        }
        $releases = $raw === null ? null : self::normalize($raw);
        @file_put_contents($this->cacheFile, json_encode(['checked_at' => time(), 'releases' => $releases], JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $releases;
    }

    /**
     * @param list<mixed> $raw
     * @return list<array{version: string, url: string, published_at: string, changes: list<array{type: string, text: string, url: ?string}>}>
     */
    private static function normalize(array $raw): array
    {
        $releases = [];
        foreach ($raw as $release) {
            if (!is_array($release) || !is_string($release['tag_name'] ?? null) || !empty($release['draft'])) {
                continue;
            }
            $releases[] = [
                'version' => $release['tag_name'],
                'url' => is_string($release['html_url'] ?? null) ? $release['html_url'] : UpdateCheck::RELEASES_URL,
                'published_at' => (string) ($release['published_at'] ?? ''),
                'changes' => self::parseNotes((string) ($release['body'] ?? '')),
            ];
        }
        return $releases;
    }

    /** @return list<mixed>|null */
    private static function fetchFromGitHub(): ?array
    {
        $context = stream_context_create(['http' => [
            'header' => "User-Agent: Petitstats\r\nAccept: application/vnd.github+json",
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(self::RELEASES_API, false, $context);
        $releases = is_string($body) ? json_decode($body, true) : null;
        return is_array($releases) && array_is_list($releases) ? $releases : null;
    }
}
