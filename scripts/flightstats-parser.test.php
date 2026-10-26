<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\IataFlightCode;
use SevillaMatrix\OperationalDay;
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

fwrite(STDOUT, "Parser FlightStats PHP: OK\n");
