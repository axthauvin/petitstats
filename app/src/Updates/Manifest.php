<?php

declare(strict_types=1);

namespace Petitstats\Updates;

use RuntimeException;

/**
 * The files of a release and their SHA-256, as listed in src/MANIFEST.
 *
 * scripts/build-archive.sh writes it in the `sha256sum` format. It tells the
 * updater which files belong to Petitstats, so it never touches anything else
 * in the folder (such as a landing page), and which files a release dropped.
 */
final class Manifest
{
    public const PATH = 'src/MANIFEST';

    /** @param array<string, string> $files SHA-256 by path, relative to the app folder */
    private function __construct(public readonly array $files) {}

    public static function parse(string $contents): self
    {
        $files = [];
        foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([a-f0-9]{64}) [ *](.+)$/', $line, $match) || !self::isSafePath($match[2])) {
                throw new RuntimeException('The release file list is not valid.');
            }
            $files[$match[2]] = $match[1];
        }
        if ($files === []) {
            throw new RuntimeException('The release file list is empty.');
        }
        return new self($files);
    }

    /** The manifest of the files in `$dir`, or null when it has none (a development checkout or an older release). */
    public static function fromDirectory(string $dir): ?self
    {
        $file = $dir . '/' . self::PATH;
        return is_file($file) ? self::parse((string) file_get_contents($file)) : null;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->files);
    }

    /** A relative path that stays inside the app folder. */
    public static function isSafePath(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, '\\') || str_contains($path, "\0") || str_contains($path, ':')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }
}
