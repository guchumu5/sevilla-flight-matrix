CREATE TABLE IF NOT EXISTS push_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_token CHAR(64) NOT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(180) NOT NULL,
  auth_secret VARCHAR(100) NOT NULL,
  watch_canary_all TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  user_agent VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_push_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  UNIQUE KEY uq_push_device (device_token),
  UNIQUE KEY uq_push_endpoint (endpoint_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS flight_watches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id BIGINT UNSIGNED NOT NULL,
  flight_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_watch_subscription FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
  CONSTRAINT fk_watch_flight FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
  UNIQUE KEY uq_watch (subscription_id, flight_id),
  KEY idx_watch_flight (flight_id, enabled)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS push_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id BIGINT UNSIGNED NOT NULL,
  flight_event_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  body VARCHAR(500) NOT NULL,
  target_url VARCHAR(300) NOT NULL DEFAULT 'index.php',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pushed_at DATETIME NULL,
  displayed_at DATETIME NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  CONSTRAINT fk_push_outbox_subscription FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
  CONSTRAINT fk_push_outbox_event FOREIGN KEY (flight_event_id) REFERENCES flight_events(id) ON DELETE CASCADE,
  UNIQUE KEY uq_push_event (subscription_id, flight_event_id),
  KEY idx_push_pending (pushed_at, created_at)
) ENGINE=InnoDB;
