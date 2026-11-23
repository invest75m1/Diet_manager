<?php
declare(strict_types=1);

/**
 * Spoločný štart aplikácie: autoload, konfigurácia, časové pásmo, DB migrácie.
 */

define('APP_ROOT', __DIR__);
define('APP_VERSION', '1.0.0');

if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
}

// Záložný autoloader pre vlastné triedy (ak by vendor chýbal).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

App\Config::load();
date_default_timezone_set((string) App\Config::get('timezone', 'Europe/Bratislava'));
mb_internal_encoding('UTF-8');
