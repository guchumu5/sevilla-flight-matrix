<?php
declare(strict_types=1);

namespace SevillaMatrix\Operations;

use PDO;
use RuntimeException;
use SevillaMatrix\Api\AirLabsClient;
use SevillaMatrix\Api\AviationWeatherClient;
use SevillaMatrix\Api\OpenSkyClient;
use SevillaMatrix\Env;
use SevillaMatrix\Sync\FlightReconciliationService;
use Throwable;

final class WebOperationsService
{
    private const ACTIONS = ['airlabs', 'opensky', 'weather', 'all'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $requiredTables = ['flights', 'observations', 'weather_observations', 'fetch_runs', 'sync_runs', 'flight_source_state', 'flight_events'];
        $existing = [];
        $stmt = $this->pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $existing[(string)$table] = true;
        }

        $latestRuns = [];
        if (isset($existing['sync_runs'])) {
            $latestRuns = $this->pdo->query(
                'SELECT id, provider, mode, started_at, finished_at, status, records_received,
                        observations_created, events_created, error_message
                 FROM sync_runs ORDER BY id DESC LIMIT 8'
            )->fetchAll();
        }
        $latestFetchRuns = [];
        if (isset($existing['fetch_runs'])) {
            $latestFetchRuns = $this->pdo->query(
                'SELECT id, provider, started_at, finished_at, ok, records_count, error_message
                 FROM fetch_runs ORDER BY id DESC LIMIT 12'
            )->fetchAll();
        }

