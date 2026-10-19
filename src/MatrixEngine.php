<?php
declare(strict_types=1);

namespace SevillaMatrix;

use DateTimeImmutable;

final class MatrixEngine
{
    private const BELT_COUNT = 8;
    private const RED_BELTS = [7, 8];
    private const OCCUPANCY_WINDOW_MINUTES = 30;

    public function decorate(array $flights): array
    {
        usort($flights, fn(array $a, array $b) => strcmp($a['effective_arrival'], $b['effective_arrival']));

        foreach ($flights as $index => &$flight) {
            $flight['deviation_minutes'] = $this->minutesBetween(
                $flight['scheduled_arrival'],
                $flight['effective_arrival']
            );
            $flight['previous_same_belt_minutes'] = null;
            $flight['next_same_belt_minutes'] = null;

            if (!empty($flight['belt'])) {
                for ($i = $index - 1; $i >= 0; $i--) {
                    if ($flights[$i]['hall'] === $flight['hall'] && $flights[$i]['belt'] === $flight['belt']) {
                        $flight['previous_same_belt_minutes'] = abs($this->minutesBetween(
                            $flights[$i]['effective_arrival'],
                            $flight['effective_arrival']
                        ));
                        break;
                    }
                }
                for ($i = $index + 1, $count = count($flights); $i < $count; $i++) {
                    if ($flights[$i]['hall'] === $flight['hall'] && $flights[$i]['belt'] === $flight['belt']) {
                        $flight['next_same_belt_minutes'] = abs($this->minutesBetween(
                            $flight['effective_arrival'],
                            $flights[$i]['effective_arrival']
                        ));
                        break;
                    }
                }
            }

            $flight['pressure'] = $this->pressure($flight);
            $flight['secondary_belt_state'] = $this->secondaryBeltState($flight);
            $flight['indicator'] = $this->indicator($flight);
            $flight['belt_label'] = empty($flight['belt'])
                ? 'CINTA PENDIENTE · Aena'
                : (($flight['belt'] === '7' || $flight['belt'] === '8') ? '🔴' : '') . $flight['hall'] . '/' . $flight['belt'];
        }
        unset($flight);

        $flights = $this->addHallLoad($flights);
        return $this->addBeltOccupancy($flights);
    }

    private function pressure(array $flight): string
    {
        $intervals = array_filter([
            $flight['previous_same_belt_minutes'],
            $flight['next_same_belt_minutes'],
        ], fn($value) => $value !== null);
        $minimum = $intervals ? min($intervals) : null;
        if ($minimum !== null && $minimum <= 15) return 'muy_alta';
        if ($minimum !== null && $minimum <= 30) return 'alta';
        if ($minimum !== null && $minimum <= 60) return 'media';
        return 'baja';
    }

    private function indicator(array $flight): string
    {
        if (($flight['baggage_state'] ?? '') === 'finalizado') return '🟢✓⁺';
        if (($flight['baggage_state'] ?? '') === 'entrega') return '🟠🟢';

        $isAirborne = preg_match('/vuelo|aproxim|en.route/i', $flight['status'] ?? '') === 1;
        $isOrange = abs((int)$flight['deviation_minutes']) >= 15
            || in_array($flight['pressure'], ['alta', 'muy_alta'], true)
            || ((int)($flight['belt_changes'] ?? 0) > 0);

        if ($isAirborne && $isOrange) return '🟠🔵';
        if ($isAirborne) return '🔵';
        if ($isOrange) return '🟠';
        return !empty($flight['belt']) ? '🟢' : '⚪';
    }

    private function secondaryBeltState(array $flight): ?string
    {
        if (($flight['source'] ?? null) !== 'aena' || empty($flight['secondary_belt'])) {
            return null;
        }

        $sameBelt = (string)($flight['belt'] ?? '') === (string)$flight['secondary_belt'];
        $sameHall = empty($flight['secondary_hall']) || empty($flight['hall'])
            || (string)$flight['hall'] === (string)$flight['secondary_hall'];
        if ($sameBelt && $sameHall) {
            return 'confirmed';
        }

        $officialAt = $flight['authoritative_seen_at'] ?? $flight['observed_at'] ?? null;
        $secondaryAt = $flight['secondary_belt_at'] ?? null;
        if ($officialAt && $secondaryAt && strcmp((string)$secondaryAt, (string)$officialAt) <= 0) {
            return 'not_confirmed';
        }

        return 'candidate';
    }

