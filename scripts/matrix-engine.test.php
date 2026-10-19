<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\MatrixEngine;

/** @return array<string,mixed> */
function sampleFlight(int $id, string $code, string $arrival, ?string $belt, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'flight_date' => substr($arrival, 0, 10),
        'physical_flight' => $code,
        'origin_iata' => 'XXX',
        'origin_name' => 'PRUEBA ' . $code,
        'scheduled_arrival' => $arrival,
        'effective_arrival' => $arrival,
        'actual_arrival' => null,
        'eta' => $arrival,
        'hall' => $belt !== null && (int)$belt >= 7 ? 'B' : 'A',
        'belt' => $belt,
        'source' => 'aena',
        'status' => 'Programado',
        'baggage_state' => 'pendiente',
        'capacity' => 180,
        'belt_changes' => 0,
        'secondary_belt' => null,
        'secondary_hall' => null,
        'predicted_belt' => null,
        'predicted_hall' => null,
        'is_canary' => 0,
    ], $extra);
}

$flights = [
    sampleFlight(1, 'VLGTEST', '2026-10-15 10:00:00', '1', ['origin_iata' => 'LPA', 'is_canary' => 1]),
    sampleFlight(2, 'OLD0001', '2026-10-15 09:35:00', '2'),
    sampleFlight(3, 'RED0007', '2026-10-15 09:50:00', '7'),
    sampleFlight(4, 'NEXT003', '2026-10-15 10:10:00', null, [
        'source' => 'airlabs',
        'predicted_belt' => '3',
        'predicted_hall' => 'A',
    ]),
    sampleFlight(5, 'NEXT008', '2026-10-15 10:20:00', '8'),
    sampleFlight(6, 'UNKNOWN', '2026-10-15 10:05:00', null),
];

$decorated = (new MatrixEngine())->decorate($flights);
$target = array_values(array_filter($decorated, static fn(array $flight): bool => $flight['id'] === 1))[0] ?? [];
$model = $target['belt_occupancy_30m'] ?? null;

if (!is_array($model)) throw new RuntimeException('No se generó belt_occupancy_30m.');
if (($model['model'] ?? null) !== 'belt_occupancy_v2') throw new RuntimeException('Versión de modelo incorrecta.');
if (($model['at_arrival']['occupied_count'] ?? null) !== 3) throw new RuntimeException('La ETA debía tener 3/8 cintas ocupadas.');
if ((float)($model['at_arrival']['percentage'] ?? -1) !== 37.5) throw new RuntimeException('El porcentaje ETA debía ser 37,5 %.');
if ((float)($model['at_arrival']['red_percentage'] ?? -1) !== 50.0) throw new RuntimeException('El bloque 7/8 debía estar al 50 %.');
if (empty($model['at_arrival']['belt_7_occupied']) || !empty($model['at_arrival']['belt_8_occupied'])) throw new RuntimeException('C7 debía estar ocupada y C8 libre en la ETA.');
if (($model['window_peak']['occupied_count'] ?? null) !== 4) throw new RuntimeException('El pico ±30 debía ser 4/8.');
if ((float)($model['window_peak']['percentage'] ?? -1) !== 50.0) throw new RuntimeException('El pico ±30 debía ser 50 %.');
if ((float)($model['red_window_percentage'] ?? -1) !== 100.0) throw new RuntimeException('Las cintas 7 y 8 aparecen en la ventana completa.');
if (($model['nearby_flights'] ?? null) !== 6 || ($model['unknown_belt_flights'] ?? null) !== 1) throw new RuntimeException('El recuento de coincidencias o incógnitas es incorrecto.');
if (($model['confidence'] ?? null) !== 'baja') throw new RuntimeException('Una cinta desconocida debe reducir la confianza a baja.');
$windowFlights = $model['window_flights'] ?? [];
if (count($windowFlights) !== 6) throw new RuntimeException('La ventana detallada debe conservar los seis vuelos cercanos.');
$redBefore = array_values(array_filter($windowFlights, static fn(array $item): bool => ($item['flight'] ?? '') === 'RED0007'))[0] ?? [];
if (($redBefore['offset_minutes'] ?? null) !== -10 || empty($redBefore['occupies_at_target'])) {
    throw new RuntimeException('El vuelo rojo de diez minutos antes debe seguir ocupando C7 en la ETA objetivo.');
}
$redAfter = array_values(array_filter($windowFlights, static fn(array $item): bool => ($item['flight'] ?? '') === 'NEXT008'))[0] ?? [];
if (($redAfter['offset_minutes'] ?? null) !== 20 || !empty($redAfter['occupies_at_target'])) {
    throw new RuntimeException('El vuelo de C8 debe constar veinte minutos después, pero no activo en la ETA objetivo.');
}

fwrite(STDOUT, "MatrixEngine belt_occupancy_v2 OK\n");
