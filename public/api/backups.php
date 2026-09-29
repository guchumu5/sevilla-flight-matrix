<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Backup\DatabaseBackupService;
use SevillaMatrix\Database;
use SevillaMatrix\Response;

if (!Auth::check()) Response::json(['error' => 'No autorizado.'], 401);
$service = new DatabaseBackupService(Database::connection());
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $name = trim((string)($_GET['download'] ?? ''));
        if ($name !== '') {
            $path = $service->path($name);
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
        Response::json(['backups' => $service->all()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') Response::json(['error' => 'Método no permitido.'], 405);
    $input = Response::input();
    if (!Auth::verifyCsrf($input['csrf'] ?? null)) Response::json(['error' => 'Sesión caducada.'], 419);
    $action = (string)($input['action'] ?? '');
    if ($action === 'create') Response::json(['ok' => true, 'backup' => $service->create('manual')]);
    if ($action === 'verify') Response::json(['ok' => true, 'backup' => $service->verify((string)($input['name'] ?? ''))]);
    Response::json(['error' => 'Acción no permitida.'], 422);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
