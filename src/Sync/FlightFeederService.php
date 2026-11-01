<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use PDO;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\IataFlightCode;
use SevillaMatrix\OperationalDay;
use Throwable;

final class FlightFeederService
{
    public const CANARY_ORIGINS = ['ACE', 'FUE', 'LPA', 'TFN', 'TFS'];
    public const MIN_DELAY = 5;

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
     * @param array<string,mixed> $flight fila de flights (+ aliases si vienen)
     * @param array<string,mixed> $mapped  salida de mapFlight (carrier/number/fecha)
     * @return array<string,mixed>|null
     */
    public function capture(array $flight, array $mapped): ?array
    {
        if (!$this->ready() || !$this->isNightCanary($flight)) return null;
        $island = strtoupper(trim((string)($flight['origin_iata'] ?? '')));
        $pairs = IataFlightCode::pairedNumbers((string)($mapped['iata_number'] ?? ''));
        if ($pairs === [] || $island === '') return null;

        $client = $this->client ?? new FlightStatsClient();
        foreach ($pairs as $index => $number) {
            $probe = $mapped;
            $probe['iata_number'] = $number;
            $probe['iata_code'] = (string)($mapped['iata_carrier'] ?? '') . $number;
            try {
                $parsed = $client->fetchRoute($probe, $island, 'SVQ', true);
            } catch (Throwable) {
                if ($index < count($pairs) - 1) usleep(180_000);
                continue;
            }
            if (!$this->matchesRotation($parsed, $island)) {
                if ($index < count($pairs) - 1) usleep(180_000);
                continue;
            }
            return $this->store((int)$flight['id'], $parsed, $island);
        }
        return null;
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
        $stmt = $this->pdo->prepare(
            "SELECT flight_id,observed_at,feeder_code,origin_iata,destination_iata,delay_minutes,
                    eta,actual_departure,actual_arrival,status_text,match_reason,public_url
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
        $stmt = $this->pdo->prepare(
            "SELECT flight_id,observed_at,feeder_code,origin_iata,destination_iata,delay_minutes,
                    eta,actual_departure,actual_arrival,status_text,match_reason,public_url
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
        return [
            'feeder_code' => $code,
            'origin_iata' => strtoupper(trim((string)($row['origin_iata'] ?? 'SVQ'))) ?: 'SVQ',
            'destination_iata' => strtoupper(trim((string)($row['destination_iata'] ?? ''))),
            'delay_minutes' => isset($row['delay_minutes']) && $row['delay_minutes'] !== null && $row['delay_minutes'] !== ''
                ? (int)$row['delay_minutes'] : null,
            'eta' => $row['eta'] ?? null,
            'actual_departure' => $row['actual_departure'] ?? null,
            'actual_arrival' => $row['actual_arrival'] ?? null,
            'status_text' => $row['status_text'] ?? null,
            'match_reason' => (string)($row['match_reason'] ?? 'paired_number'),
            'public_url' => $row['public_url'] ?? null,
            'observed_at' => $row['observed_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $parsed
     */
    public function matchesRotation(array $parsed, string $island): bool
    {
        $island = strtoupper(trim($island));
        $destination = strtoupper(trim((string)($parsed['destination_iata'] ?? '')));
        $origin = strtoupper(trim((string)($parsed['origin_iata'] ?? '')));
        if ($island === '' || $destination === '' || $destination !== $island) return false;
        return $origin === '' || $origin === 'SVQ';
    }

    /**
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function store(int $flightId, array $parsed, string $island): array
    {
        $carrier = strtoupper(trim((string)($parsed['iata_carrier'] ?? '')));
        $number = trim((string)($parsed['iata_number'] ?? ''));
        $code = preg_match('/^[A-Z][A-Z0-9]$/', $carrier) && preg_match('/^\d{1,4}[A-Z]?$/', $number)
            ? $carrier . $number
            : strtoupper(trim((string)($parsed['feeder_code'] ?? '')));
        $delay = isset($parsed['delay_minutes']) && is_numeric($parsed['delay_minutes'])
            ? max(-24 * 60, min(24 * 60, (int)round((float)$parsed['delay_minutes']))) : null;
        $payload = [
            'flight_id' => $flightId,
            'observed_at' => date('Y-m-d H:i:s'),
            'feeder_code' => substr($code, 0, 12),
            'origin_iata' => 'SVQ',
            'destination_iata' => $island,
            'delay_minutes' => $delay,
            'eta' => FlightStatsParser::localDateTime($parsed['eta'] ?? null),
            'actual_departure' => FlightStatsParser::localDateTime($parsed['actual_departure'] ?? null),
            'actual_arrival' => FlightStatsParser::localDateTime($parsed['actual_arrival'] ?? null),
            'status_text' => isset($parsed['status_text']) && trim((string)$parsed['status_text']) !== ''
                ? substr(trim((string)$parsed['status_text']), 0, 160) : null,
            'match_reason' => 'paired_number',
            'public_url' => isset($parsed['public_url']) ? substr(trim((string)$parsed['public_url']), 0, 300) : null,
        ];
        $previous = $this->pdo->prepare(
            'SELECT feeder_code,delay_minutes,eta,actual_departure,actual_arrival,status_text
             FROM flight_feeders WHERE flight_id=:id'
        );
        $previous->execute(['id' => $flightId]);
        $before = $previous->fetch();
        $changed = !is_array($before);
        if (is_array($before)) {
            foreach (['feeder_code', 'delay_minutes', 'eta', 'actual_departure', 'actual_arrival', 'status_text'] as $key) {
                if ((string)($before[$key] ?? '') !== (string)($payload[$key] ?? '')) {
                    $changed = true;
                    break;
                }
            }
        }
        $this->pdo->prepare(
            'INSERT INTO flight_feeders
             (flight_id,observed_at,feeder_code,origin_iata,destination_iata,delay_minutes,eta,
              actual_departure,actual_arrival,status_text,match_reason,public_url)
             VALUES
             (:flight_id,:observed_at,:feeder_code,:origin_iata,:destination_iata,:delay_minutes,:eta,
              :actual_departure,:actual_arrival,:status_text,:match_reason,:public_url)
             ON DUPLICATE KEY UPDATE
               observed_at=VALUES(observed_at),feeder_code=VALUES(feeder_code),
               origin_iata=VALUES(origin_iata),destination_iata=VALUES(destination_iata),
               delay_minutes=VALUES(delay_minutes),eta=VALUES(eta),
               actual_departure=VALUES(actual_departure),actual_arrival=VALUES(actual_arrival),
               status_text=VALUES(status_text),match_reason=VALUES(match_reason),
               public_url=VALUES(public_url)'
        )->execute($payload);
        if ($changed) {
            FlightRepository::forgetSnapshotCaches();
        }
        $public = $this->publicPayload($payload);
        return ($public ?? []) + ['saved' => $changed ? 1 : 0];
    }
}
