<?php
declare(strict_types=1);

namespace SevillaMatrix;

use PDO;
use Throwable;

/**
 * Genera una única predicción previa por operación y la conserva para poder
 * compararla después con la primera asignación oficial de Aena.
 */
final class PredictionEngine
{
    private ?bool $available = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function refreshForFlight(int $flightId, string $predictedAt): void
    {
        if (!$this->isAvailable()) return;

        $flight = $this->flight($flightId);
        if (!$flight) return;

        $official = $this->latestOfficialBelt($flightId);
        if ($official !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE predictions SET official_assignment_seen=1 WHERE flight_id=?'
            );
            $stmt->execute([$flightId]);
            return;
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM predictions WHERE flight_id=? LIMIT 1');
        $exists->execute([$flightId]);
        if ($exists->fetchColumn()) return;

        $history = $this->historicalBelts($flight);
        $secondary = $this->latestSecondaryBelt($flightId);
        $weights = array_fill(1, 8, 0.0);

        foreach ($history['physical'] as $belt) $weights[$belt] += 3.0;
        foreach ($history['origin'] as $belt) $weights[$belt] += 1.0;
        if ($secondary !== null) $weights[$secondary] += 2.0;

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) return;

        arsort($weights, SORT_NUMERIC);
        $predictedBelt = (int)array_key_first($weights);
        $score = (int)round(($weights[$predictedBelt] / $totalWeight) * 100);
        $samples = count($history['physical']) + count($history['origin']);
        $confidence = $samples >= 5 && $score >= 60 ? 'alta'
            : (($samples >= 3 || $secondary !== null) ? 'media' : 'baja');
        $predictedHall = $predictedBelt >= 7 ? 'B' : 'A';

