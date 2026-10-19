<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = require $root . '/database/updates/20260929_005_push_watch.php';
$sql = file_get_contents((string)$manifest['sql_file']);
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer la migración Push original.\n");
    exit(1);
}

$checksumSource = json_encode([
    'id' => (string)$manifest['id'],
    'name' => trim((string)$manifest['name']),
    'transactional' => (bool)$manifest['transactional'],
    'requires_backup' => (bool)$manifest['requires_backup'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . $sql;

$expected = '658a9da8320c9b79de20d4c2f0a67eb971c5d28886e3fced31a422fb8ab656eb';
$actual = hash('sha256', $checksumSource);
if (!hash_equals($expected, $actual)) {
    fwrite(STDERR, "La migración aplicada 20260929_005_push_watch volvió a modificarse.\n");
    fwrite(STDERR, "Esperada: {$expected}\nActual: {$actual}\n");
    exit(1);
}

fwrite(STDOUT, "Integridad de migraciones aplicadas: OK\n");
