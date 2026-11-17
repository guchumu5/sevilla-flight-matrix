<?php
declare(strict_types=1);

namespace SevillaMatrix\Analysis;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class CanaryBeltLeadAnalyzer
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{summary: array<string,mixed>, markdown: string}
     */
    public function run(string $from, string $to): array
    {
        $normBelt = static function (?string $belt): ?string {
            $belt = trim((string)$belt);
            if ($belt === '' || strcasecmp($belt, 'null') === 0 || $belt === '-') {
                return null;
            }
            if (preg_match('/(\d{1,2})/', $belt, $m)) {
                return (string)(int)$m[1];
            }
            return null;
        };
        $isRed = static fn(?string $b): bool => $b !== null && in_array($b, ['7', '8'], true);

        $stmt = $this->pdo->prepare(
            "SELECT f.id, f.physical_flight, f.origin_iata, f.origin_name, f.flight_date,
                    f.scheduled_arrival, f.scheduled_departure, f.is_canary
             FROM flights f
             WHERE f.is_canary = 1
               AND f.flight_date BETWEEN :from_day AND :to_day
             ORDER BY f.scheduled_arrival, f.id"
        );
        $stmt->execute(['from_day' => $from, 'to_day' => $to]);
        $flights = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $obsStmt = $this->pdo->prepare(
            "SELECT source, observed_at, hall, belt, status, actual_departure, actual_arrival, eta
             FROM observations
             WHERE flight_id = :id
               AND source IN ('aena','aeroapi','airlabs','flightstats')
             ORDER BY observed_at ASC, id ASC"
        );

        $summary = [
            'from' => $from,
            'to' => $to,
            'timezone' => 'Europe/Madrid',
            'canary_flights' => count($flights),
            'with_aena_belt_before_dep' => 0,
            'aena_later_changed' => 0,
            'aena_ended_red' => 0,
            'secondary_led_final_belt' => 0,
            'secondary_led_red_change' => 0,
            'cases' => [],
        ];

        foreach ($flights as $flight) {
            $obsStmt->execute(['id' => (int)$flight['id']]);
            $rows = $obsStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                continue;
            }

            $depAt = null;
            foreach ($rows as $row) {
                if (!empty($row['actual_departure'])) {
                    $depAt = (string)$row['actual_departure'];
                    break;
                }
            }
            if ($depAt === null && !empty($flight['scheduled_departure'])) {
                $depAt = (string)$flight['scheduled_departure'];
            }
            if ($depAt === null && !empty($flight['scheduled_arrival'])) {
                $depAt = (new DateTimeImmutable((string)$flight['scheduled_arrival']))
                    ->modify('-3 hours')->format('Y-m-d H:i:s');
            }

            $aenaTimeline = [];
            $secondaryHits = [];
            foreach ($rows as $row) {
                $belt = $normBelt($row['belt'] ?? null);
                if ($belt === null) {
                    continue;
                }
                $at = (string)$row['observed_at'];
                $source = (string)$row['source'];
                $hall = trim((string)($row['hall'] ?? '')) ?: null;
                if ($source === 'aena') {
                    $last = $aenaTimeline !== [] ? $aenaTimeline[count($aenaTimeline) - 1] : null;
                    if ($last === null || $last['belt'] !== $belt) {
                        $aenaTimeline[] = [
                            'at' => $at,
                            'belt' => $belt,
                            'hall' => $hall,
                            'status' => $row['status'] ?? null,
                        ];
                    }
                } else {
                    $secondaryHits[] = [
                        'source' => $source,
                        'at' => $at,
                        'belt' => $belt,
                        'hall' => $hall,
                    ];
                }
            }

            if (count($aenaTimeline) < 1) {
                continue;
            }

            $firstAena = $aenaTimeline[0];
            $confirmedBeforeDep = $depAt !== null && $firstAena['at'] < $depAt;
            if (!$confirmedBeforeDep) {
                continue;
            }
            $summary['with_aena_belt_before_dep']++;

            if (count($aenaTimeline) < 2) {
                continue;
            }
            $summary['aena_later_changed']++;

            $final = $aenaTimeline[count($aenaTimeline) - 1];
            $endedRed = $isRed($final['belt']);
            if ($endedRed) {
                $summary['aena_ended_red']++;
            }

            $transitions = [];
            for ($i = 1, $n = count($aenaTimeline); $i < $n; $i++) {
                $before = $aenaTimeline[$i - 1];
                $after = $aenaTimeline[$i];
                $lead = null;
                foreach ($secondaryHits as $hit) {
                    if ($hit['belt'] !== $after['belt']) {
                        continue;
                    }
                    if ($hit['at'] >= $after['at']) {
                        continue;
                    }
                    if ($lead === null || $hit['at'] < $lead['at']) {
                        $lead = $hit;
                    }
                }
                $leadMinutes = null;
                if ($lead !== null) {
                    $leadMinutes = (int)round((strtotime($after['at']) - strtotime($lead['at'])) / 60);
                }
                $transitions[] = [
                    'from' => ($before['hall'] ? $before['hall'] . '/' : '') . $before['belt'],
                    'to' => ($after['hall'] ? $after['hall'] . '/' : '') . $after['belt'],
                    'from_belt' => $before['belt'],
                    'to_belt' => $after['belt'],
                    'to_red' => $isRed($after['belt']),
                    'aena_at' => $after['at'],
                    'secondary_led' => $lead !== null,
                    'secondary' => $lead,
                    'lead_minutes' => $leadMinutes,
                ];
            }

            $anyLead = false;
            $anyRedLead = false;
            foreach ($transitions as $t) {
                if ($t['secondary_led']) {
                    $anyLead = true;
                    if ($t['to_red']) {
                        $anyRedLead = true;
                    }
                }
            }
            if ($anyLead) {
                $summary['secondary_led_final_belt']++;
            }
            if ($anyRedLead) {
                $summary['secondary_led_red_change']++;
            }

            if (!$endedRed && !$anyLead) {
                continue;
            }

            $summary['cases'][] = [
                'flight_id' => (int)$flight['id'],
                'code' => $flight['physical_flight'],
                'origin' => $flight['origin_iata'] . ' · ' . $flight['origin_name'],
                'date' => substr((string)$flight['flight_date'], 0, 10),
                'scheduled_arrival' => $flight['scheduled_arrival'],
                'departure_ref' => $depAt,
                'first_aena_before_dep' => ($firstAena['hall'] ? $firstAena['hall'] . '/' : '') . $firstAena['belt'],
                'first_aena_at' => $firstAena['at'],
                'final_aena' => ($final['hall'] ? $final['hall'] . '/' : '') . $final['belt'],
                'final_aena_at' => $final['at'],
                'ended_red' => $endedRed,
                'aena_timeline' => array_map(
                    static fn(array $s): string => substr($s['at'], 5, 11) . ' '
                        . ($s['hall'] ? $s['hall'] . '/' : '') . $s['belt'],
                    $aenaTimeline
                ),
                'transitions' => $transitions,
                'secondary_led_any' => $anyLead,
                'secondary_led_red' => $anyRedLead,
            ];
        }

        usort($summary['cases'], static function (array $a, array $b): int {
            $score = static function (array $c): int {
                return ($c['secondary_led_red'] ? 4 : 0)
                    + ($c['ended_red'] ? 2 : 0)
                    + ($c['secondary_led_any'] ? 1 : 0);
            };
            $cmp = $score($b) <=> $score($a);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)$a['scheduled_arrival'], (string)$b['scheduled_arrival']);
        });

        $lines = [];
        $lines[] = '# Canarias · adelanto de cinta secundaria vs Aena';
        $lines[] = "Ventana: {$from} → {$to} (Europe/Madrid)";
        $lines[] = '';
        $lines[] = sprintf(
            'Vuelos Canarias: %d · Con cinta Aena antes de despegue: %d · Aena cambió después: %d · Acabaron en 7/8: %d',
            $summary['canary_flights'],
            $summary['with_aena_belt_before_dep'],
            $summary['aena_later_changed'],
            $summary['aena_ended_red']
        );
        $lines[] = sprintf(
            'Secundaria adelantó algún cambio: %d · Adelantó cambio a roja 7/8: %d',
            $summary['secondary_led_final_belt'],
            $summary['secondary_led_red_change']
        );
        $lines[] = '';

        foreach ($summary['cases'] as $case) {
            $flags = [];
            if ($case['ended_red']) {
                $flags[] = 'FINAL_ROJA';
            }
            if ($case['secondary_led_red']) {
                $flags[] = 'SECUNDARIA_ADELANTÓ_ROJA';
            } elseif ($case['secondary_led_any']) {
                $flags[] = 'SECUNDARIA_ADELANTÓ';
            }
            $lines[] = sprintf(
                '## %s · %s · %s %s',
                $case['code'],
                $case['origin'],
                $case['date'],
                $flags !== [] ? '[' . implode(', ', $flags) . ']' : ''
            );
            $lines[] = sprintf(
                'Primera Aena antes dep: %s @ %s · Final Aena: %s @ %s · dep_ref %s',
                $case['first_aena_before_dep'],
                $case['first_aena_at'],
                $case['final_aena'],
                $case['final_aena_at'],
                $case['departure_ref']
            );
            $lines[] = 'Timeline Aena: ' . implode(' → ', $case['aena_timeline']);
            foreach ($case['transitions'] as $t) {
                if ($t['secondary_led']) {
                    $lines[] = sprintf(
                        '  • %s → %s (Aena %s): %s tenía %s desde %s (**%d min antes**)',
                        $t['from'],
                        $t['to'],
                        $t['aena_at'],
                        strtoupper((string)$t['secondary']['source']),
                        ($t['secondary']['hall'] ? $t['secondary']['hall'] . '/' : '') . $t['secondary']['belt'],
                        $t['secondary']['at'],
                        (int)$t['lead_minutes']
                    );
                } else {
                    $lines[] = sprintf(
                        '  • %s → %s (Aena %s): sin adelanto secundario en DB',
                        $t['from'],
                        $t['to'],
                        $t['aena_at']
                    );
                }
            }
            $lines[] = '';
        }

        return [
            'summary' => $summary,
            'markdown' => implode("\n", $lines) . "\n",
        ];
    }
}
