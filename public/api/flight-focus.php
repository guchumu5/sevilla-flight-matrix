<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\Operations\FlightFocusService;
use SevillaMatrix\Response;

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
    $flightId = filter_var($input['flight_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$flightId) Response::json(['error' => 'Vuelo no válido.'], 422);

    @set_time_limit(45);
    $service = new FlightFocusService($pdo);
    $result = $service->refresh((int)$flightId);
    $rows = (new FlightRepository($pdo))->board((string)$result['flight_date']);
    $flight = null;
    foreach ($rows as $candidate) {
        if ((int)$candidate['id'] === (int)$flightId) {
            $flight = $candidate;
            break;
        }
    }
    Response::json($result + ['flight_data' => $flight]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
