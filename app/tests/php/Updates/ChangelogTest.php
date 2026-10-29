<?php

declare(strict_types=1);

namespace Petitstats\Tests\Updates;

use Petitstats\Updates\Changelog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChangelogTest extends TestCase
{
    private const NOTES = <<<'MD'
        ### Quick Install

        * not a change, only install instructions

        ## What's Changed
        * feat: switch geolocation to MaxMind GeoLite2 (closes #101) by @axthauvin in https://github.com/axthauvin/petitstats/pull/106
        * fix(tracker): count visitors who view a page without interacting by @axthauvin in https://github.com/axthauvin/petitstats/pull/109
        * docs: explain the update by @someone in https://github.com/axthauvin/petitstats/pull/112
        * Plain title without a prefix

        ## New Contributors
        * @someone made their first contribution in https://github.com/axthauvin/petitstats/pull/112

        **Full Changelog**: https://github.com/axthauvin/petitstats/compare/v1.4.0-beta...v1.5.0-beta
        MD;

    private string $dir;
    private int $fetches = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/petitstats-changelog-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testReadsTheChangesFromTheGeneratedReleaseNotes(): void
    {
        $this->assertSame([
            ['type' => 'new', 'text' => 'Switch geolocation to MaxMind GeoLite2', 'url' => 'https://github.com/axthauvin/petitstats/pull/106'],
            ['type' => 'fixed', 'text' => 'Count visitors who view a page without interacting', 'url' => 'https://github.com/axthauvin/petitstats/pull/109'],
            ['type' => 'improved', 'text' => 'Explain the update', 'url' => 'https://github.com/axthauvin/petitstats/pull/112'],
            ['type' => 'improved', 'text' => 'Plain title without a prefix', 'url' => null],
        ], Changelog::parseNotes(self::NOTES));
    }

    public function testNotesWithoutAWhatsChangedSectionHaveNoChanges(): void
    {
        $this->assertSame([], Changelog::parseNotes("### Quick Install\n\n* curl …"));
    }

    public function testListsTheReleasesBetweenTwoVersionsNewestFirst(): void
    {
        $versions = array_column($this->changelog()->between('v1.3.0-beta', 'v1.5.0-beta'), 'version');

        $this->assertSame(['v1.5.0-beta', 'v1.4.0-beta'], $versions);
    }

    public function testAnOpenEndListsEveryNewerRelease(): void
    {
        $versions = array_column($this->changelog()->between('v1.4.0-beta', null), 'version');

        $this->assertSame(['v1.6.0-beta', 'v1.5.0-beta'], $versions);
    }

    public function testSkipsDraftReleases(): void
    {
        $versions = array_column($this->changelog()->between(null, null), 'version');

        $this->assertNotContains('v2.0.0', $versions);
    }

    public function testAsksGitHubOnlyOnceWithinTwelveHours(): void
    {
        $changelog = $this->changelog();
        $changelog->between(null, null);
        $changelog->between('v1.3.0-beta', null);

        $this->assertSame(1, $this->fetches);
    }

    public function testAnUnreachableGitHubIsNotAnError(): void
    {
        $changelog = new Changelog($this->dir . '/changelog.json', static fn() => throw new RuntimeException('offline'));

        $this->assertNull($changelog->between(null, null));
    }

    private function changelog(): Changelog
    {
        return new Changelog($this->dir . '/changelog.json', function (): array {
            $this->fetches++;
            $release = static fn(string $tag, array $extra = []): array => $extra + [
                'tag_name' => $tag,
                'html_url' => "https://github.com/axthauvin/petitstats/releases/tag/{$tag}",
                'published_at' => '2026-10-19T17:53:05Z',
                'body' => self::NOTES,
            ];
            return [
                $release('v2.0.0', ['draft' => true]),
                $release('v1.6.0-beta'),
                $release('v1.5.0-beta'),
                $release('v1.4.0-beta'),
                $release('v1.3.0-beta'),
            ];
        });
    }
}
