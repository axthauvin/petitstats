<?php

declare(strict_types=1);

namespace Petitstats\Importers;

/**
 * Petitstats Importer Interface
 * Contract for all analytics platform data importers.
 */
interface ImporterInterface
{
    public function getId(): string;
    public function getName(): string;
    public function getDescription(): string;
    public function getStatus(): string; // 'ready' | 'soon'
    public function getBadge(): string;  // 'Available' | 'Coming Soon'
    public function getIcon(): string;
    public function isAvailable(): bool;
    public function getSupportedFormats(): array;
    public function import(string $sourcePath, string $siteId, array $options = []): array;
}
