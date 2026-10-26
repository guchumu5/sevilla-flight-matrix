<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\OperationalDay;
use SevillaMatrix\Operations\WebOperationsService;
use SevillaMatrix\Response;
use SevillaMatrix\TrackingCadence;

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
    $flights = (new FlightRepository($pdo))->board(OperationalDay::current());
    $watchedIds = [];
    $focusedIds = [];
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
    $focusTable = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name='flight_focus_requests'"
    )->fetchColumn() === 1;
    if ($focusTable) {
        $focusRows = $pdo->query(
            "SELECT flight_id FROM flight_focus_requests
             WHERE status='active' AND expires_at>=NOW()"
        )->fetchAll(PDO::FETCH_COLUMN);
        $focusedIds = array_fill_keys(array_map('intval', $focusRows), true);
    }
    $critical = [];
    foreach ($flights as $flight) {
        $arrival = strtotime((string)($flight['effective_arrival'] ?? $flight['scheduled_arrival'] ?? '')) ?: 0;
        $status = strtolower((string)(($flight['status'] ?? '') . ' ' . ($flight['baggage_state'] ?? '')));
        $finished = preg_match('/final|cancel|completed|equipaje entregado/', $status) === 1;
        $minutesToArrival = $arrival > 0 ? (int)floor(($arrival - time()) / 60) : null;
        $monitored = isset($watchedIds[(int)$flight['id']])
            || isset($focusedIds[(int)$flight['id']])
            || ($canaryGlobal && (int)$flight['is_canary'] === 1);
        // Los vigilados y las cintas críticas van a 5 min; el resto, 15 min.
        $priorityWatch = TrackingCadence::intervalMinutes(
            $minutesToArrival,
            $monitored,
            $finished,
            !empty($flight['belt_attention'])
        ) === TrackingCadence::FINAL_MINUTES;
        if (empty($flight['belt_attention']) && !$priorityWatch) continue;
        $reason = !empty($flight['belt_attention_reason'])
            ? (string)$flight['belt_attention_reason']
            : (isset($focusedIds[(int)$flight['id']])
                ? 'Centro de atención activo: comprobación Aena prioritaria solicitada desde la ficha del vuelo.'
                : ((int)$flight['is_canary'] === 1 && $canaryGlobal
                ? 'Avisos de Canarias activos: comprobación Aena reforzada.'
                : 'Vuelo vigilado individualmente: comprobación Aena reforzada.'));
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
            'minutes_to_arrival' => $minutesToArrival,
            'tracking_phase' => TrackingCadence::phase($minutesToArrival, $monitored, $finished),
        ];
    }

    $last = $pdo->query(
        "SELECT finished_at FROM fetch_runs
         WHERE provider='aena' AND ok=1 AND finished_at IS NOT NULL
         ORDER BY finished_at DESC,id DESC LIMIT 1"
    )->fetchColumn();
    $lastTimestamp = $last ? strtotime((string)$last) : false;
    $ageMinutes = $lastTimestamp === false ? null : max(0, (int)floor((time() - $lastTimestamp) / 60));
    $forced = WebOperationsService::aenaForcePending();

    Response::json([
        'ok' => true,
        'critical' => $critical !== [] || $forced,
        'critical_count' => count($critical),
        'critical_flights' => $critical,
        'individual_watches' => count($watchedIds),
        'focused_flights' => count($focusedIds),
        'canary_watch_active' => $canaryGlobal,
        'last_aena_success_at' => $last ?: null,
        'last_aena_age_minutes' => $ageMinutes,
        'normal_due' => $forced || $ageMinutes === null || $ageMinutes >= 12,
        'forced' => $forced,
        'recommended_interval_minutes' => ($critical !== [] || $forced) ? 5 : 15,
        'policy' => 'Vuelo vigilado: cada 5 min hasta finalizar el equipaje. El resto de la matriz, 15 min. Las cintas críticas también van a 5 min.',
    ]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
