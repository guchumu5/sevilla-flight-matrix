<?php
declare(strict_types=1);

namespace SevillaMatrix\Analysis;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class BeltChangeAnalysisService
{
    public const MODEL_VERSION = 'belt_reason_v1';
    private const LOCK_NAME = 'sevilla_matrix_belt_analysis';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function analyzePreviousDayIfDue(): array
    {
        if (!$this->isAvailable()) return ['available' => false, 'skipped' => true, 'message' => 'Actualización MySQL pendiente.'];
        if ((int)date('G') < 4) {
            return ['available' => true, 'skipped' => true, 'message' => 'El cierre diario se ejecuta a partir de las 04:00.'];
        }
        return $this->analyzeDate(date('Y-m-d', strtotime('-1 day')));
    }

    /** @return array<string,mixed> */
    public function analyzeDate(string $date, bool $force = false): array
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Fecha de análisis no válida.');
        if (!$this->isAvailable()) throw new RuntimeException('Aplica primero la actualización MySQL del análisis de cintas.');

        $lock = $this->pdo->prepare('SELECT GET_LOCK(:name,0)');
        $lock->execute(['name' => self::LOCK_NAME]);
        if ((int)$lock->fetchColumn() !== 1) return ['available' => true, 'skipped' => true, 'message' => 'Ya hay otro análisis en curso.'];

