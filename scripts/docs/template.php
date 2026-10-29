<?php

declare(strict_types=1);

/** HTML of the documentation pages. Called by scripts/build-docs.php. */

function docs_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

/**
 * @param array<string, mixed> $page
 * @param list<array<string, mixed>> $pages every page, in navigation order
 * @param array<string, mixed>|null $previous
 * @param array<string, mixed>|null $next
 * @param list<array<string, mixed>> $guides cards shown on the home page
 */
function docs_page(array $page, array $pages, ?array $previous, ?array $next, array $guides): string
{
    $e = 'docs_escape';
    $isHome = $page['slug'] === '';
    $title = $isHome ? 'Petitstats documentation' : $page['title'] . ' · Petitstats docs';

    $nav = '';
    $group = null;
    foreach ($pages as $item) {
        if ($item['group'] !== $group) {
            $nav .= ($group === null ? '' : '</div>') . '<div class="nav-group"><p>' . $e($item['group']) . '</p>';
            $group = $item['group'];
        }
        $current = $item['slug'] === $page['slug'];
        $classes = trim((str_contains($item['slug'], '/') ? 'nav-sub ' : '') . ($current ? 'is-active' : ''));
        $nav .= '<a href="' . $e($item['url']) . '"' . ($classes !== '' ? ' class="' . $classes . '"' : '') . ($current ? ' aria-current="page"' : '') . '>' . $e($item['slug'] === '' ? 'Introduction' : $item['title']) . '</a>';
    }
    $nav .= '</div>';

    $toc = '';
    foreach ($page['headings'] as $heading) {
        if ($heading['level'] === 2 || $heading['level'] === 3) {
            $toc .= '<a class="toc-h' . $heading['level'] . '" href="#' . $e($heading['id']) . '">' . $e($heading['text']) . '</a>';
        }
    }
    $toc = $toc === '' || $isHome ? '' : '<p class="toc-title">On this page</p>' . $toc;

    $content = $page['html'];
    if ($isHome) {
        $content = docs_home($page, $guides);
    }

    $pager = '';
    if ($previous !== null) {
        $pager .= '<a class="pager-prev" href="' . $e($previous['url']) . '"><span>Previous</span><strong>' . $e($previous['slug'] === '' ? 'Introduction' : $previous['title']) . '</strong></a>';
    }
    if ($next !== null) {
        $pager .= '<a class="pager-next" href="' . $e($next['url']) . '"><span>Next</span><strong>' . $e($next['title']) . '</strong></a>';
    }

    $editUrl = REPOSITORY . '/edit/main/docs/' . $page['file'];
    $description = $page['description'] !== '' ? $page['description'] : 'Documentation for Petitstats, private and lightweight web analytics.';

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$e($title)}</title>
<meta name="description" content="{$e(mb_strimwidth($description, 0, 160, '…'))}">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="/docs/_static/docs.css">
<script defer src="https://minilytics.axthauvin.fr/petitstats.js" data-site-id="petitstats_landing" data-site-key="2211ebf1d2edb11b201de997cfaf48cd1dee0ed231bace06" data-privacy-mode="strict"></script>
<script defer src="/docs/_static/docs.js"></script>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <button class="icon-button menu-toggle" type="button" aria-label="Open the navigation" aria-controls="sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
    <a class="brand" href="/docs/"><img src="/favicon.svg" alt="" width="26" height="26"><span>Petitstats</span><span class="brand-tag">Docs</span></a>
    <button class="search-trigger" type="button" data-search-open><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span>Search the docs</span><kbd>Ctrl K</kbd></button>
    <nav class="top-links" aria-label="Site">
      <a href="/">Home</a><a href="/dashboard/?demo=1">Live demo</a><a href="{$e(REPOSITORY)}" target="_blank" rel="noopener">GitHub</a>
    </nav>
    <button class="icon-button search-icon" type="button" aria-label="Search the docs" data-search-open><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></button>
  </div>
</header>
<div class="layout">
  <aside class="sidebar" id="sidebar"><nav aria-label="Documentation">{$nav}</nav></aside>
  <div class="sidebar-backdrop" data-menu-close></div>
  <main class="content">
    <article class="prose">{$content}</article>
    <div class="page-meta"><a href="{$e($editUrl)}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/></svg>Edit this page on GitHub</a></div>
    <nav class="pager" aria-label="Pages">{$pager}</nav>
  </main>
  <aside class="toc" aria-label="On this page">{$toc}</aside>
</div>
<div class="search-modal" hidden>
  <div class="search-dialog" role="dialog" aria-modal="true" aria-label="Search the docs">
    <div class="search-field"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" placeholder="Search the docs" autocomplete="off" spellcheck="false" aria-label="Search the docs"><kbd>Esc</kbd></div>
    <ul class="search-results" role="listbox"></ul>
    <p class="search-hint"><kbd>↑</kbd><kbd>↓</kbd> to move <kbd>Enter</kbd> to open</p>
  </div>
</div>
</body>
</html>

HTML;
}

/**
 * What Petitstats is, then a card for each guide.
 *
 * @param array<string, mixed> $page
 * @param list<array<string, mixed>> $guides
 */
function docs_home(array $page, array $guides): string
{
    $e = 'docs_escape';
    preg_match('/<p>.*?<\/p>/s', $page['html'], $intro);
    $icons = [
        'tracking' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'privacy' => '<path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
        'operations' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'importing' => '<path d="M12 3v12m0 0-4-4m4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'mcp' => '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8L12 3Z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/>',
    ];
    $cards = '';
    foreach ($guides as $guide) {
        $cards .= '<a class="guide-card" href="' . $e($guide['url']) . '"><span class="guide-icon"><svg viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$guide['slug']] ?? '') . '</svg></span><strong>' . $e($guide['title']) . '</strong><span>' . $e($guide['summary']) . '</span></a>';
    }
    // The first paragraph of docs/README.md says what Petitstats is.
    return '<h1 id="petitstats-documentation">Petitstats documentation</h1>'
        . str_replace('<p>', '<p class="lead">', $intro[0] ?? '')
        . '<div class="hero-actions"><a class="button button-primary" href="/install.html">Install Petitstats</a><a class="button" href="/dashboard/?demo=1">Try the live demo</a></div>'
        . '<div class="guide-grid">' . $cards . '</div>';
}

/**
 * Search entries for a page: its introduction, then one per section.
 *
 * @param array<string, mixed> $page
 * @return list<array{p: string, h: string, u: string, x: string}>
 */
function docs_search_entries(array $page): array
{
    $sections = preg_split('/(?=<h[1-3] id=")/', $page['html'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $entries = [];
    foreach ($sections as $section) {
        if (!preg_match('/^<h([1-3]) id="([^"]+)">(.*?)<\/h\1>/s', $section, $heading)) {
            continue;
        }
        // Tags become spaces, so list items and table cells do not run together.
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', substr($section, strlen($heading[0]))), ENT_QUOTES)));
        $entries[] = [
            'p' => $page['slug'] === '' ? 'Introduction' : $page['title'],
            'h' => trim(html_entity_decode(strip_tags((string) preg_replace('/<a class="anchor".*?<\/a>/', '', $heading[3])), ENT_QUOTES)),
            'u' => $page['url'] . ($heading[1] === '1' ? '' : '#' . $heading[2]),
            'x' => mb_substr($text, 0, 400),
        ];
    }
    return $entries;
}
