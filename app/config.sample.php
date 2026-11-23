<?php
// Vzorová konfigurácia. Inštalátor (install.php) vytvorí app/config.php automaticky.
// Ak ho chcete vytvoriť ručne, skopírujte tento súbor ako config.php a doplňte údaje.
return [
    'db' => [
        'driver' => 'mysql',          // 'mysql' alebo 'sqlite'
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'nazov_databazy',
        'user' => 'pouzivatel',
        'pass' => 'heslo',
        // pre SQLite: 'driver' => 'sqlite', 'path' => __DIR__ . '/storage/database.sqlite',
    ],
    // Kľúč z https://console.anthropic.com (AI rozpoznávanie jedla z fotky a textu)
    'anthropic_api_key' => '',
    'anthropic_model' => 'claude-opus-5-5',
    // Úsilie modelu: 'low' je rýchle a lacné, 'medium' / 'high' presnejšie, ale drahšie
    'anthropic_effort' => 'low',
    'timezone' => 'Europe/Bratislava',
    'max_users' => 10,
    'backup_keep_days' => 30,
    'debug' => false,
];
