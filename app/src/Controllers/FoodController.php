<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Auth, Db, Http, HttpError, Nutrition, OpenFoodFacts, Validate};

final class FoodController
{
    public static function out(array $f, ?array $favIds = null): array
    {
        return [
            'id' => (int) $f['id'],
            'name' => $f['name'],
            'brand' => $f['brand'],
            'barcode' => $f['barcode'],
            'source' => $f['source'],
            'is_own' => $f['owner_id'] !== null,
            'is_recipe' => (int) $f['is_recipe'] === 1,
            'kcal' => (float) $f['kcal'],
            'protein' => (float) $f['protein'],
            'carbs' => (float) $f['carbs'],
            'sugar' => $f['sugar'] !== null ? (float) $f['sugar'] : null,
            'fat' => (float) $f['fat'],
            'fiber' => (float) $f['fiber'],
            'serving_name' => $f['serving_name'],
            'serving_g' => $f['serving_g'] !== null ? (float) $f['serving_g'] : null,
            'favorite' => $favIds !== null && in_array((int) $f['id'], $favIds, true),
        ];
    }

    private static function favIds(int $uid): array
    {
        return array_map('intval', array_column(Db::all('SELECT food_id FROM favorites WHERE user_id = :u', ['u' => $uid]), 'food_id'));
    }

    /** Potravina viditeľná pre užívateľa (vlastná alebo zdieľaná z databázy). */
    public static function visible(int $uid, int $id): array
    {
        $f = Db::one('SELECT * FROM foods WHERE id = :id AND (owner_id IS NULL OR owner_id = :u)', ['id' => $id, 'u' => $uid]);
        if (!$f) {
            throw new HttpError(404, 'Potravina neexistuje');
        }
        return $f;
    }

    public static function search(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $q = trim((string) Http::query('q', ''));
        $fav = self::favIds($uid);
        if (mb_strlen($q) < 2) {
            return ['foods' => []];
        }
        $words = array_slice(array_filter(preg_split('/\s+/', mb_strtolower($q))), 0, 5);
        $where = [];
        $params = ['u' => $uid];
        foreach ($words as $i => $w) {
            $where[] = "(LOWER(name) LIKE :n$i OR LOWER(COALESCE(brand,'')) LIKE :b$i)";
            $params["n$i"] = $params["b$i"] = '%' . str_replace(['%', '_'], ['\%', '\_'], $w) . '%';
        }
        $rows = Db::all('SELECT * FROM foods WHERE (owner_id IS NULL OR owner_id = :u) AND ' . implode(' AND ', $where) .
            ' ORDER BY CASE WHEN owner_id IS NULL THEN 1 ELSE 0 END, LENGTH(name) LIMIT 40', $params);
        // Najprv obľúbené, potom vlastné, potom ostatné.
        usort($rows, fn($a, $b) => (int) in_array((int) $b['id'], $fav, true) <=> (int) in_array((int) $a['id'], $fav, true));
        return ['foods' => array_map(fn($f) => self::out($f, $fav), $rows)];
    }

    /** Vyhľadanie v Open Food Facts; výsledky sa uložia do lokálnej databázy. */
    public static function searchOnline(): array
    {
        $u = Auth::require();
        $q = trim((string) Http::query('q', ''));
        if (mb_strlen($q) < 2) {
            return ['foods' => []];
        }
        $out = [];
        foreach (OpenFoodFacts::search($q) as $p) {
            $out[] = self::upsertShared($p);
        }
        $fav = self::favIds((int) $u['id']);
        return ['foods' => array_map(fn($f) => self::out($f, $fav), $out)];
    }

