<?php

declare(strict_types=1);

namespace Petitstats\Updates;

use Closure;
use FilesystemIterator;
use Petitstats\Database\DataDirectory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Installs a new release from the dashboard, one step per request.
 *
 *  1. problems() checks that PHP can rewrite every Petitstats file.
 *  2. download() fetches the archive, then checks its SHA-256 and every file in it.
 *  3. backup() copies the current files to the data folder and checks the copy.
 *  4. install() replaces the files, then asks the new version to answer.
 *
 * Nothing in the app folder changes before step 4. When step 4 fails, the
 * backup is put back. If PHP stops halfway (a timeout, a fatal error), a
 * shutdown function restores it; if the process is killed, the next request
 * does (see guard()). Everything lives in <data folder>/updates, and the backup
 * is a plain copy of the app files that can also be restored by hand.
 *
 * Only the files listed in src/MANIFEST are touched, so anything else in the
 * folder, such as a landing page, is left alone.
 */
final class Updater
{
    public const DOWNLOAD_URL = 'https://github.com/axthauvin/petitstats/releases/download/%s/petitstats.tar.gz';
    /** Requests are turned away for at most this long, even if the update never finishes. */
    private const MAINTENANCE_SECONDS = 600;
    private const TEMP_SUFFIX = '.petitstats-update';
    /** Written last, so an interrupted update never reports a version whose files are not all in place. */
    private const VERSION_PATH = 'src/VERSION';

    /** @var resource|null */
    private $lock = null;

    /**
     * @param Closure(string, string): void $download saves a URL to a file, and throws when it cannot
     * @param Closure(string): ?string $healthCheck the version the installed files report, or null when the server cannot reach itself
     * @param (Closure(string): void)|null $afterEachFile called after each file install() replaces, so tests can interrupt an update
     */
    public function __construct(
        private readonly string $appDir,
        private readonly string $workDir,
        private readonly Closure $download,
        private readonly Closure $healthCheck,
        private readonly ?Closure $afterEachFile = null,
    ) {}

    public static function create(): self
    {
        return new self(dirname(__DIR__, 2), DataDirectory::path() . '/updates', self::downloadFile(...), self::askInstalledVersion(...));
    }

    /**
     * Runs before every web request (see "autoload.files" in composer.json).
     * Turns requests away while files are being replaced, and puts the backup
     * back when the request replacing them was killed.
     */
    public static function guard(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        try {
            $updater = self::create();
            if (!is_file($updater->workDir . '/maintenance')) {
                return;
            }
            // Recovery does not wait for the maintenance period to end, which only limits how long requests are turned away.
            $recovered = $updater->recoverInterruptedUpdate();
            $health = $_GET['health'] ?? null;
            if (!$recovered && (!$updater->isUnderMaintenance() || (is_string($health) && $updater->acceptsHealthCheck($health)))) {
                return;
            }
        } catch (Throwable) {
            return;
        }
        http_response_code(503);
        header('Retry-After: 10');
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Petitstats is being updated. Try again in a few seconds.\n";
        exit;
    }

    /**
     * Where the last update stands. "previous" is the version a rollback would
     * bring back, and "verified" tells whether the new version answered after
     * an update.
     *
     * @return array{status: string, from?: string, to?: string, error?: string, verified?: bool, previous: ?string}
     */
    public function status(): array
    {
        $state = $this->state();
        $previous = null;
        if (($state['status'] ?? '') === 'installed' && ($state['to'] ?? null) === $this->installedVersion() && is_file($this->backupDir() . '/restore.json')) {
            $previous = $state['from'] ?? null;
        }
        return array_intersect_key($state, array_flip(['status', 'from', 'to', 'error', 'verified'])) + ['status' => 'idle', 'previous' => $previous];
    }

