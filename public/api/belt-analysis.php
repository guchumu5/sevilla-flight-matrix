<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Analysis\BeltChangeAnalysisService;
use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\Response;

if (!Auth::check()) Response::json(['error' => 'No autorizado.'], 401);

try {
    $service = new BeltChangeAnalysisService(Database::connection());
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $date = trim((string)($_GET['date'] ?? '')) ?: null;
        $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 200;
        Response::json([
            'available' => $service->available(),
            'date' => $date,
            'analyses' => $service->history($date, $limit),
            'runs' => $service->runs(30),
            'patterns' => $service->patterns(90),
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::json(['error' => 'Método no permitido.'], 405);
    $input = Response::input();
    if (!Auth::verifyCsrf($input['csrf'] ?? null)) Response::json(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
    $date = trim((string)($input['date'] ?? date('Y-m-d', strtotime('-1 day'))));
    Response::json(['result' => $service->analyzeDate($date)]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
