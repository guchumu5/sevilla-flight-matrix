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

$coincidence = $target['belt_coincidence'] ?? null;
if (!is_array($coincidence) || ($coincidence['model'] ?? null) !== 'belt_coincidence_v1') throw new RuntimeException('No se generó belt_coincidence.');
if (($coincidence['target_belt'] ?? null) !== 1 || ($coincidence['target_belt_source'] ?? null) !== 'oficial') throw new RuntimeException('La coincidencia debe incluir la cinta del propio vuelo.');
if (($coincidence['green_window_minutes'] ?? null) !== 15) throw new RuntimeException('La ventana verde por defecto debe ser ±15 min.');
if (($coincidence['span_window_minutes'] ?? null) !== 30) throw new RuntimeException('La nube por defecto debe ser ±30 min.');
if (($coincidence['green_belts'] ?? null) !== [3]) throw new RuntimeException('Solo C3 (+10) cuenta en verdes ±15 min; C2 (−25) no entra en el cálculo.');
if (($coincidence['red_belts'] ?? null) !== [7, 8]) throw new RuntimeException('C7 (−10) y C8 (+20) entran en la ventana roja de ±20 min.');
if (($coincidence['rating'] ?? null) !== 'parcial') throw new RuntimeException('Con una sola verde la coincidencia debe ser parcial.');
if (($coincidence['unknown_belt_flights'] ?? null) !== 1) throw new RuntimeException('El vuelo sin cinta a +5 min debe constar como incógnita.');
$nearbyGreen = array_values(array_filter($coincidence['flights'] ?? [], static fn(array $item): bool => ($item['flight'] ?? '') === 'OLD0001'))[0] ?? [];
if (($nearbyGreen['offset_minutes'] ?? null) !== -25 || !empty($nearbyGreen['counts'])) {
    throw new RuntimeException('C2 a −25 min debe verse en la nube ±30 y no contar para el cálculo.');
}

$wide = array_values(array_filter((new MatrixEngine())->decorate($flights, [], ['green_window_minutes' => 30]), static fn(array $flight): bool => $flight['id'] === 1))[0]['belt_coincidence'] ?? [];
if (($wide['green_belts'] ?? null) !== [2, 3] || ($wide['green_window_minutes'] ?? null) !== 30) {
    throw new RuntimeException('Con verdes a ±30 min, C2 (−25) también debe coincidir.');
}

$withGreen = array_merge($flights, [sampleFlight(7, 'GREEN05', '2026-10-15 09:55:00', '5')]);
$perfect = array_values(array_filter((new MatrixEngine())->decorate($withGreen), static fn(array $flight): bool => $flight['id'] === 1))[0]['belt_coincidence'] ?? [];
if (($perfect['green_belts'] ?? null) !== [3, 5] || ($perfect['rating'] ?? null) !== 'perfecto') {
    throw new RuntimeException('Dos verdes y dos rojas en la franja deben dar un vuelo perfecto.');
}
if (($perfect['score_10'] ?? null) !== 9) {
    throw new RuntimeException('2 rojas y 2 verdes deben puntuar 9/10.');
}
$ten = array_values(array_filter((new MatrixEngine())->decorate(array_merge($withGreen, [sampleFlight(8, 'GREEN04', '2026-10-15 10:08:00', '4')])), static fn(array $flight): bool => $flight['id'] === 1))[0]['belt_coincidence'] ?? [];
if (($ten['score_10'] ?? null) !== 10 || ($ten['green_count'] ?? 0) < 3) {
    throw new RuntimeException('2 rojas y 3 verdes deben puntuar 10/10.');
}

$profile = [
    'global' => ['median' => -4, 'samples' => 500],
    'flights' => [],
    'origins' => ['LPA' => ['median' => -8, 'samples' => 40]],
    'hours' => [],
];
$shifted = array_values(array_filter((new MatrixEngine())->decorate($flights, $profile), static fn(array $flight): bool => $flight['id'] === 1))[0] ?? [];
if (($shifted['expected_arrival'] ?? null) !== '2026-10-15 09:52:00' || ($shifted['expected_arrival_basis'] ?? null) !== 'media_origen') {
    throw new RuntimeException('Sin ETA publicada se debe aplicar la mediana del origen (−8 min).');
}
$general = array_values(array_filter((new MatrixEngine())->decorate($flights, $profile), static fn(array $flight): bool => $flight['id'] === 2))[0] ?? [];
if (($general['expected_arrival'] ?? null) !== '2026-10-15 09:31:00' || ($general['expected_arrival_basis'] ?? null) !== 'media_general') {
    throw new RuntimeException('Sin datos del origen se aplica la mediana general.');
}

$withSecondCanary = array_merge($flights, [sampleFlight(9, 'IB3500', '2026-10-15 10:12:00', '4', [
    'origin_iata' => 'TFN',
    'origin_name' => 'Tenerife Norte',
    'is_canary' => 1,
])]);
$canaryPriority = (new MatrixEngine())->decorate($withSecondCanary, [], [], 'canary');
$canaryTarget = array_values(array_filter($canaryPriority, static fn(array $flight): bool => $flight['id'] === 1))[0] ?? [];
$nonCanary = array_values(array_filter($canaryPriority, static fn(array $flight): bool => $flight['id'] === 2))[0] ?? [];
if (($nonCanary['belt_coincidence'] ?? null) !== null) {
    throw new RuntimeException('Con prioridad canary no se calcula coincidencia de vuelos que no son Canarias.');
}
if (!empty($nonCanary['belt_occupancy_30m'])) {
    throw new RuntimeException('Con prioridad canary no se calcula ocupación de pasillo para el resto.');
}
$priorityCoincidence = $canaryTarget['belt_coincidence'] ?? null;
if (!is_array($priorityCoincidence) || ($priorityCoincidence['model'] ?? null) !== 'belt_coincidence_v1') {
    throw new RuntimeException('El vuelo canario debe conservar belt_coincidence con prioridad canary.');
}
$priorityFlights = $priorityCoincidence['flights'] ?? [];
$firstAround = $priorityFlights[0] ?? [];
if (($firstAround['flight'] ?? '') !== 'IB3500' || (int)($firstAround['is_canary'] ?? 0) !== 1) {
    throw new RuntimeException('Los vuelos Canarias deben ir primero en la lista de alrededor.');
}
$redAround = array_values(array_filter($priorityFlights, static fn(array $item): bool => ($item['flight'] ?? '') === 'RED0007'))[0] ?? [];
if (($redAround['offset_minutes'] ?? null) !== -10 || empty($redAround['counts'])) {
    throw new RuntimeException('El resto de vuelos sigue en la nube aunque no sean Canarias.');
}
if (!array_key_exists('is_canary', $coincidence['flights'][0] ?? [])) {
    throw new RuntimeException('decorate() por defecto también marca is_canary en la nube.');
}

fwrite(STDOUT, "MatrixEngine belt_occupancy_v2 + belt_coincidence_v1 OK\n");
