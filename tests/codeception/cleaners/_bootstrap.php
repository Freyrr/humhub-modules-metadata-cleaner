<?php

/**
 * @license AGPL-3.0-or-later
 */

// The cleaners and readers have no HumHub dependency: load them directly.
spl_autoload_register(static function (string $class): void {
    $prefix = 'humhub\\modules\\metadataCleaner\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    if (!preg_match('#^(cleaners|readers|services/MetadataCleanerService)#', $relative)) {
        return;
    }
    $file = dirname(__DIR__, 3) . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
require_once __DIR__ . '/CleanerTestCase.php';
