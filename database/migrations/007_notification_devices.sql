CREATE TABLE IF NOT EXISTS notification_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id VARCHAR(100) NOT NULL,
  device_name VARCHAR(150) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  last_used_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_device(user_id, device_id),
  INDEX idx_notification_device_expiry(expires_at),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
