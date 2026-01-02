-- Jalankan ini jika database sudah terlanjur dibuat dari schema lama.
-- Aman dijalankan berulang (pakai IF NOT EXISTS / cek error duplikat).

-- 1) Tambah kolom user_id ke transactions
ALTER TABLE transactions
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_user_id (user_id);

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
