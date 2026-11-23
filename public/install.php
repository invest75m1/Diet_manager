<?php
declare(strict_types=1);

/**
 * Inštalačný sprievodca – spustí sa raz po nahratí aplikácie na hosting.
 * Po dokončení inštalácie sa sám zablokuje.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\{Config, Db};

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$done = false;
$configText = null;

// Stav inštalácie
$configured = Config::isConfigured();
$hasUsers = false;
if ($configured) {
    try {
        Db::migrate();
        $hasUsers = (int) Db::val('SELECT COUNT(*) FROM users') > 0;
    } catch (Throwable $e) {
        $errors[] = 'Nepodarilo sa pripojiť k databáze z config.php: ' . $e->getMessage();
    }
}
$locked = $configured && $hasUsers;
// Obnova prístupu administrátora: cez FTP vytvorte súbor app/storage/reset-admin.txt
$resetFile = Config::storage('reset-admin.txt');
$resetMode = $locked && is_file($resetFile);
$resetDone = false;
if ($resetMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string) ($_POST['admin_password'] ?? '');
    if (mb_strlen($pw) < 8) {
        $errors[] = 'Heslo musí mať aspoň 8 znakov';
    } elseif ($pw !== (string) ($_POST['admin_password2'] ?? '')) {
        $errors[] = 'Heslá sa nezhodujú';
    } else {
        $admin = Db::one('SELECT id, username FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1');
        Db::run('UPDATE users SET password_hash = :h, is_active = 1, must_change_password = 0 WHERE id = :id',
            ['h' => password_hash($pw, PASSWORD_DEFAULT), 'id' => (int) $admin['id']]);
        Db::run('DELETE FROM auth_tokens WHERE user_id = :id', ['id' => (int) $admin['id']]);
        Db::run('DELETE FROM login_attempts');
        @unlink($resetFile);
        $resetDone = true;
        $adminUser = $admin['username'];
    }
}

// Kontrola prostredia
$checks = [
    ['PHP 8.1 alebo novšie', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION, true],
    ['Rozšírenie PDO MySQL', extension_loaded('pdo_mysql'), '', false],
    ['Rozšírenie PDO SQLite', extension_loaded('pdo_sqlite'), '', false],
    ['Rozšírenie cURL (Open Food Facts, AI)', extension_loaded('curl'), '', true],
    ['Rozšírenie GD (zmenšovanie fotiek)', extension_loaded('gd'), 'odporúčané', false],
    ['Knižnice (app/vendor)', is_file(APP_ROOT . '/vendor/autoload.php'), 'spustite composer install alebo nahrajte balík z Releases', true],
    ['Zapisovateľný priečinok app/storage', is_writable(Config::storage()), Config::storage(), true],
];
$blocking = array_filter($checks, fn($c) => $c[3] && !$c[1]);

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST' && !$blocking) {
    $in = fn(string $k, string $d = '') => trim((string) ($_POST[$k] ?? $d));
    $adminUser = mb_strtolower($in('admin_username'));
    $adminName = $in('admin_name') ?: $adminUser;
    $pw = (string) ($_POST['admin_password'] ?? '');
    $pw2 = (string) ($_POST['admin_password2'] ?? '');
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $adminUser)) {
        $errors[] = 'Prihlasovacie meno: 3–32 znakov, malé písmená bez diakritiky, číslice, . _ -';
    }
    if (mb_strlen($pw) < 8) {
        $errors[] = 'Heslo musí mať aspoň 8 znakov';
    } elseif ($pw !== $pw2) {
        $errors[] = 'Heslá sa nezhodujú';
    }

    $newConfig = null;
    if (!$configured) {
        $driver = $in('db_driver', 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
        $db = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'path' => Config::storage('database.sqlite')]
            : ['driver' => 'mysql', 'host' => $in('db_host', 'localhost'), 'port' => (int) ($in('db_port') ?: 3306),
               'name' => $in('db_name'), 'user' => $in('db_user'), 'pass' => (string) ($_POST['db_pass'] ?? '')];
        $newConfig = [
            'db' => $db,
            'anthropic_api_key' => $in('api_key'),
            'anthropic_model' => 'claude-opus-5-5',
            'anthropic_effort' => 'low',
            'timezone' => 'Europe/Bratislava',
            'max_users' => 10,
            'backup_keep_days' => 30,
            'debug' => false,
        ];
        if (!$errors) {
            try {
                Db::setPdo(Db::connect($db));
            } catch (Throwable $e) {
                $errors[] = 'Pripojenie k databáze zlyhalo: ' . $e->getMessage();
            }
        }
    }

    if (!$errors) {
        try {
            Db::migrate();
            if ((int) Db::val('SELECT COUNT(*) FROM users') === 0) {
                Db::insert('users', [
                    'username' => $adminUser,
                    'display_name' => mb_substr($adminName, 0, 80),
                    'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                    'is_admin' => 1,
                    'is_active' => 1,
                    'must_change_password' => 0,
                    'created_at' => Db::now(),
                ]);
            }
            if ($newConfig !== null) {
                $php = "<?php\n// Konfigurácia Diet Manager – vytvorené inštalátorom " . date('Y-m-d H:i') . "\nreturn " . var_export($newConfig, true) . ";\n";
                if (@file_put_contents(Config::path(), $php) === false) {
                    $configText = $php;
                } else {
                    @chmod(Config::path(), 0600);
                }
            }
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Inštalácia zlyhala: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Inštalácia – Diet Manager</title>
<style>
  :root { --bg:#f5f7f4; --card:#fff; --text:#1c2321; --muted:#5f6b66; --accent:#2f9e6b; --err:#c8443a; --line:#dfe5e1; }
  @media (prefers-color-scheme: dark) { :root { --bg:#111614; --card:#1b2220; --text:#e8eeeb; --muted:#9aa8a2; --line:#2c3633; } }
  * { box-sizing: border-box; }
  body { margin:0; font:16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--text); }
  main { max-width:640px; margin:0 auto; padding:24px 16px 64px; }
  h1 { font-size:1.6rem; margin:0 0 4px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:20px; margin:16px 0; }
  label { display:block; font-weight:600; margin:14px 0 4px; font-size:.95rem; }
  input, select { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:10px; background:var(--bg); color:var(--text); font-size:16px; }
  .hint { color:var(--muted); font-size:.85rem; margin-top:4px; }
  button { margin-top:20px; width:100%; padding:14px; border:0; border-radius:12px; background:var(--accent); color:#fff; font-size:1rem; font-weight:700; cursor:pointer; }
  .err { background:color-mix(in srgb, var(--err) 12%, transparent); border:1px solid var(--err); color:var(--err); border-radius:10px; padding:10px 14px; margin:8px 0; }
  table { width:100%; border-collapse:collapse; font-size:.92rem; }
  td { padding:6px 4px; border-bottom:1px solid var(--line); vertical-align:top; }
  .ok { color:var(--accent); font-weight:700; } .no { color:var(--err); font-weight:700; }
  .row { display:grid; grid-template-columns:2fr 1fr; gap:12px; }
  pre { white-space:pre-wrap; word-break:break-all; background:var(--bg); padding:12px; border-radius:10px; font-size:.8rem; }
  a.btn { display:block; text-align:center; text-decoration:none; padding:14px; border-radius:12px; background:var(--accent); color:#fff; font-weight:700; margin-top:16px; }
</style>
</head>
<body>
<main>
  <h1>Diet Manager – inštalácia</h1>
  <p class="hint">Sprievodca vytvorí databázové tabuľky, konfiguračný súbor a účet administrátora.</p>

<?php if ($resetMode && !$resetDone): ?>
  <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>
  <form method="post" class="card" autocomplete="off">
    <strong>Obnova hesla administrátora</strong>
    <p class="hint">Našiel sa súbor <code>reset-admin.txt</code>. Nastavte nové heslo pre účet administrátora.</p>
    <label for="admin_password">Nové heslo (min. 8 znakov)</label>
    <input id="admin_password" name="admin_password" type="password" autocomplete="new-password">
    <label for="admin_password2">Heslo znova</label>
    <input id="admin_password2" name="admin_password2" type="password" autocomplete="new-password">
    <button type="submit">Nastaviť heslo</button>
  </form>
<?php elseif ($resetDone): ?>
  <div class="card"><p class="ok">✔ Heslo administrátora <strong><?= h($adminUser) ?></strong> bolo zmenené.</p>
    <?php if (is_file($resetFile)): ?><p class="err">Zmažte súbor app/storage/reset-admin.txt!</p><?php endif; ?>
    <a class="btn" href="./">Prihlásiť sa</a></div>
<?php elseif ($locked && !$done): ?>
  <div class="card">
    <p><strong>Aplikácia je už nainštalovaná.</strong> Inštalátor je zablokovaný.</p>
    <a class="btn" href="./">Prejsť do aplikácie</a>
  </div>
<?php elseif ($done): ?>
  <div class="card">
    <p class="ok">✔ Inštalácia dokončená.</p>
    <?php if ($configText !== null): ?>
      <p><strong>Konfiguračný súbor sa nepodarilo uložiť automaticky.</strong> Vytvorte súbor
        <code>app/config.php</code> s týmto obsahom (napr. cez FTP alebo správcu súborov):</p>
      <pre><?= h($configText) ?></pre>
    <?php endif; ?>
    <p>Prihláste sa ako <strong><?= h($adminUser ?? '') ?></strong>. V sekcii <em>Viac → Správa užívateľov</em> vytvoríte účty pre ostatných.</p>
    <a class="btn" href="./">Otvoriť aplikáciu</a>
  </div>
<?php else: ?>
  <div class="card">
    <strong>Kontrola servera</strong>
    <table>
      <?php foreach ($checks as [$label, $ok, $note, $required]): ?>
        <tr><td><?= h($label) ?><?= $note ? '<div class="hint">' . h($note) . '</div>' : '' ?></td>
            <td class="<?= $ok ? 'ok' : ($required ? 'no' : '') ?>"><?= $ok ? 'OK' : ($required ? 'CHÝBA' : 'nie') ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>

  <?php if (!$blocking): ?>
  <form method="post" class="card" autocomplete="off">
    <?php if (!$configured): ?>
      <strong>1. Databáza</strong>
      <label for="db_driver">Typ databázy</label>
      <select id="db_driver" name="db_driver" onchange="document.getElementById('mysql').style.display = this.value === 'mysql' ? '' : 'none'">
        <option value="mysql" <?= ($_POST['db_driver'] ?? 'mysql') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB (odporúčané – Websupport)</option>
        <option value="sqlite" <?= ($_POST['db_driver'] ?? '') === 'sqlite' ? 'selected' : '' ?>>SQLite (súbor, bez databázového servera)</option>
      </select>
      <div id="mysql" style="<?= ($_POST['db_driver'] ?? 'mysql') === 'sqlite' ? 'display:none' : '' ?>">
        <div class="row">
          <div><label for="db_host">Server (host)</label><input id="db_host" name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>"></div>
          <div><label for="db_port">Port</label><input id="db_port" name="db_port" inputmode="numeric" value="<?= h($_POST['db_port'] ?? '3306') ?>"></div>
        </div>
        <div class="hint">Na Websupporte nájdete údaje v administrácii: Databázy → detail databázy.</div>
        <label for="db_name">Názov databázy</label><input id="db_name" name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>">
        <label for="db_user">Používateľ databázy</label><input id="db_user" name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>">
        <label for="db_pass">Heslo databázy</label><input id="db_pass" name="db_pass" type="password">
      </div>

      <strong style="display:block;margin-top:24px">2. AI rozpoznávanie (Claude)</strong>
      <label for="api_key">Anthropic API kľúč</label>
      <input id="api_key" name="api_key" placeholder="sk-ant-..." value="<?= h($_POST['api_key'] ?? '') ?>">
      <div class="hint">Nepovinné – dá sa doplniť neskôr do <code>app/config.php</code>. Kľúč získate na console.anthropic.com.</div>
    <?php endif; ?>

    <strong style="display:block;margin-top:24px"><?= $configured ? '' : '3. ' ?>Administrátor</strong>
    <label for="admin_username">Prihlasovacie meno</label>
    <input id="admin_username" name="admin_username" autocapitalize="none" value="<?= h($_POST['admin_username'] ?? 'admin') ?>">
    <label for="admin_name">Zobrazované meno</label>
    <input id="admin_name" name="admin_name" value="<?= h($_POST['admin_name'] ?? '') ?>" placeholder="napr. Martin">
    <label for="admin_password">Heslo (min. 8 znakov)</label>
    <input id="admin_password" name="admin_password" type="password" autocomplete="new-password">
    <label for="admin_password2">Heslo znova</label>
    <input id="admin_password2" name="admin_password2" type="password" autocomplete="new-password">

    <button type="submit">Nainštalovať</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
