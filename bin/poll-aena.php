<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo línea de comandos.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Sync\AenaInfovuelosSyncService;

try {
    $result = (new AenaInfovuelosSyncService(Database::connection()))->syncLive('cron');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, date('c') . ' ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
