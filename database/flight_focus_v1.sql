CREATE TABLE IF NOT EXISTS flight_focus_requests (
  flight_id BIGINT UNSIGNED PRIMARY KEY,
  requested_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  last_opensky_at DATETIME NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('active','completed','failed') NOT NULL DEFAULT 'active',
  last_result VARCHAR(500) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_flight_focus_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  KEY idx_flight_focus_active (status, expires_at)
) ENGINE=InnoDB;
