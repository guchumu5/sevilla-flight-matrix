<?php
declare(strict_types=1);

namespace SevillaMatrix;

use DateTimeImmutable;

final class MatrixEngine
{
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
                ? 'STAND BY'
                : (($flight['belt'] === '7' || $flight['belt'] === '8') ? '🔴' : '') . $flight['hall'] . '/' . $flight['belt'];
        }
        unset($flight);

        return $this->addHallLoad($flights);
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

    private function minutesBetween(string $from, string $to): int
    {
        return (int)round(((new DateTimeImmutable($to))->getTimestamp() - (new DateTimeImmutable($from))->getTimestamp()) / 60);
    }
}
