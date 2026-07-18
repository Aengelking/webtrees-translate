<?php

/**
 * Translate Notes - webtrees 2.2 custom module.
 *
 * webtrees loads the module.php file from every sub-folder of modules_v4/.
 * It must return an instance of a class implementing ModuleCustomInterface.
 */

declare(strict_types=1);

namespace TranslateNotes;

// Minimal autoloader for this module's namespace (main class + Engines/).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, __NAMESPACE__ . '\\')) {
        $relative = str_replace('\\', '/', substr($class, strlen(__NAMESPACE__) + 1));
        $file     = __DIR__ . '/' . $relative . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

return new TranslateNotesModule();
