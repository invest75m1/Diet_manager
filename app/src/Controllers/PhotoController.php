<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Auth, Config, HttpError, Images};

final class PhotoController
{
    /** Odošle fotku – iba jej vlastníkovi. */
    public static function show(): never
    {
        $u = Auth::require();
        $ref = (string) ($_GET['f'] ?? '');
        if (!Images::isValidRef((int) $u['id'], $ref)) {
            throw new HttpError(404, 'Fotka neexistuje');
        }
        $path = Config::storage('photos/' . $ref);
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=31536000, immutable');
        readfile($path);
        exit;
    }
}
