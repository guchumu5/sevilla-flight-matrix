<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Response;

try {
    $date = $_GET['date'] ?? date('Y-m-d');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) Response::json(['error' => 'Fecha no válida.'], 422);
    $flights = (new FlightRepository(Database::connection()))->board($date);
    Response::json([
        'generated_at' => date('Y-m-d H:i:s'),
        'authority_note' => 'Aena prevalece para estado, sala y cinta',
        'flights' => $flights,
    ]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}

