<?php
declare(strict_types=1);

namespace SevillaMatrix\Sync;

use PDO;
use RuntimeException;
use SevillaMatrix\FlightRepository;
use SevillaMatrix\IataFlightCode;
use SevillaMatrix\Notifications\WebPushService;
use SevillaMatrix\Operations\WebOperationsService;
use Throwable;

final class AeroApiIngestService
{
    /** Nube operativa alrededor de Canarias (antes ±30). */
    public const CLOUD_MINUTES = 60;
    /** Empieza vigilancia de cinta 1 h antes del despegue estimado. */
    public const BELT_WATCH_BEFORE_DEP_MINUTES = 60;
    /** Cadencia normal vs fase cinta (vigilados/Canarias). */
    private const REFRESH_MINUTES = 5;
    private const BELT_WATCH_REFRESH_MINUTES = 2;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function configured(): bool
    {
        return (new AeroApiClient($this->pdo))->configured();
    }

    public function quota(): AeroApiQuotaService
    {
        return new AeroApiQuotaService($this->pdo);
    }

    public function ready(): bool
    {
        $tables = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN ('aeroapi_snapshots','aeroapi_requests','aeroapi_usage')"
        )->fetchColumn();
        if ($tables < 3) return false;
        $enum = (string)$this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='observations' AND column_name='source'"
        )->fetchColumn();
        return str_contains($enum, 'aeroapi');
    }

    /**
     * Cola priorizada (solo gasta cuota en esto):
     * 1 vigilados · 2 Canarias noche / cinta T−60 dep. estimado · 3 resto Canarias
     * · 4 feeder Canarias · 5 nube ±60 min.
     *
     * @return array<string,mixed>
     */
    public function queue(int $limit = 8): array
    {
        $limit = max(1, min(12, $limit));
        $quota = $this->quota()->status();
        if (!$this->configured()) {
            return [
                'ok' => true,
                'configured' => false,
                'cadence_minutes' => self::REFRESH_MINUTES,
                'flights' => [],
                'quota' => $quota,
                'message' => 'AeroAPI no configurada: define FLIGHTAWARE_API_KEY o AEROAPI_KEY en .env.',
            ];
        }
        if (!$this->ready()) {
            return [
                'ok' => true,
                'configured' => false,
                'cadence_minutes' => self::REFRESH_MINUTES,
                'flights' => [],
                'quota' => $quota,
                'message' => 'Aplica la actualización MySQL 015 de AeroAPI.',
            ];
        }
        if (!empty($quota['exhausted'])) {
            return [
                'ok' => true,
                'configured' => true,
                'cadence_minutes' => self::REFRESH_MINUTES,
                'flights' => [],
                'quota' => $quota,
                'message' => (string)$quota['message'],
            ];
        }

        // Candidatos amplios; la prioridad se calcula en PHP para no gastar en no-Canarias.
        // Incluye retraso/ETA/salida AeroAPI para mover el despegue estimado con el delay.
        $stmt = $this->pdo->query(
            "SELECT f.id,f.flight_date,f.physical_flight,f.origin_iata,f.origin_name,
                    f.scheduled_arrival,f.scheduled_departure,f.is_canary,f.aircraft_registration,
                    r.status AS request_status,r.processed_at,r.requested_at,
                    EXISTS (
                      SELECT 1 FROM flight_watches w
                      INNER JOIN push_subscriptions s ON s.id=w.subscription_id
                      WHERE w.flight_id=f.id AND w.enabled=1 AND s.active=1
                    ) AS is_watched,
                    (SELECT GROUP_CONCAT(c.flight_code ORDER BY c.flight_code SEPARATOR ',')
                     FROM flight_codes c WHERE c.flight_id=f.id) AS aliases,
                    (SELECT ff.feeder_code FROM flight_feeders ff WHERE ff.flight_id=f.id LIMIT 1) AS feeder_code,
                    (SELECT s.delay_minutes FROM aeroapi_snapshots s
                     WHERE s.flight_id=f.id ORDER BY s.observed_at DESC,s.id DESC LIMIT 1) AS aero_delay,
                    (SELECT s.actual_departure FROM aeroapi_snapshots s
                     WHERE s.flight_id=f.id ORDER BY s.observed_at DESC,s.id DESC LIMIT 1) AS aero_actual_departure,
                    (SELECT s.eta FROM aeroapi_snapshots s
                     WHERE s.flight_id=f.id ORDER BY s.observed_at DESC,s.id DESC LIMIT 1) AS aero_eta,
                    (SELECT s.raw_excerpt FROM aeroapi_snapshots s
                     WHERE s.flight_id=f.id ORDER BY s.observed_at DESC,s.id DESC LIMIT 1) AS aero_raw,
                    (SELECT o.eta FROM observations o
                     WHERE o.flight_id=f.id AND o.source='aena' AND o.eta IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS aena_eta,
                    (SELECT o.actual_departure FROM observations o
                     WHERE o.flight_id=f.id AND o.actual_departure IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS obs_actual_departure
             FROM flights f
             LEFT JOIN aeroapi_requests r ON r.flight_id=f.id
             WHERE f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 8 HOUR)
                                           AND DATE_ADD(NOW(),INTERVAL 16 HOUR)
             ORDER BY f.scheduled_arrival
             LIMIT 160"
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
        $canaryTimes = [];
        foreach ($rows as $row) {
            if ((int)($row['is_canary'] ?? 0) === 1) {
                $ts = strtotime((string)$row['scheduled_arrival']);
                if ($ts !== false) $canaryTimes[] = $ts;
            }
        }

        $now = time();
        $ranked = [];
        foreach ($rows as $row) {
            $priority = $this->priorityFor($row, $canaryTimes);
            if ($priority === null) continue;
            $beltWatch = $this->inBeltWatchWindow($row);
            if (!$this->needsRefresh($row, $beltWatch, $now)) continue;
            $mapped = $this->mapFlight($row);
            if ($mapped === null) continue;
            $mapped['priority'] = $priority;
            $mapped['priority_label'] = $this->priorityLabel($priority);
            $mapped['is_watched'] = (int)($row['is_watched'] ?? 0) === 1;
            $mapped['belt_watch'] = $beltWatch;
            $mapped['estimated_departure'] = $this->estimatedDepartureLocal($row);
            $mapped['request_status'] = $row['request_status'] ?? null;
            $mapped['feeder_code'] = $row['feeder_code'] ?? null;
            $mapped['aircraft_registration'] = $row['aircraft_registration'] ?? null;
            $ranked[] = $mapped;
        }
        usort($ranked, static function (array $a, array $b): int {
            $pa = (int)$a['priority'];
            $pb = (int)$b['priority'];
            if ($pa !== $pb) return $pa <=> $pb;
            $ba = !empty($a['belt_watch']) ? 0 : 1;
            $bb = !empty($b['belt_watch']) ? 0 : 1;
            if ($ba !== $bb) return $ba <=> $bb;
            $qa = (($a['request_status'] ?? '') === 'queued') ? 0 : 1;
            $qb = (($b['request_status'] ?? '') === 'queued') ? 0 : 1;
            if ($qa !== $qb) return $qa <=> $qb;
            return strcmp((string)$a['scheduled_arrival'], (string)$b['scheduled_arrival']);
        });

        $remaining = (int)$quota['remaining'];
        $flights = array_slice($ranked, 0, min($limit, $remaining));
        return [
            'ok' => true,
            'configured' => true,
            'cadence_minutes' => self::REFRESH_MINUTES,
            'belt_watch_refresh_minutes' => self::BELT_WATCH_REFRESH_MINUTES,
            'cloud_minutes' => self::CLOUD_MINUTES,
            'flights' => $flights,
            'quota' => $quota,
            'candidates' => count($ranked),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param list<int> $canaryTimes
     */
    private function priorityFor(array $row, array $canaryTimes): ?int
    {
        $watched = (int)($row['is_watched'] ?? 0) === 1;
        $isCanary = (int)($row['is_canary'] ?? 0) === 1;
        if ($watched) return 1;
        if ($isCanary) {
            // Fase cinta (T−60 despegue estimado) o noche: misma prioridad alta.
            if ($this->inBeltWatchWindow($row) || (new FlightFeederService($this->pdo))->isNightCanary($row)) {
                return 2;
            }
            return 3;
        }
        // 4 = llegada cuyo físico coincide con un feeder_code de Canarias (raro en SVQ,
        // pero cubre si el tramo anterior también aterriza aquí).
        $code = strtoupper(preg_replace('/\s+/', '', (string)($row['physical_flight'] ?? '')) ?? '');
        foreach ($this->canaryFeederCodes() as $feeder) {
            if ($feeder !== '' && $feeder === $code) return 4;
        }
        // 5 = nube: llegada no-Canarias a ±60 min de algún Canarias.
        $ts = strtotime((string)($row['scheduled_arrival'] ?? ''));
        if ($ts === false || $canaryTimes === []) return null;
        $cloudSeconds = self::CLOUD_MINUTES * 60;
        foreach ($canaryTimes as $canaryTs) {
            if (abs($ts - $canaryTs) <= $cloudSeconds) return 5;
        }
        return null;
    }

    /** @param array<string,mixed> $row */
    private function needsRefresh(array $row, bool $beltWatch, int $now): bool
    {
        $status = (string)($row['request_status'] ?? '');
        $processed = (string)($row['processed_at'] ?? '');
        if ($processed === '' || $status === 'queued') return true;
        $processedTs = strtotime($processed);
        if ($processedTs === false) return true;
        $watchedOrCanary = ((int)($row['is_watched'] ?? 0) === 1) || ((int)($row['is_canary'] ?? 0) === 1);
        $cadence = ($beltWatch && $watchedOrCanary)
            ? self::BELT_WATCH_REFRESH_MINUTES
            : self::REFRESH_MINUTES;
        return ($now - $processedTs) >= ($cadence * 60);
    }

    /**
     * Despegue estimado que se mueve con el retraso (no solo scheduled fijo).
     *
     * @param array<string,mixed> $row
     */
    private function estimatedDepartureLocal(array $row): ?string
    {
        $actual = (string)($row['aero_actual_departure'] ?? $row['obs_actual_departure'] ?? '');
        if ($actual !== '') return $actual;

        $fromRaw = $this->estimatedDepartureFromRaw((string)($row['aero_raw'] ?? ''));
        if ($fromRaw !== null) return $fromRaw;

        $scheduled = (string)($row['scheduled_departure'] ?? '');
        $scheduledTs = $scheduled !== '' ? strtotime($scheduled) : false;
        if ($scheduledTs === false) {
            $arrivalTs = strtotime((string)($row['scheduled_arrival'] ?? ''));
            if ($arrivalTs === false) return null;
            // Tramo Canarias típico ~2h30: ancla de despegue si Aena no trae salida.
            $scheduledTs = $arrivalTs - 150 * 60;
        }

        $delay = 0;
        if (isset($row['aero_delay']) && is_numeric($row['aero_delay'])) {
            $delay = (int)$row['aero_delay'];
        } elseif (!empty($row['aena_eta']) && !empty($row['scheduled_arrival'])) {
            $etaTs = strtotime((string)$row['aena_eta']);
            $arrTs = strtotime((string)$row['scheduled_arrival']);
            if ($etaTs !== false && $arrTs !== false) {
                $delay = (int)round(($etaTs - $arrTs) / 60);
            }
        } elseif (!empty($row['aero_eta']) && !empty($row['scheduled_arrival'])) {
            $etaTs = strtotime((string)$row['aero_eta']);
            $arrTs = strtotime((string)$row['scheduled_arrival']);
            if ($etaTs !== false && $arrTs !== false) {
                $delay = (int)round(($etaTs - $arrTs) / 60);
            }
        }
        return date('Y-m-d H:i:s', $scheduledTs + $delay * 60);
    }

    private function estimatedDepartureFromRaw(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw[0] !== '{') return null;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return null;
        $value = $decoded['estimated_departure'] ?? null;
        if (!is_string($value) || trim($value) === '') return null;
        return preg_match('/^\d{4}-\d{2}-\d{2} /', $value) ? $value : null;
    }

    /** @param array<string,mixed> $row */
    private function inBeltWatchWindow(array $row): bool
    {
        $depLocal = $this->estimatedDepartureLocal($row);
        if ($depLocal === null) return false;
        $depTs = strtotime($depLocal);
        if ($depTs === false) return false;
        $now = time();
        $start = $depTs - (self::BELT_WATCH_BEFORE_DEP_MINUTES * 60);
        $arrivalTs = strtotime((string)($row['scheduled_arrival'] ?? '')) ?: ($depTs + 3 * 3600);
        if (!empty($row['aena_eta'])) {
            $etaTs = strtotime((string)$row['aena_eta']);
            if ($etaTs !== false) $arrivalTs = max($arrivalTs, $etaTs);
        }
        if (!empty($row['aero_eta'])) {
            $etaTs = strtotime((string)$row['aero_eta']);
            if ($etaTs !== false) $arrivalTs = max($arrivalTs, $etaTs);
        }
        return $now >= $start && $now <= ($arrivalTs + 2 * 3600);
    }

    /** @return list<string> */
    private function canaryFeederCodes(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        try {
            $has = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='flight_feeders'"
            )->fetchColumn();
            if ($has === 1) {
                $stmt = $this->pdo->query(
                    "SELECT DISTINCT UPPER(REPLACE(ff.feeder_code,' ','')) AS code
                     FROM flight_feeders ff
                     INNER JOIN flights f ON f.id=ff.flight_id
                     WHERE f.is_canary=1
                       AND f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 8 HOUR)
                                                   AND DATE_ADD(NOW(),INTERVAL 16 HOUR)
                       AND NULLIF(ff.feeder_code,'') IS NOT NULL"
                );
                foreach ($stmt ? $stmt->fetchAll() : [] as $item) {
                    $code = (string)($item['code'] ?? '');
                    if ($code !== '') $cache[] = $code;
                }
            }
        } catch (Throwable) {
            $cache = [];
        }
        return $cache;
    }

    private function priorityLabel(int $priority): string
    {
        return match ($priority) {
            1 => 'vigilado',
            2 => 'canarias_noche',
            3 => 'canarias',
            4 => 'feeder',
            5 => 'nube_canarias',
            default => 'otro',
        };
    }

    /** @return array<string,mixed> */
    public function request(int $flightId): array
    {
        if (!$this->ready()) throw new RuntimeException('Aplica la actualización MySQL 015 de AeroAPI.');
        if (!$this->configured()) {
            throw new RuntimeException('AeroAPI no configurada: define FLIGHTAWARE_API_KEY o AEROAPI_KEY en .env.');
        }
        $quota = $this->quota()->status();
        if (!empty($quota['exhausted'])) {
            return [
                'ok' => true,
                'queued' => false,
                'quota' => $quota,
                'message' => (string)$quota['message'],
            ];
        }
        $flight = $this->pdo->prepare(
            'SELECT id,physical_flight,is_canary,origin_iata,scheduled_arrival FROM flights WHERE id=:id
             AND scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 1 DAY) AND DATE_ADD(NOW(),INTERVAL 2 DAY)'
        );
        $flight->execute(['id' => $flightId]);
        $row = $flight->fetch();
        if (!$row) throw new RuntimeException('El vuelo no está dentro de la ventana consultable.');
        if (!$this->isEligible($row)) {
            return [
                'ok' => true,
                'queued' => false,
                'quota' => $quota,
                'flight' => (string)$row['physical_flight'],
                'message' => 'AeroAPI solo gasta cuota en vigilados, Canarias, su feeder o la nube ±'
                    . self::CLOUD_MINUTES . ' min.',
            ];
        }
        $previous = $this->pdo->prepare('SELECT requested_at,status FROM aeroapi_requests WHERE flight_id=:id');
        $previous->execute(['id' => $flightId]);
        $before = $previous->fetch();
        $recent = is_array($before) && strtotime((string)$before['requested_at']) >= time() - 180;
        if (!$recent) {
            $this->pdo->prepare(
                "INSERT INTO aeroapi_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
                 VALUES (:id,NOW(),NULL,1,'queued',NULL)
                 ON DUPLICATE KEY UPDATE requested_at=NOW(),processed_at=NULL,
                   request_count=request_count+1,status='queued',last_error=NULL"
            )->execute(['id' => $flightId]);
        }
        return [
            'ok' => true,
            'queued' => !$recent,
            'quota' => $quota,
            'flight' => (string)$row['physical_flight'],
            'message' => $recent
                ? 'La lectura AeroAPI ya estaba solicitada recientemente.'
                : 'Lectura AeroAPI solicitada. Se procesará en el siguiente ciclo.',
        ];
    }

    /** @param array<string,mixed> $row */
    private function isEligible(array $row): bool
    {
        if ((int)($row['is_canary'] ?? 0) === 1) return true;
        $watched = $this->pdo->prepare(
            'SELECT 1 FROM flight_watches w
             INNER JOIN push_subscriptions s ON s.id=w.subscription_id
             WHERE w.flight_id=:id AND w.enabled=1 AND s.active=1 LIMIT 1'
        );
        $watched->execute(['id' => (int)$row['id']]);
        if ($watched->fetchColumn()) return true;
        $code = strtoupper(preg_replace('/\s+/', '', (string)($row['physical_flight'] ?? '')) ?? '');
        if (in_array($code, $this->canaryFeederCodes(), true)) return true;
        $ts = strtotime((string)($row['scheduled_arrival'] ?? ''));
        if ($ts === false) return false;
        // PDO nativo (EMULATE_PREPARES=false) no reutiliza el mismo named param dos veces.
        $when = date('Y-m-d H:i:s', $ts);
        $near = $this->pdo->prepare(
            'SELECT 1 FROM flights
             WHERE is_canary=1
               AND scheduled_arrival BETWEEN DATE_SUB(:when_from, INTERVAL ' . self::CLOUD_MINUTES . ' MINUTE)
                                        AND DATE_ADD(:when_to, INTERVAL ' . self::CLOUD_MINUTES . ' MINUTE)
             LIMIT 1'
        );
        $near->execute(['when_from' => $when, 'when_to' => $when]);
        return (bool)$near->fetchColumn();
    }

    /**
     * @param list<array<string,mixed>> $snapshots
     * @return array<string,mixed>
     */
    public function ingest(array $snapshots, bool $dispatch = true): array
    {
        if (!$this->ready()) throw new RuntimeException('Aplica la actualización MySQL 015 de AeroAPI.');
        if ($snapshots === [] || count($snapshots) > 12) {
            throw new RuntimeException('snapshots debe ser una lista de hasta 12 registros.');
        }

        $lookup = $this->pdo->prepare(
            'SELECT id,flight_date,physical_flight,origin_iata,origin_name,scheduled_arrival,scheduled_departure,is_canary,
                    (SELECT o.belt FROM observations o
                     WHERE o.flight_id=f.id AND o.source=\'aena\' AND NULLIF(o.belt,\'\') IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS aena_belt,
                    (SELECT o.hall FROM observations o
                     WHERE o.flight_id=f.id AND o.source=\'aena\' AND NULLIF(o.belt,\'\') IS NOT NULL
                     ORDER BY o.observed_at DESC,o.id DESC LIMIT 1) AS aena_hall
             FROM flights f WHERE f.id=:id'
        );
        $latest = $this->pdo->prepare(
            'SELECT status_text,delay_minutes,eta,actual_departure,actual_arrival,gate,belt,registration,aircraft_type,phase,progress_percent
             FROM aeroapi_snapshots WHERE flight_id=:flight_id ORDER BY observed_at DESC,id DESC LIMIT 1'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO aeroapi_snapshots
             (flight_id,observed_at,public_url,fa_flight_id,ident_iata,ident_icao,status_text,delay_minutes,eta,
              actual_departure,actual_arrival,gate,belt,registration,aircraft_type,phase,progress_percent,raw_excerpt)
             VALUES
             (:flight_id,:observed_at,:public_url,:fa_flight_id,:ident_iata,:ident_icao,:status_text,:delay_minutes,:eta,
              :actual_departure,:actual_arrival,:gate,:belt,:registration,:aircraft_type,:phase,:progress_percent,:raw_excerpt)'
        );
        $completed = $this->pdo->prepare(
            "INSERT INTO aeroapi_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
             VALUES (:id,NOW(),NOW(),1,'processed',NULL)
             ON DUPLICATE KEY UPDATE processed_at=NOW(),status='processed',last_error=NULL"
        );
        $saved = $unchanged = 0;
        $records = [];
        $forceAena = false;
        $divergences = [];
        foreach ($snapshots as $record) {
            if (!is_array($record)) continue;
            $flightId = filter_var($record['flight_id'] ?? null, FILTER_VALIDATE_INT);
            $code = strtoupper(preg_replace('/\s+/', '', (string)($record['physical_flight'] ?? '')) ?? '');
            if (!$flightId || !preg_match('/^[A-Z0-9]{2,8}$/', $code)) continue;
            $lookup->execute(['id' => $flightId]);
            $flight = $lookup->fetch();
            if (!is_array($flight) || strtoupper((string)$flight['physical_flight']) !== $code) continue;

            $normalized = $this->normalizeSnapshot($record, $flight);
            $latest->execute(['flight_id' => $flightId]);
            $previous = $latest->fetch();
            $same = is_array($previous);
            foreach ([
                'status_text','delay_minutes','eta','actual_departure','actual_arrival',
                'gate','belt','registration','aircraft_type','phase','progress_percent',
            ] as $key) {
                if ((string)($previous[$key] ?? '') !== (string)($normalized[$key] ?? '')) {
                    $same = false;
                    break;
                }
            }
            $diverges = $this->beltDivergesFromAena($flight, $normalized['belt'] ?? null);
            if ($diverges) {
                $forceAena = true;
                $prevBelt = strtoupper(trim((string)($previous['belt'] ?? '')));
                $newBelt = strtoupper(trim((string)($normalized['belt'] ?? '')));
                if (!$same || $prevBelt !== $newBelt || empty($previous['belt'])) {
                    $divergences[] = [
                        'flight' => $flight,
                        'aena_belt' => strtoupper(trim((string)($flight['aena_belt'] ?? ''))),
                        'aero_belt' => $newBelt,
                    ];
                }
            }
            if (!$same) {
                $insert->execute($normalized);
                $saved++;
                $observation = $this->observationRecord($flight, $normalized);
                if ($observation !== null) $records[] = $observation;
            } else {
                $unchanged++;
            }
            $completed->execute(['id' => $flightId]);
        }

        if ($saved > 0) {
            FlightRepository::forgetSnapshotCaches();
        }

        $sync = null;
        $push = null;
        $aena = null;
        $criticalQueued = 0;
        if ($records !== []) {
            $sync = (new FlightReconciliationService($this->pdo))->sync($records, [
                'provider' => 'aeroapi',
                'mode' => 'delta',
                'observed_at' => date('Y-m-d H:i:s'),
                'complete' => false,
                'create_missing' => false,
                'metadata' => ['transport' => 'flightaware_aeroapi', 'snapshots' => count($snapshots)],
            ]);
        }
        if ($forceAena) {
            try {
                $aena = (new WebOperationsService($this->pdo))->requestAenaSweep('aeroapi_belt_divergence');
            } catch (Throwable $error) {
                $aena = ['ok' => false, 'error' => substr($error->getMessage(), 0, 300)];
            }
        }
        foreach ($divergences as $item) {
            $criticalQueued += $this->queueCriticalBeltDivergencePush(
                $item['flight'],
                $item['aena_belt'],
                $item['aero_belt']
            );
        }
        if ($dispatch && ($records !== [] || $criticalQueued > 0)) {
            try {
                $push = (new WebPushService($this->pdo))->dispatch(50);
            } catch (Throwable $error) {
                $push = ['error' => substr($error->getMessage(), 0, 300)];
            }
        }

        return [
            'ok' => true,
            'saved' => $saved,
            'unchanged' => $unchanged,
            'sync' => $sync,
            'push' => $push,
            'aena_forced' => $forceAena,
            'aena' => $aena,
            'critical_belt_push' => $criticalQueued,
        ];
    }

    /**
     * @param bool $forceManual Forzado UI: gasta cuota en el vuelo en pantalla sin crear flight_watches
     *                          ni exigirle prioridad 1 (solo campana = vigilado).
     * @return array<string,mixed>
     */
    public function captureOne(int $flightId, bool $allowFeederFollowUp = true, bool $forceManual = false): array
    {
        if (!$this->ready()) throw new RuntimeException('Aplica la actualización MySQL 015 de AeroAPI.');
        if (!$this->configured()) {
            throw new RuntimeException('AeroAPI no configurada: define FLIGHTAWARE_API_KEY o AEROAPI_KEY en .env.');
        }
        $quota = $this->quota();
        $quota->assertCanSpend(1);
        $stmt = $this->pdo->prepare(
            'SELECT f.id,f.flight_date,f.physical_flight,f.origin_iata,f.origin_name,
                    f.scheduled_arrival,f.scheduled_departure,f.is_canary,f.aircraft_registration,
                    (SELECT GROUP_CONCAT(c.flight_code ORDER BY c.flight_code SEPARATOR \',\')
                     FROM flight_codes c WHERE c.flight_id=f.id) AS aliases
             FROM flights f WHERE f.id=:id'
        );
        $stmt->execute(['id' => $flightId]);
        $row = $stmt->fetch();
        if (!is_array($row)) throw new RuntimeException('El vuelo ya no existe.');
        if (!$forceManual && !$this->isEligible($row)) {
            throw new RuntimeException('AeroAPI no gasta cuota en este vuelo: fuera de vigilados/Canarias/feeder/nube.');
        }
        $mapped = $this->mapFlight($row);
        if ($mapped === null) throw new RuntimeException('No se pudo traducir el vuelo a un ident AeroAPI.');
        $client = new AeroApiClient($this->pdo);
        $parsed = $client->fetchFlight($mapped);
        $this->rememberAircraft($flightId, $parsed);
        $snapshot = $parsed + [
            'flight_id' => $flightId,
            'physical_flight' => (string)$row['physical_flight'],
            'observed_at' => date('c'),
        ];
        $result = $this->ingest([$snapshot], false);
        $result['snapshot'] = $this->latest($flightId);
        $result['source_kind'] = 'aeroapi';
        $result['quota'] = $quota->status();
        $result['ok'] = true;

        // Prioridad 4: tramo anterior del Canarias noche (1 result set extra si queda margen).
        if ($allowFeederFollowUp
            && (new FlightFeederService($this->pdo))->isNightCanary($row)
            && $quota->canSpend(1)) {
            $feeder = $this->captureFeederLeg($row, $mapped, $parsed, $client);
            if ($feeder !== null) {
                $result['feeder'] = $feeder;
                $result['quota'] = $quota->status();
            }
        }
        return $result;
    }

    /**
     * Consulta AeroAPI del vuelo de ida / tramo anterior (SVQ→isla o feeder_code).
     *
     * @param array<string,mixed> $flight
     * @param array<string,mixed> $mapped
     * @param array<string,mixed> $inbound
     * @return array<string,mixed>|null
     */
    private function captureFeederLeg(array $flight, array $mapped, array $inbound, AeroApiClient $client): ?array
    {
        $island = strtoupper(trim((string)($flight['origin_iata'] ?? '')));
        $carrier = (string)($mapped['iata_carrier'] ?? '');
        $number = (string)($mapped['iata_number'] ?? '');
        $paired = IataFlightCode::pairedNumbers($number);
        $idents = [];
        $knownFeeder = strtoupper(preg_replace('/\s+/', '', (string)($flight['feeder_code'] ?? '')) ?? '');
        if ($knownFeeder !== '') $idents[] = $knownFeeder;
        foreach ($paired as $pairNumber) {
            $idents[] = $carrier . $pairNumber;
            $icao = IataFlightCode::aeroIdents($carrier . $pairNumber);
            if ($icao && !empty($icao['icao'])) $idents[] = $icao['icao'];
        }
        $idents = array_values(array_unique(array_filter($idents)));
        if ($idents === []) return null;

        $scheduled = (string)($flight['scheduled_departure'] ?: $flight['scheduled_arrival']);
        foreach ($idents as $ident) {
            if (!$client->quota()->canSpend(1)) break;
            try {
                $probe = [
                    'physical_flight' => $ident,
                    'iata_carrier' => $carrier,
                    'iata_number' => preg_replace('/^[A-Z]{2,3}/', '', $ident) ?: $number,
                    'origin_iata' => 'SVQ',
                    'destination_iata' => $island,
                    'scheduled_arrival' => $scheduled,
                ];
                // Reutiliza fetchFlight con destino isla (salida desde SVQ).
                $parsed = $client->fetchFlight($probe);
                if (!empty($parsed['delay_minutes']) || !empty($parsed['actual_departure']) || !empty($parsed['status_text'])) {
                    $this->storeFeederSnapshot((int)$flight['id'], $ident, $parsed, $island);
                    return [
                        'ident' => $ident,
                        'delay_minutes' => $parsed['delay_minutes'] ?? null,
                        'status_text' => $parsed['status_text'] ?? null,
                        'actual_departure' => $parsed['actual_departure'] ?? null,
                        'eta' => $parsed['eta'] ?? null,
                    ];
                }
            } catch (Throwable) {
                continue;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function captureDue(int $limit = 12, bool $dispatch = true): array
    {
        $quotaStatus = $this->quota()->status();
        if (!$this->configured()) {
            return [
                'ok' => true,
                'configured' => false,
                'requested' => 0,
                'saved' => 0,
                'unchanged' => 0,
                'errors' => [],
                'quota' => $quotaStatus,
                'message' => 'AeroAPI no configurada: define FLIGHTAWARE_API_KEY o AEROAPI_KEY en .env.',
            ];
        }
        if (!$this->ready()) {
            return [
                'ok' => true,
                'configured' => false,
                'requested' => 0,
                'saved' => 0,
                'unchanged' => 0,
                'errors' => [],
                'quota' => $quotaStatus,
                'message' => 'Aplica la actualización MySQL 015 de AeroAPI.',
            ];
        }
        if (!empty($quotaStatus['exhausted'])) {
            return [
                'ok' => true,
                'configured' => true,
                'requested' => 0,
                'saved' => 0,
                'unchanged' => 0,
                'errors' => [],
                'quota' => $quotaStatus,
                'message' => (string)$quotaStatus['message'],
            ];
        }
        $queued = $this->queue($limit);
        $flights = $queued['flights'] ?? [];
        $saved = $unchanged = 0;
        $errors = [];
        $aenaForced = false;
        $aena = null;
        $stoppedForQuota = false;
        foreach ($flights as $index => $flight) {
            if (!$this->quota()->canSpend(1)) {
                $stoppedForQuota = true;
                break;
            }
            $code = (string)($flight['physical_flight'] ?? '');
            try {
                $result = $this->captureOne((int)$flight['id']);
                $saved += (int)($result['saved'] ?? 0);
                $unchanged += (int)($result['unchanged'] ?? 0);
                if (!empty($result['aena_forced'])) {
                    $aenaForced = true;
                    $aena = $result['aena'] ?? $aena;
                }
            } catch (Throwable $error) {
                $message = $error->getMessage();
                $errors[] = trim($code . ': ' . $message);
                $this->markError((int)$flight['id'], $message);
                if (str_contains($message, 'Tope AeroAPI') || str_contains($message, 'cuota')) {
                    $stoppedForQuota = true;
                    break;
                }
            }
            if ($index < count($flights) - 1) usleep(200_000);
        }
        $push = null;
        if ($dispatch && $saved > 0) {
            try {
                $push = (new WebPushService($this->pdo))->dispatch(50);
            } catch (Throwable $error) {
                $push = ['error' => substr($error->getMessage(), 0, 300)];
            }
        }
        if ($saved > 0) {
            FlightRepository::forgetSnapshotCaches();
        }
        $quotaStatus = $this->quota()->status();
        return [
            'ok' => true,
            'configured' => true,
            'requested' => count($flights),
            'saved' => $saved,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'aena_forced' => $aenaForced,
            'aena' => $aena,
            'stopped_for_quota' => $stoppedForQuota,
            'quota' => $quotaStatus,
            'push' => $push,
            'message' => $stoppedForQuota
                ? (string)$quotaStatus['message']
                : null,
        ];
    }

    private function markError(int $flightId, string $message): void
    {
        $error = substr($message, 0, 500);
        $this->pdo->prepare(
            "INSERT INTO aeroapi_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
             VALUES (:id,NOW(),NOW(),1,'error',:error_ins)
             ON DUPLICATE KEY UPDATE processed_at=NOW(),status='error',last_error=:error_upd"
        )->execute(['id' => $flightId, 'error_ins' => $error, 'error_upd' => $error]);
    }

    /** @return array<string,mixed>|null */
    public function latest(int $flightId): ?array
    {
        if (!$this->ready()) return null;
        $stmt = $this->pdo->prepare(
            'SELECT id,flight_id,observed_at,public_url,fa_flight_id,ident_iata,ident_icao,status_text,delay_minutes,
                    eta,actual_departure,actual_arrival,gate,belt,registration,aircraft_type,phase,progress_percent
             FROM aeroapi_snapshots WHERE flight_id=? ORDER BY observed_at DESC,id DESC LIMIT 1'
        );
        $stmt->execute([$flightId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function requeue(int $flightId): bool
    {
        if (!$this->ready() || !$this->configured()) return false;
        $this->pdo->prepare(
            "INSERT INTO aeroapi_requests (flight_id,requested_at,processed_at,request_count,status,last_error)
             VALUES (:id,NOW(),NULL,1,'queued',NULL)
             ON DUPLICATE KEY UPDATE requested_at=NOW(),processed_at=NULL,
               request_count=request_count+1,status='queued',last_error=NULL"
        )->execute(['id' => $flightId]);
        return true;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function mapFlight(array $row): ?array
    {
        $aliases = preg_split('/\s*,\s*/', (string)($row['aliases'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $idents = IataFlightCode::aeroIdents((string)$row['physical_flight'], $aliases);
        if ($idents === null) return null;
        return [
            'id' => (int)$row['id'],
            'flight_date' => (string)$row['flight_date'],
            'physical_flight' => (string)$row['physical_flight'],
            'origin_iata' => (string)$row['origin_iata'],
            'origin_name' => (string)$row['origin_name'],
            'scheduled_arrival' => (string)$row['scheduled_arrival'],
            'scheduled_departure' => $row['scheduled_departure'],
            'is_canary' => (int)$row['is_canary'],
            'aliases' => implode(',', $aliases),
            'iata_carrier' => $idents['carrier'],
            'iata_number' => $idents['number'],
            'ident_iata' => $idents['iata'],
            'ident_icao' => $idents['icao'],
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $flight
     * @return array<string,mixed>
     */
    private function normalizeSnapshot(array $record, array $flight): array
    {
        $text = static fn(string $key, int $max): ?string => isset($record[$key]) && trim((string)$record[$key]) !== ''
            ? substr(trim((string)$record[$key]), 0, $max) : null;
        $observed = new \DateTimeImmutable((string)($record['observed_at'] ?? 'now'));
        $delay = isset($record['delay_minutes']) && is_numeric($record['delay_minutes'])
            ? max(-24 * 60, min(24 * 60, (int)round((float)$record['delay_minutes']))) : null;
        $eta = $this->localDateTime($record['eta'] ?? null);
        $actualArrival = $this->localDateTime($record['actual_arrival'] ?? null);
        $actualDeparture = $this->localDateTime($record['actual_departure'] ?? null);
        if ($eta === null && $delay !== null && $delay !== 0) {
            $scheduledAt = strtotime((string)$flight['scheduled_arrival']);
            if ($scheduledAt !== false) {
                $eta = date('Y-m-d H:i:s', $scheduledAt + $delay * 60);
            }
        }
        $progress = isset($record['progress_percent']) && is_numeric($record['progress_percent'])
            ? max(0, min(100, (int)$record['progress_percent'])) : null;
        $registration = strtoupper((string)($text('registration', 15) ?? $text('aircraft_registration', 15) ?? ''));
        if ($registration !== '' && !preg_match('/^[A-Z0-9-]{3,15}$/', $registration)) $registration = '';
        $publicUrl = $text('public_url', 300)
            ?: ('https://www.flightaware.com/live/flight/' . rawurlencode((string)$flight['physical_flight']));
        $estimatedDeparture = $this->localDateTime($record['estimated_departure'] ?? null);
        $rawExcerpt = $text('raw_excerpt', 8000);
        // Persistimos estimated_departure en el excerpt para la ventana T−60 sin migración.
        $meta = [
            'estimated_departure' => $estimatedDeparture,
            'delay_minutes' => $delay,
            'belt' => AeroApiParser::belt((string)($record['belt'] ?? '')),
        ];
        if ($rawExcerpt !== null && str_starts_with(ltrim($rawExcerpt), '{')) {
            $decoded = json_decode($rawExcerpt, true);
            if (is_array($decoded)) {
                $meta = $decoded + $meta;
            }
        } elseif ($rawExcerpt !== null) {
            $meta['excerpt'] = substr($rawExcerpt, 0, 7000);
        }
        return [
            'flight_id' => (int)$flight['id'],
            'observed_at' => $observed->format('Y-m-d H:i:s'),
            'public_url' => $publicUrl,
            'fa_flight_id' => $text('fa_flight_id', 40),
            'ident_iata' => $text('ident_iata', 12),
            'ident_icao' => $text('ident_icao', 12),
            'status_text' => $text('status_text', 160),
            'delay_minutes' => $delay,
            'eta' => $eta,
            'actual_departure' => $actualDeparture,
            'actual_arrival' => $actualArrival,
            'gate' => $text('gate', 20),
            'belt' => AeroApiParser::belt((string)($record['belt'] ?? '')),
            'registration' => $registration !== '' ? $registration : null,
            'aircraft_type' => $text('aircraft_type', 20),
            'phase' => $text('phase', 40),
            'progress_percent' => $progress,
            'raw_excerpt' => substr(json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 8000),
        ];
    }

    private function localDateTime(mixed $value): ?string
    {
        if ($value === null || trim((string)$value) === '') return null;
        $asString = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $asString)) return $asString;
        return AeroApiParser::utcToLocal($asString);
    }

    /**
     * @param array<string,mixed> $flight
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>|null
     */
    private function observationRecord(array $flight, array $snapshot): ?array
    {
        $status = $snapshot['status_text'];
        $eta = $snapshot['eta'];
        $departure = $snapshot['actual_departure'];
        $arrival = $snapshot['actual_arrival'];
        $belt = isset($snapshot['belt']) && trim((string)$snapshot['belt']) !== ''
            ? strtoupper(trim((string)$snapshot['belt'])) : null;
        $gate = isset($snapshot['gate']) && trim((string)$snapshot['gate']) !== ''
            ? strtoupper(trim((string)$snapshot['gate'])) : null;
        $registration = isset($snapshot['registration']) && trim((string)$snapshot['registration']) !== ''
            ? strtoupper(trim((string)$snapshot['registration'])) : null;
        if ($status === null && $eta === null && $departure === null && $arrival === null
            && $belt === null && $gate === null && $registration === null) {
            return null;
        }
        $diverges = $this->beltDivergesFromAena($flight, $belt);
        return [
            'source_key' => implode('|', [
                (string)$flight['flight_date'],
                (string)$flight['physical_flight'],
                (string)$flight['origin_iata'],
                'SVQ',
            ]),
            'flight_date' => $flight['flight_date'],
            'physical_flight' => $flight['physical_flight'],
            'origin_iata' => $flight['origin_iata'],
            'origin_name' => $flight['origin_name'],
            'scheduled_arrival' => $flight['scheduled_arrival'],
            'is_canary' => (int)$flight['is_canary'] === 1,
            'status' => $status,
            'eta' => $eta,
            'actual_departure' => $departure,
            'actual_arrival' => $arrival,
            'belt' => $belt,
            'gate' => $gate,
            'aircraft_registration' => $registration,
            'aircraft_type' => $snapshot['aircraft_type'] ?? null,
            'confidence' => 'provisional',
            'reason_code' => 'secondary_provider_update',
            'reason_detail' => $diverges
                ? 'FlightAware AeroAPI propone cinta ' . $belt . ' distinta de Aena; se fuerza revalidación Infovuelos. No sustituye la cinta oficial.'
                : ($belt !== null
                    ? 'FlightAware AeroAPI publicó cinta provisional ' . $belt . '; no sustituye a Aena.'
                    : 'FlightAware AeroAPI actualizó estado, ETA, salida o llegada; no sustituye cinta ni sala de Aena.'),
            'occupancy_level' => 'no_verificable',
            'raw_data' => [
                'provider' => 'aeroapi',
                'fa_flight_id' => $snapshot['fa_flight_id'] ?? null,
                'delay_minutes' => $snapshot['delay_minutes'],
                'phase' => $snapshot['phase'],
                'belt' => $belt,
                'gate' => $gate,
                'public_url' => $snapshot['public_url'],
                'belt_diverges_from_aena' => $diverges,
            ],
        ];
    }

    /** @param array<string,mixed> $flight */
    private function beltDivergesFromAena(array $flight, ?string $belt): bool
    {
        $belt = strtoupper(trim((string)$belt));
        if ($belt === '') return false;
        $aenaBelt = strtoupper(trim((string)($flight['aena_belt'] ?? '')));
        if ($aenaBelt === '') return true; // Aena aún no tiene cinta: forzar reconfirmación
        return $aenaBelt !== $belt;
    }

    /**
     * Aviso crítico solo a quien YA vigila (campana). No crea flight_watches.
     *
     * @param array<string,mixed> $flight
     */
    private function queueCriticalBeltDivergencePush(array $flight, string $aenaBelt, string $aeroBelt): int
    {
        $flightId = (int)($flight['id'] ?? 0);
        if ($flightId < 1 || $aeroBelt === '') return 0;
        try {
            $subs = $this->pdo->prepare(
                'SELECT s.id FROM push_subscriptions s
                 INNER JOIN flight_watches w ON w.subscription_id=s.id
                 WHERE w.flight_id=:flight_id AND w.enabled=1 AND s.active=1'
            );
            $subs->execute(['flight_id' => $flightId]);
            $rows = $subs->fetchAll();
            if ($rows === []) return 0;

            $title = '🚨 CINTA FlightAware ≠ Aena · '
                . ((int)($flight['is_canary'] ?? 0) === 1 ? 'Canarias · ' : '')
                . ($flight['origin_name'] ?? '') . ' · ' . ($flight['physical_flight'] ?? '');
            $aenaLabel = $aenaBelt !== '' ? $aenaBelt : 'sin cinta';
            $body = 'FlightAware propone cinta ' . $aeroBelt . ' · Aena tiene ' . $aenaLabel
                . '. Se fuerza Infovuelos. Oficial sigue siendo Aena.';
            $url = 'index.php?date=' . rawurlencode(substr((string)($flight['flight_date'] ?? ''), 0, 10))
                . '&flight=' . $flightId;
            // flight_event_id NULL: fingerprint en body+title evita duplicar el mismo aviso.
            $insert = $this->pdo->prepare(
                'INSERT INTO push_outbox (subscription_id,flight_event_id,title,body,target_url)
                 SELECT :subscription_id,NULL,:title,:body,:target_url FROM DUAL
                 WHERE NOT EXISTS (
                   SELECT 1 FROM push_outbox o
                   WHERE o.subscription_id=:subscription_id_chk
                     AND o.title=:title_chk
                     AND o.created_at>=DATE_SUB(NOW(),INTERVAL 30 MINUTE)
                 )'
            );
            $queued = 0;
            foreach ($rows as $row) {
                $sid = (int)$row['id'];
                $insert->execute([
                    'subscription_id' => $sid,
                    'subscription_id_chk' => $sid,
                    'title' => substr($title, 0, 180),
                    'title_chk' => substr($title, 0, 180),
                    'body' => substr($body, 0, 500),
                    'target_url' => $url,
                ]);
                if ($insert->rowCount() > 0) $queued++;
            }
            return $queued;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Forzado batch Canarias: AeroAPI prioritario + reconfirmación Aena.
     * No crea ni toca flight_watches.
     *
     * @return array<string,mixed>
     */
    public function forceCanariasBatch(int $limit = 12): array
    {
        $limit = max(1, min(16, $limit));
        $aena = null;
        try {
            $aena = (new WebOperationsService($this->pdo))->requestAenaSweep('force_canarias_batch');
        } catch (Throwable $error) {
            $aena = ['ok' => false, 'error' => substr($error->getMessage(), 0, 300)];
        }
        if (!$this->configured() || !$this->ready()) {
            return [
                'ok' => true,
                'mode' => 'canarias',
                'requested' => 0,
                'saved' => 0,
                'aena' => $aena,
                'quota' => $this->quota()->status(),
                'watched_created' => false,
                'message' => 'Aena pedida. AeroAPI no disponible o sin migración.',
            ];
        }
        $quota = $this->quota()->status();
        if (!empty($quota['exhausted'])) {
            return [
                'ok' => true,
                'mode' => 'canarias',
                'requested' => 0,
                'saved' => 0,
                'aena' => $aena,
                'quota' => $quota,
                'watched_created' => false,
                'message' => (string)$quota['message'],
            ];
        }
        // Batch directo Canarias (ignora cadencia de cola): no crea watches.
        $stmt = $this->pdo->query(
            "SELECT f.id,f.physical_flight,f.scheduled_arrival,f.scheduled_departure,f.is_canary,f.origin_iata,
                    EXISTS (
                      SELECT 1 FROM flight_watches w
                      INNER JOIN push_subscriptions s ON s.id=w.subscription_id
                      WHERE w.flight_id=f.id AND w.enabled=1 AND s.active=1
                    ) AS is_watched
             FROM flights f
             WHERE f.is_canary=1
               AND f.scheduled_arrival BETWEEN DATE_SUB(NOW(),INTERVAL 8 HOUR)
                                           AND DATE_ADD(NOW(),INTERVAL 16 HOUR)
             ORDER BY
               CASE WHEN EXISTS (
                 SELECT 1 FROM flight_watches w
                 INNER JOIN push_subscriptions s ON s.id=w.subscription_id
                 WHERE w.flight_id=f.id AND w.enabled=1 AND s.active=1
               ) THEN 0 ELSE 1 END,
               CASE WHEN HOUR(f.scheduled_arrival)>=18 OR HOUR(f.scheduled_arrival)<2 THEN 0 ELSE 1 END,
               f.scheduled_arrival
             LIMIT 40"
        );
        $canarias = array_slice($stmt ? $stmt->fetchAll() : [], 0, min($limit, (int)$quota['remaining']));
        $saved = $unchanged = 0;
        $errors = [];
        $aenaForced = !empty($aena['ok']);
        foreach ($canarias as $index => $flight) {
            if (!$this->quota()->canSpend(1)) break;
            try {
                // forceManual=false: Canarias ya es elegible; no tocamos flight_watches.
                $result = $this->captureOne((int)$flight['id'], true, false);
                $saved += (int)($result['saved'] ?? 0);
                $unchanged += (int)($result['unchanged'] ?? 0);
                if (!empty($result['aena_forced'])) $aenaForced = true;
            } catch (Throwable $error) {
                $errors[] = trim(($flight['physical_flight'] ?? '') . ': ' . $error->getMessage());
                if (str_contains($error->getMessage(), 'Tope AeroAPI') || str_contains($error->getMessage(), 'cuota')) {
                    break;
                }
            }
            if ($index < count($canarias) - 1) usleep(150_000);
        }
        FlightRepository::forgetSnapshotCaches();
        $quota = $this->quota()->status();
        return [
            'ok' => true,
            'mode' => 'canarias',
            'requested' => count($canarias),
            'saved' => $saved,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'aena' => $aena,
            'aena_forced' => $aenaForced,
            'quota' => $quota,
            'watched_created' => false,
            'message' => sprintf(
                'Forzado Canarias: %d lecturas AeroAPI (%d nuevas) + Aena. No se ha marcado ningún vuelo como vigilado.',
                count($canarias),
                $saved
            ),
        ];
    }

    /**
     * Forzado del vuelo en pantalla + nube ±1 h. Gasta cuota a conciencia.
     * No crea flight_watches: prioridad 1 automática sigue siendo solo campana.
     *
     * @return array<string,mixed>
     */
    public function forceFlightAndCloud(int $flightId): array
    {
        if ($flightId < 1) throw new RuntimeException('Vuelo no válido.');
        $aena = null;
        try {
            $aena = (new WebOperationsService($this->pdo))->requestAenaSweep('force_flight_cloud');
        } catch (Throwable $error) {
            $aena = ['ok' => false, 'error' => substr($error->getMessage(), 0, 300)];
        }

        $center = $this->pdo->prepare(
            'SELECT id,physical_flight,scheduled_arrival,is_canary,flight_date,origin_name
             FROM flights WHERE id=:id'
        );
        $center->execute(['id' => $flightId]);
        $flight = $center->fetch();
        if (!is_array($flight)) throw new RuntimeException('El vuelo ya no existe.');

        $when = (string)$flight['scheduled_arrival'];
        $neighbors = $this->pdo->prepare(
            'SELECT f.id,f.physical_flight,f.is_canary,f.scheduled_arrival
             FROM flights f
             WHERE f.id<>:id
               AND f.scheduled_arrival BETWEEN DATE_SUB(:when_from, INTERVAL ' . self::CLOUD_MINUTES . ' MINUTE)
                                          AND DATE_ADD(:when_to, INTERVAL ' . self::CLOUD_MINUTES . ' MINUTE)
             ORDER BY ABS(TIMESTAMPDIFF(MINUTE, f.scheduled_arrival, :when_order))
             LIMIT 14'
        );
        $neighbors->execute([
            'id' => $flightId,
            'when_from' => $when,
            'when_to' => $when,
            'when_order' => $when,
        ]);
        $cloud = $neighbors->fetchAll() ?: [];

        $targets = [['id' => $flightId, 'physical_flight' => $flight['physical_flight'], 'force' => true]];
        foreach ($cloud as $row) {
            // Nube: Canarias, feeder o ya elegible; el centro se fuerza aunque no lo sea.
            if ((int)($row['is_canary'] ?? 0) === 1 || $this->isEligible($row)) {
                $targets[] = [
                    'id' => (int)$row['id'],
                    'physical_flight' => (string)$row['physical_flight'],
                    'force' => false,
                ];
            }
        }

        $saved = $unchanged = $captured = 0;
        $errors = [];
        $snapshots = [];
        $aenaForced = !empty($aena['ok']);
        foreach ($targets as $index => $target) {
            if (!$this->quota()->canSpend(1)) {
                $errors[] = 'Cuota AeroAPI agotada a mitad del forzado.';
                break;
            }
            try {
                $result = $this->captureOne((int)$target['id'], (int)$target['id'] === $flightId, !empty($target['force']));
                $captured++;
                $saved += (int)($result['saved'] ?? 0);
                $unchanged += (int)($result['unchanged'] ?? 0);
                if (!empty($result['aena_forced'])) $aenaForced = true;
                if (!empty($result['snapshot'])) $snapshots[] = $result['snapshot'];
            } catch (Throwable $error) {
                $errors[] = trim(($target['physical_flight'] ?? '') . ': ' . $error->getMessage());
                if (str_contains($error->getMessage(), 'Tope AeroAPI') || str_contains($error->getMessage(), 'cuota')) {
                    break;
                }
            }
            if ($index < count($targets) - 1) usleep(150_000);
        }
        FlightRepository::forgetSnapshotCaches();
        $quota = $this->quota()->status();
        return [
            'ok' => true,
            'mode' => 'flight_cloud',
            'flight_id' => $flightId,
            'flight' => (string)$flight['physical_flight'],
            'cloud_minutes' => self::CLOUD_MINUTES,
            'requested' => count($targets),
            'captured' => $captured,
            'saved' => $saved,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'aena' => $aena,
            'aena_forced' => $aenaForced,
            'quota' => $quota,
            'snapshot' => $snapshots[0] ?? null,
            'watched_created' => false,
            'message' => sprintf(
                'Forzado %s + nube ±%d min: %d AeroAPI (%d nuevas) + Aena. Sin marcar vigilado.',
                (string)$flight['physical_flight'],
                self::CLOUD_MINUTES,
                $captured,
                $saved
            ),
        ];
    }

    /**
     * @param array<string,mixed> $parsed
     */
    private function storeFeederSnapshot(int $flightId, string $ident, array $parsed, string $island): void
    {
        if ($flightId < 1) return;
        try {
            $has = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='flight_feeders'"
            )->fetchColumn();
            if ($has !== 1) return;
            $hasTail = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE() AND table_name='flight_feeders'
                   AND column_name='aircraft_registration'"
            )->fetchColumn() === 1;
            $tail = strtoupper(trim((string)($parsed['registration'] ?? $parsed['aircraft_registration'] ?? '')));
            if ($tail !== '' && !preg_match('/^[A-Z0-9-]{3,15}$/', $tail)) $tail = '';
            $sql = 'INSERT INTO flight_feeders
                 (flight_id,observed_at,feeder_code,origin_iata,destination_iata,delay_minutes,eta,
                  actual_departure,actual_arrival,status_text,match_reason,public_url'
                . ($hasTail ? ',aircraft_registration' : '') . ')
                 VALUES
                 (:flight_id,NOW(),:feeder_code,:origin,:dest,:delay,:eta,:dep,:arr,:status,:reason,:url'
                . ($hasTail ? ',:reg' : '') . ')
                 ON DUPLICATE KEY UPDATE observed_at=VALUES(observed_at),feeder_code=VALUES(feeder_code),
                   origin_iata=VALUES(origin_iata),destination_iata=VALUES(destination_iata),
                   delay_minutes=VALUES(delay_minutes),eta=VALUES(eta),
                   actual_departure=VALUES(actual_departure),actual_arrival=VALUES(actual_arrival),
                   status_text=VALUES(status_text),match_reason=VALUES(match_reason),public_url=VALUES(public_url)'
                . ($hasTail ? ',aircraft_registration=VALUES(aircraft_registration)' : '');
            $params = [
                'flight_id' => $flightId,
                'feeder_code' => substr(strtoupper(preg_replace('/\s+/', '', $ident) ?? $ident), 0, 12),
                'origin' => substr((string)($parsed['origin_iata'] ?? 'SVQ'), 0, 3),
                'dest' => substr((string)($parsed['destination_iata'] ?? $island), 0, 3),
                'delay' => isset($parsed['delay_minutes']) && is_numeric($parsed['delay_minutes'])
                    ? (int)$parsed['delay_minutes'] : null,
                'eta' => $parsed['eta'] ?? null,
                'dep' => $parsed['actual_departure'] ?? null,
                'arr' => $parsed['actual_arrival'] ?? null,
                'status' => isset($parsed['status_text']) ? substr((string)$parsed['status_text'], 0, 160) : null,
                'reason' => 'paired_number',
                'url' => isset($parsed['public_url']) ? substr((string)$parsed['public_url'], 0, 300) : null,
            ];
            if ($hasTail) $params['reg'] = $tail !== '' ? $tail : null;
            $this->pdo->prepare($sql)->execute($params);
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $parsed */
    private function rememberAircraft(int $flightId, array $parsed): void
    {
        $tail = strtoupper(trim((string)($parsed['aircraft_registration'] ?? $parsed['registration'] ?? '')));
        $type = trim((string)($parsed['aircraft_type'] ?? ''));
        if ($flightId < 1) return;
        try {
            if (preg_match('/^[A-Z0-9-]{3,15}$/', $tail)) {
                $this->pdo->prepare(
                    'UPDATE flights SET aircraft_registration=:tail,
                       aircraft_type=COALESCE(aircraft_type,NULLIF(:type,\'\')) WHERE id=:id'
                )->execute(['tail' => $tail, 'type' => $type, 'id' => $flightId]);
            } elseif ($type !== '') {
                $this->pdo->prepare(
                    'UPDATE flights SET aircraft_type=COALESCE(aircraft_type,:type) WHERE id=:id'
                )->execute(['type' => $type, 'id' => $flightId]);
            }
        } catch (Throwable) {
        }
    }
}
