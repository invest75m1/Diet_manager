<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(?array $cfg = null): PDO
    {
        $cfg ??= (array) Config::get('db', []);
        $driver = $cfg['driver'] ?? 'mysql';
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . $cfg['path'], null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'] ?? 'localhost', (int) ($cfg['port'] ?? 3306), $cfg['name'] ?? '');
            $pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', $opts);
            $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES'");
        }
        return $pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function driver(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($key, is_bool($v) ? (int) $v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', $cols),
            implode(',', array_map(fn($c) => ':' . $c, $cols)));
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($data)));
        $params = $data;
        foreach ($whereParams as $k => $v) {
            $params['w_' . $k] = $v;
            $where = preg_replace('/:' . preg_quote((string) $k, '/') . '\b/', ':w_' . $k, $where);
        }
        return self::run("UPDATE $table SET $set WHERE $where", $params)->rowCount();
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Spustí chýbajúce migrácie zo schema/<driver>/NNN_*.sql. */
    public static function migrate(): void
    {
        $driver = self::driver();
        $pdo = self::pdo();
        $pdo->exec($driver === 'sqlite'
            ? 'CREATE TABLE IF NOT EXISTS settings (name VARCHAR(64) NOT NULL PRIMARY KEY, value TEXT NULL)'
            : 'CREATE TABLE IF NOT EXISTS settings (name VARCHAR(64) NOT NULL PRIMARY KEY, value TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $current = (int) (self::val("SELECT value FROM settings WHERE name = 'schema_version'") ?? 0);
        $files = glob(APP_ROOT . '/schema/' . $driver . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $ver = (int) basename($file);
            if ($ver <= $current) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
            self::setSetting('schema_version', (string) $ver);
            $current = $ver;
        }
    }

    public static function latestSchemaVersion(): int
    {
        $files = glob(APP_ROOT . '/schema/' . self::driver() . '/*.sql') ?: [];
        return $files ? max(array_map(fn($f) => (int) basename($f), $files)) : 0;
    }

    public static function getSetting(string $name): ?string
    {
        $v = self::val('SELECT value FROM settings WHERE name = :n', ['n' => $name]);
        return $v === null ? null : (string) $v;
    }

    public static function setSetting(string $name, ?string $value): void
    {
        if (self::driver() === 'sqlite') {
            self::run('INSERT INTO settings (name, value) VALUES (:n, :v) ON CONFLICT(name) DO UPDATE SET value = excluded.value',
                ['n' => $name, 'v' => $value]);
        } else {
            self::run('INSERT INTO settings (name, value) VALUES (:n, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['n' => $name, 'v' => $value]);
        }
    }
}
