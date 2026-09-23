<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Response;

if (!Auth::check()) Response::json(['error' => 'No autorizado.'], 401);
$input = Response::input();
if (!Auth::verifyCsrf($input['csrf'] ?? null)) Response::json(['error' => 'La sesión ha caducado.'], 419);

$date = trim((string)($input['flight_date'] ?? ''));
$time = trim((string)($input['scheduled_time'] ?? ''));
$flightCode = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($input['physical_flight'] ?? '')));
$originIata = strtoupper(preg_replace('/[^A-Z]/i', '', (string)($input['origin_iata'] ?? '')));
$originName = trim((string)($input['origin_name'] ?? ''));
$traffic = (string)($input['traffic_class'] ?? 'desconocido');

if (!DateTimeImmutable::createFromFormat('!Y-m-d', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
    Response::json(['error' => 'Fecha u hora no válida.'], 422);
}
if ($flightCode === '' || strlen($originIata) !== 3 || $originName === '') {
    Response::json(['error' => 'Completa vuelo, código IATA y origen.'], 422);
}
if (!in_array($traffic, ['domestico','schengen','no_schengen','desconocido'], true)) $traffic = 'desconocido';

try {
    $pdo = Database::connection();
    $repo = new FlightRepository($pdo);
    $isCanary = in_array($originIata, ['LPA','TFN','TFS','ACE','FUE'], true) ? 1 : 0;
    $flightId = $repo->upsertFlight([
        'flight_date' => $date,
        'physical_flight' => $flightCode,
        'origin_iata' => $originIata,
        'origin_name' => $originName,
        'scheduled_arrival' => "{$date} {$time}:00",
        'traffic_class' => $traffic,
        'border_control' => $traffic === 'no_schengen' ? 1 : 0,
        'is_canary' => $isCanary,
        'aircraft_registration' => null,
        'aircraft_icao24' => null,
        'aircraft_type' => null,
        'capacity' => !empty($input['capacity']) ? (int)$input['capacity'] : null,
    ]);
    $codeshare = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($input['codeshare'] ?? '')));
    if ($codeshare !== '') {
        $stmt = $pdo->prepare('INSERT IGNORE INTO flight_codes (flight_id, flight_code) VALUES (?, ?)');
        $stmt->execute([$flightId, $codeshare]);
    }
    Response::json(['ok' => true, 'flight_id' => $flightId], 201);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}

