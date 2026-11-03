<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SevillaMatrix\IataFlightCode;
use SevillaMatrix\Sync\AeroApiParser;
use SevillaMatrix\Sync\AeroApiQuotaService;

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, sprintf("%s\nEsperado: %s\nObtenido: %s\n", $message, var_export($expected, true), var_export($actual, true)));
    exit(1);
}

$idents = IataFlightCode::aeroIdents('VLG3190', ['IBE5662']);
expectSame('VY', $idents['carrier'] ?? null, 'VLG3190 → VY');
expectSame('3190', $idents['number'] ?? null, 'número 3190');
expectSame('VY3190', $idents['iata'] ?? null, 'ident IATA');
expectSame('VLG3190', $idents['icao'] ?? null, 'ident ICAO preferido AeroAPI');

$payload = json_decode((string)file_get_contents(dirname(__DIR__) . '/scripts/fixtures/aeroapi-vlg3190.json'), true);
$flights = AeroApiParser::flightsFromResponse($payload);
expectSame(2, count($flights), 'fixture con 2 tramos');

$picked = AeroApiParser::pickLeg($flights, 'LPA', '2026-11-02 23:45:00', 'SVQ');
expectSame(true, is_array($picked), 'elige el tramo LPA→SVQ del día');
expectSame('VLG3190-1730572800-airline-0401', $picked['fa_flight_id'] ?? null, 'fa_flight_id del tramo en ruta');

$normalized = AeroApiParser::normalize($picked, '2026-11-02 23:45:00');
expectSame(20, $normalized['delay_minutes'] ?? null, 'arrival_delay 1200 s → 20 min');
expectSame('2026-11-03 01:05:00', $normalized['eta'] ?? null, 'estimated_in UTC → Europe/Madrid');
expectSame('2026-11-02 21:55:00', $normalized['actual_departure'] ?? null, 'actual_out local');
expectSame('2', $normalized['belt'] ?? null, 'baggage_claim → cinta provisional');
expectSame('B7', $normalized['gate'] ?? null, 'gate_destination');
expectSame('EC-NTU', $normalized['registration'] ?? null, 'matrícula');
expectSame('enroute', $normalized['phase'] ?? null, 'fase en ruta');

expectSame(1, AeroApiQuotaService::estimateResultSets($payload), '2 vuelos = 1 result set');
expectSame(2, AeroApiQuotaService::estimateResultSets(['num_pages' => 2, 'flights' => []]), 'num_pages manda');
expectSame(2, AeroApiQuotaService::estimateResultSets(['flights' => array_fill(0, 16, ['ident' => 'X'])]), '16 vuelos = 2 result sets');
expectSame(2300, AeroApiQuotaService::DEFAULT_MONTHLY_LIMIT, 'tope duro 2300');
expectSame('Europe/Madrid', AeroApiQuotaService::TIMEZONE, 'mes en Europe/Madrid');

// Regresión HY093: PDO nativo no reutiliza el mismo named placeholder dos veces.
foreach ([
    dirname(__DIR__) . '/src/Sync/AeroApiIngestService.php',
    dirname(__DIR__) . '/src/Sync/AeroApiQuotaService.php',
] as $sourcePath) {
    $source = (string)file_get_contents($sourcePath);
    if (!preg_match_all("/'(?:\\\\'|[^'])*'|\"(?:\\\\\"|[^\"])*\"/", $source, $stringMatches)) {
        continue;
    }
    foreach ($stringMatches[0] as $literal) {
        if (!preg_match_all('/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/', $literal, $params)) {
            continue;
        }
        $counts = array_count_values($params[1]);
        foreach ($counts as $name => $times) {
            if ($times > 1) {
                fwrite(STDERR, basename($sourcePath) . " reutiliza :{$name} {$times} veces (HY093 con EMULATE_PREPARES=false)\n");
                exit(1);
            }
        }
    }
}

fwrite(STDOUT, "aeroapi-parser.test.php OK\n");
