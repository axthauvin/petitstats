<?php

declare(strict_types=1);

namespace Petitstats\Tests\Updates;

use Petitstats\Updates\UpdateCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpdateCheckTest extends TestCase
{
    private const RELEASE = ['version' => 'v1.2.0', 'url' => 'https://github.com/axthauvin/petitstats/releases/tag/v1.2.0', 'published_at' => '2026-10-01T00:00:00Z'];

    private string $dir;
    private int $fetches = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/petitstats-update-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testReadsTheInstalledVersionFromTheReleaseArchive(): void
    {
        file_put_contents($this->dir . '/VERSION', "v1.1.2-beta\n");

        $this->assertSame('v1.1.2-beta', $this->check()->installed());
    }

    public function testAGitCheckoutHasNoInstalledVersion(): void
    {
        $this->assertNull($this->check()->installed());
    }

    public function testAsksGitHubOnlyOnceWithinTwelveHours(): void
    {
        $check = $this->check();

        $this->assertSame(self::RELEASE, $check->latest());
        $this->assertSame(self::RELEASE, $check->latest());
        $this->assertSame(1, $this->fetches);
    }

    public function testRefreshAsksGitHubAgain(): void
    {
        $check = $this->check();
        $check->latest();
        $check->latest(refresh: true);

        $this->assertSame(2, $this->fetches);
    }

    public function testAnUnreachableGitHubIsNotAnError(): void
    {
        $check = new UpdateCheck($this->dir . '/VERSION', $this->dir . '/cache.json', static fn() => throw new RuntimeException('offline'));

        $this->assertNull($check->latest());
    }

    #[DataProvider('versions')]
    public function testComparesVersions(?string $latest, ?string $installed, bool $newer): void
    {
        $this->assertSame($newer, UpdateCheck::isNewer($latest, $installed));
    }

    /** @return iterable<string, array{?string, ?string, bool}> */
    public static function versions(): iterable
    {
        yield 'patch release' => ['v1.1.3', 'v1.1.2', true];
        yield 'same version' => ['v1.1.2-beta', 'v1.1.2-beta', false];
        yield 'beta to next beta' => ['v1.1.3-beta', 'v1.1.2-beta', true];
        yield 'beta to stable' => ['v1.1.2', 'v1.1.2-beta', true];
        yield 'older release' => ['v1.0.0', 'v1.1.0', false];
        yield 'development checkout' => ['v9.0.0', null, false];
        yield 'GitHub unreachable' => [null, 'v1.0.0', false];
    }

    private function check(): UpdateCheck
    {
        return new UpdateCheck($this->dir . '/VERSION', $this->dir . '/cache.json', function (): array {
            $this->fetches++;
            return self::RELEASE;
        });
    }
}