    /** @return list<string> Why this install cannot update itself, or nothing when it can. */
    public function problems(): array
    {
        if ($this->installedVersion() === null) {
            return ['This copy runs from the source code, so update it with Git instead.'];
        }
        $problems = [];
        if (($this->state()['status'] ?? '') === 'broken') {
            $problems[] = 'A previous update could not be undone. Restore the backup by hand first.';
        }
        if (!function_exists('gzopen')) {
            $problems[] = 'PHP needs the Zlib extension to extract the new version.';
        }
        if (!extension_loaded('openssl') || !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            $problems[] = 'PHP cannot download files from GitHub, because the OpenSSL extension or allow_url_fopen is turned off.';
        }
        if (!$this->ensureDir($this->workDir) || !is_writable($this->workDir)) {
            $problems[] = 'PHP cannot write to the data folder, where the backup is kept.';
        }
        try {
            $paths = Manifest::fromDirectory($this->appDir)?->paths() ?? [];
        } catch (RuntimeException) {
            $paths = [];
        }
        return [...$problems, ...$this->unwritable([...$paths, Manifest::PATH])];
    }

    /** Step 2. Downloads `$version` and checks it, without touching the installed files. */
    public function download(string $version): void
    {
        if (!preg_match('/^v?\d+(\.\d+)*[0-9A-Za-z.+-]*$/', $version)) {
            throw new RuntimeException('This version name is not valid.');
        }
        $this->locked(function () use ($version): void {
            if ($problems = $this->problems()) {
                throw new RuntimeException($problems[0]);
            }
            $staging = $this->stagingDir();
            $this->removeDir($staging);
            if (!$this->ensureDir($staging . '/new')) {
                throw new RuntimeException('PHP cannot write to the data folder.');
            }

            $archive = $staging . '/petitstats.tar.gz';
            $url = sprintf(self::DOWNLOAD_URL, rawurlencode($version));
            ($this->download)($url, $archive);
            ($this->download)($url . '.sha256', $archive . '.sha256');
            $expected = preg_match('/\b[a-f0-9]{64}\b/i', (string) file_get_contents($archive . '.sha256'), $match) ? strtolower($match[0]) : '';
            if (!hash_equals($expected, (string) hash_file('sha256', $archive))) {
                throw new RuntimeException('The downloaded archive is damaged, because its SHA-256 does not match the one published with the release. Try again later.');
            }

            TarArchive::extract($archive, $staging . '/new');
            $this->verifyStaged();
            $staged = trim((string) @file_get_contents($staging . '/new/' . self::VERSION_PATH));
            if ($staged !== $version) {
                throw new RuntimeException(sprintf('The archive contains version "%s" instead of %s.', $staged, $version));
            }
            $this->saveState(['status' => 'downloaded', 'from' => $this->installedVersion(), 'to' => $version]);
        });
    }

    /** Step 3. Copies every file the update will replace or remove, and checks each copy. */
    public function backup(): void
    {
        $this->locked(function (): void {
            $state = $this->state();
            if (($state['status'] ?? '') !== 'downloaded') {
                throw new RuntimeException('Download the new version first.');
            }
            $paths = $this->touchedPaths($this->verifyStaged());
            if ($problems = $this->unwritable($paths)) {
                throw new RuntimeException($problems[0]);
            }

            $size = 0;
            foreach ($paths as $path) {
                $size += is_file($this->appDir . '/' . $path) ? (int) filesize($this->appDir . '/' . $path) : 0;
            }
            $free = function_exists('disk_free_space') ? @disk_free_space($this->workDir) : false;
            if ($free !== false && $free < $size + 10_000_000) {
                throw new RuntimeException('There is not enough free disk space to keep a backup of the current version.');
            }

            // Built aside, so the previous backup stays until this one is complete and in place.
            $dir = $this->workDir . '/backup-new';
            $this->removeDir($dir);
            $saved = [];
            foreach ($paths as $path) {
                $source = $this->appDir . '/' . $path;
                if (!is_file($source)) {
                    continue;
                }
                $hash = (string) hash_file('sha256', $source);
                $copy = $dir . '/files/' . $path;
                if (!$this->ensureDir(dirname($copy)) || !@copy($source, $copy) || hash_file('sha256', $copy) !== $hash) {
                    throw new RuntimeException(sprintf('Could not back up %s.', $path));
                }
                $saved[$path] = $hash;
            }
            $this->writeJson($dir . '/restore.json', ['version' => $this->installedVersion(), 'saved' => $saved, 'written' => $paths]);
            $previous = $this->workDir . '/backup-previous';
            $this->removeDir($previous);
            if (is_dir($this->backupDir()) && !$this->retry(fn(): bool => @rename($this->backupDir(), $previous))) {
                throw new RuntimeException('Could not save the backup.');
            }
            if (!$this->retry(fn(): bool => @rename($dir, $this->backupDir()))) {
                @rename($previous, $this->backupDir());
                throw new RuntimeException('Could not save the backup.');
            }
            $this->removeDir($previous);
            $this->saveState(['status' => 'backed_up', 'from' => $state['from'] ?? null, 'to' => $state['to'] ?? null]);
        });
    }

