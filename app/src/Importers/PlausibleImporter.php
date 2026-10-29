<?php

declare(strict_types=1);

namespace Petitstats\Importers;

use RuntimeException;

/**
 * Plausible Analytics Importer — Coming Soon
 */
class PlausibleImporter extends BaseImporter
{
    public function getId(): string
    {
        return 'plausible';
    }

    public function getName(): string
    {
        return 'Plausible Analytics';
    }

    public function getDescription(): string
    {
        return 'Import pageviews, custom goals and visitor metrics from a Plausible Analytics CSV export.';
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
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getSupportedFormats(): array
    {
        return ['.zip', '.csv'];
    }

    public function import(string $sourcePath, string $siteId, array $options = []): array
    {
        throw new RuntimeException("Plausible Analytics importer is coming soon.");
    }
}
