<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Auth, Db, Http, HttpError, Nutrition, Validate};

/**
 * Recepty sú súkromné "potraviny" zložené z ingrediencií.
 * Hodnoty sa prepočítajú na 100 g hotového jedla; porcia = celková hmotnosť / počet porcií.
 */
final class RecipeController
{
    public static function save(?string $id = null): array
    {
        $u = Auth::require();
        $uid = (int) $u['id'];
        $b = Http::body();
        $name = Validate::str($b, 'name', 1, 200, true, 'Názov receptu');
        $portions = Validate::num($b, 'portions', 1, 100, false, 1, 'Počet porcií');
        $items = $b['items'] ?? [];
        if (!is_array($items) || !$items) {
            throw new HttpError(422, 'Recept musí obsahovať aspoň jednu surovinu');
        }
        $totals = Nutrition::emptyTotals();
        $rawWeight = 0.0;
        $clean = [];
        foreach ($items as $it) {
            $food = FoodController::visible($uid, (int) ($it['food_id'] ?? 0));
            if ((int) $food['id'] === (int) $id) {
                throw new HttpError(422, 'Recept nemôže obsahovať sám seba');
            }
            $g = Validate::num($it, 'grams', 0.1, 20000, true, null, 'Množstvo suroviny');
            foreach (Nutrition::scaleFood($food, $g) as $k => $v) {
                $totals[$k] += $v;
            }
            $rawWeight += $g;
            $clean[] = ['food_id' => (int) $food['id'], 'grams' => $g];
        }
        // Hmotnosť hotového jedla (napr. po uvarení) – nepovinné.
        $totalG = Validate::num($b, 'total_g', 1, 50000, false, null, 'Hmotnosť hotového jedla') ?? $rawWeight;
        $per100 = array_map(fn($v) => round($v / $totalG * 100, 2), $totals);
        $data = $per100 + [
            'name' => $name,
            'brand' => null,
            'sugar' => null,
            'serving_name' => $portions > 1 ? 'porcia (1/' . (int) $portions . ')' : 'celý recept',
            'serving_g' => round($totalG / $portions, 1),
            'recipe_total_g' => $totalG,
            'updated_at' => Db::now(),
        ];
        $recipeId = Db::tx(function () use ($id, $uid, $data, $clean) {
            if ($id !== null) {
                $f = FoodController::own($uid, (int) $id);
                if ((int) $f['is_recipe'] !== 1) {
                    throw new HttpError(422, 'Nie je to recept');
                }
                Db::update('foods', $data, 'id = :id', ['id' => (int) $id]);
                Db::run('DELETE FROM recipe_items WHERE recipe_id = :id', ['id' => (int) $id]);
                $rid = (int) $id;
            } else {
                $rid = Db::insert('foods', $data + [
                    'owner_id' => $uid, 'source' => 'recipe', 'is_recipe' => 1, 'barcode' => null, 'created_at' => Db::now(),
                ]);
            }
            foreach ($clean as $c) {
                Db::insert('recipe_items', $c + ['recipe_id' => $rid]);
            }
            return $rid;
        });
        return FoodController::show((string) $recipeId);
    }
}
