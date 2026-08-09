CREATE TABLE IF NOT EXISTS online_registrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL UNIQUE,
  registration_code VARCHAR(20) NOT NULL UNIQUE,
  service_id BIGINT UNSIGNED NOT NULL,
  ticket_id BIGINT UNSIGNED NULL UNIQUE,
  visitor_name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  identity_number VARCHAR(80) NULL,
  notes TEXT NULL,
  visit_date DATE NOT NULL,
  status ENUM('registered','checking_in','checked_in','cancelled','expired') NOT NULL DEFAULT 'registered',
  source VARCHAR(50) NOT NULL DEFAULT 'native_form',
  consent_at DATETIME NOT NULL,
  checked_in_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_registration_visit(service_id, visit_date, status),
  INDEX idx_registration_phone(phone),
  FOREIGN KEY(service_id) REFERENCES services(id),
  FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,
  allowed_origin VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_rate_limits (
  client_key VARCHAR(128) NOT NULL,
  window_start DATETIME NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY(client_key, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
