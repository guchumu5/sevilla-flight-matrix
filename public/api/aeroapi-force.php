<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Response;
use SevillaMatrix\Sync\AeroApiIngestService;

/**
 * Forzado manual AeroAPI + Aena.
 * Nunca crea ni activa flight_watches: solo la campana «Vigilar» vigila.
 */
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Response::json(['error' => 'Método no permitido.'], 405);
}

try {
    $input = Response::input();
    $pdo = Database::connection();
    $authorized = false;
    if (Auth::check()) {
        if (!Auth::verifyCsrf($input['csrf'] ?? null)) {
            Response::json(['error' => 'La sesión ha caducado. Recarga la página.'], 419);
        }
        $authorized = true;
    } else {
        $deviceToken = strtolower(trim((string)($input['device_token'] ?? '')));
        if (preg_match('/^[0-9a-f]{64}$/', $deviceToken)) {
            $subscription = $pdo->prepare(
                'SELECT EXISTS(SELECT 1 FROM push_subscriptions WHERE device_token=:token AND active=1)'
            );
            $subscription->execute(['token' => $deviceToken]);
            $authorized = (bool)$subscription->fetchColumn();
        }
    }
    if (!$authorized) {
        Response::json(['error' => 'Inicia sesión como administrador o activa las notificaciones de este dispositivo.'], 401);
    }

    $mode = strtolower(trim((string)($input['mode'] ?? $input['action'] ?? '')));
    if (!in_array($mode, ['canarias', 'flight_cloud'], true)) {
        Response::json(['error' => 'Modo no válido. Usa canarias o flight_cloud.'], 422);
    }

    @set_time_limit(90);
    $service = new AeroApiIngestService($pdo);
    if ($mode === 'canarias') {
        $limit = filter_var($input['limit'] ?? 12, FILTER_VALIDATE_INT) ?: 12;
        $result = $service->forceCanariasBatch((int)$limit);
        $result['watched_created'] = false;
        Response::json($result);
    }

    $flightId = filter_var($input['flight_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$flightId) Response::json(['error' => 'Vuelo no válido.'], 422);
    $result = $service->forceFlightAndCloud((int)$flightId);
    $result['watched_created'] = false;

    $flightRow = $pdo->prepare('SELECT flight_date FROM flights WHERE id=:id');
    $flightRow->execute(['id' => (int)$flightId]);
    $flightDate = (string)($flightRow->fetchColumn() ?: '');
    if ($flightDate !== '') {
        foreach ((new FlightRepository($pdo))->board($flightDate) as $candidate) {
            if ((int)$candidate['id'] === (int)$flightId) {
                $result['flight_data'] = $candidate;
                break;
            }
        }
    }
    Response::json($result);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
