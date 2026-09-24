<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class FlightReconciliationService
{
    private const OBSERVATION_SOURCES = ['aena', 'airlabs', 'opensky', 'manual', 'aviationweather'];
    private const MODES = ['full_window', 'delta', 'manual'];
    private const CONFIDENCE = ['confirmado', 'probable', 'provisional'];
    private const OPERATIONAL_FIELDS = [
        'status', 'eta', 'actual_departure', 'actual_arrival', 'hall', 'belt',
        'gate', 'stand', 'baggage_state', 'occupancy_level',
    ];
    private const CORE_FIELDS = [
        'scheduled_arrival', 'scheduled_departure', 'origin_name', 'aircraft_registration',
        'aircraft_icao24', 'aircraft_type', 'capacity', 'traffic_class', 'border_control', 'is_canary',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function sync(array $records, array $context): array
    {
        $provider = strtolower(trim((string)($context['provider'] ?? '')));
        $mode = strtolower(trim((string)($context['mode'] ?? 'delta')));
        $observedAt = $this->dateTime($context['observed_at'] ?? date('Y-m-d H:i:s'), 'observed_at');
        $windowFrom = $this->nullableDateTime($context['window_from'] ?? null, 'window_from');
        $windowTo = $this->nullableDateTime($context['window_to'] ?? null, 'window_to');
        $complete = filter_var($context['complete'] ?? false, FILTER_VALIDATE_BOOL);
        $withdrawAfter = max(2, min(10, (int)($context['withdraw_after'] ?? 3)));

        if (!in_array($provider, self::OBSERVATION_SOURCES, true)) {
            throw new InvalidArgumentException('Proveedor no válido para las observaciones.');
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Modo de sincronización no válido.');
        }
        if ($complete && $mode !== 'full_window') {
            throw new InvalidArgumentException('Solo una ejecución full_window puede declarar una ventana completa.');
        }
        if ($complete && (!$windowFrom || !$windowTo || $windowFrom > $windowTo)) {
            throw new InvalidArgumentException('Una ventana completa necesita límites válidos.');
        }
        if ($complete && $records === [] && !filter_var($context['allow_empty'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new InvalidArgumentException('Se rechazó una ventana completa vacía para evitar retiradas masivas accidentales.');
        }

        $runId = $this->startRun($provider, $mode, $windowFrom, $windowTo, $observedAt, count($records), $context);
        $lockName = 'sevilla_matrix_sync_global';
        $lockHeld = false;
        $counts = [
            'records_received' => count($records),
            'flights_created' => 0,
            'flights_updated' => 0,
            'observations_created' => 0,
            'events_created' => 0,
            'flights_missing' => 0,
            'flights_withdrawn' => 0,
        ];

        try {
            $lockHeld = $this->acquireLock($lockName);
            if (!$lockHeld) {
                throw new RuntimeException('Ya hay una sincronización de este proveedor en curso.');
            }

            $this->pdo->beginTransaction();
            $seenKeys = [];
            foreach ($records as $index => $record) {
                if (!is_array($record)) {
                    throw new InvalidArgumentException('El registro ' . ($index + 1) . ' no es un objeto válido.');
                }
                $normalized = $this->normalizeRecord($record, $provider, $observedAt);
                if (isset($seenKeys[$normalized['source_key']])) {
                    throw new InvalidArgumentException('Clave de origen duplicada en el lote: ' . $normalized['source_key']);
                }
                $seenKeys[$normalized['source_key']] = true;
                $this->reconcileRecord($runId, $provider, $normalized, $counts);
            }

            if ($complete) {
                $this->markMissingFlights(
                    $runId,
                    $provider,
                    $observedAt,
                    $windowFrom,
                    $windowTo,
                    array_keys($seenKeys),
                    $withdrawAfter,
                    $counts
                );
            }

            $this->pdo->commit();
            $this->finishRun($runId, 'success', $counts, null);

            return ['ok' => true, 'run_id' => $runId, 'provider' => $provider, 'mode' => $mode] + $counts;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            try {
                $this->finishRun($runId, 'failed', $counts, $this->truncate($error->getMessage(), 1000));
            } catch (Throwable) {
                // Preserve the reconciliation error if the database connection also fails here.
            }
            throw $error;
        } finally {
            if ($lockHeld) {
                $this->releaseLock($lockName);
            }
        }
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function normalizeRecord(array $record, string $provider, string $observedAt): array
    {
        $physical = strtoupper(trim((string)($record['physical_flight'] ?? '')));
        $originIata = strtoupper(trim((string)($record['origin_iata'] ?? '')));
        $flightDate = $this->date((string)($record['flight_date'] ?? ''), 'flight_date');
        $scheduledArrival = $this->dateTime($record['scheduled_arrival'] ?? null, 'scheduled_arrival');

        if (!preg_match('/^[A-Z0-9]{2,4}\s?\d{1,5}[A-Z]?$/', $physical)) {
            throw new InvalidArgumentException('Código de vuelo físico no válido: ' . $physical);
        }
        if (!preg_match('/^[A-Z]{3}$/', $originIata)) {
            throw new InvalidArgumentException('IATA de origen no válido: ' . $originIata);
        }

        $sourceKey = trim((string)($record['source_key'] ?? ''));
        if ($sourceKey === '') {
            $sourceKey = implode('|', [$flightDate, $physical, $originIata, 'SVQ']);
        }
        if (strlen($sourceKey) > 190 || preg_match('/[\x00-\x1F\x7F]/', $sourceKey)) {
            throw new InvalidArgumentException('source_key no válido.');
        }

        $traffic = strtolower(trim((string)($record['traffic_class'] ?? 'desconocido')));
        if (!in_array($traffic, ['domestico', 'schengen', 'no_schengen', 'desconocido'], true)) {
            $traffic = 'desconocido';
        }
        $baggage = $this->nullableEnum($record['baggage_state'] ?? null, ['pendiente', 'entrega', 'finalizado']);
        $occupancy = $this->nullableEnum($record['occupancy_level'] ?? 'no_verificable', ['no_verificable', 'baja', 'media', 'alta']) ?? 'no_verificable';
        $confidence = $this->nullableEnum($record['confidence'] ?? null, self::CONFIDENCE)
            ?? ($provider === 'aena' ? 'confirmado' : 'provisional');
        $codeshares = [];
        foreach ((array)($record['codeshares'] ?? []) as $code) {
            $code = strtoupper(trim((string)$code));
            if ($code !== '' && preg_match('/^[A-Z0-9]{2,4}\s?\d{1,5}[A-Z]?$/', $code)) {
                $codeshares[$code] = true;
            }
        }

        $normalized = [
            'source_key' => $sourceKey,
            'flight_date' => $flightDate,
            'physical_flight' => $physical,
            'origin_iata' => $originIata,
            'origin_name' => trim((string)($record['origin_name'] ?? $originIata)),
            'destination_iata' => 'SVQ',
            'scheduled_arrival' => $scheduledArrival,
            'scheduled_departure' => $this->nullableDateTime($record['scheduled_departure'] ?? null, 'scheduled_departure'),
            'aircraft_registration' => $this->nullableUpper($record['aircraft_registration'] ?? null),
            'aircraft_icao24' => $this->nullableLower($record['aircraft_icao24'] ?? null),
            'aircraft_type' => $this->nullableString($record['aircraft_type'] ?? null),
            'capacity' => isset($record['capacity']) && $record['capacity'] !== '' ? max(1, min(600, (int)$record['capacity'])) : null,
            'traffic_class' => $traffic,
            'border_control' => filter_var($record['border_control'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'is_canary' => filter_var($record['is_canary'] ?? in_array($originIata, ['LPA', 'TFN', 'TFS', 'ACE', 'FUE'], true), FILTER_VALIDATE_BOOL) ? 1 : 0,
            'codeshares' => array_keys($codeshares),
            'status' => $this->nullableString($record['status'] ?? null),
            'eta' => $this->nullableDateTime($record['eta'] ?? null, 'eta'),
            'actual_departure' => $this->nullableDateTime($record['actual_departure'] ?? null, 'actual_departure'),
            'actual_arrival' => $this->nullableDateTime($record['actual_arrival'] ?? null, 'actual_arrival'),
            'hall' => $this->nullableUpper($record['hall'] ?? null),
            'belt' => $this->nullableString($record['belt'] ?? null),
            'gate' => $this->nullableUpper($record['gate'] ?? null),
            'stand' => $this->nullableUpper($record['stand'] ?? null),
            'baggage_state' => $baggage,
            'occupancy_level' => $occupancy,
            'reason_code' => $this->safeToken($record['reason_code'] ?? null),
            'reason_detail' => $this->nullableString($record['reason_detail'] ?? null),
            'confidence' => $confidence,
            'observed_at' => $this->nullableDateTime($record['observed_at'] ?? null, 'record.observed_at') ?? $observedAt,
            'raw_data' => $record['raw_data'] ?? $record,
            '_provided' => array_fill_keys(array_keys($record), true),
        ];

        return $normalized;
    }

    /** @param array<string,mixed> $record @param array<string,int> $counts */
    private function reconcileRecord(int $runId, string $provider, array $record, array &$counts): void
    {
        $state = $this->sourceState($provider, $record['source_key']);
        $flight = $state ? $this->flightById((int)$state['flight_id']) : $this->matchFlight($record);
        $isNew = !$flight;

        if ($isNew) {
            $flightId = $this->insertFlight($record);
            $flight = $this->flightById($flightId);
            $counts['flights_created']++;
            $this->addEvent($flightId, $runId, $provider, 'flight_created', null, null, $record['physical_flight'],
                'first_seen', 'Primera aparición del vuelo en esta fuente.', $record['confidence'], $record, $record['observed_at'], $counts);
        } else {
            $flightId = (int)$flight['id'];
            if ($this->updateCoreFields($flightId, $flight, $record, $runId, $provider, $counts)) {
                $counts['flights_updated']++;
                $flight = $this->flightById($flightId);
            }
        }

        if (!$state && !$isNew) {
            $this->addEvent($flightId, $runId, $provider, 'source_first_seen', null, null, $record['source_key'],
                'first_seen', 'Primera aparición de este vuelo para el proveedor.', $record['confidence'],
                $record, $record['observed_at'], $counts);
        }

        $this->upsertCodes($flightId, $record['physical_flight'], $record['codeshares']);
        $previous = $state ? json_decode((string)$state['snapshot'], true) : null;
        if (!is_array($previous)) {
            $previous = [];
        }

        $visibility = $this->isCancelled($record['status']) ? 'cancelled' : 'active';
        if ($state && $state['visibility_status'] !== 'active' && $visibility === 'active') {
            $this->addEvent($flightId, $runId, $provider, 'flight_reappeared', 'visibility_status',
                $state['visibility_status'], 'active', 'source_reappeared',
                'El vuelo vuelve a aparecer tras una ausencia en la fuente.', $record['confidence'], $record, $record['observed_at'], $counts);
        }

        $snapshot = $this->snapshot($record);
        $payloadHash = hash('sha256', $this->canonicalJson($snapshot));
        if (!$state || !hash_equals((string)$state['last_payload_hash'], $payloadHash)) {
            $this->addObservation($flightId, $provider, $record);
            $counts['observations_created']++;
            $this->detectOperationalEvents($flightId, $runId, $provider, $previous, $snapshot, $record, $counts);
        }

        if ($visibility === 'cancelled' && (!$state || $state['visibility_status'] !== 'cancelled')) {
            $this->addEvent($flightId, $runId, $provider, 'flight_cancelled', 'status',
                $previous['status'] ?? null, $record['status'], 'source_cancelled',
                'La fuente publicó el vuelo como cancelado.', $record['confidence'], $record, $record['observed_at'], $counts);
        }
        $this->saveSourceState($flightId, $provider, $record['source_key'], $record['observed_at'], $payloadHash, $snapshot, $visibility);
    }

    /** @param array<string,mixed> $flight @param array<string,mixed> $record @param array<string,int> $counts */
    private function updateCoreFields(int $flightId, array $flight, array $record, int $runId, string $provider, array &$counts): bool
    {
        $changed = [];
        foreach (self::CORE_FIELDS as $field) {
            if (!in_array($field, ['scheduled_arrival', 'is_canary'], true)
                && !isset($record['_provided'][$field])) {
                continue;
            }
            $before = $this->scalar($flight[$field] ?? null);
            $after = $this->scalar($record[$field] ?? null);
            if ($before !== $after) {
                $changed[$field] = [$before, $after];
            }
        }
        if (!$changed) {
            return false;
        }

        $set = [];
        $params = ['id' => $flightId];
        foreach (array_keys($changed) as $field) {
            $set[] = $field . '=:' . $field;
            $params[$field] = $record[$field];
        }
        $stmt = $this->pdo->prepare('UPDATE flights SET ' . implode(',', $set) . ' WHERE id=:id');
        $stmt->execute($params);

        foreach ($changed as $field => [$before, $after]) {
            $type = $field === 'scheduled_arrival' ? 'schedule_changed'
                : (str_starts_with($field, 'aircraft_') || $field === 'capacity' ? 'aircraft_changed' : 'flight_data_changed');
            $this->addEvent($flightId, $runId, $provider, $type, $field, $before, $after,
                $record['reason_code'] ?? 'source_changed',
                $record['reason_detail'] ?? ('La fuente modificó ' . $field . '; la causa operativa no fue publicada.'),
                $record['confidence'], $record, $record['observed_at'], $counts);
        }
        return true;
    }

    /** @param array<string,mixed> $previous @param array<string,mixed> $current @param array<string,mixed> $record @param array<string,int> $counts */
    private function detectOperationalEvents(int $flightId, int $runId, string $provider, array $previous, array $current, array $record, array &$counts): void
    {
        foreach (self::OPERATIONAL_FIELDS as $field) {
            $before = $this->scalar($previous[$field] ?? null);
            $after = $this->scalar($current[$field] ?? null);
            if ($field === 'occupancy_level' && $before === null && $after === 'no_verificable') {
                continue;
            }
            if ($before === $after) {
                continue;
            }
            [$type, $reasonCode, $detail] = $this->eventMeaning($field, $before, $after);
            $this->addEvent(
                $flightId,
                $runId,
                $provider,
                $type,
                $field,
                $before,
                $after,
                $record['reason_code'] ?? $reasonCode,
                $record['reason_detail'] ?? $detail,
                $record['confidence'],
                $record,
                $record['observed_at'],
                $counts
            );
        }
    }

    /** @return array{0:string,1:string,2:string} */
    private function eventMeaning(string $field, ?string $before, ?string $after): array
    {
        if ($field === 'belt') {
            if ($before === null && $after !== null) return ['belt_assigned', 'belt_assignment', 'Primera cinta publicada por la fuente.'];
            if ($before !== null && $after === null) return ['belt_removed', 'belt_removed', 'La fuente retiró la cinta; la causa operativa no fue publicada.'];
            return ['belt_changed', 'belt_change', 'La fuente cambió la cinta; la causa operativa no fue publicada.'];
        }
        if ($field === 'hall' && $before === null && $after !== null) return ['hall_assigned', 'hall_assignment', 'Primera sala publicada por la fuente.'];
        if ($field === 'hall') return ['hall_changed', 'hall_change', 'La fuente cambió la sala; la causa operativa no fue publicada.'];
        if ($field === 'eta' && $before === null && $after !== null) return ['eta_published', 'source_changed', 'Primera hora estimada publicada por la fuente.'];
        if ($field === 'eta') return ['eta_changed', 'source_changed', 'La fuente actualizó la hora estimada.'];
        if ($field === 'status' && $before === null && $after !== null) return ['status_published', 'status_transition', 'Primer estado publicado por la fuente.'];
        if ($field === 'status') return ['status_changed', 'status_transition', 'La fuente publicó una transición de estado.'];
        if ($field === 'baggage_state' && $after === 'entrega') return ['baggage_started', 'baggage_transition', 'La fuente indicó el inicio de la entrega de equipaje.'];
        if ($field === 'baggage_state' && $after === 'finalizado') return ['baggage_finished', 'baggage_transition', 'La fuente indicó la finalización del equipaje.'];
        if ($field === 'actual_arrival') return ['arrival_recorded', 'source_changed', 'La fuente publicó la llegada real.'];
        if ($field === 'actual_departure') return ['departure_recorded', 'source_changed', 'La fuente publicó la salida real.'];
        if ($field === 'gate') return ['gate_changed', 'gate_change', 'La fuente actualizó la puerta.'];
        if ($field === 'stand') return ['stand_changed', 'stand_change', 'La fuente actualizó la posición de estacionamiento.'];
        return ['observation_changed', 'source_changed', 'La fuente actualizó este dato.'];
    }

    /** @param array<string,int> $counts */
    private function markMissingFlights(int $runId, string $provider, string $observedAt, string $windowFrom, string $windowTo, array $seenKeys, int $withdrawAfter, array &$counts): void
    {
        $params = ['provider' => $provider, 'window_from' => $windowFrom, 'window_to' => $windowTo];
        $notIn = '';
        if ($seenKeys) {
            $placeholders = [];
            foreach ($seenKeys as $i => $key) {
                $name = 'seen_' . $i;
                $placeholders[] = ':' . $name;
                $params[$name] = $key;
            }
            $notIn = ' AND s.source_key NOT IN (' . implode(',', $placeholders) . ')';
        }
        $stmt = $this->pdo->prepare(
            "SELECT s.*, f.scheduled_arrival FROM flight_source_state s
             INNER JOIN flights f ON f.id=s.flight_id
             WHERE s.provider=:provider AND f.scheduled_arrival BETWEEN :window_from AND :window_to
             AND s.visibility_status IN ('active','missing')" . $notIn . ' FOR UPDATE'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $state) {
            $misses = (int)$state['consecutive_misses'] + 1;
            $newStatus = $misses >= $withdrawAfter ? 'withdrawn' : 'missing';
            $update = $this->pdo->prepare(
                'UPDATE flight_source_state SET consecutive_misses=:misses, visibility_status=:status,
                 withdrawn_at=:withdrawn_at WHERE id=:id'
            );
            $update->execute([
                'misses' => $misses,
                'status' => $newStatus,
                'withdrawn_at' => $newStatus === 'withdrawn' ? $observedAt : null,
                'id' => $state['id'],
            ]);
            $counts['flights_missing']++;
            if ($misses === 1) {
                $this->addEvent((int)$state['flight_id'], $runId, $provider, 'flight_missing', 'visibility_status',
                    'active', 'missing', 'source_missing',
                    'El vuelo no apareció en una ventana completa; todavía no se considera retirado.',
                    'provisional', ['source_key' => $state['source_key'], 'misses' => $misses], $observedAt, $counts);
            }
            if ($newStatus === 'withdrawn') {
                $counts['flights_withdrawn']++;
                $this->addEvent((int)$state['flight_id'], $runId, $provider, 'flight_withdrawn', 'visibility_status',
                    $state['visibility_status'], 'withdrawn', 'source_withdrawal',
                    'El vuelo faltó en ' . $misses . ' ventanas completas consecutivas. No se atribuye una causa operativa.',
                    'probable', ['source_key' => $state['source_key'], 'misses' => $misses], $observedAt, $counts);
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function insertFlight(array $record): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO flights (flight_date, physical_flight, origin_iata, origin_name, destination_iata,
             scheduled_arrival, scheduled_departure, aircraft_registration, aircraft_icao24, aircraft_type,
             capacity, traffic_class, border_control, is_canary)
             VALUES (:flight_date,:physical_flight,:origin_iata,:origin_name,:destination_iata,
             :scheduled_arrival,:scheduled_departure,:aircraft_registration,:aircraft_icao24,:aircraft_type,
             :capacity,:traffic_class,:border_control,:is_canary)'
        );
        $stmt->execute(array_intersect_key($record, array_flip([
            'flight_date', 'physical_flight', 'origin_iata', 'origin_name', 'destination_iata',
            'scheduled_arrival', 'scheduled_departure', 'aircraft_registration', 'aircraft_icao24',
            'aircraft_type', 'capacity', 'traffic_class', 'border_control', 'is_canary',
        ])));
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $record */
    private function matchFlight(array $record): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM flights WHERE flight_date=:flight_date AND physical_flight=:physical_flight
             AND origin_iata=:origin_iata ORDER BY ABS(TIMESTAMPDIFF(SECOND, scheduled_arrival, :scheduled_arrival)) LIMIT 1'
        );
        $stmt->execute([
            'flight_date' => $record['flight_date'],
            'physical_flight' => $record['physical_flight'],
            'origin_iata' => $record['origin_iata'],
            'scheduled_arrival' => $record['scheduled_arrival'],
        ]);
        return $stmt->fetch() ?: null;
    }

    private function flightById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flights WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function sourceState(string $provider, string $sourceKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flight_source_state WHERE provider=? AND source_key=? FOR UPDATE');
        $stmt->execute([$provider, $sourceKey]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<int,string> $codeshares */
    private function upsertCodes(int $flightId, string $physical, array $codeshares): void
    {
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO flight_codes (flight_id, flight_code) VALUES (?, ?)');
        $stmt->execute([$flightId, $physical]);
        foreach ($codeshares as $code) {
            $stmt->execute([$flightId, $code]);
        }
    }

    /** @param array<string,mixed> $record */
    private function addObservation(int $flightId, string $provider, array $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO observations (flight_id,source,observed_at,status,eta,actual_departure,actual_arrival,
             hall,belt,gate,stand,baggage_state,occupancy_level,raw_data)
             VALUES (:flight_id,:source,:observed_at,:status,:eta,:actual_departure,:actual_arrival,
             :hall,:belt,:gate,:stand,:baggage_state,:occupancy_level,:raw_data)'
        );
        $stmt->execute([
            'flight_id' => $flightId,
            'source' => $provider,
            'observed_at' => $record['observed_at'],
            'status' => $record['status'],
            'eta' => $record['eta'],
            'actual_departure' => $record['actual_departure'],
            'actual_arrival' => $record['actual_arrival'],
            'hall' => $record['hall'],
            'belt' => $record['belt'],
            'gate' => $record['gate'],
            'stand' => $record['stand'],
            'baggage_state' => $record['baggage_state'],
            'occupancy_level' => $record['occupancy_level'],
            'raw_data' => $this->canonicalJson($record['raw_data']),
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    private function saveSourceState(int $flightId, string $provider, string $sourceKey, string $observedAt, string $hash, array $snapshot, string $visibility): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO flight_source_state
             (flight_id,provider,source_key,first_seen_at,last_seen_at,last_payload_hash,snapshot,consecutive_misses,visibility_status,withdrawn_at)
             VALUES (:flight_id,:provider,:source_key,:observed_at,:observed_at,:hash,:snapshot,0,:visibility,NULL)
             ON DUPLICATE KEY UPDATE flight_id=VALUES(flight_id),last_seen_at=VALUES(last_seen_at),
             last_payload_hash=VALUES(last_payload_hash),snapshot=VALUES(snapshot),consecutive_misses=0,
             visibility_status=VALUES(visibility_status),withdrawn_at=NULL'
        );
        $stmt->execute([
            'flight_id' => $flightId,
            'provider' => $provider,
            'source_key' => $sourceKey,
            'observed_at' => $observedAt,
            'hash' => $hash,
            'snapshot' => $this->canonicalJson($snapshot),
            'visibility' => $visibility,
        ]);
    }

    /** @param array<string,mixed> $evidence @param array<string,int> $counts */
    private function addEvent(int $flightId, int $runId, string $source, string $type, ?string $field,
        mixed $before, mixed $after, string $reasonCode, string $reasonDetail, string $confidence,
        array $evidence, string $detectedAt, array &$counts): void
    {
        $beforeValue = $this->scalar($before);
        $afterValue = $this->scalar($after);
        $fingerprint = hash('sha256', implode('|', [
            (string)$flightId, (string)$runId, $source, $type, (string)$field,
            (string)$beforeValue, (string)$afterValue,
        ]));
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO flight_events
             (flight_id,sync_run_id,source,event_type,field_name,before_value,after_value,reason_code,
             reason_detail,confidence,evidence,detected_at,event_fingerprint)
             VALUES (:flight_id,:run_id,:source,:event_type,:field_name,:before_value,:after_value,:reason_code,
             :reason_detail,:confidence,:evidence,:detected_at,:fingerprint)'
        );
        $stmt->execute([
            'flight_id' => $flightId,
            'run_id' => $runId,
            'source' => $source,
            'event_type' => $type,
            'field_name' => $field,
            'before_value' => $beforeValue,
            'after_value' => $afterValue,
            'reason_code' => $this->truncate($reasonCode, 50),
            'reason_detail' => $this->truncate($reasonDetail, 500),
            'confidence' => in_array($confidence, self::CONFIDENCE, true) ? $confidence : 'provisional',
            'evidence' => $this->canonicalJson($evidence),
            'detected_at' => $detectedAt,
            'fingerprint' => $fingerprint,
        ]);
        if ($stmt->rowCount() > 0) {
            $counts['events_created']++;
        }
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function snapshot(array $record): array
    {
        return array_intersect_key($record, array_flip(array_merge(self::CORE_FIELDS, self::OPERATIONAL_FIELDS)));
    }

    /** @param array<string,mixed> $context */
    private function startRun(string $provider, string $mode, ?string $from, ?string $to, string $startedAt, int $count, array $context): int
    {
        $metadata = $context['metadata'] ?? [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_runs (provider,mode,window_from,window_to,started_at,status,records_received,metadata)
             VALUES (:provider,:mode,:window_from,:window_to,:started_at,\'running\',:records_received,:metadata)'
        );
        $stmt->execute([
            'provider' => $provider,
            'mode' => $mode,
            'window_from' => $from,
            'window_to' => $to,
            'started_at' => $startedAt,
            'records_received' => $count,
            'metadata' => $this->canonicalJson($metadata),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,int> $counts */
    private function finishRun(int $runId, string $status, array $counts, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sync_runs SET finished_at=NOW(),status=:status,records_received=:records_received,
             flights_created=:flights_created,flights_updated=:flights_updated,
             observations_created=:observations_created,events_created=:events_created,
             flights_missing=:flights_missing,flights_withdrawn=:flights_withdrawn,error_message=:error
             WHERE id=:id'
        );
        $stmt->execute($counts + ['status' => $status, 'error' => $error, 'id' => $runId]);
    }

    private function acquireLock(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseLock(string $name): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$name]);
        } catch (Throwable) {
        }
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = array_map(fn(mixed $item): mixed => is_array($item) ? json_decode($this->canonicalJson($item), true) : $item, $value);
            } else {
                ksort($value);
                foreach ($value as $key => $item) {
                    if (is_array($item)) $value[$key] = json_decode($this->canonicalJson($item), true);
                }
            }
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) throw new RuntimeException('No se pudo serializar el dato de sincronización.');
        return $json;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_scalar($value)) return (string)$value;
        return $this->canonicalJson($value);
    }

    private function date(string $value, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException($field . ' no es una fecha válida.');
        return $value;
    }

    private function dateTime(mixed $value, string $field): string
    {
        $normalized = $this->nullableDateTime($value, $field);
        if ($normalized === null) throw new InvalidArgumentException($field . ' es obligatorio.');
        return $normalized;
    }

    private function nullableDateTime(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string)$value) === '') return null;
        try {
            return (new DateTimeImmutable((string)$value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new InvalidArgumentException($field . ' no contiene una fecha/hora válida.');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableUpper(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        return $value === null ? null : strtoupper($value);
    }

    private function nullableLower(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        return $value === null ? null : strtolower($value);
    }

    private function nullableEnum(mixed $value, array $allowed): ?string
    {
        $value = $this->nullableString($value);
        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }

    private function safeToken(mixed $value): ?string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return $value !== '' && preg_match('/^[a-z0-9_-]{2,50}$/', $value) ? $value : null;
    }

    private function isCancelled(?string $status): bool
    {
        return $status !== null && preg_match('/cancel|cancelado|cancelled/i', $status) === 1;
    }

    private function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
