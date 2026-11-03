<?php
declare(strict_types=1);

namespace SevillaMatrix\Notifications;

use PDO;
use RuntimeException;
use SevillaMatrix\BeltAlarm;
use SevillaMatrix\Env;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\OperationalStatus;
use SevillaMatrix\Sync\AeroApiIngestService;
use SevillaMatrix\Sync\FlightFeederService;
use SevillaMatrix\Sync\FlightStatsIngestService;

final class WebPushService
{
    private string $keyFile;

    public function __construct(private readonly PDO $pdo, ?string $keyFile = null)
    {
        $this->keyFile = $keyFile ?? PROJECT_ROOT . '/storage/keys/vapid.json';
    }

    public function publicKey(): string
    {
        return (string)$this->keys()['public_key'];
    }

    /** @param array<string,mixed> $subscription @return array<string,mixed> */
    public function subscribe(array $subscription, string $userAgent, string $previousDeviceToken = ''): array
    {
        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        $p256dh = trim((string)($keys['p256dh'] ?? ''));
        $auth = trim((string)($keys['auth'] ?? ''));
        if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > 3000 || $p256dh === '' || $auth === '') {
            throw new RuntimeException('Suscripción push no válida.');
        }
        $hash = hash('sha256', $endpoint);
        $existing = $this->pdo->prepare('SELECT device_token FROM push_subscriptions WHERE endpoint_hash=:hash LIMIT 1');
        $existing->execute(['hash' => $hash]);
        $token = (string)($existing->fetchColumn() ?: '');
        if ($token === '' && preg_match('/^[a-f0-9]{64}$/', $previousDeviceToken)) {
            $previous = $this->pdo->prepare('SELECT device_token FROM push_subscriptions WHERE device_token=:token LIMIT 1');
            $previous->execute(['token' => $previousDeviceToken]);
            $token = (string)($previous->fetchColumn() ?: '');
            if ($token !== '') {
                $this->pdo->prepare(
                    'UPDATE push_subscriptions
                     SET endpoint=:endpoint,endpoint_hash=:hash,p256dh=:p256dh,auth_secret=:auth,
                         active=1,user_agent=:agent,last_error=NULL,updated_at=updated_at
                     WHERE device_token=:token'
                )->execute([
                    'endpoint' => $endpoint, 'hash' => $hash, 'p256dh' => substr($p256dh, 0, 180),
                    'auth' => substr($auth, 0, 100), 'agent' => substr($userAgent, 0, 300), 'token' => $token,
                ]);
            }
        }
        if ($token === '') $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare(
            'INSERT INTO push_subscriptions
             (device_token,endpoint,endpoint_hash,p256dh,auth_secret,watch_canary_all,active,user_agent)
             VALUES (:token,:endpoint,:hash,:p256dh,:auth,0,1,:agent)
             ON DUPLICATE KEY UPDATE endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth_secret=VALUES(auth_secret),active=1,user_agent=VALUES(user_agent),last_error=NULL,updated_at=updated_at'
        );
        $stmt->execute([
            'token' => $token, 'endpoint' => $endpoint, 'hash' => $hash,
            'p256dh' => substr($p256dh, 0, 180), 'auth' => substr($auth, 0, 100),
            'agent' => substr($userAgent, 0, 300),
        ]);
        $current = $this->status($token);
        return ['device_token' => $token, 'watch_canary_all' => (bool)($current['watch_canary_all'] ?? false)];
    }

    public function setCanaryWatch(string $deviceToken, bool $enabled): void
    {
        $subscriptionId = $this->subscriptionId($deviceToken);
        $stmt = $this->pdo->prepare(
            'UPDATE push_subscriptions SET watch_canary_all=:enabled,updated_at=NOW() WHERE id=:id'
        );
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'id' => $subscriptionId]);
    }

    /** @return array{notified:bool,sent_at:?string,http_status:?int,push_error:?string} */
    public function setWatch(string $deviceToken, int $flightId, bool $enabled): array
    {
        $subscriptionId = $this->subscriptionId($deviceToken);
        $flight = $this->pdo->prepare('SELECT id FROM flights WHERE id=:id');
        $flight->execute(['id' => $flightId]);
        if (!$flight->fetchColumn()) throw new RuntimeException('El vuelo ya no existe.');
        $stmt = $this->pdo->prepare(
            'INSERT INTO flight_watches (subscription_id,flight_id,enabled) VALUES (:subscription_id,:flight_id,:enabled)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=NOW()'
        );
        $stmt->execute(['subscription_id' => $subscriptionId, 'flight_id' => $flightId, 'enabled' => $enabled ? 1 : 0]);
        if (!$enabled) {
            $this->forgetWatchState($subscriptionId, $flightId);
            return ['notified' => false, 'sent_at' => null, 'http_status' => null, 'push_error' => null];
        }
        $this->rememberWatchState($subscriptionId, $flightId, $this->watchStateSnapshot($flightId));
        $this->armWatchPulseLoop();
        $endpointStmt = $this->pdo->prepare('SELECT endpoint FROM push_subscriptions WHERE id=:id');
        $endpointStmt->execute(['id' => $subscriptionId]);
        if (!$this->isLivePushEndpoint((string)$endpointStmt->fetchColumn())) {
            return ['notified' => false, 'sent_at' => null, 'http_status' => null, 'push_error' => null];
        }
        try {
            $notice = $this->watchArmedNotice($flightId);
            $delivery = $this->deliverToDevice($deviceToken, $notice);
            return [
                'notified' => true,
                'sent_at' => $delivery['sent_at'],
                'http_status' => $delivery['http_status'],
                'push_error' => null,
            ];
        } catch (\Throwable $error) {
            return [
                'notified' => false,
                'sent_at' => null,
                'http_status' => null,
                'push_error' => substr($error->getMessage(), 0, 500),
            ];
        }
    }

    /**
     * Un aviso cifrado de prueba desde el servidor. Los seis de ejemplo
     * se muestran en el teléfono de forma local; este confirma el canal Apple.
     *
     * @return array{ok:bool,sent:int,http_status:int,encrypted:bool,http_version:?int,apple:bool,sent_at:string}
     */
    public function drill(string $deviceToken): array
    {
        $notice = [
            'title' => 'Simulacro servidor · Gran Canaria · VLGTEST',
            'body' => 'Este aviso ha salido del servidor. En iPhone aparece al salir de la app o bloquear la pantalla.',
            'target_url' => 'index.php',
            'tag' => 'matrix-drill-server',
        ];
        $path = $this->testNoticePath($deviceToken);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el simulacro Web Push.');
        }
        file_put_contents($path, json_encode($notice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        try {
            $delivery = $this->deliverToDevice($deviceToken, $notice);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
        return [
            'ok' => true,
            'sent' => 1,
            'http_status' => $delivery['http_status'],
            'encrypted' => $delivery['encrypted'],
            'http_version' => $delivery['http_version'] ?? null,
            'apple' => $delivery['apple'] ?? false,
            'sent_at' => $delivery['sent_at'],
        ];
    }

    /**
     * Reencola FlightAware y manda un aviso honesto con Aena + fuentes secundarias.
     * No escribe observaciones Aena.
     *
     * @return array<string,mixed>
     */
    public function forceCheck(string $deviceToken, int $flightId): array
    {
        if ($flightId < 1) throw new RuntimeException('Vuelo no válido.');
        $subscriptionId = $this->subscriptionId($deviceToken);
        $watch = $this->pdo->prepare(
            'SELECT enabled FROM flight_watches WHERE subscription_id=:subscription_id AND flight_id=:flight_id'
        );
        $watch->execute(['subscription_id' => $subscriptionId, 'flight_id' => $flightId]);
        if ((int)$watch->fetchColumn() !== 1) {
            throw new RuntimeException('Vigila este vuelo para forzar la comprobación.');
        }

        $queued = $this->requeueFlightAware($flightId);
        $statsQueued = $this->requeueFlightStats($flightId);
        $aeroQueued = $this->requeueAeroApi($flightId);
        $aena = [];
        try {
            $aena = (new \SevillaMatrix\Operations\WebOperationsService($this->pdo))
                ->requestAenaSweep('force_check');
        } catch (\Throwable $error) {
            $aena = ['ok' => false, 'error' => $error->getMessage()];
        }
        $snapshot = null;
        try {
            $snapshot = (new FlightStatsIngestService($this->pdo))->captureOne($flightId)['snapshot'] ?? null;
        } catch (\Throwable) {
            // La comprobación sigue con Aena y, si existe, la última lectura FlightStats.
        }
        $aeroSnapshot = null;
        try {
            $aeroSnapshot = (new AeroApiIngestService($this->pdo))->captureOne($flightId)['snapshot'] ?? null;
        } catch (\Throwable) {
            // Sin clave o sin cuota AeroAPI no bloquea la comprobación.
        }
        FlightRepository::forgetSnapshotCaches();
        $notice = $this->forceCheckNotice($flightId);
        if (!empty($aena['local_json']) && !empty($aena['ok'])) {
            $belt = '';
            if (!empty($aena['result']) || !empty($aena['message'])) {
                $belt = ' · cinta reconfirmada';
            }
            $notice['body'] = 'Aena JSON ok' . $belt . ' · ' . $notice['body'];
        } elseif (!empty($aena['ok'])) {
            $notice['body'] = 'Aena pedida · ' . $notice['body'];
        }
        $path = $this->testNoticePath($deviceToken);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar la comprobación Web Push.');
        }
        file_put_contents($path, json_encode($notice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $stmt = $this->pdo->prepare(
            'SELECT s.id,s.endpoint,s.p256dh,s.auth_secret
             FROM push_subscriptions s
             INNER JOIN flight_watches w ON w.subscription_id=s.id
             WHERE w.flight_id=:flight_id AND w.enabled=1 AND s.active=1'
        );
        $stmt->execute(['flight_id' => $flightId]);
        $sent = 0;
        $mine = null;
        $lastError = null;
        foreach ($stmt->fetchAll() as $row) {
            if (!$this->isLivePushEndpoint((string)$row['endpoint'])) continue;
            try {
                $delivery = $this->deliverToRow($row, $notice);
                $this->markWatchPulse((int)$row['id'], $flightId);
                $sent++;
                if ((int)$row['id'] === $subscriptionId) $mine = $delivery;
            } catch (\Throwable $error) {
                $lastError = $error->getMessage();
            }
        }
        if ($sent === 0) {
            @unlink($path);
            throw new RuntimeException($lastError ?: 'No se pudo enviar la comprobación a este teléfono.');
        }
        return [
            'ok' => true,
            'sent' => $sent,
            'flightaware_queued' => $queued,
            'flightstats_queued' => $statsQueued,
            'aeroapi_queued' => $aeroQueued,
            'flightstats_captured' => is_array($snapshot),
            'flightstats' => $snapshot,
            'aeroapi_captured' => is_array($aeroSnapshot),
            'aeroapi' => $aeroSnapshot,
            'aena' => $aena,
            'aena_requested_at' => $aena['requested_at'] ?? null,
            'baseline_aena_unix' => $aena['baseline_aena_unix'] ?? null,
            'body' => $notice['body'],
            'title' => $notice['title'],
            'http_status' => $mine['http_status'] ?? null,
            'encrypted' => $mine['encrypted'] ?? true,
            'http_version' => $mine['http_version'] ?? null,
            'apple' => $mine['apple'] ?? false,
            'sent_at' => $mine['sent_at'] ?? date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Itinerario de vuelos vigilados: última hora cada 10 min, últimos 30 min cada 5.
     *
     * @return array{ok:bool,sent:int,failed:int,flights:int}
     */
    public function tickWatches(string $deviceToken): array
    {
        $subscriptionId = $this->subscriptionId($deviceToken);
        $stmt = $this->pdo->prepare(
            "SELECT s.id,s.id AS subscription_id,w.flight_id,s.endpoint,s.p256dh,s.auth_secret,
                    (SELECT o.baggage_state FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena'
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS baggage_state,
                    (SELECT o.actual_arrival FROM observations o
                     WHERE o.flight_id=f.id AND o.actual_arrival IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS actual_arrival,
                    (SELECT COALESCE(o.actual_arrival,o.eta) FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena'
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS aena_arrival,
                    (SELECT COALESCE(o.actual_arrival,o.eta) FROM observations o
                     WHERE o.flight_id=f.id AND o.source <> 'aena'
                       AND (o.actual_arrival IS NOT NULL OR o.eta IS NOT NULL)
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS other_arrival,
                    f.scheduled_arrival
             FROM flight_watches w
             INNER JOIN push_subscriptions s ON s.id=w.subscription_id AND s.active=1
             INNER JOIN flights f ON f.id=w.flight_id
             WHERE w.enabled=1 AND s.id=:id
               AND f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 12 HOUR)
                                           AND DATE_ADD(NOW(),INTERVAL 2 DAY)"
        );
        $stmt->execute(['id' => $subscriptionId]);
        $rows = $stmt->fetchAll();
        $sent = $this->sendWatchPulses($subscriptionId);
        $loop = $this->armWatchPulseLoop();
        return ['ok' => true, 'sent' => $sent, 'failed' => 0, 'flights' => count($rows), 'loop' => $loop];
    }

    public function pulseWatches(): int
    {
        return $this->sendWatchPulses();
    }

    public function hasActiveWatchTargets(): bool
    {
        $count = (int)$this->pdo->query(
            "SELECT COUNT(*)
             FROM flight_watches w
             INNER JOIN push_subscriptions s ON s.id=w.subscription_id AND s.active=1
             INNER JOIN flights f ON f.id=w.flight_id
             WHERE w.enabled=1
               AND f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 12 HOUR)
                                           AND DATE_ADD(NOW(),INTERVAL 2 DAY)"
        )->fetchColumn();
        return $count > 0;
    }

    /**
     * Arranca en Plesk un proceso CLI que sigue el itinerario
     * aunque el iPhone haya dormido la PWA.
     */
    public function armWatchPulseLoop(): bool
    {
        if (!$this->hasActiveWatchTargets()) return false;
        if ($this->watchPulseLoopRunning()) return true;
        $php = $this->cliPhpBinary();
        $script = PROJECT_ROOT . '/bin/watch-pulse-loop.php';
        if ($php === null || !is_file($script) || !$this->canSpawnShell()) {
            $this->logPush('pulse-loop no se pudo arrancar php=' . ($php ?? 'none') . ' exec=' . ($this->canSpawnShell() ? '1' : '0'));
            return false;
        }
        $log = PROJECT_ROOT . '/storage/logs/watch-pulse-loop.log';
        $directory = dirname($log);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) return false;
        $inner = sprintf(
            'nohup %s %s >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($log)
        );
        exec('bash -c ' . escapeshellarg($inner));
        $this->logPush('pulse-loop arrancado php=' . $php);
        return true;
    }

    /** @return array<string,mixed> */
    public function status(string $deviceToken, ?int $flightId = null): array
    {
        $stmt = $this->pdo->prepare('SELECT id,watch_canary_all,active,last_push_at,last_error FROM push_subscriptions WHERE device_token=:token LIMIT 1');
        $stmt->execute(['token' => $deviceToken]);
        $subscription = $stmt->fetch();
        if (!$subscription) return ['subscribed' => false, 'watching' => false];
        $watching = false;
        if ($flightId) {
            $watch = $this->pdo->prepare('SELECT enabled FROM flight_watches WHERE subscription_id=:subscription_id AND flight_id=:flight_id');
            $watch->execute(['subscription_id' => $subscription['id'], 'flight_id' => $flightId]);
            $watching = (int)$watch->fetchColumn() === 1;
        }
        $watches = $this->pdo->prepare('SELECT COUNT(*) FROM flight_watches WHERE subscription_id=:subscription_id AND enabled=1');
        $watches->execute(['subscription_id' => $subscription['id']]);
        $watchIds = $this->pdo->prepare(
            'SELECT flight_id FROM flight_watches WHERE subscription_id=:subscription_id AND enabled=1 ORDER BY updated_at DESC'
        );
        $watchIds->execute(['subscription_id' => $subscription['id']]);
        $watchedFlights = $this->pdo->prepare(
            'SELECT f.id,f.flight_date,f.physical_flight,f.origin_iata,f.origin_name,f.scheduled_arrival,f.is_canary
             FROM flight_watches w INNER JOIN flights f ON f.id=w.flight_id
             WHERE w.subscription_id=:subscription_id AND w.enabled=1
               AND f.scheduled_arrival>=DATE_SUB(NOW(),INTERVAL 1 DAY)
             ORDER BY f.scheduled_arrival LIMIT 20'
        );
        $watchedFlights->execute(['subscription_id' => $subscription['id']]);
        $watchedRows = $watchedFlights->fetchAll();
        $watchedIds = array_map(static fn(array $row): int => (int)$row['id'], $watchedRows);
        $statsByFlight = $this->latestFlightStats($watchedIds);
        $feedersByFlight = $this->latestFeeders($watchedIds);
        $pending = $this->pdo->prepare('SELECT COUNT(*) FROM push_outbox WHERE subscription_id=:subscription_id AND pushed_at IS NULL AND attempts<4');
        $pending->execute(['subscription_id' => $subscription['id']]);
        return [
            'subscribed' => (int)$subscription['active'] === 1,
            'watching' => $watching,
            'watch_canary_all' => (int)$subscription['watch_canary_all'] === 1,
            'last_push_at' => $subscription['last_push_at'],
            'last_error' => $subscription['last_error'],
            'individual_watches' => (int)$watches->fetchColumn(),
            'watched_flight_ids' => array_map('intval', $watchIds->fetchAll(PDO::FETCH_COLUMN)),
            'watched_flights' => array_map(static fn(array $row): array => [
                'id' => (int)$row['id'],
                'flight_date' => (string)$row['flight_date'],
                'physical_flight' => (string)$row['physical_flight'],
                'origin_iata' => (string)$row['origin_iata'],
                'origin_name' => (string)$row['origin_name'],
                'scheduled_arrival' => (string)$row['scheduled_arrival'],
                'is_canary' => (int)$row['is_canary'],
                'flightstats' => $statsByFlight[(int)$row['id']] ?? null,
                'feeder' => $feedersByFlight[(int)$row['id']] ?? null,
            ], $watchedRows),
            'pending_notifications' => (int)$pending->fetchColumn(),
        ];
    }

    /** @return array<string,mixed>|null */
    public function pending(string $deviceToken): ?array
    {
        $subscriptionId = $this->subscriptionId($deviceToken);
        $test = $this->takeTestNotice($deviceToken);
        if ($test !== null) return $test;
        $stmt = $this->pdo->prepare(
            'SELECT id,title,body,target_url FROM push_outbox
             WHERE subscription_id=:subscription_id AND pushed_at IS NOT NULL AND displayed_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['subscription_id' => $subscriptionId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $this->pdo->prepare('UPDATE push_outbox SET displayed_at=NOW() WHERE id=:id')->execute(['id' => $row['id']]);
        $row['tag'] = 'matrix-outbox-' . (int)$row['id'];
        unset($row['id']);
        return $row;
    }

    /** @return array<string,mixed> */
    public function test(string $deviceToken): array
    {
        $subscriptionId = $this->subscriptionId($deviceToken);
        $notice = [
            'title' => 'Matriz Sevilla · prueba real',
            'body' => 'Web Push funciona: este aviso ha viajado desde el servidor hasta el teléfono.',
            'target_url' => 'index.php',
        ];
        $path = $this->testNoticePath($deviceToken);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar la prueba Web Push.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, json_encode($notice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo guardar el aviso de prueba.');
        }

        try {
            $delivery = $this->deliverToDevice($deviceToken, $notice + ['tag' => 'matrix-test']);
            return ['ok' => true] + $delivery;
        } catch (\Throwable $error) {
            @unlink($path);
            $this->pdo->prepare('UPDATE push_subscriptions SET last_error=:error,updated_at=updated_at WHERE id=:id')
                ->execute(['error' => substr($error->getMessage(), 0, 500), 'id' => $subscriptionId]);
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function takeTestNotice(string $deviceToken): ?array
    {
        $path = $this->testNoticePath($deviceToken);
        if (!is_file($path)) return null;
        // Los service workers actuales muestran la prueba cifrada sin consultar
        // la cola; una copia antigua no debe reaparecer en un aviso posterior.
        if ((int)@filemtime($path) < time() - 120) {
            @unlink($path);
            return null;
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) return null;
        try {
            if (!flock($handle, LOCK_EX)) return null;
            $data = json_decode(stream_get_contents($handle) ?: '', true);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        @unlink($path);
        return is_array($data) ? $data : null;
    }

    private function testNoticePath(string $deviceToken): string
    {
        return PROJECT_ROOT . '/storage/cache/push-tests/' . hash('sha256', $deviceToken) . '.json';
    }

    /** @return array<string,int> */
    public function dispatch(int $limit = 50): array
    {
        $this->createArrivalReminders();
        $stateNotices = $this->notifyWatchStateChanges();
        $watched = "'departure_recorded','belt_assigned','belt_changed','belt_removed','arrival_recorded'," .
            "'baggage_started','eta_published','eta_changed','flight_cancelled','flight_missing'," .
            "'flight_withdrawn','flight_reappeared','arrival_reminder','schedule_changed'," .
            "'hall_changed','gate_changed','stand_changed','baggage_finished'," .
            "'status_changed','status_published'";
        $this->pdo->exec(
            "UPDATE push_outbox o
             JOIN flight_events e ON e.id=o.flight_event_id
             SET o.attempts=4,o.last_error='Descartado por la política de avisos operativos'
             WHERE o.pushed_at IS NULL AND o.attempts<4 AND e.event_type NOT IN ({$watched})"
        );
        // Cinta/sala: solo Aena. Descarta ruido histórico de AirLabs u otros secundarios.
        $this->pdo->exec(
            "UPDATE push_outbox o
             JOIN flight_events e ON e.id=o.flight_event_id
             SET o.attempts=4,o.last_error='Descartado: cinta/sala solo oficiales de Aena'
             WHERE o.pushed_at IS NULL AND o.attempts<4
               AND e.event_type IN ('belt_assigned','belt_changed','belt_removed','hall_changed','hall_assigned')
               AND e.source<>'aena'"
        );
        $this->pdo->exec(
            "UPDATE push_outbox o
             JOIN flight_events e ON e.id=o.flight_event_id
             JOIN flights f ON f.id=e.flight_id
             JOIN push_subscriptions s ON s.id=o.subscription_id
             LEFT JOIN flight_watches w ON w.subscription_id=o.subscription_id AND w.flight_id=e.flight_id AND w.enabled=1
             SET o.attempts=4,o.last_error='Descartado: sin vigilancia individual ni Canarias global'
             WHERE o.pushed_at IS NULL AND o.attempts<4
               AND NOT (
                 (w.id IS NOT NULL AND e.detected_at>=w.updated_at)
                 OR (s.watch_canary_all=1 AND f.is_canary=1 AND e.detected_at>=s.updated_at)
               )"
        );
        $sourceName = "CASE e.source
            WHEN 'aena' THEN 'Aena'
            WHEN 'airlabs' THEN 'AirLabs'
            WHEN 'flightstats' THEN 'FlightStats'
            WHEN 'opensky' THEN 'OpenSky'
            WHEN 'system' THEN ''
            ELSE CONVERT(e.source USING utf8mb4)
          END";
        $aenaStillOnTime = "e.source<>'aena' AND ABS(TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,COALESCE((
            SELECT o.eta FROM observations o
            WHERE o.flight_id=f.id AND o.source='aena' AND o.eta IS NOT NULL
            ORDER BY o.observed_at DESC,o.id DESC LIMIT 1
          ),f.scheduled_arrival)))<5";
        $this->pdo->exec(
            "INSERT IGNORE INTO push_outbox (subscription_id,flight_event_id,title,body,target_url)
             SELECT s.id,e.id,
                    CONCAT(IF(f.is_canary=1,'Canarias · ',''),CONVERT(f.origin_name USING utf8mb4),' · ',CONVERT(f.physical_flight USING utf8mb4)),
                    LEFT(CASE e.event_type
                      WHEN 'departure_recorded' THEN CONCAT('✈️ Despega',IF({$sourceName}='','',CONCAT(' · ',{$sourceName})),IF(NULLIF(e.after_value,'') IS NULL,'',CONCAT(' · ',DATE_FORMAT(e.after_value,'%H:%i'))))
                      WHEN 'belt_assigned' THEN CONCAT('🧳 Asignación de cinta · ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'pendiente de número'))
                      WHEN 'belt_changed' THEN CONCAT('⚠️ Cambio de cinta · ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' → ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'—'))
                      WHEN 'belt_removed' THEN CONCAT('⚠️ Aena retiró la cinta ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' · nueva asignación pendiente')
                      WHEN 'eta_published' THEN CONCAT(
                        '⏱️ ',IF({$sourceName}='','',CONCAT({$sourceName},' ')),
                        'ETA ',DATE_FORMAT(e.after_value,'%H:%i'),' · ',
                        IF(TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value)>0,'+',''),
                        TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value),' min sobre programada',
                        IF({$aenaStillOnTime},' · Aena aún en hora','')
                      )
                      WHEN 'eta_changed' THEN CONCAT(
                        '⏱️ ',IF({$sourceName}='','',CONCAT({$sourceName},' ')),
                        'ETA ',DATE_FORMAT(e.after_value,'%H:%i'),' · ',
                        IF(TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value)>0,'+',''),
                        TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value),' min sobre programada',
                        ' · antes ',DATE_FORMAT(e.before_value,'%H:%i'),
                        IF({$aenaStillOnTime},' · Aena aún en hora','')
                      )
                      WHEN 'schedule_changed' THEN CONCAT('🗓️ Cambio de programación · ',DATE_FORMAT(e.before_value,'%H:%i'),' → ',DATE_FORMAT(e.after_value,'%H:%i'))
                      WHEN 'hall_changed' THEN CONCAT('⚠️ Cambio de sala · ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' → ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'—'))
                      WHEN 'gate_changed' THEN CONCAT('🚪 Cambio de puerta · ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' → ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'—'))
                      WHEN 'stand_changed' THEN CONCAT('🅿️ Cambio de posición · ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' → ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'—'))
                      WHEN 'arrival_recorded' THEN CONCAT('🛬 Aterriza',IF({$sourceName}='','',CONCAT(' · ',{$sourceName})),IF(NULLIF(e.after_value,'') IS NULL,'',CONCAT(' · ',DATE_FORMAT(e.after_value,'%H:%i'))))
                      WHEN 'arrival_reminder' THEN CONCAT('🚗 Aviso 40 min · llegada prevista ',DATE_FORMAT(e.after_value,'%H:%i'))
                      WHEN 'flight_cancelled' THEN CONCAT('🚫 Vuelo cancelado',IF({$sourceName}='','',CONCAT(' · ',{$sourceName})))
                      WHEN 'flight_missing' THEN CONCAT('⚠️ El vuelo dejó de aparecer temporalmente',IF({$sourceName}='','',CONCAT(' · ',{$sourceName})))
                      WHEN 'flight_withdrawn' THEN CONCAT('🚫 Vuelo retirado tras varias comprobaciones',IF({$sourceName}='','',CONCAT(' · ',{$sourceName})))
                      WHEN 'flight_reappeared' THEN CONCAT('✅ El vuelo vuelve a aparecer',IF({$sourceName}='','',CONCAT(' · ',{$sourceName})))
                      WHEN 'baggage_started' THEN CONCAT(
                        '🧳 En la cinta',
                        COALESCE((SELECT CONCAT(' · ',IF(NULLIF(o.hall,'') IS NULL,'',CONCAT(CONVERT(o.hall USING utf8mb4),'/')),CONVERT(o.belt USING utf8mb4))
                                  FROM observations o
                                  WHERE o.flight_id=f.id AND NULLIF(o.belt,'') IS NOT NULL
                                  ORDER BY (o.source='aena') DESC,o.observed_at DESC,o.id DESC LIMIT 1),'')
                      )
                      WHEN 'baggage_finished' THEN '✅ Entrega de equipaje finalizada'
                      WHEN 'status_published' THEN CONCAT('ℹ️ ',IF({$sourceName}='','',CONCAT({$sourceName},' · ')),COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'Estado publicado'))
                      WHEN 'status_changed' THEN CONCAT('ℹ️ ',IF({$sourceName}='','',CONCAT({$sourceName},' · ')),COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'Cambio de estado'))
                    END,500),
                    CONCAT('index.php?date=',DATE_FORMAT(f.flight_date,'%Y-%m-%d'),'&flight=',f.id)
             FROM push_subscriptions s
             JOIN flight_events e ON e.detected_at>=s.created_at AND e.event_type IN ({$watched})
             JOIN flights f ON f.id=e.flight_id
             LEFT JOIN flight_watches w ON w.subscription_id=s.id AND w.flight_id=f.id AND w.enabled=1
             WHERE s.active=1
               AND (
                 (w.id IS NOT NULL AND e.detected_at>=w.updated_at)
                 OR (
                   s.watch_canary_all=1 AND f.is_canary=1 AND e.detected_at>=s.updated_at
                   AND (
                     e.event_type IN ('departure_recorded','arrival_recorded','arrival_reminder')
                     OR e.source='aena'
                     OR (e.source IN ('flightstats','airlabs','opensky') AND e.event_type IN (
                       'eta_changed','eta_published','departure_recorded','arrival_recorded',
                       'flight_cancelled'
                     ))
                   )
                 )
               )
               AND NOT (
                 e.event_type='eta_changed'
                 AND ABS(TIMESTAMPDIFF(MINUTE,e.before_value,e.after_value))<5
               )
               AND NOT (
                 e.event_type IN ('eta_changed','eta_published')
                 AND ABS(TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value))<5
               )
               AND NOT (
                 e.event_type IN ('status_changed','status_published')
                 AND e.source IN ('flightstats','airlabs','opensky')
               )
               AND NOT (
                 e.event_type IN ('belt_assigned','belt_changed','belt_removed','hall_changed','hall_assigned')
                 AND e.source<>'aena'
               )"
        );
        $criticalBelt = $this->queueCriticalBeltAlarms();

        $stmt = $this->pdo->prepare(
            'SELECT o.id,o.subscription_id,o.title,o.body,o.target_url,s.endpoint,s.p256dh,s.auth_secret,
                    e.flight_id,e.event_type,e.before_value,e.after_value,
                    f.physical_flight,f.origin_name,f.is_canary
             FROM push_outbox o
             JOIN push_subscriptions s ON s.id=o.subscription_id
             JOIN flight_events e ON e.id=o.flight_event_id
             JOIN flights f ON f.id=e.flight_id
             WHERE o.pushed_at IS NULL AND o.attempts<4 AND s.active=1
             ORDER BY o.id LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $sent = $failed = 0;
        foreach ($stmt->fetchAll() as $row) {
            // Reserve the outbox row before waking the service worker. Safari can
            // receive an empty Web Push immediately and request /pending while
            // sendEmpty() is still waiting for Apple's HTTP response. Marking it
            // afterwards left that request with an empty queue and lost the alert.
            $reserve = $this->pdo->prepare(
                'UPDATE push_outbox
                 SET pushed_at=NOW(),attempts=attempts+1,last_error=NULL
                 WHERE id=:id AND pushed_at IS NULL AND attempts<4'
            );
            $reserve->execute(['id' => $row['id']]);
            if ($reserve->rowCount() !== 1) continue;
            try {
                $notice = $this->outboxNotice($row);
                $delivery = $this->deliverToRow([
                    'id' => (int)$row['subscription_id'],
                    'endpoint' => $row['endpoint'],
                    'p256dh' => $row['p256dh'],
                    'auth_secret' => $row['auth_secret'],
                ], $notice);
                if (!empty($delivery['skipped'])) continue;
                if (!empty($delivery['encrypted'])) {
                    $this->pdo->prepare('UPDATE push_outbox SET displayed_at=NOW() WHERE id=:id AND displayed_at IS NULL')
                        ->execute(['id' => $row['id']]);
                }
                $sent++;
            } catch (\Throwable $error) {
                // Return the row to the retry queue. The attempt was already
                // counted by the atomic reservation above.
                $this->pdo->prepare('UPDATE push_outbox SET pushed_at=NULL,last_error=:error WHERE id=:id')
                    ->execute(['error' => substr($error->getMessage(), 0, 500), 'id' => $row['id']]);
                $this->pdo->prepare('UPDATE push_subscriptions SET last_error=:error,updated_at=updated_at WHERE id=:id')
                    ->execute(['error' => substr($error->getMessage(), 0, 500), 'id' => $row['subscription_id']]);
                $failed++;
            }
        }
        $pulses = $this->sendWatchPulses();
        $loop = $this->armWatchPulseLoop();
        return [
            'queued' => (int)$this->pdo->query('SELECT COUNT(*) FROM push_outbox WHERE pushed_at IS NULL')->fetchColumn(),
            'sent' => $sent,
            'failed' => $failed,
            'pulses' => $pulses,
            'state_notices' => $stateNotices,
            'critical_belt' => $criticalBelt,
            'loop' => $loop,
        ];
    }

    /**
     * Un A/3 → B/7 no puede depender de que alguien vigile el vuelo:
     * avisa a todos los teléfonos con push activo.
     */
    private function queueCriticalBeltAlarms(): int
    {
        $events = $this->pdo->query(
            "SELECT e.id,e.flight_id,e.event_type,e.before_value,e.after_value,e.detected_at,
                    f.physical_flight,f.origin_name,f.flight_date,f.is_canary
             FROM flight_events e
             INNER JOIN flights f ON f.id=e.flight_id
             WHERE e.source='aena'
               AND e.event_type IN ('belt_changed','hall_changed')
               AND e.detected_at>=DATE_SUB(NOW(),INTERVAL 20 MINUTE)
             ORDER BY e.id DESC
             LIMIT 30"
        );
        $subscriptions = $this->pdo->query(
            'SELECT id FROM push_subscriptions WHERE active=1'
        )->fetchAll();
        if ($subscriptions === []) return 0;
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO push_outbox (subscription_id,flight_event_id,title,body,target_url)
             VALUES (:subscription_id,:flight_event_id,:title,:body,:target_url)'
        );
        $queued = 0;
        foreach ($events as $event) {
            if (!BeltAlarm::isBlockChange((string)$event['before_value'], (string)$event['after_value'])) {
                continue;
            }
            $title = '🚨 ALARMA CINTA · '
                . ((int)$event['is_canary'] === 1 ? 'Canarias · ' : '')
                . $event['origin_name'] . ' · ' . $event['physical_flight'];
            $body = BeltAlarm::pushBody((string)$event['before_value'], (string)$event['after_value']);
            $url = 'index.php?date=' . rawurlencode(substr((string)$event['flight_date'], 0, 10))
                . '&flight=' . (int)$event['flight_id'];
            foreach ($subscriptions as $subscription) {
                $insert->execute([
                    'subscription_id' => (int)$subscription['id'],
                    'flight_event_id' => (int)$event['id'],
                    'title' => substr($title, 0, 180),
                    'body' => substr($body, 0, 500),
                    'target_url' => $url,
                ]);
                if ($insert->rowCount() > 0) $queued++;
            }
        }
        return $queued;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function outboxNotice(array $row): array
    {
        $flightId = (int)$row['flight_id'];
        $eventType = (string)$row['event_type'];
        $critical = in_array($eventType, ['belt_changed', 'hall_changed'], true)
            && BeltAlarm::isBlockChange((string)($row['before_value'] ?? ''), (string)($row['after_value'] ?? ''));
        $title = (string)$row['title'];
        $body = (string)$row['body'];
        $tag = 'matrix-watch-' . $flightId . '-' . ($critical ? 'belt-critical' : $this->eventTag($eventType));
        if ($critical) {
            $prefix = '🚨 ALARMA CINTA · ';
            if (!str_contains($title, 'ALARMA CINTA')) {
                $title = $prefix . ((int)($row['is_canary'] ?? 0) === 1 && !str_starts_with($title, 'Canarias') ? 'Canarias · ' : '')
                    . preg_replace('/^(Canarias · )?/u', '', $title);
            }
            $body = BeltAlarm::pushBody((string)($row['before_value'] ?? ''), (string)($row['after_value'] ?? ''));
        }
        return [
            'title' => $title,
            'body' => $body,
            'target_url' => (string)$row['target_url'],
            'tag' => $tag,
            'urgent' => $critical,
        ];
    }

    private function createArrivalReminders(): void
    {
        // Un único hito por vuelo. La ventana 0–45 min evita perder el aviso
        // si el despacho no cae exactamente entre los minutos 36 y 40.
        $this->pdo->exec(
            "INSERT IGNORE INTO flight_events
             (flight_id,sync_run_id,source,event_type,field_name,before_value,after_value,
              reason_code,reason_detail,confidence,evidence,detected_at,event_fingerprint)
             SELECT due.flight_id,NULL,'system','arrival_reminder','effective_arrival',NULL,due.arrival_at,
                    'arrival_reminder_40m','Aviso previo para salir a recoger al pasajero.',
                    'probable',JSON_OBJECT('minutes_before',TIMESTAMPDIFF(MINUTE,NOW(),due.arrival_at)),
                    NOW(),SHA2(CONCAT('arrival_reminder_40m|',due.flight_id,'|',DATE(due.arrival_at)),256)
             FROM (
               SELECT f.id AS flight_id,
                      COALESCE(
                        (SELECT o.actual_arrival FROM observations o
                         WHERE o.flight_id=f.id AND o.actual_arrival IS NOT NULL
                         ORDER BY o.observed_at DESC,o.id DESC LIMIT 1),
                        (SELECT o.eta FROM observations o
                         WHERE o.flight_id=f.id AND o.eta IS NOT NULL
                         ORDER BY o.observed_at DESC,o.id DESC LIMIT 1),
                        f.scheduled_arrival
                      ) AS arrival_at
               FROM flights f
               WHERE f.flight_date BETWEEN DATE_SUB(CURRENT_DATE(),INTERVAL 1 DAY)
                                       AND DATE_ADD(CURRENT_DATE(),INTERVAL 1 DAY)
                 AND EXISTS (
                   SELECT 1 FROM flight_watches w
                   INNER JOIN push_subscriptions s ON s.id=w.subscription_id
                   WHERE w.flight_id=f.id AND w.enabled=1 AND s.active=1
                 )
                 AND NOT EXISTS (
                   SELECT 1 FROM observations landed
                   WHERE landed.flight_id=f.id AND landed.actual_arrival IS NOT NULL
                 )
             ) due
             WHERE TIMESTAMPDIFF(MINUTE,NOW(),due.arrival_at) BETWEEN 0 AND 45"
        );
    }

    /** @return array<string,string> */
    private function watchNoticeBase(array $ctx, int $flightId): array
    {
        $title = ((int)$ctx['is_canary'] === 1 ? 'Canarias · ' : '') . $ctx['origin_name'] . ' · ' . $ctx['physical_flight'];
        $date = substr((string)$ctx['flight_date'], 0, 10);
        return [
            'title' => $title,
            'target_url' => 'index.php?date=' . rawurlencode($date) . '&flight=' . $flightId,
        ];
    }

    /** @return array<string,string> */
    private function watchArmedNotice(int $flightId): array
    {
        $ctx = $this->flightNoticeContext($flightId);
        if ($ctx === null) throw new RuntimeException('El vuelo ya no existe.');
        return $this->watchNoticeBase($ctx, $flightId) + [
            'body' => 'Vigilancia activa · ' . $ctx['physical_flight'] . '. Última hora cada 10 min, últimos 30 min cada 5. Si Aena, AirLabs o FlightStats cambia hora, cinta o estado, aviso al momento y dice quién lo vio.',
            'tag' => 'matrix-watch-' . $flightId . '-on',
        ];
    }

    /** @return array<string,string> */
    private function watchStatusNotice(int $flightId, bool $justStarted = false): array
    {
        if ($justStarted) return $this->watchArmedNotice($flightId);
        $ctx = $this->flightNoticeContext($flightId);
        if ($ctx === null) throw new RuntimeException('El vuelo ya no existe.');
        $belt = $this->beltLabel($ctx['hall'], $ctx['belt']);
        $arrival = (string)($ctx['actual_arrival'] ?: $ctx['aena_arrival'] ?: $ctx['scheduled_arrival']);
        $when = $arrival !== '' ? date('H:i', strtotime($arrival)) : '—';
        $body = $ctx['actual_arrival']
            ? '🛬 Aterrizó a las ' . $when . ' · ' . $belt
            : ('Prevista ' . $when . ' · ' . $belt);
        return $this->watchNoticeBase($ctx, $flightId) + [
            'body' => $body,
            'tag' => 'matrix-watch-' . $flightId,
        ];
    }

    /** @return array<string,string> */
    private function forceCheckNotice(int $flightId): array
    {
        $ctx = $this->flightNoticeContext($flightId);
        if ($ctx === null) throw new RuntimeException('El vuelo ya no existe.');
        $belt = $this->beltLabel($ctx['hall'], $ctx['belt']);
        $aenaWhen = $ctx['aena_arrival'] ? date('H:i', strtotime((string)$ctx['aena_arrival'])) : 'sin ETA';
        $aenaDiff = null;
        if ($ctx['aena_arrival'] && $ctx['scheduled_arrival']) {
            $aenaDiff = (int)round((strtotime((string)$ctx['aena_arrival']) - strtotime((string)$ctx['scheduled_arrival'])) / 60);
        }
        $aenaBit = 'Aena ' . ($ctx['aena_status'] ?: 'sin estado') . ' ' . $aenaWhen;
        if ($aenaDiff !== null) $aenaBit .= ' (' . ($aenaDiff > 0 ? '+' : '') . $aenaDiff . ' min)';
        $aenaBit .= ' · ' . $belt;

        $parts = ['Comprobación forzada · ' . $aenaBit];
        $stats = $this->latestFlightStats([$flightId])[$flightId] ?? null;
        if ($stats) {
            $fs = 'FlightStats';
            if (isset($stats['delay_minutes']) && $stats['delay_minutes'] !== null && $stats['delay_minutes'] !== '') {
                $fs .= ' retraso ' . (int)$stats['delay_minutes'] . ' min';
            } elseif (!empty($stats['status_text'])) {
                $fs .= ' ' . $stats['status_text'];
            }
            if (!empty($stats['actual_departure'])) {
                $fs .= ' · salió ' . date('H:i', strtotime((string)$stats['actual_departure']));
            }
            $fsEta = (string)($stats['eta'] ?: $stats['actual_arrival'] ?: '');
            if ($fsEta !== '') $fs .= ' · ETA ' . date('H:i', strtotime($fsEta));
            $parts[] = $fs;
        }
        if (!empty($ctx['airlabs_departure'])) {
            $parts[] = 'AirLabs despegue ' . date('H:i', strtotime((string)$ctx['airlabs_departure']));
        }
        $parts[] = 'En iPhone bloquea o sal de la app.';
        $date = substr((string)$ctx['flight_date'], 0, 10);
        return [
            'title' => 'Comprobar · ' . ((int)$ctx['is_canary'] === 1 ? 'Canarias · ' : '') . $ctx['origin_name'] . ' · ' . $ctx['physical_flight'],
            'body' => implode(' · ', $parts),
            'target_url' => 'index.php?date=' . rawurlencode($date) . '&flight=' . $flightId,
            'tag' => 'matrix-force-' . $flightId,
        ];
    }

    private function requeueFlightAware(int $flightId): bool
    {
        $tables = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN ('flightaware_snapshots','flightaware_requests')"
        )->fetchColumn();
        if ($tables < 2) return false;
        $this->pdo->prepare(
            "INSERT INTO flightaware_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
             VALUES (:id,NOW(),NULL,1,'queued',NULL)
             ON DUPLICATE KEY UPDATE requested_at=NOW(),processed_at=NULL,
               request_count=request_count+1,status='queued',last_error=NULL"
        )->execute(['id' => $flightId]);
        return true;
    }

    private function requeueFlightStats(int $flightId): bool
    {
        try {
            return (new FlightStatsIngestService($this->pdo))->requeue($flightId);
        } catch (\Throwable) {
            return false;
        }
    }

    private function requeueAeroApi(int $flightId): bool
    {
        try {
            return (new AeroApiIngestService($this->pdo))->requeue($flightId);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<int> $flightIds
     * @return array<int,array<string,mixed>>
     */
    private function latestFlightStats(array $flightIds): array
    {
        $flightIds = array_values(array_unique(array_filter(array_map('intval', $flightIds))));
        if ($flightIds === []) return [];
        try {
            $ready = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='flightstats_snapshots'"
            )->fetchColumn();
            if ($ready !== 1) return [];
        } catch (\Throwable) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($flightIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT s.id,s.flight_id,s.observed_at,s.public_url,s.iata_carrier,s.iata_number,s.status_text,
                    s.delay_minutes,s.eta,s.actual_departure,s.actual_arrival,s.departure_text,s.arrival_text,s.phase
             FROM flightstats_snapshots s
             WHERE s.flight_id IN ({$placeholders})
               AND s.id=(SELECT s2.id FROM flightstats_snapshots s2
                         WHERE s2.flight_id=s.flight_id ORDER BY s2.observed_at DESC,s2.id DESC LIMIT 1)"
        );
        $stmt->execute($flightIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['flight_id']] = $row;
        }
        return $out;
    }

    /**
     * @param list<int> $flightIds
     * @return array<int,array<string,mixed>>
     */
    private function latestFeeders(array $flightIds): array
    {
        return (new FlightFeederService($this->pdo))->latestByFlightIds($flightIds);
    }

    /** @return array<string,mixed>|null */
    private function flightNoticeContext(int $flightId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.id,f.physical_flight,f.origin_name,f.flight_date,f.scheduled_arrival,f.scheduled_departure,f.is_canary,
                    (SELECT COALESCE(o.actual_arrival,o.eta) FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena'
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS aena_arrival,
                    (SELECT o.eta FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena' AND o.eta IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS aena_eta,
                    (SELECT o.actual_arrival FROM observations o
                     WHERE o.flight_id=f.id AND o.actual_arrival IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS actual_arrival,
                    (SELECT o.belt FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena' AND NULLIF(o.belt,'') IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS belt,
                    (SELECT o.hall FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena' AND NULLIF(o.hall,'') IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS hall,
                    (SELECT o.baggage_state FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena'
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS baggage_state,
                    (SELECT o.status FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena'
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS aena_status,
                    (SELECT o.status FROM observations o
                     WHERE o.flight_id=f.id AND o.source='airlabs' AND NULLIF(o.status,'') IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS airlabs_status,
                    (SELECT o.status FROM observations o
                     WHERE o.flight_id=f.id AND o.source='opensky' AND NULLIF(o.status,'') IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS opensky_status,
                    (SELECT o.status FROM observations o
                     WHERE o.flight_id=f.id AND o.source='flightstats' AND NULLIF(o.status,'') IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS flightstats_status,
                    (SELECT COALESCE(o.eta,o.actual_arrival) FROM observations o
                     WHERE o.flight_id=f.id AND o.source IN ('airlabs','flightstats')
                       AND (o.eta IS NOT NULL OR o.actual_arrival IS NOT NULL)
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS secondary_eta,
                    (SELECT o.source FROM observations o
                     WHERE o.flight_id=f.id AND o.source IN ('airlabs','flightstats')
                       AND (o.eta IS NOT NULL OR o.actual_arrival IS NOT NULL)
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS secondary_eta_source,
                    (SELECT o.actual_departure FROM observations o
                     WHERE o.flight_id=f.id AND o.actual_departure IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS secondary_departure,
                    (SELECT o.actual_departure FROM observations o
                     WHERE o.flight_id=f.id AND o.source='airlabs' AND o.actual_departure IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS airlabs_departure
             FROM flights f WHERE f.id=:id LIMIT 1"
        );
        $stmt->execute(['id' => $flightId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function beltLabel(?string $hall, ?string $belt): string
    {
        $belt = trim((string)$belt);
        if ($belt === '') return 'cinta pendiente';
        $hall = trim((string)$hall);
        if ($hall !== '' && !str_contains($belt, '/')) return $hall . '/' . $belt;
        return $belt;
    }

    private function notifyWatchStateChanges(): int
    {
        $stmt = $this->pdo->query(
            "SELECT s.id,s.id AS subscription_id,s.endpoint,s.p256dh,s.auth_secret,w.flight_id,
                    f.physical_flight,f.origin_name,f.flight_date,f.scheduled_arrival,f.is_canary
             FROM flight_watches w
             INNER JOIN push_subscriptions s ON s.id=w.subscription_id AND s.active=1
             INNER JOIN flights f ON f.id=w.flight_id
             WHERE w.enabled=1
               AND f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 12 HOUR)
                                           AND DATE_ADD(NOW(),INTERVAL 2 DAY)
             LIMIT 80"
        );
        $sent = 0;
        foreach ($stmt->fetchAll() as $row) {
            if (!$this->isLivePushEndpoint((string)$row['endpoint'])) continue;
            $flightId = (int)$row['flight_id'];
            $subscriptionId = (int)$row['subscription_id'];
            $snap = $this->watchStateSnapshot($flightId);
            $before = $this->readWatchState($subscriptionId, $flightId);
            $this->rememberWatchState($subscriptionId, $flightId, $snap);
            if ($before === null) continue;
            foreach ($this->watchStateDiff($row, $before, $snap) as $notice) {
                try {
                    $delivery = $this->deliverToRow($row, $notice);
                    if (empty($delivery['skipped'])) $sent++;
                } catch (\Throwable $error) {
                    $this->logPush('state-fail flight=' . $flightId . ' sub=' . $subscriptionId . ' err=' . $error->getMessage());
                }
            }
        }
        return $sent;
    }

    /** @return array<string,string> */
    private function watchStateSnapshot(int $flightId): array
    {
        $ctx = $this->flightNoticeContext($flightId);
        if ($ctx === null) {
            return [
                'aena_status' => '', 'airlabs_status' => '', 'opensky_status' => '', 'flightstats_status' => '',
                'eta' => '', 'aena_eta' => '', 'secondary_eta' => '', 'secondary_source' => '', 'fs_delay' => '',
                'actual_arrival' => '', 'actual_departure' => '', 'belt' => '', 'baggage' => '',
                'feeder_code' => '', 'feeder_delay' => '',
            ];
        }
        $stats = $this->latestFlightStats([$flightId])[$flightId] ?? null;
        $fsEta = is_array($stats) ? (string)($stats['eta'] ?: $stats['actual_arrival'] ?: '') : '';
        $fsDelay = is_array($stats) && $stats['delay_minutes'] !== null && $stats['delay_minutes'] !== ''
            ? (string)(int)$stats['delay_minutes'] : '';
        $feeder = $this->latestFeeders([$flightId])[$flightId] ?? null;
        $feederDelay = is_array($feeder) && $feeder['delay_minutes'] !== null && $feeder['delay_minutes'] !== ''
            ? (string)(int)$feeder['delay_minutes'] : '';
        $secondary = (string)($ctx['secondary_eta'] ?: $fsEta ?: '');
        $secondarySource = (string)($ctx['secondary_eta_source'] ?: ($fsEta !== '' ? 'flightstats' : ''));
        return [
            'aena_status' => $this->statusLabel((string)($ctx['aena_status'] ?? '')),
            'airlabs_status' => $this->statusLabel((string)($ctx['airlabs_status'] ?? '')),
            'opensky_status' => $this->statusLabel((string)($ctx['opensky_status'] ?? '')),
            'flightstats_status' => $this->statusLabel((string)($ctx['flightstats_status'] ?? '')),
            'eta' => (string)($this->preferredEta($ctx) ?: ''),
            'aena_eta' => (string)($ctx['aena_eta'] ?: ''),
            'secondary_eta' => $secondary,
            'secondary_source' => $secondarySource,
            'fs_delay' => $fsDelay,
            'actual_arrival' => (string)($ctx['actual_arrival'] ?: ''),
            'actual_departure' => (string)($ctx['airlabs_departure'] ?: $ctx['secondary_departure'] ?: ''),
            'belt' => $this->beltLabel($ctx['hall'] ?? null, $ctx['belt'] ?? null),
            'baggage' => (string)($ctx['baggage_state'] ?: ''),
            'feeder_code' => is_array($feeder) ? (string)($feeder['feeder_code'] ?? '') : '',
            'feeder_delay' => $feederDelay,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $before
     * @param array<string,string> $snap
     * @return list<array<string,string>>
     */
    private function watchStateDiff(array $row, array $before, array $snap): array
    {
        $before = array_merge([
            'aena_status' => '', 'airlabs_status' => '', 'opensky_status' => '', 'flightstats_status' => '',
            'eta' => '', 'aena_eta' => '', 'secondary_eta' => '', 'secondary_source' => '', 'fs_delay' => '',
            'actual_arrival' => '', 'actual_departure' => '', 'belt' => '', 'baggage' => '',
            'feeder_code' => '', 'feeder_delay' => '',
        ], $before);
        $title = ((int)$row['is_canary'] === 1 ? 'Canarias · ' : '')
            . $row['origin_name'] . ' · ' . $row['physical_flight'];
        $date = substr((string)$row['flight_date'], 0, 10);
        $url = 'index.php?date=' . rawurlencode($date) . '&flight=' . (int)$row['flight_id'];
        $tag = 'matrix-watch-' . (int)$row['flight_id'];
        $notice = function (string $body, string $suffix) use ($title, $url, $tag): array {
            $urgent = str_contains($suffix, 'critical');
            return [
                'title' => $urgent ? '🚨 ALARMA CINTA · ' . $title : $title,
                'body' => $body,
                'target_url' => $url,
                'tag' => $tag . $suffix,
                'urgent' => $urgent,
            ];
        };
        $out = [];
        if ($before['actual_arrival'] !== $snap['actual_arrival'] && $snap['actual_arrival'] !== '') {
            $out[] = $notice('🛬 Aterriza · ' . date('H:i', strtotime($snap['actual_arrival'])), '-arr');
        }
        if ($before['actual_departure'] !== $snap['actual_departure'] && $snap['actual_departure'] !== '') {
            $out[] = $notice('✈️ Despega · ' . date('H:i', strtotime($snap['actual_departure'])), '-dep');
        }
        $scheduled = strtotime((string)$row['scheduled_arrival']);
        if ($before['aena_eta'] !== $snap['aena_eta'] && $snap['aena_eta'] !== '' && $scheduled) {
            $etaAt = strtotime($snap['aena_eta']);
            $delay = $etaAt ? (int)round(($etaAt - $scheduled) / 60) : 0;
            if ($etaAt && abs($delay) >= 5) {
                $out[] = $notice(
                    '⏱️ Aena ETA ' . date('H:i', $etaAt) . ' · ' . ($delay > 0 ? '+' : '') . $delay . ' min sobre programada',
                    '-eta'
                );
            }
        } elseif ($before['eta'] !== $snap['eta'] && $snap['eta'] !== '' && $scheduled && ($snap['aena_eta'] ?? '') === '') {
            $etaAt = strtotime($snap['eta']);
            $delay = $etaAt ? (int)round(($etaAt - $scheduled) / 60) : 0;
            if ($etaAt && abs($delay) >= 5) {
                $out[] = $notice(
                    '⏱️ ETA ' . date('H:i', $etaAt) . ' · ' . ($delay > 0 ? '+' : '') . $delay . ' min sobre programada',
                    '-eta'
                );
            }
        }
        $prevSecondaryDelay = $this->roundedDelayMinutes((string)$before['secondary_eta'], (string)$row['scheduled_arrival']);
        $nextSecondaryDelay = $this->roundedDelayMinutes((string)$snap['secondary_eta'], (string)$row['scheduled_arrival']);
        if ($snap['secondary_eta'] !== '' && $scheduled && $prevSecondaryDelay !== $nextSecondaryDelay && abs((int)$nextSecondaryDelay) >= 5) {
            $etaAt = strtotime($snap['secondary_eta']);
            $delay = $etaAt ? (int)round(($etaAt - $scheduled) / 60) : 0;
            $aenaAt = strtotime((string)($snap['aena_eta'] ?: $row['scheduled_arrival']));
            $vsAena = ($etaAt && $aenaAt) ? abs((int)round(($etaAt - $aenaAt) / 60)) : abs($delay);
            if ($etaAt && abs($delay) >= 5) {
                $source = $this->prettySource((string)$snap['secondary_source']);
                $body = '⏱️ ' . $source . ' ETA ' . date('H:i', $etaAt)
                    . ' · ' . ($delay > 0 ? '+' : '') . $delay . ' min sobre programada';
                if ($vsAena >= 5) $body .= ' · Aena aún en hora';
                $out[] = $notice($body, '-eta2');
            }
        } elseif ($before['fs_delay'] !== ($snap['fs_delay'] ?? '') && ($snap['fs_delay'] ?? '') !== '' && abs((int)$snap['fs_delay']) >= 5) {
            $delay = (int)$snap['fs_delay'];
            $body = '⏱️ FlightStats ' . ($delay > 0 ? '+' : '') . $delay . ' min';
            $aenaAt = strtotime((string)($snap['aena_eta'] ?: $row['scheduled_arrival']));
            if ($scheduled && $aenaAt && abs((int)round(($aenaAt - $scheduled) / 60)) < 5) {
                $body .= ' · Aena aún en hora';
            }
            $out[] = $notice($body, '-fs');
        }
        if (($before['feeder_delay'] ?? '') !== ($snap['feeder_delay'] ?? '')
            && ($snap['feeder_delay'] ?? '') !== ''
            && abs((int)$snap['feeder_delay']) >= FlightFeederService::MIN_DELAY) {
            $delay = (int)$snap['feeder_delay'];
            $code = (string)($snap['feeder_code'] ?: 'ida');
            $body = '🔄 Arrastra retraso de ' . $code . ' · ' . ($delay > 0 ? '+' : '') . $delay . ' min';
            $aenaAt = strtotime((string)($snap['aena_eta'] ?: $row['scheduled_arrival']));
            if ($scheduled && $aenaAt && abs((int)round(($aenaAt - $scheduled) / 60)) < 5) {
                $body .= ' · Aena aún en hora';
            }
            $out[] = $notice($body, '-feeder');
        }
        $beltBefore = $before['belt'] === 'cinta pendiente' ? '' : $before['belt'];
        $beltNow = $snap['belt'] === 'cinta pendiente' ? '' : $snap['belt'];
        if ($beltBefore !== $beltNow && $beltNow !== '') {
            if ($beltBefore !== '' && BeltAlarm::isBlockChange($beltBefore, $beltNow)) {
                $out[] = $notice(BeltAlarm::pushBody($beltBefore, $beltNow), '-belt-critical');
            } else {
                $out[] = $notice(
                    ($beltBefore !== '' ? '⚠️ Cambio de cinta · ' . $beltBefore . ' → ' : '🧳 Asignación de cinta · ') . $beltNow,
                    '-belt'
                );
            }
        }
        if ($before['baggage'] !== $snap['baggage'] && $snap['baggage'] !== '' && $snap['baggage'] !== 'pendiente') {
            $out[] = $notice(
                $snap['baggage'] === 'finalizado' || $snap['baggage'] === 'final'
                    ? '✅ Entrega de equipaje finalizada'
                    : ('🧳 En la cinta' . ($beltNow !== '' ? ' · ' . $beltNow : '')),
                '-bag'
            );
        }
        foreach ([
            'aena_status' => 'Aena',
            'airlabs_status' => 'AirLabs',
            'opensky_status' => 'OpenSky',
            'flightstats_status' => 'FlightStats',
        ] as $field => $source) {
            $next = $snap[$field] ?? '';
            $prev = $before[$field] ?? '';
            if ($next === '' || OperationalStatus::isCosmeticChange($prev, $next)) continue;
            if (!OperationalStatus::isActionablePhase($next)) continue;
            if (preg_match('/tierra|landed|aterriz|finaliz/i', $next) === 1 && $snap['actual_arrival'] !== '') continue;
            $out[] = $notice('ℹ️ ' . $next . ' · ' . $source, '-st-' . strtolower($source));
        }
        return array_slice($out, 0, 5);
    }

    /** @param array<string,mixed> $ctx */
    private function preferredEta(array $ctx): string
    {
        $scheduled = (string)($ctx['scheduled_arrival'] ?? '');
        $aena = (string)($ctx['aena_arrival'] ?: $ctx['aena_eta'] ?: '');
        $secondary = (string)($ctx['secondary_eta'] ?? '');
        if ($this->differsAtLeast($aena, $scheduled, 5)) return $aena;
        if ($this->differsAtLeast($secondary, $scheduled, 5)) return $secondary;
        return $aena !== '' ? $aena : $scheduled;
    }

    private function differsAtLeast(string $value, string $scheduled, int $minutes): bool
    {
        if ($value === '' || $scheduled === '') return false;
        $left = strtotime($value);
        $right = strtotime($scheduled);
        return $left !== false && $right !== false && abs($left - $right) >= $minutes * 60;
    }

    private function prettySource(string $source): string
    {
        return match (strtolower(trim($source))) {
            'aena' => 'Aena',
            'airlabs' => 'AirLabs',
            'flightstats' => 'FlightStats',
            'opensky' => 'OpenSky',
            'arrastre' => 'avión anterior',
            default => $source !== '' ? $source : 'API',
        };
    }

    private function statusLabel(string $status): string
    {
        $map = [
            'active' => 'En vuelo',
            'enroute' => 'En vuelo',
            'landed' => 'Aterrizado',
            'scheduled' => 'Programado',
            'on_ground' => 'En tierra',
        ];
        $key = strtolower(trim($status));
        return $map[$key] ?? trim($status);
    }

    private function isActionableStatus(string $label): bool
    {
        return OperationalStatus::isActionablePhase($label);
    }

    private function roundedDelayMinutes(string $eta, string $scheduled): ?int
    {
        if ($eta === '' || $scheduled === '') return null;
        $left = strtotime($eta);
        $right = strtotime($scheduled);
        if ($left === false || $right === false) return null;
        return (int)(5 * round((($left - $right) / 60) / 5));
    }

    private function eventTag(string $eventType): string
    {
        return match ($eventType) {
            'eta_published', 'eta_changed' => 'eta',
            'status_published', 'status_changed' => 'st',
            'belt_assigned', 'belt_changed', 'belt_removed' => 'belt',
            'hall_changed' => 'belt',
            'departure_recorded' => 'dep',
            'arrival_recorded', 'arrival_reminder' => 'arr',
            'baggage_started', 'baggage_finished' => 'bag',
            default => preg_replace('/[^a-z0-9]+/i', '', $eventType) ?: 'evt',
        };
    }

    /** @param array<string,string> $snap */
    private function rememberWatchState(int $subscriptionId, int $flightId, array $snap): void
    {
        $states = $this->readWatchStates();
        $states[$this->watchPulseKey($subscriptionId, $flightId)] = $snap;
        if (count($states) > 400) {
            $states = array_slice($states, -200, null, true);
        }
        $this->writeJsonFile($this->watchStatePath(), $states);
    }

    private function forgetWatchState(int $subscriptionId, int $flightId): void
    {
        $states = $this->readWatchStates();
        unset($states[$this->watchPulseKey($subscriptionId, $flightId)]);
        $this->writeJsonFile($this->watchStatePath(), $states);
    }

    /** @return array<string,string>|null */
    private function readWatchState(int $subscriptionId, int $flightId): ?array
    {
        $row = $this->readWatchStates()[$this->watchPulseKey($subscriptionId, $flightId)] ?? null;
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function readWatchStates(): array
    {
        $path = $this->watchStatePath();
        if (!is_file($path)) return [];
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function watchStatePath(): string
    {
        return PROJECT_ROOT . '/storage/cache/watch-states.json';
    }

    /** @param array<string,mixed> $data */
    private function writeJsonFile(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) return;
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** @return array<string,string> */
    private function watchSummaryNotice(int $flightId): array
    {
        $ctx = $this->flightNoticeContext($flightId);
        if ($ctx === null) throw new RuntimeException('El vuelo ya no existe.');
        $depActual = (string)($ctx['airlabs_departure'] ?: $ctx['secondary_departure'] ?: '');
        $depPlan = (string)($ctx['scheduled_departure'] ?? '');
        $despegue = $depActual !== ''
            ? date('H:i', strtotime($depActual))
            : ($depPlan !== '' ? date('H:i', strtotime($depPlan)) : 'pendiente');
        $landed = (string)($ctx['actual_arrival'] ?: '');
        $eta = $this->preferredEta($ctx);
        $llegada = $landed !== ''
            ? date('H:i', strtotime($landed))
            : ($eta !== '' ? date('H:i', strtotime($eta)) : 'pendiente');
        $belt = $this->beltLabel($ctx['hall'] ?? null, $ctx['belt'] ?? null);
        return $this->watchNoticeBase($ctx, $flightId) + [
            'body' => 'Itinerario · Despegue ' . $despegue . ' · Llegada ' . $llegada . ' · Cinta ' . $belt,
            'tag' => 'matrix-watch-' . $flightId . '-sum',
        ];
    }

    /** @return array<string,string> */
    private function watchMinuteNotice(int $flightId): array
    {
        $notice = $this->watchSummaryNotice($flightId);
        $notice['body'] = date('H:i') . ' · ' . $notice['body'];
        $notice['tag'] = 'matrix-watch-' . $flightId . '-min';
        return $notice;
    }

    private function sendWatchFiveMinuteSummaries(): int
    {
        $stmt = $this->pdo->query(
            "SELECT s.id,s.id AS subscription_id,w.flight_id,w.updated_at,
                    s.endpoint,s.p256dh,s.auth_secret
             FROM flight_watches w
             INNER JOIN push_subscriptions s ON s.id=w.subscription_id AND s.active=1
             INNER JOIN flights f ON f.id=w.flight_id
             WHERE w.enabled=1
               AND TIMESTAMPDIFF(MINUTE,w.updated_at,NOW())>=3
               AND f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 12 HOUR)
                                           AND DATE_ADD(NOW(),INTERVAL 2 DAY)
             LIMIT 40"
        );
        $sent = 0;
        $done = $this->readWatchSummaries();
        foreach ($stmt->fetchAll() as $row) {
            if (!$this->isLivePushEndpoint((string)$row['endpoint'])) continue;
            $key = $this->watchSummaryKey((int)$row['subscription_id'], (int)$row['flight_id'], (string)$row['updated_at']);
            if (isset($done[$key])) continue;
            try {
                $this->deliverToRow($row, $this->watchSummaryNotice((int)$row['flight_id']));
                $done[$key] = time();
                $sent++;
            } catch (\Throwable $error) {
                $this->logPush('summary-fail flight=' . (int)$row['flight_id'] . ' sub=' . (int)$row['subscription_id'] . ' err=' . $error->getMessage());
            }
        }
        if ($sent > 0) {
            if (count($done) > 400) {
                asort($done);
                $done = array_slice($done, -200, null, true);
            }
            $this->writeJsonFile($this->watchSummaryPath(), $done);
        }
        return $sent;
    }

    private function watchSummaryKey(int $subscriptionId, int $flightId, string $updatedAt): string
    {
        return $subscriptionId . '-' . $flightId . '-' . (string)strtotime($updatedAt);
    }

    /** @return array<string,int> */
    private function readWatchSummaries(): array
    {
        $path = $this->watchSummaryPath();
        if (!is_file($path)) return [];
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string)$key] = (int)$value;
        }
        return $out;
    }

    private function watchSummaryPath(): string
    {
        return PROJECT_ROOT . '/storage/cache/watch-summaries.json';
    }

    private function sendWatchPulses(?int $onlySubscriptionId = null): int
    {
        $sql = "SELECT s.id,w.subscription_id,w.flight_id,s.endpoint,s.p256dh,s.auth_secret,
                    f.physical_flight,f.origin_name,f.flight_date,f.scheduled_arrival,f.is_canary,
                    (SELECT COALESCE(o.actual_arrival,o.eta) FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena'
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS aena_arrival,
                    (SELECT o.actual_arrival FROM observations o
                     WHERE o.flight_id=f.id AND o.actual_arrival IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS actual_arrival,
                    (SELECT o.belt FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena' AND NULLIF(o.belt,'') IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS belt,
                    (SELECT o.hall FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena' AND NULLIF(o.hall,'') IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS hall,
                    (SELECT o.baggage_state FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena'
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS baggage_state,
                    (SELECT COALESCE(o.actual_arrival,o.eta) FROM observations o
                     WHERE o.flight_id=f.id AND o.source <> 'aena'
                       AND (o.actual_arrival IS NOT NULL OR o.eta IS NOT NULL)
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS other_arrival
             FROM flight_watches w
             INNER JOIN push_subscriptions s ON s.id=w.subscription_id AND s.active=1
             INNER JOIN flights f ON f.id=w.flight_id
             WHERE w.enabled=1
               AND f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 12 HOUR)
                                           AND DATE_ADD(NOW(),INTERVAL 2 DAY)";
        if ($onlySubscriptionId !== null) {
            $sql .= ' AND s.id=' . (int)$onlySubscriptionId;
        }
        $sql .= ' LIMIT 80';
        $stmt = $this->pdo->query($sql);
        $bySubscription = [];
        foreach ($stmt->fetchAll() as $row) {
            $finished = in_array((string)$row['baggage_state'], ['finalizado', 'final'], true);
            $arrival = (string)($row['actual_arrival'] ?: $row['aena_arrival'] ?: $row['other_arrival'] ?: $row['scheduled_arrival']);
            $minutes = $this->minutesUntilArrival($arrival);
            if ($finished) continue;
            if ($row['actual_arrival'] && $minutes !== null && $minutes < -90) continue;
            if (!$this->isLivePushEndpoint((string)$row['endpoint'])) continue;
            $interval = $this->watchItineraryIntervalMinutes($minutes);
            if ($interval === null) continue;
            $row['interval'] = $interval;
            $bySubscription[(int)$row['subscription_id']][] = $row;
        }
        $sent = 0;
        foreach ($bySubscription as $subscriptionId => $flights) {
            if (!$this->claimWatchDigest($subscriptionId, $flights)) continue;
            try {
                $this->deliverToRow($flights[0], $this->watchDigestNotice($flights));
                $sent++;
            } catch (\Throwable $error) {
                $this->logPush('pulse-fail sub=' . $subscriptionId . ' err=' . $error->getMessage());
            }
        }
        return $sent;
    }

    /**
     * @param list<array<string,mixed>> $flights
     * @return array<string,string>
     */
    private function watchDigestNotice(array $flights): array
    {
        $lines = [];
        foreach (array_slice($flights, 0, 4) as $row) {
            $summary = $this->watchSummaryNotice((int)$row['flight_id']);
            $detail = preg_replace('/^Itinerario · /', '', (string)$summary['body']);
            $lines[] = $row['physical_flight'] . ' · ' . $detail;
        }
        if (count($flights) > 4) $lines[] = 'y ' . (count($flights) - 4) . ' más';
        $first = $flights[0];
        $title = count($flights) === 1
            ? (((int)$first['is_canary'] === 1 ? 'Canarias · ' : '') . $first['origin_name'] . ' · ' . $first['physical_flight'])
            : ('Vuelos vigilados · ' . count($flights));
        $date = substr((string)$first['flight_date'], 0, 10);
        $target = 'index.php?date=' . rawurlencode($date) . '&flight=' . (int)$first['flight_id'];
        return [
            'title' => $title,
            'body' => date('H:i') . ' · Itinerario · ' . implode(' · ', $lines),
            'target_url' => $target,
            'tag' => 'matrix-watch-itinerary',
        ];
    }

    private function minutesUntilArrival(string $arrival): ?int
    {
        if ($arrival === '') return null;
        $at = strtotime($arrival);
        return $at === false ? null : (int)round(($at - time()) / 60);
    }

    /** Última hora: 10 min. Últimos 30 min (y tras aterrizar): 5 min. Antes, nada periódico. */
    private function watchItineraryIntervalMinutes(?int $minutesToArrival): ?int
    {
        if ($minutesToArrival === null) return null;
        if ($minutesToArrival > 60) return null;
        return $minutesToArrival > 30 ? 10 : 5;
    }

    /**
     * Un aviso de itinerario por teléfono. Si algún vuelo de la ventana está
     * vencido, se envía el digest y se marca todo el lote (el más cercano impone 5 min).
     *
     * @param list<array<string,mixed>> $flights
     */
    private function claimWatchDigest(int $subscriptionId, array $flights): bool
    {
        $now = time();
        $interval = 10;
        foreach ($flights as $row) {
            $interval = min($interval, max(1, (int)($row['interval'] ?? 10)));
        }
        $key = 'digest-' . $subscriptionId;
        return $this->mutateJsonStamps($this->watchPulsePath(), static function (array &$pulses) use ($key, $now, $interval, $flights): bool {
            $last = (int)($pulses[$key] ?? 0);
            if ($last > $now - $interval * 60) return false;
            $due = false;
            foreach ($flights as $row) {
                $flightKey = ((int)$row['subscription_id']) . '-' . ((int)$row['flight_id']);
                $flightLast = (int)($pulses[$flightKey] ?? $last);
                if ($flightLast <= $now - max(1, (int)$row['interval']) * 60) $due = true;
            }
            if (!$due) return false;
            $pulses[$key] = $now;
            foreach ($flights as $row) {
                $pulses[((int)$row['subscription_id']) . '-' . ((int)$row['flight_id'])] = $now;
            }
            return true;
        });
    }

    private function watchPulseDue(int $subscriptionId, int $flightId, int $intervalMinutes): bool
    {
        $pulses = $this->readJsonStamps($this->watchPulsePath());
        $last = (int)($pulses[$this->watchPulseKey($subscriptionId, $flightId)] ?? 0);
        return $last <= time() - max(1, $intervalMinutes) * 60;
    }

    private function markWatchPulse(int $subscriptionId, int $flightId): void
    {
        $this->mutateJsonStamps($this->watchPulsePath(), function (array &$pulses) use ($subscriptionId, $flightId): bool {
            $pulses[$this->watchPulseKey($subscriptionId, $flightId)] = time();
            return true;
        });
    }

    private function noticeCooldownSeconds(string $tag): ?int
    {
        if ($tag === '' || str_contains($tag, '-itinerary') || str_contains($tag, '-min') || str_contains($tag, '-on') || str_contains($tag, '-force') || str_contains($tag, 'belt-critical')) {
            return null;
        }
        if (preg_match('/-(belt|arr|dep|bag)$/', $tag) === 1) return 30;
        if (preg_match('/-(eta|eta2|fs|st|feeder)(-|$)/', $tag) === 1) return 15 * 60;
        return 5 * 60;
    }

    private function noticeOnCooldown(int $subscriptionId, string $tag): bool
    {
        $seconds = $this->noticeCooldownSeconds($tag);
        if ($seconds === null) return false;
        $last = (int)($this->readJsonStamps($this->noticeCooldownPath())[$subscriptionId . ':' . $tag] ?? 0);
        return $last > time() - $seconds;
    }

    private function rememberNotice(int $subscriptionId, string $tag): void
    {
        if ($this->noticeCooldownSeconds($tag) === null) return;
        $key = $subscriptionId . ':' . $tag;
        $this->mutateJsonStamps($this->noticeCooldownPath(), static function (array &$stamps) use ($key): bool {
            $stamps[$key] = time();
            return true;
        });
    }

    /** @param callable(array<string,int>):bool $mutator */
    private function mutateJsonStamps(string $path, callable $mutator): bool
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) return false;
        $handle = fopen($path, 'c+');
        if ($handle === false) return false;
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }
        $raw = stream_get_contents($handle);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $stamps = [];
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $stamps[(string)$key] = (int)$value;
            }
        }
        $changed = (bool)$mutator($stamps);
        if ($changed) {
            if (count($stamps) > 400) {
                asort($stamps);
                $stamps = array_slice($stamps, -200, null, true);
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string)json_encode($stamps));
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        return $changed;
    }

    /** @return array<string,int> */
    private function readJsonStamps(string $path): array
    {
        if (!is_file($path)) return [];
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string)$key] = (int)$value;
        }
        return $out;
    }

    private function noticeCooldownPath(): string
    {
        return PROJECT_ROOT . '/storage/cache/watch-notice-cooldown.json';
    }

    private function watchPulseKey(int $subscriptionId, int $flightId): string
    {
        return $subscriptionId . '-' . $flightId;
    }

    private function watchPulsePath(): string
    {
        return PROJECT_ROOT . '/storage/cache/watch-pulses.json';
    }

    private function watchPulseLoopRunning(): bool
    {
        $path = PROJECT_ROOT . '/storage/cache/watch-pulse-loop.lock';
        if (!is_file($path)) return false;
        $fp = fopen($path, 'c+');
        if ($fp === false) return false;
        $busy = !flock($fp, LOCK_EX | LOCK_NB);
        if (!$busy) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $busy;
    }

    private function cliPhpBinary(): ?string
    {
        $candidates = [
            '/opt/plesk/php/8.3/bin/php',
            '/opt/plesk/php/8.2/bin/php',
            '/opt/plesk/php/8.1/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];
        if (PHP_BINARY !== '' && !str_contains(strtolower(PHP_BINARY), 'fpm')) {
            array_unshift($candidates, PHP_BINARY);
        }
        foreach ($candidates as $bin) {
            if (is_executable($bin)) return $bin;
        }
        return null;
    }

    private function canSpawnShell(): bool
    {
        if (!function_exists('exec')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    private function isLivePushEndpoint(string $endpoint): bool
    {
        $host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
        return $host !== '' && $host !== 'localhost' && !str_ends_with($host, '.invalid');
    }

    /** @param array<string,string> $notice @return array{ok:bool,http_status:int,encrypted:bool,sent_at:string} */
    private function deliverToDevice(string $deviceToken, array $notice): array
    {
        $subscriptionId = $this->subscriptionId($deviceToken);
        $stmt = $this->pdo->prepare('SELECT id,endpoint,p256dh,auth_secret FROM push_subscriptions WHERE id=:id AND active=1');
        $stmt->execute(['id' => $subscriptionId]);
        $subscription = $stmt->fetch();
        if (!$subscription) throw new RuntimeException('La suscripción de este dispositivo no está activa.');
        return $this->deliverToRow($subscription, $notice);
    }

    /**
     * @param array<string,mixed> $subscription
     * @param array<string,string> $notice
     * @return array{ok:bool,http_status:int,encrypted:bool,sent_at:string}
     */
    private function deliverToRow(array $subscription, array $notice): array
    {
        $subscriptionId = (int)$subscription['id'];
        $endpoint = (string)($subscription['endpoint'] ?? '');
        if ($endpoint === '') throw new RuntimeException('La suscripción de este dispositivo no está activa.');
        $tag = (string)($notice['tag'] ?? '');
        if ($tag !== '' && $this->noticeOnCooldown($subscriptionId, $tag)) {
            return [
                'ok' => true,
                'http_status' => 200,
                'encrypted' => true,
                'skipped' => true,
                'sent_at' => date('Y-m-d H:i:s'),
            ];
        }
        $delivery = $this->send($endpoint, (string)$subscription['p256dh'], (string)$subscription['auth_secret'], $notice);
        $status = $delivery['status'];
        if (in_array($status, [404, 410], true)) {
            $this->pdo->prepare('UPDATE push_subscriptions SET active=0,last_error=:error,updated_at=updated_at WHERE id=:id')
                ->execute(['error' => "Push HTTP {$status}", 'id' => $subscriptionId]);
            throw new RuntimeException('La suscripción del teléfono ha caducado. Pulsa de nuevo «Activar avisos» para renovarla.');
        }
        if ($status < 200 || $status >= 300) {
            $this->pdo->prepare('UPDATE push_subscriptions SET last_error=:error,updated_at=updated_at WHERE id=:id')
                ->execute(['error' => substr("Push HTTP {$status}", 0, 500), 'id' => $subscriptionId]);
            throw new RuntimeException("Push HTTP {$status}");
        }
        $this->pdo->prepare('UPDATE push_subscriptions SET last_push_at=NOW(),last_error=NULL,updated_at=updated_at WHERE id=:id')
            ->execute(['id' => $subscriptionId]);
        if ($tag !== '') $this->rememberNotice($subscriptionId, $tag);
        return [
            'ok' => true,
            'http_status' => $status,
            'encrypted' => $delivery['encrypted'],
            'http_version' => $delivery['http_version'] ?? null,
            'apple' => (bool)($delivery['apple'] ?? false),
            'sent_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function subscriptionId(string $token): int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new RuntimeException('Dispositivo no válido.');
        $stmt = $this->pdo->prepare('SELECT id FROM push_subscriptions WHERE device_token=:token AND active=1');
        $stmt->execute(['token' => $token]);
        $id = (int)$stmt->fetchColumn();
        if (!$id) throw new RuntimeException('Dispositivo no suscrito.');
        return $id;
    }

    /**
     * Envía el aviso cifrado dentro del propio push para que el service worker
     * lo muestre sin depender de una segunda petición al servidor. iOS revoca
     * las suscripciones que reciben pushes sin notificación visible.
     *
     * @param array<string,string> $notice
     * @return array{status:int,encrypted:bool,http_version:?int,apple:bool}
     */
    private function send(string $endpoint, string $p256dh, string $auth, array $notice): array
    {
        $payload = json_encode($notice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = $this->encryptPayload((string)$payload, $p256dh, $auth);
        $posted = $this->post($endpoint, $body, [
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
        ]);
        if (!empty($posted['apple']) && !$this->isHttp2($posted['http_version'] ?? null)) {
            throw new RuntimeException('Plesk/PHP envió el aviso a Apple por HTTP/1.1. El iPhone no lo entrega. En PHP de Plesk hace falta curl con HTTP/2 (nghttp2).');
        }
        return $posted + ['encrypted' => true];
    }

    /** RFC 8291 (aes128gcm) con un único registro. */
    public function encryptPayload(string $payload, string $p256dh, string $auth): string
    {
        $userPublic = $this->b64Decode($p256dh);
        $authSecret = $this->b64Decode($auth);
        if (strlen($userPublic) !== 65 || $userPublic[0] !== "\x04" || strlen($authSecret) < 16) {
            throw new RuntimeException('Claves de suscripción push no válidas.');
        }

        $local = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$local) throw new RuntimeException('OpenSSL no pudo crear la clave efímera.');
        $details = openssl_pkey_get_details($local);
        $localPublic = "\x04" . $this->pad32((string)($details['ec']['x'] ?? '')) . $this->pad32((string)($details['ec']['y'] ?? ''));

        $peer = openssl_pkey_get_public($this->p256PublicPem($userPublic));
        if (!$peer) throw new RuntimeException('Clave pública del dispositivo no válida.');
        $shared = openssl_pkey_derive($peer, $local, 32);
        if (!is_string($shared) || strlen($shared) !== 32) throw new RuntimeException('No se pudo derivar el secreto ECDH.');

        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $userPublic . $localPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) throw new RuntimeException('No se pudo cifrar el aviso push.');

        return $salt . pack('N', 4096) . chr(strlen($localPublic)) . $localPublic . $cipher . $tag;
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,http_version:?int,apple:bool}
     */
    private function post(string $endpoint, string $body, array $headers): array
    {
        $keys = $this->keys();
        $host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
        $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $header = $this->b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = $this->b64(json_encode(['aud' => $audience, 'exp' => time() + 43200, 'sub' => (string)Env::get('VAPID_SUBJECT', 'mailto:admin@ojito.top')]));
        $unsigned = $header . '.' . $claims;
        if (!openssl_sign($unsigned, $der, (string)$keys['private_pem'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el aviso push.');
        }
        $jwt = $unsigned . '.' . $this->b64($this->derToJose($der));
        $curl = curl_init($endpoint);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge([
                'TTL: 86400',
                'Urgency: high',
                'Authorization: vapid t=' . $jwt . ', k=' . $keys['public_key'],
            ], $headers),
        ];
        if (defined('CURL_HTTP_VERSION_2TLS')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        } elseif (defined('CURL_HTTP_VERSION_2_0')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
        }
        curl_setopt_array($curl, $options);
        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $version = curl_getinfo($curl, CURLINFO_HTTP_VERSION);
        $error = curl_error($curl);
        curl_close($curl);
        $apple = str_contains($host, 'push.apple.com');
        $this->logPush(sprintf(
            'host=%s status=%d http=%s bytes=%d apple=%s err=%s',
            $host,
            $status,
            (string)$version,
            strlen($body),
            $apple ? '1' : '0',
            $error !== '' ? $error : '-'
        ));
        if ($status === 0) {
            throw new RuntimeException('No se pudo contactar el servicio Push' . ($error !== '' ? ': ' . $error : '.'));
        }
        if ($apple && !$this->isHttp2($version)) {
            $cli = $this->postWithSystemCurl($endpoint, $body, $jwt, $keys['public_key']);
            if ($cli !== null) {
                $this->logPush(sprintf(
                    'apple-cli status=%d http=%s bytes=%d',
                    $cli['status'],
                    (string)$cli['http_version'],
                    strlen($body)
                ));
                return $cli;
            }
        }
        return ['status' => $status, 'http_version' => is_numeric($version) ? (int)$version : $version, 'apple' => $apple];
    }

    private function isHttp2(mixed $version): bool
    {
        if (is_int($version) || is_float($version)) {
            return (int)$version >= 3;
        }
        $text = strtolower(trim((string)$version));
        if ($text === '' || str_starts_with($text, '1')) {
            return false;
        }
        return str_starts_with($text, '2') || str_starts_with($text, '3') || str_contains($text, 'h2');
    }

    /**
     * Plesk a menudo enlaza PHP/cURL sin nghttp2. El curl del sistema sí suele
     * hablar HTTP/2, y Apple exige eso para Web Push.
     *
     * @return array{status:int,http_version:?string,apple:bool}|null
     */
    private function postWithSystemCurl(string $endpoint, string $body, string $jwt, string $publicKey): ?array
    {
        $bin = $this->http2CurlBinary();
        if ($bin === null) return null;
        $payloadFile = tempnam(sys_get_temp_dir(), 'wpush');
        if ($payloadFile === false) return null;
        file_put_contents($payloadFile, $body);
        $stderrFile = $payloadFile . '.err';
        try {
            $cmd = [
                $bin, '--http2', '-sS', '-o', '/dev/null', '-w', '%{http_code} %{http_version}',
                '--max-time', '20',
                '-X', 'POST',
                '-H', 'TTL: 86400',
                '-H', 'Urgency: high',
                '-H', 'Content-Encoding: aes128gcm',
                '-H', 'Content-Type: application/octet-stream',
                '-H', 'Authorization: vapid t=' . $jwt . ', k=' . $publicKey,
                '--data-binary', '@' . $payloadFile,
                $endpoint,
            ];
            $process = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $stderrFile, 'w'],
            ], $pipes, null, null);
            if (!is_resource($process)) return null;
            fclose($pipes[0]);
            $output = trim((string)stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            $code = proc_close($process);
            if ($code !== 0 || $output === '') return null;
            $parts = preg_split('/\s+/', $output) ?: [];
            $status = (int)($parts[0] ?? 0);
            $httpVersion = $parts[1] ?? '';
            if ($status < 200) return null;
            return ['status' => $status, 'http_version' => $httpVersion !== '' ? $httpVersion : '2', 'apple' => true];
        } finally {
            @unlink($payloadFile);
            @unlink($stderrFile);
        }
    }

    private function http2CurlBinary(): ?string
    {
        static $cached = false;
        static $path = null;
        if ($cached) return $path;
        $cached = true;
        foreach (['/usr/bin/curl', '/usr/local/bin/curl', 'curl'] as $candidate) {
            if ($candidate !== 'curl' && !is_executable($candidate)) continue;
            $lines = [];
            exec(escapeshellarg($candidate) . ' --version 2>/dev/null', $lines, $code);
            $line = strtolower(implode(' ', $lines));
            if ($code === 0 && (str_contains($line, 'http2') || str_contains($line, 'nghttp2'))) {
                $path = $candidate;
                return $path;
            }
        }
        return null;
    }

    private function logPush(string $line): void
    {
        $file = PROJECT_ROOT . '/storage/logs/push.log';
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) return;
        @file_put_contents($file, date('c') . ' ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function p256PublicPem(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function pad32(string $value): string
    {
        return str_pad($value, 32, "\0", STR_PAD_LEFT);
    }

    private function b64Decode(string $value): string
    {
        $value = strtr(trim($value), '-_', '+/');
        $decoded = base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? '' : $decoded;
    }

    /** @return array{private_pem:string,public_key:string} */
    private function keys(): array
    {
        if (is_file($this->keyFile)) {
            $stored = json_decode((string)file_get_contents($this->keyFile), true);
            if (is_array($stored) && !empty($stored['private_pem']) && !empty($stored['public_key'])) return $stored;
        }
        $directory = dirname($this->keyFile);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('No se pudo crear storage/keys.');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$key || !openssl_pkey_export($key, $privatePem)) throw new RuntimeException('OpenSSL no pudo crear las claves VAPID.');
        $details = openssl_pkey_get_details($key);
        $x = $details['ec']['x'] ?? null; $y = $details['ec']['y'] ?? null;
        if (!is_string($x) || !is_string($y)) throw new RuntimeException('PHP/OpenSSL no expuso la clave pública EC.');
        $stored = ['private_pem' => $privatePem, 'public_key' => $this->b64("\x04" . $this->pad32($x) . $this->pad32($y))];
        file_put_contents($this->keyFile, json_encode($stored, JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($this->keyFile, 0600);
        return $stored;
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function derToJose(string $der): string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) throw new RuntimeException('Firma ECDSA no válida.');
        $this->readLength($der, $offset);
        if (ord($der[$offset++]) !== 0x02) throw new RuntimeException('Firma ECDSA no válida.');
        $rLength = $this->readLength($der, $offset); $r = substr($der, $offset, $rLength); $offset += $rLength;
        if (ord($der[$offset++]) !== 0x02) throw new RuntimeException('Firma ECDSA no válida.');
        $sLength = $this->readLength($der, $offset); $s = substr($der, $offset, $sLength);
        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    private function readLength(string $data, int &$offset): int
    {
        $length = ord($data[$offset++]);
        if (($length & 0x80) === 0) return $length;
        $bytes = $length & 0x7f; $length = 0;
        while ($bytes-- > 0) $length = ($length << 8) | ord($data[$offset++]);
        return $length;
    }
}
