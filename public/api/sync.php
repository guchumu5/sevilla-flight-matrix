<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Operations\WebOperationsService;
use SevillaMatrix\Response;
use Throwable;

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    Response::json(['error' => 'Método no permitido.'], 405);
}

$minInterval = 45;
$lockName = 'sevilla_matrix_board_sync';
$pdo = null;

try {
    $pdo = Database::connection();
    $lock = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
    $lock->execute(['name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        Response::json([
            'ok' => true,
            'skipped' => true,
            'running' => true,
            'message' => 'Ya hay una actualización en curso. Recargo el tablero.',
        ]);
    }

    $cacheDir = PROJECT_ROOT . '/storage/cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('No se pudo preparar la caché de sincronización.');
    }
    $stampFile = $cacheDir . '/board-sync.json';
    $previous = is_file($stampFile) ? json_decode((string)file_get_contents($stampFile), true) : [];
    $previous = is_array($previous) ? $previous : [];
    $finishedAt = (int)($previous['finished_at'] ?? 0);
    $age = $finishedAt > 0 ? time() - $finishedAt : 9999;
    if ($age < $minInterval) {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute(['name' => $lockName]);
        Response::json([
            'ok' => true,
            'skipped' => true,
            'age_seconds' => $age,
            'message' => 'La última consulta fue hace ' . $age . ' s. Recargo el tablero con lo último.',
        ]);
    }

    @set_time_limit(120);
    file_put_contents($stampFile, json_encode([
        'started_at' => time(),
        'running' => true,
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);

    $service = new WebOperationsService($pdo);
    $results = [];
    foreach (['aena' => 1, 'airlabs' => 40] as $action => $limit) {
        try {
            $results[$action] = $service->run($action, $limit);
        } catch (Throwable $error) {
            $results[$action] = ['ok' => false, 'error' => $error->getMessage()];
        }
    }

    $ok = count(array_filter($results, static fn(array $item): bool => ($item['ok'] ?? false) === true)) > 0;
    $parts = [];
    if (!empty($results['aena']['message'])) {
        $parts[] = (string)$results['aena']['message'];
    } elseif (!empty($results['aena']['error'])) {
        $parts[] = 'Aena: ' . $results['aena']['error'];
    }
    if (!empty($results['airlabs']['message'])) {
        $parts[] = (string)$results['airlabs']['message'];
    } elseif (!empty($results['airlabs']['error'])) {
        $parts[] = 'AirLabs: ' . $results['airlabs']['error'];
    }
    $message = $parts !== []
        ? implode(' ', $parts)
        : ($ok ? 'Fuentes consultadas.' : 'No se pudo consultar ninguna fuente.');

    file_put_contents($stampFile, json_encode([
        'finished_at' => time(),
        'finished_at_iso' => date('c'),
        'running' => false,
        'ok' => $ok,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
    $release->execute(['name' => $lockName]);

    Response::json([
        'ok' => $ok,
        'message' => $message,
        'results' => [
            'aena' => [
                'ok' => (bool)($results['aena']['ok'] ?? false),
                'dispatched' => (bool)($results['aena']['dispatched'] ?? false),
            ],
            'airlabs' => [
                'ok' => (bool)($results['airlabs']['ok'] ?? false),
                'received' => (int)($results['airlabs']['received'] ?? 0),
            ],
        ],
    ]);
} catch (Throwable $error) {
    if ($pdo instanceof PDO) {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        } catch (Throwable $ignored) {
        }
    }
    Response::json(['error' => $error->getMessage()], 500);
}
