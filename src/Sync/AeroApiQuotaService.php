<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use PDO;
use RuntimeException;
use SevillaMatrix\Env;

/**
 * Contador mensual de result sets AeroAPI.
 *
 * Mes calendario en Europe/Madrid. Tope duro por defecto 2300
 * (plan regalado ~2500 con margen de 200). Un result set = página ≤15 vuelos.
 */
final class AeroApiQuotaService
{
    public const DEFAULT_MONTHLY_LIMIT = 2300;
    public const FLIGHTS_PER_RESULT_SET = 15;
    public const TIMEZONE = 'Europe/Madrid';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ready(): bool
    {
        try {
            return (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='aeroapi_usage'"
            )->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function monthlyLimit(): int
    {
        $value = filter_var(Env::get('AEROAPI_MONTHLY_LIMIT', (string)self::DEFAULT_MONTHLY_LIMIT), FILTER_VALIDATE_INT);
        if ($value === false) return self::DEFAULT_MONTHLY_LIMIT;
        return max(1, min(100000, (int)$value));
    }

    public function monthKey(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        return $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m');
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $limit = $this->monthlyLimit();
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $month = $this->monthKey($now);
        $used = 0;
        $httpCalls = 0;
        $lastCall = null;
        if ($this->ready()) {
            $stmt = $this->pdo->prepare(
                'SELECT result_sets,http_calls,last_call_at FROM aeroapi_usage WHERE month_key=:month'
            );
            $stmt->execute(['month' => $month]);
            $row = $stmt->fetch();
            if (is_array($row)) {
                $used = (int)$row['result_sets'];
                $httpCalls = (int)$row['http_calls'];
                $lastCall = $row['last_call_at'];
            }
        }
        $remaining = max(0, $limit - $used);
        $pace = $this->paceAnalysis($used, $limit, $remaining, $now);
        $baseMessage = $remaining < 1
            ? "AeroAPI sin cuota: {$used}/{$limit} result sets en {$month} (Europe/Madrid)."
            : "AeroAPI {$used}/{$limit} result sets · quedan {$remaining} este mes ({$month}, Europe/Madrid).";
        return [
            'ready' => $this->ready(),
            'timezone' => self::TIMEZONE,
            'month_key' => $month,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'http_calls' => $httpCalls,
            'last_call_at' => $lastCall,
            'exhausted' => $remaining < 1,
            'flights_per_result_set' => self::FLIGHTS_PER_RESULT_SET,
            'message' => $baseMessage,
            'pace' => $pace,
            'pace_warning' => $pace['warning'],
            'pace_message' => $pace['message'],
        ];
    }

    /**
     * Alerta temprana de ritmo (no reduce cadencia sola; hard stop sigue en 2300).
     *
     * @return array{
     *   day_of_month:int,
     *   days_in_month:int,
     *   days_remaining:int,
     *   month_fraction:float,
     *   used_fraction:float,
     *   projected_month_end:int,
     *   sustainable_per_day:float,
     *   current_per_day:float,
     *   warning:bool,
     *   level:?string,
     *   message:?string,
     *   reasons:list<string>
     * }
     */
    public function paceAnalysis(int $used, int $limit, int $remaining, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $now = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $day = (int)$now->format('j');
        $daysInMonth = (int)$now->format('t');
        $daysRemaining = max(0, $daysInMonth - $day);
        $monthFraction = $day / max(1, $daysInMonth);
        $usedFraction = $limit > 0 ? $used / $limit : 0.0;
        $currentPerDay = $day > 0 ? $used / $day : 0.0;
        $projected = (int)round($currentPerDay * $daysInMonth);
        $sustainablePerDay = $daysRemaining > 0
            ? $remaining / $daysRemaining
            : (float)$remaining;

        $reasons = [];
        if ($remaining < 1) {
            $reasons[] = 'exhausted';
        } else {
            if ($projected > $limit) {
                $reasons[] = 'linear_projection';
            }
            if ($daysRemaining > 0 && $currentPerDay > ($sustainablePerDay + 0.05) && $used >= 20) {
                $reasons[] = 'pace_above_sustainable';
            }
            // >70% del cupo con menos del 55% del mes → quemando demasiado pronto.
            if ($usedFraction >= 0.70 && $monthFraction <= 0.55) {
                $reasons[] = 'mid_month_over_70';
            }
            // Quedan pocos días y el ritmo reciente no cabe.
            if ($daysRemaining > 0 && $daysRemaining <= 10 && $currentPerDay > $sustainablePerDay && $remaining < (int)ceil($limit * 0.25)) {
                $reasons[] = 'late_month_tight';
            }
            // Ritmo alto absoluto: proyección > 90% del tope ya con margen.
            if ($projected >= (int)ceil($limit * 0.92) && $usedFraction >= 0.35 && $monthFraction < 0.85) {
                $reasons[] = 'near_limit_projection';
            }
        }

        $warning = $reasons !== [] && $remaining >= 1;
        $level = null;
        $message = null;
        if ($remaining < 1) {
            $level = 'critical';
            $message = "AeroAPI agotada: {$used}/{$limit}. Hard stop activo hasta el próximo mes (Europe/Madrid).";
        } elseif ($warning) {
            $level = in_array('mid_month_over_70', $reasons, true) || $projected >= (int)ceil($limit * 1.15)
                ? 'critical'
                : 'warning';
            $message = sprintf(
                'Ritmo alto: vas a ~%d a fin de mes (tope %d). Quedan %d. Hay que bajar cadencia.',
                $projected,
                $limit,
                $remaining
            );
        }

        return [
            'day_of_month' => $day,
            'days_in_month' => $daysInMonth,
            'days_remaining' => $daysRemaining,
            'month_fraction' => round($monthFraction, 4),
            'used_fraction' => round($usedFraction, 4),
            'projected_month_end' => $projected,
            'sustainable_per_day' => round($sustainablePerDay, 2),
            'current_per_day' => round($currentPerDay, 2),
            'warning' => $warning,
            'level' => $level,
            'message' => $message,
            'reasons' => $reasons,
        ];
    }

    public function remaining(): int
    {
        return (int)$this->status()['remaining'];
    }

    public function canSpend(int $resultSets = 1): bool
    {
        $resultSets = max(1, $resultSets);
        return $this->remaining() >= $resultSets;
    }

    /**
     * Estima result sets de una respuesta AeroAPI.
     * Preferir num_pages; si no, ceil(vuelos/15); mínimo 1 si hubo respuesta útil.
     *
     * @param array<string,mixed> $payload
     */
    public static function estimateResultSets(array $payload, int $flightCountHint = 0): int
    {
        if (isset($payload['num_pages']) && is_numeric($payload['num_pages'])) {
            return max(1, (int)$payload['num_pages']);
        }
        $flights = AeroApiParser::flightsFromResponse($payload);
        $count = max($flightCountHint, count($flights));
        if ($count < 1) return 1;
        return max(1, (int)ceil($count / self::FLIGHTS_PER_RESULT_SET));
    }

    public function assertCanSpend(int $resultSets = 1): void
    {
        if (!$this->ready()) {
            throw new RuntimeException('Aplica la actualización MySQL 015 de AeroAPI (tabla aeroapi_usage).');
        }
        if (!$this->canSpend($resultSets)) {
            $status = $this->status();
            throw new RuntimeException(
                'Tope AeroAPI alcanzado: ' . $status['used'] . '/' . $status['limit']
                . ' result sets en ' . $status['month_key'] . ' (Europe/Madrid). Margen de seguridad: no se consulta más este mes.'
            );
        }
    }

    public function record(int $resultSets, int $httpCalls = 1): array
    {
        $resultSets = max(0, $resultSets);
        $httpCalls = max(0, $httpCalls);
        if ($resultSets < 1 && $httpCalls < 1) return $this->status();
        if (!$this->ready()) return $this->status();

        $month = $this->monthKey();
        // Named params distintos: PDO nativo no permite reutilizar :sets/:calls en UPDATE.
        $this->pdo->prepare(
            'INSERT INTO aeroapi_usage (month_key,result_sets,http_calls,last_call_at)
             VALUES (:month,:sets_ins,:calls_ins,NOW())
             ON DUPLICATE KEY UPDATE
               result_sets=result_sets+:sets_upd,
               http_calls=http_calls+:calls_upd,
               last_call_at=NOW()'
        )->execute([
            'month' => $month,
            'sets_ins' => $resultSets,
            'calls_ins' => $httpCalls,
            'sets_upd' => $resultSets,
            'calls_upd' => $httpCalls,
        ]);
        return $this->status();
    }
}
