<?php
declare(strict_types=1);
return [
    'id' => '20261026_012_flightstats_web',
    'name' => 'FlightStats para vuelos vigilados',
    'description' => 'Añade lecturas públicas de retraso y ETA (JSON de FlightStats) solo para vuelos vigilados, sin tocar cinta ni sala de Aena.',
    'category' => 'Telemetría secundaria',
    'sql_file' => dirname(__DIR__) . '/flightstats_web_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