        try {
            $existing = $this->pdo->prepare(
                'SELECT status,changes_found,analyses_created,finished_at FROM belt_change_analysis_runs
                 WHERE analysis_date=:date AND model_version=:model LIMIT 1'
            );
            $existing->execute(['date' => $date, 'model' => self::MODEL_VERSION]);
            $previous = $existing->fetch();
            if (!$force && $previous && $previous['status'] === 'success') {
                return [
                    'available' => true, 'skipped' => true, 'analysis_date' => $date,
                    'model_version' => self::MODEL_VERSION, 'changes_found' => (int)$previous['changes_found'],
                    'analyses_created' => (int)$previous['analyses_created'], 'finished_at' => $previous['finished_at'],
                    'message' => 'El día ya estaba analizado con esta versión del modelo.',
                ];
            }

            $this->pdo->prepare(
                "INSERT INTO belt_change_analysis_runs
                 (analysis_date,model_version,started_at,status,changes_found,analyses_created,error_message)
                 VALUES (:date,:model,NOW(),'running',0,0,NULL)
                 ON DUPLICATE KEY UPDATE started_at=NOW(),finished_at=NULL,status='running',error_message=NULL"
            )->execute(['date' => $date, 'model' => self::MODEL_VERSION]);

            $events = $this->eventsForDate($date);
            $created = 0;
            foreach ($events as $event) {
                $analysis = $this->analyzeEvent($event);
                $stmt = $this->pdo->prepare(
                    'INSERT IGNORE INTO belt_change_analyses
                     (flight_event_id,flight_id,analysis_date,analyzed_at,model_version,reason_code,
                      reason_label,reason_detail,confidence,official_reason,evidence)
                     VALUES (:event_id,:flight_id,:analysis_date,NOW(),:model,:reason_code,
                      :reason_label,:reason_detail,:confidence,:official_reason,:evidence)'
                );
                $stmt->execute([
                    'event_id' => $event['id'], 'flight_id' => $event['flight_id'], 'analysis_date' => $date,
                    'model' => self::MODEL_VERSION, 'reason_code' => $analysis['reason_code'],
                    'reason_label' => $analysis['reason_label'], 'reason_detail' => $analysis['reason_detail'],
                    'confidence' => $analysis['confidence'], 'official_reason' => $analysis['official_reason'] ? 1 : 0,
                    'evidence' => json_encode($analysis['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $created += $stmt->rowCount();
            }

            $this->pdo->prepare(
                "UPDATE belt_change_analysis_runs SET finished_at=NOW(),status='success',changes_found=:changes,
                 analyses_created=:created,error_message=NULL WHERE analysis_date=:date AND model_version=:model"
            )->execute(['changes' => count($events), 'created' => $created, 'date' => $date, 'model' => self::MODEL_VERSION]);

            return [
                'available' => true, 'skipped' => false, 'analysis_date' => $date,
                'model_version' => self::MODEL_VERSION, 'changes_found' => count($events),
                'analyses_created' => $created, 'message' => 'Análisis diario completado.',
            ];
        } catch (Throwable $error) {
            $this->pdo->prepare(
                "UPDATE belt_change_analysis_runs SET finished_at=NOW(),status='failed',error_message=:error
                 WHERE analysis_date=:date AND model_version=:model"
            )->execute(['error' => substr($error->getMessage(), 0, 1000), 'date' => $date, 'model' => self::MODEL_VERSION]);
            throw $error;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => self::LOCK_NAME]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(?string $date = null, int $limit = 200): array
    {
        if (!$this->isAvailable()) return [];
        $conditions = ['a.id=(SELECT latest.id FROM belt_change_analyses latest WHERE latest.flight_event_id=a.flight_event_id ORDER BY latest.analyzed_at DESC,latest.id DESC LIMIT 1)'];
        $params = [];
        if ($date !== null && $date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Fecha no válida.');
            $conditions[] = 'a.analysis_date=:date';
            $params['date'] = $date;
        }
        $where = 'WHERE ' . implode(' AND ', $conditions);
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT a.id,a.analysis_date,a.analyzed_at,a.model_version,a.reason_code,a.reason_label,
                    a.reason_detail,a.confidence,a.official_reason,a.evidence,
                    e.detected_at,e.before_value,e.after_value,
                    f.id AS flight_id,f.physical_flight,f.origin_iata,f.origin_name,f.scheduled_arrival,f.is_canary
             FROM belt_change_analyses a
             JOIN flight_events e ON e.id=a.flight_event_id
             JOIN flights f ON f.id=a.flight_id
             {$where}
             ORDER BY a.analysis_date DESC,e.detected_at DESC,a.id DESC LIMIT {$limit}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $decoded = json_decode((string)$row['evidence'], true);
            $row['evidence'] = is_array($decoded) ? $decoded : [];
            $row['official_reason'] = (int)$row['official_reason'];
            $row['is_canary'] = (int)$row['is_canary'];
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function runs(int $limit = 30): array
    {
        if (!$this->isAvailable()) return [];
        $limit = max(1, min(100, $limit));
        return $this->pdo->query(
            "SELECT analysis_date,model_version,started_at,finished_at,status,changes_found,
                    analyses_created,error_message
             FROM belt_change_analysis_runs ORDER BY analysis_date DESC,id DESC LIMIT {$limit}"
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function patterns(int $days = 90): array
    {
        if (!$this->isAvailable()) return [];
        $days = max(7, min(730, $days));
        $stmt = $this->pdo->query(
            "SELECT a.reason_code,MAX(a.reason_label) AS reason_label,a.confidence,a.official_reason,
                    COUNT(*) AS cases_count,SUM(CASE WHEN f.is_canary=1 THEN 1 ELSE 0 END) AS canary_count,
                    MAX(a.analysis_date) AS last_seen
             FROM belt_change_analyses a JOIN flights f ON f.id=a.flight_id
             WHERE a.analysis_date>=DATE_SUB(CURDATE(),INTERVAL {$days} DAY)
               AND a.id=(SELECT latest.id FROM belt_change_analyses latest WHERE latest.flight_event_id=a.flight_event_id ORDER BY latest.analyzed_at DESC,latest.id DESC LIMIT 1)
             GROUP BY a.reason_code,a.confidence,a.official_reason
             ORDER BY cases_count DESC,last_seen DESC"
        );
        return $stmt->fetchAll();
    }

    public function available(): bool
    {
        return $this->isAvailable();
    }

    /** @return list<array<string,mixed>> */
    private function eventsForDate(string $date): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id,e.flight_id,e.before_value,e.after_value,e.reason_code,e.reason_detail,
                    e.confidence,e.evidence,e.detected_at,
                    f.flight_date,f.physical_flight,f.origin_iata,f.origin_name,f.scheduled_arrival,f.capacity,f.is_canary
             FROM flight_events e JOIN flights f ON f.id=e.flight_id
             WHERE f.flight_date=:date AND e.source='aena' AND e.event_type='belt_changed'
             ORDER BY e.detected_at,e.id"
        );
        $stmt->execute(['date' => $date]);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function analyzeEvent(array $event): array
    {
        $snapshot = json_decode((string)($event['evidence'] ?? ''), true);
        if (!is_array($snapshot)) $snapshot = [];
        $arrival = $this->timestamp($snapshot['actual_arrival'] ?? $snapshot['eta'] ?? $event['scheduled_arrival']);
        $scheduled = $this->timestamp($event['scheduled_arrival']);
        $deviation = $arrival && $scheduled ? (int)round(($arrival - $scheduled) / 60) : null;
        $oldBelt = $this->beltNumber($event['before_value'] ?? null);
        $newBelt = $this->beltNumber($event['after_value'] ?? null);
        $oldHall = $oldBelt !== null ? ($oldBelt >= 7 ? 'B' : 'A') : null;
        $newHall = $newBelt !== null ? ($newBelt >= 7 ? 'B' : 'A') : null;
        $context = $this->contextFlights((string)$event['flight_date'], (string)$event['detected_at']);

        $nearby = [];
        $oldConflicts = [];
        $hallFlights = 0;
        $hallCapacity = 0;
        foreach ($context as $other) {
            if ((int)$other['id'] === (int)$event['flight_id']) continue;
            $otherArrival = $this->timestamp($other['effective_arrival'] ?? null);
            if (!$arrival || !$otherArrival) continue;
            $interval = (int)round(($otherArrival - $arrival) / 60);
            if (abs($interval) > 30) continue;
            $item = [
                'flight_id' => (int)$other['id'], 'physical_flight' => $other['physical_flight'],
                'origin' => $other['origin_name'], 'arrival' => $other['effective_arrival'],
                'interval_minutes' => $interval, 'hall' => $other['hall'], 'belt' => $other['belt'],
                'capacity' => $other['capacity'] !== null ? (int)$other['capacity'] : null,
            ];
            $nearby[] = $item;
            if ($oldBelt !== null && $this->beltNumber($other['belt'] ?? null) === $oldBelt) $oldConflicts[] = $item;
            if ($newHall !== null && (string)$other['hall'] === $newHall) {
                $hallFlights++;
                $hallCapacity += (int)($other['capacity'] ?? 0);
            }
        }
        $hallFlights++; // Incluye el propio vuelo.
        $hallCapacity += (int)($event['capacity'] ?? 0);
        usort($oldConflicts, static fn(array $a, array $b): int => abs($a['interval_minutes']) <=> abs($b['interval_minutes']));
        $minimumConflict = $oldConflicts ? abs((int)$oldConflicts[0]['interval_minutes']) : null;

        $genericCodes = ['belt_change','source_changed','belt_assignment','belt_removed'];
        $detail = trim((string)$event['reason_detail']);
        $genericDetail = $detail === '' || preg_match('/(?:la fuente cambió la cinta|causa operativa no fue publicada|causa no publicada)/i', $detail);
        $published = !$genericDetail || !in_array((string)$event['reason_code'], $genericCodes, true);

        if ($published) {
            $reason = [
                'reason_code' => 'official_published', 'reason_label' => 'Causa publicada por la fuente',
                'reason_detail' => $detail, 'confidence' => 'confirmado', 'official_reason' => true,
            ];
        } elseif ($minimumConflict !== null && $minimumConflict <= 15 && $deviation !== null && abs($deviation) >= 15) {
            $reason = [
                'reason_code' => 'eta_and_belt_conflict', 'reason_label' => 'Reordenación por horario y solapamiento',
                'reason_detail' => "Inferencia probable: la desviación de {$deviation} min situó el vuelo a {$minimumConflict} min de otra llegada que utilizaba la cinta anterior.",
                'confidence' => 'probable', 'official_reason' => false,
            ];
        } elseif ($minimumConflict !== null && $minimumConflict <= 30) {
            $reason = [
                'reason_code' => 'previous_belt_conflict', 'reason_label' => 'Separación insuficiente en la cinta anterior',
                'reason_detail' => "Inferencia probable: existía otra llegada en la cinta anterior con un intervalo de {$minimumConflict} min.",
                'confidence' => 'probable', 'official_reason' => false,
            ];
        } elseif ($deviation !== null && abs($deviation) >= 15) {
            $reason = [
                'reason_code' => 'arrival_reordering', 'reason_label' => 'Reordenación tras variar la llegada',
                'reason_detail' => "Inferencia provisional: el vuelo acumuló una desviación de {$deviation} min y cambió su posición respecto al plan inicial.",
                'confidence' => 'provisional', 'official_reason' => false,
            ];
        } elseif ($hallFlights >= 4 || $hallCapacity >= 600) {
            $reason = [
                'reason_code' => 'hall_pressure', 'reason_label' => 'Redistribución por presión simultánea de sala',
                'reason_detail' => "Inferencia provisional: coincidían {$hallFlights} vuelos y hasta {$hallCapacity} plazas teóricas en la misma sala dentro de ±30 min.",
                'confidence' => 'provisional', 'official_reason' => false,
            ];
        } elseif ($oldHall !== null && $newHall !== null && $oldHall !== $newHall) {
            $reason = [
                'reason_code' => 'operational_zone_change', 'reason_label' => 'Cambio de zona operativa A/B',
                'reason_detail' => "Inferencia provisional: la reasignación trasladó el vuelo de la sala {$oldHall} a la {$newHall}; Aena no publicó la causa.",
                'confidence' => 'provisional', 'official_reason' => false,
            ];
        } else {
            $reason = [
                'reason_code' => 'unverified_operational_change', 'reason_label' => 'Causa operativa no verificable',
                'reason_detail' => 'Aena confirmó el cambio de cinta, pero no publicó una causa y el contexto disponible no permite atribuirla con suficiente evidencia.',
                'confidence' => 'provisional', 'official_reason' => false,
            ];
        }

        $reason['evidence'] = [
            'authority' => 'Aena', 'change' => ['from' => $event['before_value'], 'to' => $event['after_value'], 'detected_at' => $event['detected_at']],
            'scheduled_arrival' => $event['scheduled_arrival'], 'effective_arrival' => $arrival ? date('Y-m-d H:i:s', $arrival) : null,
            'deviation_minutes' => $deviation, 'minimum_previous_belt_interval_minutes' => $minimumConflict,
            'previous_belt_conflicts' => $oldConflicts, 'nearby_flights_30m' => $nearby,
            'new_hall_flights_30m' => $hallFlights, 'new_hall_capacity_30m' => $hallCapacity,
            'disclaimer' => $reason['official_reason'] ? 'Causa publicada.' : 'Inferencia contextual; no demuestra causalidad operativa.',
        ];
        return $reason;
    }

    /** @return list<array<string,mixed>> */
    private function contextFlights(string $date, string $observedAt): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.id,f.physical_flight,f.origin_name,f.scheduled_arrival,f.capacity,
                    o.hall,o.belt,COALESCE(o.actual_arrival,o.eta,f.scheduled_arrival) AS effective_arrival
             FROM flights f
             LEFT JOIN observations o ON o.id=(
               SELECT prior.id FROM observations prior
               WHERE prior.flight_id=f.id AND prior.source='aena' AND prior.observed_at<=:observed_at
               ORDER BY prior.observed_at DESC,prior.id DESC LIMIT 1
             )
             WHERE f.flight_date=:date"
        );
        $stmt->execute(['observed_at' => $observedAt, 'date' => $date]);
        return $stmt->fetchAll();
    }

    private function beltNumber(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        return preg_match('/(?:^|\D)([1-8])$/', trim((string)$value), $match) ? (int)$match[1] : null;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $timestamp = strtotime((string)$value);
        return $timestamp === false ? null : $timestamp;
    }

    private function isAvailable(): bool
    {
        try {
            return (bool)$this->pdo->query("SHOW TABLES LIKE 'belt_change_analyses'")->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
