<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Response;

$configuredToken = trim((string)Env::get('AENA_INGEST_TOKEN', ''));
$authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$providedToken = trim((string)($_SERVER['HTTP_X_MATRIX_TOKEN'] ?? ''));
if ($providedToken === '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    $providedToken = trim($matches[1]);
}
if (strlen($configuredToken) < 24 || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
    Response::json(['error' => 'Credencial de control no válida.'], 401);
}

try {
    $pdo = Database::connection();
    $flights = (new FlightRepository($pdo))->board(date('Y-m-d'));
    $watchedIds = [];
    $canaryGlobal = false;
    $watchTables = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name IN ('push_subscriptions','flight_watches')"
    )->fetchColumn() === 2;
    if ($watchTables) {
        $canaryGlobal = (bool)$pdo->query(
            'SELECT EXISTS(SELECT 1 FROM push_subscriptions WHERE active=1 AND watch_canary_all=1)'
        )->fetchColumn();
        $watchRows = $pdo->query(
            'SELECT DISTINCT w.flight_id FROM flight_watches w
             INNER JOIN push_subscriptions s ON s.id=w.subscription_id
             WHERE w.enabled=1 AND s.active=1'
        )->fetchAll(PDO::FETCH_COLUMN);
        $watchedIds = array_fill_keys(array_map('intval', $watchRows), true);
    }
    $critical = [];
    foreach ($flights as $flight) {
        $arrival = strtotime((string)($flight['effective_arrival'] ?? $flight['scheduled_arrival'] ?? '')) ?: 0;
        $status = strtolower((string)(($flight['status'] ?? '') . ' ' . ($flight['baggage_state'] ?? '')));
        $finished = preg_match('/final|cancel|completed|equipaje entregado/', $status) === 1;
        $priorityWatch = !$finished && $arrival >= time() - 7200 && $arrival <= time() + 21600
            && (isset($watchedIds[(int)$flight['id']]) || ($canaryGlobal && (int)$flight['is_canary'] === 1));
        if (empty($flight['belt_attention']) && !$priorityWatch) continue;
        $reason = !empty($flight['belt_attention_reason'])
            ? (string)$flight['belt_attention_reason']
            : ((int)$flight['is_canary'] === 1 && $canaryGlobal
                ? 'Avisos de Canarias activos: comprobación Aena reforzada.'
                : 'Vuelo vigilado individualmente: comprobación Aena reforzada.');
        $critical[] = [
            'id' => (int)$flight['id'],
            'flight' => (string)$flight['physical_flight'],
            'origin' => (string)$flight['origin_name'],
            'arrival' => $flight['effective_arrival'] ?? $flight['scheduled_arrival'],
            'belt' => $flight['belt'] ?? null,
            'status' => $flight['status'] ?? null,
            'baggage_state' => $flight['baggage_state'] ?? null,
            'level' => (string)($flight['belt_attention'] ?: 'watched'),
            'reason' => $reason,
            'watch_priority' => $priorityWatch,
        ];
    }

    $last = $pdo->query(
        "SELECT finished_at FROM fetch_runs
         WHERE provider='aena' AND ok=1 AND finished_at IS NOT NULL
         ORDER BY finished_at DESC,id DESC LIMIT 1"
    )->fetchColumn();
    $lastTimestamp = $last ? strtotime((string)$last) : false;
    $ageMinutes = $lastTimestamp === false ? null : max(0, (int)floor((time() - $lastTimestamp) / 60));

    Response::json([
        'ok' => true,
        'critical' => $critical !== [],
        'critical_count' => count($critical),
        'critical_flights' => $critical,
        'individual_watches' => count($watchedIds),
        'canary_watch_active' => $canaryGlobal,
        'last_aena_success_at' => $last ?: null,
        'last_aena_age_minutes' => $ageMinutes,
        'normal_due' => $ageMinutes === null || $ageMinutes >= 12,
        'recommended_interval_minutes' => $critical !== [] ? 5 : 15,
        'policy' => 'Cada 5 min para vuelos vigilados, Canarias con avisos o casos críticos; cada 15 min en situación normal.',
    ]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
