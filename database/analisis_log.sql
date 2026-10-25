-- =====================================================================
-- analisis_log.sql  --  Tiga query analisis tabel log_api
-- Tugas Pertemuan 7, nomor 4.
--
-- Jalankan di phpMyAdmin (database kampus_db) atau:
--   mysql -u root kampus_db < database/analisis_log.sql
-- =====================================================================

USE kampus_db;

-- ---------------------------------------------------------------------
-- Query 1: jumlah login GAGAL per username
--   Dipakai untuk melihat akun mana yang sering dicoba ditebak
--   (indikasi brute force). Status 401 pada path /login = login gagal.
-- ---------------------------------------------------------------------
SELECT
  COALESCE(username, '(tanpa username)') AS username,
  COUNT(*)                               AS jumlah_gagal
FROM log_api
WHERE path LIKE '%/login'
  AND status = 401
GROUP BY username
ORDER BY jumlah_gagal DESC;

-- ---------------------------------------------------------------------
-- Query 2: 5 IP dengan jumlah request terbanyak
--   Dipakai untuk mendeteksi satu IP yang membanjiri API
--   (indikasi flooding / serangan otomatis).
-- ---------------------------------------------------------------------
SELECT
  ip,
  COUNT(*) AS jumlah_request
FROM log_api
GROUP BY ip
ORDER BY jumlah_request DESC
LIMIT 5;

-- ---------------------------------------------------------------------
-- Query 3: jumlah status 401, 403, dan 429 per hari
--   401 = belum/tidak terautentikasi
--   403 = sudah login tetapi tidak berhak (otorisasi)
--   429 = terlalu banyak request (rate limiting)
--   Berguna untuk melihat tren percobaan akses yang ditolak.
-- ---------------------------------------------------------------------
SELECT
  DATE(waktu) AS tanggal,
  SUM(CASE WHEN status = 401 THEN 1 ELSE 0 END) AS jumlah_401,
  SUM(CASE WHEN status = 403 THEN 1 ELSE 0 END) AS jumlah_403,
  SUM(CASE WHEN status = 429 THEN 1 ELSE 0 END) AS jumlah_429
FROM log_api
WHERE status IN (401, 403, 429)
GROUP BY DATE(waktu)
ORDER BY tanggal DESC;
