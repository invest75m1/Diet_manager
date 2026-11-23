<?php
declare(strict_types=1);

namespace App;

/** Výpočty cieľov, denných súčtov a vyhodnotenia. */
final class Nutrition
{
    public const KEYS = ['kcal', 'protein', 'carbs', 'fat', 'fiber'];
    /** Typ cieľa: 'max' = nemal by sa prekročiť, 'min' = treba dosiahnuť. */
    public const KIND = ['kcal' => 'max', 'protein' => 'min', 'carbs' => 'max', 'fat' => 'max', 'fiber' => 'min'];
    public const TOLERANCE = 0.05;

    public const ACTIVITY = [
        'sedentary' => 1.2,   // sedavé zamestnanie, bez pohybu
        'light' => 1.375,     // ľahký pohyb
        'moderate' => 1.55,   // stredne aktívny
        'active' => 1.725,    // veľmi aktívny
        'very_active' => 1.9, // fyzicky náročná práca
    ];

    /** Cieľ platný pre daný deň (posledný s valid_from <= dátum). */
    public static function baseGoal(int $userId, string $date): ?array
    {
        $g = Db::one('SELECT * FROM goals WHERE user_id = :u AND valid_from <= :d ORDER BY valid_from DESC LIMIT 1',
            ['u' => $userId, 'd' => $date]);
        if (!$g) {
            // Ak existuje len cieľ s neskorším dátumom, použijeme najstarší.
            $g = Db::one('SELECT * FROM goals WHERE user_id = :u ORDER BY valid_from ASC LIMIT 1', ['u' => $userId]);
        }
        return $g ? self::castGoal($g) : null;
    }

    /** Všetky ciele používateľa (pre reporty), zoradené podľa dátumu. */
    public static function goalHistory(int $userId): array
    {
        return array_map([self::class, 'castGoal'],
            Db::all('SELECT * FROM goals WHERE user_id = :u ORDER BY valid_from ASC', ['u' => $userId]));
    }

    public static function goalFromHistory(array $history, string $date): ?array
    {
        $found = null;
        foreach ($history as $g) {
            if ($g['valid_from'] <= $date) {
                $found = $g;
            }
        }
        return $found ?? ($history[0] ?? null);
    }

    public static function castGoal(array $g): array
    {
        return [
            'valid_from' => $g['valid_from'],
            'kcal' => (float) $g['kcal'],
            'protein' => (float) $g['protein'],
            'carbs' => (float) $g['carbs'],
            'fat' => (float) $g['fat'],
            'fiber' => (float) $g['fiber'],
            'water_ml' => (int) $g['water_ml'],
            'input_mode' => $g['input_mode'] ?? 'grams',
        ];
    }

    /**
     * Denný cieľ navýšený o kalórie z tréningu.
     * Bielkoviny ostávajú, extra kalórie sa rozdelia medzi sacharidy a tuky
     * v rovnakom pomere, aký majú v základnom cieli.
     */
    public static function withTraining(?array $goal, float $trainingKcal): ?array
    {
        if (!$goal) {
            return null;
        }
        $goal['training_kcal'] = round($trainingKcal, 1);
        if ($trainingKcal <= 0) {
            return $goal;
        }
        $carbKcal = $goal['carbs'] * 4;
        $fatKcal = $goal['fat'] * 9;
        $sum = $carbKcal + $fatKcal;
        $carbShare = $sum > 0 ? $carbKcal / $sum : 0.6;
        $goal['kcal'] = round($goal['kcal'] + $trainingKcal, 1);
        $goal['carbs'] = round($goal['carbs'] + $trainingKcal * $carbShare / 4, 1);
        $goal['fat'] = round($goal['fat'] + $trainingKcal * (1 - $carbShare) / 9, 1);
        return $goal;
    }

