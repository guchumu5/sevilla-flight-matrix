<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Sync\FlightReconciliationService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo se puede ejecutar desde consola.\n");
    exit(1);
}

$options = getopt('', [
    'file:', 'provider::', 'mode::', 'window-from::', 'window-to::',
    'observed-at::', 'complete', 'withdraw-after::', 'allow-empty', 'help',
]);

if (isset($options['help']) || empty($options['file'])) {
    echo <<<'HELP'
Uso:
  php bin/sync-json.php --file=/ruta/lote.json [opciones]

Opciones:
  --provider=aena          Proveedor: aena, airlabs, opensky o manual.
  --mode=delta             delta, full_window o manual.
  --window-from="..."      Inicio de la ventana completa.
  --window-to="..."        Fin de la ventana completa.
  --observed-at="..."      Hora de captura; por defecto, ahora.
  --complete               Permite detectar vuelos ausentes en una ventana completa.
  --withdraw-after=3       Ausencias consecutivas antes de marcar retirado (2-10).
  --allow-empty            Permite procesar un lote completo vacío. Usar con cautela.

El JSON admite {"context": {...}, "flights": [...]} o directamente una lista de vuelos.
Los argumentos de consola prevalecen sobre context.
HELP;
    exit(isset($options['help']) ? 0 : 2);
}

try {
    $path = realpath((string)$options['file']);
    if (!$path || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('No se puede leer el fichero indicado.');
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('No se pudo abrir el fichero JSON.');
    }
    $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('El contenido JSON no es válido.');
    }

    $isList = array_is_list($payload);
    $records = $isList ? $payload : ($payload['flights'] ?? null);
    $context = $isList ? [] : (array)($payload['context'] ?? []);
    if (!is_array($records) || !array_is_list($records)) {
        throw new RuntimeException('El JSON debe contener una lista flights.');
    }

    $overrides = [
        'provider' => $options['provider'] ?? null,
        'mode' => $options['mode'] ?? null,
        'window_from' => $options['window-from'] ?? null,
        'window_to' => $options['window-to'] ?? null,
        'observed_at' => $options['observed-at'] ?? null,
        'withdraw_after' => $options['withdraw-after'] ?? null,
    ];
    foreach ($overrides as $key => $value) {
        if ($value !== null && $value !== false && $value !== '') {
            $context[$key] = $value;
        }
    }
    if (isset($options['complete'])) $context['complete'] = true;
    if (isset($options['allow-empty'])) $context['allow_empty'] = true;
    $context['metadata'] = array_merge((array)($context['metadata'] ?? []), [
        'transport' => 'json_file',
        'filename' => basename($path),
        'file_sha256' => hash_file('sha256', $path),
    ]);

    $result = (new FlightReconciliationService(Database::connection()))->sync($records, $context);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
