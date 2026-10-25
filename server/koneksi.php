<?php
// =====================================================================
// koneksi.php  --  Pengaturan + koneksi database
// File ini di-include oleh index.php. Hasilnya: variabel $db siap dipakai,
// ditambah beberapa PENGATURAN KEAMANAN (di bagian tengah).
// =====================================================================

// ---------------- Pengaturan database ----------------
// 127.0.0.1 memaksa koneksi lewat TCP. 'localhost' bisa memakai socket Unix
// yang path-nya belum tentu sama dengan konfigurasi MySQL di komputer ini.
$host    = '127.0.0.1';
$user    = 'root';        // XAMPP: root
$pass    = '';            // XAMPP: kosong
$nama_db = 'kampus_db';

// ---------------- Pengaturan keamanan (BARU di Pertemuan 7) ----------------

// Kunci rahasia untuk "menandatangani" token JWT.
// Siapa pun yang tahu kunci ini bisa membuat token palsu, jadi:
//   - pilih teks panjang dan acak,
//   - di aplikasi sungguhan simpan di environment variable, jangan di kode.
$jwt_secret = 'ganti-dengan-teks-rahasia-yang-panjang-dan-acak-123';

// Token berlaku berapa detik? 3600 = 1 jam.
$jwt_berlaku = 3600;

// RATE LIMITING (pembatasan jumlah request):
$batas_login = 5;     // maksimal 5x salah login per username per menit
$batas_umum  = 120;   // maksimal 120 request per IP per menit

try {
    $db = new PDO("mysql:host=$host;dbname=$nama_db;charset=utf8mb4", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'pesan' => 'Tidak bisa terhubung ke database']);
    exit;
}
