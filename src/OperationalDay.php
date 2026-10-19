<?php
declare(strict_types=1);

namespace SevillaMatrix;

use DateTimeImmutable;

final class OperationalDay
{
    public const CUTOFF_HOUR = 2;

    public static function current(?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable('now');
        if ((int)$now->format('G') < self::CUTOFF_HOUR) {
            $now = $now->modify('-1 day');
        }
        return $now->format('Y-m-d');
    }

    /** @return array{from:string,to:string} */
    public static function window(string $date): array
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Fecha operativa no válida.');
        }
        $from = $day->setTime(self::CUTOFF_HOUR, 0);
        return [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $from->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
    }

    public static function contains(string $dateTime, string $operationalDate): bool
    {
        $window = self::window($operationalDate);
        return $dateTime >= $window['from'] && $dateTime < $window['to'];
    }
}
