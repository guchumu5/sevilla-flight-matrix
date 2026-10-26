<?php
declare(strict_types=1);

namespace SevillaMatrix;

final class TrackingCadence
{
    public const NORMAL_MINUTES = 15;
    public const FINAL_MINUTES = 5;

    /**
     * Un vuelo vigilado se captura cada 5 minutos hasta que termina el
     * equipaje. El resto de la matriz sigue en 15 minutos.
     */
    public static function intervalMinutes(
        ?int $minutesToArrival,
        bool $monitored,
        bool $finished,
        bool $criticalBelt = false
    ): int {
        if ($criticalBelt) return self::FINAL_MINUTES;
        if ($finished || !$monitored) return self::NORMAL_MINUTES;
        return self::FINAL_MINUTES;
    }

    public static function phase(?int $minutesToArrival, bool $monitored, bool $finished): string
    {
        if ($finished) return 'finished';
        if (!$monitored) return 'normal';
        if ($minutesToArrival === null || $minutesToArrival > 60) return 'waiting';
        if ($minutesToArrival > 20) return 'last_hour';
        return 'final_and_baggage';
    }
}
