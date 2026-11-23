<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Auth, Db, Http, HttpError, Images, Validate};

/** Voda, hmotnosť a tréningy. */
final class BodyController
{
    public static function addWater(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $b = Http::body();
        $date = Validate::date($b, 'date', false) ?? date('Y-m-d');
        $delta = (int) Validate::num($b, 'delta', -5000, 5000, true, null, 'Množstvo');
        $cur = Db::val('SELECT ml FROM water_logs WHERE user_id = :u AND log_date = :d', ['u' => $uid, 'd' => $date]);
        $new = max(0, (int) ($cur ?? 0) + $delta);
        if ($cur === null) {
            Db::insert('water_logs', ['user_id' => $uid, 'log_date' => $date, 'ml' => $new]);
        } else {
            Db::run('UPDATE water_logs SET ml = :ml WHERE user_id = :u AND log_date = :d', ['ml' => $new, 'u' => $uid, 'd' => $date]);
        }
        return ['water_ml' => $new];
    }

    public static function weights(): array
    {
        $u = Auth::require();
        $from = Validate::date($_GET, 'from', false) ?? date('Y-m-d', strtotime('-365 days'));
        $to = Validate::date($_GET, 'to', false) ?? date('Y-m-d');
        $rows = Db::all('SELECT id, log_date, kg FROM weights WHERE user_id = :u AND log_date BETWEEN :f AND :t ORDER BY log_date',
            ['u' => (int) $u['id'], 'f' => $from, 't' => $to]);
        return ['weights' => array_map(fn($r) => ['id' => (int) $r['id'], 'date' => $r['log_date'], 'kg' => (float) $r['kg']], $rows)];
    }

    public static function saveWeight(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $b = Http::body();
        $date = Validate::date($b, 'date', false) ?? date('Y-m-d');
        $kg = round((float) Validate::num($b, 'kg', 20, 400, true, null, 'Hmotnosť'), 1);
        $id = Db::val('SELECT id FROM weights WHERE user_id = :u AND log_date = :d', ['u' => $uid, 'd' => $date]);
        if ($id) {
            Db::run('UPDATE weights SET kg = :kg WHERE id = :id', ['kg' => $kg, 'id' => (int) $id]);
        } else {
            Db::insert('weights', ['user_id' => $uid, 'log_date' => $date, 'kg' => $kg, 'created_at' => Db::now()]);
        }
        // Aktualizujeme aj profil (pre návrh cieľa).
        Db::run('UPDATE profiles SET weight_kg = :kg WHERE user_id = :u', ['kg' => $kg, 'u' => $uid]);
        return ['ok' => true];
    }

    public static function deleteWeight(string $id): array
    {
        $u = Auth::require();
        Db::run('DELETE FROM weights WHERE id = :id AND user_id = :u', ['id' => (int) $id, 'u' => (int) $u['id']]);
        return ['ok' => true];
    }

    public static function activityOut(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'date' => $a['log_date'],
            'name' => $a['name'],
            'kcal' => (float) $a['kcal'],
            'duration_min' => $a['duration_min'] !== null ? (int) $a['duration_min'] : null,
            'photo' => $a['photo'],
        ];
    }

    public static function activities(): array
    {
        $u = Auth::require();
        $from = Validate::date($_GET, 'from', false) ?? date('Y-m-d', strtotime('-60 days'));
        $to = Validate::date($_GET, 'to', false) ?? date('Y-m-d');
        $rows = Db::all('SELECT * FROM activities WHERE user_id = :u AND log_date BETWEEN :f AND :t ORDER BY log_date DESC, id DESC',
            ['u' => (int) $u['id'], 'f' => $from, 't' => $to]);
        return ['activities' => array_map([self::class, 'activityOut'], $rows)];
    }

    public static function saveActivity(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $b = Http::body();
        $photo = $b['photo'] ?? null;
        $id = Db::insert('activities', [
            'user_id' => $uid,
            'log_date' => Validate::date($b, 'date', false) ?? date('Y-m-d'),
            'name' => Validate::str($b, 'name', 1, 120, false) ?? 'Tréning',
            'kcal' => Validate::num($b, 'kcal', 0, 10000, true, null, 'Kalórie'),
            'duration_min' => ($d = Validate::num($b, 'duration_min', 0, 2000, false, null)) !== null ? (int) $d : null,
            'photo' => $photo && Images::isValidRef($uid, (string) $photo) ? $photo : null,
            'created_at' => Db::now(),
        ]);
        return ['id' => $id];
    }

    public static function deleteActivity(string $id): array
    {
        $u = Auth::require();
        Db::run('DELETE FROM activities WHERE id = :id AND user_id = :u', ['id' => (int) $id, 'u' => (int) $u['id']]);
        return ['ok' => true];
    }
}
