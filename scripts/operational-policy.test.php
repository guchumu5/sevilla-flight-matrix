<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\OperationalDay;
use SevillaMatrix\TrackingCadence;

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, sprintf("%s\nEsperado: %s\nObtenido: %s\n", $message, var_export($expected, true), var_export($actual, true)));
    exit(1);
}

expectSame('2026-10-19', OperationalDay::current(new DateTimeImmutable('2026-10-20 01:59:00')), 'La madrugada debe pertenecer al día operativo anterior.');
expectSame('2026-10-20', OperationalDay::current(new DateTimeImmutable('2026-10-20 02:00:00')), 'El nuevo día operativo debe comenzar a las 02:00.');
expectSame(true, OperationalDay::contains('2026-10-20 00:15:00', '2026-10-19'), 'Una llegada de madrugada debe permanecer en la jornada de salida.');
expectSame(false, OperationalDay::contains('2026-10-20 02:00:00', '2026-10-19'), 'Las 02:00 deben quedar fuera de la jornada anterior.');

expectSame(5, TrackingCadence::intervalMinutes(61, true, false), 'Un vuelo vigilado debe consultarse cada 5 minutos aunque falte más de una hora.');
expectSame(5, TrackingCadence::intervalMinutes(60, true, false), 'T-60 vigilado debe consultar cada 5 minutos.');
expectSame(5, TrackingCadence::intervalMinutes(21, true, false), 'T-21 vigilado debe consultar cada 5 minutos.');
expectSame(5, TrackingCadence::intervalMinutes(20, true, false), 'T-20 debe conservar la consulta cada 5 minutos.');
expectSame(5, TrackingCadence::intervalMinutes(-25, true, false), 'La entrega de equipaje debe conservar los 5 minutos.');
expectSame(5, TrackingCadence::intervalMinutes(-180, true, false), 'El refuerzo no debe caducar antes de que el vuelo finalice.');
expectSame(15, TrackingCadence::intervalMinutes(-25, true, true), 'Un vuelo finalizado debe abandonar el refuerzo.');
expectSame(15, TrackingCadence::intervalMinutes(10, false, false), 'Un vuelo no vigilado no debe consumir el refuerzo.');

fwrite(STDOUT, "Política operativa y cadencia: OK\n");
