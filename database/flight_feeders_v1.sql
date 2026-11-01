CREATE TABLE IF NOT EXISTS flight_feeders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT UNSIGNED NOT NULL,
  observed_at DATETIME NOT NULL,
  feeder_code VARCHAR(12) NOT NULL,
  origin_iata CHAR(3) NOT NULL DEFAULT 'SVQ',
  destination_iata CHAR(3) NOT NULL,
  delay_minutes SMALLINT NULL,
  eta DATETIME NULL,
  actual_departure DATETIME NULL,
  actual_arrival DATETIME NULL,
  status_text VARCHAR(160) NULL,
  match_reason VARCHAR(40) NOT NULL DEFAULT 'paired_number',
  public_url VARCHAR(300) NULL,
  UNIQUE KEY uq_feeder_flight (flight_id),
  KEY idx_feeder_observed (observed_at),
  CONSTRAINT fk_feeder_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE
) ENGINE=InnoDB;
