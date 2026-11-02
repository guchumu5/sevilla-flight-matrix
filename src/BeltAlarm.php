<?php
declare(strict_types=1);

namespace SevillaMatrix;

final class BeltAlarm
{
    public static function digit(?string $value): ?int
    {
        if ($value === null || !preg_match('/^\s*(?:[AB]\s*\/\s*)?([1-8])\s*$/i', $value, $match)) {
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
        return ($digit >= 7 ? 'B' : 'A') . '/' . $digit;
    }

    /** Cintas 1–6 (sala A) ↔ 7–8 (sala B). El caso A/3 → B/7. */
    public static function isBlockChange(?string $before, ?string $after): bool
    {
        $from = self::digit($before);
        $to = self::digit($after);
        return $from !== null && $to !== null && self::isRed($from) !== self::isRed($to);
    }

    public static function isCriticalEvent(array $event): bool
    {
        return ($event['source'] ?? '') === 'aena'
            && ($event['event_type'] ?? '') === 'belt_changed'
            && self::isBlockChange($event['before_value'] ?? null, $event['after_value'] ?? null);
    }

    /**
     * Input is ordered by detected_at,id. Keep the first ID of consecutive
     * duplicate transitions, but give a real reversal its own identity.
     * A newer assignment/removal/ordinary change supersedes an unsent alarm.
     * @return array<int,array<string,mixed>> keyed by flight ID
     */
    public static function currentEvents(array $events): array
    {
        $latest = [];
        foreach ($events as $event) {
            if (($event['source'] ?? '') !== 'aena'
                || !in_array($event['event_type'] ?? '', ['belt_assigned', 'belt_changed', 'belt_removed'], true)) continue;
            $id = (int)$event['flight_id'];
            $previous = $latest[$id] ?? null;
            if ($previous !== null && self::isCriticalEvent($previous) && self::isCriticalEvent($event)
                && self::digit($previous['before_value']) === self::digit($event['before_value'])
                && self::digit($previous['after_value']) === self::digit($event['after_value'])) continue;
            $latest[$id] = $event;
        }
        return array_filter($latest, static fn(array $event): bool => self::isCriticalEvent($event) && !empty($event['recent']));
    }

    public static function payload(array $event): array
    {
        return [
            'id' => (string)$event['id'], 'flight_id' => (string)$event['flight_id'],
            'source' => 'aena', 'event_type' => 'belt_changed',
            'from' => self::format($event['before_value']), 'to' => self::format($event['after_value']),
            'expires_at' => (int)$event['expires_at'],
        ];
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
