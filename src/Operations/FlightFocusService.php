<?php
declare(strict_types=1);

namespace SevillaMatrix\Operations;

use PDO;
use RuntimeException;
use SevillaMatrix\Api\AirLabsClient;
use SevillaMatrix\Api\OpenSkyClient;
use SevillaMatrix\Env;
use Throwable;

final class FlightFocusService
{
    private const PER_FLIGHT_COOLDOWN_SECONDS = 180;
    private const GLOBAL_COOLDOWN_SECONDS = 90;
    private const AENA_PRIORITY_MINUTES = 30;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function status(int $flightId): array
    {
        if (!$this->tableExists()) {
            return [
                'ready' => false,
                'migration' => '20261019_010_flight_focus',
                'active' => false,
                'retry_after_seconds' => 0,
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT requested_at,
                    expires_at,
                    last_opensky_at,
                    request_count,
                    last_result,
                    status,
                    CASE
                        WHEN status=\'active\' AND expires_at>=NOW() THEN 1
                        ELSE 0
                    END AS active_now,
                    CASE
                        WHEN last_opensky_at IS NULL THEN 0
                        ELSE GREATEST(0,' . self::PER_FLIGHT_COOLDOWN_SECONDS . ' - TIMESTAMPDIFF(SECOND,last_opensky_at,NOW()))
                    END AS retry_after_seconds
             FROM flight_focus_requests WHERE flight_id=:flight_id'
        );
        $stmt->execute(['flight_id' => $flightId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return [
                'ready' => true,
                'active' => false,
                'retry_after_seconds' => 0,
                'policy' => $this->policy(),
            ];
        }

        return [
            'ready' => true,
            'active' => (int)($row['active_now'] ?? 0) === 1,
            'requested_at' => $row['requested_at'],
            'expires_at' => $row['expires_at'],
            'last_opensky_at' => $row['last_opensky_at'],
            'request_count' => (int)$row['request_count'],
            'last_result' => $row['last_result'],
            'retry_after_seconds' => (int)($row['retry_after_seconds'] ?? 0),
            'policy' => $this->policy(),
        ];
    }

    /** @return array<string,mixed> */
    public function refresh(int $flightId): array
    {
        if (!$this->tableExists()) {
            throw new RuntimeException('Aplica la actualización MySQL 20261019_010_flight_focus.');
        }

        $flightStmt = $this->pdo->prepare(
            'SELECT id,flight_date,physical_flight,aircraft_registration,aircraft_icao24,aircraft_type
             FROM flights WHERE id=:id
             AND scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 1 DAY) AND DATE_ADD(NOW(),INTERVAL 2 DAY)'
        );
        $flightStmt->execute(['id' => $flightId]);
        $flight = $flightStmt->fetch();
        if (!is_array($flight)) {
            throw new RuntimeException('El vuelo no está dentro de la ventana de seguimiento.');
        }

        $this->pdo->prepare(
            "INSERT INTO flight_focus_requests
             (flight_id,requested_at,expires_at,request_count,status,last_result)
             VALUES (:flight_id,NOW(),DATE_ADD(NOW(),INTERVAL " . self::AENA_PRIORITY_MINUTES . " MINUTE),1,'active','Solicitud recibida')
             ON DUPLICATE KEY UPDATE requested_at=NOW(),
               expires_at=DATE_ADD(NOW(),INTERVAL " . self::AENA_PRIORITY_MINUTES . " MINUTE),
               request_count=request_count+1,status='active',last_result='Solicitud recibida'"
        )->execute(['flight_id' => $flightId]);

        $aircraft = $this->resolveAircraft($flight);
        if (!empty($aircraft['updated'])) {
            $flight['aircraft_registration'] = $aircraft['aircraft_registration'] ?? $flight['aircraft_registration'];
            $flight['aircraft_icao24'] = $aircraft['aircraft_icao24'] ?? $flight['aircraft_icao24'];
            $flight['aircraft_type'] = $aircraft['aircraft_type'] ?? $flight['aircraft_type'];
        }
        $flightAware = $this->queueFlightAwareLookup($flight);
        $openSky = $this->refreshOpenSky($flight);
        $message = trim(
            (string)($aircraft['message'] ?? '') . ' ' .
            (string)($flightAware['message'] ?? '') . ' ' .
            (string)($openSky['message'] ?? 'Seguimiento focalizado activado.')
        );
        $this->pdo->prepare(
            'UPDATE flight_focus_requests SET last_result=:last_result WHERE flight_id=:flight_id'
        )->execute([
            'last_result' => substr($message, 0, 500),
            'flight_id' => $flightId,
        ]);

        return [
            'ok' => true,
            'flight_id' => $flightId,
            'flight' => (string)$flight['physical_flight'],
            'flight_date' => (string)$flight['flight_date'],
            'aena_priority_minutes' => self::AENA_PRIORITY_MINUTES,
            'aena_message' => 'Aena queda marcado para seguimiento: cada 5 min hasta finalizar el equipaje.',
            'aircraft' => $aircraft,
            'flightaware' => $flightAware,
            'opensky' => $openSky,
            'focus' => $this->status($flightId),
        ];
    }

    /** @param array<string,mixed> $flight @return array<string,mixed> */
    private function resolveAircraft(array $flight): array
    {
        $icao24 = strtolower(trim((string)($flight['aircraft_icao24'] ?? '')));
        if (preg_match('/^[0-9a-f]{6}$/', $icao24)) {
            return [
                'state' => 'known',
                'updated' => false,
                'aircraft_registration' => $flight['aircraft_registration'] ?? null,
                'aircraft_icao24' => $icao24,
                'aircraft_type' => $flight['aircraft_type'] ?? null,
                'message' => 'Aeronave ya identificada; se consulta directamente su señal ADS-B.',
            ];
        }

        $registration = strtoupper(trim((string)($flight['aircraft_registration'] ?? '')));
        if (preg_match('/^[A-Z0-9-]{3,15}$/', $registration)) {
            $known = $this->pdo->prepare(
                "SELECT aircraft_icao24 FROM flights
                 WHERE id<>:id AND aircraft_registration=:registration
                   AND aircraft_icao24 REGEXP '^[0-9a-fA-F]{6}$'
                 ORDER BY updated_at DESC,id DESC LIMIT 1"
            );
            $known->execute(['id' => (int)$flight['id'], 'registration' => $registration]);
            $knownIcao24 = strtolower(trim((string)($known->fetchColumn() ?: '')));
            if (preg_match('/^[0-9a-f]{6}$/', $knownIcao24)) {
                $this->pdo->prepare('UPDATE flights SET aircraft_icao24=:icao24 WHERE id=:id')
                    ->execute(['icao24' => $knownIcao24, 'id' => (int)$flight['id']]);
                return [
                    'state' => 'resolved_from_history',
                    'updated' => true,
                    'aircraft_registration' => $registration,
                    'aircraft_icao24' => $knownIcao24,
                    'aircraft_type' => $flight['aircraft_type'] ?? null,
                    'message' => 'La matrícula ya estaba publicada y el ICAO24 se recuperó del histórico de esa misma aeronave.',
                ];
            }
        }

        $keys = $this->airLabsKeys();
        if ($keys === []) {
            return [
                'state' => 'unconfigured',
                'updated' => false,
                'message' => 'Falta ICAO24 y AirLabs no está configurado para buscar la matrícula.',
            ];
        }

        $run = $this->pdo->prepare(
            "INSERT INTO fetch_runs (provider,started_at,ok,records_count) VALUES ('airlabs_focus',NOW(),0,0)"
        );
        $run->execute();
        $runId = (int)$this->pdo->lastInsertId();
        try {
            $client = new AirLabsClient($keys, PROJECT_ROOT . '/storage/cache/airlabs-key-slot.txt');
            $data = $client->flight((string)$flight['physical_flight']);
            if (!is_array($data)) {
                $this->finishAircraftRun($runId, true, 0, 'AirLabs no publicó una aeronave para este vuelo.');
                $this->markFocusedLookup((int)$flight['id']);
                return ['state' => 'not_found', 'updated' => false, 'message' => 'AirLabs no publicó todavía la matrícula de este vuelo.'];
            }

            $registration = strtoupper(trim((string)($data['reg_number'] ?? '')));
            $resolvedIcao24 = strtolower(trim((string)($data['hex'] ?? '')));
            $type = strtoupper(trim((string)($data['aircraft_icao'] ?? $data['aircraft_model'] ?? '')));
            if (!preg_match('/^[0-9a-f]{6}$/', $resolvedIcao24)) $resolvedIcao24 = '';
            if ($registration !== '' && !preg_match('/^[A-Z0-9-]{3,15}$/', $registration)) $registration = '';
            if ($type !== '') $type = substr($type, 0, 50);
            if ($registration === '' && $resolvedIcao24 === '' && $type === '') {
                $this->finishAircraftRun($runId, true, 0, 'AirLabs respondió sin matrícula ni ICAO24.');
                $this->markFocusedLookup((int)$flight['id']);
                return ['state' => 'not_found', 'updated' => false, 'message' => 'AirLabs respondió, pero aún no identifica la aeronave.'];
            }

            $update = $this->pdo->prepare(
                'UPDATE flights SET
                   aircraft_registration=COALESCE(NULLIF(:registration,\'\'),aircraft_registration),
                   aircraft_icao24=COALESCE(NULLIF(:icao24,\'\'),aircraft_icao24),
                   aircraft_type=COALESCE(NULLIF(:aircraft_type,\'\'),aircraft_type)
                 WHERE id=:id'
            );
            $update->execute([
                'registration' => $registration,
                'icao24' => $resolvedIcao24,
                'aircraft_type' => $type,
                'id' => (int)$flight['id'],
            ]);
            $this->finishAircraftRun($runId, true, 1, null);
            if ($resolvedIcao24 === '') $this->markFocusedLookup((int)$flight['id']);
            return [
                'state' => $resolvedIcao24 !== '' ? 'resolved' : 'partial',
                'updated' => true,
                'aircraft_registration' => $registration !== '' ? $registration : null,
                'aircraft_icao24' => $resolvedIcao24 !== '' ? $resolvedIcao24 : null,
                'aircraft_type' => $type !== '' ? $type : null,
                'message' => $resolvedIcao24 !== ''
                    ? 'AirLabs identificó la aeronave; a continuación se consulta su telemetría ADS-B.'
                    : 'AirLabs aportó datos parciales de la aeronave, pero todavía falta el ICAO24 necesario para ADS-B.',
            ];
        } catch (Throwable $error) {
            $message = substr($error->getMessage(), 0, 500);
            $this->finishAircraftRun($runId, false, 0, $message);
            $this->markFocusedLookup((int)$flight['id']);
            return ['state' => 'error', 'updated' => false, 'message' => 'No se pudo identificar la aeronave: ' . $message];
        }
    }

    /** @param array<string,mixed> $flight @return array<string,mixed> */
    private function queueFlightAwareLookup(array $flight): array
    {
        $icao24 = strtolower(trim((string)($flight['aircraft_icao24'] ?? '')));
        if (preg_match('/^[0-9a-f]{6}$/', $icao24)) {
            return [
                'state' => 'not_needed',
                'queued' => false,
                'message' => 'FlightAware no necesita otra búsqueda de matrícula: el ICAO24 ya está verificado.',
            ];
        }

        try {
            $tables = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name IN ('flightaware_snapshots','flightaware_requests')"
            )->fetchColumn();
            if ($tables < 2) {
                return [
                    'state' => 'unconfigured',
                    'queued' => false,
                    'message' => 'FlightAware no está preparado: faltan las actualizaciones MySQL 007 y 008.',
                ];
            }

            $previous = $this->pdo->prepare('SELECT requested_at,status FROM flightaware_requests WHERE flight_id=:id');
            $previous->execute(['id' => (int)$flight['id']]);
            $before = $previous->fetch();
            $recent = is_array($before) && strtotime((string)$before['requested_at']) >= time() - 300;
            if (!$recent) {
                $this->pdo->prepare(
                    "INSERT INTO flightaware_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
                     VALUES (:id,NOW(),NULL,1,'queued',NULL)
                     ON DUPLICATE KEY UPDATE requested_at=NOW(),processed_at=NULL,
                       request_count=request_count+1,status='queued',last_error=NULL"
                )->execute(['id' => (int)$flight['id']]);
            }

            return [
                'state' => $recent ? 'cooldown' : 'queued',
                'queued' => !$recent,
                'message' => $recent
                    ? 'FlightAware ya fue consultado o solicitado en los últimos 5 minutos; se respeta su intervalo.'
                    : 'FlightAware queda solicitado automáticamente; el recolector comprobará su ficha pública en el siguiente ciclo.',
            ];
        } catch (Throwable $error) {
            return [
                'state' => 'error',
                'queued' => false,
                'message' => 'No se pudo solicitar FlightAware: ' . substr($error->getMessage(), 0, 300),
            ];
        }
    }

    private function markFocusedLookup(int $flightId): void
    {
        $this->pdo->prepare('UPDATE flight_focus_requests SET last_opensky_at=NOW() WHERE flight_id=:flight_id')
            ->execute(['flight_id' => $flightId]);
    }

    private function finishAircraftRun(int $runId, bool $ok, int $records, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fetch_runs SET finished_at=NOW(),ok=:ok,records_count=:records,error_message=:error WHERE id=:id'
        );
        $stmt->execute(['ok' => $ok ? 1 : 0, 'records' => $records, 'error' => $error, 'id' => $runId]);
    }

    /** @param array<string,mixed> $flight @return array<string,mixed> */
    private function refreshOpenSky(array $flight): array
    {
        $icao24 = strtolower(trim((string)($flight['aircraft_icao24'] ?? '')));
        if (!preg_match('/^[0-9a-f]{6}$/', $icao24)) {
            return [
                'state' => 'unavailable',
                'updated' => false,
                'message' => 'Sin ICAO24 verificado: no se consume una consulta OpenSky.',
            ];
        }

        $clientId = trim((string)Env::get('OPENSKY_CLIENT_ID', ''));
        $secret = trim((string)Env::get('OPENSKY_CLIENT_SECRET', ''));
        if ($clientId === '' || $secret === '') {
            return [
                'state' => 'unconfigured',
                'updated' => false,
                'message' => 'OpenSky no está configurado; Aena y FlightAware continúan disponibles.',
            ];
        }

        $focusStmt = $this->pdo->prepare(
            'SELECT CASE
                        WHEN last_opensky_at IS NULL THEN 0
                        ELSE GREATEST(0,' . self::PER_FLIGHT_COOLDOWN_SECONDS . ' - TIMESTAMPDIFF(SECOND,last_opensky_at,NOW()))
                    END AS retry_after_seconds
             FROM flight_focus_requests WHERE flight_id=:flight_id'
        );
        $focusStmt->execute(['flight_id' => (int)$flight['id']]);
        $flightRemaining = (int)($focusStmt->fetchColumn() ?: 0);

        $lastGlobal = $this->pdo->query(
            "SELECT COALESCE(
                        GREATEST(0," . self::GLOBAL_COOLDOWN_SECONDS . " - TIMESTAMPDIFF(SECOND,MAX(started_at),NOW())),
                        0
                    )
             FROM fetch_runs WHERE provider IN ('opensky','opensky_focus')"
        )->fetchColumn();
        $globalRemaining = (int)($lastGlobal ?: 0);
        $remaining = max($flightRemaining, $globalRemaining);
        if ($remaining > 0) {
            return [
                'state' => 'cooldown',
                'updated' => false,
                'retry_after_seconds' => $remaining,
                'message' => "Se conserva la última telemetría; nueva consulta ADS-B disponible en {$remaining} s.",
            ];
        }

        $lockName = 'sevilla_matrix_web_opensky';
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
        $lock->execute(['lock_name' => $lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return [
                'state' => 'busy',
                'updated' => false,
                'retry_after_seconds' => 30,
                'message' => 'OpenSky ya está realizando otra consulta agrupada; inténtalo en 30 s.',
            ];
        }

        $run = $this->pdo->prepare(
            "INSERT INTO fetch_runs (provider,started_at,ok,records_count) VALUES ('opensky_focus',NOW(),0,0)"
        );
        $run->execute();
        $runId = (int)$this->pdo->lastInsertId();

        try {
            $client = new OpenSkyClient($clientId, $secret, PROJECT_ROOT . '/storage/cache/opensky-token.json');
            $batch = $client->states([$icao24]);
            $state = $batch['states'][$icao24] ?? null;
            $updated = false;
            if (is_array($state) && $state['latitude'] !== null && $state['longitude'] !== null) {
                $insert = $this->pdo->prepare(
                    "INSERT INTO observations
                     (flight_id,source,observed_at,status,latitude,longitude,altitude_m,ground_speed_ms,track_deg,vertical_rate_ms,occupancy_level,raw_data)
                     VALUES (:flight_id,'opensky',NOW(),:status,:latitude,:longitude,:altitude,:speed,:track,:vertical_rate,'no_verificable',:raw_data)"
                );
                $insert->execute([
                    'flight_id' => (int)$flight['id'],
                    'status' => !empty($state['on_ground']) ? 'En tierra' : 'En vuelo',
                    'latitude' => $state['latitude'],
                    'longitude' => $state['longitude'],
                    'altitude' => $state['altitude_m'],
                    'speed' => $state['ground_speed_ms'],
                    'track' => $state['track_deg'],
                    'vertical_rate' => $state['vertical_rate_ms'],
                    'raw_data' => json_encode($state['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $updated = true;
            }
            $this->pdo->prepare(
                'UPDATE flight_focus_requests SET last_opensky_at=NOW() WHERE flight_id=:flight_id'
            )->execute(['flight_id' => (int)$flight['id']]);
            $this->pdo->prepare(
                'UPDATE fetch_runs SET finished_at=NOW(),ok=1,records_count=:count WHERE id=:id'
            )->execute(['count' => $updated ? 1 : 0, 'id' => $runId]);
            return [
                'state' => $updated ? 'updated' : 'no_signal',
                'updated' => $updated,
                'retry_after_seconds' => self::PER_FLIGHT_COOLDOWN_SECONDS,
                'rate_limit' => $batch['rate_limit'],
                'message' => $updated
                    ? 'Posición, altura, velocidad y tendencia vertical actualizadas mediante una consulta ADS-B dirigida.'
                    : 'OpenSky respondió sin una posición actual para esta matrícula.',
            ];
        } catch (Throwable $error) {
            $message = substr($error->getMessage(), 0, 500);
            $paused = preg_match('/(?:HTTP 429|en pausa|reintentar)/i', $message) === 1;
            $this->pdo->prepare(
                'UPDATE fetch_runs SET finished_at=NOW(),ok=:ok,records_count=0,error_message=:error WHERE id=:id'
            )->execute(['ok' => $paused ? 1 : 0, 'error' => $message, 'id' => $runId]);
            return [
                'state' => $paused ? 'quota_pause' : 'error',
                'updated' => false,
                'message' => $message,
            ];
        } finally {
            try {
                $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute(['lock_name' => $lockName]);
            } catch (Throwable) {
            }
        }
    }

    private function tableExists(): bool
    {
        try {
            return (bool)$this->pdo->query("SHOW TABLES LIKE 'flight_focus_requests'")->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function airLabsKeys(): array
    {
        $combined = trim((string)Env::get('AIRLABS_API_KEYS', ''));
        $keys = $combined === '' ? [] : (preg_split('/[,;\r\n]+/', $combined) ?: []);
        foreach (['AIRLABS_API_KEY_1', 'AIRLABS_API_KEY_2', 'AIRLABS_API_KEY_3', 'AIRLABS_API_KEY'] as $name) {
            $value = trim((string)Env::get($name, ''));
            if ($value !== '') $keys[] = $value;
        }
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $key): string => trim((string)$key),
            $keys
        ))));
    }

    /** @return array<string,int> */
    private function policy(): array
    {
        return [
            'opensky_per_flight_seconds' => self::PER_FLIGHT_COOLDOWN_SECONDS,
            'opensky_global_seconds' => self::GLOBAL_COOLDOWN_SECONDS,
            'aena_priority_minutes' => self::AENA_PRIORITY_MINUTES,
            'aena_last_hour_interval_minutes' => 15,
            'aena_final_interval_minutes' => 5,
            'aena_final_threshold_minutes' => 20,
            'flightaware_seconds' => 300,
        ];
    }
}
