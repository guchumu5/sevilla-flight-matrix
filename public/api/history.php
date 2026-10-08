<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Response;

try {
    $flightId = filter_input(INPUT_GET, 'flight_id', FILTER_VALIDATE_INT);
    if (!$flightId) Response::json(['error' => 'Vuelo no válido.'], 422);
    $repository = new FlightRepository(Database::connection());
    Response::json([
        'history' => $repository->history((int)$flightId),
        'flightaware' => $repository->flightAwareSnapshot((int)$flightId),
    ]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}

