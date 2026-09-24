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
    COALESCE(latest_aena.actual_arrival, latest_aena.eta,
             latest_other.actual_arrival, latest_other.eta, f.scheduled_arrival) AS effective_arrival,
    COALESCE(latest_aena.observed_at, latest_other.observed_at) AS observed_at,
    CASE WHEN latest_aena.id IS NOT NULL THEN 'aena' ELSE latest_other.source END AS source,
    CASE WHEN latest_aena.id IS NOT NULL THEN latest_aena.status ELSE latest_other.status END AS status,
    COALESCE(latest_aena.eta, latest_other.eta) AS eta,
    COALESCE(latest_aena.actual_departure, latest_other.actual_departure) AS actual_departure,
    COALESCE(latest_aena.actual_arrival, latest_other.actual_arrival) AS actual_arrival,
    CASE WHEN latest_aena.id IS NOT NULL THEN latest_aena.hall ELSE latest_other.hall END AS hall,
    CASE WHEN latest_aena.id IS NOT NULL THEN latest_aena.belt ELSE latest_other.belt END AS belt,
    COALESCE(latest_aena.gate, latest_other.gate) AS gate,
    COALESCE(latest_aena.stand, latest_other.stand) AS stand,
    CASE WHEN latest_aena.id IS NOT NULL THEN latest_aena.baggage_state ELSE latest_other.baggage_state END AS baggage_state,
    COALESCE(latest_aena.occupancy_level, latest_other.occupancy_level, 'no_verificable') AS occupancy_level,
    latest_secondary_belt.source AS secondary_belt_source,
    latest_secondary_belt.observed_at AS secondary_belt_at,
    latest_secondary_belt.hall AS secondary_hall,
    latest_secondary_belt.belt AS secondary_belt,
    aena_state.last_seen_at AS authoritative_seen_at,
    CONCAT_WS(' / ', f.physical_flight, NULLIF(codes.codes, '')) AS codes,
    CASE WHEN latest_aena.id IS NOT NULL
         THEN COALESCE(changes.aena_belt_changes, 0)
         ELSE COALESCE(changes.other_belt_changes, 0)
    END AS belt_changes,
    CASE WHEN latest_aena.id IS NOT NULL
         THEN first_aena_belt.observed_at ELSE first_other_belt.observed_at
    END AS first_belt_at,
    CASE WHEN latest_aena.id IS NOT NULL
         THEN first_aena_belt.belt ELSE first_other_belt.belt
    END AS first_belt,
    CASE WHEN latest_aena.id IS NOT NULL
         THEN first_aena_belt.hall ELSE first_other_belt.hall
    END AS first_hall,
    TIMESTAMPDIFF(
        MINUTE,
        CASE WHEN latest_aena.id IS NOT NULL
             THEN first_aena_belt.observed_at ELSE first_other_belt.observed_at
        END,
        f.scheduled_arrival
    ) AS first_belt_lead_minutes
FROM flights f
LEFT JOIN observations latest_aena ON latest_aena.id = (
    SELECT o.id FROM observations o
    WHERE o.flight_id = f.id AND o.source = 'aena'
    ORDER BY o.observed_at DESC, o.id DESC
    LIMIT 1
)
LEFT JOIN observations latest_other ON latest_other.id = (
    SELECT o.id FROM observations o
    WHERE o.flight_id = f.id AND o.source <> 'aena'
    ORDER BY o.observed_at DESC, o.id DESC
    LIMIT 1
)
LEFT JOIN observations latest_secondary_belt ON latest_secondary_belt.id = (
    SELECT o.id FROM observations o
    WHERE o.flight_id = f.id AND o.source <> 'aena'
      AND o.belt IS NOT NULL AND o.belt <> ''
    ORDER BY o.observed_at DESC, o.id DESC
    LIMIT 1
)
LEFT JOIN (
    SELECT flight_id, MAX(last_seen_at) AS last_seen_at
    FROM flight_source_state
    WHERE provider = 'aena'
    GROUP BY flight_id
) aena_state ON aena_state.flight_id = f.id
LEFT JOIN observations first_aena_belt ON first_aena_belt.id = (
    SELECT o.id FROM observations o
    WHERE o.flight_id = f.id AND o.source = 'aena'
      AND o.belt IS NOT NULL AND o.belt <> ''
    ORDER BY o.observed_at, o.id
    LIMIT 1
)
LEFT JOIN observations first_other_belt ON first_other_belt.id = (
    SELECT o.id FROM observations o
    WHERE o.flight_id = f.id AND o.source <> 'aena'
      AND o.belt IS NOT NULL AND o.belt <> ''
    ORDER BY o.observed_at, o.id
    LIMIT 1
)
LEFT JOIN (
    SELECT flight_id, GROUP_CONCAT(flight_code ORDER BY flight_code SEPARATOR ' / ') AS codes
    FROM flight_codes GROUP BY flight_id
) codes ON codes.flight_id = f.id
LEFT JOIN (
    SELECT flight_id,
           SUM(CASE WHEN source = 'aena' AND event_type = 'belt_changed' THEN 1 ELSE 0 END) AS aena_belt_changes,
           SUM(CASE WHEN source <> 'aena' AND event_type = 'belt_changed' THEN 1 ELSE 0 END) AS other_belt_changes
    FROM flight_events
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
