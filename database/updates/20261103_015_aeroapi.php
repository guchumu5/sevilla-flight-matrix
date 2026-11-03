<?php
declare(strict_types=1);
return [
    'id' => '20261103_015_aeroapi',
    'name' => 'FlightAware AeroAPI para Canarias',
    'description' => 'Añade lecturas oficiales AeroAPI (estado, ETA, salida/llegada, matrícula, puerta y cinta provisional) como fuente secundaria seria, sin sustituir la cinta de Aena.',
    'category' => 'Telemetría secundaria',
    'sql_file' => dirname(__DIR__) . '/aeroapi_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
