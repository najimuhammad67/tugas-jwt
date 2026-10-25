<?php
// =====================================================================
// latihan/jwt_demo.php  --  Belajar JWT tanpa server, langsung di terminal
//
// Jalankan:   php latihan/jwt_demo.php
// Tujuan: melihat sendiri isi token, dan membuktikan bahwa token yang
// diubah / kedaluwarsa akan DITOLAK.
// =====================================================================

require __DIR__ . '/../server/jwt.php';

$rahasia = 'kunci-rahasia-untuk-latihan';

// ---------------------------------------------------------------------
echo "1) MEMBUAT TOKEN\n";
$token = buat_token(['sub' => 1, 'username' => 'budi', 'role' => 'user'], $rahasia, 3600);
echo "   $token\n\n";

// ---------------------------------------------------------------------
echo "2) ISI TOKEN BISA DIBACA SIAPA SAJA (hanya di-encode, tidak dienkripsi)\n";
list($h, $p, $t) = explode('.', $token);
echo "   header  : " . base64url_decode($h) . "\n";
echo "   payload : " . base64url_decode($p) . "\n";
echo "   tanda tangan (tidak bisa dibaca): $t\n\n";

// ---------------------------------------------------------------------
echo "3) MEMERIKSA TOKEN YANG ASLI\n";
$hasil = cek_token($token, $rahasia);
echo "   " . ($hasil ? "SAH, username = {$hasil['username']}, role = {$hasil['role']}" : "DITOLAK") . "\n\n";

// ---------------------------------------------------------------------
echo "4) PENYERANG MENGUBAH ROLE MENJADI ADMIN (tanpa tahu kunci rahasia)\n";
$payloadPalsu = base64url_encode(json_encode(['sub' => 1, 'username' => 'budi', 'role' => 'admin', 'exp' => time() + 3600]));
$tokenPalsu   = "$h.$payloadPalsu.$t";               // tanda tangan lama dipakai ulang
$hasil = cek_token($tokenPalsu, $rahasia);
echo "   " . ($hasil ? "SAH (BERBAHAYA!)" : "DITOLAK -> tanda tangan tidak cocok dengan isi yang baru") . "\n\n";

// ---------------------------------------------------------------------
echo "5) TOKEN DENGAN KUNCI RAHASIA YANG BERBEDA\n";
$hasil = cek_token($token, 'kunci-lain');
echo "   " . ($hasil ? "SAH (BERBAHAYA!)" : "DITOLAK") . "\n\n";

// ---------------------------------------------------------------------
echo "6) TOKEN KEDALUWARSA (masa berlaku -10 detik)\n";
$kadaluwarsa = buat_token(['sub' => 1, 'username' => 'budi', 'role' => 'user'], $rahasia, -10);
$hasil = cek_token($kadaluwarsa, $rahasia);
echo "   " . ($hasil ? "SAH (BERBAHAYA!)" : "DITOLAK -> sudah lewat waktu exp") . "\n\n";

// ---------------------------------------------------------------------
echo "7) HASH PASSWORD\n";
$hash = password_hash('budi123', PASSWORD_DEFAULT);
echo "   hash        : $hash\n";
echo "   cocok 'budi123' ? " . (password_verify('budi123', $hash) ? 'ya' : 'tidak') . "\n";
echo "   cocok 'salah'   ? " . (password_verify('salah', $hash) ? 'ya' : 'tidak') . "\n";
echo "   (hash yang sama TIDAK bisa dikembalikan menjadi password asli)\n";
