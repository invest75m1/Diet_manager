<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    public const REMEMBER_COOKIE = 'dm_remember';
    public const REMEMBER_DAYS = 30;
    public const MIN_PASSWORD = 8;
    private const MAX_FAIL_USER = 8;
    private const MAX_FAIL_IP = 25;
    private const FAIL_WINDOW_MIN = 15;

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = Config::storage('sessions');
        if (is_dir($dir) || @mkdir($dir, 0770, true)) {
            session_save_path($dir);
        }
        session_name('dm_sess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '86400');
        session_start();
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        self::startSession();
        $uid = $_SESSION['uid'] ?? null;
        if ($uid) {
            $u = Db::one('SELECT * FROM users WHERE id = :id', ['id' => (int) $uid]);
            if ($u && (int) $u['is_active'] === 1 && ($_SESSION['pwv'] ?? '') === self::passwordVersion($u)) {
                return self::$user = $u;
            }
            unset($_SESSION['uid'], $_SESSION['pwv']);
        }
        // Prihlásenie cez "zapamätať si ma".
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie && str_contains($cookie, ':')) {
            [$selector, $validator] = explode(':', $cookie, 2);
            $tok = Db::one('SELECT * FROM auth_tokens WHERE selector = :s', ['s' => $selector]);
            if ($tok && $tok['expires_at'] > Db::now() && hash_equals($tok['token_hash'], hash('sha256', $validator))) {
                $u = Db::one('SELECT * FROM users WHERE id = :id AND is_active = 1', ['id' => (int) $tok['user_id']]);
                Db::run('DELETE FROM auth_tokens WHERE id = :id', ['id' => (int) $tok['id']]);
                if ($u) {
                    self::establish($u);
                    self::issueRememberToken((int) $u['id']);
                    return self::$user = $u;
                }
            }
            Http::setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
        }
        return null;
    }

    /** Pri zmene hesla sa zneplatnia ostatné relácie. */
    private static function passwordVersion(array $u): string
    {
        return substr(hash('sha256', $u['password_hash']), 0, 16);
    }

    private static function establish(array $u): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['pwv'] = self::passwordVersion($u);
        Db::run('UPDATE users SET last_login_at = :t WHERE id = :id', ['t' => Db::now(), 'id' => (int) $u['id']]);
    }

    public static function require(bool $allowPasswordChange = false): array
    {
        $u = self::user();
        if (!$u) {
            throw new HttpError(401, 'Nie ste prihlásený', 'unauthenticated');
        }
        if (!$allowPasswordChange && (int) $u['must_change_password'] === 1) {
            throw new HttpError(403, 'Najprv si zmeňte heslo', 'must_change_password');
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::require();
        if ((int) $u['is_admin'] !== 1) {
            throw new HttpError(403, 'Prístup len pre administrátora', 'forbidden');
        }
        return $u;
    }

    public static function attempt(string $username, string $password, bool $remember): array
    {
        $username = mb_strtolower(trim($username));
        $ip = Http::ip();
        $since = date('Y-m-d H:i:s', time() - self::FAIL_WINDOW_MIN * 60);
        $failUser = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE username = :u AND attempted_at > :t', ['u' => $username, 't' => $since]);
        $failIp = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND attempted_at > :t', ['ip' => $ip, 't' => $since]);
        if ($failUser >= self::MAX_FAIL_USER || $failIp >= self::MAX_FAIL_IP) {
            throw new HttpError(429, 'Príliš veľa neúspešných pokusov. Skúste to o ' . self::FAIL_WINDOW_MIN . ' minút.');
        }
        $u = Db::one('SELECT * FROM users WHERE username = :u', ['u' => $username]);
        // Overenie hesla vykonáme aj pre neexistujúceho užívateľa (rovnaký čas odozvy).
        $hash = $u['password_hash'] ?? '$2y$10$n9H/tEvnQfh5P4cfD88t6uOyxGnEitYSjpmz2VCSTfeijvrrCBcVW';
        $ok = password_verify($password, $hash) && $u !== null;
        if (!$ok || (int) $u['is_active'] !== 1) {
            Db::insert('login_attempts', ['username' => mb_substr($username, 0, 64), 'ip' => $ip, 'attempted_at' => Db::now()]);
            if ($ok) {
                throw new HttpError(403, 'Účet je deaktivovaný. Kontaktujte administrátora.');
            }
            throw new HttpError(401, 'Nesprávne meno alebo heslo');
        }
        Db::run('DELETE FROM login_attempts WHERE username = :u', ['u' => $username]);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            Db::run('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => $u['password_hash'], 'id' => (int) $u['id']]);
        }
        self::establish($u);
        if ($remember) {
            self::issueRememberToken((int) $u['id']);
        }
        self::$user = $u;
        self::$resolved = true;
        return $u;
    }

    public static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + self::REMEMBER_DAYS * 86400;
        Db::insert('auth_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => date('Y-m-d H:i:s', $expires),
            'created_at' => Db::now(),
        ]);
        Http::setCookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, $expires);
        // Upratovanie starých tokenov a pokusov.
        Db::run('DELETE FROM auth_tokens WHERE expires_at < :t', ['t' => Db::now()]);
        Db::run('DELETE FROM login_attempts WHERE attempted_at < :t', ['t' => date('Y-m-d H:i:s', time() - 86400)]);
    }

    public static function logout(): void
    {
        self::startSession();
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie && str_contains($cookie, ':')) {
            Db::run('DELETE FROM auth_tokens WHERE selector = :s', ['s' => explode(':', $cookie, 2)[0]]);
        }
        Http::setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
        $_SESSION = [];
        session_destroy();
        self::$user = null;
    }

    public static function validatePassword(string $pw): void
    {
        if (mb_strlen($pw) < self::MIN_PASSWORD) {
            throw new HttpError(422, 'Heslo musí mať aspoň ' . self::MIN_PASSWORD . ' znakov');
        }
        if (strlen($pw) > 200) {
            throw new HttpError(422, 'Heslo je príliš dlhé');
        }
    }

    /** Po zmene hesla: obnoví reláciu aktuálneho užívateľa. */
    public static function refreshSession(int $userId): void
    {
        $u = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if ($u) {
            $_SESSION['pwv'] = self::passwordVersion($u);
            self::$user = $u;
        }
    }

    public static function publicUser(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'display_name' => $u['display_name'],
            'is_admin' => (int) $u['is_admin'] === 1,
            'must_change_password' => (int) $u['must_change_password'] === 1,
        ];
    }
}
