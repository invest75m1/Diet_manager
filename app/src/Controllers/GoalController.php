<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Auth, Db, Http, HttpError, Nutrition, Validate};

final class GoalController
{
    public static function get(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $profile = Db::one('SELECT sex, birth_year, height_cm, weight_kg, activity_level, goal_type FROM profiles WHERE user_id = :u', ['u' => $uid]);
        $lastWeight = Db::val('SELECT kg FROM weights WHERE user_id = :u ORDER BY log_date DESC LIMIT 1', ['u' => $uid]);
        if ($profile && $lastWeight) {
            $profile['weight_kg'] = (float) $lastWeight;
        }
        return [
            'goal' => Nutrition::baseGoal($uid, date('Y-m-d')),
            'history' => array_reverse(Nutrition::goalHistory($uid)),
            'profile' => $profile ? [
                'sex' => $profile['sex'],
                'birth_year' => $profile['birth_year'] !== null ? (int) $profile['birth_year'] : null,
                'height_cm' => $profile['height_cm'] !== null ? (float) $profile['height_cm'] : null,
                'weight_kg' => $profile['weight_kg'] !== null ? (float) $profile['weight_kg'] : null,
                'activity_level' => $profile['activity_level'],
                'goal_type' => $profile['goal_type'],
            ] : ['weight_kg' => $lastWeight ? (float) $lastWeight : null],
        ];
    }

    public static function save(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $b = Http::body();
        $data = [
            'kcal' => Validate::num($b, 'kcal', 500, 10000, true, null, 'Kalórie'),
            'protein' => Validate::num($b, 'protein', 0, 1000, true, null, 'Bielkoviny'),
            'carbs' => Validate::num($b, 'carbs', 0, 2000, true, null, 'Sacharidy'),
            'fat' => Validate::num($b, 'fat', 0, 1000, true, null, 'Tuky'),
            'fiber' => Validate::num($b, 'fiber', 0, 200, false, 0, 'Vláknina'),
            'water_ml' => (int) Validate::num($b, 'water_ml', 0, 10000, false, 0, 'Voda'),
            'input_mode' => Validate::oneOf($b, 'input_mode', ['grams', 'percent'], 'grams'),
        ];
        $from = Validate::date($b, 'valid_from', false) ?? date('Y-m-d');
        $existing = Db::val('SELECT id FROM goals WHERE user_id = :u AND valid_from = :d', ['u' => $uid, 'd' => $from]);
        if ($existing) {
            Db::update('goals', $data, 'id = :id', ['id' => (int) $existing]);
        } else {
            Db::insert('goals', $data + ['user_id' => $uid, 'valid_from' => $from, 'created_at' => Db::now()]);
        }
        return ['goal' => Nutrition::baseGoal($uid, date('Y-m-d'))];
    }

    public static function suggest(): array
    {
        $u = Auth::require();
        $b = Http::body();
        $p = [
            'sex' => Validate::oneOf($b, 'sex', ['male', 'female']),
            'birth_year' => (int) Validate::num($b, 'birth_year', 1920, (int) date('Y') - 10, true, null, 'Rok narodenia'),
            'height_cm' => Validate::num($b, 'height_cm', 100, 250, true, null, 'Výška'),
            'weight_kg' => Validate::num($b, 'weight_kg', 30, 300, true, null, 'Hmotnosť'),
            'activity_level' => Validate::oneOf($b, 'activity_level', array_keys(Nutrition::ACTIVITY)),
            'goal_type' => Validate::oneOf($b, 'goal_type', ['lose', 'maintain', 'gain']),
        ];
        $uid = (int) $u['id'];
        $row = $p + ['updated_at' => Db::now()];
        if (Db::val('SELECT user_id FROM profiles WHERE user_id = :u', ['u' => $uid])) {
            Db::update('profiles', $row, 'user_id = :uid', ['uid' => $uid]);
        } else {
            Db::insert('profiles', $row + ['user_id' => $uid]);
        }
        return ['suggestion' => Nutrition::suggest($p)];
    }
}
