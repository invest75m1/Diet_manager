<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Auth, Db, Http, HttpError, AiService, Config};

final class AuthController
{
    public static function me(): array
    {
        $u = Auth::user();
        return [
            'user' => $u ? Auth::publicUser($u) : null,
            'ai_enabled' => AiService::isEnabled(),
            'version' => APP_VERSION,
        ];
    }

    public static function login(): array
    {
        $b = Http::body();
        $username = trim((string) ($b['username'] ?? ''));
        $password = (string) ($b['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new HttpError(422, 'Zadajte meno aj heslo');
        }
        $u = Auth::attempt($username, $password, !empty($b['remember']));
        return ['user' => Auth::publicUser($u), 'ai_enabled' => AiService::isEnabled()];
    }

    public static function logout(): array
    {
        Auth::logout();
        return ['ok' => true];
    }

    public static function changePassword(): array
    {
        $u = Auth::require(true);
        $b = Http::body();
        $current = (string) ($b['current_password'] ?? '');
        $new = (string) ($b['new_password'] ?? '');
        if (!password_verify($current, $u['password_hash'])) {
            throw new HttpError(422, 'Aktuálne heslo nie je správne');
        }
        Auth::validatePassword($new);
        if ($new === $current) {
            throw new HttpError(422, 'Nové heslo musí byť iné ako súčasné');
        }
        Db::run('UPDATE users SET password_hash = :h, must_change_password = 0 WHERE id = :id',
            ['h' => password_hash($new, PASSWORD_DEFAULT), 'id' => (int) $u['id']]);
        // Odhlási ostatné zariadenia, toto ponechá prihlásené.
        Db::run('DELETE FROM auth_tokens WHERE user_id = :id', ['id' => (int) $u['id']]);
        Auth::refreshSession((int) $u['id']);
        if (!empty($_COOKIE[Auth::REMEMBER_COOKIE])) {
            Auth::issueRememberToken((int) $u['id']);
        }
        $u = Db::one('SELECT * FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        return ['user' => Auth::publicUser($u)];
    }
}
