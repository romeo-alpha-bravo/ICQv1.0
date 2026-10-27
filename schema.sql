-- ICQ1.0: database schema
-- Usage: mysql -u <admin_user> -p < schema.sql

CREATE DATABASE IF NOT EXISTS uin_mail
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE uin_mail;

-- Users. email/phone are stored encrypted (AES-256-GCM, see crypto.php);
-- email_hash = HMAC-SHA256(email) is used only to enforce unique emails.
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  uin           INT UNSIGNED   NOT NULL UNIQUE,
  login         VARCHAR(50)    NOT NULL UNIQUE,
  password_hash VARCHAR(255)   NOT NULL,
  first_name    VARCHAR(100)   NOT NULL,
  last_name     VARCHAR(100)   NOT NULL,
  email_enc     VARBINARY(512) NOT NULL,
  email_hash    CHAR(64)       NOT NULL UNIQUE,
  phone_enc     VARBINARY(512) NOT NULL,
  gender        ENUM('m','f','other') NOT NULL,
  photo_path    VARCHAR(255)   NOT NULL,
  role          ENUM('user','admin')  NOT NULL DEFAULT 'user',
  -- messenger profile and presence (see includes/presence.php)
  status        ENUM('online','away','na','dnd','invisible') NOT NULL DEFAULT 'online',
  status_text   VARCHAR(100)   NULL,
  city          VARCHAR(100)   NULL,
  about         VARCHAR(500)   NULL,
  last_activity DATETIME       NULL,   -- last real action (page view, sent message)
  last_seen     DATETIME       NULL,   -- last request of any kind, also background polling
  typing_to     INT            NULL,   -- "is typing..." indicator: to whom and when
  typing_at     DATETIME       NULL,
  created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages. Subject and body are stored encrypted.
-- Inbox: recipient_id = ?  |  Sent: sender_id = ?  |  Unread: read_at IS NULL
CREATE TABLE IF NOT EXISTS messages (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  sender_id    INT            NOT NULL,
  recipient_id INT            NOT NULL,
  subject_enc  VARBINARY(1024) NOT NULL,
  body_enc     MEDIUMBLOB     NOT NULL,
  sent_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at      DATETIME       NULL,
  FOREIGN KEY (sender_id)    REFERENCES users(id),
  FOREIGN KEY (recipient_id) REFERENCES users(id),
  INDEX idx_recipient_unread (recipient_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed login attempts, counted per login name and per IP (see includes/throttle.php).
-- Rows older than the throttle window are deleted by the app itself.
CREATE TABLE IF NOT EXISTS login_attempts (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  login    VARCHAR(50)    NOT NULL,
  ip       VARBINARY(16)  NOT NULL,
  tried_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_time (login, tried_at),
  INDEX idx_ip_time (ip, tried_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset requests. Only a SHA-256 hash of the e-mailed token is stored, so a
-- leaked database does not give usable links. Rows with user_id NULL are requests for
-- unknown e-mails: they exist only to count attempts per IP (no way to probe accounts).
CREATE TABLE IF NOT EXISTS password_resets (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT            NULL,
  token_hash CHAR(64)       NULL UNIQUE,
  ip         VARBINARY(16)  NOT NULL,
  created_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME       NOT NULL,
  used_at    DATETIME       NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ip_time   (ip, created_at),
  INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Default admin (LOCKED placeholder).
-- Encrypted fields need the app key, which must not live in SQL, so this row
-- cannot be complete yet. password_hash '!' is not a valid hash, so
-- password_verify() always fails: nobody can log in as admin until the
-- installer script sets the real password, email_enc, email_hash, phone_enc.
INSERT INTO users
  (uin, login, password_hash, first_name, last_name,
   email_enc, email_hash, phone_enc, gender, photo_path, role)
VALUES
  (10000, 'admin', '!', 'Default', 'Admin',
   '', REPEAT('0', 64), '', 'other', 'default.jpg', 'admin')
ON DUPLICATE KEY UPDATE id = id;
