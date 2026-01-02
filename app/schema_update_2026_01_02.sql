-- Jalankan ini jika database sudah terlanjur dibuat dari schema lama.
-- Aman dijalankan berulang (pakai cek information_schema).

-- 1) Tambah kolom user_id ke transactions (idempotent)
SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND COLUMN_NAME = 'user_id'
);

SET @idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND INDEX_NAME = 'idx_user_id'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE transactions ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql2 := IF(
  @idx_exists = 0,
  'ALTER TABLE transactions ADD KEY idx_user_id (user_id)',
  'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 2) Buat tabel users
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_email (email),
  UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
