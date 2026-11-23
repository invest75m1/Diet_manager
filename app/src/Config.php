<?php
declare(strict_types=1);

namespace App;

final class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function path(): string
    {
        return getenv('DM_CONFIG') ?: APP_ROOT . '/config.php';
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $defaults = [
            'timezone' => 'Europe/Bratislava',
            'max_users' => 10,
            'anthropic_api_key' => '',
            'anthropic_model' => 'claude-opus-5-5',
            'anthropic_effort' => 'low',
            'backup_keep_days' => 30,
            'storage_dir' => APP_ROOT . '/storage',
            'debug' => false,
        ];
        $cfg = self::exists() ? (require self::path()) : [];
        self::$data = array_replace($defaults, is_array($cfg) ? $cfg : []);
        // Premenné prostredia majú prednosť (Docker / cloud).
        $env = [
            'DM_DB_DRIVER' => ['db', 'driver'], 'DM_DB_HOST' => ['db', 'host'], 'DM_DB_PORT' => ['db', 'port'],
            'DM_DB_NAME' => ['db', 'name'], 'DM_DB_USER' => ['db', 'user'], 'DM_DB_PASS' => ['db', 'pass'],
            'DM_DB_PATH' => ['db', 'path'], 'ANTHROPIC_API_KEY' => ['anthropic_api_key'],
            'DM_ANTHROPIC_MODEL' => ['anthropic_model'], 'DM_ANTHROPIC_BASE_URL' => ['anthropic_base_url'], 'DM_TIMEZONE' => ['timezone'],
            'DM_STORAGE_DIR' => ['storage_dir'],
        ];
        foreach ($env as $var => $keys) {
            $v = getenv($var);
            if ($v === false || $v === '') {
                continue;
            }
            if (count($keys) === 2) {
                self::$data[$keys[0]][$keys[1]] = $v;
            } else {
                self::$data[$keys[0]] = $v;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    /** Aplikácia je nainštalovaná, ak pozná databázu. */
    public static function isConfigured(): bool
    {
        return !empty(self::$data['db']['driver']);
    }

    /** Vytvorí podpriečinky úložiska, ak chýbajú. */
    public static function ensureStorage(): void
    {
        foreach (['photos', 'backups', 'logs', 'sessions'] as $d) {
            $p = self::storage($d);
            if (!is_dir($p)) {
                @mkdir($p, 0770, true);
            }
        }
    }

    public static function storage(string $sub = ''): string
    {
        $dir = rtrim((string) self::get('storage_dir'), '/');
        return $sub === '' ? $dir : $dir . '/' . ltrim($sub, '/');
    }
}