    private function addHallLoad(array $flights): array
    {
        foreach ($flights as &$flight) {
            $center = new DateTimeImmutable($flight['effective_arrival']);
            $capacity = 0;
            $count = 0;
            foreach ($flights as $candidate) {
                if (($candidate['hall'] ?: 'A') !== ($flight['hall'] ?: 'A')) continue;
                $minutes = abs(($center->getTimestamp() - (new DateTimeImmutable($candidate['effective_arrival']))->getTimestamp()) / 60);
                if ($minutes <= 30) {
                    $count++;
                    $capacity += (int)($candidate['capacity'] ?: 180);
                }
            }
            $flight['hall_flights_30m'] = $count;
            $flight['hall_capacity_30m'] = $capacity;
            $flight['hall_load'] = $count >= 6 ? 'alta' : ($count >= 3 ? 'media' : 'baja');
        }
        unset($flight);
        return $flights;
    }

    /**
     * Estimación matemática de ocupación para cada llegada.
     *
     * Una cinta se considera ocupada desde la llegada efectiva del vuelo
     * hasta 30 minutos después. Para cada vuelo se calcula:
     *  - ocupación simultánea en el instante de su ETA/real;
     *  - pico de cintas ocupadas dentro de la ventana ETA ±30 minutos;
     *  - ocupación separada del bloque 7/8.
     *
     * Aena prevalece. Si aún no existe cinta oficial se utiliza, por orden,
     * una cinta secundaria publicada y la predicción histórica. La fuente se
     * conserva en el resultado para que la interfaz no presente una previsión
     * como confirmación.
     *
     * @param array<int,array<string,mixed>> $flights
     * @return array<int,array<string,mixed>>
     */
    private function addBeltOccupancy(array $flights): array
    {
        $serviceSeconds = self::OCCUPANCY_WINDOW_MINUTES * 60;
        $resolved = [];
        foreach ($flights as $candidate) {
            $arrival = strtotime((string)($candidate['effective_arrival'] ?? ''));
            if ($arrival === false) continue;
            $assignment = $this->resolvedBelt($candidate);
            $resolved[] = [
                'id' => (int)($candidate['id'] ?? 0),
                'flight' => (string)($candidate['physical_flight'] ?? ''),
                'origin' => (string)($candidate['origin_name'] ?? $candidate['origin_iata'] ?? ''),
                'arrival' => $arrival,
                'arrival_text' => date('Y-m-d H:i:s', $arrival),
                'end' => $arrival + $serviceSeconds,
                'belt' => $assignment['belt'],
                'hall' => $assignment['hall'],
                'assignment_source' => $assignment['source'],
            ];
        }

        foreach ($flights as &$flight) {
            $target = strtotime((string)($flight['effective_arrival'] ?? ''));
            if ($target === false) {
                $flight['belt_occupancy_30m'] = null;
                continue;
            }

            $windowFrom = $target - $serviceSeconds;
            $windowTo = $target + $serviceSeconds;
            $events = [$windowFrom, $target, $windowTo];
            $nearbyFlights = [];
            $unknown = 0;
            $context = [];

            foreach ($resolved as $candidate) {
                if (abs($candidate['arrival'] - $target) <= $serviceSeconds) {
                    $nearbyFlights[] = $candidate;
                    if ($candidate['belt'] === null) $unknown++;
                }
                if ($candidate['end'] < $windowFrom || $candidate['arrival'] > $windowTo) continue;
                $context[] = $candidate;
                $events[] = max($windowFrom, $candidate['arrival']);
                $events[] = min($windowTo, $candidate['end']);
            }

            $atArrival = $this->occupancyAt($context, $target);
            $peak = $atArrival;
            $peakAt = $target;
            foreach (array_values(array_unique($events)) as $eventAt) {
                $snapshot = $this->occupancyAt($context, (int)$eventAt);
                if ($snapshot['occupied_count'] > $peak['occupied_count']) {
                    $peak = $snapshot;
                    $peakAt = (int)$eventAt;
                }
            }

            $redInWindow = [];
            foreach ($context as $candidate) {
                if (in_array($candidate['belt'], self::RED_BELTS, true)) {
                    $redInWindow[$candidate['belt']] = true;
                }
            }

            $sources = array_values(array_unique(array_filter(array_column($nearbyFlights, 'assignment_source'))));
            $confidence = $unknown > 0 ? 'baja'
                : (array_diff($sources, ['oficial']) === [] ? 'alta' : 'media');

            $windowFlights = array_map(static function (array $candidate) use ($target): array {
                $offsetMinutes = (int)round(($candidate['arrival'] - $target) / 60);
                return [
                    'id' => $candidate['id'],
                    'flight' => $candidate['flight'],
                    'origin' => $candidate['origin'],
                    'arrival' => $candidate['arrival_text'],
                    'service_end' => date('Y-m-d H:i:s', $candidate['end']),
                    'offset_minutes' => $offsetMinutes,
                    'belt' => $candidate['belt'],
                    'hall' => $candidate['hall'],
                    'assignment_source' => $candidate['assignment_source'],
                    'occupies_at_target' => $candidate['belt'] !== null
                        && $candidate['arrival'] <= $target
                        && $candidate['end'] >= $target,
                ];
            }, $nearbyFlights);
            usort($windowFlights, static fn(array $a, array $b): int =>
                ($a['offset_minutes'] <=> $b['offset_minutes']) ?: ($a['id'] <=> $b['id'])
            );

            $flight['belt_occupancy_30m'] = [
                'model' => 'belt_occupancy_v2',
                'target_time' => date('Y-m-d H:i:s', $target),
                'window_from' => date('Y-m-d H:i:s', $windowFrom),
                'window_to' => date('Y-m-d H:i:s', $windowTo),
                'assumed_service_minutes' => self::OCCUPANCY_WINDOW_MINUTES,
                'at_arrival' => $atArrival,
                'window_peak' => array_merge($peak, [
                    'at' => date('Y-m-d H:i:s', $peakAt),
                ]),
                'red_belts_in_window' => array_values(array_map('intval', array_keys($redInWindow))),
                'red_window_percentage' => round((count($redInWindow) / count(self::RED_BELTS)) * 100, 1),
                'nearby_flights' => count($nearbyFlights),
                'window_flights' => $windowFlights,
                'unknown_belt_flights' => $unknown,
                'confidence' => $confidence,
            ];
        }
        unset($flight);
        return $flights;
    }

