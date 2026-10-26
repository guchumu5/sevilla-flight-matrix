<?php
declare(strict_types=1);

namespace SevillaMatrix;

use DateTimeImmutable;

final class IataFlightCode
{
    /** ICAO de operadores habituales en SVQ → IATA de FlightStats. */
    private const ICAO_TO_IATA = [
        'AEA' => 'UX',
        'AFR' => 'AF',
        'ANE' => 'YW',
        'BAW' => 'BA',
        'CFG' => 'DE',
        'DLH' => 'LH',
        'EIN' => 'EI',
        'ENT' => 'E4',
        'EWG' => 'EW',
        'EZY' => 'U2',
        'EZS' => 'U2',
        'IBE' => 'IB',
        'IBK' => 'D8',
        'IBS' => 'I2',
        'KLM' => 'KL',
        'NAX' => 'DY',
        'RYR' => 'FR',
        'SWR' => 'LX',
        'TAP' => 'TP',
        'TRA' => 'HV',
        'TVF' => 'TO',
        'VLG' => 'VY',
        'VOE' => 'V7',
        'WZZ' => 'W6',
    ];

    /**
     * @param list<string> $aliases
     * @return array{carrier:string,number:string,code:string}|null
     */
    public static function split(string $physical, array $aliases = []): ?array
    {
        $physical = strtoupper((string)preg_replace('/\s+/', '', $physical));
        $mapped = self::fromIcao($physical) ?? self::fromIata($physical);
        if ($mapped !== null) return $mapped;
        foreach ($aliases as $code) {
            $normalized = strtoupper((string)preg_replace('/\s+/', '', (string)$code));
            $mapped = self::fromIata($normalized) ?? self::fromIcao($normalized);
            if ($mapped !== null) return $mapped;
        }
        return null;
    }

    /** @return array{carrier:string,number:string,code:string}|null */
    private static function fromIata(string $code): ?array
    {
        if (preg_match('/^([A-Z][A-Z0-9])(\d{1,4}[A-Z]?)$/', $code, $match)) {
            return ['carrier' => $match[1], 'number' => $match[2], 'code' => $match[1] . $match[2]];
        }
        return null;
    }

    /** @return array{carrier:string,number:string,code:string}|null */
    private static function fromIcao(string $code): ?array
    {
        if (preg_match('/^([A-Z]{3})(\d{1,4}[A-Z]?)$/', $code, $match) && isset(self::ICAO_TO_IATA[$match[1]])) {
            $carrier = self::ICAO_TO_IATA[$match[1]];
            return ['carrier' => $carrier, 'number' => $match[2], 'code' => $carrier . $match[2]];
        }
        return null;
    }

    /** @return array{year:int,month:int,date:int,iso:string} */
    public static function trackerDate(string $scheduledArrival): array
    {
        $when = new DateTimeImmutable($scheduledArrival);
        $iso = OperationalDay::current($when);
        $day = new DateTimeImmutable($iso . ' 12:00:00');
        return [
            'year' => (int)$day->format('Y'),
            'month' => (int)$day->format('n'),
            'date' => (int)$day->format('j'),
            'iso' => $iso,
        ];
    }

    public static function trackerUrl(string $carrier, string $number, array $parts): string
    {
        return sprintf(
            'https://www.flightstats.com/v2/flight-tracker/%s/%s?year=%d&month=%d&date=%d',
            rawurlencode($carrier),
            rawurlencode($number),
            (int)$parts['year'],
            (int)$parts['month'],
            (int)$parts['date']
        );
    }

    public static function jsonUrl(string $carrier, string $number, array $parts): string
    {
        return sprintf(
            'https://www.flightstats.com/v2/api-next/flight-tracker/%s/%s/%04d/%d/%d',
            rawurlencode($carrier),
            rawurlencode($number),
            (int)$parts['year'],
            (int)$parts['month'],
            (int)$parts['date']
        );
    }
}
