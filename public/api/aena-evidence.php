<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Auth;
use SevillaMatrix\Database;
use SevillaMatrix\Response;
use SevillaMatrix\Sync\AenaEvidenceService;
use SevillaMatrix\Sync\AeroApiIngestService;

/**
 * Evidencias Infovuelos (Aena). Nunca gasta cuota AeroAPI.
 * GET: lista evidencias + cuota AeroAPI (solo lectura, para el banner).
 * POST action=capture: snapshot JSON vivo con timestamp.
 */
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $pdo = Database::connection();
    $quota = (new AeroApiIngestService($pdo))->quota()->status();
    $evidence = new AenaEvidenceService();

    if ($method === 'GET' || $method === 'HEAD') {
        $download = basename(trim((string)($_GET['download'] ?? $_GET['file'] ?? '')));
        if ($download !== '') {
            if (!preg_match('/^(?:aena-|ground-truth-)[A-Za-z0-9._-]+\.(json|html|jpg|jpeg|png)$/', $download)) {
                Response::json(['error' => 'Nombre de evidencia no válido.'], 422);
            }
            $absolute = dirname(__DIR__, 2) . '/storage/evidence/' . $download;
            if (!is_file($absolute)) {
                Response::json(['error' => 'Evidencia no encontrada.'], 404);
            }
            $mime = str_ends_with($download, '.html') ? 'text/html; charset=utf-8'
                : (str_ends_with($download, '.json') ? 'application/json; charset=utf-8'
                : (preg_match('/\.jpe?g$/i', $download) ? 'image/jpeg' : 'application/octet-stream'));
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . $download . '"');
            header('Content-Length: ' . (string)filesize($absolute));
            readfile($absolute);
            exit;
        }
        Response::json([
            'ok' => true,
            'items' => $evidence->listEvidence(40),
            'aeroapi_quota' => $quota,
            'note' => 'Capturar evidencia Aena no consume AeroAPI. La cuota se muestra solo para no pasarte en reconsultas FA.',
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, HEAD, POST');
        Response::json(['error' => 'Método no permitido.'], 405);
    }

    $input = Response::input();
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
        Response::json(['error' => 'Activa avisos o inicia sesión para pedir evidencia Aena.'], 401);
    }

    $action = strtolower(trim((string)($input['action'] ?? $input['mode'] ?? 'capture')));
    if (in_array($action, ['dual', 'regen', 'compare'], true)) {
        $action = 'regen_dual';
    }
    $by = Auth::check() ? 'admin' : 'device';
    if ($action === 'regen_dual') {
        // Solo pistas de BD / JSON: no llama AeroAPI (0 gasto).
        $hints = [];
        if ($pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name='aeroapi_snapshots'"
        )->fetchColumn()) {
            $stmt = $pdo->query(
                "SELECT f.physical_flight AS code, s.belt AS belt_aeroapi
                 FROM aeroapi_snapshots s
                 INNER JOIN flights f ON f.id=s.flight_id
                 INNER JOIN (
                   SELECT flight_id, MAX(id) AS max_id FROM aeroapi_snapshots GROUP BY flight_id
                 ) last ON last.max_id=s.id
                 WHERE f.is_canary=1 AND NULLIF(s.belt,'') IS NOT NULL
                 ORDER BY s.observed_at DESC
                 LIMIT 80"
            );
            foreach ($stmt->fetchAll() ?: [] as $row) {
                if (is_array($row)) $hints[] = $row;
            }
        }
        $result = $evidence->regenerateDualCompare($hints, $by . '_regen');
        $result['aeroapi_quota'] = $quota;
        $result['aeroapi_spent_this_call'] = 0;
        Response::json($result);
    }

    $result = $evidence->captureLive('SVQ', $by);
    $result['aeroapi_quota'] = $quota;
    $result['aeroapi_spent_this_call'] = 0;
    Response::json($result, 201);
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
