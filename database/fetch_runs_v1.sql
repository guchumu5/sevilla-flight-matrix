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
