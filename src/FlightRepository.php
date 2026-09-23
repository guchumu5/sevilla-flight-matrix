<?php
declare(strict_types=1);

namespace SevillaMatrix;

use PDO;

final class FlightRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function board(string $date): array
    {
        $sql = <<<'SQL'
SELECT
    f.*,
    COALESCE(latest.eta, f.scheduled_arrival) AS effective_arrival,
    latest.observed_at,
    latest.source,
    latest.status,
    latest.eta,
    latest.actual_departure,
    latest.actual_arrival,
    latest.hall,
    latest.belt,
    latest.gate,
    latest.stand,
    latest.baggage_state,
    latest.occupancy_level,
    COALESCE(codes.codes, f.physical_flight) AS codes,
    COALESCE(changes.belt_changes, 0) AS belt_changes,
    changes.first_belt_at,
    TIMESTAMPDIFF(MINUTE, changes.first_belt_at, f.scheduled_arrival) AS first_belt_lead_minutes
FROM flights f
LEFT JOIN observations latest ON latest.id = (
    SELECT o.id FROM observations o
    WHERE o.flight_id = f.id
    ORDER BY (o.source = 'aena') DESC, o.observed_at DESC, o.id DESC
    LIMIT 1
)
LEFT JOIN (
    SELECT flight_id, GROUP_CONCAT(flight_code ORDER BY flight_code SEPARATOR ' / ') AS codes
    FROM flight_codes GROUP BY flight_id
) codes ON codes.flight_id = f.id
LEFT JOIN (
    SELECT flight_id,
           GREATEST(COUNT(DISTINCT CONCAT(COALESCE(hall,''), '/', COALESCE(belt,''))) - 1, 0) AS belt_changes,
           MIN(CASE WHEN belt IS NOT NULL AND belt <> '' THEN observed_at END) AS first_belt_at,
           SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN belt IS NOT NULL AND belt <> '' THEN belt END ORDER BY observed_at, id SEPARATOR ','), ',', 1) AS first_belt,
           SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN belt IS NOT NULL AND belt <> '' THEN hall END ORDER BY observed_at, id SEPARATOR ','), ',', 1) AS first_hall
    FROM observations
    GROUP BY flight_id
) changes ON changes.flight_id = f.id
WHERE f.flight_date = :date
ORDER BY effective_arrival, f.physical_flight
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date' => $date]);
        return (new MatrixEngine())->decorate($stmt->fetchAll());
    }

    public function history(int $flightId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, source, observed_at, status, eta, actual_departure, actual_arrival,
                    hall, belt, gate, stand, baggage_state, latitude, longitude,
                    altitude_m, ground_speed_ms, track_deg, occupancy_level
             FROM observations WHERE flight_id = ? ORDER BY observed_at, id'
        );
        $stmt->execute([$flightId]);
        return $stmt->fetchAll();
    }

    public function upsertFlight(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO flights
             (flight_date, physical_flight, origin_iata, origin_name, scheduled_arrival,
              traffic_class, border_control, is_canary, aircraft_registration, aircraft_icao24,
              aircraft_type, capacity)
             VALUES (:flight_date, :physical_flight, :origin_iata, :origin_name, :scheduled_arrival,
              :traffic_class, :border_control, :is_canary, :aircraft_registration, :aircraft_icao24,
              :aircraft_type, :capacity)
             ON DUPLICATE KEY UPDATE
              id=LAST_INSERT_ID(id), origin_name=VALUES(origin_name),
              scheduled_arrival=VALUES(scheduled_arrival), traffic_class=VALUES(traffic_class),
              border_control=VALUES(border_control), is_canary=VALUES(is_canary),
              aircraft_registration=COALESCE(VALUES(aircraft_registration), aircraft_registration),
              aircraft_icao24=COALESCE(VALUES(aircraft_icao24), aircraft_icao24),
              aircraft_type=COALESCE(VALUES(aircraft_type), aircraft_type),
              capacity=COALESCE(VALUES(capacity), capacity)'
        );
        $stmt->execute($data);
        return (int)$this->pdo->lastInsertId();
    }

    public function addObservation(int $flightId, array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO observations
             (flight_id, source, observed_at, status, eta, actual_departure, actual_arrival,
              hall, belt, gate, stand, baggage_state, occupancy_level, raw_data)
             VALUES (:flight_id, :source, :observed_at, :status, :eta, :actual_departure, :actual_arrival,
              :hall, :belt, :gate, :stand, :baggage_state, :occupancy_level, :raw_data)'
        );
        $stmt->execute([
            'flight_id' => $flightId,
            'source' => $data['source'],
            'observed_at' => $data['observed_at'],
            'status' => $data['status'] ?: null,
            'eta' => $data['eta'] ?: null,
            'actual_departure' => $data['actual_departure'] ?: null,
            'actual_arrival' => $data['actual_arrival'] ?: null,
            'hall' => $data['hall'] ?: null,
            'belt' => $data['belt'] ?: null,
            'gate' => $data['gate'] ?: null,
            'stand' => $data['stand'] ?: null,
            'baggage_state' => $data['baggage_state'] ?: null,
            'occupancy_level' => $data['occupancy_level'] ?: 'no_verificable',
            'raw_data' => $data['raw_data'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function trackedAircraft(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, physical_flight, aircraft_icao24 FROM flights
             WHERE flight_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)
             AND aircraft_icao24 IS NOT NULL"
        );
        return $stmt->fetchAll();
    }
}
