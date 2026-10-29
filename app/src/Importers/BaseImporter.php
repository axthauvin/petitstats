<?php

declare(strict_types=1);

namespace Petitstats\Importers;

use InvalidArgumentException;
use Petitstats\Database\Connections;
use Petitstats\Database\DatabaseConnection;
use Petitstats\Sites\SiteRegistry;
use RuntimeException;
use ZipArchive;

/**
 * Base Importer Class with shared normalization utilities.
 */
abstract class BaseImporter implements ImporterInterface
{
    /** @param ?int $now Today as a Unix time, for tests whose exports have fixed dates. */
    public function __construct(private readonly ?int $now = null) {}

    protected static array $countryMap = [
        'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra',
        'AO' => 'Angola', 'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia',
        'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas',
        'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus',
        'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BT' => 'Bhutan',
        'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil',
        'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
        'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cape Verde',
        'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China',
        'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo (DRC)',
        'CR' => 'Costa Rica', 'CI' => 'Ivory Coast', 'HR' => 'Croatia', 'CU' => 'Cuba',
        'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'DJ' => 'Djibouti',
        'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador',
        'EE' => 'Estonia', 'ET' => 'Ethiopia', 'FI' => 'Finland', 'FR' => 'France',
        'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany',
        'GH' => 'Ghana', 'GR' => 'Greece', 'GT' => 'Guatemala', 'GN' => 'Guinea',
        'HT' => 'Haiti', 'HN' => 'Honduras', 'HU' => 'Hungary', 'IS' => 'Iceland',
        'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq',
        'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica',
        'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya',
        'KR' => 'South Korea', 'KW' => 'Kuwait', 'LV' => 'Latvia', 'LB' => 'Lebanon',
        'LY' => 'Libya', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MG' => 'Madagascar',
        'MY' => 'Malaysia', 'ML' => 'Mali', 'MT' => 'Malta', 'MR' => 'Mauritania',
        'MU' => 'Mauritius', 'MX' => 'Mexico', 'MD' => 'Moldova', 'MC' => 'Monaco',
        'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco', 'MZ' => 'Mozambique',
        'NA' => 'Namibia', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NZ' => 'New Zealand',
        'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NO' => 'Norway',
        'OM' => 'Oman', 'PK' => 'Pakistan', 'PA' => 'Panama', 'PY' => 'Paraguay',
        'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal',
        'QA' => 'Qatar', 'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda',
        'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SG' => 'Singapore',
        'SK' => 'Slovakia', 'SI' => 'Slovenia', 'ZA' => 'South Africa', 'ES' => 'Spain',
        'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SE' => 'Sweden', 'CH' => 'Switzerland',
        'SY' => 'Syria', 'TW' => 'Taiwan', 'TZ' => 'Tanzania', 'TH' => 'Thailand',
        'TG' => 'Togo', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'UG' => 'Uganda',
        'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom',
        'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VE' => 'Venezuela',
        'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
        'MQ' => 'Martinique', 'GP' => 'Guadeloupe', 'RE' => 'Reunion', 'GF' => 'French Guiana',
    ];

    public function getCountryName(string $code): string
    {
        $clean = strtoupper(trim($code));
        return self::$countryMap[$clean] ?? $clean;
    }

    public function normalizeBrowser(?string $browser): string
    {
        if (empty($browser) || $browser === '\N') {
            return 'Unknown';
        }
        $b = strtolower(trim($browser));

        if ($b === 'ios' || $b === 'safari') {
            return 'Safari';
        }
        if ($b === 'crios') {
            return 'Chrome (iOS)';
        }
        if ($b === 'fxios') {
            return 'Firefox (iOS)';
        }
        if ($b === 'edge-ios') {
            return 'Edge (iOS)';
        }
        if (str_contains($b, 'edge')) {
            return 'Edge';
        }
        if (str_contains($b, 'chrome')) {
            return 'Chrome';
        }
        if (str_contains($b, 'firefox')) {
            return 'Firefox';
        }
        if (str_contains($b, 'opera') || str_contains($b, 'opr')) {
            return 'Opera';
        }
        if (str_contains($b, 'samsung')) {
            return 'Samsung Internet';
        }
        if (str_contains($b, 'webview')) {
            return 'WebView';
        }
        if ($b === 'facebook') {
            return 'Facebook In-App';
        }
        if ($b === 'instagram') {
            return 'Instagram In-App';
        }
        if ($b === 'android') {
            return 'Android Browser';
        }

        return ucfirst($b);
    }

    public function normalizeOs(?string $os): string
    {
        if (empty($os) || $os === '\N') {
            return 'Unknown';
        }
        $o = trim($os);
        $low = strtolower($o);

        if ($low === 'mac os' || $low === 'macos' || $low === 'os x') {
            return 'macOS';
        }
        if (str_starts_with($low, 'windows')) {
            return $o;
        }
        if ($low === 'android os' || $low === 'android') {
            return 'Android';
        }
        if ($low === 'ios') {
            return 'iOS';
        }
        if ($low === 'linux') {
            return 'Linux';
        }
        if (str_contains($low, 'chrome os')) {
            return 'Chrome OS';
        }

        return $o;
    }

    public function normalizeDevice(?string $device): string
    {
        if (empty($device) || $device === '\N') {
            return 'Desktop';
        }
        $d = strtolower(trim($device));

        if ($d === 'mobile') {
            return 'Mobile';
        }
        if ($d === 'tablet') {
            return 'Tablet';
        }
        if ($d === 'laptop' || $d === 'desktop') {
            return 'Desktop';
        }

        return ucfirst($d);
    }

