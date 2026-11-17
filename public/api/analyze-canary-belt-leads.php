<?php
declare(strict_types=1);

/**
 * Análisis puntual Canarias (octubre→hoy). Borrar este fichero tras usarlo.
 * Auth: sesión admin O ?token=AENA_INGEST_TOKEN
 */
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Analysis\CanaryBeltLeadAnalyzer;
use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\Env;

@set_time_limit(300);
@ini_set('memory_limit', '512M');

$token = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_MATRIX_TOKEN'] ?? ''));
$configured = trim((string)Env::get('AENA_INGEST_TOKEN', ''));
$ok = Auth::check()
    || ($configured !== '' && $token !== '' && hash_equals($configured, $token));

if (!$ok) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No autorizado. Inicia sesión de admin en la matriz o pasa ?token=AENA_INGEST_TOKEN\n";
    exit;
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? ''))
    ? (string)$_GET['from'] : '2026-10-01';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))
    ? (string)$_GET['to']
    : (new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');

try {
    $result = (new CanaryBeltLeadAnalyzer(Database::connection()))->run($from, $to);
    $summary = $result['summary'];
    $outDir = dirname(__DIR__, 2) . '/storage/evidence';
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

    $format = strtolower((string)($_GET['format'] ?? 'text'));
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
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
            'cases' => $summary['cases'],
            'markdown' => $result['markdown'],
            'saved_json' => $jsonPath,
            'saved_md' => $mdPath,
            'delete_this_endpoint' => 'public/api/analyze-canary-belt-leads.php',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $result['markdown'];
    echo "\n---\n";
    echo "Guardado: {$mdPath}\n";
    echo "JSON: {$jsonPath}\n";
    echo "Borra este endpoint cuando termines: public/api/analyze-canary-belt-leads.php\n";
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $error->getMessage() . "\n";
    exit(1);
}
