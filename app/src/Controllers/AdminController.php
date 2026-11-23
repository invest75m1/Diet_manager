<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Auth, Config, Db, Http, HttpError, Images, Validate};

final class AdminController
{
    public static function list(): array
    {
        Auth::requireAdmin();
        $rows = Db::all('SELECT id, username, display_name, is_admin, is_active, must_change_password, created_at, last_login_at FROM users ORDER BY username');
        return [
            'users' => array_map(fn($r) => [
                'id' => (int) $r['id'],
                'username' => $r['username'],
                'display_name' => $r['display_name'],
                'is_admin' => (int) $r['is_admin'] === 1,
                'is_active' => (int) $r['is_active'] === 1,
                'must_change_password' => (int) $r['must_change_password'] === 1,
                'created_at' => $r['created_at'],
                'last_login_at' => $r['last_login_at'],
            ], $rows),
            'max_users' => (int) Config::get('max_users', 10),
        ];
    }

    public static function create(): array
    {
        Auth::requireAdmin();
        $b = Http::body();
        $username = mb_strtolower((string) Validate::str($b, 'username', 3, 32, true, 'Prihlasovacie meno'));
        if (!preg_match('/^[a-z0-9._-]+$/', $username)) {
            throw new HttpError(422, 'Prihlasovacie meno môže obsahovať len malé písmená bez diakritiky, číslice, bodku, pomlčku a podčiarkovník');
        }
        $display = Validate::str($b, 'display_name', 1, 80, false) ?? $username;
        $password = (string) ($b['password'] ?? '');
        Auth::validatePassword($password);
        $max = (int) Config::get('max_users', 10);
        if ((int) Db::val('SELECT COUNT(*) FROM users') >= $max) {
            throw new HttpError(422, "Dosiahnutý maximálny počet užívateľov ($max)");
        }
        if (Db::val('SELECT id FROM users WHERE username = :u', ['u' => $username])) {
            throw new HttpError(422, 'Užívateľ s týmto menom už existuje');
        }
        $id = Db::insert('users', [
            'username' => $username,
            'display_name' => $display,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin' => 0,
            'is_active' => 1,
            'must_change_password' => 1,
            'created_at' => Db::now(),
        ]);
        return ['id' => $id];
    }

    public static function update(string $id): array
    {
        $admin = Auth::requireAdmin();
        $id = (int) $id;
        $user = self::find($id);
        $b = Http::body();
        $data = [];
        if (array_key_exists('display_name', $b)) {
            $data['display_name'] = Validate::str($b, 'display_name', 1, 80, true, 'Meno');
        }
        if (array_key_exists('is_active', $b)) {
            if ($id === (int) $admin['id'] && !$b['is_active']) {
                throw new HttpError(422, 'Nemôžete deaktivovať sám seba');
            }
            $data['is_active'] = $b['is_active'] ? 1 : 0;
        }
        if ($data) {
            Db::update('users', $data, 'id = :id', ['id' => $id]);
        }
        if (isset($data['is_active']) && $data['is_active'] === 0) {
            Db::run('DELETE FROM auth_tokens WHERE user_id = :id', ['id' => $id]);
        }
        return ['ok' => true, 'username' => $user['username']];
    }

    public static function resetPassword(string $id): array
    {
        Auth::requireAdmin();
        $id = (int) $id;
        self::find($id);
        $password = (string) Http::input('password', '');
        Auth::validatePassword($password);
        Db::run('UPDATE users SET password_hash = :h, must_change_password = 1 WHERE id = :id',
            ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
        Db::run('DELETE FROM auth_tokens WHERE user_id = :id', ['id' => $id]);
        Db::run('DELETE FROM login_attempts WHERE username = (SELECT username FROM users WHERE id = :id)', ['id' => $id]);
        return ['ok' => true];
    }

    public static function delete(string $id): array
    {
        $admin = Auth::requireAdmin();
        $id = (int) $id;
        if ($id === (int) $admin['id']) {
            throw new HttpError(422, 'Nemôžete zmazať vlastný účet');
        }
        self::find($id);
        Db::tx(function () use ($id) {
            // Explicitné mazanie (pre istotu aj bez cudzích kľúčov).
            foreach (['favorites', 'entries', 'water_logs', 'weights', 'activities', 'goals', 'profiles', 'auth_tokens'] as $t) {
                Db::run("DELETE FROM $t WHERE user_id = :id", ['id' => $id]);
            }
            Db::run('DELETE FROM recipe_items WHERE recipe_id IN (SELECT id FROM foods WHERE owner_id = :id)', ['id' => $id]);
            Db::run('DELETE FROM foods WHERE owner_id = :id', ['id' => $id]);
            Db::run('DELETE FROM users WHERE id = :id', ['id' => $id]);
        });
        Images::deleteUserPhotos($id);
        return ['ok' => true];
    }

    private static function find(int $id): array
    {
        $u = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if (!$u) {
            throw new HttpError(404, 'Užívateľ neexistuje');
        }
        return $u;
    }
}
