<?php

declare(strict_types=1);

namespace Petitstats\Tests\Tracking;

use Petitstats\Database\Connections;
use Petitstats\Database\DatabaseConnection;
use Petitstats\Database\DataDirectory;
use Petitstats\Tracking\FingerprintCleanup;
use PHPUnit\Framework\TestCase;

/** The cleanup of November 25, 2026, and bin/requalify-bots.php. */
final class FingerprintCleanupTest extends TestCase
{
    private const DAY = '2026-11-25';
    private const PROFILE = ['country' => 'US', 'os' => 'Linux', 'browser' => 'Chrome', 'language' => 'en-US'];

    private DatabaseConnection $db;

    protected function setUp(): void
    {
        $this->db = Connections::forSite('test_site');
        $this->db->exec('DELETE FROM user_activity');
        $this->db->exec('DELETE FROM bot_activity');

        // The crawler: 4 articles in a few seconds, one with the tracker's own engagement event.
        foreach ([1, 2, 3, 4] as $i) {
            $this->visit("crawl{$i}", "06:03:1{$i}", ['path' => "/blog/article-{$i}"]);
        }
        $this->record('crawl1', 'crawl1', '_ml_engaged', self::DAY . ' 06:03:14', ['path' => '/blog/article-1']);
        // People.
        $this->visit('iphone', '08:00:00', ['country_code' => 'FR', 'os' => 'iOS', 'browser' => 'Safari', 'language' => 'fr-FR', '_ml_confirm' => 'interaction']);
        $this->visit('reader', '09:00:00', ['_ml_confirm' => 'interaction']);
        $this->visit('referred', '09:10:00', ['referrer' => 'www.google.com']);
        $this->visit('clicker', '09:20:00');
        $this->record('clicker', 'clicker', 'signup', self::DAY . ' 09:20:02');
        $this->visit('browser', '09:30:00', ['path' => '/a']);
        $this->record('browser', 'browser', 'pageview', self::DAY . ' 09:30:05', ['path' => '/b']);
        $this->visit('slow', '09:40:00');
        $this->record('slow', 'slow', '_ml_engaged', self::DAY . ' 09:40:30');
        $this->visit('returning', '10:00:00');
        $this->record('returning-before', 'returning', 'pageview', '2026-11-20 10:00:00', ['path' => '/']);
        $this->visit('other-day', '00:00:00', [], '2026-11-24');
    }

    public function testFindsOnlyTheCrawlerVisits(): void
    {
        $matches = (new FingerprintCleanup($this->db))->matches(self::DAY, self::PROFILE);

        $this->assertSame(['crawl1', 'crawl2', 'crawl3', 'crawl4'], array_column($matches, 'session_id'));
        $this->assertSame(5, array_sum(array_column($matches, 'rows')));
    }

    public function testARealFrenchIphoneVisitNeverMatchesWhateverTheProfile(): void
    {
        $matches = (new FingerprintCleanup($this->db))->matches(self::DAY, ['country' => 'FR', 'os' => 'iOS', 'browser' => 'Safari']);

        $this->assertSame([], $matches);
    }

    public function testDryRunShowsTheVisitsAndChangesNothing(): void
    {
        $before = $this->counts();
        [$status, $output] = $this->runScript([]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('4 matching visits (5 stored events)', $output);
        $this->assertStringContainsString('06:00    4', $output);
        $this->assertStringContainsString('Dry run: nothing changed', $output);
        $this->assertSame($before, $this->counts());
    }

    public function testApplyingMovesTheVisitsOnceConfirmed(): void
    {
        [$status, $output] = $this->runScript(['--apply'], "yes\n");

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('4 visits moved to the blocked traffic', $output);
        $this->assertSame(['user_activity' => 12, 'bot_activity' => 4], $this->counts());
        $this->assertSame(4, (int) $this->db->querySingle("SELECT COUNT(*) FROM bot_activity WHERE reason = 'manual:fingerprint' AND country_code = 'US' AND origin = 'example.com' AND timestamp LIKE '2026-11-25 06:03:1%'"));
        $this->assertSame(0, (int) $this->db->querySingle("SELECT COUNT(*) FROM user_activity WHERE session_id LIKE 'crawl%'"));

        preg_match('/Backup of the database: (\S+)/', $output, $backup);
        preg_match('/Events about to move: (\S+)/', $output, $export);
        $this->assertFileExists($backup[1]);
        $this->assertCount(5, json_decode((string) file_get_contents($export[1]), true));
        $copy = new \SQLite3($backup[1]);
        $this->assertSame(17, $copy->querySingle('SELECT COUNT(*) FROM user_activity'), 'the backup holds the visits as they were');
        $copy->close();
    }

    public function testAnythingButYesCancels(): void
    {
        $before = $this->counts();
        [$status, $output] = $this->runScript(['--apply'], "y\n");

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Cancelled: nothing changed.', $output);
        $this->assertSame($before, $this->counts());
    }

    public function testRefusesToRunWithoutAProfile(): void
    {
        [$status, $output] = $this->runScript(['--site=test_site', '--date=' . self::DAY], profile: false);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Give at least one of', $output);
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private function runScript(array $arguments, string $input = '', bool $profile = true): array
    {
        $defaults = $profile ? ['--site=test_site', '--date=' . self::DAY, '--country=US', '--os=Linux', '--browser=Chrome', '--language=en-US'] : [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/requalify-bots.php', ...$defaults, ...$arguments],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            ['PETITSTATS_DATA_DIR' => DataDirectory::path()] + getenv(),
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $output];
    }

    /** @return array{user_activity: int, bot_activity: int} */
    private function counts(): array
    {
        return [
            'user_activity' => (int) $this->db->querySingle('SELECT COUNT(*) FROM user_activity'),
            'bot_activity' => (int) $this->db->querySingle('SELECT COUNT(*) FROM bot_activity'),
        ];
    }

    /** @param array<string, string> $data overrides the crawler's profile */
    private function visit(string $id, string $time, array $data = [], string $day = self::DAY): void
    {
        $this->record($id, $id, 'pageview', "{$day} {$time}", $data + [
            'path' => "/{$id}", 'hostname' => 'example.com', 'referrer' => null, 'language' => 'en-US',
            'browser' => 'Chrome', 'os' => 'Linux', 'device' => 'Desktop', 'country_code' => 'US', 'city' => 'North Charleston',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function record(string $session, string $visitor, string $name, string $timestamp, array $data = []): void
    {
        $stmt = $this->db->prepare('INSERT INTO user_activity (session_id, visitor_id, action, timestamp) VALUES (:s, :v, :a, :t)');
        $stmt->bindValue(':s', $session, SQLITE3_TEXT);
        $stmt->bindValue(':v', $visitor, SQLITE3_TEXT);
        $stmt->bindValue(':a', (string) json_encode(['site_id' => 'test_site', 'name' => $name, 'data' => $data]), SQLITE3_TEXT);
        $stmt->bindValue(':t', $timestamp, SQLITE3_TEXT);
        $stmt->execute();
    }
}
