ALTER TABLE observations
  MODIFY source ENUM('aena','airlabs','opensky','manual','aviationweather','flightstats','aeroapi') NOT NULL;

CREATE TABLE IF NOT EXISTS aeroapi_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT UNSIGNED NOT NULL,
  observed_at DATETIME NOT NULL,
  public_url VARCHAR(300) NOT NULL,
  fa_flight_id VARCHAR(40) NULL,
  ident_iata VARCHAR(12) NULL,
  ident_icao VARCHAR(12) NULL,
  status_text VARCHAR(160) NULL,
  delay_minutes SMALLINT NULL,
  eta DATETIME NULL,
  actual_departure DATETIME NULL,
  actual_arrival DATETIME NULL,
  gate VARCHAR(20) NULL,
  belt VARCHAR(20) NULL,
  registration VARCHAR(15) NULL,
  aircraft_type VARCHAR(20) NULL,
  phase VARCHAR(40) NULL,
  progress_percent SMALLINT NULL,
  raw_excerpt TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_aeroapi_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  KEY idx_aeroapi_latest (flight_id, observed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS aeroapi_requests (
  flight_id BIGINT UNSIGNED PRIMARY KEY,
  requested_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('queued','processed','failed','error') NOT NULL DEFAULT 'queued',
  last_error VARCHAR(500) NULL,
  CONSTRAINT fk_aeroapi_request_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  KEY idx_aeroapi_request_queue (status, requested_at)
) ENGINE=InnoDB;

-- Contador mensual de result sets AeroAPI (mes calendario Europe/Madrid).
-- Tope duro por defecto 2300 (= 2500 del plan con margen 200).
CREATE TABLE IF NOT EXISTS aeroapi_usage (
  month_key CHAR(7) NOT NULL PRIMARY KEY,
  result_sets INT UNSIGNED NOT NULL DEFAULT 0,
  http_calls INT UNSIGNED NOT NULL DEFAULT 0,
  last_call_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
