<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{AiService, Auth, Http, HttpError, Images, Validate};

final class AiController
{
    public static function text(): array
    {
        Auth::require();
        $text = Validate::str(Http::body(), 'text', 2, 2000, true, 'Popis jedla');
        return AiService::analyzeText($text);
    }

    public static function photo(): array
    {
        $u = Auth::require();
        $img = Images::fromUpload('image');
        $kind = Validate::oneOf($_POST, 'kind', ['meal', 'menu'], 'meal');
        $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 1000);
        $result = AiService::analyzePhoto($img['data'], $img['mime'], $kind, $desc);
        $result['photo'] = Images::store((int) $u['id'], $img['data']);
        return $result;
    }

    public static function activity(): array
    {
        $u = Auth::require();
        $img = Images::fromUpload('image');
        $result = AiService::analyzeActivity($img['data'], $img['mime']);
        if (!$result['is_activity']) {
            throw new HttpError(422, $result['comment'] ?: 'Na obrázku sa nenašli údaje o tréningu');
        }
        $result['photo'] = Images::store((int) $u['id'], $img['data']);
        return $result;
    }
}