    /** Step 4. Replaces the files, and puts the backup back if anything goes wrong. */
    public function install(): void
    {
        $this->locked(function (): void {
            $state = $this->state();
            if (($state['status'] ?? '') !== 'backed_up' || !is_file($this->backupDir() . '/restore.json')) {
                throw new RuntimeException('Back up the current version first.');
            }
            $new = $this->verifyStaged();
            $old = Manifest::fromDirectory($this->appDir);
            $from = $state['from'] ?? null;
            $to = (string) ($state['to'] ?? '');

            // Closing the tab or a short time limit must not stop PHP between two files.
            @set_time_limit(0);
            ignore_user_abort(true);
            $token = bin2hex(random_bytes(16));
            // Maintenance first, so a process killed right after saving the state is still noticed by guard().
            $this->startMaintenance();
            $this->saveState(['status' => 'installing', 'from' => $from, 'to' => $to, 'token' => $token]);
            register_shutdown_function($this->finishAbortedWork(...));

            try {
                foreach (array_diff($old?->paths() ?? [], $new->paths()) as $path) {
                    $this->removeFile($path);
                }
                foreach (self::inWriteOrder([...$new->paths(), Manifest::PATH]) as $path) {
                    $this->placeFile($this->stagingDir() . '/new/' . $path, $path);
                    if ($this->afterEachFile !== null) {
                        ($this->afterEachFile)($path);
                    }
                }
                foreach ($new->files as $path => $hash) {
                    if (hash_file('sha256', $this->appDir . '/' . $path) !== $hash) {
                        throw new RuntimeException(sprintf('%s was not written correctly.', $path));
                    }
                }
                $this->resetOpcache();

                $served = ($this->healthCheck)($token);
                if ($served !== null && $served !== $to) {
                    throw new RuntimeException(sprintf('The new version did not start correctly, since the server still reports %s.', $served));
                }
                $this->saveState(['status' => 'installed', 'from' => $from, 'to' => $to, 'verified' => $served !== null]);
            } catch (Throwable $e) {
                $this->undo($e->getMessage());
                throw new RuntimeException(sprintf('The update failed, so Petitstats went back to %s. %s', $from, $e->getMessage()), 0, $e);
            }
            $this->stopMaintenance();
            $this->removeDir($this->stagingDir());
        });
    }

    /** Brings back the version that ran before the last update. */
    public function rollback(): void
    {
        $this->locked(function (): void {
            if ($this->status()['previous'] === null) {
                throw new RuntimeException('There is no previous version to go back to.');
            }
            @set_time_limit(0);
            ignore_user_abort(true);
            $this->startMaintenance();
            $this->saveState(['status' => 'restoring'] + $this->state());
            register_shutdown_function($this->finishAbortedWork(...));
            $this->finishRollback();
        });
    }

    /**
     * Finishes what a killed request left halfway: an update is undone, a
     * rollback is completed. Does nothing while that request is still running.
     */
    public function recoverInterruptedUpdate(): bool
    {
        if (!in_array($this->state()['status'] ?? '', ['installing', 'restoring'], true) || !$this->tryLock()) {
            return false;
        }
        try {
            $this->finishInterruptedWork();
        } catch (Throwable) {
            // finishRollback() already recorded the failure.
        } finally {
            $this->unlock();
        }
        return true;
    }

    /**
     * Only called while holding the lock: an update or rollback still marked
     * as running belongs to a process that is gone.
     */
    private function finishInterruptedWork(): void
    {
        $status = $this->state()['status'] ?? '';
        if ($status === 'installing') {
            $this->undo('The update was interrupted before it finished.');
        } elseif ($status === 'restoring') {
            $this->finishRollback();
        }
    }

    public function isUnderMaintenance(): bool
    {
        $since = @filemtime($this->workDir . '/maintenance');
        return $since !== false && $since > time() - self::MAINTENANCE_SECONDS;
    }

