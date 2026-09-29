<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SevillaMatrix\Database;
use SevillaMatrix\Notifications\WebPushService;
try {
    echo json_encode((new WebPushService(Database::connection()))->dispatch(100), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, date(DATE_ATOM) . ' ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
