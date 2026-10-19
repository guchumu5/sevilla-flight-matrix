<?php
declare(strict_types=1);

namespace SevillaMatrix;

final class TrackingCadence
{
    public const NORMAL_MINUTES = 15;
    public const FINAL_MINUTES = 5;

    /**
     * Un vuelo vigilado conserva la captura general de 15 minutos hasta T-20.
     * Desde T-20 se refuerza a cinco minutos y se mantiene durante la entrega.
     */
    public static function intervalMinutes(
        ?int $minutesToArrival,
        bool $monitored,
        bool $finished,
        bool $criticalBelt = false
    ): int {
        if ($criticalBelt) return self::FINAL_MINUTES;
        if ($finished || !$monitored || $minutesToArrival === null) return self::NORMAL_MINUTES;

        return $minutesToArrival <= 20
            ? self::FINAL_MINUTES
            : self::NORMAL_MINUTES;
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
