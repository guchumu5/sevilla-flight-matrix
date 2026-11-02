<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\Operations\WebOperationsService;
use SevillaMatrix\Response;
use Throwable;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$flightId = filter_var($_GET['flight_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

try {
    $pdo = Database::connection();
    $service = new WebOperationsService($pdo);

    if ($method === 'GET' || $method === 'HEAD') {
        Response::json($service->aenaStatus($flightId ? (int)$flightId : null));
    }

    if ($method !== 'POST') {
        header('Allow: GET, HEAD, POST');
        Response::json(['error' => 'Método no permitido.'], 405);
    }

    $input = Response::input();
    $authorized = Auth::check();
    if (!$authorized) {
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
        Response::json([
            'error' => 'Activa los avisos de este dispositivo o inicia sesión para pedir Aena.',
        ], 401);
    }

    $postFlightId = filter_var($input['flight_id'] ?? $flightId, FILTER_VALIDATE_INT) ?: null;
    $by = Auth::check() ? 'admin' : 'device';
    $request = $service->requestAenaSweep($by);
    $status = $service->aenaStatus($postFlightId ? (int)$postFlightId : null);
    Response::json($request + [
        'status' => $status,
        'wait_hint' => !empty($request['dispatched'])
            ? 'Infovuelos suele responder en 1–2 minutos. No cierres la pantalla.'
            : 'Sin disparo inmediato: el próximo ciclo de GitHub (máx. 5 min) lee Infovuelos.',
    ]);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
