ALTER TABLE user_notification_settings
  ADD COLUMN IF NOT EXISTS play_mode ENUM('auto','persistent') NOT NULL DEFAULT 'auto' AFTER volume;
