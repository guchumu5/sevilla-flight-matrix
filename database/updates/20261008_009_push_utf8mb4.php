<?php
declare(strict_types=1);
return [
    'id' => '20261008_009_push_utf8mb4',
    'name' => 'Compatibilidad UTF-8 de avisos Push',
    'description' => 'Convierte los textos de la cola Push a utf8mb4 para combinar datos históricos con iconos sin errores de intercalación.',
    'category' => 'Notificaciones',
    'sql_file' => dirname(__DIR__) . '/push_utf8mb4_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
