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
