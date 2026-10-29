<?php

declare(strict_types=1);

namespace Petitstats\Importers;

use RuntimeException;

/**
 * Simple Analytics Importer — Coming Soon
 */
class SimpleAnalyticsImporter extends BaseImporter
{
    public function getId(): string
    {
        return 'simple_analytics';
    }

    public function getName(): string
    {
        return 'Simple Analytics';
    }

    public function getDescription(): string
    {
        return 'Import privacy-friendly website analytics from a Simple Analytics export.';
    }

    public function getStatus(): string
    {
        return 'soon';
    }

    public function getBadge(): string
    {
        return 'Coming Soon';
    }

    public function getIcon(): string
    {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getSupportedFormats(): array
    {
        return ['.csv'];
    }

    public function import(string $sourcePath, string $siteId, array $options = []): array
    {
        throw new RuntimeException("Simple Analytics importer is coming soon.");
    }
}
