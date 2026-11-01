<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\BeltAlarm;
use SevillaMatrix\OperationalDay;
use SevillaMatrix\OperationalStatus;
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

expectSame('delayed', OperationalStatus::phase('Scheduled · Delayed by 1h 23m'), 'Un retraso de FlightStats no es «en hora».');
expectSame('scheduled', OperationalStatus::phase('Scheduled · On time'), 'On time debe agruparse con programado.');
expectSame(true, OperationalStatus::isCosmeticChange('Scheduled · On time', 'Scheduled'), 'No avisar por un cambio de texto sin cambio de fase.');
expectSame(false, OperationalStatus::isCosmeticChange('Scheduled · On time', 'Scheduled · Delayed by 1h 23m'), 'El paso a retraso sí es un aviso.');
expectSame(false, OperationalStatus::isActionablePhase('Programado'), 'Programado / On time no debe generar aviso de estado.');
expectSame(true, OperationalStatus::isActionablePhase('Delayed by 1h 23m'), 'Un retraso sí es un aviso de estado.');

expectSame(true, BeltAlarm::isBlockChange('A/3', 'B/7'), 'A/3 → B/7 es el cambio de bloque que hay que alarmar.');
expectSame(true, BeltAlarm::isBlockChange('3', '7'), 'El dígito 3 → 7 también es cambio de bloque.');
expectSame(false, BeltAlarm::isBlockChange('A/3', 'A/4'), 'Un cambio dentro de 1–6 no es alarma de bloque.');
expectSame('A/3 → B/7 · cambio de bloque 1–6 a 7–8. Hay que moverse YA.', BeltAlarm::pushBody('A/3', 'B/7'), 'El texto de alarma debe decir el bloque.');

fwrite(STDOUT, "Política operativa y cadencia: OK\n");
