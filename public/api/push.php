<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
use SevillaMatrix\Database;
use SevillaMatrix\Notifications\WebPushService;
use SevillaMatrix\Response;

try {
    $service = new WebPushService(Database::connection());
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = (string)($_GET['action'] ?? 'config');
        if ($action === 'config') Response::json(['public_key' => $service->publicKey()]);
        if ($action === 'status') Response::json($service->status((string)($_GET['device_token'] ?? ''), filter_input(INPUT_GET, 'flight_id', FILTER_VALIDATE_INT) ?: null));
        if ($action === 'pending') Response::json(['notification' => $service->pending((string)($_GET['device_token'] ?? ''))]);
        Response::json(['error' => 'Acción no permitida.'], 422);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::json(['error' => 'Método no permitido.'], 405);
    $input = Response::input();
    $action = (string)($input['action'] ?? '');
    if ($action === 'subscribe') Response::json(['ok' => true] + $service->subscribe((array)($input['subscription'] ?? []), (string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($action === 'watch') {
        $service->setWatch((string)($input['device_token'] ?? ''), (int)($input['flight_id'] ?? 0), (bool)($input['enabled'] ?? false));
        Response::json(['ok' => true]);
    }
    if ($action === 'watch_canary_all') {
        $enabled = (bool)($input['enabled'] ?? false);
        $service->setCanaryWatch((string)($input['device_token'] ?? ''), $enabled);
        Response::json(['ok' => true, 'watch_canary_all' => $enabled]);
    }
    Response::json(['error' => 'Acción no permitida.'], 422);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
