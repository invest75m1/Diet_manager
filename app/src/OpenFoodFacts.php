<?php
declare(strict_types=1);

namespace App;

/** Napojenie na otvorenú databázu potravín Open Food Facts. */
final class OpenFoodFacts
{
    private const BASE = 'https://world.openfoodfacts.org';
    private const FIELDS = 'code,product_name,product_name_sk,product_name_cs,generic_name,brands,nutriments,serving_size,serving_quantity,quantity';
    private const UA = 'DietManager/1.0 (self-hosted diet tracker)';

    private static function get(string $url, int $timeout = 12): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status >= 500 || $status === 429) {
            throw new HttpError(502, 'Databáza Open Food Facts je momentálne nedostupná, skúste neskôr');
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : null;
    }

    public static function byBarcode(string $code): ?array
    {
        $data = self::get(self::BASE . '/api/v2/product/' . rawurlencode($code) . '.json?fields=' . self::FIELDS);
        if (!$data || (int) ($data['status'] ?? 0) !== 1 || empty($data['product'])) {
            return null;
        }
        return self::normalize($data['product']);
    }

    public static function search(string $q, int $limit = 20): array
    {
        $url = self::BASE . '/cgi/search.pl?' . http_build_query([
            'search_terms' => $q,
            'search_simple' => 1,
            'action' => 'process',
            'json' => 1,
            'page_size' => $limit,
            'sort_by' => 'unique_scans_n',
            'fields' => self::FIELDS,
        ]);
        $data = self::get($url, 20);
        $out = [];
        foreach (($data['products'] ?? []) as $p) {
            $n = self::normalize($p);
            if ($n) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** Prevedie produkt OFF na náš formát (hodnoty na 100 g). */
    public static function normalize(array $p): ?array
    {
        $n = $p['nutriments'] ?? [];
        $kcal = $n['energy-kcal_100g'] ?? null;
        if ($kcal === null && isset($n['energy_100g'])) {
            $kcal = (float) $n['energy_100g'] / 4.184; // kJ → kcal
        }
        $name = trim((string) ($p['product_name_sk'] ?? '') ?: (string) ($p['product_name_cs'] ?? '') ?: (string) ($p['product_name'] ?? '') ?: (string) ($p['generic_name'] ?? ''));
        if ($kcal === null || $name === '' || empty($p['code'])) {
            return null;
        }
        $num = fn($k) => isset($n[$k]) && is_numeric($n[$k]) ? round((float) $n[$k], 2) : 0.0;
        $servingG = isset($p['serving_quantity']) && is_numeric($p['serving_quantity']) && (float) $p['serving_quantity'] > 0
            ? round((float) $p['serving_quantity'], 1) : null;
        return [
            'barcode' => (string) $p['code'],
            'name' => mb_substr($name, 0, 200),
            'brand' => mb_substr(trim(explode(',', (string) ($p['brands'] ?? ''))[0]), 0, 120) ?: null,
            'kcal' => round((float) $kcal, 1),
            'protein' => $num('proteins_100g'),
            'carbs' => $num('carbohydrates_100g'),
            'sugar' => $num('sugars_100g'),
            'fat' => $num('fat_100g'),
            'fiber' => $num('fiber_100g'),
            'serving_name' => $servingG ? mb_substr((string) ($p['serving_size'] ?? 'porcia'), 0, 60) : null,
            'serving_g' => $servingG,
        ];
    }
}
