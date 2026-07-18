<?php

/**
 * Translate Notes - webtrees 2.2 custom module.
 *
 * webtrees loads the module.php file from every sub-folder of modules_v4/.
 * It must return an instance of a class implementing ModuleCustomInterface.
 *
 * Copyright (C) 2026 Amos Engelking
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
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