    /** Whether `$token` is the one the running update sends to check the new files. */
    public function acceptsHealthCheck(string $token): bool
    {
        $state = $this->state();
        return $token !== '' && ($state['status'] ?? '') === 'installing' && is_string($state['token'] ?? null) && hash_equals($state['token'], $token);
    }

    private function installedVersion(): ?string
    {
        $version = trim((string) @file_get_contents($this->appDir . '/' . self::VERSION_PATH));
        return $version !== '' ? $version : null;
    }

    /**
     * Every file the update writes or removes: the new release's files and the
     * installed ones, which may include files the new release dropped.
     *
     * @return list<string>
     */
    private function touchedPaths(Manifest $new): array
    {
        $paths = [...$new->paths(), ...(Manifest::fromDirectory($this->appDir)?->paths() ?? []), Manifest::PATH];
        $paths = array_values(array_unique($paths));
        sort($paths);
        return $paths;
    }

    /**
     * The extracted archive, checked against its own file list. Run again before
     * each step, so files changed in the data folder in between are caught.
     */
    private function verifyStaged(): Manifest
    {
        $dir = $this->stagingDir() . '/new';
        $manifest = Manifest::fromDirectory($dir) ?? throw new RuntimeException('The archive has no file list (src/MANIFEST), so it cannot be installed from the dashboard.');
        $found = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('The archive contains a symbolic link, which Petitstats releases never do.');
            }
            if ($file->isFile()) {
                $found[] = strtr(substr($file->getPathname(), strlen($dir) + 1), '\\', '/');
            }
        }
        $unlisted = array_diff($found, [...$manifest->paths(), Manifest::PATH]);
        if ($unlisted !== []) {
            throw new RuntimeException(sprintf('The archive contains %s, which is missing from its file list.', reset($unlisted)));
        }
        foreach ($manifest->files as $path => $hash) {
            if (!is_file($dir . '/' . $path) || hash_file('sha256', $dir . '/' . $path) !== $hash) {
                throw new RuntimeException(sprintf('The file %s in the archive is missing or damaged.', $path));
            }
        }
        return $manifest;
    }

    /**
     * @param list<string> $paths
     * @return list<string> a problem when PHP cannot change some of these files
     */
    private function unwritable(array $paths): array
    {
        $blocked = [];
        $dirs = [];
        foreach ($paths as $path) {
            $target = $this->appDir . '/' . $path;
            if (file_exists($target) && !is_writable($target)) {
                $blocked[] = $path;
            }
            // Files are replaced by renaming, and new ones created, so their folder must be writable too.
            $dir = dirname($path);
            while ($dir !== '.' && !is_dir($this->appDir . '/' . $dir)) {
                $dir = dirname($dir);
            }
            $dirs[$dir] = true;
        }
        foreach (array_keys($dirs) as $dir) {
            if (!is_writable($this->appDir . '/' . $dir)) {
                $blocked[] = $dir === '.' ? 'the Petitstats folder' : $dir . '/';
            }
        }
        if ($blocked === []) {
            return [];
        }
        sort($blocked);
        $examples = implode(', ', array_slice($blocked, 0, 3));
        return [count($blocked) > 3
            ? sprintf('PHP is not allowed to change %d Petitstats files and folders, such as %s.', count($blocked), $examples)
            : sprintf('PHP is not allowed to change %s.', $examples)];
    }

    /** Puts the backup back after a failed update, and records the outcome. */
    private function undo(string $reason): void
    {
        $state = $this->state();
        $versions = ['from' => $state['from'] ?? null, 'to' => $state['to'] ?? null];
        try {
            $this->restoreBackup();
            $this->saveState(['status' => 'failed', 'error' => $reason] + $versions);
        } catch (Throwable $e) {
            $this->saveState(['status' => 'broken', 'error' => $reason . ' Restoring the backup failed too. ' . $e->getMessage()] + $versions);
        }
        $this->stopMaintenance();
    }

    private function finishRollback(): void
    {
        $state = $this->state();
        try {
            $this->restoreBackup();
            $this->saveState(['status' => 'rolled_back', 'from' => $state['to'] ?? null, 'to' => $state['from'] ?? null]);
            $this->removeDir($this->backupDir());
        } catch (Throwable $e) {
            $this->saveState(['status' => 'broken', 'from' => $state['from'] ?? null, 'to' => $state['to'] ?? null, 'error' => 'Going back to the previous version failed. ' . $e->getMessage()]);
            throw $e;
        } finally {
            $this->stopMaintenance();
        }
    }

    /** Shutdown function of install() and rollback(): runs even when PHP stops on a timeout or a fatal error. */
    private function finishAbortedWork(): void
    {
        $status = $this->state()['status'] ?? '';
        if ($status !== 'installing' && $status !== 'restoring') {
            return;
        }
        @set_time_limit(0);
        try {
            $status === 'installing' ? $this->undo('PHP stopped before the update finished.') : $this->finishRollback();
        } catch (Throwable) {
            // finishRollback() already recorded the failure.
        }
    }

    /** Copies the backup over the app files. Files already restored are skipped, so it can run again after an interruption. */
    private function restoreBackup(): void
    {
        $plan = $this->readJson($this->backupDir() . '/restore.json');
        $saved = $plan['saved'] ?? null;
        $written = $plan['written'] ?? null;
        if (!is_array($saved) || !is_array($written)) {
            throw new RuntimeException('The backup is incomplete.');
        }
        foreach ($written as $path) {
            if (!is_string($path) || !Manifest::isSafePath($path)) {
                continue;
            }
            @unlink($this->appDir . '/' . $path . self::TEMP_SUFFIX);
            if (!isset($saved[$path]) && is_file($this->appDir . '/' . $path)) {
                $this->removeFile($path);
            }
        }
        foreach (self::inWriteOrder(array_map('strval', array_keys($saved))) as $path) {
            $target = $this->appDir . '/' . $path;
            $hash = $saved[$path];
            if (!Manifest::isSafePath($path) || (is_file($target) && hash_file('sha256', $target) === $hash)) {
                continue;
            }
            $this->placeFile($this->backupDir() . '/files/' . $path, $path);
            if (hash_file('sha256', $target) !== $hash) {
                throw new RuntimeException(sprintf('Could not restore %s.', $path));
            }
        }
        $this->resetOpcache();
    }

    /** Writes next to the target, then renames, so no request ever reads a half-written file. */
    private function placeFile(string $source, string $path): void
    {
        $target = $this->appDir . '/' . $path;
        $temp = $target . self::TEMP_SUFFIX;
        if (!$this->ensureDir(dirname($target)) || !@copy($source, $temp)) {
            throw new RuntimeException(sprintf('Could not write %s.', $path));
        }
        if (is_file($target)) {
            @chmod($temp, fileperms($target) & 0777);
        }
        // Windows refuses to rename over the running script (update.php itself) but lets PHP
        // overwrite it. The hashes checked after each update and restore catch a partial copy.
        $replaced = $this->retry(fn(): bool => @rename($temp, $target)) || (PHP_OS_FAMILY === 'Windows' && @copy($temp, $target));
        @unlink($temp);
        if (!$replaced) {
            throw new RuntimeException(sprintf('Could not replace %s.', $path));
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
    }

    private function removeFile(string $path): void
    {
        $target = $this->appDir . '/' . $path;
        if (is_file($target) && !$this->retry(fn(): bool => @unlink($target))) {
            throw new RuntimeException(sprintf('Could not remove %s.', $path));
        }
        // Removes the folders this leaves empty, up to the app folder.
        $dir = dirname($path);
        while ($dir !== '.' && @rmdir($this->appDir . '/' . $dir)) {
            $dir = dirname($dir);
        }
    }

    /**
     * Every file in the order it is written: src/MANIFEST, then src/VERSION
     * come last, so an interrupted update or restore never reports a version
     * whose files are not all in place.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function inWriteOrder(array $paths): array
    {
        $last = [Manifest::PATH, self::VERSION_PATH];
        return [...array_diff($paths, $last), ...array_intersect($last, $paths)];
    }

    /**
     * Windows refuses to rename or delete a file another request is reading at
     * that moment, so a failure there is retried for a second.
     *
     * @param Closure(): bool $operation
     */
    private function retry(Closure $operation): bool
    {
        for ($attempt = 1; !$operation(); $attempt++) {
            if (PHP_OS_FAMILY !== 'Windows' || $attempt >= 20) {
                return false;
            }
            usleep(50_000);
            clearstatcache();
        }
        return true;
    }

    private function startMaintenance(): void
    {
        if (!@touch($this->workDir . '/maintenance')) {
            throw new RuntimeException('PHP cannot write to the data folder.');
        }
    }

    private function stopMaintenance(): void
    {
        @unlink($this->workDir . '/maintenance');
    }

    private function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache(true);
    }

    /** @param Closure(): void $work */
    private function locked(Closure $work): void
    {
        if (!$this->ensureDir($this->workDir) || !$this->tryLock()) {
            throw new RuntimeException('Another update step is already running. Wait a moment, then reload the page.');
        }
        try {
            $this->finishInterruptedWork();
            $work();
        } finally {
            $this->unlock();
        }
    }

    /** The lock is released by the system when the process ends, so a killed request never leaves it behind. */
    private function tryLock(): bool
    {
        $handle = @fopen($this->workDir . '/update.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->lock = $handle;
        return true;
    }

    private function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private function stagingDir(): string
    {
        return $this->workDir . '/staging';
    }

    private function backupDir(): string
    {
        return $this->workDir . '/backup';
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return $this->readJson($this->workDir . '/state.json');
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        $this->writeJson($this->workDir . '/state.json', ['updated_at' => time()] + $state);
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $file, array $data): void
    {
        $temp = $file . '.tmp';
        if (@file_put_contents($temp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false || !$this->retry(fn(): bool => @rename($temp, $file))) {
            throw new RuntimeException('PHP cannot write to the data folder.');
        }
    }

    private function ensureDir(string $dir): bool
    {
        return is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private static function downloadFile(string $url, string $target): void
    {
        $context = stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: Petitstats\r\n"]]);
        $in = @fopen($url, 'rb', false, $context);
        $out = $in !== false ? @fopen($target, 'wb') : false;
        $copied = $in !== false && $out !== false ? stream_copy_to_stream($in, $out) : false;
        if ($in !== false) {
            fclose($in);
        }
        if ($out !== false) {
            fclose($out);
        }
        if (!$copied) {
            throw new RuntimeException(sprintf('Could not download %s from GitHub. Try again later.', basename($url)));
        }
    }

    /**
     * The answer of update.php to a health check. Every release must keep
     * sending it, or updating to that release is undone.
     *
     * @return array{health: string, version: ?string}
     */
    public static function healthAnswer(string $token, ?string $version): array
    {
        return ['health' => $token, 'version' => $version];
    }

    /**
     * Asks the updated files which version they run, through the same URL as
     * the running request (dashboard/src/api/update.php).
     */
    private static function askInstalledVersion(string $token): ?string
    {
        $host = $_SERVER['HTTP_HOST'] ?? null;
        $script = $_SERVER['SCRIPT_NAME'] ?? null;
        if (!is_string($host) || !is_string($script)) {
            return null;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        $url = ($https ? 'https' : 'http') . '://' . $host . $script . '?health=' . $token;
        // The server calls itself to read a version number, so a self-signed certificate is fine.
        $context = stream_context_create([
            'http' => ['timeout' => 15, 'ignore_errors' => true, 'header' => "User-Agent: Petitstats\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        // A server that cannot reach itself says nothing about the new files.
        if ($stream === false) {
            return null;
        }
        $body = stream_get_contents($stream);
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        fclose($stream);
        // The last status line, after any redirect (such as http:// to https://).
        $statuses = [];
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
                $statuses[] = (int) $match[1];
            }
        }
        return self::readHealthAnswer(end($statuses) ?: 0, count($statuses) > 1, (string) $body, $token);
    }

    /**
     * The version the new files report, found even after a PHP warning printed
     * before it. Without it, only a PHP error (500), or another 200 answer
     * from update.php itself, means the new version is broken. Anything else,
     * like a redirect to a login page or a proxy error, says nothing about it.
     */
    private static function readHealthAnswer(int $status, bool $redirected, string $body, string $token): ?string
    {
        $start = strpos($body, '{"health":' . json_encode($token) . ',');
        if ($start !== false && preg_match('/^\{[^{}]*\}/', substr($body, $start), $match)) {
            $answer = json_decode($match[0], true);
            return is_string($answer['version'] ?? null) ? $answer['version'] : '';
        }
        if ($status === 500 || ($status === 200 && !$redirected)) {
            throw new RuntimeException(sprintf('The new version did not start correctly (HTTP %d).', $status));
        }
        return null;
    }
}
