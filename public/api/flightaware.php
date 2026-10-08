<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\Response;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST' ? Response::input() : [];

try {
    $pdo = Database::connection();

    // El navegador únicamente crea una petición de lectura limitada.
    if ($method === 'POST' && (string)($input['action'] ?? '') === 'request') {
        $ready = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN ('flightaware_snapshots','flightaware_requests')"
        )->fetchColumn() === 2;
        if (!$ready) Response::json(['error' => 'Aplica las actualizaciones MySQL 007 y 008.'], 503);

        $flightId = filter_var($input['flight_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$flightId) Response::json(['error' => 'Vuelo no válido.'], 422);
        $flight = $pdo->prepare(
            'SELECT id,physical_flight FROM flights WHERE id=:id
             AND scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 1 DAY) AND DATE_ADD(NOW(),INTERVAL 2 DAY)'
        );
        $flight->execute(['id' => $flightId]);
        $row = $flight->fetch();
        if (!$row) Response::json(['error' => 'El vuelo no está dentro de la ventana consultable.'], 422);

        $previous = $pdo->prepare('SELECT requested_at,status FROM flightaware_requests WHERE flight_id=:id');
        $previous->execute(['id' => $flightId]);
        $before = $previous->fetch();
        $recent = is_array($before) && strtotime((string)$before['requested_at']) >= time() - 300;
        if (!$recent) {
            $queue = $pdo->prepare(
                "INSERT INTO flightaware_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
                 VALUES (:id,NOW(),NULL,1,'queued',NULL)
                 ON DUPLICATE KEY UPDATE requested_at=NOW(),processed_at=NULL,
                   request_count=request_count+1,status='queued',last_error=NULL"
            );
            $queue->execute(['id' => $flightId]);
        }
        Response::json([
            'ok' => true,
            'queued' => !$recent,
            'flight' => (string)$row['physical_flight'],
            'message' => $recent
                ? 'La lectura FlightAware ya estaba solicitada recientemente.'
                : 'Lectura FlightAware solicitada. Se procesará en el siguiente ciclo.',
        ]);
    }

    $configured = trim((string)Env::get('AENA_INGEST_TOKEN', ''));
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $provided = trim((string)($_SERVER['HTTP_X_MATRIX_TOKEN'] ?? ''));
    if ($provided === '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        $provided = trim($matches[1]);
    }
    if (strlen($configured) < 24 || $provided === '' || !hash_equals($configured, $provided)) {
        Response::json(['error' => 'Credencial de automatización no válida.'], 401);
    }

    $tables = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE()
           AND table_name IN ('flightaware_snapshots','flightaware_requests')"
    )->fetchColumn();
    if ($tables < 2) {
        Response::json([
            'ok' => true,
            'configured' => false,
            'flights' => [],
            'message' => 'Aplica las actualizaciones MySQL 007 y 008 de FlightAware.',
        ]);
    }

    if ($method === 'GET') {
        $stmt = $pdo->query(
            "SELECT f.id,f.flight_date,f.physical_flight,f.origin_iata,f.origin_name,f.scheduled_arrival
             FROM flightaware_requests r
             INNER JOIN flights f ON f.id=r.flight_id
             WHERE r.status='queued' AND r.requested_at>=DATE_SUB(NOW(),INTERVAL 30 MINUTE)
             ORDER BY r.requested_at LIMIT 8"
        );
        Response::json([
            'ok' => true,
            'configured' => true,
            'on_demand' => true,
            'cadence_minutes' => 5,
            'flights' => $stmt->fetchAll(),
        ]);
    }

    if ($method !== 'POST') Response::json(['error' => 'Método no permitido.'], 405);
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
    $aircraft = $pdo->prepare('UPDATE flights SET aircraft_type=COALESCE(aircraft_type,:aircraft_type) WHERE id=:id');
    $completed = $pdo->prepare(
        "UPDATE flightaware_requests SET processed_at=NOW(),status='processed',last_error=NULL WHERE flight_id=:id"
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
            $completed->execute(['id' => $flightId]);
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
        $completed->execute(['id' => $flightId]);
        $saved++;
    }
    Response::json(['ok' => true, 'saved' => $saved, 'unchanged' => $unchanged]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
