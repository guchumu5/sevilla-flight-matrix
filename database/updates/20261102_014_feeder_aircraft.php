<?php
declare(strict_types=1);
return [
    'id' => '20261102_014_feeder_aircraft',
    'name' => 'Matrícula del arrastre',
    'description' => 'Guarda la matrícula de la aeronave que trae el retraso del tramo anterior.',
    'category' => 'Telemetría secundaria',
    'sql_file' => dirname(__DIR__) . '/flight_feeders_aircraft_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
