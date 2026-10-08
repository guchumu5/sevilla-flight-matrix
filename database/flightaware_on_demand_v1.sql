CREATE TABLE IF NOT EXISTS flightaware_requests (
  flight_id BIGINT UNSIGNED PRIMARY KEY,
  requested_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('queued','processed','failed') NOT NULL DEFAULT 'queued',
  last_error VARCHAR(500) NULL,
  CONSTRAINT fk_flightaware_request_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  KEY idx_flightaware_request_queue (status, requested_at)
) ENGINE=InnoDB;
