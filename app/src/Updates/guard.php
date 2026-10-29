<?php

declare(strict_types=1);

/*
 * Loaded by Composer before any entry point runs (see "autoload.files" in
 * composer.json), so every request goes through the update guard.
 */

/*
 * The project was called Minilytics before, and its classes lived in the
 * Minilytics namespace. An update from such a version that is killed halfway
 * leaves files of both namespaces in src/, so the code that puts the backup
 * back would miss half of its classes. This loader finds a class under either
 * name and aliases it, so that recovery still runs. It only acts when
 * Composer's own loader found nothing.
 */
spl_autoload_register(static function (string $class): void {
    foreach (['Petitstats\\' => 'Minilytics\\', 'Minilytics\\' => 'Petitstats\\'] as $prefix => $renamed) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        $twin = $renamed . $relative;
        if (!class_exists($class, false) && !interface_exists($class, false) && (class_exists($twin, false) || interface_exists($twin, false))) {
            class_alias($twin, $class);
        }
        return;
    }
});

Petitstats\Updates\Updater::guard();
