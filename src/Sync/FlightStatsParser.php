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
        $delayMinutes = self::nullableInt($arrivalDelay['minutes'] ?? $delayStatus['minutes'] ?? null);
        $statusText = trim($statusLabel . ($delayText !== '' && !str_contains(strtolower($statusLabel), strtolower($delayText))
            ? ' · ' . $delayText : ($delayText !== '' && $statusLabel === '' ? $delayText : '')), ' ·');

        $depActual = is_array(($departure['times'] ?? [])['estimatedActual'] ?? null) ? $departure['times']['estimatedActual'] : [];
        $arrActual = is_array(($arrival['times'] ?? [])['estimatedActual'] ?? null) ? $arrival['times']['estimatedActual'] : [];
        $depTitle = strtolower((string)($schedule['estimatedActualDepartureTitle'] ?? $depActual['title'] ?? ''));
        $arrTitle = strtolower((string)($schedule['estimatedActualArrivalTitle'] ?? $arrActual['title'] ?? ''));
        $landed = filter_var($data['isLanded'] ?? $note['landed'] ?? false, FILTER_VALIDATE_BOOL);
        $canceled = filter_var($note['canceled'] ?? false, FILTER_VALIDATE_BOOL)
            || preg_match('/cancel/i', $statusLabel . ' ' . $delayText) === 1;

        $eta = self::localDateTime($schedule['estimatedActualArrival'] ?? null);
        $actualArrival = ($landed || $arrTitle === 'actual') ? $eta : null;
        if ($actualArrival !== null) $eta = $actualArrival;
        if (!$landed && $arrTitle !== 'actual') $actualArrival = null;

        $actualDeparture = null;
        if ($depTitle === 'actual' || !empty($note['hasActualGateDepartureTime']) || !empty($note['hasActualRunwayDepartureTime'])) {
            $actualDeparture = self::localDateTime($schedule['estimatedActualDeparture'] ?? $schedule['tookOff'] ?? null);
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
        if (preg_match('/Delayed by (\d+)\s*m/i', $text, $found)) {
            $out['delay_minutes'] = (int)$found[1];
            $out['status_text'] = 'Delayed by ' . $found[1] . 'm';
        } elseif (preg_match('/\b(On time|Arrived|Departed|Scheduled|Cancelled|Landed|In flight|En route)\b/i', $text, $found)) {
            $out['status_text'] = $found[1];
        }
        if (preg_match('/Scheduled\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})\s+Actual\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})/', $text, $found)) {
            $out['departure_text'] = 'Actual ' . $found[3] . ' ' . $found[4];
            $out['actual_departure'] = self::clockOnDay($scheduledArrival, $found[3]);
        }
        if (preg_match('/Scheduled\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})\s+Estimated\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})/', $text, $found)) {
            $out['arrival_text'] = 'Estimated ' . $found[3] . ' ' . $found[4];
            $out['eta'] = self::clockOnDay($scheduledArrival, $found[3]);
        } elseif (preg_match('/Scheduled\s+(\d{1,2}:\d{2})\s+(CET|CEST)\s+Actual\s+(\d{1,2}:\d{2})\s+(CET|CEST)/', $text, $found)) {
            $out['arrival_text'] = 'Actual ' . $found[3] . ' ' . $found[4];
            $out['actual_arrival'] = self::clockOnDay($scheduledArrival, $found[3]);
        }
        $useful = $out['delay_minutes'] !== null || $out['eta'] || $out['actual_departure']
            || $out['actual_arrival'] || $out['status_text'] || $out['canceled'];
        return $useful ? $out : null;
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

    public static function localDateTime(mixed $value): ?string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') return null;
        try {
            if (str_ends_with($raw, 'Z') || preg_match('/[+-]\d{2}:\d{2}$/', $raw)) {
                return (new DateTimeImmutable($raw))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
            }
            return (new DateTimeImmutable(str_replace('T', ' ', substr($raw, 0, 19))))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
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