    public static function barcode(string $code): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        if (!preg_match('/^\d{6,14}$/', $code)) {
            throw new HttpError(422, 'Neplatný čiarový kód');
        }
        $fav = self::favIds($uid);
        // Vlastné potraviny s týmto kódom majú prednosť.
        $f = Db::one('SELECT * FROM foods WHERE barcode = :c AND owner_id = :u ORDER BY id DESC LIMIT 1', ['c' => $code, 'u' => $uid])
            ?? Db::one('SELECT * FROM foods WHERE barcode = :c AND owner_id IS NULL ORDER BY id DESC LIMIT 1', ['c' => $code]);
        if (!$f) {
            $p = OpenFoodFacts::byBarcode($code);
            if (!$p) {
                return ['food' => null];
            }
            $f = self::upsertShared($p);
        }
        return ['food' => self::out($f, $fav)];
    }

    private static function upsertShared(array $p): array
    {
        $existing = Db::one("SELECT * FROM foods WHERE barcode = :c AND owner_id IS NULL AND source = 'off'", ['c' => $p['barcode']]);
        $data = $p + ['updated_at' => Db::now()];
        if ($existing) {
            Db::update('foods', $data, 'id = :id', ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = Db::insert('foods', $data + ['owner_id' => null, 'source' => 'off', 'is_recipe' => 0, 'created_at' => Db::now()]);
        }
        return Db::one('SELECT * FROM foods WHERE id = :id', ['id' => $id]);
    }

    /** Naposledy jedené – posledné rôzne položky (potraviny aj AI záznamy). */
    public static function recent(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $fav = self::favIds($uid);
        $rows = Db::all('SELECT e.*, f.id AS f_id FROM entries e LEFT JOIN foods f ON f.id = e.food_id
            WHERE e.user_id = :u ORDER BY e.entry_date DESC, e.created_at DESC LIMIT 300', ['u' => $uid]);
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $key = $r['f_id'] ? 'f' . $r['f_id'] : 'n' . mb_strtolower($r['name']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($r['f_id']) {
                $food = Db::one('SELECT * FROM foods WHERE id = :id', ['id' => (int) $r['f_id']]);
                $out[] = ['type' => 'food', 'food' => self::out($food, $fav), 'last_amount_g' => $r['amount_g'] !== null ? (float) $r['amount_g'] : null];
            } else {
                $out[] = ['type' => 'entry', 'entry' => EntryController::out($r)];
            }
            if (count($out) >= 30) {
                break;
            }
        }
        $favorites = Db::all('SELECT f.* FROM favorites fa JOIN foods f ON f.id = fa.food_id WHERE fa.user_id = :u ORDER BY f.name', ['u' => $uid]);
        return ['recent' => $out, 'favorites' => array_map(fn($f) => self::out($f, $fav), $favorites)];
    }

    public static function mine(): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $fav = self::favIds($uid);
        $rows = Db::all('SELECT * FROM foods WHERE owner_id = :u ORDER BY is_recipe DESC, name', ['u' => $uid]);
        return ['foods' => array_map(fn($f) => self::out($f, $fav), $rows)];
    }

    public static function show(string $id): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $f = self::visible($uid, (int) $id);
        $out = self::out($f, self::favIds($uid));
        if ((int) $f['is_recipe'] === 1) {
            $out['recipe_total_g'] = $f['recipe_total_g'] !== null ? (float) $f['recipe_total_g'] : null;
            $out['items'] = array_map(fn($r) => [
                'food' => self::out($r),
                'grams' => (float) $r['grams'],
            ], Db::all('SELECT f.*, ri.grams FROM recipe_items ri JOIN foods f ON f.id = ri.food_id WHERE ri.recipe_id = :r ORDER BY ri.id', ['r' => (int) $f['id']]));
        }
        return ['food' => $out];
    }

    private static function foodData(array $b): array
    {
        $servingG = Validate::num($b, 'serving_g', 0, 5000, false, null, 'Porcia (g)');
        $barcode = trim((string) ($b['barcode'] ?? ''));
        if ($barcode !== '' && !preg_match('/^\d{6,14}$/', $barcode)) {
            throw new HttpError(422, 'Neplatný čiarový kód');
        }
        return [
            'name' => Validate::str($b, 'name', 1, 200, true, 'Názov'),
            'brand' => Validate::str($b, 'brand', 0, 120, false),
            'barcode' => $barcode ?: null,
            'kcal' => Validate::num($b, 'kcal', 0, 1000, true, null, 'Kalórie'),
            'protein' => Validate::num($b, 'protein', 0, 100, false, 0, 'Bielkoviny'),
            'carbs' => Validate::num($b, 'carbs', 0, 100, false, 0, 'Sacharidy'),
            'sugar' => Validate::num($b, 'sugar', 0, 100, false, null, 'Cukry'),
            'fat' => Validate::num($b, 'fat', 0, 100, false, 0, 'Tuky'),
            'fiber' => Validate::num($b, 'fiber', 0, 100, false, 0, 'Vláknina'),
            'serving_name' => $servingG ? (Validate::str($b, 'serving_name', 0, 60, false) ?? 'porcia') : null,
            'serving_g' => $servingG ?: null,
        ];
    }

    public static function create(): array
    {
        $u = Auth::require();
        $data = self::foodData(Http::body());
        $id = Db::insert('foods', $data + [
            'owner_id' => (int) $u['id'], 'source' => 'custom', 'is_recipe' => 0,
            'created_at' => Db::now(), 'updated_at' => Db::now(),
        ]);
        return ['food' => self::out(Db::one('SELECT * FROM foods WHERE id = :id', ['id' => $id]))];
    }

    public static function update(string $id): array
    {
        $u = Auth::require();
        $f = self::own((int) $u['id'], (int) $id);
        if ((int) $f['is_recipe'] === 1) {
            return RecipeController::save($id);
        }
        Db::update('foods', self::foodData(Http::body()) + ['updated_at' => Db::now()], 'id = :id', ['id' => (int) $id]);
        return ['food' => self::out(Db::one('SELECT * FROM foods WHERE id = :id', ['id' => (int) $id]))];
    }

    public static function delete(string $id): array
    {
        $u = Auth::require();
        self::own((int) $u['id'], (int) $id);
        Db::run('DELETE FROM recipe_items WHERE recipe_id = :id OR food_id = :id2', ['id' => (int) $id, 'id2' => (int) $id]);
        Db::run('UPDATE entries SET food_id = NULL WHERE food_id = :id', ['id' => (int) $id]);
        Db::run('DELETE FROM favorites WHERE food_id = :id', ['id' => (int) $id]);
        Db::run('DELETE FROM foods WHERE id = :id', ['id' => (int) $id]);
        return ['ok' => true];
    }

    public static function toggleFavorite(string $id): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        self::visible($uid, (int) $id);
        if (Db::val('SELECT food_id FROM favorites WHERE user_id = :u AND food_id = :f', ['u' => $uid, 'f' => (int) $id])) {
            Db::run('DELETE FROM favorites WHERE user_id = :u AND food_id = :f', ['u' => $uid, 'f' => (int) $id]);
            return ['favorite' => false];
        }
        Db::insert('favorites', ['user_id' => $uid, 'food_id' => (int) $id, 'created_at' => Db::now()]);
        return ['favorite' => true];
    }

    public static function own(int $uid, int $id): array
    {
        $f = Db::one('SELECT * FROM foods WHERE id = :id AND owner_id = :u', ['id' => $id, 'u' => $uid]);
        if (!$f) {
            throw new HttpError(404, 'Potravina neexistuje alebo nie je vaša');
        }
        return $f;
    }
}
