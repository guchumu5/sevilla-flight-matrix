<?php
declare(strict_types=1);

return [
    'id' => '20260923_001_week_schedule',
    'name' => 'Programación SVQ del 24 al 27 de septiembre',
    'description' => 'Carga 408 llegadas físicas, agrupa sus códigos compartidos e incorpora 67 asignaciones preliminares de sala/cinta publicadas por Aena.',
    'category' => 'Programación y primera observación Aena',
    'sql_file' => dirname(__DIR__) . '/seed_arrivals_2026-09-24_27.sql',
    'transactional' => false,
    'requires_backup' => true,
];
