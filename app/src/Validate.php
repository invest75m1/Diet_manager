<?php
declare(strict_types=1);

namespace App;

/** Jednoduché validačné pomôcky – pri chybe vyhodia HttpError 422. */
final class Validate
{
    public static function str(array $src, string $key, int $min = 0, int $max = 255, bool $required = true, string $label = ''): ?string
    {
        $v = $src[$key] ?? null;
        if ($v === null || trim((string) $v) === '') {
            if ($required) {
                throw new HttpError(422, ($label ?: $key) . ': povinný údaj');
            }
            return null;
        }
        $v = trim((string) $v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            throw new HttpError(422, ($label ?: $key) . ": dĺžka musí byť $min–$max znakov");
        }
        return $v;
    }

    public static function num(array $src, string $key, float $min = 0, float $max = 100000, bool $required = true, ?float $default = null, string $label = ''): ?float
    {
        $v = $src[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required && $default === null) {
                throw new HttpError(422, ($label ?: $key) . ': povinný údaj');
            }
            return $default;
        }
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }
        if (!is_numeric($v)) {
            throw new HttpError(422, ($label ?: $key) . ': musí byť číslo');
        }
        $f = (float) $v;
        if ($f < $min || $f > $max) {
            throw new HttpError(422, ($label ?: $key) . ": povolený rozsah $min–$max");
        }
        return $f;
    }

    public static function date(array $src, string $key, bool $required = true): ?string
    {
        $v = $src[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                throw new HttpError(422, "$key: povinný dátum");
            }
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw new HttpError(422, "$key: neplatný dátum");
        }
        return $v;
    }

    public static function time(array $src, string $key): string
    {
        $v = (string) ($src[$key] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            return date('H:i');
        }
        return $v;
    }

    public static function oneOf(array $src, string $key, array $allowed, ?string $default = null): string
    {
        $v = $src[$key] ?? $default;
        if (!in_array($v, $allowed, true)) {
            if ($default !== null) {
                return $default;
            }
            throw new HttpError(422, "$key: neplatná hodnota");
        }
        return (string) $v;
    }
}