        $features = [
            'model' => 'historical_weighted_v1',
            'physical_samples' => count($history['physical']),
            'origin_samples' => count($history['origin']),
            'secondary_belt' => $secondary,
            'weights' => $weights,
            'note' => 'Predicción estadística previa; Aena prevalece siempre.',
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO predictions
             (flight_id,predicted_at,predicted_hall,predicted_belt,score,confidence,features,official_assignment_seen)
             VALUES (:flight_id,:predicted_at,:hall,:belt,:score,:confidence,:features,0)'
        );
        $stmt->execute([
            'flight_id' => $flightId,
            'predicted_at' => $predictedAt,
            'hall' => $predictedHall,
            'belt' => (string)$predictedBelt,
            'score' => $score,
            'confidence' => $confidence,
            'features' => json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function summary(?string $beforeDate = null): array
    {
        if (!$this->isAvailable()) return $this->emptySummary();

        $whereDate = $beforeDate ? ' AND f.flight_date<=:before_date' : '';
        $sql = "SELECT COUNT(*) AS evaluated,
                       SUM(CASE WHEN (CAST(RIGHT(TRIM(p.predicted_belt),1) AS UNSIGNED) BETWEEN 1 AND 6
                                           AND CAST(RIGHT(TRIM(actual.belt),1) AS UNSIGNED) BETWEEN 1 AND 6)
                                          OR (CAST(RIGHT(TRIM(p.predicted_belt),1) AS UNSIGNED) BETWEEN 7 AND 8
                                           AND CAST(RIGHT(TRIM(actual.belt),1) AS UNSIGNED) BETWEEN 7 AND 8)
                                      THEN 1 ELSE 0 END) AS correct,
                       SUM(CASE WHEN CAST(p.predicted_belt AS CHAR)=CAST(actual.belt AS CHAR) THEN 1 ELSE 0 END) AS exact_correct,
                       SUM(CASE WHEN f.is_canary=1 THEN 1 ELSE 0 END) AS canary_evaluated,
                       SUM(CASE WHEN f.is_canary=1 AND ((CAST(RIGHT(TRIM(p.predicted_belt),1) AS UNSIGNED) BETWEEN 1 AND 6
                                                        AND CAST(RIGHT(TRIM(actual.belt),1) AS UNSIGNED) BETWEEN 1 AND 6)
                                                       OR (CAST(RIGHT(TRIM(p.predicted_belt),1) AS UNSIGNED) BETWEEN 7 AND 8
                                                        AND CAST(RIGHT(TRIM(actual.belt),1) AS UNSIGNED) BETWEEN 7 AND 8))
                                THEN 1 ELSE 0 END) AS canary_correct,
                       SUM(CASE WHEN f.is_canary=1 AND CAST(p.predicted_belt AS CHAR)=CAST(actual.belt AS CHAR) THEN 1 ELSE 0 END) AS canary_exact_correct
                FROM predictions p
                INNER JOIN flights f ON f.id=p.flight_id
                INNER JOIN observations actual ON actual.id=(
                    SELECT o.id FROM observations o
                    WHERE o.flight_id=p.flight_id AND o.source='aena'
                      AND o.belt IS NOT NULL AND o.belt<>''
                    ORDER BY o.observed_at DESC,o.id DESC LIMIT 1
                )
                WHERE p.id=(SELECT MIN(first_p.id) FROM predictions first_p WHERE first_p.flight_id=p.flight_id)" . $whereDate;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($beforeDate ? ['before_date' => $beforeDate] : []);
        $row = $stmt->fetch() ?: [];

        $evaluated = (int)($row['evaluated'] ?? 0);
        $correct = (int)($row['correct'] ?? 0);
        $exactCorrect = (int)($row['exact_correct'] ?? 0);
        $canaryEvaluated = (int)($row['canary_evaluated'] ?? 0);
        $canaryCorrect = (int)($row['canary_correct'] ?? 0);
        $canaryExactCorrect = (int)($row['canary_exact_correct'] ?? 0);
        return [
            'evaluated' => $evaluated,
            'correct' => $correct,
            'accuracy_percentage' => $evaluated ? round(($correct / $evaluated) * 100, 1) : null,
            'exact_correct' => $exactCorrect,
            'exact_accuracy_percentage' => $evaluated ? round(($exactCorrect / $evaluated) * 100, 1) : null,
            'canary_evaluated' => $canaryEvaluated,
            'canary_correct' => $canaryCorrect,
            'canary_accuracy_percentage' => $canaryEvaluated ? round(($canaryCorrect / $canaryEvaluated) * 100, 1) : null,
            'canary_exact_correct' => $canaryExactCorrect,
            'canary_exact_accuracy_percentage' => $canaryEvaluated ? round(($canaryExactCorrect / $canaryEvaluated) * 100, 1) : null,
            'method' => 'historical_weighted_v2_blocks',
        ];
    }

    private function flight(int $flightId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,flight_date,physical_flight,origin_iata FROM flights WHERE id=?'
        );
        $stmt->execute([$flightId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function latestOfficialBelt(int $flightId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT belt FROM observations WHERE flight_id=? AND source='aena'
             AND belt IS NOT NULL AND belt<>'' ORDER BY observed_at DESC,id DESC LIMIT 1"
        );
        $stmt->execute([$flightId]);
        return $this->beltNumber($stmt->fetchColumn());
    }

    private function latestSecondaryBelt(int $flightId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT belt FROM observations WHERE flight_id=? AND source<>'aena'
             AND belt IS NOT NULL AND belt<>'' ORDER BY observed_at DESC,id DESC LIMIT 1"
        );
        $stmt->execute([$flightId]);
        return $this->beltNumber($stmt->fetchColumn());
    }

    /** @return array{physical:array<int,int>,origin:array<int,int>} */
    private function historicalBelts(array $flight): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT hf.physical_flight,hf.origin_iata,
                    (SELECT o.belt FROM observations o
                     WHERE o.flight_id=hf.id AND o.source='aena'
                       AND o.belt IS NOT NULL AND o.belt<>''
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS final_belt
             FROM flights hf
             WHERE hf.flight_date<:flight_date
               AND (hf.physical_flight=:physical OR hf.origin_iata=:origin)
             ORDER BY hf.flight_date DESC LIMIT 120"
        );
        $stmt->execute([
            'flight_date' => $flight['flight_date'],
            'physical' => $flight['physical_flight'],
            'origin' => $flight['origin_iata'],
        ]);
        $physical = [];
        $origin = [];
        foreach ($stmt->fetchAll() as $row) {
            $belt = $this->beltNumber($row['final_belt'] ?? null);
            if ($belt === null) continue;
            if ($row['physical_flight'] === $flight['physical_flight']) $physical[] = $belt;
            if ($row['origin_iata'] === $flight['origin_iata']) $origin[] = $belt;
        }
        return ['physical' => $physical, 'origin' => $origin];
    }

    private function beltNumber(mixed $value): ?int
    {
        if ($value === false || $value === null || $value === '') return null;
        if (!preg_match('/(?:^|\\D)([1-8])$/', trim((string)$value), $match)) return null;
        return (int)$match[1];
    }

    private function isAvailable(): bool
    {
        if ($this->available !== null) return $this->available;
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'predictions'");
            return $this->available = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    private function emptySummary(): array
    {
        return [
            'evaluated' => 0, 'correct' => 0, 'accuracy_percentage' => null,
            'exact_correct' => 0, 'exact_accuracy_percentage' => null,
            'canary_evaluated' => 0, 'canary_correct' => 0,
            'canary_accuracy_percentage' => null, 'canary_exact_correct' => 0,
            'canary_exact_accuracy_percentage' => null, 'method' => 'historical_weighted_v2_blocks',
        ];
    }
}
