<?php
declare(strict_types=1);
return [
    'id' => '20261008_007_flightaware_web',
    'name' => 'Instantáneas públicas FlightAware',
    'description' => 'Guarda lecturas limitadas de la ficha pública para vuelos vigilados, separadas de la autoridad Aena.',
    'category' => 'Telemetría secundaria',
    'sql_file' => dirname(__DIR__) . '/flightaware_web_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
