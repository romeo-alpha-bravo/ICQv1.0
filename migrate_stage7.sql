-- Stage 7 migration for a database created before this stage.
-- A fresh database made from schema.sql already contains this table.
-- Usage: docker exec -i uin-mariadb mariadb -u root -p uin_mail < migrate_stage7.sql

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
