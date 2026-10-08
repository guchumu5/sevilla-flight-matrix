<?php
declare(strict_types=1);
return [
    'id' => '20261008_008_flightaware_on_demand',
    'name' => 'FlightAware solamente bajo demanda',
    'description' => 'Crea una cola limitada: la ficha pública solo se consulta después de abrir un avión en la aplicación.',
    'category' => 'Telemetría secundaria',
    'sql_file' => dirname(__DIR__) . '/flightaware_on_demand_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
