<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use DateTimeImmutable;
use Throwable;

final class FlightStatsParser
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public static function fromJson(array $payload, string $publicUrl = ''): ?array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if ($data === []) return null;
        $schedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $status = is_array($data['status'] ?? null) ? $data['status'] : [];
        $note = is_array($data['flightNote'] ?? null) ? $data['flightNote'] : [];
        $header = is_array($data['resultHeader'] ?? null) ? $data['resultHeader'] : [];
        $ticket = is_array($data['ticketHeader'] ?? null) ? $data['ticketHeader'] : [];
        $departure = is_array($data['departureAirport'] ?? null) ? $data['departureAirport'] : [];
        $arrival = is_array($data['arrivalAirport'] ?? null) ? $data['arrivalAirport'] : [];

        $headerCarrier = is_array($header['carrier'] ?? null) ? $header['carrier'] : [];
        $ticketCarrier = is_array($ticket['carrier'] ?? null) ? $ticket['carrier'] : [];
        $delayBlock = is_array($status['delay'] ?? null) ? $status['delay'] : [];
        $arrivalDelay = is_array($delayBlock['arrival'] ?? null) ? $delayBlock['arrival'] : [];
        $delayStatus = is_array($status['delayStatus'] ?? null) ? $status['delayStatus'] : [];
        $carrier = strtoupper(trim((string)(($headerCarrier['fs'] ?? null) ?: ($ticketCarrier['fs'] ?? ''))));
        $number = trim((string)(($header['flightNumber'] ?? null) ?: ($ticket['flightNumber'] ?? '')));
        $statusLabel = trim((string)($status['status'] ?? $header['status'] ?? ''));
        $delayText = trim((string)($status['statusDescription'] ?? $header['statusDescription'] ?? ''));
        $delayMinutes = self::parseDelayMinutes(
            $arrivalDelay['minutes'] ?? $delayStatus['minutes'] ?? null,
            $delayText !== '' ? $delayText : (string)($delayStatus['wording'] ?? '')
        );
        if (($delayMinutes ?? 0) >= 5 && preg_match('/on time/i', $delayText) === 1) {
            $delayText = 'Delayed by ' . $delayMinutes . 'm';
        }
        if (($delayMinutes ?? 0) >= 5 && preg_match('/on time/i', $statusLabel) === 1) {
            $statusLabel = 'Scheduled';
        }
        $statusText = trim($statusLabel . ($delayText !== '' && !str_contains(strtolower($statusLabel), strtolower($delayText))
            ? ' · ' . $delayText : ($delayText !== '' && $statusLabel === '' ? $delayText : '')), ' ·');

        $depActual = is_array(($departure['times'] ?? [])['estimatedActual'] ?? null) ? $departure['times']['estimatedActual'] : [];
        $arrActual = is_array(($arrival['times'] ?? [])['estimatedActual'] ?? null) ? $arrival['times']['estimatedActual'] : [];
        $depTitle = strtolower((string)($schedule['estimatedActualDepartureTitle'] ?? $depActual['title'] ?? ''));
        $arrTitle = strtolower((string)($schedule['estimatedActualArrivalTitle'] ?? $arrActual['title'] ?? ''));
        $landed = filter_var($data['isLanded'] ?? $note['landed'] ?? false, FILTER_VALIDATE_BOOL);
        $canceled = filter_var($note['canceled'] ?? false, FILTER_VALIDATE_BOOL)
            || preg_match('/cancel/i', $statusLabel . ' ' . $delayText) === 1;

        $eta = self::localDateTime($schedule['estimatedActualArrivalUTC'] ?? null, 'Europe/Madrid')
            ?: self::localDateTime($schedule['estimatedActualArrival'] ?? null, 'Europe/Madrid');
        $actualArrival = ($landed || $arrTitle === 'actual') ? $eta : null;
        if ($actualArrival !== null) $eta = $actualArrival;
        if (!$landed && $arrTitle !== 'actual') $actualArrival = null;

        $actualDeparture = null;
        if ($depTitle === 'actual' || !empty($note['hasActualGateDepartureTime']) || !empty($note['hasActualRunwayDepartureTime'])) {
            $actualDeparture = self::localDateTime($schedule['estimatedActualDeparture'] ?? $schedule['tookOff'] ?? null, 'Atlantic/Canary')
                ?: self::localDateTime($schedule['estimatedActualDepartureUTC'] ?? null, 'Atlantic/Canary');
        }

        $depClock = trim((string)($depActual['time24'] ?? ''));
        $arrClock = trim((string)($arrActual['time24'] ?? ''));
        $depZone = trim((string)($depActual['timezone'] ?? ''));
        $arrZone = trim((string)($arrActual['timezone'] ?? ''));

        if ($statusText === '' && $eta === null && $actualDeparture === null) return null;

        return [
            'iata_carrier' => preg_match('/^[A-Z][A-Z0-9]$/', $carrier) ? $carrier : null,
            'iata_number' => preg_match('/^\d{1,4}[A-Z]?$/', $number) ? $number : null,
            'status_text' => $statusText !== '' ? substr($statusText, 0, 160) : ($canceled ? 'Cancelled' : null),
            'delay_minutes' => $delayMinutes,
            'eta' => $actualArrival === null ? $eta : null,
            'actual_departure' => $actualDeparture,
            'actual_arrival' => $actualArrival,
            'departure_text' => self::clockLabel($depClock, $depZone, $depTitle === 'actual' ? 'Actual' : null),
            'arrival_text' => self::clockLabel($arrClock, $arrZone, $arrTitle === 'actual' ? 'Actual' : 'Estimated'),
            'phase' => substr(trim((string)($note['phase'] ?? $data['flightState'] ?? '')), 0, 40) ?: null,
            'public_url' => $publicUrl,
            'canceled' => $canceled,
            'origin_iata' => strtoupper(trim((string)($departure['iata'] ?? $header['departureAirportFS'] ?? ''))),
            'destination_iata' => strtoupper(trim((string)($arrival['iata'] ?? $header['arrivalAirportFS'] ?? ''))),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function fromHtml(string $html, string $scheduledArrival = '', string $publicUrl = ''): ?array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/\s+/', ' ', $text);
        if ($text === '') return null;
        $out = [
            'iata_carrier' => null,
            'iata_number' => null,
            'status_text' => null,
            'delay_minutes' => null,
            'eta' => null,
            'actual_departure' => null,
            'actual_arrival' => null,
            'departure_text' => null,
            'arrival_text' => null,
            'phase' => null,
            'public_url' => $publicUrl,
            'canceled' => preg_match('/\bCancelled\b/i', $text) === 1,
            'origin_iata' => '',
            'destination_iata' => '',
        ];
        if (preg_match('/Delayed by\s+((?:\d+\s*h(?:ours?)?\s*)?\d+\s*m(?:in(?:utes?)?)?|\d+\s*h(?:ours?)?)/i', $text, $found)) {
            $out['delay_minutes'] = self::parseDelayMinutes(null, $found[1]);
            $out['status_text'] = 'Delayed by ' . trim($found[1]);
        } elseif (preg_match('/\b(On time|Arrived|Departed|Scheduled|Cancelled|Landed|In flight|En route)\b/i', $text, $found)) {
            $out['status_text'] = $found[1];
        }
        if (preg_match_all('/Scheduled\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})\s+(Actual|Estimated)\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $found) {
                $zone = strtoupper($found[5]);
                $kind = strtolower($found[3]);
                $label = ($kind === 'actual' ? 'Actual' : 'Estimated') . ' ' . $found[4] . ' ' . $zone;
                $seville = in_array($zone, ['CET', 'CEST'], true);
                if ($seville) {
                    $out['arrival_text'] = $label;
                    if ($kind === 'actual') $out['actual_arrival'] = self::clockOnDay($scheduledArrival, $found[4]);
                    else $out['eta'] = self::clockOnDay($scheduledArrival, $found[4]);
                } else {
                    $out['departure_text'] = $label;
                    if ($kind === 'actual') {
                        $out['actual_departure'] = self::clockOnDay($scheduledArrival, $found[4]);
                    }
                }
            }
            if ($out['eta'] === null && $out['actual_arrival'] === null) {
                $last = $matches[count($matches) - 1];
                $kind = strtolower($last[3]);
                $label = ($kind === 'actual' ? 'Actual' : 'Estimated') . ' ' . $last[4] . ' ' . $last[5];
                $out['arrival_text'] = $label;
                if ($kind === 'actual') $out['actual_arrival'] = self::clockOnDay($scheduledArrival, $last[4]);
                else $out['eta'] = self::clockOnDay($scheduledArrival, $last[4]);
            }
        }
        if ($out['canceled']) return $out;
        if ($out['delay_minutes'] !== null && (int)$out['delay_minutes'] !== 0) return $out;
        if ($out['actual_departure'] || $out['actual_arrival']) return $out;
        if ($out['eta'] && $scheduledArrival !== '') {
            $etaAt = strtotime((string)$out['eta']);
            $scheduledAt = strtotime($scheduledArrival);
            if ($etaAt !== false && $scheduledAt !== false && abs($etaAt - $scheduledAt) >= 60) return $out;
        }
        return null;
    }

    /**
     * Lectura cascarón (SPA sin datos): «On time» / programada, sin retraso.
     *
     * @param array<string,mixed> $parsed
     */
    public static function isPlaceholder(array $parsed, string $scheduledArrival = ''): bool
    {
        $delay = $parsed['delay_minutes'] ?? null;
        if (is_numeric($delay) && (int)$delay !== 0) return false;
        if (!empty($parsed['actual_departure']) || !empty($parsed['actual_arrival']) || !empty($parsed['canceled'])) {
            return false;
        }
        $eta = (string)($parsed['eta'] ?? '');
        if ($eta !== '' && $scheduledArrival !== '') {
            $etaAt = strtotime($eta);
            $scheduledAt = strtotime($scheduledArrival);
            if ($etaAt !== false && $scheduledAt !== false && abs($etaAt - $scheduledAt) >= 60) return false;
        }
        $status = strtolower((string)($parsed['status_text'] ?? ''));
        return $status === '' || preg_match('/on time|scheduled|en hora|programad/i', $status) === 1;
    }

    public static function clockOnDay(string $baseDateTime, string $hhmm): ?string
    {
        if ($baseDateTime === '' || !preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $match)) return null;
        try {
            $base = new DateTimeImmutable($baseDateTime);
            $candidate = $base->setTime((int)$match[1], (int)$match[2]);
            $diff = $candidate->getTimestamp() - $base->getTimestamp();
            if ($diff < -12 * 3600) $candidate = $candidate->modify('+1 day');
            if ($diff > 12 * 3600) $candidate = $candidate->modify('-1 day');
            return $candidate->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public static function localDateTime(mixed $value, string $timezone = ''): ?string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') return null;
        try {
            $zone = new \DateTimeZone($timezone !== '' ? $timezone : (date_default_timezone_get() ?: 'Europe/Madrid'));
            if (str_ends_with($raw, 'Z') || preg_match('/[+-]\d{2}:\d{2}$/', $raw)) {
                return (new DateTimeImmutable($raw))->setTimezone($zone)->format('Y-m-d H:i:s');
            }
            return (new DateTimeImmutable(str_replace('T', ' ', substr($raw, 0, 19)), $zone))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public static function parseDelayMinutes(mixed $numeric, string $text = ''): ?int
    {
        $fromNumber = self::nullableInt($numeric);
        if ($fromNumber !== null) return $fromNumber;
        $text = trim($text);
        if ($text === '') return null;
        if (preg_match('/(?:delayed by\s*)?(?:(\d+)\s*h(?:ours?)?\s*)?(\d+)\s*m(?:in(?:utes?)?)?\b/i', $text, $match)) {
            return self::nullableInt(((int)($match[1] ?? 0)) * 60 + (int)$match[2]);
        }
        if (preg_match('/(?:delayed by\s*)?(\d+)\s*h(?:ours?)?\b/i', $text, $match)) {
            return self::nullableInt(((int)$match[1]) * 60);
        }
        return null;
    }

    private static function clockLabel(string $clock, string $zone, ?string $title): ?string
    {
        if ($clock === '') return null;
        return trim(($title ? $title . ' ' : '') . $clock . ($zone !== '' ? ' ' . $zone : ''));
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        return max(-24 * 60, min(24 * 60, (int)round((float)$value)));
    }
}
