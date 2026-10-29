<?php

declare(strict_types=1);

namespace Petitstats\Sites;

use Petitstats\Database\Connections;
use Petitstats\Database\DatabaseConnection;
use Petitstats\Database\DataDirectory;
use Petitstats\Database\Schema;
use Throwable;

/**
 * Made-up traffic for the live demo website, kept current on its own.
 *
 * A website with "demo_days" set holds a rolling window of that many days of
 * visits. Each time someone looks at it, the hours since the last visit are
 * filled in, a few visitors are kept "live" and visits that left the window
 * are deleted, so the demo never shows stale data and needs no cron job.
 */
final class DemoTraffic
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 365;

    /** Seconds between two refreshes: one per minute is enough to keep the live counter moving. */
    private const REFRESH_EVERY = 60;

    /** Visits on an ordinary weekday. */
    private const VISITS_PER_DAY = 45;

    /** Robots rejected per day, for the blocked traffic report. */
    private const BOTS_PER_DAY = 6;

    private const PAGES = [
        '/' => 'Petitstats: private, GDPR-friendly web analytics',
        '/install.html' => 'Install Petitstats',
        '/docs/' => 'Documentation · Petitstats',
        '/docs/tracking/' => 'Tracking guide · Petitstats',
        '/docs/importing/' => 'Importing data · Petitstats',
        '/docs/privacy/' => 'Privacy & GDPR · Petitstats',
        '/docs/mcp/' => 'MCP server · Petitstats',
        '/blog/cookieless-analytics/' => 'Why cookieless analytics matter · Petitstats',
        '/blog/shared-hosting/' => 'Self-hosting analytics on shared hosting · Petitstats',
        '/changelog/' => 'Changelog · Petitstats',
    ];

    // [weight, referrer host|null, utm parameters]
    private const SOURCES = [
        [30, null, []],
        [22, 'google.com', []],
        [14, 'github.com', []],
        [8, 'twitter.com', []],
        [7, 'news.ycombinator.com', []],
        [7, null, ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'monthly-release']],
        [5, 'reddit.com', []],
        [4, 'duckduckgo.com', []],
        [2, 'alternativeto.net', []],
        [2, 'linkedin.com', ['utm_source' => 'linkedin', 'utm_medium' => 'social', 'utm_campaign' => 'launch']],
        [2, 'bing.com', []],
    ];

    private const SEARCH_ENGINES = ['google.com', 'duckduckgo.com', 'bing.com'];

    // Landing pages per source family: search lands on docs/blog, the rest on home.
    private const LANDINGS = [
        'search' => ['/' => 40, '/docs/' => 15, '/docs/tracking/' => 10, '/docs/privacy/' => 10, '/blog/cookieless-analytics/' => 15, '/blog/shared-hosting/' => 10],
        'default' => ['/' => 75, '/install.html' => 10, '/blog/cookieless-analytics/' => 8, '/changelog/' => 7],
    ];

    // Next-page transitions ('' = exit).
    private const NEXT = [
        '/' => ['/install.html' => 40, '/docs/' => 15, '/blog/cookieless-analytics/' => 7, '/changelog/' => 5, '' => 33],
        '/install.html' => ['/docs/' => 18, '/docs/tracking/' => 22, '/' => 8, '' => 52],
        '/docs/' => ['/docs/tracking/' => 30, '/docs/importing/' => 18, '/docs/privacy/' => 12, '/docs/mcp/' => 10, '/install.html' => 12, '' => 18],
        '/docs/tracking/' => ['/docs/privacy/' => 15, '/install.html' => 20, '/docs/importing/' => 10, '' => 55],
        '/docs/importing/' => ['/install.html' => 25, '/docs/' => 10, '' => 65],
        '/docs/privacy/' => ['/install.html' => 20, '/' => 10, '' => 70],
        '/docs/mcp/' => ['/install.html' => 30, '/docs/' => 10, '' => 60],
        '/blog/cookieless-analytics/' => ['/' => 35, '/blog/shared-hosting/' => 15, '/install.html' => 10, '' => 40],
        '/blog/shared-hosting/' => ['/install.html' => 30, '/' => 15, '' => 55],
        '/changelog/' => ['/install.html' => 25, '/' => 15, '' => 60],
    ];

    // [weight, code, name, [cities]]
    private const COUNTRIES = [
        [28, 'FR', 'France', ['Paris', 'Lyon', 'Toulouse', 'Bordeaux', 'Lille', 'Nantes']],
        [20, 'US', 'United States', ['New York', 'San Francisco', 'Seattle', 'Austin', 'Chicago']],
        [10, 'DE', 'Germany', ['Berlin', 'Munich', 'Hamburg', 'Cologne']],
        [8, 'GB', 'United Kingdom', ['London', 'Manchester', 'Edinburgh']],
        [5, 'CA', 'Canada', ['Montreal', 'Toronto', 'Vancouver']],
        [4, 'NL', 'Netherlands', ['Amsterdam', 'Rotterdam', 'Utrecht']],
        [4, 'ES', 'Spain', ['Madrid', 'Barcelona', 'Valencia']],
        [4, 'IT', 'Italy', ['Milan', 'Rome', 'Turin']],
        [4, 'IN', 'India', ['Bengaluru', 'Mumbai', 'Pune']],
        [3, 'BE', 'Belgium', ['Brussels', 'Ghent']],
        [3, 'CH', 'Switzerland', ['Zurich', 'Geneva', 'Lausanne']],
        [3, 'BR', 'Brazil', ['São Paulo', 'Rio de Janeiro']],
        [2, 'JP', 'Japan', ['Tokyo', 'Osaka']],
        [2, 'SE', 'Sweden', ['Stockholm', 'Gothenburg']],
    ];

    // [weight, device, browser, os, screen]
    private const CLIENTS = [
        [26, 'Desktop', 'Chrome', 'Windows', '1920×1080'],
        [14, 'Desktop', 'Chrome', 'macOS', '1512×982'],
        [12, 'Desktop', 'Safari', 'macOS', '1440×900'],
        [11, 'Desktop', 'Firefox', 'Linux', '2560×1440'],
        [5, 'Desktop', 'Firefox', 'Windows', '1920×1080'],
        [4, 'Desktop', 'Microsoft Edge', 'Windows', '1536×864'],
        [13, 'Mobile', 'Safari', 'iOS', '393×852'],
        [11, 'Mobile', 'Chrome', 'Android', '412×915'],
        [2, 'Mobile', 'Samsung Internet', 'Android', '384×854'],
        [2, 'Tablet', 'Safari', 'iOS', '820×1180'],
    ];

    private const LANGUAGES = ['FR' => 'fr-FR', 'US' => 'en-US', 'DE' => 'de-DE', 'GB' => 'en-GB', 'CA' => 'en-CA', 'NL' => 'nl-NL', 'ES' => 'es-ES', 'IT' => 'it-IT', 'IN' => 'en-IN', 'BE' => 'fr-BE', 'CH' => 'de-CH', 'BR' => 'pt-BR', 'JP' => 'ja-JP', 'SE' => 'sv-SE'];

    // [weight, reason, user agent, country]
    private const BOTS = [
        [30, 'known_bot_user_agent', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/130.0.0.0 Safari/537.36', 'US'],
        [15, 'known_bot_user_agent', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/130.0.0.0 Safari/537.36', 'US'],
        [20, 'known_bot_user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0.0.0 Safari/537.36', 'DE'],
        [10, 'known_bot_user_agent', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot)', 'US'],
        [10, 'client:webdriver', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'NL'],
        [5, 'missing_user_agent', '', 'FR'],
    ];

    // Hour-of-day traffic shape (UTC, European audience peaking mid-afternoon).
    private const HOURLY = [1, 1, 1, 1, 1, 2, 3, 5, 7, 9, 10, 10, 9, 10, 11, 11, 10, 9, 8, 7, 6, 4, 3, 2];

    /** Number of days a demo website keeps, or 0 when it is not a demo. */
    public static function days(?array $site): int
    {
        return max(0, min(self::MAX_DAYS, (int) ($site['demo_days'] ?? 0)));
    }

    /**
     * Brings a demo website up to date: fills in the visits since the last
     * one, keeps a few visitors live and deletes what left the window. Does
     * nothing for other websites, or when another request is already on it.
     * Returns the number of visits added.
     */
    public static function refresh(string $siteId, ?int $now = null): int
    {
        $site = SiteRegistry::find($siteId);
        $days = self::days($site);
        if ($days === 0) {
            return 0;
        }
        $now ??= time();
        $siteId = (string) $site['id'];

        // The state file holds the time of the last refresh, and its lock makes
        // a second request arriving meanwhile skip instead of adding the same hours twice.
        $state = DataDirectory::path() . "/.demo-{$siteId}";
        if ($now - (int) @file_get_contents($state) < self::REFRESH_EVERY) {
            return 0;
        }
        $lock = @fopen($state, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        try {
            $lastRefresh = (int) stream_get_contents($lock);
            if ($now - $lastRefresh < self::REFRESH_EVERY) {
                return 0;
            }
            $db = Connections::forSite($siteId);
            $windowStart = $now - $days * 86400;
            // Without a record of the last refresh (data seeded by hand), carry on from the latest visit.
            $latest = $db->querySingle('SELECT MAX(timestamp) FROM user_activity');
            $from = $latest === null ? $windowStart : max($windowStart, $lastRefresh ?: (int) strtotime($latest . ' UTC'));

            $db->exec('BEGIN');
            try {
                $visits = (new self($db, $siteId, $now))->fill($from);
                $cutoff = gmdate('Y-m-d H:i:s', $windowStart);
                $db->exec("DELETE FROM user_activity WHERE timestamp < '{$cutoff}'");
                $db->exec("DELETE FROM bot_activity WHERE timestamp < '{$cutoff}'");
                $db->exec('COMMIT');
            } catch (Throwable $e) {
                $db->exec('ROLLBACK');
                throw $e;
            }
            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, (string) $now);
            return $visits;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Deletes the demo traffic of a website, so the next refresh fills the whole window again. */
    public static function clear(string $siteId): void
    {
        $db = Connections::forSite($siteId);
        $db->exec('DELETE FROM user_activity');
        $db->exec('DELETE FROM bot_activity');
        @unlink(DataDirectory::path() . "/.demo-{$siteId}");
    }

    /** @var list<string> */
    private array $visitors = [];

    private string $version;

    private function __construct(private DatabaseConnection $db, private string $siteId, private int $now)
    {
        $this->version = trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: 'v1.0.0';
    }

    /** Adds the visits and robots from $from to now, then the live visitors. */
    private function fill(int $from): int
    {
        $visits = 0;
        $this->loadVisitors();
        for ($hour = intdiv($from, 3600) * 3600; $hour < $this->now; $hour += 3600) {
            $start = max($from, $hour);
            $end = min($this->now, $hour + 3600);
            $share = ($end - $start) / 3600 * self::HOURLY[(int) gmdate('G', $hour)] / array_sum(self::HOURLY);
            $volume = self::dayVolume($hour);

            for ($i = self::roll(self::VISITS_PER_DAY * $volume * $share); $i > 0; $i--) {
                $visits += $this->storeVisit(mt_rand($start, $end - 1));
            }
            for ($i = self::roll(self::BOTS_PER_DAY * ($end - $start) / 86400); $i > 0; $i--) {
                $this->storeBot(mt_rand($start, $end - 1));
            }
        }

        // The live counter shows the visitors of the last five minutes.
        $live = (int) $this->db->querySingle("SELECT COUNT(DISTINCT session_id) FROM user_activity WHERE timestamp >= '" . gmdate('Y-m-d H:i:s', $this->now - 300) . "'");
        for ($i = mt_rand(2, 5) - $live; $i > 0; $i--) {
            $visits += $this->storeVisit($this->now - mt_rand(20, 240));
        }
        return $visits;
    }

    /**
     * How busy a day is compared to an ordinary weekday: quieter weekends,
     * some noise, and now and then a launch day picked up by the news. Derived
     * from the date, so the same day always gets the same volume.
     */
    private static function dayVolume(int $timestamp): float
    {
        $date = gmdate('Y-m-d', $timestamp);
        $seed = crc32('demo-' . $date);
        $volume = (int) gmdate('N', $timestamp) >= 6 ? 0.6 : 1.0;
        $volume *= 0.85 + ($seed % 31) / 100;
        if ($seed % 25 === 0) {
            $volume *= 3.2;
        } elseif (crc32('demo-' . gmdate('Y-m-d', $timestamp - 86400)) % 25 === 0) {
            $volume *= 1.8;
        }
        return $volume;
    }

    /** A whole number of events whose average is $expected. */
    private static function roll(float $expected): int
    {
        $count = (int) floor($expected);
        return $count + (mt_rand() / mt_getrandmax() < $expected - $count ? 1 : 0);
    }

    /** Recent visitors, so about a quarter of the new visits come from returning ones. */
    private function loadVisitors(): void
    {
        $result = $this->db->query('SELECT DISTINCT visitor_id FROM user_activity WHERE visitor_id IS NOT NULL ORDER BY visitor_id LIMIT 300');
        while ($result && ($row = $result->fetchArray(SQLITE3_NUM))) {
            $this->visitors[] = (string) $row[0];
        }
    }

    private function visitor(): string
    {
        if ($this->visitors && mt_rand(1, 100) <= 25) {
            return $this->visitors[array_rand($this->visitors)];
        }
        $id = 'vid_' . bin2hex(random_bytes(6));
        $this->visitors[] = $id;
        return $id;
    }

    /** Stores one visit starting at $start and returns 1, or 0 when none of it happened yet. */
    private function storeVisit(int $start): int
    {
        $hits = $this->visit($start);
        if (!$hits) {
            return 0;
        }
        $session = bin2hex(random_bytes(16));
        $visitor = $this->visitor();
        $insert = $this->db->prepare('INSERT INTO user_activity (session_id, visitor_id, action, timestamp) VALUES (:session, :visitor, :action, :ts)');
        foreach ($hits as [$ts, $name, $data]) {
            $insert->bindValue(':session', $session, SQLITE3_TEXT);
            $insert->bindValue(':visitor', $visitor, SQLITE3_TEXT);
            $insert->bindValue(':action', json_encode(['site_id' => $this->siteId, 'name' => $name, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
            $insert->bindValue(':ts', gmdate('Y-m-d H:i:s', $ts), SQLITE3_TEXT);
            $insert->execute();
        }
        return 1;
    }

    private function storeBot(int $ts): void
    {
        [, $reason, $userAgent, $country] = self::pick(self::BOTS);
        $located = Schema::hasBotCountry($this->db);
        $insert = $this->db->prepare($located
            ? 'INSERT INTO bot_activity (reason, user_agent, origin, ip_hash, country_code, timestamp) VALUES (:reason, :ua, :origin, :ip, :country, :ts)'
            : 'INSERT INTO bot_activity (reason, user_agent, origin, ip_hash, timestamp) VALUES (:reason, :ua, :origin, :ip, :ts)');
        $insert->bindValue(':reason', $reason, SQLITE3_TEXT);
        $insert->bindValue(':ua', $userAgent, SQLITE3_TEXT);
        $insert->bindValue(':origin', 'localhost', SQLITE3_TEXT);
        $insert->bindValue(':ip', hash('sha256', random_bytes(8)), SQLITE3_TEXT);
        if ($located) {
            $insert->bindValue(':country', $country, SQLITE3_TEXT);
        }
        $insert->bindValue(':ts', gmdate('Y-m-d H:i:s', $ts), SQLITE3_TEXT);
        $insert->execute();
    }

    /** One visit (list of [timestamp, name, data]) starting at $start, cut at the current time. */
    private function visit(int $start): array
    {
        [, $referrer, $utm] = self::pick(self::SOURCES);
        [, $code, $country, $cities] = self::pick(self::COUNTRIES);
        [, $device, $browser, $os, $screen] = self::pick(self::CLIENTS);
        $path = self::pickKey(self::LANDINGS[in_array($referrer, self::SEARCH_ENGINES, true) ? 'search' : 'default']);

        $base = [
            'hostname' => 'localhost',
            'language' => self::LANGUAGES[$code] ?? 'en-US',
            'screen' => $screen,
            '_ml_tracking_mode' => 'strict',
            'browser' => $browser,
            'os' => $os,
            'device' => $device,
            'country' => $country,
            'country_code' => $code,
            'region' => '',
            'city' => $cities[array_rand($cities)],
        ];

        $hits = [];
        $time = $start;
        $previous = $referrer;
        $engaged = false;
        for ($depth = 0; $path !== null && $depth < 7 && $time <= $this->now; $depth++) {
            $data = ['path' => $path, 'title' => self::PAGES[$path], 'referrer' => $previous] + $base;
            $hits[] = [$time, 'pageview', $depth === 0 ? $data + $utm : $data];
            $eventBase = ['path' => $path, 'title' => self::PAGES[$path], 'referrer' => 'localhost'] + $base;

            // Engagement ping after ~10 s on most visits.
            if (!$engaged && mt_rand(1, 100) <= 72) {
                $engaged = true;
                $hits[] = [$time + 10, '_ml_engaged', $eventBase];
            }

            // Custom events tied to the page being read.
            if ($path === '/install.html' && mt_rand(1, 100) <= 55) {
                $hits[] = [$time + mt_rand(15, 60), 'copy_snippet', $eventBase + ['snippet' => 'install-command']];
                if (mt_rand(1, 100) <= 62) {
                    $hits[] = [$time + mt_rand(61, 140), 'download_zip', $eventBase + ['file_name' => 'petitstats.tar.gz', 'version' => $this->version]];
                }
            }
            // Readers sponsor the project or buy a support plan, for the Revenue page.
            if (in_array($path, ['/install.html', '/changelog/'], true) && mt_rand(1, 100) <= 14) {
                $hits[] = [$time + mt_rand(90, 200), 'sponsor', $eventBase + ['_ml_revenue' => [5, 10, 10, 25, 50][mt_rand(0, 4)], 'currency' => 'EUR', 'order_id' => sprintf('sp_%08x', mt_rand())]];
            }
            if (str_starts_with($path, '/docs/') && mt_rand(1, 100) <= 4) {
                $hits[] = [$time + mt_rand(60, 180), 'support_plan', $eventBase + ['_ml_revenue' => [49, 49, 99, 249][mt_rand(0, 3)], 'currency' => 'EUR', 'order_id' => sprintf('sup_%08x', mt_rand())]];
            }
            if (str_starts_with($path, '/docs/') && $path !== '/docs/' && mt_rand(1, 100) <= 30) {
                $hits[] = [$time + mt_rand(20, 90), 'copy_snippet', $eventBase + ['snippet' => 'tracking-script']];
            }
            if (mt_rand(1, 100) <= 9) {
                $hits[] = [$time + mt_rand(5, 40), 'theme_toggle', $eventBase + ['theme' => mt_rand(0, 3) ? 'dark' : 'light']];
            }
            if (mt_rand(1, 100) <= 7) {
                $hits[] = [$time + mt_rand(8, 50), 'outbound_click', $eventBase + ['target_url' => 'https://github.com/axthauvin/petitstats', 'target_host' => 'github.com', 'link_text' => 'Explore on GitHub']];
            }

            $previous = 'localhost';
            $time += mt_rand(25, 210);
            $path = self::pickKey(self::NEXT[$path] ?? ['' => 1]);
        }

        $hits = array_values(array_filter($hits, fn(array $hit): bool => $hit[0] <= $this->now));
        usort($hits, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return $hits;
    }

    private static function pick(array $weighted): array
    {
        $roll = mt_rand(1, (int) array_sum(array_column($weighted, 0)));
        foreach ($weighted as $row) {
            $roll -= $row[0];
            if ($roll <= 0) {
                return $row;
            }
        }
        return end($weighted);
    }

    /** A key drawn by weight, or null for the '' (exit) key. */
    private static function pickKey(array $weights): ?string
    {
        $roll = mt_rand(1, (int) array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key === '' ? null : (string) $key;
            }
        }
        return null;
    }
}
