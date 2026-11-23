<?php
declare(strict_types=1);

namespace App;

/** Prijatie nahratej fotky, zmenšenie a uloženie. */
final class Images
{
    public const MAX_UPLOAD = 12 * 1024 * 1024;
    public const AI_MAX_SIDE = 1568;
    public const STORE_MAX_SIDE = 800;

    /**
     * Načíta nahratý súbor z $_FILES[$field].
     * Vráti ['data' => binárne JPEG/PNG dáta pre AI, 'mime' => ...].
     */
    public static function fromUpload(string $field = 'image'): array
    {
        $f = $_FILES[$field] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new HttpError(422, $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                ? 'Fotka je príliš veľká' : 'Chýba fotka');
        }
        if ($f['size'] > self::MAX_UPLOAD) {
            throw new HttpError(422, 'Fotka je príliš veľká (max. 12 MB)');
        }
        $data = (string) file_get_contents($f['tmp_name']);
        $info = @getimagesizefromstring($data);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new HttpError(422, 'Nepodporovaný formát obrázka (použite JPG, PNG alebo WebP)');
        }
        // Pre AI zmenšíme, ak je obrázok väčší (šetrí čas aj náklady).
        if (max($info[0], $info[1]) > self::AI_MAX_SIDE) {
            $resized = self::resize($data, self::AI_MAX_SIDE, 85);
            if ($resized !== null) {
                return ['data' => $resized, 'mime' => 'image/jpeg'];
            }
        }
        return ['data' => $data, 'mime' => $mime];
    }

    /** Zmenší obrázok na JPEG s max. stranou $max. Bez GD vráti null. */
    public static function resize(string $data, int $max, int $quality = 80): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($data);
        if (!$src) {
            return null;
        }
        $src = self::autoRotate($src, $data);
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, $quality);
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $out;
    }

    private static function autoRotate(\GdImage $img, string $data): \GdImage
    {
        if (!function_exists('exif_read_data') || !str_starts_with($data, "\xFF\xD8")) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));
        $o = (int) ($exif['Orientation'] ?? 1);
        $angle = match ($o) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($angle !== 0) {
            $rot = imagerotate($img, $angle, 0);
            if ($rot) {
                return $rot;
            }
        }
        return $img;
    }

    /** Uloží zmenšenú kópiu, vráti relatívnu cestu "uid/RRRRMM/xxx.jpg". */
    public static function store(int $userId, string $data): string
    {
        $small = self::resize($data, self::STORE_MAX_SIDE, 78) ?? $data;
        $rel = $userId . '/' . date('Ym') . '/' . bin2hex(random_bytes(10)) . '.jpg';
        $path = Config::storage('photos/' . $rel);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0770, true)) {
            throw new HttpError(500, 'Nepodarilo sa uložiť fotku (priečinok storage nie je zapisovateľný)');
        }
        file_put_contents($path, $small);
        return $rel;
    }

    public static function isValidRef(int $userId, ?string $rel): bool
    {
        return $rel !== null && preg_match('#^' . $userId . '/\d{6}/[a-f0-9]{20}\.jpg$#', $rel) === 1
            && is_file(Config::storage('photos/' . $rel));
    }

    public static function deleteUserPhotos(int $userId): void
    {
        self::rmdirRecursive(Config::storage('photos/' . $userId));
    }

    public static function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
