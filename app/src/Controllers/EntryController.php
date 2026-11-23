<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Auth, Db, Http, HttpError, Images, Nutrition, Validate};

final class EntryController
{
    public const SOURCES = ['food', 'ai_text', 'ai_photo', 'manual', 'copy'];

    public static function out(array $e): array
    {
        return [
            'id' => (int) $e['id'],
            'date' => $e['entry_date'],
            'time' => $e['eaten_time'],
            'meal' => $e['meal'],
            'name' => $e['name'],
            'food_id' => $e['food_id'] !== null ? (int) $e['food_id'] : null,
            'amount_g' => $e['amount_g'] !== null ? (float) $e['amount_g'] : null,
            'kcal' => (float) $e['kcal'],
            'protein' => (float) $e['protein'],
            'carbs' => (float) $e['carbs'],
            'fat' => (float) $e['fat'],
            'fiber' => (float) $e['fiber'],
            'source' => $e['source'],
            'photo' => $e['photo'],
            'note' => $e['note'],
        ];
    }

    /** Prehľad dňa: cieľ, tréningy, záznamy, súčty, voda, váha. */
    public static function day(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $date = Validate::date($_GET, 'date', false) ?? date('Y-m-d');
        $entries = Db::all('SELECT * FROM entries WHERE user_id = :u AND entry_date = :d ORDER BY eaten_time, id', ['u' => $uid, 'd' => $date]);
        $activities = Db::all('SELECT * FROM activities WHERE user_id = :u AND log_date = :d ORDER BY id', ['u' => $uid, 'd' => $date]);
        $trainingKcal = array_sum(array_map(fn($a) => (float) $a['kcal'], $activities));
        $base = Nutrition::baseGoal($uid, $date);
        $weight = Db::val('SELECT kg FROM weights WHERE user_id = :u AND log_date = :d', ['u' => $uid, 'd' => $date]);
        return [
            'date' => $date,
            'today' => date('Y-m-d'),
            'goal' => Nutrition::withTraining($base, $trainingKcal),
            'base_goal' => $base,
            'activities' => array_map([BodyController::class, 'activityOut'], $activities),
            'entries' => array_map([self::class, 'out'], $entries),
            'totals' => Nutrition::sumEntries($entries),
            'water_ml' => (int) (Db::val('SELECT ml FROM water_logs WHERE user_id = :u AND log_date = :d', ['u' => $uid, 'd' => $date]) ?? 0),
            'weight_kg' => $weight !== null ? (float) $weight : null,
        ];
    }

    /**
     * Pripraví riadok záznamu. Ak je zadaná potravina + gramy, hodnoty sa vypočítajú na serveri.
     */
    private static function row(int $uid, array $it, array $common): array
    {
        $amount = Validate::num($it, 'amount_g', 0, 20000, false, null, 'Množstvo');
        $foodId = isset($it['food_id']) && $it['food_id'] ? (int) $it['food_id'] : null;
        $name = Validate::str($it, 'name', 0, 200, false);
        if ($foodId) {
            $food = FoodController::visible($uid, $foodId);
            if (!$amount) {
                throw new HttpError(422, 'Zadajte množstvo v gramoch');
            }
            $vals = Nutrition::scaleFood($food, $amount);
            $name = $name ?: $food['name'] . ($food['brand'] ? ' (' . $food['brand'] . ')' : '');
        } else {
            if (!$name) {
                throw new HttpError(422, 'Zadajte názov jedla');
            }
            $vals = [
                'kcal' => Validate::num($it, 'kcal', 0, 20000, true, null, 'Kalórie'),
                'protein' => Validate::num($it, 'protein', 0, 2000, false, 0, 'Bielkoviny'),
                'carbs' => Validate::num($it, 'carbs', 0, 3000, false, 0, 'Sacharidy'),
                'fat' => Validate::num($it, 'fat', 0, 2000, false, 0, 'Tuky'),
                'fiber' => Validate::num($it, 'fiber', 0, 500, false, 0, 'Vláknina'),
            ];
        }
        return $common + $vals + [
            'name' => mb_substr($name, 0, 200),
            'food_id' => $foodId,
            'amount_g' => $amount ?: null,
            'source' => Validate::oneOf($it, 'source', self::SOURCES, $foodId ? 'food' : 'manual'),
            'note' => Validate::str($it, 'note', 0, 500, false),
        ];
    }

    private static function common(int $uid, array $b): array
    {
        $date = Validate::date($b, 'date', false) ?? date('Y-m-d');
        $time = Validate::time($b, 'time');
        $meal = Validate::oneOf($b, 'meal', Nutrition::MEALS, Nutrition::mealForTime($time));
        $photo = $b['photo'] ?? null;
        if ($photo !== null && $photo !== '' && !Images::isValidRef($uid, (string) $photo)) {
            $photo = null;
        }
        return [
            'user_id' => $uid,
            'entry_date' => $date,
            'eaten_time' => $time,
            'meal' => $meal,
            'photo' => $photo ?: null,
        ];
    }

