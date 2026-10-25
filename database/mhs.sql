-- =====================================================================
-- database/mhs.sql  --  Database API Mahasiswa + AUTENTIKASI (Pertemuan 7)
-- Cara pakai: phpMyAdmin -> tab Import -> pilih file ini -> Go
-- (File ini menghapus & membuat ulang tabel, jadi data lama akan hilang.)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS kampus_db CHARACTER SET utf8mb4;
USE kampus_db;

-- ---------------------------------------------------------------------
-- TABEL 1: mhs  -> data mahasiswa
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS mhs;
CREATE TABLE mhs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nim        VARCHAR(12)  NOT NULL UNIQUE,
  name       VARCHAR(100) NOT NULL,
  major      VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- TABEL 2: users  -> akun yang boleh login (BARU di Pertemuan 7)
--   password disimpan dalam bentuk HASH, BUKAN teks asli.
--   role menentukan hak akses (otorisasi): 'admin', 'dosen', atau 'user'.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  username  VARCHAR(50)  NOT NULL UNIQUE,
  password  VARCHAR(255) NOT NULL,                  -- hasil password_hash()
  role      VARCHAR(10)  NOT NULL DEFAULT 'user'    -- 'admin', 'dosen', atau 'user'
);

-- ---------------------------------------------------------------------
-- TABEL 3: log_api  -> catatan setiap request (kolom username BARU)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS log_api;
CREATE TABLE log_api (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  waktu    DATETIME     NOT NULL,
  method   VARCHAR(10)  NOT NULL,
  path     VARCHAR(255) NOT NULL,
  status   INT          NOT NULL,
  ip       VARCHAR(45)  NOT NULL,
  username VARCHAR(50)  NULL,                       -- siapa yang mengakses (jika login)
  pesan    VARCHAR(255) NULL
);

-- ---------------------------------------------------------------------
-- Data contoh
-- Akun latihan:   admin / admin123  (role admin)
--                 dosen / dosen123  (role dosen: boleh mengubah, tidak boleh menghapus)
--                 budi  / budi123   (role user)
-- Hanya untuk praktikum! Di aplikasi sungguhan gunakan password yang kuat.
-- ---------------------------------------------------------------------
INSERT INTO users (username, password, role) VALUES
  ('admin', '$2y$10$pWQGUDarcWM74IF4ZYNR5u3kAqKJWaPrMjc1rXXq4w6/bsSZ7kzDK', 'admin'),
  ('dosen', '$2y$12$aNJxm46IvkO/nvEjcvwqxevPKEgE86GNW3rlYkHdVSp2.rcHLB2/2', 'dosen'),
  ('budi',  '$2y$10$Zw8rAKVFTx5wmo5Pzg5nkuEg667G.E70mo.ep5e1WR8i67B8XLiLy', 'user');

INSERT INTO mhs (nim, name, major) VALUES
  ('22670001', 'Andi Pratama',   'Informatika'),
  ('22670002', 'Budi Santoso',   'Informatika'),
  ('22670003', 'Citra Lestari',  'Sistem Informasi');
