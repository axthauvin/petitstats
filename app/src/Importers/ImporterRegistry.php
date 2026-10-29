<?php

declare(strict_types=1);

namespace Petitstats\Importers;

/**
 * Registry of analytics data importers
 */
class ImporterRegistry
{
    /** @var ImporterInterface[] */
    private static array $importers = [];

    private static function init(): void
    {
        if (!empty(self::$importers)) {
            return;
        }

        self::register(new UmamiImporter());
        self::register(new GoogleAnalyticsImporter());
        self::register(new PlausibleImporter());
        self::register(new MatomoImporter());
        self::register(new SimpleAnalyticsImporter());
    }

    public static function register(ImporterInterface $importer): void
    {
        self::$importers[$importer->getId()] = $importer;
    }

    public static function getImporter(string $id): ?ImporterInterface
    {
        self::init();
        return self::$importers[$id] ?? null;
    }

    public static function getAll(): array
    {
        self::init();
        return self::$importers;
    }

    public static function getProvidersList(): array
    {
        self::init();
        $list = [];
        foreach (self::$importers as $importer) {
            $list[] = [
                'id' => $importer->getId(),
                'name' => $importer->getName(),
                'description' => $importer->getDescription(),
                'status' => $importer->getStatus(),
                'badge' => $importer->getBadge(),
                'icon' => $importer->getIcon(),
                'is_available' => $importer->isAvailable(),
                'supported_formats' => $importer->getSupportedFormats(),
            ];
        }
        return $list;
    }
}
