<?php
declare(strict_types=1);

namespace SevillaMatrix\Notifications;

use PDO;
use RuntimeException;
use SevillaMatrix\Env;

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

    public function setWatch(string $deviceToken, int $flightId, bool $enabled): void
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
            ], $watchedFlights->fetchAll()),
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
        $stmt = $this->pdo->prepare('SELECT endpoint,p256dh,auth_secret FROM push_subscriptions WHERE id=:id AND active=1');
        $stmt->execute(['id' => $subscriptionId]);
        $subscription = $stmt->fetch();
        $endpoint = (string)($subscription['endpoint'] ?? '');
        if ($endpoint === '') throw new RuntimeException('La suscripción de este dispositivo no está activa.');

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
            $delivery = $this->send($endpoint, (string)$subscription['p256dh'], (string)$subscription['auth_secret'], $notice + ['tag' => 'matrix-test']);
            $status = $delivery['status'];
            if (in_array($status, [404, 410], true)) {
                $this->pdo->prepare('UPDATE push_subscriptions SET active=0,last_error=:error,updated_at=updated_at WHERE id=:id')
                    ->execute(['error' => "Push HTTP {$status}", 'id' => $subscriptionId]);
                throw new RuntimeException('La suscripción del teléfono ha caducado. Pulsa de nuevo «Activar avisos» para renovarla.');
            }
            if ($status < 200 || $status >= 300) throw new RuntimeException("Push HTTP {$status}");
            $this->pdo->prepare('UPDATE push_subscriptions SET last_push_at=NOW(),last_error=NULL,updated_at=updated_at WHERE id=:id')
                ->execute(['id' => $subscriptionId]);
            return ['ok' => true, 'http_status' => $status, 'encrypted' => $delivery['encrypted'], 'sent_at' => date('Y-m-d H:i:s')];
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
        $watched = "'departure_recorded','belt_assigned','belt_changed','belt_removed','arrival_recorded'," .
            "'baggage_started','eta_published','eta_changed','flight_cancelled','flight_missing'," .
            "'flight_withdrawn','flight_reappeared','arrival_reminder','schedule_changed'," .
            "'hall_changed','gate_changed','stand_changed','baggage_finished'";
        $this->pdo->exec(
            "UPDATE push_outbox o
             JOIN flight_events e ON e.id=o.flight_event_id
             SET o.attempts=4,o.last_error='Descartado por la política de avisos operativos'
             WHERE o.pushed_at IS NULL AND o.attempts<4 AND e.event_type NOT IN ({$watched})"
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
        $this->pdo->exec(
            "INSERT IGNORE INTO push_outbox (subscription_id,flight_event_id,title,body,target_url)
             SELECT s.id,e.id,
                    CONCAT(IF(f.is_canary=1,'Canarias · ',''),CONVERT(f.origin_name USING utf8mb4),' · ',CONVERT(f.physical_flight USING utf8mb4)),
                    LEFT(CASE e.event_type
                      WHEN 'departure_recorded' THEN CONCAT('✈️ Despega',IF(NULLIF(e.after_value,'') IS NULL,'',CONCAT(' · ',DATE_FORMAT(e.after_value,'%H:%i'))))
                      WHEN 'belt_assigned' THEN CONCAT('🧳 Asignación de cinta · ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'pendiente de número'))
                      WHEN 'belt_changed' THEN CONCAT('⚠️ Cambio de cinta · ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' → ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'—'))
                      WHEN 'belt_removed' THEN CONCAT('⚠️ Aena retiró la cinta ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' · nueva asignación pendiente')
                      WHEN 'eta_published' THEN CONCAT(
                        '⏱️ Nueva ETA ',DATE_FORMAT(e.after_value,'%H:%i'),' · ',
                        IF(TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value)>0,'+',''),
                        TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value),' min sobre programada'
                      )
                      WHEN 'eta_changed' THEN CONCAT(
                        '⏱️ ETA ',DATE_FORMAT(e.after_value,'%H:%i'),' · ',
                        IF(TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value)>0,'+',''),
                        TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value),' min sobre programada',
                        ' · antes ',DATE_FORMAT(e.before_value,'%H:%i')
                      )
                      WHEN 'schedule_changed' THEN CONCAT('🗓️ Cambio de programación · ',DATE_FORMAT(e.before_value,'%H:%i'),' → ',DATE_FORMAT(e.after_value,'%H:%i'))
                      WHEN 'hall_changed' THEN CONCAT('⚠️ Cambio de sala · ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' → ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'—'))
                      WHEN 'gate_changed' THEN CONCAT('🚪 Cambio de puerta · ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' → ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'—'))
                      WHEN 'stand_changed' THEN CONCAT('🅿️ Cambio de posición · ',COALESCE(NULLIF(CONVERT(e.before_value USING utf8mb4),''),'—'),' → ',COALESCE(NULLIF(CONVERT(e.after_value USING utf8mb4),''),'—'))
                      WHEN 'arrival_recorded' THEN CONCAT('🛬 Aterriza',IF(NULLIF(e.after_value,'') IS NULL,'',CONCAT(' · ',DATE_FORMAT(e.after_value,'%H:%i'))))
                      WHEN 'arrival_reminder' THEN CONCAT('🚗 Aviso 40 min · llegada prevista ',DATE_FORMAT(e.after_value,'%H:%i'))
                      WHEN 'flight_cancelled' THEN '🚫 Vuelo cancelado por la fuente'
                      WHEN 'flight_missing' THEN '⚠️ El vuelo dejó de aparecer temporalmente en la fuente'
                      WHEN 'flight_withdrawn' THEN '🚫 Vuelo retirado tras varias comprobaciones'
                      WHEN 'flight_reappeared' THEN '✅ El vuelo vuelve a aparecer en la fuente'
                      WHEN 'baggage_started' THEN CONCAT(
                        '🧳 En la cinta',
                        COALESCE((SELECT CONCAT(' · ',IF(NULLIF(o.hall,'') IS NULL,'',CONCAT(CONVERT(o.hall USING utf8mb4),'/')),CONVERT(o.belt USING utf8mb4))
                                  FROM observations o
                                  WHERE o.flight_id=f.id AND NULLIF(o.belt,'') IS NOT NULL
                                  ORDER BY (o.source='aena') DESC,o.observed_at DESC,o.id DESC LIMIT 1),'')
                      )
                      WHEN 'baggage_finished' THEN '✅ Entrega de equipaje finalizada'
                    END,500),
                    CONCAT('index.php?date=',DATE_FORMAT(f.flight_date,'%Y-%m-%d'),'&flight=',f.id)
             FROM push_subscriptions s
             JOIN flight_events e ON e.detected_at>=s.created_at AND e.event_type IN ({$watched})
             JOIN flights f ON f.id=e.flight_id
             LEFT JOIN flight_watches w ON w.subscription_id=s.id AND w.flight_id=f.id AND w.enabled=1
             WHERE s.active=1
               AND (
                 (w.id IS NOT NULL AND e.detected_at>=w.updated_at)
                 OR (s.watch_canary_all=1 AND f.is_canary=1 AND e.detected_at>=s.updated_at)
               )
               AND (e.event_type IN ('departure_recorded','arrival_recorded','arrival_reminder') OR e.source='aena')
               AND NOT (
                 e.event_type='eta_changed'
                 AND ABS(TIMESTAMPDIFF(MINUTE,e.before_value,e.after_value))<5
               )
               AND NOT (
                 e.event_type='eta_published'
                 AND ABS(TIMESTAMPDIFF(MINUTE,f.scheduled_arrival,e.after_value))<5
               )"
        );

        $stmt = $this->pdo->prepare(
            'SELECT o.id,o.subscription_id,o.title,o.body,o.target_url,s.endpoint,s.p256dh,s.auth_secret
             FROM push_outbox o
             JOIN push_subscriptions s ON s.id=o.subscription_id
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
                $delivery = $this->send((string)$row['endpoint'], (string)$row['p256dh'], (string)$row['auth_secret'], [
                    'title' => (string)$row['title'],
                    'body' => (string)$row['body'],
                    'target_url' => (string)$row['target_url'],
                    'tag' => 'matrix-outbox-' . (int)$row['id'],
                ]);
                $status = $delivery['status'];
                if (in_array($status, [404, 410], true)) {
                    $this->pdo->prepare('UPDATE push_subscriptions SET active=0,last_error=:error,updated_at=updated_at WHERE id=:id')
                        ->execute(['error' => "Push HTTP {$status}", 'id' => $row['subscription_id']]);
                    throw new RuntimeException("Suscripción caducada ({$status}).");
                }
                if ($status < 200 || $status >= 300) throw new RuntimeException("Push HTTP {$status}");
                if ($delivery['encrypted']) {
                    $this->pdo->prepare('UPDATE push_outbox SET displayed_at=NOW() WHERE id=:id AND displayed_at IS NULL')
                        ->execute(['id' => $row['id']]);
                }
                $this->pdo->prepare('UPDATE push_subscriptions SET last_push_at=NOW(),last_error=NULL,updated_at=updated_at WHERE id=:id')
                    ->execute(['id' => $row['subscription_id']]);
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
        return ['queued' => (int)$this->pdo->query('SELECT COUNT(*) FROM push_outbox WHERE pushed_at IS NULL')->fetchColumn(), 'sent' => $sent, 'failed' => $failed];
    }

    private function createArrivalReminders(): void
    {
        // Se crea un único hito auditable por vuelo. La ventana de cinco
        // minutos mantiene el aviso cerca de los 40 incluso con un cron breve.
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
                        (SELECT COALESCE(o.actual_arrival,o.eta)
                         FROM observations o
                         WHERE o.flight_id=f.id AND o.source='aena'
                           AND (o.actual_arrival IS NOT NULL OR o.eta IS NOT NULL)
                         ORDER BY o.observed_at DESC,o.id DESC LIMIT 1),
                        (SELECT COALESCE(o.actual_arrival,o.eta)
                         FROM observations o
                         WHERE o.flight_id=f.id AND o.source<>'aena'
                           AND (o.actual_arrival IS NOT NULL OR o.eta IS NOT NULL)
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
             WHERE TIMESTAMPDIFF(MINUTE,NOW(),due.arrival_at) BETWEEN 36 AND 40"
        );
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
     * @return array{status:int,encrypted:bool}
     */
    private function send(string $endpoint, string $p256dh, string $auth, array $notice): array
    {
        $payload = json_encode($notice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $body = $this->encryptPayload((string)$payload, $p256dh, $auth);
        } catch (\Throwable) {
            return ['status' => $this->post($endpoint, '', []), 'encrypted' => false];
        }
        return [
            'status' => $this->post($endpoint, $body, [
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
            ]),
            'encrypted' => true,
        ];
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

    /** @param list<string> $headers */
    private function post(string $endpoint, string $body, array $headers): int
    {
        $keys = $this->keys();
        $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $header = $this->b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = $this->b64(json_encode(['aud' => $audience, 'exp' => time() + 43200, 'sub' => (string)Env::get('VAPID_SUBJECT', 'mailto:admin@ojito.top')]));
        $unsigned = $header . '.' . $claims;
        if (!openssl_sign($unsigned, $der, (string)$keys['private_pem'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el aviso push.');
        }
        $jwt = $unsigned . '.' . $this->b64($this->derToJose($der));
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge([
                'TTL: 3600', 'Urgency: high', 'Content-Length: ' . strlen($body),
                'Authorization: vapid t=' . $jwt . ', k=' . $keys['public_key'],
            ], $headers),
        ]);
        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status;
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