    /** Uloží jeden alebo viac záznamov naraz ({items: [...]} alebo jeden objekt). */
    public static function create(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $b = Http::body();
        $common = self::common($uid, $b);
        $items = isset($b['items']) && is_array($b['items']) ? $b['items'] : [$b];
        if (count($items) > 50) {
            throw new HttpError(422, 'Príliš veľa položiek naraz');
        }
        $ids = Db::tx(function () use ($uid, $items, $common) {
            $ids = [];
            foreach ($items as $it) {
                $ids[] = Db::insert('entries', self::row($uid, (array) $it, $common) + ['created_at' => Db::now(), 'updated_at' => Db::now()]);
            }
            return $ids;
        });
        return ['ids' => $ids];
    }

    public static function update(string $id): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $e = self::find($uid, (int) $id);
        $b = Http::body();
        $data = [];
        if (isset($b['date'])) {
            $data['entry_date'] = Validate::date($b, 'date');
        }
        if (isset($b['time'])) {
            $data['eaten_time'] = Validate::time($b, 'time');
        }
        if (isset($b['meal'])) {
            $data['meal'] = Validate::oneOf($b, 'meal', Nutrition::MEALS);
        }
        if (isset($b['name'])) {
            $data['name'] = Validate::str($b, 'name', 1, 200, true, 'Názov');
        }
        if (array_key_exists('note', $b)) {
            $data['note'] = Validate::str($b, 'note', 0, 500, false);
        }
        $newAmount = Validate::num($b, 'amount_g', 0.1, 20000, false, null, 'Množstvo');
        $hasValues = isset($b['kcal']);
        if ($hasValues) {
            // Užívateľ priamo upravil hodnoty.
            foreach (['kcal' => 20000, 'protein' => 2000, 'carbs' => 3000, 'fat' => 2000, 'fiber' => 500] as $k => $max) {
                $data[$k] = Validate::num($b, $k, 0, $max, false, 0);
            }
            if ($newAmount) {
                $data['amount_g'] = $newAmount;
            }
        } elseif ($newAmount && (float) $newAmount !== (float) $e['amount_g']) {
            if ($e['food_id']) {
                $food = Db::one('SELECT * FROM foods WHERE id = :id', ['id' => (int) $e['food_id']]);
                $data += Nutrition::scaleFood($food, $newAmount);
            } elseif ((float) $e['amount_g'] > 0) {
                // Pomerné prepočítanie (napr. AI odhad).
                $r = $newAmount / (float) $e['amount_g'];
                foreach (Nutrition::KEYS as $k) {
                    $data[$k] = round((float) $e[$k] * $r, 1);
                }
            }
            $data['amount_g'] = $newAmount;
        }
        if ($data) {
            Db::update('entries', $data + ['updated_at' => Db::now()], 'id = :id', ['id' => (int) $id]);
        }
        return ['entry' => self::out(self::find($uid, (int) $id))];
    }

    public static function delete(string $id): array
    {
        $u = Auth::require();
        self::find((int) $u['id'], (int) $id);
        Db::run('DELETE FROM entries WHERE id = :id', ['id' => (int) $id]);
        return ['ok' => true];
    }

    /** Skopíruje záznamy jedla (alebo celého dňa) z iného dňa. */
    public static function copy(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $b = Http::body();
        $from = Validate::date($b, 'from_date');
        $to = Validate::date($b, 'to_date');
        $meal = isset($b['meal']) && $b['meal'] !== '' ? Validate::oneOf($b, 'meal', Nutrition::MEALS) : null;
        $sql = 'SELECT * FROM entries WHERE user_id = :u AND entry_date = :d';
        $params = ['u' => $uid, 'd' => $from];
        if ($meal) {
            $sql .= ' AND meal = :m';
            $params['m'] = $meal;
        }
        $rows = Db::all($sql . ' ORDER BY eaten_time, id', $params);
        $n = Db::tx(function () use ($rows, $to) {
            foreach ($rows as $r) {
                unset($r['id']);
                $r['entry_date'] = $to;
                $r['created_at'] = $r['updated_at'] = Db::now();
                Db::insert('entries', $r);
            }
            return count($rows);
        });
        return ['copied' => $n];
    }

    private static function find(int $uid, int $id): array
    {
        $e = Db::one('SELECT * FROM entries WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]);
        if (!$e) {
            throw new HttpError(404, 'Záznam neexistuje');
        }
        return $e;
    }
}
