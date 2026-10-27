<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo línea de comandos.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\Database;
use SevillaMatrix\Notifications\WebPushService;

$lockPath = PROJECT_ROOT . '/storage/cache/watch-pulse-loop.lock';
$directory = dirname($lockPath);
if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
    fwrite(STDERR, date('c') . " no se pudo crear el bloqueo del bucle\n");
    exit(1);
}

$lock = fopen($lockPath, 'c+');
if ($lock === false) {
    fwrite(STDERR, date('c') . " no se pudo abrir el bloqueo del bucle\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('c') . " ya había un bucle de avisos en marcha\n";
    exit(0);
}
ftruncate($lock, 0);
fwrite($lock, (string)getmypid());
fflush($lock);

$started = time();
$maxSeconds = 8 * 3600;
$service = new WebPushService(Database::connection());
echo date('c') . " bucle de avisos cada minuto arrancado pid=" . getmypid() . PHP_EOL;

try {
    while ((time() - $started) < $maxSeconds) {
        if (!$service->hasActiveWatchTargets()) {
            echo date('c') . " no quedan vuelos vigilados; paro el bucle\n";
            break;
        }
        $sent = $service->pulseWatches();
        echo date('c') . " pulsos={$sent}\n";
        sleep(55);
    }
} catch (Throwable $error) {
    fwrite(STDERR, date('c') . ' ' . $error->getMessage() . PHP_EOL);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

flock($lock, LOCK_UN);
fclose($lock);
echo date('c') . " bucle de avisos terminado\n";
