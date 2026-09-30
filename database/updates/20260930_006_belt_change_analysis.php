<?php
declare(strict_types=1);
return [
    'id' => '20260930_006_belt_change_analysis',
    'name' => 'Análisis histórico de cambios de cinta',
    'description' => 'Guarda cada análisis diario, su motivo inferido, confianza y evidencias sin alterar el evento original.',
    'category' => 'Cerebro de eventos',
    'sql_file' => dirname(__DIR__) . '/belt_change_analysis_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
