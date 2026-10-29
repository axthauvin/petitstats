<?php

declare(strict_types=1);

/*
 * Builds the documentation website from docs/*.md into landing/docs/.
 *
 *   php scripts/build-docs.php [output directory]
 *
 * Plain PHP, no dependency: docs/ stays the single source, edited on GitHub,
 * and the generated pages are static HTML that search engines can read.
 */

require __DIR__ . '/docs/Markdown.php';
require __DIR__ . '/docs/template.php';

const REPOSITORY = 'https://github.com/axthauvin/petitstats';

$root = dirname(__DIR__);
$source = $root . '/docs';
$output = rtrim($argv[1] ?? $root . '/landing/docs', '/\\');

/** Pages in navigation order: slug => [group, Markdown file]. The title is the page's first heading. */
$pages = [
    '' => ['Getting started', 'README.md'],
    'tracking' => ['Getting started', 'tracking/README.md'],
    'privacy' => ['Getting started', 'privacy/README.md'],
    'operations' => ['Running Petitstats', 'operations/README.md'],
    'importing' => ['Running Petitstats', 'importing/README.md'],
    'importing/umami' => ['Running Petitstats', 'importing/umami.md'],
    'importing/matomo' => ['Running Petitstats', 'importing/matomo.md'],
    'mcp' => ['Running Petitstats', 'mcp/README.md'],
];
$slugByFile = array_flip(array_map(static fn(array $page): string => $page[1], $pages));

/** Rewrites a link found in `$file`: other docs become site URLs, the rest of the repository points to GitHub. */
$resolve = static function (string $url, string $file) use ($slugByFile): string {
    if (preg_match('#^([a-z]+:|//|\#)#i', $url)) {
        return $url;
    }
    [$path, $fragment] = array_pad(explode('#', $url, 2), 2, null);
    $hash = $fragment !== null ? '#' . $fragment : '';
    // The target's path from the repository root.
    $parts = [];
    foreach (explode('/', 'docs/' . dirname($file) . '/' . $path) as $part) {
        if ($part === '..') {
            array_pop($parts);
        } elseif ($part !== '.' && $part !== '') {
            $parts[] = $part;
        }
    }
    $target = implode('/', $parts);
    $inDocs = substr($target, strlen('docs/'));
    if (str_starts_with($target, 'docs/') && isset($slugByFile[$inDocs])) {
        $slug = $slugByFile[$inDocs];
        return '/docs/' . ($slug === '' ? '' : $slug . '/') . $hash;
    }
    if (str_starts_with($target, 'docs/assets/')) {
        return '/' . $target;
    }
    return REPOSITORY . '/blob/main/' . $target . $hash;
};

// Render every page first: the navigation and search need all titles.
$rendered = [];
foreach ($pages as $slug => [$group, $file]) {
    $markdown = (string) file_get_contents($source . '/' . $file);
    // "See also" lines are GitHub navigation; the site has its own.
    $markdown = (string) preg_replace('/^See also:.*$/m', '', $markdown);
    $renderer = new Markdown(static fn(string $url): string => $resolve($url, $file));
    $html = $renderer->render($markdown);
    $title = $renderer->headings[0]['text'] ?? ucfirst($slug);
    preg_match('/<p>(.*?)<\/p>/s', $html, $intro);
    $rendered[$slug] = [
        'slug' => $slug,
        'group' => $group,
        'file' => $file,
        'title' => $title,
        'url' => '/docs/' . ($slug === '' ? '' : $slug . '/'),
        'html' => $html,
        'headings' => $renderer->headings,
        'description' => trim(html_entity_decode(strip_tags($intro[1] ?? ''), ENT_QUOTES)),
    ];
}

// The home page lists the guides with the descriptions from docs/README.md.
$guides = [];
foreach (array_slice($rendered, 1) as $page) {
    // Sub-pages, such as one guide per import source, are reached from their parent page.
    if (str_contains($page['slug'], '/')) {
        continue;
    }
    preg_match('/\[[^\]]+\]\(' . preg_quote($page['slug'], '/') . '\/README\.md\)[.:]\s*(.+)$/m', (string) file_get_contents($source . '/README.md'), $match);
    $guides[] = $page + ['summary' => ucfirst(trim($match[1] ?? $page['description']))];
}

if (is_dir($output)) {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
}
@mkdir($output . '/_static', 0775, true);

$order = array_values($rendered);
$search = [];
foreach ($order as $index => $page) {
    $directory = $output . ($page['slug'] === '' ? '' : '/' . $page['slug']);
    @mkdir($directory, 0775, true);
    file_put_contents($directory . '/index.html', docs_page($page, $order, $order[$index - 1] ?? null, $order[$index + 1] ?? null, $page['slug'] === '' ? $guides : []));
    foreach (docs_search_entries($page) as $entry) {
        $search[] = $entry;
    }
}

foreach (['docs.css', 'docs.js'] as $asset) {
    copy(__DIR__ . '/docs/' . $asset, $output . '/_static/' . $asset);
}
file_put_contents($output . '/_static/search-index.js', 'window.PETITSTATS_DOCS_INDEX=' . json_encode($search, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n");

@mkdir($output . '/assets', 0775, true);
foreach (glob($source . '/assets/*') ?: [] as $asset) {
    copy($asset, $output . '/assets/' . basename($asset));
}

echo 'Built ' . count($order) . ' documentation pages into ' . $output . PHP_EOL;
