-- =====================================================================
-- migrasi_p7_tugas.sql  --  Tambahan untuk Tugas Pertemuan 7
--
-- Pakai file ini jika database kampus_db SUDAH ADA (hasil impor mhs.sql
-- versi praktikum) dan Anda tidak ingin menghapus data lama.
-- Isinya hanya menambahkan akun dosen untuk tugas role baru.
--
-- Cara pakai: phpMyAdmin -> pilih kampus_db -> tab SQL -> tempel isi file
--             ini -> Go. (Atau impor file ini setelah memilih database.)
-- =====================================================================

USE kampus_db;

-- Role 'dosen' sudah muat di kolom role VARCHAR(10), jadi tidak perlu
-- mengubah struktur tabel. Akun dosen ditambahkan di sini.
-- Password: dosen123 (hash dibuat dengan password_hash()).
INSERT INTO users (username, password, role) VALUES
  ('dosen', '$2y$12$aNJxm46IvkO/nvEjcvwqxevPKEgE86GNW3rlYkHdVSp2.rcHLB2/2', 'dosen')
ON DUPLICATE KEY UPDATE
  password = VALUES(password),
  role     = 'dosen';
