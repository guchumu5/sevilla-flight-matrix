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
    $critical = [];
    foreach ($flights as $flight) {
        if (empty($flight['belt_attention'])) continue;
        $critical[] = [
            'id' => (int)$flight['id'],
            'flight' => (string)$flight['physical_flight'],
            'origin' => (string)$flight['origin_name'],
            'arrival' => $flight['effective_arrival'] ?? $flight['scheduled_arrival'],
            'belt' => $flight['belt'] ?? null,
            'status' => $flight['status'] ?? null,
            'baggage_state' => $flight['baggage_state'] ?? null,
            'level' => (string)$flight['belt_attention'],
            'reason' => (string)$flight['belt_attention_reason'],
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
        'last_aena_success_at' => $last ?: null,
        'last_aena_age_minutes' => $ageMinutes,
        'normal_due' => $ageMinutes === null || $ageMinutes >= 12,
        'recommended_interval_minutes' => $critical !== [] ? 5 : 15,
        'policy' => 'Cada 5 min mientras exista un caso crítico sin resolver; cada 15 min en situación normal.',
    ]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
