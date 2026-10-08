CREATE TABLE IF NOT EXISTS flightaware_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT UNSIGNED NOT NULL,
  observed_at DATETIME NOT NULL,
  public_url VARCHAR(300) NOT NULL,
  status_text VARCHAR(120) NULL,
  aircraft_type VARCHAR(120) NULL,
  altitude_ft INT NULL,
  speed_mph INT NULL,
  distance_mi INT NULL,
  duration_text VARCHAR(40) NULL,
  departure_text VARCHAR(80) NULL,
  arrival_text VARCHAR(80) NULL,
  raw_excerpt TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_flightaware_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  KEY idx_flightaware_latest (flight_id, observed_at)
) ENGINE=InnoDB;
