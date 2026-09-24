<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\Operations\WebOperationsService;
use SevillaMatrix\Response;

if (!Auth::check()) {
    Response::json(['error' => 'No autorizado.'], 401);
}

try {
    $service = new WebOperationsService(Database::connection());
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        Response::json($service->status());
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::json(['error' => 'Método no permitido.'], 405);
    }
    $input = Response::input();
    if (!Auth::verifyCsrf($input['csrf'] ?? null)) {
        Response::json(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
    }
    @set_time_limit(120);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $limit = filter_var($input['limit'] ?? 10, FILTER_VALIDATE_INT) ?: 10;
    Response::json($service->run($action, $limit));
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
