<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\Response;
use SevillaMatrix\Sync\FlightReconciliationService;

if (!Auth::check()) Response::json(['error' => 'No autorizado.'], 401);
$input = Response::input();
if (!Auth::verifyCsrf($input['csrf'] ?? null)) Response::json(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
$flightId = filter_var($input['flight_id'] ?? null, FILTER_VALIDATE_INT);
if (!$flightId) Response::json(['error' => 'Selecciona un vuelo.'], 422);

try {
    $normalize = static fn(?string $value): ?string => $value ? str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '') : null;
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        "SELECT f.*,
                GROUP_CONCAT(DISTINCT fc.flight_code ORDER BY fc.flight_code SEPARATOR ',') AS codeshares
         FROM flights f LEFT JOIN flight_codes fc ON fc.flight_id=f.id
         WHERE f.id=:id GROUP BY f.id"
    );
    $stmt->execute(['id' => (int)$flightId]);
    $flight = $stmt->fetch();
    if (!$flight) Response::json(['error' => 'El vuelo seleccionado ya no existe.'], 404);

    $record = [
        'source_key' => implode('|', [$flight['flight_date'], $flight['physical_flight'], $flight['origin_iata'], 'SVQ']),
        'flight_date' => $flight['flight_date'],
        'physical_flight' => $flight['physical_flight'],
        'origin_iata' => $flight['origin_iata'],
        'origin_name' => $flight['origin_name'],
        'scheduled_arrival' => $flight['scheduled_arrival'],
        'codeshares' => $flight['codeshares'] ? explode(',', (string)$flight['codeshares']) : [],
        'observed_at' => date('Y-m-d H:i:s'),
        'status' => trim((string)($input['status'] ?? '')),
        'eta' => $normalize($input['eta'] ?? null),
        'actual_arrival' => $normalize($input['actual_arrival'] ?? null),
        'hall' => trim((string)($input['hall'] ?? '')), 'belt' => trim((string)($input['belt'] ?? '')),
        'gate' => trim((string)($input['gate'] ?? '')),
        'baggage_state' => $input['baggage_state'] ?? 'pendiente',
        'occupancy_level' => $input['occupancy_level'] ?? 'no_verificable',
        'confidence' => 'confirmado',
        'reason_code' => 'manual_aena_capture',
        'reason_detail' => 'Observación de Aena transcrita por un administrador; la causa operativa solo consta si Aena la publica.',
        'raw_data' => ['transport' => 'admin_web', 'form' => 'aena_observation'],
    ];
    $result = (new FlightReconciliationService($pdo))->sync([$record], [
        'provider' => 'aena', 'mode' => 'manual', 'observed_at' => $record['observed_at'],
        'complete' => false, 'metadata' => ['transport' => 'admin_web'],
    ]);
    Response::json(['ok' => true, 'result' => $result], 201);
} catch (Throwable $error) { Response::json(['error' => $error->getMessage()], 500); }
