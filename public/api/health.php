<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Response;

try {
    Database::connection()->query('SELECT 1');
    Response::json(['ok' => true, 'time' => date(DATE_ATOM)]);
} catch (Throwable $error) {
    Response::json(['ok' => false, 'error' => $error->getMessage()], 503);
}

