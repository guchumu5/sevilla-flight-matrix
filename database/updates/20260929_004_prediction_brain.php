<?php
declare(strict_types=1);

return [
    'id' => '20260929_004_prediction_brain',
    'name' => 'Cerebro predictivo y medición de aciertos',
    'description' => 'Conserva la predicción previa de cinta y permite compararla posteriormente con la asignación oficial de Aena.',
    'category' => 'Predicción e histórico',
    'sql_file' => dirname(__DIR__) . '/predictions_v1.sql',
    'transactional' => false,
    'requires_backup' => true,
];
