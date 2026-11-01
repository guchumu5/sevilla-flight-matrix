<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\IataFlightCode;
use SevillaMatrix\OperationalDay;
use SevillaMatrix\Sync\FlightAwareActivityParser;
use SevillaMatrix\Sync\FlightFeederService;
use SevillaMatrix\Sync\FlightStatsParser;

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, sprintf("%s\nEsperado: %s\nObtenido: %s\n", $message, var_export($expected, true), var_export($actual, true)));
    exit(1);
}

$iata = IataFlightCode::split('VLG3190', ['IBE5662', 'IB5662']);
expectSame('VY', $iata['carrier'] ?? null, 'VLG3190 debe traducirse a VY.');
expectSame('3190', $iata['number'] ?? null, 'El número IATA de VLG3190 debe ser 3190.');
expectSame(['3189', '3191'], IataFlightCode::pairedNumbers('3190'), 'VY3190 procede de VY3189.');
expectSame(['3050', '3052'], IataFlightCode::pairedNumbers('3051'), 'VY3051 procede de VY3050.');
expectSame('VY', IataFlightCode::split('VLG3190', ['IB5662'])['carrier'] ?? null, 'El operador físico prevalece sobre el código compartido.');
expectSame('I2', IataFlightCode::split('IBS3910')['carrier'] ?? null, 'IBS debe traducirse a I2.');

$parts = IataFlightCode::trackerDate('2026-10-27 01:15:00');
expectSame('2026-10-26', $parts['iso'], 'La madrugada operativa debe usar el día anterior en FlightStats.');
expectSame('2026-10-26', OperationalDay::current(new DateTimeImmutable('2026-10-27 01:15:00')), 'Coherencia con el día operativo.');

$payload = json_decode((string)file_get_contents(dirname(__DIR__) . '/scripts/fixtures/flightstats-vy3190.json'), true);
$parsed = FlightStatsParser::fromJson($payload);
expectSame(20, $parsed['delay_minutes'] ?? null, 'El JSON de VY3190 debe leer 20 min de retraso.');
expectSame('2026-10-26 23:55:00', $parsed['eta'] ?? null, 'ETA local 23:55.');
expectSame('2026-10-26 20:44:00', $parsed['actual_departure'] ?? null, 'Salida real 20:44.');
expectSame(null, $parsed['actual_arrival'] ?? null, 'No debe marcar llegada real si sigue en ruta.');
if (str_contains(json_encode($parsed), 'baggage')) {
    fwrite(STDERR, "El parser no debe copiar la cinta publicada por FlightStats.\n");
    exit(1);
}

$html = FlightStatsParser::fromHtml(
    'Delayed by 20m Scheduled 20:20 WET Actual 20:44 WET Scheduled 23:35 CET Estimated 23:55 CET',
    '2026-10-26 23:40:00'
);
expectSame(20, $html['delay_minutes'] ?? null, 'El HTML de reserva debe leer el retraso.');
expectSame('2026-10-26 23:55:00', $html['eta'] ?? null, 'ETA HTML 23:55.');

$live = json_decode((string)file_get_contents(dirname(__DIR__) . '/scripts/fixtures/flightstats-vy3190-20261101.json'), true);
$today = FlightStatsParser::fromJson($live);
expectSame(83, $today['delay_minutes'] ?? null, 'VY3190 del 1 nov debe leer +83 min.');
expectSame('2026-11-02 01:13:00', $today['eta'] ?? null, 'ETA local 01:13.');
expectSame('SVQ', $today['destination_iata'] ?? null, 'El destino debe ser Sevilla.');
expectSame(83, FlightStatsParser::parseDelayMinutes(null, 'Delayed by 1h 23m'), 'El texto 1h 23m son 83 min.');

$hours = FlightStatsParser::fromHtml(
    'Delayed by 1h 23m Scheduled 20:35 WET Estimated 21:58 WET Scheduled 23:50 CET Estimated 01:13 CET',
    '2026-11-01 23:50:00'
);
expectSame(83, $hours['delay_minutes'] ?? null, 'El HTML debe leer Delayed by 1h 23m.');
expectSame('2026-11-02 01:13:00', $hours['eta'] ?? null, 'ETA HTML 01:13 al día siguiente.');

$shell = FlightStatsParser::fromHtml(
    'On time Scheduled Track flight VY 3190 Estimated 23:50 CET',
    '2026-11-01 23:50:00'
);
expectSame(null, $shell, 'El HTML cascarón On time no debe valer como lectura.');
expectSame(true, FlightStatsParser::isPlaceholder([
    'status_text' => 'Scheduled · On time',
    'delay_minutes' => 0,
    'eta' => '2026-11-01 23:50:00',
], '2026-11-01 23:50:00'), 'On time con ETA programada es cascarón.');
expectSame('EC-LOP', FlightStatsParser::tailNumber([
    'positional' => ['flexTrack' => ['tailNumber' => 'EC-LOP']],
]), 'El JSON de FlightStats trae la matrícula en flexTrack.');
expectSame(false, FlightStatsParser::isPlaceholder([
    'status_text' => 'Scheduled · Delayed by 1h 23m',
    'delay_minutes' => 83,
    'eta' => '2026-11-02 01:13:00',
], '2026-11-01 23:50:00'), 'Un retraso JSON no es cascarón.');

$feeder = new FlightFeederService(new PDO('sqlite::memory:'));
expectSame(true, $feeder->isNightCanary([
    'is_canary' => 1,
    'origin_iata' => 'LPA',
    'scheduled_arrival' => '2026-11-01 23:50:00',
]), 'VLG3190 noche es Canarias nocturno.');
expectSame(true, $feeder->isNightCanary([
    'is_canary' => 1,
    'origin_iata' => 'TFN',
    'scheduled_arrival' => '2026-11-02 00:40:00',
]), 'La madrugada operativa también arrastra.');
expectSame(false, $feeder->isNightCanary([
    'is_canary' => 1,
    'origin_iata' => 'LPA',
    'scheduled_arrival' => '2026-11-01 12:00:00',
]), 'Un Canarias de mediodía no entra en arrastre.');

$activity = json_decode((string)file_get_contents(dirname(__DIR__) . '/scripts/fixtures/flightaware-eclop-activity.json'), true);
$sector = FlightAwareActivityParser::previousSector($activity, 'VLG3190', 'LPA');
expectSame('VY3191', $sector['feeder_code'] ?? null, 'El avión de VY3190 venía de VY3191, no de la ida 3189.');
expectSame('SVQ', $sector['origin_iata'] ?? null, 'VY3191 salió de SVQ.');
expectSame('LPA', $sector['destination_iata'] ?? null, 'VY3191 aterrizó en LPA.');
expectSame(79, $sector['delay_minutes'] ?? null, 'VY3191 llegó a LPA con +79.');
expectSame('EC-LOP', $sector['aircraft_registration'] ?? null, 'La matrícula es EC-LOP.');
expectSame('same_aircraft', $sector['match_reason'] ?? null, 'El enlace es por la misma aeronave.');

fwrite(STDOUT, "Parser FlightStats PHP: OK\n");
