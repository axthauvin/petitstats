<?php

declare(strict_types=1);

namespace Petitstats\Importers;

use RuntimeException;

/**
 * Google Analytics (GA4 / UA) Importer — Coming Soon
 */
class GoogleAnalyticsImporter extends BaseImporter
{
    public function getId(): string
    {
        return 'google_analytics';
    }

    public function getName(): string
    {
        return 'Google Analytics (GA4)';
    }

    public function getDescription(): string
    {
        return 'Import historical analytics from Google Analytics 4 (BigQuery exports or CSV event dumps).';
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
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M19.5 3H16.5C15.67 3 15 3.67 15 4.5V19.5C15 20.33 15.67 21 16.5 21H19.5C20.33 21 21 20.33 21 19.5V4.5C21 3.67 20.33 3 19.5 3Z" fill="currentColor"/><path d="M10.5 9H7.5C6.67 9 6 9.67 6 10.5V19.5C6 20.33 6.67 21 7.5 21H10.5C11.33 21 12 20.33 12 19.5V10.5C12 9.67 11.33 9 10.5 9Z" fill="currentColor"/><circle cx="3" cy="18" r="3" fill="currentColor"/></svg>';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getSupportedFormats(): array
    {
        return ['.csv', '.json', '.parquet'];
    }

    public function import(string $sourcePath, string $siteId, array $options = []): array
    {
        throw new RuntimeException("Google Analytics 4 importer is coming soon.");
    }
}
