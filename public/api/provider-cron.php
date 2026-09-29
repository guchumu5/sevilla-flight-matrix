<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Env;
use SevillaMatrix\Operations\WebOperationsService;
use SevillaMatrix\Notifications\WebPushService;
use SevillaMatrix\Response;

$configured = trim((string)Env::get('AENA_INGEST_TOKEN', ''));
$authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$provided = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) $provided = trim($matches[1]);
if (strlen($configured) < 24 || $provided === '' || !hash_equals($configured, $provided)) {
    Response::json(['error' => 'Credencial de automatización no válida.'], 401);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Response::json(['error' => 'Método no permitido.'], 405);
}

try {
    $input = Response::input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $pdo = Database::connection();
    if ($action === 'push') {
        $table = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='push_outbox'")->fetchColumn();
        if ((int)$table !== 1) Response::json(['ok' => true, 'skipped' => true, 'message' => 'La actualización Web Push todavía no está aplicada.']);
        Response::json(['ok' => true, 'action' => 'push', 'result' => (new WebPushService($pdo))->dispatch(100)]);
    }
    $settings = [
        'airlabs' => ['provider' => 'airlabs', 'minimum_gap' => 45, 'limit' => 50],
        'opensky' => ['provider' => 'opensky', 'minimum_gap' => 4, 'limit' => 25],
        'weather' => ['provider' => 'aviationweather', 'minimum_gap' => 8, 'limit' => 10],
    ];
    if (!isset($settings[$action])) Response::json(['error' => 'Proceso no permitido.'], 422);
    $stmt = $pdo->prepare('SELECT GREATEST(0,TIMESTAMPDIFF(MINUTE,MAX(started_at),NOW())) FROM fetch_runs WHERE provider=:provider');
    $stmt->execute(['provider' => $settings[$action]['provider']]);
    $storedAge = $stmt->fetchColumn();
    $age = $storedAge === false || $storedAge === null ? null : (int)$storedAge;
    if ($age !== null && $age < $settings[$action]['minimum_gap']) {
        Response::json(['ok' => true, 'skipped' => true, 'message' => "{$action} ya se ejecutó hace {$age} min."]);
    }
    @set_time_limit(150);
    Response::json((new WebOperationsService($pdo))->run($action, $settings[$action]['limit']));
} catch (Throwable $error) {
    Response::json(['error' => $error->getMessage()], 500);
}
