<?php
declare(strict_types=1);

return [
    'id' => '20260923_002_event_brain',
    'name' => 'Cerebro de conciliación y eventos',
    'description' => 'Añade ejecuciones de sincronización, estado por proveedor y un histórico inmutable de cambios con motivo, confianza y evidencia.',
    'category' => 'Conciliación e histórico',
    'sql_file' => dirname(__DIR__) . '/brain_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
