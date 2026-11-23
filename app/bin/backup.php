<?php
declare(strict_types=1);

// Spustenie zálohy z cronu:  php /cesta/k/app/bin/backup.php
require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$file = App\Backup::run();
echo "Záloha vytvorená: $file\n";
