<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Response;

if (!Auth::check()) Response::json(['error' => 'No autorizado.'], 401);
$input = Response::input();
if (!Auth::verifyCsrf($input['csrf'] ?? null)) Response::json(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
$flightId = filter_var($input['flight_id'] ?? null, FILTER_VALIDATE_INT);
if (!$flightId) Response::json(['error' => 'Selecciona un vuelo.'], 422);

try {
    $normalize = static fn(?string $value): ?string => $value ? str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '') : null;
    $id = (new FlightRepository(Database::connection()))->addObservation((int)$flightId, [
        'source' => 'aena', 'observed_at' => date('Y-m-d H:i:s'),
        'status' => trim((string)($input['status'] ?? '')),
        'eta' => $normalize($input['eta'] ?? null), 'actual_departure' => null,
        'actual_arrival' => $normalize($input['actual_arrival'] ?? null),
        'hall' => trim((string)($input['hall'] ?? '')), 'belt' => trim((string)($input['belt'] ?? '')),
        'gate' => trim((string)($input['gate'] ?? '')), 'stand' => null,
        'baggage_state' => $input['baggage_state'] ?? 'pendiente',
        'occupancy_level' => $input['occupancy_level'] ?? 'no_verificable', 'raw_data' => null,
    ]);
    Response::json(['ok' => true, 'observation_id' => $id], 201);
} catch (Throwable $error) { Response::json(['error' => $error->getMessage()], 500); }

