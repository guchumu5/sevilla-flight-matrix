<?php
declare(strict_types=1);
return [
    'id' => '20261019_010_flight_focus',
    'name' => 'Centro de atención por vuelo',
    'description' => 'Añade solicitudes temporales de seguimiento focalizado, prioridad Aena y control de cuota ADS-B por vuelo.',
    'category' => 'Seguimiento operativo',
    'sql_file' => dirname(__DIR__) . '/flight_focus_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
