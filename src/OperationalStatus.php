<?php
declare(strict_types=1);

namespace SevillaMatrix;

final class OperationalStatus
{
    public static function phase(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') return '';
        if (preg_match('/cancel/', $status) === 1) return 'cancelled';
        if (preg_match('/landed|aterriz|llegó|llego|arrived/', $status) === 1) return 'landed';
        if (preg_match('/delay|retras|delayed/', $status) === 1) return 'delayed';
        if (preg_match('/departed|despeg|took off|en vuelo|enruta|en route|active|in flight/', $status) === 1) {
            return 'airborne';
        }
        if (preg_match('/on time|en hora|scheduled|programad/', $status) === 1) return 'scheduled';
        return $status;
    }

    public static function isCosmeticChange(?string $before, ?string $after): bool
    {
        $next = self::phase((string)$after);
        if ($next === '') return true;
        return self::phase((string)$before) === $next;
    }

    public static function isActionablePhase(string $status): bool
    {
        return in_array(self::phase($status), ['cancelled', 'landed', 'airborne', 'delayed'], true);
    }
}
