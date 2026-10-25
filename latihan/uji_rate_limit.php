<?php
// =====================================================================
// latihan/uji_rate_limit.php  --  Membuktikan rate limiting umum bekerja
//
// Program ini "membanjiri" server dengan request cepat, lalu menghitung
// berapa yang diterima (200) dan berapa yang diblokir (429).
//
// Jalankan:   php latihan/uji_rate_limit.php http://localhost/api-mhs-auth/server
// Setelah selesai, tunggu 1 menit sebelum memakai API lagi.
// =====================================================================

$base  = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://localhost/api-mhs-auth/server';
$total = 150;                       // jumlah request yang dikirim
$hitung = [];                       // [status code => jumlah]

for ($i = 1; $i <= $total; $i++) {
    $ch = curl_init($base . '/');   // alamat "/" tidak butuh token
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!isset($hitung[$status])) $hitung[$status] = 0;
    $hitung[$status]++;
}

echo "Mengirim $total request cepat ke $base\n";
foreach ($hitung as $status => $jumlah) {
    echo "  status $status : $jumlah kali\n";
}
echo "Jika ada status 429, rate limiting bekerja.\n";
