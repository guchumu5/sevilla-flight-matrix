<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Operations\WebOperationsService;

try {
    $result = (new WebOperationsService(Database::connection()))->run('weather');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch(Throwable $error) {
    fwrite(STDERR, date('c') . ' ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
