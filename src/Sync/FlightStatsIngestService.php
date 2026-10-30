<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use PDO;
use RuntimeException;
use SevillaMatrix\IataFlightCode;
use SevillaMatrix\Notifications\WebPushService;
use Throwable;

final class FlightStatsIngestService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ready(): bool
    {
        $tables = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN ('flightstats_snapshots','flightstats_requests')"
        )->fetchColumn();
        if ($tables < 2) return false;
        $enum = (string)$this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='observations' AND column_name='source'"
        )->fetchColumn();
        return str_contains($enum, 'flightstats');
    }

    /**
     * @return array<string,mixed>
     */
    public function queue(int $limit = 8): array
    {
        $limit = max(1, min(8, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT f.id,f.flight_date,f.physical_flight,f.origin_iata,f.origin_name,
                    f.scheduled_arrival,f.scheduled_departure,f.is_canary,
                    r.status AS request_status,r.processed_at,r.requested_at,
                    (SELECT GROUP_CONCAT(c.flight_code ORDER BY c.flight_code SEPARATOR ',')
                     FROM flight_codes c WHERE c.flight_id=f.id) AS aliases
             FROM flights f
             LEFT JOIN flightstats_requests r ON r.flight_id=f.id
             WHERE f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 8 HOUR)
                                           AND DATE_ADD(NOW(),INTERVAL 16 HOUR)
               AND (
                 EXISTS (
                   SELECT 1 FROM flight_watches w
                   INNER JOIN push_subscriptions s ON s.id=w.subscription_id
                   WHERE w.flight_id=f.id AND w.enabled=1 AND s.active=1
                 )
                 OR (
                   f.is_canary=1 AND EXISTS (
                     SELECT 1 FROM push_subscriptions s
                     WHERE s.watch_canary_all=1 AND s.active=1
                   )
                 )
                 OR r.status='queued'
               )
               AND (
                 r.processed_at IS NULL
                 OR r.status='queued'
                 OR r.processed_at<DATE_SUB(NOW(),INTERVAL 3 MINUTE)
               )
             ORDER BY CASE WHEN r.status='queued' THEN 0 ELSE 1 END, f.scheduled_arrival
             LIMIT {$limit}"
        );
        $stmt->execute();
        $flights = [];
        foreach ($stmt->fetchAll() as $row) {
            $mapped = $this->mapFlight($row);
            if ($mapped === null) continue;
            $flights[] = $mapped;
        }
        return [
            'ok' => true,
            'configured' => true,
            'cadence_minutes' => 5,
            'flights' => $flights,
        ];
    }

    /** @return array<string,mixed> */
    public function request(int $flightId): array
    {
        if (!$this->ready()) throw new RuntimeException('Aplica la actualización MySQL 012 de FlightStats.');
        $flight = $this->pdo->prepare(
            'SELECT id,physical_flight FROM flights WHERE id=:id
             AND scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 1 DAY) AND DATE_ADD(NOW(),INTERVAL 2 DAY)'
        );
        $flight->execute(['id' => $flightId]);
        $row = $flight->fetch();
        if (!$row) throw new RuntimeException('El vuelo no está dentro de la ventana consultable.');
        $previous = $this->pdo->prepare('SELECT requested_at,status FROM flightstats_requests WHERE flight_id=:id');
        $previous->execute(['id' => $flightId]);
        $before = $previous->fetch();
        $recent = is_array($before) && strtotime((string)$before['requested_at']) >= time() - 180;
        if (!$recent) {
            $this->pdo->prepare(
                "INSERT INTO flightstats_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
                 VALUES (:id,NOW(),NULL,1,'queued',NULL)
                 ON DUPLICATE KEY UPDATE requested_at=NOW(),processed_at=NULL,
                   request_count=request_count+1,status='queued',last_error=NULL"
            )->execute(['id' => $flightId]);
        }
        return [
            'ok' => true,
            'queued' => !$recent,
            'flight' => (string)$row['physical_flight'],
            'message' => $recent
                ? 'La lectura FlightStats ya estaba solicitada recientemente.'
                : 'Lectura FlightStats solicitada. Se procesará en el siguiente ciclo.',
        ];
    }

    /**
     * @param list<array<string,mixed>> $snapshots
     * @return array<string,mixed>
     */
    public function ingest(array $snapshots, bool $dispatch = true): array
    {
        if (!$this->ready()) throw new RuntimeException('Aplica la actualización MySQL 012 de FlightStats.');
        if ($snapshots === [] || count($snapshots) > 8) {
            throw new RuntimeException('snapshots debe ser una lista de hasta 8 registros.');
        }

        $lookup = $this->pdo->prepare(
            'SELECT id,flight_date,physical_flight,origin_iata,origin_name,scheduled_arrival,scheduled_departure,is_canary
             FROM flights WHERE id=:id'
        );
        $latest = $this->pdo->prepare(
            'SELECT status_text,delay_minutes,eta,actual_departure,actual_arrival,departure_text,arrival_text,phase
             FROM flightstats_snapshots WHERE flight_id=:flight_id ORDER BY observed_at DESC,id DESC LIMIT 1'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO flightstats_snapshots
             (flight_id,observed_at,public_url,iata_carrier,iata_number,status_text,delay_minutes,eta,
              actual_departure,actual_arrival,departure_text,arrival_text,phase,raw_excerpt)
             VALUES
             (:flight_id,:observed_at,:public_url,:iata_carrier,:iata_number,:status_text,:delay_minutes,:eta,
              :actual_departure,:actual_arrival,:departure_text,:arrival_text,:phase,:raw_excerpt)'
        );
        $completed = $this->pdo->prepare(
            "INSERT INTO flightstats_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
             VALUES (:id,NOW(),NOW(),1,'processed',NULL)
             ON DUPLICATE KEY UPDATE processed_at=NOW(),status='processed',last_error=NULL"
        );
        $saved = $unchanged = 0;
        $records = [];
        foreach ($snapshots as $record) {
            if (!is_array($record)) continue;
            $flightId = filter_var($record['flight_id'] ?? null, FILTER_VALIDATE_INT);
            $code = strtoupper(preg_replace('/\s+/', '', (string)($record['physical_flight'] ?? '')) ?? '');
            if (!$flightId || !preg_match('/^[A-Z0-9]{2,8}$/', $code)) continue;
            $lookup->execute(['id' => $flightId]);
            $flight = $lookup->fetch();
            if (!is_array($flight) || strtoupper((string)$flight['physical_flight']) !== $code) continue;

            $normalized = $this->normalizeSnapshot($record, $flight);
            $latest->execute(['flight_id' => $flightId]);
            $previous = $latest->fetch();
            $same = is_array($previous);
            foreach (['status_text','delay_minutes','eta','actual_departure','actual_arrival','departure_text','arrival_text','phase'] as $key) {
                if ((string)($previous[$key] ?? '') !== (string)($normalized[$key] ?? '')) {
                    $same = false;
                    break;
                }
            }
            if (!$same) {
                $insert->execute($normalized);
                $saved++;
                $observation = $this->observationRecord($flight, $normalized);
                if ($observation !== null) $records[] = $observation;
            } else {
                $unchanged++;
            }
            $completed->execute(['id' => $flightId]);
        }

        $sync = null;
        $push = null;
        if ($records !== []) {
            $sync = (new FlightReconciliationService($this->pdo))->sync($records, [
                'provider' => 'flightstats',
                'mode' => 'delta',
                'observed_at' => date('Y-m-d H:i:s'),
                'complete' => false,
                'create_missing' => false,
                'metadata' => ['transport' => 'flightstats_public_json', 'snapshots' => count($snapshots)],
            ]);
            if ($dispatch) {
                try {
                    $push = (new WebPushService($this->pdo))->dispatch(50);
                } catch (Throwable $error) {
                    $push = ['error' => substr($error->getMessage(), 0, 300)];
                }
            }
        }

        return [
            'ok' => true,
            'saved' => $saved,
            'unchanged' => $unchanged,
            'sync' => $sync,
            'push' => $push,
        ];
    }

    /** @return array<string,mixed> */
    public function captureOne(int $flightId): array
    {
        if (!$this->ready()) throw new RuntimeException('Aplica la actualización MySQL 012 de FlightStats.');
        $stmt = $this->pdo->prepare(
            'SELECT f.id,f.flight_date,f.physical_flight,f.origin_iata,f.origin_name,
                    f.scheduled_arrival,f.scheduled_departure,f.is_canary,
                    (SELECT GROUP_CONCAT(c.flight_code ORDER BY c.flight_code SEPARATOR \',\')
                     FROM flight_codes c WHERE c.flight_id=f.id) AS aliases
             FROM flights f WHERE f.id=:id'
        );
        $stmt->execute(['id' => $flightId]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('El vuelo ya no existe.');
        $mapped = $this->mapFlight($row);
        if ($mapped === null) throw new RuntimeException('No se pudo traducir el vuelo a un código IATA de FlightStats.');
        $parsed = (new FlightStatsClient())->fetch($mapped);
        $snapshot = $parsed + [
            'flight_id' => $flightId,
            'physical_flight' => (string)$row['physical_flight'],
            'observed_at' => date('c'),
            'raw_excerpt' => json_encode([
                'status_text' => $parsed['status_text'] ?? null,
                'delay_minutes' => $parsed['delay_minutes'] ?? null,
                'eta' => $parsed['eta'] ?? null,
                'actual_departure' => $parsed['actual_departure'] ?? null,
                'source_kind' => $parsed['source_kind'] ?? 'json',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $result = $this->ingest([$snapshot], false);
        $result['snapshot'] = $this->latest($flightId);
        $result['source_kind'] = $parsed['source_kind'] ?? 'json';
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function latest(int $flightId): ?array
    {
        if (!$this->ready()) return null;
        $stmt = $this->pdo->prepare(
            'SELECT id,flight_id,observed_at,public_url,iata_carrier,iata_number,status_text,delay_minutes,
                    eta,actual_departure,actual_arrival,departure_text,arrival_text,phase
             FROM flightstats_snapshots WHERE flight_id=? ORDER BY observed_at DESC,id DESC LIMIT 1'
        );
        $stmt->execute([$flightId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function requeue(int $flightId): bool
    {
        if (!$this->ready()) return false;
        $this->pdo->prepare(
            "INSERT INTO flightstats_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
             VALUES (:id,NOW(),NULL,1,'queued',NULL)
             ON DUPLICATE KEY UPDATE requested_at=NOW(),processed_at=NULL,
               request_count=request_count+1,status='queued',last_error=NULL"
        )->execute(['id' => $flightId]);
        return true;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function mapFlight(array $row): ?array
    {
        $aliases = preg_split('/\s*,\s*/', (string)($row['aliases'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $iata = IataFlightCode::split((string)$row['physical_flight'], $aliases);
        if ($iata === null) return null;
        $parts = IataFlightCode::trackerDate((string)$row['scheduled_arrival']);
        return [
            'id' => (int)$row['id'],
            'flight_date' => (string)$row['flight_date'],
            'physical_flight' => (string)$row['physical_flight'],
            'origin_iata' => (string)$row['origin_iata'],
            'origin_name' => (string)$row['origin_name'],
            'scheduled_arrival' => (string)$row['scheduled_arrival'],
            'scheduled_departure' => $row['scheduled_departure'],
            'is_canary' => (int)$row['is_canary'],
            'iata_carrier' => $iata['carrier'],
            'iata_number' => $iata['number'],
            'iata_code' => $iata['code'],
            'year' => $parts['year'],
            'month' => $parts['month'],
            'date' => $parts['date'],
            'public_url' => IataFlightCode::trackerUrl($iata['carrier'], $iata['number'], $parts),
            'json_url' => IataFlightCode::jsonUrl($iata['carrier'], $iata['number'], $parts),
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $flight
     * @return array<string,mixed>
     */
    private function normalizeSnapshot(array $record, array $flight): array
    {
        $text = static fn(string $key, int $max): ?string => isset($record[$key]) && trim((string)$record[$key]) !== ''
            ? substr(trim((string)$record[$key]), 0, $max) : null;
        $carrier = strtoupper((string)($text('iata_carrier', 2) ?? ''));
        $number = (string)($text('iata_number', 8) ?? '');
        $observed = new \DateTimeImmutable((string)($record['observed_at'] ?? 'now'));
        $eta = FlightStatsParser::localDateTime($record['eta'] ?? null)
            ?: FlightStatsParser::clockOnDay((string)$flight['scheduled_arrival'], (string)($record['arr_est'] ?? ''));
        $actualArrival = FlightStatsParser::localDateTime($record['actual_arrival'] ?? null);
        $actualDeparture = FlightStatsParser::localDateTime($record['actual_departure'] ?? null)
            ?: FlightStatsParser::clockOnDay((string)($flight['scheduled_departure'] ?: $flight['scheduled_arrival']), (string)($record['dep_actual'] ?? ''));
        $delay = isset($record['delay_minutes']) && is_numeric($record['delay_minutes'])
            ? max(-24 * 60, min(24 * 60, (int)round((float)$record['delay_minutes']))) : null;
        if ($eta === null && $delay !== null && $delay !== 0) {
            $scheduledAt = strtotime((string)$flight['scheduled_arrival']);
            if ($scheduledAt !== false) {
                $eta = date('Y-m-d H:i:s', $scheduledAt + $delay * 60);
            }
        }
        $publicUrl = $text('public_url', 300)
            ?: IataFlightCode::trackerUrl($carrier, $number, IataFlightCode::trackerDate((string)$flight['scheduled_arrival']));
        return [
            'flight_id' => (int)$flight['id'],
            'observed_at' => $observed->format('Y-m-d H:i:s'),
            'public_url' => $publicUrl,
            'iata_carrier' => preg_match('/^[A-Z][A-Z0-9]$/', $carrier) ? $carrier : null,
            'iata_number' => preg_match('/^\d{1,4}[A-Z]?$/', $number) ? $number : null,
            'status_text' => $text('status_text', 160),
            'delay_minutes' => $delay,
            'eta' => $eta,
            'actual_departure' => $actualDeparture,
            'actual_arrival' => $actualArrival,
            'departure_text' => $text('departure_text', 80),
            'arrival_text' => $text('arrival_text', 80),
            'phase' => $text('phase', 40),
            'raw_excerpt' => $text('raw_excerpt', 8000),
        ];
    }

    /**
     * @param array<string,mixed> $flight
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>|null
     */
    private function observationRecord(array $flight, array $snapshot): ?array
    {
        $status = $snapshot['status_text'];
        $eta = $snapshot['eta'];
        $departure = $snapshot['actual_departure'];
        $arrival = $snapshot['actual_arrival'];
        if ($status === null && $eta === null && $departure === null && $arrival === null) return null;
        return [
            'source_key' => implode('|', [
                (string)$flight['flight_date'],
                (string)$flight['physical_flight'],
                (string)$flight['origin_iata'],
                'SVQ',
            ]),
            'flight_date' => $flight['flight_date'],
            'physical_flight' => $flight['physical_flight'],
            'origin_iata' => $flight['origin_iata'],
            'origin_name' => $flight['origin_name'],
            'scheduled_arrival' => $flight['scheduled_arrival'],
            'is_canary' => (int)$flight['is_canary'] === 1,
            'status' => $status,
            'eta' => $eta,
            'actual_departure' => $departure,
            'actual_arrival' => $arrival,
            'confidence' => 'provisional',
            'reason_code' => 'secondary_provider_update',
            'reason_detail' => 'FlightStats actualizó retraso, despegue o ETA; no sustituye cinta, sala ni estado de Aena.',
            'occupancy_level' => 'no_verificable',
            'raw_data' => [
                'provider' => 'flightstats',
                'delay_minutes' => $snapshot['delay_minutes'],
                'phase' => $snapshot['phase'],
                'public_url' => $snapshot['public_url'],
            ],
        ];
    }
}
