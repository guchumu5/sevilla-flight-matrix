<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use PDO;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\OperationalDay;
use Throwable;

final class FlightFeederService
{
    public const CANARY_ORIGINS = ['ACE', 'FUE', 'LPA', 'TFN', 'TFS'];
    public const MIN_DELAY = 5;
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36 SevillaFlightMatrix/1.0';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?FlightStatsClient $client = null,
    ) {
    }

    public function ready(): bool
    {
        try {
            return (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='flight_feeders'"
            )->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $flight
     */
    public function isNightCanary(array $flight): bool
    {
        if ((int)($flight['is_canary'] ?? 0) !== 1) return false;
        $origin = strtoupper(trim((string)($flight['origin_iata'] ?? '')));
        if (!in_array($origin, self::CANARY_ORIGINS, true)) return false;
        $when = (string)($flight['scheduled_arrival'] ?? '');
        if ($when === '') return false;
        $hour = (int)(new \DateTimeImmutable($when))->format('G');
        return $hour >= FlightRepository::EVENING_FROM_HOUR || $hour < OperationalDay::CUTOFF_HOUR;
    }

    /**
     * @param array<string,mixed> $flight
     * @param array<string,mixed> $mapped
     * @param array<string,mixed>|null $inboundSnapshot
     * @return array<string,mixed>|null
     */
    public function capture(array $flight, array $mapped, ?array $inboundSnapshot = null): ?array
    {
        if (!$this->ready() || !$this->isNightCanary($flight)) return null;
        $island = strtoupper(trim((string)($flight['origin_iata'] ?? '')));
        if ($island === '') return null;
        $tail = strtoupper(trim((string)(
            ($inboundSnapshot['aircraft_registration'] ?? null)
            ?: ($flight['aircraft_registration'] ?? '')
        )));
        if ($tail === '' || !preg_match('/^[A-Z0-9-]{3,15}$/', $tail)) {
            $tail = $this->resolveTail($mapped, $inboundSnapshot);
        }
        if ($tail === '') return null;

        $html = $this->httpGet(FlightAwareActivityParser::tailUrl($tail));
        if (!is_string($html) || $html === '') return null;
        $bootstrap = FlightAwareActivityParser::bootstrapFromHtml($html);
        if ($bootstrap === null) return null;
        $current = FlightAwareActivityParser::ident(
            (string)(($mapped['iata_code'] ?? null) ?: ($flight['physical_flight'] ?? ''))
        );
        $sector = FlightAwareActivityParser::previousSector($bootstrap, $current, $island);
        if ($sector === null) return null;
        $sector['aircraft_registration'] = $sector['aircraft_registration'] ?: $tail;
        return $this->store((int)$flight['id'], $sector);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function attach(array &$rows): void
    {
        foreach ($rows as &$row) $row['feeder'] = null;
        unset($row);
        if (!$this->ready() || $rows === []) return;
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $rows
        ))));
        if ($ids === []) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $registration = $this->hasRegistrationColumn() ? ',aircraft_registration' : '';
        $stmt = $this->pdo->prepare(
            "SELECT flight_id,observed_at,feeder_code,origin_iata,destination_iata,delay_minutes,
                    eta,actual_departure,actual_arrival,status_text,match_reason,public_url{$registration}
             FROM flight_feeders WHERE flight_id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $indexed = [];
        foreach ($stmt->fetchAll() as $row) {
            $indexed[(int)$row['flight_id']] = $this->publicPayload($row);
        }
        foreach ($rows as &$row) {
            $row['feeder'] = $indexed[(int)($row['id'] ?? 0)] ?? null;
        }
        unset($row);
    }

    /**
     * @param list<int> $flightIds
     * @return array<int,array<string,mixed>>
     */
    public function latestByFlightIds(array $flightIds): array
    {
        $flightIds = array_values(array_unique(array_filter(array_map('intval', $flightIds))));
        if (!$this->ready() || $flightIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($flightIds), '?'));
        $registration = $this->hasRegistrationColumn() ? ',aircraft_registration' : '';
        $stmt = $this->pdo->prepare(
            "SELECT flight_id,observed_at,feeder_code,origin_iata,destination_iata,delay_minutes,
                    eta,actual_departure,actual_arrival,status_text,match_reason,public_url{$registration}
             FROM flight_feeders WHERE flight_id IN ({$placeholders})"
        );
        $stmt->execute($flightIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $payload = $this->publicPayload($row);
            if ($payload !== null) $out[(int)$row['flight_id']] = $payload;
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    public function publicPayload(?array $row): ?array
    {
        if (!is_array($row) || $row === []) return null;
        $code = strtoupper(trim((string)($row['feeder_code'] ?? '')));
        if ($code === '') return null;
        $tail = strtoupper(trim((string)($row['aircraft_registration'] ?? '')));
        return [
            'feeder_code' => $code,
            'origin_iata' => strtoupper(trim((string)($row['origin_iata'] ?? ''))),
            'destination_iata' => strtoupper(trim((string)($row['destination_iata'] ?? ''))),
            'delay_minutes' => isset($row['delay_minutes']) && $row['delay_minutes'] !== null && $row['delay_minutes'] !== ''
                ? (int)$row['delay_minutes'] : null,
            'eta' => $row['eta'] ?? null,
            'actual_departure' => $row['actual_departure'] ?? null,
            'actual_arrival' => $row['actual_arrival'] ?? null,
            'status_text' => $row['status_text'] ?? null,
            'match_reason' => (string)($row['match_reason'] ?? 'same_aircraft'),
            'aircraft_registration' => preg_match('/^[A-Z0-9-]{3,15}$/', $tail) ? $tail : null,
            'public_url' => $row['public_url'] ?? null,
            'observed_at' => $row['observed_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $mapped
     * @param array<string,mixed>|null $inboundSnapshot
     */
    private function resolveTail(array $mapped, ?array $inboundSnapshot): string
    {
        $fromSnapshot = strtoupper(trim((string)($inboundSnapshot['aircraft_registration'] ?? '')));
        if (preg_match('/^[A-Z0-9-]{3,15}$/', $fromSnapshot)) return $fromSnapshot;
        try {
            $parsed = ($this->client ?? new FlightStatsClient())->fetchRoute($mapped, 'SVQ', null, true);
        } catch (Throwable) {
            return '';
        }
        $tail = strtoupper(trim((string)($parsed['aircraft_registration'] ?? '')));
        return preg_match('/^[A-Z0-9-]{3,15}$/', $tail) ? $tail : '';
    }

    /**
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function store(int $flightId, array $parsed): array
    {
        $code = strtoupper(trim((string)($parsed['feeder_code'] ?? '')));
        if ($code === '') {
            $carrier = strtoupper(trim((string)($parsed['iata_carrier'] ?? '')));
            $number = trim((string)($parsed['iata_number'] ?? ''));
            if (preg_match('/^[A-Z][A-Z0-9]$/', $carrier) && preg_match('/^\d{1,4}[A-Z]?$/', $number)) {
                $code = $carrier . $number;
            }
        }
        $delay = isset($parsed['delay_minutes']) && is_numeric($parsed['delay_minutes'])
            ? max(-24 * 60, min(24 * 60, (int)round((float)$parsed['delay_minutes']))) : null;
        $origin = strtoupper(trim((string)($parsed['origin_iata'] ?? '')));
        $dest = strtoupper(trim((string)($parsed['destination_iata'] ?? '')));
        $tail = strtoupper(trim((string)($parsed['aircraft_registration'] ?? '')));
        $payload = [
            'flight_id' => $flightId,
            'observed_at' => date('Y-m-d H:i:s'),
            'feeder_code' => substr($code, 0, 12),
            'origin_iata' => $origin !== '' ? $origin : '???',
            'destination_iata' => $dest !== '' ? $dest : '???',
            'delay_minutes' => $delay,
            'eta' => FlightStatsParser::localDateTime($parsed['eta'] ?? null),
            'actual_departure' => FlightStatsParser::localDateTime($parsed['actual_departure'] ?? null),
            'actual_arrival' => FlightStatsParser::localDateTime($parsed['actual_arrival'] ?? null),
            'status_text' => isset($parsed['status_text']) && trim((string)$parsed['status_text']) !== ''
                ? substr(trim((string)$parsed['status_text']), 0, 160) : null,
            'match_reason' => 'same_aircraft',
            'public_url' => isset($parsed['public_url']) ? substr(trim((string)$parsed['public_url']), 0, 300) : null,
        ];
        $withTail = $this->hasRegistrationColumn() && preg_match('/^[A-Z0-9-]{3,15}$/', $tail);
        if ($withTail) $payload['aircraft_registration'] = $tail;
        $compare = ['feeder_code', 'delay_minutes', 'eta', 'actual_departure', 'actual_arrival', 'status_text', 'origin_iata', 'destination_iata'];
        $previous = $this->pdo->prepare(
            'SELECT ' . implode(',', $compare) . ' FROM flight_feeders WHERE flight_id=:id'
        );
        $previous->execute(['id' => $flightId]);
        $before = $previous->fetch();
        $changed = !is_array($before);
        if (is_array($before)) {
            foreach ($compare as $key) {
                if ((string)($before[$key] ?? '') !== (string)($payload[$key] ?? '')) {
                    $changed = true;
                    break;
                }
            }
        }
        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $key): string => ':' . $key, $columns);
        $updates = [];
        foreach ($columns as $key) {
            if ($key === 'flight_id') continue;
            $updates[] = $key . '=VALUES(' . $key . ')';
        }
        $this->pdo->prepare(
            'INSERT INTO flight_feeders (' . implode(',', $columns) . ')
             VALUES (' . implode(',', $placeholders) . ')
             ON DUPLICATE KEY UPDATE ' . implode(',', $updates)
        )->execute($payload);
        if ($changed) {
            FlightRepository::forgetSnapshotCaches();
        }
        $public = $this->publicPayload($payload + ['aircraft_registration' => $withTail ? $tail : null]);
        return ($public ?? []) + ['saved' => $changed ? 1 : 0];
    }

    private function hasRegistrationColumn(): bool
    {
        try {
            return (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE() AND table_name='flight_feeders'
                   AND column_name='aircraft_registration'"
            )->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function httpGet(string $url): ?string
    {
        $curl = curl_init($url);
        if ($curl === false) return null;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: es-ES,es;q=0.9,en;q=0.8',
            ],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($body) || $body === '' || $status >= 400) return null;
        return $body;
    }
}
