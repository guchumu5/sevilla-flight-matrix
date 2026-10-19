<?php
declare(strict_types=1);
return [
    'id' => '20261019_011_push_canary_default',
    'name' => 'Activación voluntaria de avisos Canarias',
    'description' => 'Conserva las preferencias actuales y evita activar por defecto los avisos de todos los vuelos canarios en dispositivos nuevos.',
    'category' => 'Notificaciones',
    'sql_file' => dirname(__DIR__) . '/push_canary_default_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
