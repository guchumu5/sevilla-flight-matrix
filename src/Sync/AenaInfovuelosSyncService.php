<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Notifications\WebPushService;
use SevillaMatrix\Operations\WebOperationsService;
use Throwable;

final class AenaInfovuelosSyncService
{
    private const AUTHORITATIVE_OPERATORS = ['VLG', 'IBS', 'ANE'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function syncLive(string $by = 'cron'): array
    {
        $started = microtime(true);
        $client = new AenaInfovuelosClient();
        $rows = $client->fetchArrivals('SVQ');
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
        $observedAt = $now->format('Y-m-d H:i:s');
        $windowFrom = $now->modify('-2 hours')->format('Y-m-d H:i:s');
        $windowTo = $now->modify('+2 days')->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        $flights = $this->groupPhysicalFlights($rows, $windowFrom, $observedAt);
        if ($flights === []) {
            throw new RuntimeException('Infovuelos JSON no produjo vuelos físicos en la ventana viva.');
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO fetch_runs (provider,started_at,ok,records_count) VALUES ('aena',NOW(),0,0)"
        );
        $insert->execute();
        $fetchRunId = (int)$this->pdo->lastInsertId();

        try {
            $result = (new FlightReconciliationService($this->pdo))->sync($flights, [
                'provider' => 'aena',
                'mode' => 'full_window',
                'observed_at' => $observedAt,
                'window_from' => $windowFrom,
                'window_to' => $windowTo,
                'complete' => true,
                'withdraw_after' => 3,
                'metadata' => [
                    'transport' => 'infovuelos_json',
                    'collector' => 'aena-json-php-v1',
                    'requested_by' => $by,
                    'raw_rows' => count($rows),
                ],
            ]);

            $this->pdo->prepare(
                'UPDATE fetch_runs SET finished_at=NOW(),ok=1,records_count=:count WHERE id=:id'
            )->execute(['count' => count($flights), 'id' => $fetchRunId]);
            WebOperationsService::clearAenaForce();
            FlightRepository::forgetSnapshotCaches();

            $push = null;
            try {
                $push = (new WebPushService($this->pdo))->dispatch(50);
            } catch (Throwable $error) {
                $push = ['error' => substr($error->getMessage(), 0, 300)];
            }

            return [
                'ok' => true,
                'useful' => true,
                'action' => 'aena',
                'transport' => 'infovuelos_json',
                'fetch_run_id' => $fetchRunId,
                'raw_rows' => count($rows),
                'physical_flights' => count($flights),
                'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
                'result' => $result,
                'push' => $push,
                'message' => sprintf(
                    'Aena reconfirmado por JSON Infovuelos: %d vuelos · cinta/sala releídas.',
                    count($flights)
                ),
            ];
        } catch (Throwable $error) {
            $this->pdo->prepare(
                'UPDATE fetch_runs SET finished_at=NOW(),ok=0,error_message=:error WHERE id=:id'
            )->execute(['error' => substr($error->getMessage(), 0, 1000), 'id' => $fetchRunId]);
            throw $error;
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function groupPhysicalFlights(array $rows, string $windowFrom, string $observedAt): array
    {
        $minimum = substr($windowFrom, 0, 16);
        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $day = AenaInfovuelosClient::spanishDateToIso(AenaInfovuelosClient::clean($row['fecha'] ?? null));
            $scheduledClock = AenaInfovuelosClient::clock(AenaInfovuelosClient::clean($row['horaProgramada'] ?? null));
            $estimatedDay = AenaInfovuelosClient::spanishDateToIso(AenaInfovuelosClient::clean($row['fechaEstimada'] ?? null)) ?: $day;
            $estimatedClock = AenaInfovuelosClient::clock(AenaInfovuelosClient::clean($row['horaEstimada'] ?? null)) ?: $scheduledClock;
            $origin = strtoupper((string)(AenaInfovuelosClient::clean($row['iataOtro'] ?? null) ?? ''));
            if ($day === null || $scheduledClock === null || $origin === '') continue;

            $scheduledArrival = $day . ' ' . $scheduledClock . ':00';
            $effectiveArrival = ($estimatedDay ?: $day) . ' ' . $estimatedClock . ':00';
            if (substr($scheduledArrival, 0, 16) < $minimum && substr($effectiveArrival, 0, 16) < $minimum) {
                continue;
            }

            $codes = AenaInfovuelosClient::codesFromRow($row);
            $physical = $this->choosePhysicalCode($codes);
            if ($physical === null) continue;

            $statusCode = strtoupper((string)(AenaInfovuelosClient::clean($row['estado'] ?? null) ?? ''));
            $status = AenaInfovuelosClient::statusLabel($statusCode);
            $hall = AenaInfovuelosClient::clean($row['salaPrimera'] ?? null);
            $belt = AenaInfovuelosClient::clean($row['cintaPrimera'] ?? null);
            $signature = implode('|', [
                $day, $scheduledClock, $estimatedClock, $origin,
                strtoupper((string)$hall), (string)$belt, $statusCode,
            ]);
            if (!isset($groups[$signature])) {
                $groups[$signature] = [
                    'day' => $day,
                    'scheduled_arrival' => $scheduledArrival,
                    'effective_arrival' => $effectiveArrival,
                    'origin_iata' => $origin,
                    'origin_name' => AenaInfovuelosClient::clean($row['ciudadIataOtro'] ?? null) ?: $origin,
                    'hall' => $hall !== null ? strtoupper($hall) : null,
                    'belt' => $belt,
                    'status_code' => $statusCode,
                    'status' => $status,
                    'codes' => [],
                    'raw' => $row,
                ];
            }
            foreach ($codes as $code) {
                if (!in_array($code, $groups[$signature]['codes'], true)) {
                    $groups[$signature]['codes'][] = $code;
                }
            }
        }

        $flights = [];
        foreach ($groups as $group) {
            $physical = $this->choosePhysicalCode($group['codes']);
            if ($physical === null) continue;
            $codeshares = array_values(array_filter(
                $group['codes'],
                static fn(string $code): bool => $code !== $physical
            ));
            $status = $group['status'];
            $arrived = $this->statusIndicatesArrival($status, $group['status_code']);
            $baggageState = $this->baggageStateFromStatus($status, $group['status_code']);
            $scheduledArrival = $group['scheduled_arrival'];
            $effectiveArrival = $group['effective_arrival'];
            $flights[] = [
                'source_key' => implode('|', [$group['day'], $physical, $group['origin_iata'], 'SVQ']),
                'flight_date' => $group['day'],
                'physical_flight' => $physical,
                'origin_iata' => $group['origin_iata'],
                'origin_name' => $group['origin_name'],
                'scheduled_arrival' => $scheduledArrival,
                'codeshares' => $codeshares,
                'status' => $status,
                'eta' => !$arrived && $effectiveArrival !== $scheduledArrival ? $effectiveArrival : null,
                'actual_arrival' => $arrived ? $effectiveArrival : null,
                'hall' => $group['hall'],
                'belt' => $group['belt'],
                'baggage_state' => $baggageState,
                'occupancy_level' => 'no_verificable',
                'confidence' => 'confirmado',
                'reason_code' => 'aena_infovuelos_json',
                'reason_detail' => 'Reconfirmación Infovuelos JSON; Aena publica cinta/sala oficiales.',
                'observed_at' => $observedAt,
                'raw_data' => [
                    'transport' => 'infovuelos_json',
                    'status_code' => $group['status_code'],
                    'codes' => $group['codes'],
                    'cintaPrimera' => $group['belt'],
                    'salaPrimera' => $group['hall'],
                    'displayed_effective_time' => substr($effectiveArrival, 11, 5),
                ],
            ];
        }

        $keyCounts = [];
        foreach ($flights as $flight) {
            $key = $flight['source_key'];
            $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
        }
        foreach ($flights as &$flight) {
            if (($keyCounts[$flight['source_key']] ?? 0) < 2) continue;
            $scheduledTime = substr((string)$flight['scheduled_arrival'], 11, 5);
            $flight['source_key'] .= '|' . str_replace(':', '', $scheduledTime);
            $flight['raw_data']['source_key_disambiguated_by'] = 'scheduled_time';
        }
        unset($flight);

        usort(
            $flights,
            static fn(array $a, array $b): int => strcmp((string)$a['scheduled_arrival'], (string)$b['scheduled_arrival'])
        );
        return $flights;
    }

    /** @param list<string> $codes */
    private function choosePhysicalCode(array $codes): ?string
    {
        $normalized = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim((string)preg_replace('/\s+/', '', $code) ?? ''));
            if ($code !== '' && !in_array($code, $normalized, true)) {
                $normalized[] = $code;
            }
        }
        if ($normalized === []) return null;
        $hasIbe = false;
        foreach ($normalized as $code) {
            if (str_starts_with($code, 'IBE')) {
                $hasIbe = true;
                break;
            }
        }
        if ($hasIbe) {
            foreach (self::AUTHORITATIVE_OPERATORS as $prefix) {
                foreach ($normalized as $code) {
                    if (str_starts_with($code, $prefix)) return $code;
                }
            }
        }
        // Preferir ICAO de 3 letras (VLG3190) frente a IATA (VY3190) cuando ambos existan.
        foreach ($normalized as $code) {
            if (preg_match('/^[A-Z]{3}\d/', $code) === 1) return $code;
        }
        return $normalized[0];
    }

    private function statusIndicatesArrival(?string $status, string $code): bool
    {
        $code = strtoupper($code);
        if (in_array($code, ['LND', 'ATE', 'OPE', 'OPF', 'IBK', 'BOR'], true)) return true;
        return preg_match('/finaliz|aterriz|llegad|entrega|recogida/i', (string)$status) === 1;
    }

    private function baggageStateFromStatus(?string $status, string $code): string
    {
        $code = strtoupper($code);
        if (in_array($code, ['OPE', 'OPF', 'IBK'], true)) return 'entrega';
        if ($code === 'BOR' || preg_match('/finaliz/i', (string)$status) === 1) return 'finalizado';
        if (preg_match('/(?:entrega|recogida)(?:\s+de)?\s+(?:equip|malet)/i', (string)$status) === 1) {
            return 'entrega';
        }
        return 'pendiente';
    }
}
