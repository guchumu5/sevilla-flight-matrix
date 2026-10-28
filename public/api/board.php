<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\OperationalDay;
use SevillaMatrix\Response;
use SevillaMatrix\Analysis\BeltChangeAnalysisService;

try {
    $date = (string)($_GET['date'] ?? OperationalDay::current());
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) Response::json(['error' => 'Fecha no válida.'], 422);
    $pdo = Database::connection();
    $repository = new FlightRepository($pdo);
    $flights = $repository->cachedBoard($date);
    $dailyAnalysis = null;
    try {
        $dailyAnalysis = (new BeltChangeAnalysisService($pdo))->analyzePreviousDayIfDue();
    } catch (Throwable $ignored) {
        // El tablero nunca debe fallar por una tarea de mantenimiento secundaria.
    }
    Response::json([
        'generated_at' => date('Y-m-d H:i:s'),
        'date' => $date,
        'operational_today' => OperationalDay::current(),
        'authority_note' => 'Aena prevalece para estado, sala y cinta',
        'prediction_summary' => $repository->predictionSummary($date),
        'belt_analysis_daily' => $dailyAnalysis,
        'flights' => $flights,
    ]);
} catch (Throwable $error) {
    error_log('board.php: ' . $error->getMessage());
    $debug = filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
    Response::json([
        'error' => $debug || !($error instanceof PDOException)
            ? $error->getMessage()
            : 'No se pudo leer la base de datos. Inténtalo de nuevo en unos segundos.',
    ], 500);
}
