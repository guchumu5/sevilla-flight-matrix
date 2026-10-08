<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\Response;

$configured = trim((string)Env::get('AENA_INGEST_TOKEN', ''));
$authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$provided = trim((string)($_SERVER['HTTP_X_MATRIX_TOKEN'] ?? ''));
if ($provided === '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    $provided = trim($matches[1]);
}
if (strlen($configured) < 24 || $provided === '' || !hash_equals($configured, $provided)) {
    Response::json(['error' => 'Credencial de automatización no válida.'], 401);
}

try {
    $pdo = Database::connection();
    $tables = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE()
           AND table_name IN ('push_subscriptions','flight_watches','flightaware_snapshots')"
    )->fetchColumn();
    if ($tables < 3) {
        Response::json([
            'ok' => true,
            'configured' => false,
            'flights' => [],
            'message' => 'Aplica la actualización MySQL 20261008_007_flightaware_web.',
        ]);
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $stmt = $pdo->query(
            "SELECT DISTINCT f.id,f.flight_date,f.physical_flight,f.origin_iata,f.origin_name,
                    f.scheduled_arrival,
                    CASE WHEN fw.flight_id IS NOT NULL THEN 1 ELSE 0 END AS individual_watch,
                    CASE WHEN f.is_canary=1 AND canary.enabled=1 THEN 1 ELSE 0 END AS canary_watch
             FROM flights f
             LEFT JOIN (
               SELECT DISTINCT w.flight_id FROM flight_watches w
               INNER JOIN push_subscriptions s ON s.id=w.subscription_id
               WHERE w.enabled=1 AND s.active=1
             ) fw ON fw.flight_id=f.id
             LEFT JOIN (
               SELECT EXISTS(SELECT 1 FROM push_subscriptions WHERE active=1 AND watch_canary_all=1) AS enabled
             ) canary ON 1=1
             WHERE f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 3 HOUR) AND DATE_ADD(NOW(),INTERVAL 8 HOUR)
               AND (fw.flight_id IS NOT NULL OR (f.is_canary=1 AND canary.enabled=1))
             ORDER BY individual_watch DESC,f.scheduled_arrival
             LIMIT 8"
        );
        Response::json([
            'ok' => true,
            'configured' => true,
            'cadence_minutes' => 10,
            'flights' => $stmt->fetchAll(),
        ]);
    }

    if ($method !== 'POST') Response::json(['error' => 'Método no permitido.'], 405);
    $input = Response::input();
    $records = $input['snapshots'] ?? null;
    if (!is_array($records) || !array_is_list($records) || count($records) > 8) {
        Response::json(['error' => 'snapshots debe ser una lista de hasta 8 registros.'], 422);
    }

    $lookup = $pdo->prepare('SELECT physical_flight FROM flights WHERE id=:id');
    $latest = $pdo->prepare(
        'SELECT status_text,aircraft_type,altitude_ft,speed_mph,distance_mi,duration_text,departure_text,arrival_text
         FROM flightaware_snapshots WHERE flight_id=:flight_id ORDER BY observed_at DESC,id DESC LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO flightaware_snapshots
         (flight_id,observed_at,public_url,status_text,aircraft_type,altitude_ft,speed_mph,distance_mi,
          duration_text,departure_text,arrival_text,raw_excerpt)
         VALUES
         (:flight_id,:observed_at,:public_url,:status_text,:aircraft_type,:altitude_ft,:speed_mph,:distance_mi,
          :duration_text,:departure_text,:arrival_text,:raw_excerpt)'
    );
    $aircraft = $pdo->prepare(
        'UPDATE flights SET aircraft_type=COALESCE(aircraft_type,:aircraft_type) WHERE id=:id'
    );
    $saved = $unchanged = 0;
    foreach ($records as $record) {
        if (!is_array($record)) continue;
        $flightId = filter_var($record['flight_id'] ?? null, FILTER_VALIDATE_INT);
        $code = strtoupper(trim((string)($record['physical_flight'] ?? '')));
        if (!$flightId || !preg_match('/^[A-Z0-9]{2,8}$/', $code)) continue;
        $lookup->execute(['id' => $flightId]);
        if (strtoupper((string)$lookup->fetchColumn()) !== $code) continue;

        $value = static fn(string $key, int $max = 120): ?string => isset($record[$key]) && trim((string)$record[$key]) !== ''
            ? substr(trim((string)$record[$key]), 0, $max) : null;
        $number = static fn(string $key, int $min, int $max): ?int => isset($record[$key]) && is_numeric($record[$key])
            ? max($min, min($max, (int)round((float)$record[$key]))) : null;
        $observed = new DateTimeImmutable((string)($record['observed_at'] ?? 'now'));
        $normalized = [
            'status_text' => $value('status_text'),
            'aircraft_type' => $value('aircraft_type'),
            'altitude_ft' => $number('altitude_ft', 0, 70000),
            'speed_mph' => $number('speed_mph', 0, 1000),
            'distance_mi' => $number('distance_mi', 0, 20000),
            'duration_text' => $value('duration_text', 40),
            'departure_text' => $value('departure_text', 80),
            'arrival_text' => $value('arrival_text', 80),
        ];
        $latest->execute(['flight_id' => $flightId]);
        $previous = $latest->fetch();
        $same = is_array($previous);
        foreach ($normalized as $key => $currentValue) {
            if ((string)($previous[$key] ?? '') !== (string)($currentValue ?? '')) {
                $same = false;
                break;
            }
        }
        if ($same) {
            $unchanged++;
            continue;
        }
        $insert->execute($normalized + [
            'flight_id' => $flightId,
            'observed_at' => $observed->format('Y-m-d H:i:s'),
            'public_url' => 'https://www.flightaware.com/live/flight/' . rawurlencode($code),
            'raw_excerpt' => $value('raw_excerpt', 8000),
        ]);
        if ($normalized['aircraft_type']) {
            $aircraft->execute(['aircraft_type' => $normalized['aircraft_type'], 'id' => $flightId]);
        }
        $saved++;
    }
    Response::json(['ok' => true, 'saved' => $saved, 'unchanged' => $unchanged]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
