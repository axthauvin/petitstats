<?php

declare(strict_types=1);

namespace Petitstats\Importers;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Matomo Analytics Importer
 * Reads visits exported from the Matomo Live API (Live.getLastVisitsDetails) as JSON or CSV,
 * a single file, a folder of files or a .zip of them. Every action of a visit becomes an event:
 * pageviews, events, outlinks, downloads, site searches, goals, ecommerce orders and the rest.
 */
class MatomoImporter extends BaseImporter
{
    /** Action fields that only matter to the Matomo interface. */
    private const NOISE_FIELDS = [
        'type', 'icon', 'iconSVG', 'title', 'subtitle', 'serverTimePretty', 'timeSpentPretty',
        'pageIdAction', 'idpageview', 'pageId', 'pageviewPosition', 'timestamp', 'idsite',
    ];

    public function getId(): string
    {
        return 'matomo';
    }

    public function getName(): string
    {
        return 'Matomo';
    }

    public function getDescription(): string
    {
        return 'Import visits, pageviews, events, goals and ecommerce orders from a Matomo visits export (JSON or CSV).';
    }

    public function getStatus(): string
    {
        return 'ready';
    }

    public function getBadge(): string
    {
        return 'Available';
    }

    public function getIcon(): string
    {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getSupportedFormats(): array
    {
        return ['.json', '.csv', '.zip'];
    }

    /**
     * The JSON and CSV export files of a source, in name order.
     *
     * @return list<string>
     */
    public function findFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            $extension = strtolower($item->getExtension());
            if ($item->isFile() && ($extension === 'json' || $extension === 'csv')) {
                $files[] = $item->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Every visit of an export file, nested the way the JSON format nests it.
     *
     * @return iterable<array<string, mixed>>
     */
    public function readVisits(string $file): iterable
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Unable to read ' . basename($file) . '.');
        }
        $raw = $this->toUtf8($raw);
        $trimmed = ltrim($raw);
        if ($trimmed === '') {
            return [];
        }
        if ($trimmed[0] === '[' || $trimmed[0] === '{') {
            return $this->decodeJson($trimmed, basename($file));
        }
        return $this->readCsv($raw);
    }

