<?php
declare(strict_types=1);

return [
    'id' => '20260924_003_fetch_runs',
    'name' => 'Histórico de intentos de recolectores',
    'description' => 'Registra cada ejecución de AirLabs, OpenSky y meteorología, incluso cuando la fuente devuelve cero registros.',
    'category' => 'Diagnóstico y automatización',
    'sql_file' => dirname(__DIR__) . '/fetch_runs_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
