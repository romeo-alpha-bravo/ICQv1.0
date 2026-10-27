-- Migration: messenger features (statuses, profile info, contacts, chat) for a database
-- created before them. A fresh database made from schema.sql already has everything.
-- Safe to run more than once (MariaDB: ADD COLUMN IF NOT EXISTS). Usage with Docker: make migrate

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS status      ENUM('online','away','na','dnd','invisible') NOT NULL DEFAULT 'online' AFTER role,
  ADD COLUMN IF NOT EXISTS status_text VARCHAR(100) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS city        VARCHAR(100) NULL AFTER status_text,
  ADD COLUMN IF NOT EXISTS about       VARCHAR(500) NULL AFTER city,
  ADD COLUMN IF NOT EXISTS last_seen   DATETIME     NULL AFTER last_activity,
  ADD COLUMN IF NOT EXISTS typing_to   INT          NULL AFTER last_seen,
  ADD COLUMN IF NOT EXISTS typing_at   DATETIME     NULL AFTER typing_to;

-- Contact list with authorization. Row (A, B, 'pending') = A asked B for authorization.
-- When B accepts, the row becomes 'accepted' and the mirror row (B, A) is added.
-- Only accepted contacts can chat and see each other's status.
CREATE TABLE IF NOT EXISTS contacts (
  owner_id   INT          NOT NULL,
  contact_id INT          NOT NULL,
  state      ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
  group_name VARCHAR(40)  NOT NULL DEFAULT 'General',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (owner_id, contact_id),
  INDEX idx_incoming (contact_id, state),
  FOREIGN KEY (owner_id)   REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instant messages (chat). The body is stored encrypted, like mail.
-- delivered_at: the recipient's app received it; read_at: the recipient opened the chat.
CREATE TABLE IF NOT EXISTS chat_messages (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  sender_id    INT      NOT NULL,
  recipient_id INT      NOT NULL,
  body_enc     BLOB     NOT NULL,
  sent_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  read_at      DATETIME NULL,
  FOREIGN KEY (sender_id)    REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_pair (sender_id, recipient_id, id),
  INDEX idx_unread (recipient_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
