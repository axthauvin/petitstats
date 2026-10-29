<?php

declare(strict_types=1);

namespace Petitstats\Analytics;

use Petitstats\Database\Connections;
use Petitstats\Database\DatabaseConfig;
use Petitstats\Database\DataDirectory;
use Petitstats\Sites\SiteRegistry;
use Throwable;

/** The figures of every website shown on the websites page: totals, live visitors and a 7-day sparkline. */
final class SiteOverview
{
    public static function all(): array
    {
        $sites = SiteRegistry::all();
        $dataDir = DataDirectory::path();
        $sevenDaysAgo = gmdate('Y-m-d 00:00:00', strtotime('-6 days'));

        foreach ($sites as &$site) {
            $dbPath = "{$dataDir}/{$site['id']}.db";
            $views = 0;
            $visitors = 0;
            $visitors7d = 0;
            $live = 0;
            $lastActive = null;

            // 7 daily buckets for the sparkline (day -6 to today)
            $sparklineMap = [];
            for ($i = 6; $i >= 0; $i--) {
                $dayStr = gmdate('Y-m-d', strtotime("-{$i} days"));
                $sparklineMap[$dayStr] = 0;
            }

            if (DatabaseConfig::load()['driver'] !== 'sqlite' || file_exists($dbPath)) {
                try {
                    $db = Connections::forSite($site['id']);
                    $views = (int) $db->querySingle("SELECT COUNT(*) FROM user_activity WHERE json_extract(action, '$.name') = 'pageview'");
                    $visitors = (int) $db->querySingle("SELECT COUNT(DISTINCT COALESCE(visitor_id, session_id)) FROM user_activity");
                    $visitors7d = (int) $db->querySingle("SELECT COUNT(DISTINCT COALESCE(visitor_id, session_id)) FROM user_activity WHERE timestamp >= '{$sevenDaysAgo}'");
                    $liveThresh = gmdate('Y-m-d H:i:s', time() - 300);
                    $live = (int) $db->querySingle("SELECT COUNT(DISTINCT COALESCE(visitor_id, session_id)) FROM user_activity WHERE timestamp >= '{$liveThresh}'");
                    $lastActive = $db->querySingle("SELECT MAX(timestamp) FROM user_activity");

                    // Real daily visitors for the 7 days
                    $spStmt = $db->prepare("SELECT strftime('%Y-%m-%d', timestamp) as day, COUNT(DISTINCT COALESCE(visitor_id, session_id)) as v
                                           FROM user_activity
                                           WHERE timestamp >= :start_date
                                           GROUP BY day");
                    $spStmt->bindValue(':start_date', $sevenDaysAgo, SQLITE3_TEXT);
                    $spRes = $spStmt->execute();
                    while ($spRow = $spRes->fetchArray(SQLITE3_ASSOC)) {
                        if (isset($sparklineMap[$spRow['day']])) {
                            $sparklineMap[$spRow['day']] = (int) $spRow['v'];
                        }
                    }
                } catch (Throwable $e) {
                    // Ignore DB read errors
                }
            }

            $site['pageviews'] = $views;
            $site['visitors'] = $visitors;
            $site['visitors_7d'] = $visitors7d;
            $site['sparkline'] = array_values($sparklineMap);
            $site['live_visitors'] = $live;
            $site['last_active'] = $lastActive;
        }

        return $sites;
    }
}
