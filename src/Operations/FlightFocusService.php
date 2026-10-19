<?php
declare(strict_types=1);

namespace SevillaMatrix\Operations;

use PDO;
use RuntimeException;
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
            'SELECT id,flight_date,physical_flight,aircraft_icao24
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

        $openSky = $this->refreshOpenSky($flight);
        $message = (string)($openSky['message'] ?? 'Seguimiento focalizado activado.');
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
            'aena_message' => 'Aena queda marcado para seguimiento: cada 15 min durante la última hora y cada 5 min desde T-20 hasta finalizar el equipaje.',
            'opensky' => $openSky,
            'focus' => $this->status($flightId),
        ];
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
