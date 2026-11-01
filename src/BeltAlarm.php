<?php
declare(strict_types=1);

namespace SevillaMatrix;

final class BeltAlarm
{
    public static function digit(?string $value): ?int
    {
        if ($value === null || trim($value) === '' || !preg_match('/(\d+)/', $value, $match)) {
            return null;
        }
        $digit = (int)$match[1];
        return ($digit >= 1 && $digit <= 8) ? $digit : null;
    }

    public static function isRed(?int $belt): bool
    {
        return $belt !== null && ($belt === 7 || $belt === 8);
    }

    public static function hall(?string $value): ?string
    {
        if ($value !== null && preg_match('/\b([AB])\b/i', $value, $match) === 1) {
            return strtoupper($match[1]);
        }
        $digit = self::digit($value);
        if ($digit === null) return null;
        return $digit >= 7 ? 'B' : 'A';
    }

    public static function format(?string $value): string
    {
        $digit = self::digit($value);
        if ($digit === null) {
            $raw = trim((string)$value);
            return $raw !== '' ? $raw : '—';
        }
        return self::hall($value) . '/' . $digit;
    }

    /** Cintas 1–6 (sala A) ↔ 7–8 (sala B). El caso A/3 → B/7. */
    public static function isBlockChange(?string $before, ?string $after): bool
    {
        $from = self::digit($before);
        $to = self::digit($after);
        if ($from !== null && $to !== null) {
            return self::isRed($from) !== self::isRed($to);
        }
        $hallFrom = self::hall($before);
        $hallTo = self::hall($after);
        return $hallFrom !== null && $hallTo !== null && $hallFrom !== $hallTo;
    }

    public static function pushBody(?string $before, ?string $after): string
    {
        $from = self::format($before);
        $to = self::format($after);
        $fromDigit = self::digit($before);
        $toRed = self::isRed(self::digit($after));
        $direction = $fromDigit !== null && $toRed
            ? 'cambio de bloque 1–6 a 7–8'
            : 'cambio de bloque 7–8 a 1–6';
        return $from . ' → ' . $to . ' · ' . $direction . '. Hay que moverse YA.';
    }
}
