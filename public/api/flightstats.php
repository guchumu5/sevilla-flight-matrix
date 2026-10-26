<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\Response;
use SevillaMatrix\Sync\FlightStatsIngestService;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST' ? Response::input() : [];

try {
    $pdo = Database::connection();
    $service = new FlightStatsIngestService($pdo);

    if ($method === 'POST' && (string)($input['action'] ?? '') === 'request') {
        $flightId = filter_var($input['flight_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$flightId) Response::json(['error' => 'Vuelo no válido.'], 422);
        Response::json($service->request($flightId));
    }

    $configured = trim((string)Env::get('AENA_INGEST_TOKEN', ''));
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $provided = trim((string)($_SERVER['HTTP_X_MATRIX_TOKEN'] ?? ''));
    if ($provided === '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        $provided = trim($matches[1]);
    }
    if (strlen($configured) < 24 || $provided === '' || !hash_equals($configured, $provided)) {
        Response::json(['error' => 'Credencial de automatización no válida.'], 401);
    }

    if (!$service->ready()) {
        Response::json([
            'ok' => true,
            'configured' => false,
            'flights' => [],
            'message' => 'Aplica la actualización MySQL 012 de FlightStats.',
        ]);
    }

    if ($method === 'GET') {
        Response::json($service->queue(8));
    }

    if ($method !== 'POST') Response::json(['error' => 'Método no permitido.'], 405);
    $records = $input['snapshots'] ?? null;
    if (!is_array($records) || !array_is_list($records) || count($records) > 8) {
        Response::json(['error' => 'snapshots debe ser una lista de hasta 8 registros.'], 422);
    }
    Response::json($service->ingest($records, true));
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
