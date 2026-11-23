<?php
declare(strict_types=1);

namespace App;

/**
 * Denná záloha databázy do storage/backups (gzip SQL), uchováva sa N dní.
 * Spúšťa sa cez cron (bin/backup.php) alebo automaticky pri prvej požiadavke dňa.
 */
final class Backup
{
    private const TABLES = ['settings', 'users', 'profiles', 'goals', 'foods', 'recipe_items', 'favorites',
        'entries', 'water_logs', 'weights', 'activities'];

    public static function runIfDue(): void
    {
        try {
            if (Db::getSetting('last_backup_date') === date('Y-m-d')) {
                return;
            }
            self::run();
        } catch (\Throwable $e) {
            @file_put_contents(Config::storage('logs/backup.log'), date('c') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    public static function run(): string
    {
        $dir = Config::storage('backups');
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new \RuntimeException('Priečinok na zálohy nie je zapisovateľný: ' . $dir);
        }
        // Zápis "dnes zálohované" najprv, aby súbežné požiadavky nezačali zálohovať tiež.
        Db::setSetting('last_backup_date', date('Y-m-d'));
        $file = $dir . '/backup-' . date('Y-m-d') . '.sql.gz';
        $gz = gzopen($file . '.tmp', 'wb6');
        gzwrite($gz, "-- Diet Manager záloha " . date('c') . " (" . Db::driver() . ")\n");
        $pdo = Db::pdo();
        foreach (self::TABLES as $t) {
            gzwrite($gz, "\n-- $t\nDELETE FROM $t;\n");
            $st = $pdo->query("SELECT * FROM $t");
            while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
                gzwrite($gz, "INSERT INTO $t (" . implode(',', array_keys($row)) . ') VALUES (' . implode(',', $vals) . ");\n");
            }
        }
        gzclose($gz);
        rename($file . '.tmp', $file);
        self::prune($dir);
        return $file;
    }

    private static function prune(string $dir): void
    {
        $keep = (int) Config::get('backup_keep_days', 30);
        $limit = date('Y-m-d', strtotime("-$keep days"));
        foreach (glob($dir . '/backup-*.sql.gz') ?: [] as $f) {
            if (preg_match('/backup-(\d{4}-\d{2}-\d{2})/', $f, $m) && $m[1] < $limit) {
                @unlink($f);
            }
        }
    }
}
