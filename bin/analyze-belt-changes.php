<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Analysis\BeltChangeAnalysisService;
use SevillaMatrix\Database;

$date = date('Y-m-d', strtotime('-1 day'));
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--date=')) $date = substr($argument, 7);
}

try {
    $result = (new BeltChangeAnalysisService(Database::connection()))->analyzeDate($date);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
