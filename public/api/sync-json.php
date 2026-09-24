<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use JsonException;
use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\Response;
use SevillaMatrix\Sync\FlightReconciliationService;

if (!Auth::check()) {
    Response::json(['error' => 'No autorizado.'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    Response::json(['error' => 'Método no permitido. Usa el importador del Centro de procesos.'], 405);
}

$input = Response::input();
if (!Auth::verifyCsrf($input['csrf'] ?? null)) {
    Response::json(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
}

$contents = trim((string)($input['payload'] ?? ''));
if ($contents === '') {
    Response::json(['error' => 'Pega un JSON o selecciona un fichero antes de importar.'], 422);
}
if (strlen($contents) > 2 * 1024 * 1024) {
    Response::json(['error' => 'El JSON supera el límite web de 2 MB.'], 413);
}

try {
    $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        Response::json(['error' => 'El contenido JSON no es un objeto ni una lista válidos.'], 422);
    }

    $isList = array_is_list($payload);
    $records = $isList ? $payload : ($payload['flights'] ?? null);
    $context = $isList ? [] : (array)($payload['context'] ?? []);
    if (!is_array($records) || !array_is_list($records)) {
        Response::json(['error' => 'El JSON debe contener una lista flights.'], 422);
    }
    if ($records === [] || count($records) > 1200) {
        Response::json(['error' => 'La importación web debe contener entre 1 y 1200 vuelos físicos.'], 422);
    }

    $context['provider'] = trim((string)($context['provider'] ?? 'manual')) ?: 'manual';
    $context['mode'] = trim((string)($context['mode'] ?? 'delta')) ?: 'delta';
    $context['observed_at'] = trim((string)($context['observed_at'] ?? date('Y-m-d H:i:s')));
    $context['metadata'] = array_merge((array)($context['metadata'] ?? []), [
        'transport' => 'admin_web_json',
        'filename' => basename(trim((string)($input['filename'] ?? 'captura.json'))),
        'payload_sha256' => hash('sha256', $contents),
    ]);

    @set_time_limit(120);
    $result = (new FlightReconciliationService(Database::connection()))->sync($records, $context);
    Response::json([
        'ok' => true,
        'message' => sprintf(
            'Captura conciliada: %d vuelos, %d observaciones y %d eventos nuevos.',
            (int)$result['records_received'],
            (int)$result['observations_created'],
            (int)$result['events_created']
        ),
        'result' => $result,
    ], 201);
} catch (JsonException $error) {
    Response::json(['error' => 'JSON no válido: ' . $error->getMessage()], 422);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
