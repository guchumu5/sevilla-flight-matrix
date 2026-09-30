CREATE TABLE IF NOT EXISTS belt_change_analysis_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  analysis_date DATE NOT NULL,
  model_version VARCHAR(50) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  changes_found INT UNSIGNED NOT NULL DEFAULT 0,
  analyses_created INT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(1000) NULL,
  UNIQUE KEY uq_belt_analysis_run (analysis_date, model_version),
  KEY idx_belt_analysis_run_status (status, analysis_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS belt_change_analyses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_event_id BIGINT UNSIGNED NOT NULL,
  flight_id BIGINT UNSIGNED NOT NULL,
  analysis_date DATE NOT NULL,
  analyzed_at DATETIME NOT NULL,
  model_version VARCHAR(50) NOT NULL,
  reason_code VARCHAR(80) NOT NULL,
  reason_label VARCHAR(180) NOT NULL,
  reason_detail VARCHAR(1000) NOT NULL,
  confidence ENUM('confirmado','probable','provisional') NOT NULL DEFAULT 'provisional',
  official_reason TINYINT(1) NOT NULL DEFAULT 0,
  evidence JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_belt_analysis_event FOREIGN KEY (flight_event_id) REFERENCES flight_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_belt_analysis_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  UNIQUE KEY uq_belt_analysis_version (flight_event_id, model_version),
  KEY idx_belt_analysis_date (analysis_date, analyzed_at),
  KEY idx_belt_analysis_flight (flight_id, analyzed_at),
  KEY idx_belt_analysis_reason (reason_code, confidence)
) ENGINE=InnoDB;
