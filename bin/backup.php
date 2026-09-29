<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Backup\DatabaseBackupService;
use SevillaMatrix\Database;

try {
    $result = (new DatabaseBackupService(Database::connection()))->create('auto');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, date(DATE_ATOM) . ' ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
