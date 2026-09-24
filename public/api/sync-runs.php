<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\Response;
use SevillaMatrix\Sync\SyncReadRepository;

if (!Auth::check()) {
    Response::json(['error' => 'No autorizado.'], 401);
}

try {
    $runLimit = filter_input(INPUT_GET, 'runs', FILTER_VALIDATE_INT) ?: 20;
    $eventLimit = filter_input(INPUT_GET, 'events', FILTER_VALIDATE_INT) ?: 100;
    Response::json((new SyncReadRepository(Database::connection()))->dashboard($runLimit, $eventLimit));
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
