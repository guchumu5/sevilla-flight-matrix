<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Response;
use SevillaMatrix\Analysis\BeltChangeAnalysisService;

try {
    $date = $_GET['date'] ?? date('Y-m-d');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) Response::json(['error' => 'Fecha no válida.'], 422);
    $pdo = Database::connection();
    $repository = new FlightRepository($pdo);
    $flights = $repository->board($date);
    $dailyAnalysis = null;
    try {
        $dailyAnalysis = (new BeltChangeAnalysisService($pdo))->analyzePreviousDayIfDue();
    } catch (Throwable $ignored) {
        // El tablero nunca debe fallar por una tarea de mantenimiento secundaria.
    }
    Response::json([
        'generated_at' => date('Y-m-d H:i:s'),
        'authority_note' => 'Aena prevalece para estado, sala y cinta',
        'prediction_summary' => $repository->predictionSummary($date),
        'belt_analysis_daily' => $dailyAnalysis,
        'flights' => $flights,
    ]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
