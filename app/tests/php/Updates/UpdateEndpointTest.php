<?php

declare(strict_types=1);

namespace Petitstats\Tests\Updates;

use Petitstats\Database\DataDirectory;
use Petitstats\Tests\PhpServer;
use Petitstats\Updates\Updater;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Sends real HTTP requests while an update replaces the files: the guard that
 * runs before every request, and how the installer reads the health check.
 */
final class UpdateEndpointTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    private static ?PhpServer $app = null;
    private static ?PhpServer $answers = null;
    private static string $answersDir;

    private string $work;
    /** @var resource|null the lock of the running update */
    private $lock = null;
    /** @var array<string, mixed> */
    private array $server;

    public static function tearDownAfterClass(): void
    {
        self::$app?->stop();
        self::$answers?->stop();
        self::$app = self::$answers = null;
        if (isset(self::$answersDir)) {
            array_map('unlink', glob(self::$answersDir . '/*') ?: []);
            rmdir(self::$answersDir);
        }
    }

    protected function setUp(): void
    {
        $this->work = DataDirectory::path() . '/updates';
        @mkdir($this->work . '/backup', 0755, true);
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        if ($this->lock !== null) {
            fclose($this->lock);
            $this->lock = null;
        }
        foreach (['state.json', 'maintenance', 'update.lock', 'backup/restore.json'] as $file) {
            @unlink($this->work . '/' . $file);
        }
    }

    public function testTurnsRequestsAwayWhileFilesAreReplaced(): void
    {
        $this->startInstalling();

        $response = $this->request('/track.php', 'POST');

        $this->assertSame(503, $response['status']);
        $this->assertSame("Petitstats is being updated. Try again in a few seconds.\n", $response['body']);
    }

    public function testLetsTheHealthCheckOfTheRunningUpdateThrough(): void
    {
        $this->startInstalling();

        $answer = $this->request('/dashboard/src/api/update.php?health=' . self::TOKEN);

        $this->assertSame(200, $answer['status']);
        $this->assertSame(self::TOKEN, json_decode($answer['body'], true)['health'] ?? null);
        $this->assertSame(503, $this->request('/dashboard/src/api/update.php?health=another-token')['status']);
    }

    public function testAMalformedHealthCheckIsRefusedWithoutAWarning(): void
    {
        $response = $this->request('/dashboard/src/api/update.php?health[]=x');

        $this->assertSame(404, $response['status']);
        $this->assertSame('{"error":"No update is being checked."}', $response['body']);
    }

    public function testTheNextRequestFinishesAnUpdateWhoseProcessDied(): void
    {
        $this->startInstalling();
        // The process holding the lock dies, and the system releases it.
        fclose($this->lock);
        $this->lock = null;

        $this->assertSame(503, $this->request('/track.php', 'POST')['status']);
        $state = json_decode((string) file_get_contents($this->work . '/state.json'), true);
        $this->assertSame('failed', $state['status']);
        $this->assertSame('The update was interrupted before it finished.', $state['error']);
        $this->assertFileDoesNotExist($this->work . '/maintenance');
        $this->assertSame(400, $this->request('/track.php', 'POST')['status']);
    }

    #[DataProvider('healthAnswers')]
    public function testReadsTheHealthCheckAnswer(string $script, ?string $version): void
    {
        $this->assertSame($version, $this->askInstalledVersion($script));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function healthAnswers(): iterable
    {
        yield 'the answer, after a PHP warning' => ['/answer.php', 'v2.0.0'];
        yield 'a redirect to a login page' => ['/login.php', null];
        yield 'a proxy that cannot reach PHP' => ['/proxy.php', null];
        yield 'a firewall' => ['/denied.php', null];
    }

    #[DataProvider('brokenVersions')]
    public function testABrokenVersionFailsTheHealthCheck(string $script): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The new version did not start correctly');

        $this->askInstalledVersion($script);
    }

    /** @return iterable<string, array{string}> */
    public static function brokenVersions(): iterable
    {
        yield 'a fatal error shown on the page' => ['/fatal.php'];
        yield 'a fatal error with errors hidden' => ['/error.php'];
    }

    public function testAServerThatCannotReachItselfSaysNothing(): void
    {
        $_SERVER['HTTP_HOST'] = '127.0.0.1:1';
        $_SERVER['SCRIPT_NAME'] = '/answer.php';

        $this->assertNull((new ReflectionMethod(Updater::class, 'askInstalledVersion'))->getClosure(null)(self::TOKEN));
    }

    /** An update replacing files right now: its state, the maintenance flag, and its lock. */
    private function startInstalling(): void
    {
        // An empty backup, since a recovery restores it over the real app folder.
        file_put_contents($this->work . '/backup/restore.json', json_encode(['version' => 'v1.0.0', 'saved' => (object) [], 'written' => []]));
        file_put_contents($this->work . '/state.json', json_encode(['status' => 'installing', 'from' => 'v1.0.0', 'to' => 'v2.0.0', 'token' => self::TOKEN]));
        touch($this->work . '/maintenance');
        $this->lock = fopen($this->work . '/update.lock', 'c') ?: null;
        $this->assertNotNull($this->lock);
        $this->assertTrue(flock($this->lock, LOCK_EX | LOCK_NB));
    }

    /** @return array{status: int, body: string} */
    private function request(string $path, string $method = 'GET'): array
    {
        self::$app ??= PhpServer::start(dirname(__DIR__, 3));
        $context = stream_context_create(['http' => ['method' => $method, 'header' => 'Content-Type: application/json', 'content' => $method === 'POST' ? '{}' : '', 'ignore_errors' => true]]);
        $body = (string) file_get_contents(self::$app->url . $path, false, $context);
        preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $match);
        return ['status' => (int) ($match[1] ?? 0), 'body' => $body];
    }

    private function askInstalledVersion(string $script): ?string
    {
        self::$answers ??= self::startAnswers();
        $_SERVER['HTTP_HOST'] = (string) parse_url(self::$answers->url, PHP_URL_HOST) . ':' . parse_url(self::$answers->url, PHP_URL_PORT);
        $_SERVER['SCRIPT_NAME'] = $script;
        return (new ReflectionMethod(Updater::class, 'askInstalledVersion'))->getClosure(null)(self::TOKEN);
    }

    /** A server answering the health check in every way a real server might. */
    private static function startAnswers(): PhpServer
    {
        self::$answersDir = sys_get_temp_dir() . '/petitstats-health-' . bin2hex(random_bytes(4));
        mkdir(self::$answersDir);
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        $scripts = [
            'answer.php' => "<?php require {$autoload};\necho \"Deprecated: something in the new version\\n\";\necho json_encode(Petitstats\\Updates\\Updater::healthAnswer(\$_GET['health'], 'v2.0.0'));",
            'fatal.php' => '<?php echo "<b>Fatal error</b>: Uncaught Error in update.php";',
            'error.php' => '<?php http_response_code(500);',
            'proxy.php' => '<?php http_response_code(502);',
            'denied.php' => '<?php http_response_code(403);',
            'login.php' => '<?php header("Location: /page.html", true, 302);',
            'page.html' => '<html><body>Sign in</body></html>',
        ];
        foreach ($scripts as $name => $content) {
            file_put_contents(self::$answersDir . '/' . $name, $content);
        }
        return PhpServer::start(self::$answersDir);
    }
}
