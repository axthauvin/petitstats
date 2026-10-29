<?php

declare(strict_types=1);

namespace Petitstats\Updates;

use RuntimeException;

/**
 * Extracts a release archive (.tar.gz), with nothing but Zlib.
 *
 * PharData cannot extract the "./" entries GNU tar writes, which every release
 * archive has. This reader only accepts regular files and folders, with paths
 * that stay inside the target folder. It understands the long paths of GNU tar
 * ("L" entries) and of POSIX tar ("x" entries and the ustar prefix).
 */
final class TarArchive
{
    private const BLOCK = 512;

    public static function extract(string $archive, string $dir): void
    {
        $gz = @gzopen($archive, 'rb');
        if ($gz === false) {
            throw new RuntimeException('The archive cannot be opened.');
        }
        try {
            $longPath = null;
            while (($header = self::read($gz, self::BLOCK, true)) !== '' && trim($header, "\0") !== '') {
                /** @var array{name: string, size: string, checksum: string, type: string, magic: string, prefix: string} $fields */
                $fields = unpack('a100name/a24ids/a12size/a12mtime/a8checksum/a1type/a100link/a6magic/a2version/a64owners/a16device/a155prefix', $header);
                if ((int) octdec(trim($fields['checksum'], " \0")) !== array_sum((array) unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8)))) {
                    throw new RuntimeException('The archive is damaged.');
                }
                $size = (int) octdec(trim($fields['size'], " \0"));
                $content = $size > 0 ? substr(self::read($gz, (int) ceil($size / self::BLOCK) * self::BLOCK), 0, $size) : '';

                $path = $longPath ?? rtrim($fields['name'], "\0");
                if ($longPath === null && $fields['magic'] === "ustar\0" && trim($fields['prefix'], "\0") !== '') {
                    $path = rtrim($fields['prefix'], "\0") . '/' . $path;
                }
                $longPath = null;

                switch ($fields['type']) {
                    case 'L':
                        $longPath = rtrim($content, "\0");
                        continue 2;
                    case 'x':
                        $longPath = preg_match('/(?:^|\n)\d+ path=([^\n]*)/', $content, $match) ? $match[1] : null;
                        continue 2;
                    case 'g':
                        continue 2;
                }

                $path = rtrim(preg_replace('#^(\./)+#', '', $path) ?? '', '/');
                if ($path === '' || $path === '.') {
                    continue;
                }
                if (!Manifest::isSafePath($path)) {
                    throw new RuntimeException(sprintf('The archive contains an unsafe path, %s.', $path));
                }
                $target = $dir . '/' . $path;
                if ($fields['type'] === '5') {
                    if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                        throw new RuntimeException(sprintf('Could not create %s.', $path));
                    }
                    continue;
                }
                if ($fields['type'] !== '0' && $fields['type'] !== "\0") {
                    throw new RuntimeException(sprintf('The archive contains %s, which is not a regular file.', $path));
                }
                if ((!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) || @file_put_contents($target, $content) !== $size) {
                    throw new RuntimeException(sprintf('Could not extract %s.', $path));
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    /**
     * @param resource $gz
     * @return string `$length` bytes, or an empty string at the very end of the archive when `$endAllowed`
     */
    private static function read($gz, int $length, bool $endAllowed = false): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = gzread($gz, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                if ($endAllowed && $data === '') {
                    return '';
                }
                throw new RuntimeException('The archive is incomplete.');
            }
            $data .= $chunk;
        }
        return $data;
    }
}
