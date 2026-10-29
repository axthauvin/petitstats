<?php

declare(strict_types=1);

namespace Petitstats\Geo;

use Petitstats\Database\DataDirectory;
use RuntimeException;
use Throwable;

/**
 * Local, no-quota country/region/city lookup backed by MaxMind GeoLite2 City,
 * downloaded from the same public mirror Umami uses so no license key is needed.
 * The dashboard credits MaxMind as the GeoLite2 license requires.
 */

final class GeoLocation
{
    private const DATABASE_URL = 'https://raw.githubusercontent.com/GitSquared/node-geolite2-redist/master/redist/GeoLite2-City.tar.gz';
    // GeoLite2 is published twice a week and its license asks to stay up to date.
    private const MAX_AGE_SECONDS = 7 * 86400;
    private const LEGACY_DATABASE = 'dbip-city-lite.mmdb';
    private const RETRY_DELAY_SECONDS = 3600;
    private static ?\MaxMind\Db\Reader $reader = null;

    public static function lookup(string $ip): ?array
    {
        if (!self::ensureDatabase()) {
            return null;
        }
        try {
            self::$reader ??= new \MaxMind\Db\Reader(self::databasePath());
            $record = self::$reader->get($ip);
            $code = strtoupper((string) ($record['country']['iso_code'] ?? ''));
            $country = (string) ($record['country']['names']['en'] ?? '');
            if (!preg_match('/^[A-Z]{2}$/', $code) || $country === '') {
                return null;
            }
            return [
                'country' => $country,
                'country_code' => $code,
                'region' => (string) ($record['subdivisions'][0]['names']['en'] ?? ''),
                'city' => (string) ($record['city']['names']['en'] ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private static function directory(): string
    {
        return DataDirectory::path() . '/geo';
    }

    private static function databasePath(): string
    {
        return self::directory() . '/GeoLite2-City.mmdb';
    }

    private static function ensureDatabase(): bool
    {
        $database = self::databasePath();
        if (is_file($database) && filesize($database) > 1024 && filemtime($database) >= time() - self::MAX_AGE_SECONDS) {
            return true;
        }
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return is_file($database);
        }
        $failure = $directory . '/last-update-failure';
        if (is_file($failure) && filemtime($failure) >= time() - self::RETRY_DELAY_SECONDS) {
            return is_file($database);
        }
        $lock = @fopen($directory . '/update.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return is_file($database);
        }
        try {
            if (is_file($database) && filesize($database) > 1024 && filemtime($database) >= time() - self::MAX_AGE_SECONDS) {
                return true;
            }
            // The compressed GeoLite2 City archive is about 30 MB. Stream both
            // download and extraction so shared hosts never need hundreds
            // of megabytes of PHP memory during the initial installation.
            @set_time_limit(180);
            $compressed = $database . '.download';
            $temporary = $database . '.new';
            if (!self::downloadTo($compressed) || !self::extractDatabaseTo($compressed, $temporary) || filesize($temporary) < 1024 || !@rename($temporary, $database)) {
                throw new RuntimeException('Unable to install GeoLite2 City database.');
            }
            @unlink($failure);
            @unlink($directory . '/' . self::LEGACY_DATABASE);
            return true;
        } catch (Throwable $error) {
            @file_put_contents($failure, gmdate('c') . ' ' . $error->getMessage() . PHP_EOL, LOCK_EX);
            return is_file($database);
        } finally {
            @unlink($database . '.download');
            @unlink($database . '.new');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function downloadTo(string $destination): bool
    {
        $file = @fopen($destination, 'wb');
        if ($file === false) {
            return false;
        }
        if (function_exists('curl_init')) {
            $curl = curl_init(self::DATABASE_URL);
            curl_setopt_array($curl, [CURLOPT_FILE => $file, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Accept: application/gzip']]);
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            fclose($file);
            return $ok === true && $status === 200;
        }
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            fclose($file);
            return false;
        }
        $source = @fopen(self::DATABASE_URL, 'rb', false, stream_context_create(['http' => ['timeout' => 120]]));
        if ($source === false) {
            fclose($file);
            return false;
        }
        $copied = stream_copy_to_stream($source, $file);
        fclose($source);
        fclose($file);
        return $copied !== false;
    }

    /** Streams the .mmdb file out of the .tar.gz archive without unpacking the rest. */
    private static function extractDatabaseTo(string $archive, string $destination): bool
    {
        $input = @gzopen($archive, 'rb');
        if ($input === false) {
            return false;
        }
        try {
            while (true) {
                $header = gzread($input, 512);
                if ($header === false || strlen($header) < 512 || trim($header, "\0") === '') {
                    return false;
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
                $padded = (int) ceil($size / 512) * 512;
                if (in_array($header[156], ['0', "\0"], true) && str_ends_with($name, '.mmdb')) {
                    return self::copyBytes($input, $destination, $size);
                }
                if ($padded > 0 && gzseek($input, $padded, SEEK_CUR) === -1) {
                    return false;
                }
            }
        } finally {
            gzclose($input);
        }
    }

    /** @param resource $input */
    private static function copyBytes($input, string $destination, int $size): bool
    {
        $output = @fopen($destination, 'wb');
        if ($output === false) {
            return false;
        }
        try {
            while ($size > 0) {
                $chunk = gzread($input, min($size, 1024 * 1024));
                if ($chunk === false || $chunk === '' || fwrite($output, $chunk) !== strlen($chunk)) {
                    return false;
                }
                $size -= strlen($chunk);
            }
            return true;
        } finally {
            fclose($output);
        }
    }
}
