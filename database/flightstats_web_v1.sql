ALTER TABLE observations
  MODIFY source ENUM('aena','airlabs','opensky','manual','aviationweather','flightstats') NOT NULL;

CREATE TABLE IF NOT EXISTS flightstats_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT UNSIGNED NOT NULL,
  observed_at DATETIME NOT NULL,
  public_url VARCHAR(300) NOT NULL,
  iata_carrier CHAR(2) NULL,
  iata_number VARCHAR(8) NULL,
  status_text VARCHAR(160) NULL,
  delay_minutes SMALLINT NULL,
  eta DATETIME NULL,
  actual_departure DATETIME NULL,
  actual_arrival DATETIME NULL,
  departure_text VARCHAR(80) NULL,
  arrival_text VARCHAR(80) NULL,
  phase VARCHAR(40) NULL,
  raw_excerpt TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_flightstats_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  KEY idx_flightstats_latest (flight_id, observed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS flightstats_requests (
  flight_id BIGINT UNSIGNED PRIMARY KEY,
  requested_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('queued','processed','failed') NOT NULL DEFAULT 'queued',
  last_error VARCHAR(500) NULL,
  CONSTRAINT fk_flightstats_request_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  KEY idx_flightstats_request_queue (status, requested_at)
) ENGINE=InnoDB;
