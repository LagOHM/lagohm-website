-- LagOHM booking system schema
-- Run once against the database created in Netcup WCP (see SETUP.md).

CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(32) NOT NULL,
  name VARCHAR(100) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  price_cents INT UNSIGNED NOT NULL,
  buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_services_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS availability_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  weekday TINYINT UNSIGNED NOT NULL, -- 0=Sunday .. 6=Saturday
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_availability_weekday (weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id INT UNSIGNED NOT NULL,
  customer_name VARCHAR(150) NOT NULL,
  customer_email VARCHAR(190) NOT NULL,
  customer_phone VARCHAR(40) NULL,
  customer_note TEXT NULL,
  language CHAR(2) NOT NULL DEFAULT 'de',
  start_datetime DATETIME NOT NULL, -- stored in UTC
  end_datetime DATETIME NOT NULL,   -- stored in UTC
  status ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  google_event_id VARCHAR(255) NULL,
  reminder_sent_at DATETIME NULL,
  cancellation_token CHAR(64) NOT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_cancellation_token (cancellation_token),
  KEY idx_bookings_window (start_datetime, end_datetime, status),
  KEY idx_bookings_service (service_id),
  CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oauth_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_label VARCHAR(50) NOT NULL,
  refresh_token_encrypted TEXT NULL,
  access_token TEXT NULL,
  access_token_expires_at DATETIME NULL,
  scope VARCHAR(255) NULL,
  calendar_id_primary VARCHAR(255) NULL,
  calendar_id_bookings VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oauth_tokens_label (account_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(64) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_audit_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL, -- created|cancelled|calendar_sync_failed|reminder_sent
  detail VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed data

INSERT INTO services (slug, name, duration_minutes, price_cents, buffer_minutes, active)
VALUES
  ('yoga', 'Yoga', 75, 6000, 30, 1),
  ('massage', 'Massage', 90, 12000, 30, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Mo-Sa 09:00-19:00, Sunday closed (weekday 1=Mon .. 6=Sat per PHP's date('N') convention, see Availability.php)
INSERT INTO availability_templates (weekday, start_time, end_time, active)
VALUES
  (1, '09:00:00', '19:00:00', 1),
  (2, '09:00:00', '19:00:00', 1),
  (3, '09:00:00', '19:00:00', 1),
  (4, '09:00:00', '19:00:00', 1),
  (5, '09:00:00', '19:00:00', 1),
  (6, '09:00:00', '19:00:00', 1);

INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('slot_granularity_minutes', '15'),
  ('min_lead_hours', '3'),
  ('max_horizon_days', '60'),
  ('reminder_hours_before', '24')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
