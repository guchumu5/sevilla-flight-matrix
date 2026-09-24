<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\Response;
use SevillaMatrix\Sync\FlightReconciliationService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::json(['error' => 'Método no permitido.'], 405);
}

$configuredToken = trim((string)Env::get('AENA_INGEST_TOKEN', ''));
$authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$providedToken = trim((string)($_SERVER['HTTP_X_MATRIX_TOKEN'] ?? ''));
if ($providedToken === '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    $providedToken = trim($matches[1]);
}

if ($configuredToken === '' || strlen($configuredToken) < 24) {
    Response::json(['error' => 'El receptor Aena no está configurado.'], 503);
}
if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
    Response::json(['error' => 'Credencial de ingestión no válida.'], 401);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 5 * 1024 * 1024) {
    Response::json(['error' => 'La captura supera el límite de 5 MB.'], 413);
}

try {
    $payload = Response::input();
    $records = $payload['flights'] ?? null;
    if (!is_array($records) || !array_is_list($records)) {
        Response::json(['error' => 'El cuerpo debe contener una lista flights.'], 422);
    }
    if ($records === [] || count($records) > 1200) {
        Response::json(['error' => 'La captura Aena debe contener entre 1 y 1200 vuelos físicos.'], 422);
    }

    $observedAt = trim((string)($payload['observed_at'] ?? date('Y-m-d H:i:s')));
    $windowFrom = trim((string)($payload['window_from'] ?? ''));
    $windowTo = trim((string)($payload['window_to'] ?? ''));
    if ($windowFrom === '' || $windowTo === '') {
        Response::json(['error' => 'Faltan los límites de la ventana completa.'], 422);
    }

    $pdo = Database::connection();
    $insert = $pdo->prepare(
        "INSERT INTO fetch_runs (provider,started_at,ok,records_count) VALUES ('aena',NOW(),0,0)"
    );
    $insert->execute();
    $fetchRunId = (int)$pdo->lastInsertId();

    try {
        $result = (new FlightReconciliationService($pdo))->sync($records, [
            'provider' => 'aena',
            'mode' => 'full_window',
            'observed_at' => $observedAt,
            'window_from' => $windowFrom,
            'window_to' => $windowTo,
            'complete' => true,
            'withdraw_after' => 3,
            'metadata' => [
                'transport' => 'github_actions_browser',
                'collector' => trim((string)($payload['collector'] ?? 'aena-playwright-v1')),
                'aena_updated_label' => trim((string)($payload['aena_updated_label'] ?? '')),
            ],
        ]);

        $finish = $pdo->prepare(
            'UPDATE fetch_runs SET finished_at=NOW(),ok=1,records_count=:count WHERE id=:id'
        );
        $finish->execute(['count' => count($records), 'id' => $fetchRunId]);

        Response::json([
            'ok' => true,
            'message' => sprintf('Captura Aena conciliada: %d vuelos físicos.', count($records)),
            'fetch_run_id' => $fetchRunId,
            'result' => $result,
        ], 201);
    } catch (Throwable $error) {
        $finish = $pdo->prepare(
            'UPDATE fetch_runs SET finished_at=NOW(),ok=0,error_message=:error WHERE id=:id'
        );
        $finish->execute(['error' => substr($error->getMessage(), 0, 1000), 'id' => $fetchRunId]);
        throw $error;
    }
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
