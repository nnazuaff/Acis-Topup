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

-- 1b) Tambah kolom operator_id ke transactions (idempotent)
SET @col_op_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND COLUMN_NAME = 'operator_id'
);

SET @sql3 := IF(
  @col_op_exists = 0,
  'ALTER TABLE transactions ADD COLUMN operator_id VARCHAR(32) NULL AFTER trx_id',
  'SELECT 1'
);
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- 1c) Tambah kolom wallet_debited ke transactions (idempotent)
SET @col_wallet_debited_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND COLUMN_NAME = 'wallet_debited'
);

SET @sql5 := IF(
  @col_wallet_debited_exists = 0,
  'ALTER TABLE transactions ADD COLUMN wallet_debited INT NOT NULL DEFAULT 0 AFTER price',
  'SELECT 1'
);
PREPARE stmt5 FROM @sql5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- 1d) Tambah kolom wallet_refunded ke transactions (idempotent)
SET @col_wallet_refunded_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transactions'
    AND COLUMN_NAME = 'wallet_refunded'
);

SET @sql6 := IF(
  @col_wallet_refunded_exists = 0,
  'ALTER TABLE transactions ADD COLUMN wallet_refunded INT NOT NULL DEFAULT 0 AFTER wallet_debited',
  'SELECT 1'
);
PREPARE stmt6 FROM @sql6;
EXECUTE stmt6;
DEALLOCATE PREPARE stmt6;

-- 2) Buat tabel users
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  balance INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_email (email),
  UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2b) Tambah kolom balance ke users jika tabel sudah ada (idempotent)
SET @users_balance_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'balance'
);

SET @sql4 := IF(
  @users_balance_exists = 0,
  'ALTER TABLE users ADD COLUMN balance INT NOT NULL DEFAULT 0 AFTER password_hash',
  'SELECT 1'
);
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;
