CREATE TABLE IF NOT EXISTS flights (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_date DATE NOT NULL,
  physical_flight VARCHAR(20) NOT NULL,
  origin_iata CHAR(3) NOT NULL,
  origin_name VARCHAR(100) NOT NULL,
  destination_iata CHAR(3) NOT NULL DEFAULT 'SVQ',
  scheduled_arrival DATETIME NOT NULL,
  scheduled_departure DATETIME NULL,
  aircraft_registration VARCHAR(15) NULL,
  aircraft_icao24 CHAR(6) NULL,
  aircraft_type VARCHAR(40) NULL,
  capacity SMALLINT UNSIGNED NULL,
  traffic_class ENUM('domestico','schengen','no_schengen','desconocido') NOT NULL DEFAULT 'desconocido',
  border_control TINYINT(1) NOT NULL DEFAULT 0,
  is_canary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_physical_flight (flight_date, physical_flight, origin_iata, scheduled_arrival),
  KEY idx_arrival (scheduled_arrival),
  KEY idx_canary (is_canary, scheduled_arrival)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS flight_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT UNSIGNED NOT NULL,
  flight_code VARCHAR(20) NOT NULL,
  airline_name VARCHAR(100) NULL,
  UNIQUE KEY uq_code (flight_id, flight_code),
  CONSTRAINT fk_codes_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS observations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT UNSIGNED NOT NULL,
  source ENUM('aena','airlabs','opensky','manual','aviationweather') NOT NULL,
  observed_at DATETIME NOT NULL,
  status VARCHAR(50) NULL,
  eta DATETIME NULL,
  actual_departure DATETIME NULL,
  actual_arrival DATETIME NULL,
  hall VARCHAR(10) NULL,
  belt VARCHAR(10) NULL,
  gate VARCHAR(15) NULL,
  stand VARCHAR(20) NULL,
  baggage_state ENUM('pendiente','entrega','finalizado') NULL,
  latitude DECIMAL(9,6) NULL,
  longitude DECIMAL(9,6) NULL,
  altitude_m INT NULL,
  ground_speed_ms DECIMAL(8,2) NULL,
  track_deg DECIMAL(6,2) NULL,
  vertical_rate_ms DECIMAL(7,2) NULL,
  occupancy_level ENUM('no_verificable','baja','media','alta') NOT NULL DEFAULT 'no_verificable',
  raw_data JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_observations_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  KEY idx_observed (flight_id, observed_at),
  KEY idx_source (source, observed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS predictions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT UNSIGNED NOT NULL,
  predicted_at DATETIME NOT NULL,
  predicted_hall VARCHAR(10) NULL,
  predicted_belt VARCHAR(10) NULL,
  score SMALLINT NOT NULL,
  confidence ENUM('baja','media','alta') NOT NULL,
  features JSON NOT NULL,
  official_assignment_seen TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_predictions_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  KEY idx_prediction (flight_id, predicted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT UNSIGNED NOT NULL,
  alert_key VARCHAR(120) NOT NULL,
  alert_type VARCHAR(40) NOT NULL,
  severity ENUM('info','verde','naranja','rojo') NOT NULL,
  message VARCHAR(500) NOT NULL,
  acknowledged_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alerts_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  UNIQUE KEY uq_alert_key (alert_key),
  KEY idx_alert_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS weather_observations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  station CHAR(4) NOT NULL,
  observed_at DATETIME NOT NULL,
  wind_direction SMALLINT NULL,
  wind_speed_kt SMALLINT NULL,
  gust_kt SMALLINT NULL,
  raw_metar VARCHAR(500) NULL,
  raw_data JSON NULL,
  UNIQUE KEY uq_weather (station, observed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fetch_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(30) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  records_count INT NOT NULL DEFAULT 0,
  error_message VARCHAR(1000) NULL,
  KEY idx_fetch_provider (provider, started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  migration_id VARCHAR(120) NOT NULL,
  name VARCHAR(180) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL,
  execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
  statements_executed INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_schema_migration (migration_id)
) ENGINE=InnoDB;
