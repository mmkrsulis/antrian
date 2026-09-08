ALTER TABLE chat_messages
    ADD COLUMN recipient_user_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD INDEX idx_chat_direct (recipient_user_id, user_id, id),
    ADD CONSTRAINT fk_chat_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS chat_conversation_reads (
    user_id BIGINT UNSIGNED NOT NULL,
    peer_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, peer_user_id),
    CONSTRAINT fk_chat_conversation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_presence (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    last_seen_at DATETIME NOT NULL,
    CONSTRAINT fk_chat_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
