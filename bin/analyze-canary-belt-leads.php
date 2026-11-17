<?php
declare(strict_types=1);
/**
 * Canarias oct→hoy: Aena tenía cinta antes del despegue, luego cambió
 * (sobre todo a 7/8). ¿Alguna secundaria publicó esa cinta nueva ANTES que Aena?
 *
 * Web (preferido): /public/api/analyze-canary-belt-leads.php (sesión admin o ?token=)
 * CLI: php bin/analyze-canary-belt-leads.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo línea de comandos.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Analysis\CanaryBeltLeadAnalyzer;
use SevillaMatrix\Database;

$from = '2026-10-01';
$to = (new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $from = $m[1];
    }
    if (preg_match('/^--to=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $to = $m[1];
    }
    if ($arg === '--help') {
        fwrite(STDOUT, "Uso: php bin/analyze-canary-belt-leads.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]\n");
        exit(0);
    }
}

$result = (new CanaryBeltLeadAnalyzer(Database::connection()))->run($from, $to);
$summary = $result['summary'];
$outDir = dirname(__DIR__) . '/storage/evidence';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}
$stamp = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d_H-i-s');
$jsonPath = $outDir . '/canary-belt-leads-' . $stamp . '.json';
$mdPath = $outDir . '/canary-belt-leads-' . $stamp . '.md';
file_put_contents(
    $jsonPath,
    json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);
file_put_contents($mdPath, $result['markdown']);

fwrite(STDOUT, json_encode([
    'ok' => true,
    'from' => $from,
    'to' => $to,
    'canary_flights' => $summary['canary_flights'],
    'with_aena_belt_before_dep' => $summary['with_aena_belt_before_dep'],
    'aena_later_changed' => $summary['aena_later_changed'],
    'aena_ended_red' => $summary['aena_ended_red'],
    'secondary_led_any_change' => $summary['secondary_led_final_belt'],
    'secondary_led_red_change' => $summary['secondary_led_red_change'],
    'cases_listed' => count($summary['cases']),
    'json' => $jsonPath,
    'markdown' => $mdPath,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
fwrite(STDOUT, "\n---\n" . $result['markdown']);
