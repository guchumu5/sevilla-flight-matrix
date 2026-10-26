<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Operations\FlightFocusService;
use SevillaMatrix\Response;

try {
    $flightId = filter_input(INPUT_GET, 'flight_id', FILTER_VALIDATE_INT);
    if (!$flightId) Response::json(['error' => 'Vuelo no válido.'], 422);
    $pdo = Database::connection();
    $repository = new FlightRepository($pdo);
    $aircraft = $pdo->prepare(
        'SELECT aircraft_registration,aircraft_icao24,aircraft_type FROM flights WHERE id=:flight_id'
    );
    $aircraft->execute(['flight_id' => (int)$flightId]);
    $request = null;
    try {
        $requestStmt = $pdo->prepare(
            'SELECT status,requested_at,processed_at,last_error FROM flightaware_requests WHERE flight_id=:flight_id'
        );
        $requestStmt->execute(['flight_id' => (int)$flightId]);
        $request = $requestStmt->fetch() ?: null;
    } catch (Throwable) {
    }
    Response::json([
        'history' => $repository->history((int)$flightId),
        'flightaware' => $repository->flightAwareSnapshot((int)$flightId),
        'flightstats' => $repository->flightStatsSnapshot((int)$flightId),
        'aircraft' => $aircraft->fetch() ?: null,
        'flightaware_request' => $request,
        'focus' => (new FlightFocusService($pdo))->status((int)$flightId),
    ]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}

