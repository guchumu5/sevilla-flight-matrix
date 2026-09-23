<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\DatabaseUpdateManager;
use SevillaMatrix\Response;

if (!Auth::check()) {
    Response::json(['error' => 'No autorizado.'], 401);
}

try {
    $manager = new DatabaseUpdateManager(Database::connection());

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        Response::json($manager->status());
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::json(['error' => 'Método no permitido.'], 405);
    }

    $input = Response::input();
    if (!Auth::verifyCsrf($input['csrf'] ?? null)) {
        Response::json(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
    }
    if (($input['confirm_backup'] ?? '') !== '1') {
        Response::json(['error' => 'Confirma la copia de seguridad antes de actualizar.'], 422);
    }

    $id = trim((string)($input['update_id'] ?? ''));
    @set_time_limit(180);
    Response::json(['ok' => true, 'result' => $manager->apply($id)]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
