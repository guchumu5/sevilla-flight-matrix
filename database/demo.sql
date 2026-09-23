INSERT INTO flights
(flight_date, physical_flight, origin_iata, origin_name, scheduled_arrival, aircraft_registration, aircraft_icao24, aircraft_type, capacity, traffic_class, is_canary)
VALUES
(CURRENT_DATE(), 'VLG3049', 'LPA', 'Gran Canaria', CONCAT(CURRENT_DATE(), ' 10:00:00'), 'EC-NCF', '345314', 'Airbus A320neo', 186, 'domestico', 1),
(CURRENT_DATE(), 'EJU7612', 'SZG', 'Salzburgo', CONCAT(CURRENT_DATE(), ' 10:25:00'), NULL, NULL, 'Airbus A320', 180, 'schengen', 0),
(CURRENT_DATE(), 'NGN7951', 'HAJ', 'Hannover', CONCAT(CURRENT_DATE(), ' 10:30:00'), NULL, NULL, 'Airbus A320', 180, 'schengen', 0),
(CURRENT_DATE(), 'VLG2224', 'BCN', 'Barcelona', CONCAT(CURRENT_DATE(), ' 11:50:00'), NULL, NULL, 'Airbus A320', 180, 'domestico', 0)
ON DUPLICATE KEY UPDATE origin_name = VALUES(origin_name);

INSERT IGNORE INTO flight_codes (flight_id, flight_code, airline_name)
SELECT id, 'IBE5354', 'Iberia' FROM flights WHERE physical_flight='VLG3049' AND flight_date=CURRENT_DATE();

INSERT INTO observations (flight_id, source, observed_at, status, eta, hall, belt, baggage_state)
SELECT id, 'aena', NOW() - INTERVAL 70 MINUTE, 'En vuelo', CONCAT(CURRENT_DATE(), ' 09:50:00'), 'A', '1', 'pendiente'
FROM flights WHERE physical_flight='VLG3049' AND flight_date=CURRENT_DATE();

INSERT INTO observations (flight_id, source, observed_at, status, eta, actual_arrival, hall, belt, baggage_state)
SELECT id, 'aena', NOW() - INTERVAL 20 MINUTE, 'Entrega de equipaje', CONCAT(CURRENT_DATE(), ' 09:50:00'), CONCAT(CURRENT_DATE(), ' 09:50:00'), 'A', '2', 'entrega'
FROM flights WHERE physical_flight='VLG3049' AND flight_date=CURRENT_DATE();

INSERT INTO observations (flight_id, source, observed_at, status, eta, hall, belt, baggage_state)
SELECT id, 'aena', NOW() - INTERVAL 10 MINUTE, 'Programado', CONCAT(CURRENT_DATE(), ' 10:17:00'), 'A', '2', 'pendiente'
FROM flights WHERE physical_flight='NGN7951' AND flight_date=CURRENT_DATE();

INSERT INTO observations (flight_id, source, observed_at, status, eta, hall, belt, baggage_state)
SELECT id, 'aena', NOW() - INTERVAL 5 MINUTE, 'Programado', CONCAT(CURRENT_DATE(), ' 10:25:00'), 'A', '1', 'pendiente'
FROM flights WHERE physical_flight='EJU7612' AND flight_date=CURRENT_DATE();