    /** Návrh cieľa podľa Mifflin-St Jeor. */
    public static function suggest(array $p): array
    {
        $sex = ($p['sex'] ?? 'male') === 'female' ? 'female' : 'male';
        $age = max(14, min(100, (int) date('Y') - (int) ($p['birth_year'] ?? 1985)));
        $h = (float) ($p['height_cm'] ?? 175);
        $w = (float) ($p['weight_kg'] ?? 75);
        $factor = self::ACTIVITY[$p['activity_level'] ?? 'light'] ?? 1.375;
        $goalType = $p['goal_type'] ?? 'maintain';

        $bmr = 10 * $w + 6.25 * $h - 5 * $age + ($sex === 'male' ? 5 : -161);
        $tdee = $bmr * $factor;
        $kcal = match ($goalType) {
            'lose' => $tdee * 0.80,
            'gain' => $tdee * 1.10,
            default => $tdee,
        };
        $kcal = max($sex === 'male' ? 1500 : 1200, $kcal);
        $proteinPerKg = match ($goalType) { 'lose' => 1.8, 'gain' => 1.8, default => 1.6 };
        $protein = $w * $proteinPerKg;
        $fat = $kcal * 0.28 / 9;
        $carbs = max(0, ($kcal - $protein * 4 - $fat * 9) / 4);
        return [
            'bmr' => round($bmr),
            'tdee' => round($tdee),
            'kcal' => round($kcal / 10) * 10,
            'protein' => round($protein),
            'carbs' => round($carbs),
            'fat' => round($fat),
            'fiber' => round(max(25, $kcal / 1000 * 14)),
            'water_ml' => (int) (round($w * 35 / 250) * 250),
        ];
    }

    public static function emptyTotals(): array
    {
        return array_fill_keys(self::KEYS, 0.0);
    }

    public static function sumEntries(array $entries): array
    {
        $t = self::emptyTotals();
        foreach ($entries as $e) {
            foreach (self::KEYS as $k) {
                $t[$k] += (float) $e[$k];
            }
        }
        return array_map(fn($v) => round($v, 1), $t);
    }

    /**
     * Vyhodnotenie jednej hodnoty voči cieľu:
     *  max-ciele: 'in' (≤ cieľ + 5 %), 'over' (nad)
     *  min-ciele: 'in' (≥ cieľ − 5 %), 'under' (pod)
     */
    public static function evaluate(string $key, float $value, float $goal): ?string
    {
        if ($goal <= 0) {
            return null;
        }
        $r = $value / $goal;
        if (self::KIND[$key] === 'max') {
            return $r > 1 + self::TOLERANCE ? 'over' : 'in';
        }
        return $r < 1 - self::TOLERANCE ? 'under' : 'in';
    }

    /** Hodnoty jedla na zadané množstvo gramov (potraviny sú uložené na 100 g). */
    public static function scaleFood(array $food, float $grams): array
    {
        $f = $grams / 100;
        return [
            'kcal' => round((float) $food['kcal'] * $f, 1),
            'protein' => round((float) $food['protein'] * $f, 1),
            'carbs' => round((float) $food['carbs'] * $f, 1),
            'fat' => round((float) $food['fat'] * $f, 1),
            'fiber' => round((float) $food['fiber'] * $f, 1),
        ];
    }

    /** Priradenie jedla dňa podľa času. */
    public static function mealForTime(string $hhmm): string
    {
        $m = (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
        return match (true) {
            $m >= 240 && $m < 600 => 'breakfast',   // 4:00–9:59
            $m >= 600 && $m < 690 => 'snack1',      // 10:00–11:29
            $m >= 690 && $m < 870 => 'lunch',       // 11:30–14:29
            $m >= 870 && $m < 1050 => 'snack2',     // 14:30–17:29
            $m >= 1050 && $m < 1290 => 'dinner',    // 17:30–21:29
            default => 'other',
        };
    }

    public const MEALS = ['breakfast', 'snack1', 'lunch', 'snack2', 'dinner', 'other'];
}