    public function cleanVal($val, $default = null)
    {
        if ($val === null || $val === '' || $val === '\N') {
            return $default;
        }
        return $val;
    }

    public function cleanSiteId(string $siteId): string
    {
        $clean = (string) preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower(trim($siteId)));
        if ($clean === '') {
            throw new InvalidArgumentException('A valid website identifier is required for import.');
        }
        return $clean;
    }

    /**
     * Registers the website when it does not exist yet.
     *
     * @return array{string, string} The website name and domain.
     */
    protected function ensureSite(string $siteId, array $options): array
    {
        $siteName = ($options['name'] ?? '') ?: ucwords(str_replace(['_', '-'], ' ', $siteId));
        $siteDomain = (string) ($options['domain'] ?? '');

        $existing = SiteRegistry::find($siteId);
        if ($existing !== null) {
            return [(string) ($existing['name'] ?? '') ?: $siteName, (string) ($existing['domain'] ?? '') ?: $siteDomain];
        }
        SiteRegistry::create($siteId, $siteName, $siteDomain);
        return [$siteName, $siteDomain];
    }

    /**
     * Applies the "retention_days" option to the website, then returns the date before which
     * its visits are deleted: importing them would only have them removed right away.
     */
    protected function retentionCutoff(string $siteId, array $options): string
    {
        if (isset($options['retention_days']) && is_numeric($options['retention_days'])) {
            SiteRegistry::update($siteId, ['retention_days' => (int) $options['retention_days']]);
        }
        $site = SiteRegistry::find($siteId) ?? [];
        $days = SiteRegistry::clampRetention((int) ($site['retention_days'] ?? SiteRegistry::DEFAULT_RETENTION_DAYS));
        return gmdate('Y-m-d H:i:s', ($this->now ?? time()) - $days * 86400);
    }

    /** Opens the website database tuned for a bulk insert. Call finishBulkInsert() afterwards. */
    protected function openForBulkInsert(string $siteId): DatabaseConnection
    {
        $db = Connections::forSite($siteId);
        if (!$db->isMysql()) {
            $db->exec('PRAGMA synchronous = OFF;');
            $db->exec('PRAGMA journal_mode = MEMORY;');
            $db->exec("CREATE TABLE IF NOT EXISTS user_activity (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                visitor_id TEXT,
                action TEXT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ua_timestamp ON user_activity(timestamp)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ua_session ON user_activity(session_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ua_visitor ON user_activity(visitor_id)");
        }
        return $db;
    }

    /**
     * Visits the website already has, so importing the same export twice does not count them twice.
     *
     * @return array<string, true>
     */
    protected function knownSessions(DatabaseConnection $db): array
    {
        $known = [];
        $result = $db->query('SELECT DISTINCT session_id FROM user_activity');
        while ($result !== false && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $known[(string) $row['session_id']] = true;
        }
        return $known;
    }

    protected function finishBulkInsert(DatabaseConnection $db): void
    {
        if (!$db->isMysql()) {
            $db->exec('PRAGMA synchronous = NORMAL;');
            $db->exec('PRAGMA journal_mode = WAL;');
        }
    }

    /**
     * Saves the most seen hostname as the website domain when it has none.
     *
     * @param array<string, int> $hostnames Hostname => number of events.
     */
    protected function saveDetectedDomain(string $siteId, string $siteDomain, array $hostnames): string
    {
        if ($siteDomain !== '' || $hostnames === []) {
            return $siteDomain;
        }
        arsort($hostnames);
        $detectedHost = (string) array_key_first($hostnames);
        $sites = SiteRegistry::all();
        foreach ($sites as &$s) {
            if ($s['id'] === $siteId && empty($s['domain'])) {
                $s['domain'] = $detectedHost;
                if (empty($s['allowed_domains'])) {
                    $s['allowed_domains'] = [SiteRegistry::normalizeHost($detectedHost)];
                }
                break;
            }
        }
        unset($s);
        SiteRegistry::save($sites);
        return $detectedHost;
    }

    /** True for a ZIP archive, whatever its name (uploads have temporary names without extension). */
    public function isZipArchiveFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $signature = fread($handle, 4);
        fclose($handle);
        return in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }

    /**
     * Extracts a zip archive to a target directory.
     * Uses ZipArchive if available, or PowerShell Expand-Archive fallback on Windows.
     */
    public function extractZip(string $zipPath, string $targetDir): bool
    {
        if (!file_exists($zipPath)) {
            throw new InvalidArgumentException("Zip file does not exist: {$zipPath}");
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $res = $zip->open($zipPath);
            if ($res === true) {
                $zip->extractTo($targetDir);
                $zip->close();
                return true;
            }
            throw new RuntimeException("ZipArchive failed to open file (code {$res}): {$zipPath}");
        }

        // Fallback for Windows if ZipArchive extension is disabled in php.ini
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = sprintf(
                'powershell -NoProfile -NonInteractive -Command "Expand-Archive -LiteralPath %s -DestinationPath %s -Force"',
                escapeshellarg($zipPath),
                escapeshellarg($targetDir),
            );
            $out = [];
            $ret = 0;
            exec($cmd, $out, $ret);
            if ($ret === 0) {
                return true;
            }
        }

        throw new RuntimeException("ZipArchive extension is not enabled in PHP and system fallback failed.");
    }

    /**
     * Recursively delete a directory
     */
    public function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