    /**
     * Quick metadata inspection before importing
     */
    public function inspect(string $sourcePath): array
    {
        return $this->withSource($sourcePath, function (array $files): array {
            $hostnames = [];
            $siteNames = [];
            $seen = [];
            $dateStart = null;
            $dateEnd = null;
            foreach ($files as $file) {
                foreach ($this->readVisits($file) as $visit) {
                    $key = ($visit['idSite'] ?? '0') . '_' . ($visit['idVisit'] ?? count($seen));
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $times = array_column($this->visitEvents($visit), 0);
                    if ($times !== []) {
                        $dateStart = $dateStart === null ? min($times) : min($dateStart, ...$times);
                        $dateEnd = $dateEnd === null ? max($times) : max($dateEnd, ...$times);
                    }
                    // The website name and address are clear from the first visits.
                    if (count($seen) <= 500) {
                        $siteName = $this->cleanVal($visit['siteName'] ?? null);
                        if (is_string($siteName)) {
                            $siteNames[$siteName] = ($siteNames[$siteName] ?? 0) + 1;
                        }
                        foreach ($visit['actionDetails'] ?? [] as $action) {
                            $host = $this->hostOf($action['url'] ?? null);
                            if ($host !== null && ($action['type'] ?? '') === 'action') {
                                $hostnames[$host] = ($hostnames[$host] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
            arsort($hostnames);
            arsort($siteNames);
            $primaryHost = (string) (array_key_first($hostnames) ?? '');
            $siteName = (string) (array_key_first($siteNames) ?? '');

            return [
                'visits' => count($seen),
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'detected_host' => $primaryHost,
                'suggested_site_id' => preg_replace('/[^a-z0-9_\-]/', '', strtolower(explode('.', $primaryHost)[0] ?: 'imported_site')),
                // The name given in Matomo, else the address without "www." and its extension.
                'suggested_name' => $siteName !== '' ? $siteName : ucwords(str_replace(['.', '-', '_'], ' ', (string) preg_replace('/^www\.|\.[a-z]+$/i', '', $primaryHost) ?: 'Imported Site')),
            ];
        });
    }

    /**
     * Import data into Petitstats database
     */
    public function import(string $sourcePath, string $siteId, array $options = []): array
    {
        $cleanSiteId = $this->cleanSiteId($siteId);

        return $this->withSource($sourcePath, function (array $files) use ($cleanSiteId, $options): array {
            [$siteName, $siteDomain] = $this->ensureSite($cleanSiteId, $options);
            $cutoff = $this->retentionCutoff($cleanSiteId, $options);
            $db = $this->openForBulkInsert($cleanSiteId);

            $totalImported = 0;
            $pageviewCount = 0;
            $customEventCount = 0;
            // Visits already imported, from an earlier import or an overlapping export file.
            $seen = $this->knownSessions($db);
            $duplicates = 0;
            $sessions = [];
            $skippedVisits = 0;
            $hostnames = [];
            $minTimestamp = null;
            $maxTimestamp = null;

            $db->exec($db->isMysql() ? 'START TRANSACTION' : 'BEGIN TRANSACTION');
            $insertStmt = $db->prepare("INSERT INTO user_activity (session_id, visitor_id, action, timestamp) VALUES (:session_id, :visitor_id, :action, :timestamp)");

            try {
                foreach ($files as $file) {
                    foreach ($this->readVisits($file) as $visit) {
                        $sessionId = 'matomo_' . ($visit['idSite'] ?? '0') . '_' . ($visit['idVisit'] ?? uniqid());
                        if (isset($seen[$sessionId])) {
                            $duplicates++;
                            continue;
                        }
                        $seen[$sessionId] = true;

                        $events = array_filter($this->visitEvents($visit), static fn(array $event): bool => $event[0] >= $cutoff);
                        if ($events === []) {
                            $skippedVisits++;
                            continue;
                        }
                        $sessions[$sessionId] = true;
                        foreach ($events as [$timestamp, $name, $data]) {
                            $action = [
                                'site_id' => $cleanSiteId,
                                'session_id' => $sessionId,
                                'visitor_id' => $this->visitorId($visit, $sessionId),
                                'name' => $name,
                                'data' => $data,
                            ];
                            $insertStmt->bindValue(':session_id', $sessionId, SQLITE3_TEXT);
                            $insertStmt->bindValue(':visitor_id', $action['visitor_id'], SQLITE3_TEXT);
                            $insertStmt->bindValue(':action', json_encode($action, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                            $insertStmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
                            $insertStmt->execute();

                            $totalImported++;
                            if ($name === 'pageview') {
                                $pageviewCount++;
                                if (!empty($data['hostname'])) {
                                    $hostnames[$data['hostname']] = ($hostnames[$data['hostname']] ?? 0) + 1;
                                }
                            } else {
                                $customEventCount++;
                            }
                            $minTimestamp = $minTimestamp === null || $timestamp < $minTimestamp ? $timestamp : $minTimestamp;
                            $maxTimestamp = $maxTimestamp === null || $timestamp > $maxTimestamp ? $timestamp : $maxTimestamp;
                        }
                    }
                }
                $db->exec('COMMIT;');
            } catch (Throwable $error) {
                $db->exec('ROLLBACK;');
                throw $error;
            } finally {
                $this->finishBulkInsert($db);
            }

            $siteDomain = $this->saveDetectedDomain($cleanSiteId, $siteDomain, $hostnames);
            arsort($hostnames);

            return [
                'success' => true,
                'provider' => 'matomo',
                'site_id' => $cleanSiteId,
                'site_name' => $siteName,
                'site_domain' => $siteDomain,
                'total_imported' => $totalImported,
                'pageviews' => $pageviewCount,
                'custom_events' => $customEventCount,
                'sessions' => count($sessions),
                'skipped_sessions' => $skippedVisits,
                'duplicate_sessions' => $duplicates,
                'date_start' => $minTimestamp,
                'date_end' => $maxTimestamp,
                'detected_hostnames' => array_keys($hostnames),
            ];
        });
    }

    /**
     * The Petitstats events of one visit, in order.
     *
     * @param array<string, mixed> $visit
     * @return list<array{string, string, array<string, mixed>}> Timestamp, event name and data.
     */
    public function visitEvents(array $visit): array
    {
        $actions = array_values(array_filter(
            is_array($visit['actionDetails'] ?? null) ? $visit['actionDetails'] : [],
            static fn($action): bool => is_array($action) && ($action['type'] ?? '') !== '',
        ));
        $context = $this->visitContext($visit);
        $clock = $this->clock($visit, $actions);

        // Interface CSV exports list products per visit rather than per order: give them to the only order or cart.
        $carts = array_keys(array_filter($actions, static fn(array $a): bool => in_array($a['type'], ['ecommerceOrder', 'ecommerceAbandonedCart'], true)));
        if (count($carts) === 1 && !isset($actions[$carts[0]]['itemDetails']) && is_array($visit['itemDetails'] ?? null)) {
            $actions[$carts[0]]['itemDetails'] = $visit['itemDetails'];
        }

        $events = [];
        $page = [];
        $landed = false;
        foreach ($actions as $position => $action) {
            [$name, $data] = $this->actionEvent($action, $page);
            $isLanding = $position === 0 || ($name === 'pageview' && !$landed);
            if ($name === 'pageview') {
                $landed = true;
                $page = array_intersect_key($data, array_flip(['path', 'title', 'url', 'hostname', 'search', 'hash']));
            }
            // The visit's referrer and campaign belong to its landing; later pages come from the site itself.
            $data += $isLanding ? $this->acquisition($visit) : ['referrer' => $page['hostname'] ?? null];
            $events[] = [$clock($action), $name, $data + $context];
        }
        return $events;
    }

    /**
     * Event name and data for one Matomo action. $page is the last page viewed, where the action happened.
     *
     * @param array<string, mixed> $action
     * @param array<string, mixed> $page
     * @return array{string, array<string, mixed>}
     */
    private function actionEvent(array $action, array $page): array
    {
        $type = (string) $action['type'];
        $custom = $this->customValues($action, 'customVariablePageName', 'customVariablePageValue');

        switch ($type) {
            case 'action':
                return ['pageview', $this->pageData($action['url'] ?? null, $action['pageTitle'] ?? null) + $custom];
            case 'event':
                return [(string) ($this->cleanVal($action['eventAction'] ?? null) ?? 'event'), $this->withoutEmpty([
                    'category' => $action['eventCategory'] ?? null,
                    'label' => $action['eventName'] ?? null,
                    'value' => $this->number($action['eventValue'] ?? null),
                ]) + $custom + $this->pageOrUrl($page, $action)];
            case 'outlink':
                return ['outbound_click', $this->withoutEmpty([
                    'target_host' => $this->hostOf($action['url'] ?? null),
                    'target_url' => $action['url'] ?? null,
                ]) + $page];
            case 'download':
                return ['file_download', $this->withoutEmpty([
                    'file_name' => basename((string) parse_url((string) ($action['url'] ?? ''), PHP_URL_PATH)),
                    'file_url' => $action['url'] ?? null,
                ]) + $page];
            case 'search':
                return ['site_search', $this->withoutEmpty([
                    'keyword' => $action['siteSearchKeyword'] ?? null,
                    'category' => $action['siteSearchCategory'] ?? null,
                    'results_count' => $this->number($action['siteSearchCount'] ?? null),
                ]) + $custom + $page];
            case 'goal':
                $goal = $this->cleanVal($action['goalName'] ?? null) ?? ('Goal ' . ($action['goalId'] ?? ''));
                return [(string) $goal, $this->withoutEmpty([
                    'goal_id' => $this->number($action['goalId'] ?? null),
                    'revenue' => $this->number($action['revenue'] ?? null),
                ]) + $this->pageOrUrl($page, $action)];
            case 'ecommerceOrder':
                return ['purchase', $this->withoutEmpty([
                    'order_id' => $action['orderId'] ?? null,
                    'revenue' => $this->number($action['revenue'] ?? null),
                    'subtotal' => $this->number($action['revenueSubTotal'] ?? null),
                    'tax' => $this->number($action['revenueTax'] ?? null),
                    'shipping' => $this->number($action['revenueShipping'] ?? null),
                    'discount' => $this->number($action['revenueDiscount'] ?? null),
                    'items' => $this->number($action['items'] ?? null),
                    'products' => $this->productNames($action),
                ]) + $page];
            case 'ecommerceAbandonedCart':
                return ['abandoned_cart', $this->withoutEmpty([
                    'revenue' => $this->number($action['revenue'] ?? null),
                    'items' => $this->number($action['items'] ?? null),
                    'products' => $this->productNames($action),
                ]) + $page];
            case 'form':
                return ['form_interaction', $this->withoutEmpty([
                    'form_name' => $action['formName'] ?? null,
                    'status' => $action['formStatus'] ?? null,
                    'submitted' => $this->flag($action['submitted'] ?? null),
                    'converted' => $this->flag($action['converted'] ?? null),
                ]) + $page];
            case 'media':
                return ['media_play', $this->withoutEmpty([
                    'media_title' => $action['media_title'] ?? null,
                    'media_type' => $action['media_type'] ?? null,
                    'player' => $action['player_name'] ?? null,
                    'watched_time' => $this->number($action['watched_time'] ?? null),
                    'media_length' => $this->number($action['media_length'] ?? null),
                    'media_url' => $action['url'] ?? null,
                ]) + $page];
            case 'crash':
                return ['js_error', $this->withoutEmpty([
                    'message' => $action['message'] ?? null,
                    'error_type' => $action['crashType'] ?? null,
                    'category' => $action['category'] ?? null,
                    'source' => $action['resourceUri'] ?? null,
                ]) + $page];
        }

        if (isset($action['contentName'])) {
            $interaction = $this->cleanVal($action['contentInteraction'] ?? null);
            return [$interaction !== null ? 'content_interaction' : 'content_impression', $this->withoutEmpty([
                'content_name' => $action['contentName'],
                'content_piece' => $action['contentPiece'] ?? null,
                'content_target' => $action['contentTarget'] ?? null,
                'interaction' => $interaction,
            ]) + $custom + $this->pageOrUrl($page, $action)];
        }

        // Any other Matomo action (from a plugin) keeps its plain fields.
        $data = [];
        foreach ($action as $key => $value) {
            if (!in_array($key, self::NOISE_FIELDS, true) && is_scalar($value) && $this->cleanVal($value) !== null) {
                $data[$key] = $value;
            }
        }
        return ['matomo_' . $type, $data + $page];
    }

    /**
     * Visitor details repeated on every event of the visit.
     *
     * @param array<string, mixed> $visit
     * @return array<string, mixed>
     */
    private function visitContext(array $visit): array
    {
        $countryCode = strtoupper((string) ($visit['countryCode'] ?? ''));
        $known = $countryCode !== '' && $countryCode !== 'XX';
        $resolution = strtolower((string) ($visit['resolution'] ?? ''));
        $language = strtolower((string) ($visit['languageCode'] ?? ''));

        return [
            'screen' => preg_match('/^\d+x\d+$/', $resolution) ? str_replace('x', '×', $resolution) : null,
            'device' => $this->matomoDevice($visit['deviceType'] ?? null),
            'language' => $language !== '' ? explode('-', $language)[0] : null,
            'browser' => $this->matomoBrowser($visit['browserName'] ?? null),
            'os' => $this->matomoOs($visit['operatingSystemName'] ?? null),
            'country_code' => $known ? $countryCode : 'UN',
            'country' => $known ? $this->getCountryName($countryCode) : 'Unknown',
            'region' => $this->cleanVal($visit['region'] ?? null),
            'city' => $this->cleanVal($visit['city'] ?? null),
        ] + $this->customValues($visit, 'customVariableName', 'customVariableValue');
    }

    /**
     * Referrer and campaign of the visit.
     *
     * @param array<string, mixed> $visit
     * @return array<string, mixed>
     */
    private function acquisition(array $visit): array
    {
        $referrerType = (string) ($visit['referrerType'] ?? '');
        $referrer = null;
        foreach (['referrerUrl', 'referrerSearchEngineUrl', 'referrerSocialNetworkUrl', 'referrerAIAssistantUrl'] as $field) {
            if (($referrer = $this->cleanVal($visit[$field] ?? null)) !== null) {
                break;
            }
        }
        if ($referrer === null && $referrerType === 'website') {
            $referrer = $this->cleanVal($visit['referrerName'] ?? null);
        }

        $campaign = $this->withoutEmpty([
            'utm_source' => $visit['campaignSource'] ?? null,
            'utm_medium' => $visit['campaignMedium'] ?? null,
            'utm_campaign' => $visit['campaignName'] ?? null,
            'utm_content' => $visit['campaignContent'] ?? null,
            'utm_term' => $visit['campaignKeyword'] ?? null,
        ]);
        if ($referrerType === 'campaign' && !isset($campaign['utm_campaign'])) {
            $campaign += $this->withoutEmpty([
                'utm_campaign' => $visit['referrerName'] ?? null,
                'utm_term' => $visit['referrerKeyword'] ?? null,
            ]);
        }
        return ['referrer' => $referrer] + $campaign;
    }

    /**
     * Turns action times into UTC dates. Matomo gives the visit start in UTC but, depending on the
     * version, action times in the website timezone; anchoring on the visit start handles both.
     *
     * @param array<string, mixed> $visit
     * @param list<array<string, mixed>> $actions
     * @return callable(array<string, mixed>): string
     */
    private function clock(array $visit, array $actions): callable
    {
        $actionTimes = array_filter(array_map(static fn(array $a) => is_numeric($a['timestamp'] ?? null) ? (int) $a['timestamp'] : null, $actions));
        $visitStart = is_numeric($visit['firstActionTimestamp'] ?? null) ? (int) $visit['firstActionTimestamp'] : null;
        if ($visitStart === null && is_numeric($visit['serverTimestamp'] ?? null)) {
            $visitStart = (int) $visit['serverTimestamp'];
        }
        $offset = ($visitStart !== null && $actionTimes !== []) ? $visitStart - min($actionTimes) : 0;
        $fallback = $visitStart ?? (strtotime(((string) ($visit['serverDate'] ?? '')) . ' UTC') ?: time());

        return static fn(array $action): string => gmdate(
            'Y-m-d H:i:s',
            is_numeric($action['timestamp'] ?? null) ? (int) $action['timestamp'] + $offset : $fallback,
        );
    }

    /** @param array<string, mixed> $visit */
    private function visitorId(array $visit, string $sessionId): string
    {
        // A missing visitor ID is false in JSON and 0 in CSV.
        $visitorId = $visit['visitorId'] ?? null;
        if (is_string($visitorId) && preg_match('/^[0-9a-f]{16}$/i', $visitorId)) {
            return 'matomo_' . $visitorId;
        }
        $userId = $this->cleanVal($visit['userId'] ?? null);
        if (is_string($userId)) {
            // User IDs are often emails: keep them linkable without storing them.
            return 'matomo_' . substr(hash('sha256', $userId), 0, 16);
        }
        return $sessionId;
    }

    /** @return array<string, mixed> */
    private function pageData(mixed $url, mixed $title): array
    {
        $url = is_string($url) ? $url : '';
        $parts = $url !== '' ? (parse_url($url) ?: []) : [];
        return [
            'path' => ($parts['path'] ?? '') ?: '/',
            'title' => $this->cleanVal($title),
            'url' => $url !== '' ? $url : null,
            'hostname' => $parts['host'] ?? null,
            'search' => $parts['query'] ?? null,
            'hash' => isset($parts['fragment']) ? '#' . $parts['fragment'] : null,
        ];
    }

    /**
     * The page of the action itself when Matomo records one, else the last page viewed.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function pageOrUrl(array $page, array $action): array
    {
        $url = $action['url'] ?? null;
        return is_string($url) && $url !== '' ? $this->pageData($url, $page['title'] ?? null) : $page;
    }

    /**
     * Custom variables (name => value) and custom dimensions of a visit or an action.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function customValues(array $source, string $namePrefix, string $valuePrefix): array
    {
        $values = [];
        foreach (is_array($source['customVariables'] ?? null) ? $source['customVariables'] : [] as $index => $variable) {
            $name = $this->cleanVal($variable[$namePrefix . $index] ?? null);
            if (is_string($name)) {
                $values[$name] = $variable[$valuePrefix . $index] ?? null;
            }
        }
        foreach ($source as $key => $value) {
            if (preg_match('/^dimension\d+$/', $key) && $this->cleanVal($value) !== null) {
                $values[$key] = $value;
            }
        }
        return $values;
    }

    /** @param array<string, mixed> $action */
    private function productNames(array $action): ?string
    {
        $names = [];
        foreach (is_array($action['itemDetails'] ?? null) ? $action['itemDetails'] : [] as $item) {
            if (is_array($item) && ($name = $this->cleanVal($item['itemName'] ?? null)) !== null) {
                $names[] = (string) $name;
            }
        }
        return $names !== [] ? implode(', ', $names) : null;
    }

    public function matomoBrowser(mixed $name): string
    {
        $raw = trim((string) $name);
        $b = strtolower($raw);
        return match (true) {
            $b === '' || $b === 'unknown' => 'Unknown',
            str_contains($b, 'edge') => 'Microsoft Edge',
            str_contains($b, 'samsung') => 'Samsung Internet',
            str_contains($b, 'opera') => 'Opera',
            str_contains($b, 'firefox') => 'Firefox',
            str_contains($b, 'chrom') => 'Chrome',
            str_contains($b, 'safari') => 'Safari',
            default => $raw,
        };
    }

    public function matomoOs(mixed $name): string
    {
        $raw = trim((string) $name);
        $o = strtolower($raw);
        return match (true) {
            $o === '' || $o === 'unknown' => 'Unknown',
            str_starts_with($o, 'windows') => 'Windows',
            $o === 'mac' || str_contains($o, 'mac os') || $o === 'macos' => 'macOS',
            $o === 'ios' || $o === 'ipados' => 'iOS',
            str_contains($o, 'android') => 'Android',
            str_contains($o, 'chrome os') || $o === 'chromeos' => 'Chrome OS',
            in_array($o, ['gnu/linux', 'linux', 'ubuntu', 'debian', 'fedora', 'arch linux', 'mint'], true) => 'Linux',
            default => $raw,
        };
    }

    public function matomoDevice(mixed $type): string
    {
        $raw = trim((string) $type);
        $d = strtolower($raw);
        return match (true) {
            in_array($d, ['smartphone', 'phablet', 'feature phone', 'wearable'], true) => 'Mobile',
            $d === 'tablet' => 'Tablet',
            $d === '' || $d === 'unknown' || $d === 'desktop' => 'Desktop',
            default => $raw,
        };
    }

    /**
     * Runs $work on the export files of a file, folder or .zip archive.
     *
     * @template T
     * @param callable(list<string>): T $work
     * @return T
     */
    private function withSource(string $sourcePath, callable $work): mixed
    {
        $tempDir = null;
        $workPath = $sourcePath;
        if ($this->isZipArchiveFile($sourcePath)) {
            $tempDir = sys_get_temp_dir() . '/petitstats_matomo_' . uniqid();
            $this->extractZip($sourcePath, $tempDir);
            $workPath = $tempDir;
        }
        try {
            $files = $this->findFiles($workPath);
            if ($files === []) {
                throw new RuntimeException('No Matomo visits export (.json or .csv) was found.');
            }
            return $work($files);
        } finally {
            if ($tempDir) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    /** Matomo writes CSV exports in UTF-16 with a byte order mark. */
    private function toUtf8(string $raw): string
    {
        $from = match (true) {
            str_starts_with($raw, "\xFF\xFE") => 'UTF-16LE',
            str_starts_with($raw, "\xFE\xFF") => 'UTF-16BE',
            default => null,
        };
        if ($from === null) {
            return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
        }
        $body = substr($raw, 2);
        $converted = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($body, 'UTF-8', $from)
            : iconv($from, 'UTF-8', $body);
        if (!is_string($converted)) {
            throw new RuntimeException('Unable to read the export encoding.');
        }
        return $converted;
    }

    /** @return list<array<string, mixed>> */
    private function decodeJson(string $json, string $fileName): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("{$fileName} is not valid JSON.");
        }
        if (($decoded['result'] ?? null) === 'error') {
            throw new RuntimeException("{$fileName} holds a Matomo error instead of visits: " . ($decoded['message'] ?? 'unknown error'));
        }
        return array_values(array_filter($decoded, static fn($visit): bool => is_array($visit) && isset($visit['actionDetails'])));
    }

    /**
     * Matomo flattens nested values into columns such as actionDetails_0_url; rebuild the nesting.
     *
     * @return iterable<array<string, mixed>>
     */
    private function readCsv(string $csv): iterable
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new RuntimeException('Unable to read the CSV export.');
        }
        fwrite($handle, $csv);
        rewind($handle);
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!$header || !in_array('idVisit', $header, true)) {
                throw new RuntimeException('This CSV file is not a Matomo visits export (no idVisit column).');
            }
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                $visit = [];
                $actions = [];
                foreach ($header as $index => $column) {
                    $value = $row[$index] ?? '';
                    if ($value === '') {
                        continue;
                    }
                    // Matomo quotes values that a spreadsheet would read as a formula.
                    if (preg_match("/^'[=+\\-@]/", $value)) {
                        $value = substr($value, 1);
                    }
                    if (preg_match('/^actionDetails_(\d+)_(.+)$/', $column, $m)) {
                        $actions[(int) $m[1]][$m[2]] = $value;
                    } elseif (preg_match('/^(.+) \((\w+) (\d+)\)$/', $column, $m)) {
                        // Exports from the Matomo interface name nested columns "url (actionDetails 0)".
                        if ($m[2] === 'actionDetails') {
                            $actions[(int) $m[3]][$m[1]] = $value;
                        } else {
                            $visit["{$m[2]}_{$m[3]}_{$m[1]}"] = $value;
                        }
                    } else {
                        $visit[$column] = $value;
                    }
                }
                ksort($actions);
                $visit = $this->nestLists($visit);
                $visit['actionDetails'] = array_map(fn(array $action): array => $this->nestLists($action), array_values($actions));
                yield $visit;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function nestLists(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (preg_match('/^(customVariables|itemDetails)_(\d+)_(.+)$/', $key, $m)) {
                $out[$m[1]][$m[2]][$m[3]] = $value;
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private function hostOf(mixed $url): ?string
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        return is_string($host) && $host !== '' ? $host : null;
    }

    private function number(mixed $value): int|float|null
    {
        if (!is_numeric($value)) {
            return null;
        }
        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    private function flag(mixed $value): ?bool
    {
        return $value === null || $value === '' ? null : (bool) (int) $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $values): array
    {
        return array_filter($values, fn($value): bool => $this->cleanVal($value) !== null);
    }
}
