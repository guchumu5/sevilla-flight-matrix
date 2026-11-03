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
        $month = $this->monthKey();
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
            'message' => $remaining < 1
                ? "AeroAPI sin cuota: {$used}/{$limit} result sets en {$month} (Europe/Madrid)."
                : "AeroAPI {$used}/{$limit} result sets · quedan {$remaining} este mes ({$month}, Europe/Madrid).",
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
