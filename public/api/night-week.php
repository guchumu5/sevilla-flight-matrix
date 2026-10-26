<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\OperationalDay;
use SevillaMatrix\Response;

try {
    $from = (string)($_GET['from'] ?? OperationalDay::current());
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    if (!$parsed || $parsed->format('Y-m-d') !== $from) Response::json(['error' => 'Fecha no válida.'], 422);
    $days = (int)($_GET['days'] ?? 7);
    $nightFrom = (int)($_GET['night_from'] ?? FlightRepository::NIGHT_FROM_HOUR);
    Response::json([
        'generated_at' => date('Y-m-d H:i:s'),
        'operational_today' => OperationalDay::current(),
    ] + (new FlightRepository(Database::connection()))->nightWeek($from, $days, $nightFrom));
} catch (Throwable $error) {
    error_log('night-week.php: ' . $error->getMessage());
    Response::json(['error' => 'No se pudo cargar la semana de vuelos nocturnos.'], 500);
}
