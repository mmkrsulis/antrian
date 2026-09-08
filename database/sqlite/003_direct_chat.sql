ALTER TABLE chat_messages ADD COLUMN recipient_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_chat_direct ON chat_messages(recipient_user_id,user_id,id);
CREATE TABLE IF NOT EXISTS chat_conversation_reads (user_id INTEGER NOT NULL,peer_user_id INTEGER NOT NULL DEFAULT 0,last_read_message_id INTEGER NOT NULL DEFAULT 0,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,peer_user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS chat_presence (user_id INTEGER PRIMARY KEY,last_seen_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
