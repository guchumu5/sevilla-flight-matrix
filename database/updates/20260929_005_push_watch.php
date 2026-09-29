<?php
declare(strict_types=1);
return [
    'id' => '20260929_005_push_watch',
    'name' => 'Vigilancia de vuelos y avisos móviles',
    'description' => 'Añade dispositivos web push, vuelos vigilados y una cola auditable de avisos materiales.',
    'category' => 'Avisos y vigilancia',
    'sql_file' => dirname(__DIR__) . '/push_watch_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