        return [
            'ok' => true,
            'server_time' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'extensions' => [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'curl' => extension_loaded('curl'),
                'json' => extension_loaded('json'),
            ],
            'providers' => [
                'airlabs' => $this->configured('AIRLABS_API_KEY'),
                'opensky' => $this->configured('OPENSKY_CLIENT_ID') && $this->configured('OPENSKY_CLIENT_SECRET'),
                'aviationweather' => true,
            ],
            'storage' => [
                'cache_writable' => is_dir(PROJECT_ROOT . '/storage/cache') && is_writable(PROJECT_ROOT . '/storage/cache'),
                'logs_writable' => is_dir(PROJECT_ROOT . '/storage/logs') && is_writable(PROJECT_ROOT . '/storage/logs'),
            ],
            'tables' => array_map(
                static fn(string $table): array => ['name' => $table, 'ready' => isset($existing[$table])],
                $requiredTables
            ),
            'latest_runs' => $latestRuns,
            'latest_fetch_runs' => $latestFetchRuns,
            'limits' => ['airlabs_max' => 20, 'opensky_max' => 25],
        ];
    }

    /** @return array<string,mixed> */
    public function run(string $action, int $limit = 10): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('Proceso no permitido.');
        }

        return match ($action) {
            'airlabs' => $this->recordFetch('airlabs', fn(): array => $this->withLock('airlabs', fn(): array => $this->airLabs($this->clamp($limit, 1, 20)))),
            'opensky' => $this->recordFetch('opensky', fn(): array => $this->withLock('opensky', fn(): array => $this->openSky($this->clamp($limit, 1, 25)))),
            'weather' => $this->recordFetch('aviationweather', fn(): array => $this->withLock('weather', fn(): array => $this->weather())),
            'all' => $this->runAll($this->clamp($limit, 1, 10)),
        };
    }

    /** @return array<string,mixed> */
    private function airLabs(int $limit): array
    {
        $key = trim((string)Env::get('AIRLABS_API_KEY', ''));
        if ($key === '') {
            throw new RuntimeException('AIRLABS_API_KEY no está configurada en .env.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT f.*,
                    GROUP_CONCAT(DISTINCT fc.flight_code ORDER BY fc.flight_code SEPARATOR ',') AS codeshares
             FROM flights f
             LEFT JOIN flight_codes fc ON fc.flight_id=f.id AND fc.flight_code<>f.physical_flight
             WHERE f.scheduled_arrival BETWEEN DATE_SUB(NOW(), INTERVAL 3 HOUR) AND DATE_ADD(NOW(), INTERVAL 36 HOUR)
             GROUP BY f.id
             ORDER BY CASE WHEN f.scheduled_arrival >= NOW() THEN 0 ELSE 1 END,
                      ABS(TIMESTAMPDIFF(MINUTE, NOW(), f.scheduled_arrival))
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $flights = $stmt->fetchAll();

        if ($flights === []) {
            return ['ok' => true, 'action' => 'airlabs', 'message' => 'No hay vuelos próximos para consultar.', 'requested' => 0, 'received' => 0, 'errors' => []];
        }

        $client = new AirLabsClient($key);
        $records = [];
        $errors = [];
        foreach ($flights as $flight) {
            try {
                $codeshares = $flight['codeshares'] ? explode(',', (string)$flight['codeshares']) : [];
                $candidateCodes = array_values(array_unique(array_filter(array_map(
                    static fn(mixed $code): string => strtoupper(trim((string)$code)),
                    array_merge([(string)$flight['physical_flight']], $codeshares)
                ))));

                $data = null;
                $lookupCode = null;
                foreach ($candidateCodes as $candidateCode) {
                    $data = $client->flight($candidateCode);
                    if ($data !== null) {
                        $lookupCode = $candidateCode;
                        break;
                    }
                }
                if (!$data) {
                    $errors[] = sprintf(
                        '%s: sin datos actuales (probados: %s)',
                        $flight['physical_flight'],
                        implode(', ', $candidateCodes)
                    );
                    continue;
                }
                $destination = strtoupper(trim((string)($data['arr_iata'] ?? 'SVQ')));
                $origin = strtoupper(trim((string)($data['dep_iata'] ?? $flight['origin_iata'])));
                if ($destination !== 'SVQ' || $origin !== strtoupper((string)$flight['origin_iata'])) {
                    $errors[] = $flight['physical_flight'] . ': la respuesta corresponde a otra ruta';
                    continue;
                }
                $responseDate = substr(trim((string)($data['flight_date'] ?? '')), 0, 10);
                if ($responseDate !== '' && $responseDate !== (string)$flight['flight_date']) {
                    $errors[] = $flight['physical_flight'] . ': la respuesta corresponde a otra fecha';
                    continue;
                }

                $record = [
                    'source_key' => implode('|', [$flight['flight_date'], $flight['physical_flight'], $flight['origin_iata'], 'SVQ']),
                    'flight_date' => $flight['flight_date'],
                    'physical_flight' => $flight['physical_flight'],
                    'origin_iata' => $flight['origin_iata'],
                    'origin_name' => $flight['origin_name'],
                    'scheduled_arrival' => $flight['scheduled_arrival'],
                    'codeshares' => $codeshares,
                    'status' => $this->nullable($data['status'] ?? null),
                    'eta' => $this->providerDate($data['arr_estimated'] ?? $data['arr_estimated_utc'] ?? null),
                    'actual_departure' => $this->providerDate($data['dep_actual'] ?? $data['dep_actual_utc'] ?? null),
                    'actual_arrival' => $this->providerDate($data['arr_actual'] ?? $data['arr_actual_utc'] ?? null),
                    'hall' => $this->nullable($data['arr_terminal'] ?? null),
                    'belt' => $this->nullable($data['arr_baggage'] ?? null),
                    'gate' => $this->nullable($data['arr_gate'] ?? null),
                    'baggage_state' => null,
                    'occupancy_level' => 'no_verificable',
                    'confidence' => 'provisional',
                    'reason_code' => 'secondary_provider_update',
                    'reason_detail' => 'AirLabs actualizó la información secundaria; no constituye una causa operativa publicada por Aena.',
                    'raw_data' => $data + ['_matched_flight_code' => $lookupCode],
                ];
                foreach ([
                    'aircraft_registration' => $data['reg_number'] ?? null,
                    'aircraft_icao24' => $data['hex'] ?? null,
                    'aircraft_type' => $data['aircraft_icao'] ?? null,
                ] as $field => $value) {
                    if ($this->nullable($value) !== null) $record[$field] = $this->nullable($value);
                }
                $records[] = $record;
            } catch (Throwable $error) {
                $errors[] = $flight['physical_flight'] . ': ' . $error->getMessage();
            }
        }

        $sync = null;
        if ($records !== []) {
            $sync = (new FlightReconciliationService($this->pdo))->sync($records, [
                'provider' => 'airlabs',
                'mode' => 'delta',
                'observed_at' => date('Y-m-d H:i:s'),
                'complete' => false,
                'metadata' => ['transport' => 'admin_web', 'requested' => count($flights)],
            ]);
        }

        return [
            'ok' => true,
            'useful' => $records !== [],
            'action' => 'airlabs',
            'message' => $records !== []
                ? sprintf('AirLabs consultó %d vuelos y devolvió %d registros válidos.', count($flights), count($records))
                : sprintf('AirLabs respondió, pero ninguno de los %d vuelos produjo un registro válido.', count($flights)),
            'requested' => count($flights),
            'received' => count($records),
            'errors' => $errors,
            'sync' => $sync,
        ];
    }

    /** @return array<string,mixed> */
    private function openSky(int $limit): array
    {
        $clientId = trim((string)Env::get('OPENSKY_CLIENT_ID', ''));
        $secret = trim((string)Env::get('OPENSKY_CLIENT_SECRET', ''));
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('Faltan OPENSKY_CLIENT_ID u OPENSKY_CLIENT_SECRET en .env.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, physical_flight, aircraft_icao24 FROM flights
             WHERE flight_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)
               AND aircraft_icao24 IS NOT NULL AND aircraft_icao24<>\'\'
             ORDER BY ABS(TIMESTAMPDIFF(MINUTE, NOW(), scheduled_arrival)) LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $flights = $stmt->fetchAll();
        $client = new OpenSkyClient($clientId, $secret, PROJECT_ROOT . '/storage/cache/opensky-token.json');
        $insert = $this->pdo->prepare(
            "INSERT INTO observations
             (flight_id,source,observed_at,status,latitude,longitude,altitude_m,ground_speed_ms,track_deg,vertical_rate_ms,occupancy_level,raw_data)
             VALUES (:flight_id,'opensky',NOW(),:status,:latitude,:longitude,:altitude,:speed,:track,:vertical_rate,'no_verificable',:raw_data)"
        );
        $updated = 0;
        $errors = [];
        foreach ($flights as $flight) {
            try {
                $state = $client->state((string)$flight['aircraft_icao24']);
                if (!$state) continue;
                $insert->execute([
                    'flight_id' => (int)$flight['id'],
                    'status' => $state['on_ground'] ? 'En tierra' : 'En vuelo',
                    'latitude' => $state['latitude'],
                    'longitude' => $state['longitude'],
                    'altitude' => $state['altitude_m'],
                    'speed' => $state['ground_speed_ms'],
                    'track' => $state['track_deg'],
                    'vertical_rate' => $state['vertical_rate_ms'],
                    'raw_data' => json_encode($state['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $updated++;
            } catch (Throwable $error) {
                $errors[] = $flight['physical_flight'] . ': ' . $error->getMessage();
            }
        }

        return [
            'ok' => true,
            'action' => 'opensky',
            'message' => sprintf('OpenSky comprobó %d matrículas y guardó %d posiciones.', count($flights), $updated),
            'requested' => count($flights),
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    private function weather(): array
    {
        $data = (new AviationWeatherClient())->metar('LEZL');
        if (!$data) {
            return ['ok' => true, 'action' => 'weather', 'message' => 'AviationWeather no publicó un METAR nuevo.', 'updated' => false];
        }
        $observed = !empty($data['obsTime']) ? date('Y-m-d H:i:s', (int)$data['obsTime']) : date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO weather_observations
             (station,observed_at,wind_direction,wind_speed_kt,gust_kt,raw_metar,raw_data)
             VALUES ('LEZL',:observed,:direction,:speed,:gust,:metar,:raw_data)"
        );
        $stmt->execute([
            'observed' => $observed,
            'direction' => $data['wdir'] ?? null,
            'speed' => $data['wspd'] ?? null,
            'gust' => $data['wgst'] ?? null,
            'metar' => $data['rawOb'] ?? null,
            'raw_data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $inserted = $stmt->rowCount() > 0;

        return [
            'ok' => true,
            'action' => 'weather',
            'message' => $inserted ? 'METAR LEZL guardado correctamente.' : 'El METAR LEZL ya estaba guardado.',
            'updated' => $inserted,
            'observed_at' => $observed,
            'wind' => ['direction' => $data['wdir'] ?? null, 'speed_kt' => $data['wspd'] ?? null, 'gust_kt' => $data['wgst'] ?? null],
        ];
    }

    /** @return array<string,mixed> */
    private function runAll(int $limit): array
    {
        $results = [];
        foreach (['weather', 'airlabs', 'opensky'] as $action) {
            try {
                $results[$action] = $this->run($action, $limit);
            } catch (Throwable $error) {
                $results[$action] = ['ok' => false, 'error' => $error->getMessage()];
            }
        }
        $ok = count(array_filter($results, static fn(array $result): bool => ($result['ok'] ?? false) === true));
        $useful = count(array_filter($results, static fn(array $result): bool => (bool)($result['useful'] ?? $result['updated'] ?? false)));
        return [
            'ok' => $ok > 0,
            'action' => 'all',
            'message' => "Se ejecutaron {$ok} de 3 procesos; {$useful} aportaron datos nuevos o utilizables.",
            'results' => $results,
        ];
    }

    /** @template T @param callable():T $callback @return T */
    private function withLock(string $name, callable $callback): mixed
    {
        $lock = 'sevilla_matrix_web_' . $name;
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $stmt->execute(['lock_name' => $lock]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Este proceso ya se está ejecutando. Espera a que termine.');
        }
        try {
            return $callback();
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lock]);
        }
    }

    /** @template T of array<string,mixed> @param callable():T $callback @return T */
    private function recordFetch(string $provider, callable $callback): array
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO fetch_runs (provider,started_at,ok,records_count) VALUES (:provider,NOW(),0,0)'
        );
        $insert->execute(['provider' => $provider]);
        $runId = (int)$this->pdo->lastInsertId();
        try {
            $result = $callback();
            $count = (int)($result['received'] ?? $result['updated'] ?? 0);
            $diagnostic = null;
            if ($count === 0 && !empty($result['errors']) && is_array($result['errors'])) {
                $diagnostic = substr(implode(' | ', array_map('strval', $result['errors'])), 0, 1000);
            }
            $finish = $this->pdo->prepare(
                'UPDATE fetch_runs SET finished_at=NOW(),ok=1,records_count=:records_count,error_message=:error_message WHERE id=:id'
            );
            $finish->execute(['records_count' => $count, 'error_message' => $diagnostic, 'id' => $runId]);
            return $result + ['fetch_run_id' => $runId];
        } catch (Throwable $error) {
            $finish = $this->pdo->prepare(
                'UPDATE fetch_runs SET finished_at=NOW(),ok=0,error_message=:error_message WHERE id=:id'
            );
            $finish->execute(['error_message' => substr($error->getMessage(), 0, 1000), 'id' => $runId]);
            throw $error;
        }
    }

    private function configured(string $key): bool
    {
        return trim((string)Env::get($key, '')) !== '';
    }

    private function clamp(int $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, $value));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function providerDate(mixed $value): ?string
    {
        $value = $this->nullable($value);
        if ($value === null) return null;
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
