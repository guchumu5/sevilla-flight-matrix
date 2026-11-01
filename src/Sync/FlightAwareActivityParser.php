<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use DateTimeImmutable;
use DateTimeZone;
use SevillaMatrix\IataFlightCode;
use Throwable;

final class FlightAwareActivityParser
{
    /**
     * @return array<string,mixed>|null
     */
    public static function bootstrapFromHtml(string $html): ?array
    {
        $marker = 'var trackpollBootstrap =';
        $at = strpos($html, $marker);
        if ($at === false) return null;
        $start = strpos($html, '{', $at + strlen($marker));
        if ($start === false) return null;
        $depth = 0;
        $inString = false;
        $escaped = false;
        $end = null;
        $length = strlen($html);
        for ($i = $start; $i < $length; $i++) {
            $ch = $html[$i];
            if ($inString) {
                if ($escaped) $escaped = false;
                elseif ($ch === '\\') $escaped = true;
                elseif ($ch === '"') $inString = false;
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') $depth++;
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
        }
        if ($end === null) return null;
        try {
            $decoded = json_decode(substr($html, $start, $end - $start), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Siguiente ítem del activityLog de la misma cola: el vuelo que
     * acaba de hacer esa aeronave, da igual origen y destino.
     *
     * @param array<string,mixed> $bootstrap
     * @return array<string,mixed>|null
     */
    public static function previousSector(array $bootstrap, string $currentCode, string $originHint = ''): ?array
    {
        $currentCode = self::ident($currentCode);
        $originHint = strtoupper(trim($originHint));
        if ($currentCode === '') return null;
        $flights = is_array($bootstrap['flights'] ?? null) ? $bootstrap['flights'] : [];
        if ($flights === []) return null;
        $current = self::currentEntry($flights, $currentCode, $originHint);
        $log = is_array($current['activityLog']['flights'] ?? null) ? $current['activityLog']['flights'] : [];
        $tail = strtoupper(trim((string)(($current['aircraft']['tail'] ?? '') ?: '')));
        $afterCurrent = false;
        foreach ($log as $item) {
            if (!is_array($item)) continue;
            $ident = self::ident((string)($item['displayIdent'] ?? $item['ident'] ?? ''));
            if ($ident === '') continue;
            if (!$afterCurrent) {
                if (self::isCurrentItem($item, $currentCode, $originHint)) $afterCurrent = true;
                continue;
            }
            if ($ident === $currentCode) continue;
            return self::sector($item, $tail);
        }
        foreach ($log as $item) {
            if (!is_array($item)) continue;
            $ident = self::ident((string)($item['displayIdent'] ?? $item['ident'] ?? ''));
            if ($ident !== '' && $ident !== $currentCode) {
                return self::sector($item, $tail);
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $flights
     * @return array<string,mixed>
     */
    private static function currentEntry(array $flights, string $currentCode, string $originHint): array
    {
        foreach ($flights as $entry) {
            if (!is_array($entry)) continue;
            if (self::isCurrentItem($entry, $currentCode, $originHint)) return $entry;
        }
        $first = reset($flights);
        return is_array($first) ? $first : [];
    }

    /** @param array<string,mixed> $item */
    private static function isCurrentItem(array $item, string $currentCode, string $originHint): bool
    {
        $ident = self::ident((string)($item['displayIdent'] ?? $item['ident'] ?? ''));
        if ($ident !== $currentCode) return false;
        if ($originHint === '') return true;
        $origin = strtoupper(trim((string)(($item['origin']['iata'] ?? '') ?: '')));
        return $origin === '' || $origin === $originHint;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function sector(array $item, string $tail): array
    {
        $ident = self::ident((string)($item['displayIdent'] ?? $item['ident'] ?? ''));
        $split = IataFlightCode::split($ident);
        $origin = strtoupper(trim((string)(($item['origin']['iata'] ?? '') ?: '')));
        $dest = strtoupper(trim((string)(($item['destination']['iata'] ?? '') ?: '')));
        $originTz = self::timezone((string)(($item['origin']['TZ'] ?? '') ?: 'Europe/Madrid'));
        $destTz = self::timezone((string)(($item['destination']['TZ'] ?? '') ?: 'Europe/Madrid'));
        $dep = is_array($item['gateDepartureTimes'] ?? null) ? $item['gateDepartureTimes'] : [];
        $arr = is_array($item['gateArrivalTimes'] ?? null) ? $item['gateArrivalTimes'] : [];
        $takeoff = is_array($item['takeoffTimes'] ?? null) ? $item['takeoffTimes'] : [];
        $landing = is_array($item['landingTimes'] ?? null) ? $item['landingTimes'] : [];
        $arrSched = self::unix($arr['scheduled'] ?? $landing['scheduled'] ?? null);
        $arrBest = self::unix($arr['actual'] ?? $arr['estimated'] ?? $landing['actual'] ?? $landing['estimated'] ?? null);
        $delay = ($arrSched !== null && $arrBest !== null)
            ? (int)round(($arrBest - $arrSched) / 60)
            : null;
        return [
            'feeder_code' => $split['code'] ?? $ident,
            'iata_carrier' => $split['carrier'] ?? null,
            'iata_number' => $split['number'] ?? null,
            'origin_iata' => $origin,
            'destination_iata' => $dest,
            'delay_minutes' => $delay,
            'eta' => self::stamp($arr['estimated'] ?? $landing['estimated'] ?? null, $destTz),
            'actual_departure' => self::stamp($dep['actual'] ?? $takeoff['actual'] ?? null, $originTz),
            'actual_arrival' => self::stamp($arr['actual'] ?? $landing['actual'] ?? null, $destTz),
            'status_text' => trim((string)($item['flightStatus'] ?? $item['status'] ?? '')) ?: null,
            'aircraft_registration' => $tail !== '' ? $tail : null,
            'match_reason' => 'same_aircraft',
            'public_url' => $tail !== ''
                ? 'https://www.flightaware.com/live/flight/' . rawurlencode(str_replace('-', '', $tail))
                : null,
        ];
    }

    public static function ident(string $code): string
    {
        $split = IataFlightCode::split($code);
        return $split['code'] ?? strtoupper((string)preg_replace('/\s+/', '', $code));
    }

    public static function tailUrl(string $registration): string
    {
        $tail = strtoupper((string)preg_replace('/[^A-Z0-9]/', '', $registration));
        return 'https://www.flightaware.com/live/flight/' . rawurlencode($tail);
    }

    private static function timezone(string $raw): string
    {
        $raw = ltrim(trim($raw), ':');
        return $raw !== '' ? $raw : 'Europe/Madrid';
    }

    private static function unix(mixed $value): ?int
    {
        if (!is_numeric($value)) return null;
        $ts = (int)$value;
        return $ts > 1_000_000_000 ? $ts : null;
    }

    private static function stamp(mixed $value, string $timezone): ?string
    {
        $ts = self::unix($value);
        if ($ts === null) return null;
        try {
            return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
