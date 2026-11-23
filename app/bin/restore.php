<?php
declare(strict_types=1);

// Obnova zo zálohy:  php app/bin/restore.php app/storage/backups/backup-RRRR-MM-DD.sql.gz
require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$file = $argv[1] ?? '';
if (!is_file($file)) {
    fwrite(STDERR, "Použitie: php bin/restore.php <subor.sql.gz>\n");
    exit(1);
}
echo "POZOR: všetky aktuálne dáta budú nahradené zálohou. Pokračovať? [ano/nie] ";
if (trim((string) fgets(STDIN)) !== 'ano') {
    exit("Zrušené.\n");
}
App\Db::migrate();
$pdo = App\Db::pdo();
$sql = gzdecode((string) file_get_contents($file));
if (App\Db::driver() === 'mysql') {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
} else {
    $pdo->exec('PRAGMA foreign_keys = OFF');
}
$pdo->beginTransaction();
foreach (preg_split("/;\n/", $sql) as $stmt) {
    $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
    if ($stmt !== '') {
        $pdo->exec($stmt);
    }
}
$pdo->commit();
echo "Obnovené zo zálohy $file\n";
