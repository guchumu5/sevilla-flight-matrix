<?php
declare(strict_types=1);
return [
    'id' => '20261101_013_flight_feeders',
    'name' => 'Arrastre de Canarias noche',
    'description' => 'Guarda el vuelo de ida (rotación SVQ → Canarias) para marcar retrasos arrastrados en las llegadas nocturnas.',
    'category' => 'Telemetría secundaria',
    'sql_file' => dirname(__DIR__) . '/flight_feeders_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
