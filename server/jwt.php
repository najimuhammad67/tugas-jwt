<?php
// =====================================================================
// jwt.php  --  Membuat dan memeriksa JSON Web Token (JWT)
//
// JWT adalah teks yang terdiri dari 3 bagian dipisah titik:
//
//      HEADER . PAYLOAD . SIGNATURE
//
//   HEADER    = info algoritma           {"alg":"HS256","typ":"JWT"}
//   PAYLOAD   = isi data (klaim)         {"sub":1,"username":"admin","role":"admin","exp":...}
//   SIGNATURE = tanda tangan digital     HMAC-SHA256(header.payload, KUNCI RAHASIA)
//
// PENTING: header dan payload HANYA di-encode (bisa dibaca siapa saja), TIDAK dienkripsi.
//          Jangan taruh password atau data rahasia di payload!
//          Keamanan datang dari SIGNATURE: jika isi diubah, tanda tangan tidak cocok lagi.
// =====================================================================

// Base64 versi aman untuk URL: ganti + dan / menjadi - dan _, buang tanda =.
function base64url_encode($teks)
{
    return rtrim(strtr(base64_encode($teks), '+/', '-_'), '=');
}

function base64url_decode($teks)
{
    return base64_decode(strtr($teks, '-_', '+/'));
}

// ---------------------------------------------------------------------
// buat_token() : membuat JWT dari data payload
//   $payload = array data, mis. ['sub' => 1, 'username' => 'admin', 'role' => 'admin']
//   $rahasia = kunci rahasia server
//   $detik   = masa berlaku token
// ---------------------------------------------------------------------
function buat_token($payload, $rahasia, $detik)
{
    $payload['iat'] = time();            // iat = issued at (kapan dibuat)
    $payload['exp'] = time() + $detik;   // exp = expired (kapan kedaluwarsa)

    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $isi    = base64url_encode(json_encode($payload));

    // Tanda tangan = HMAC-SHA256 dari "header.isi" memakai kunci rahasia.
    $tanda_tangan = base64url_encode(hash_hmac('sha256', "$header.$isi", $rahasia, true));

    return "$header.$isi.$tanda_tangan";
}

// ---------------------------------------------------------------------
// cek_token() : memeriksa token
//   Mengembalikan array payload jika token SAH, atau null jika tidak sah.
// ---------------------------------------------------------------------
function cek_token($token, $rahasia)
{
    // 1) Harus terdiri dari tepat 3 bagian.
    $bagian = explode('.', $token);
    if (count($bagian) != 3) {
        return null;
    }
    list($header, $isi, $tanda_tangan) = $bagian;

    // 2) Hitung ulang tanda tangan yang SEHARUSNYA, lalu bandingkan.
    //    Kita SELALU memakai HS256 (tidak mempercayai "alg" dari token) -> mencegah serangan "alg: none".
    //    hash_equals = perbandingan aman dari serangan pengukuran waktu.
    $seharusnya = base64url_encode(hash_hmac('sha256', "$header.$isi", $rahasia, true));
    if (!hash_equals($seharusnya, $tanda_tangan)) {
        return null;   // isi pernah diubah, atau ditandatangani dengan kunci lain
    }

    // 3) Buka isi payload.
    $payload = json_decode(base64url_decode($isi), true);
    if (!is_array($payload)) {
        return null;
    }

    // 4) Periksa masa berlaku.
    if (!isset($payload['exp']) || $payload['exp'] < time()) {
        return null;   // sudah kedaluwarsa
    }

    return $payload;
}
