<?php

declare(strict_types=1);

namespace Petitstats\Tests\Docs;

use FilesystemIterator;
use Markdown;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 4) . '/scripts/docs/Markdown.php';

/** The documentation website built from docs/ by scripts/build-docs.php. */
final class DocsSiteTest extends TestCase
{
    private static string $site = '';

    public static function setUpBeforeClass(): void
    {
        self::$site = sys_get_temp_dir() . '/petitstats-docs-' . bin2hex(random_bytes(4));
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 4) . '/scripts/build-docs.php') . ' ' . escapeshellarg(self::$site), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }

    public static function tearDownAfterClass(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$site, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir(self::$site);
    }

    public function testHeadingsGetGitHubAnchors(): void
    {
        $markdown = $this->markdown();
        $html = $markdown->render("## Nginx & Caddy\n\n## Data\n\n## Data");

        $this->assertStringContainsString('<h2 id="nginx--caddy">', $html);
        $this->assertStringContainsString('<h2 id="data">', $html);
        $this->assertStringContainsString('<h2 id="data-1">', $html);
    }

    public function testRawHtmlIsAlwaysEscaped(): void
    {
        $html = $this->markdown()->render("Add it to the `<head>` <script>alert(1)</script>\n\n```html\n<script defer></script>\n```");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<code>&lt;head&gt;</code>', $html);
    }

    public function testRendersTheSyntaxTheDocsUse(): void
    {
        $html = $this->markdown()->render(implode("\n", [
            'Some **bold**, *italic* and a [link](tracking/README.md).',
            '',
            '- one',
            '- two',
            '',
            '1. first',
            '2. second',
            '',
            '> A note',
            '',
            '| Tool | Returns |',
            '| --- | --- |',
            '| `list_sites` | Sites |',
        ]));

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<a href="resolved:tracking/README.md">link</a>', $html);
        $this->assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $html);
        $this->assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $html);
        $this->assertStringContainsString('<blockquote><p>A note</p>', $html);
        $this->assertStringContainsString('<td><code>list_sites</code></td>', $html);
    }

    public function testRendersGitHubTaskLists(): void
    {
        $html = $this->markdown()->render("- [x] Umami (ZIP export)\n- [ ] Matomo");

        $this->assertStringContainsString('<ul class="task-list">', $html);
        $this->assertStringContainsString('<li class="task is-done">', $html);
        $this->assertStringContainsString('Umami (ZIP export)</li>', $html);
        $this->assertStringNotContainsString('[x]', $html);
        $this->assertStringNotContainsString('[ ]', $html);
    }

    public function testBuildsEveryPage(): void
    {
        foreach (['', 'tracking/', 'privacy/', 'operations/', 'importing/', 'importing/umami/', 'importing/matomo/', 'mcp/'] as $page) {
            $this->assertFileExists(self::$site . '/' . $page . 'index.html');
        }
        $this->assertFileExists(self::$site . '/_static/search-index.js');
        // The pages carry their text, inline code included.
        $this->assertStringContainsString('call <code>petitstats.track</code>', (string) file_get_contents(self::$site . '/tracking/index.html'));
    }

    public function testNoMarkdownIsLeftUnconverted(): void
    {
        foreach ($this->pages() as $path => $html) {
            $text = (string) preg_replace('#<pre>.*?</pre>#s', '', $html);
            $this->assertDoesNotMatchRegularExpression('/\]\(|\*\*|```|<li>\[[ xX]\]/', $text, $path);
        }
    }

    public function testEveryLinkBetweenPagesReachesAPageAndASection(): void
    {
        $pages = $this->pages();
        foreach ($pages as $path => $html) {
            preg_match_all('/href="(\/docs\/[^"]*)"/', $html, $links);
            foreach ($links[1] as $link) {
                [$url, $anchor] = array_pad(explode('#', $link, 2), 2, null);
                if (str_starts_with($url, '/docs/_static/') || str_starts_with($url, '/docs/assets/')) {
                    $this->assertFileExists(self::$site . substr($url, strlen('/docs')), "{$path} links to {$link}");
                    continue;
                }
                $target = substr($url, strlen('/docs/')) . 'index.html';
                $this->assertArrayHasKey($target, $pages, "{$path} links to {$link}");
                if ($anchor !== null) {
                    $this->assertStringContainsString('id="' . $anchor . '"', $pages[$target], "{$path} links to {$link}");
                }
            }
        }
    }

    private function markdown(): Markdown
    {
        return new Markdown(static fn(string $url): string => 'resolved:' . $url);
    }

    /** @return array<string, string> page path => HTML */
    private function pages(): array
    {
        $pages = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$site, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getFilename() === 'index.html') {
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen(self::$site) + 1));
                $pages[$path] = (string) file_get_contents($file->getPathname());
            }
        }
        return $pages;
    }
}
