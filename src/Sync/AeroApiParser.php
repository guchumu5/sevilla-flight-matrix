<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Normaliza un registro AeroAPI FlightAware a campos operativos locales.
 */
final class AeroApiParser
{
    private const ORIGIN_IATA = [
        'LPA' => 'GCLP',
        'TFN' => 'GCXO',
        'TFS' => 'GCTS',
        'ACE' => 'GCRR',
        'FUE' => 'GCFV',
        'SVQ' => 'LEZL',
    ];

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    public static function flightsFromResponse(array $payload): array
    {
        $list = $payload['flights'] ?? $payload['arrivals'] ?? $payload['scheduled_arrivals'] ?? null;
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $row) {
            if (is_array($row)) $out[] = $row;
        }
        return $out;
    }

    /**
     * Elige el tramo LPA/TFN/…→SVQ más cercano a la llegada programada.
     *
     * @param list<array<string,mixed>> $flights
     * @return array<string,mixed>|null
     */
    public static function pickLeg(array $flights, string $originIata, string $scheduledArrival, string $destinationIata = 'SVQ'): ?array
    {
        $wantOrigin = strtoupper(trim($originIata));
        $wantDest = strtoupper(trim($destinationIata));
        $scheduledTs = strtotime($scheduledArrival) ?: time();
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($flights as $flight) {
            if (!is_array($flight)) continue;
            $origin = self::airportIata($flight['origin'] ?? null);
            $dest = self::airportIata($flight['destination'] ?? null);
            if ($wantOrigin !== '' && $origin !== '' && $origin !== $wantOrigin) continue;
            if ($wantDest !== '' && $dest !== '' && $dest !== $wantDest) continue;
            if (!empty($flight['cancelled']) && empty($flight['actual_in']) && empty($flight['actual_on'])) {
                // Cancelado sin llegada: candidata válida si no hay mejor.
            }
            $anchor = self::utcToLocal($flight['scheduled_in'] ?? $flight['scheduled_on'] ?? $flight['estimated_in'] ?? null)
                ?? self::utcToLocal($flight['actual_in'] ?? $flight['actual_on'] ?? null);
            $anchorTs = $anchor !== null ? (strtotime($anchor) ?: $scheduledTs) : $scheduledTs;
            $score = abs($anchorTs - $scheduledTs);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $flight;
            }
        }
        // Tolera desvíos de hasta 18 h respecto a la programada Aena.
        if ($best === null || $bestScore > 18 * 3600) return null;
        return $best;
    }

    /**
     * @param array<string,mixed> $flight
     * @return array<string,mixed>
     */
    public static function normalize(array $flight, ?string $scheduledArrival = null): array
    {
        $status = trim((string)($flight['status'] ?? ''));
        if ($status === '') {
            if (!empty($flight['cancelled'])) $status = 'Cancelled';
            elseif (!empty($flight['diverted'])) $status = 'Diverted';
            elseif (!empty($flight['actual_in']) || !empty($flight['actual_on'])) $status = 'Arrived';
            elseif (!empty($flight['actual_out']) || !empty($flight['actual_off'])) $status = 'En Route';
            else $status = 'Scheduled';
        }

        $eta = self::utcToLocal($flight['estimated_in'] ?? null)
            ?: self::utcToLocal($flight['estimated_on'] ?? null);
        $actualArrival = self::utcToLocal($flight['actual_in'] ?? null)
            ?: self::utcToLocal($flight['actual_on'] ?? null);
        $actualDeparture = self::utcToLocal($flight['actual_out'] ?? null)
            ?: self::utcToLocal($flight['actual_off'] ?? null);

        $delaySeconds = null;
        foreach (['arrival_delay', 'departure_delay'] as $key) {
            if (isset($flight[$key]) && is_numeric($flight[$key])) {
                $delaySeconds = (int)$flight[$key];
                break;
            }
        }
        $delayMinutes = $delaySeconds !== null
            ? (int)round($delaySeconds / 60)
            : null;
        if ($delayMinutes === null && $eta !== null && $scheduledArrival) {
            $left = strtotime($scheduledArrival);
            $right = strtotime($eta);
            if ($left !== false && $right !== false) {
                $delayMinutes = (int)round(($right - $left) / 60);
            }
        }

        $belt = self::belt((string)($flight['baggage_claim'] ?? ''));
        $gate = self::token((string)($flight['gate_destination'] ?? ''), 20);
        $registration = self::registration((string)($flight['registration'] ?? ''));
        $progress = isset($flight['progress_percent']) && is_numeric($flight['progress_percent'])
            ? max(0, min(100, (int)$flight['progress_percent'])) : null;
        $phase = self::phase($status, $progress, $actualArrival, $actualDeparture);

        return [
            'fa_flight_id' => self::token((string)($flight['fa_flight_id'] ?? ''), 40),
            'ident_iata' => self::token((string)($flight['ident_iata'] ?? $flight['ident'] ?? ''), 12),
            'ident_icao' => self::token((string)($flight['ident_icao'] ?? ''), 12),
            'status_text' => $status !== '' ? substr($status, 0, 160) : null,
            'delay_minutes' => $delayMinutes !== null ? max(-24 * 60, min(24 * 60, $delayMinutes)) : null,
            'eta' => $eta,
            'actual_departure' => $actualDeparture,
            'actual_arrival' => $actualArrival,
            'gate' => $gate,
            'belt' => $belt,
            'registration' => $registration,
            'aircraft_registration' => $registration,
            'aircraft_type' => self::token((string)($flight['aircraft_type'] ?? ''), 20),
            'phase' => $phase,
            'progress_percent' => $progress,
            'origin_iata' => self::airportIata($flight['origin'] ?? null) ?: null,
            'destination_iata' => self::airportIata($flight['destination'] ?? null) ?: null,
            'public_url' => self::publicUrl($flight),
        ];
    }

    /** @param mixed $airport */
    public static function airportIata(mixed $airport): string
    {
        if (!is_array($airport)) return '';
        $iata = strtoupper(trim((string)($airport['code_iata'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $iata)) return $iata;
        $code = strtoupper(trim((string)($airport['code'] ?? $airport['code_icao'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $code)) return $code;
        $flip = array_flip(self::ORIGIN_IATA);
        return $flip[$code] ?? '';
    }

    public static function utcToLocal(?string $iso): ?string
    {
        $iso = trim((string)$iso);
        if ($iso === '') return null;
        try {
            $utc = new DateTimeImmutable($iso);
            return $utc->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function belt(string $raw): ?string
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '') return null;
        if (preg_match('/\b([A-H]?)(\d{1,2})\b/', $raw, $match)) {
            return $match[2];
        }
        if (preg_match('/^[A-Z0-9]{1,4}$/', $raw)) return $raw;
        return null;
    }

    private static function registration(string $raw): ?string
    {
        $raw = strtoupper(trim($raw));
        return preg_match('/^[A-Z0-9-]{3,15}$/', $raw) ? $raw : null;
    }

    private static function token(string $raw, int $max): ?string
    {
        $raw = trim($raw);
        return $raw === '' ? null : substr($raw, 0, $max);
    }

    private static function phase(string $status, ?int $progress, ?string $actualArrival, ?string $actualDeparture): ?string
    {
        if ($actualArrival !== null) return 'arrived';
        if (preg_match('/cancel/i', $status)) return 'cancelled';
        if (preg_match('/divert/i', $status)) return 'diverted';
        if ($progress !== null && $progress >= 100) return 'arrived';
        if ($actualDeparture !== null || ($progress !== null && $progress > 0)) return 'enroute';
        if (preg_match('/en\s*route|airborne|climb|cruise|descent|approach/i', $status)) return 'enroute';
        if (preg_match('/taxi|gate|board|depart/i', $status)) return 'departing';
        return 'scheduled';
    }

    /** @param array<string,mixed> $flight */
    private static function publicUrl(array $flight): string
    {
        $ident = preg_replace('/\s+/', '', (string)($flight['ident_iata'] ?? $flight['ident_icao'] ?? $flight['ident'] ?? ''));
        $ident = strtoupper((string)$ident);
        if ($ident === '') return 'https://www.flightaware.com/live/';
        return 'https://www.flightaware.com/live/flight/' . rawurlencode($ident);
    }
}