    /** @param array<string,mixed> $flight @return array{belt:?int,hall:?string,source:?string} */
    private function resolvedBelt(array $flight): array
    {
        $belt = $this->beltNumber($flight['belt'] ?? null);
        if ($belt !== null) {
            return [
                'belt' => $belt,
                'hall' => $this->resolvedHall($flight['hall'] ?? null, $belt),
                'source' => ($flight['source'] ?? null) === 'aena' ? 'oficial' : 'provisional',
            ];
        }

        $belt = $this->beltNumber($flight['secondary_belt'] ?? null);
        if ($belt !== null) {
            return [
                'belt' => $belt,
                'hall' => $this->resolvedHall($flight['secondary_hall'] ?? null, $belt),
                'source' => 'provisional',
            ];
        }

        $belt = $this->beltNumber($flight['predicted_belt'] ?? null);
        return [
            'belt' => $belt,
            'hall' => $belt === null ? null : $this->resolvedHall($flight['predicted_hall'] ?? null, $belt),
            'source' => $belt === null ? null : 'prediccion',
        ];
    }

    private function resolvedHall(mixed $hall, int $belt): string
    {
        $value = strtoupper(trim((string)$hall));
        if ($value === 'A' || $value === 'B') return $value;
        return $belt >= 7 ? 'B' : 'A';
    }

    /**
     * @param array<int,array<string,mixed>> $context
     * @return array<string,mixed>
     */
    private function occupancyAt(array $context, int $timestamp): array
    {
        $byBelt = [];
        foreach ($context as $candidate) {
            if ($candidate['belt'] === null) continue;
            if ($candidate['arrival'] > $timestamp || $candidate['end'] < $timestamp) continue;
            $belt = (int)$candidate['belt'];
            $byBelt[$belt][] = [
                'id' => $candidate['id'],
                'flight' => $candidate['flight'],
                'origin' => $candidate['origin'],
                'arrival' => $candidate['arrival_text'],
                'hall' => $candidate['hall'],
                'belt' => $belt,
                'assignment_source' => $candidate['assignment_source'],
            ];
        }
        ksort($byBelt);
        $belts = array_values(array_map('intval', array_keys($byBelt)));
        $redBelts = array_values(array_intersect(self::RED_BELTS, $belts));
        return [
            'occupied_count' => count($belts),
            'total_belts' => self::BELT_COUNT,
            'percentage' => round((count($belts) / self::BELT_COUNT) * 100, 1),
            'belts' => $belts,
            'belt_flights' => $byBelt,
            'red_occupied_count' => count($redBelts),
            'red_total_belts' => count(self::RED_BELTS),
            'red_percentage' => round((count($redBelts) / count(self::RED_BELTS)) * 100, 1),
            'belt_7_occupied' => in_array(7, $redBelts, true),
            'belt_8_occupied' => in_array(8, $redBelts, true),
        ];
    }

    private function beltNumber(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!preg_match('/(?:^|\\D)([1-8])$/', trim((string)$value), $match)) return null;
        return (int)$match[1];
    }

    private function minutesBetween(string $from, string $to): int
    {
        return (int)round(((new DateTimeImmutable($to))->getTimestamp() - (new DateTimeImmutable($from))->getTimestamp()) / 60);
    }
}
