-- Migration: password reset table for a database created before this feature.
-- A fresh database made from schema.sql already contains it.
-- Usage: docker exec -i uin-mariadb mariadb -u root -prootpass uin_mail < migrate_password_reset.sql
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
