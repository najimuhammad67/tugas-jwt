<?php
// =====================================================================
// fungsi.php  --  Fungsi bantu: log, kirim jawaban, keamanan
// =====================================================================

// Nama pengguna yang sedang mengakses. Diisi saat login / token diperiksa,
// lalu ikut dicatat di log supaya kita tahu "siapa melakukan apa".
$username_log = null;

// ---------------------------------------------------------------------
// catat_log() : menyimpan satu baris ke tabel log_api (LOGGING DI DATABASE)
// ---------------------------------------------------------------------
function catat_log($status, $pesan = '')
{
    global $db, $username_log;

    $waktu  = date('Y-m-d H:i:s');
    $method = $_SERVER['REQUEST_METHOD'];
    $path   = $_SERVER['REQUEST_URI'];
    $ip     = $_SERVER['REMOTE_ADDR'];

    $sql  = "INSERT INTO log_api (waktu, method, path, status, ip, username, pesan)
             VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($sql);
    $stmt->execute([$waktu, $method, $path, $status, $ip, $username_log, $pesan]);
}

// ---------------------------------------------------------------------
// kirim() : mengirim jawaban JSON, mencatat log, lalu berhenti
// ---------------------------------------------------------------------
function kirim($data, $status = 200)
{
    $pesan = isset($data['pesan']) ? $data['pesan'] : 'OK';
    catat_log($status, $pesan);

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// ---------------------------------------------------------------------
// ambil_token() : membaca token dari header
//     Authorization: Bearer eyJhbGciOi...
// Mengembalikan teks token, atau null jika header tidak ada / bentuknya salah.
// ---------------------------------------------------------------------
function ambil_token()
{
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];     // dipakai pada beberapa konfigurasi Apache
    }

    // Bentuk yang benar: "Bearer <token>"
    if (preg_match('/^Bearer\s+(\S+)$/', $header, $cocok)) {
        return $cocok[1];
    }
    return null;
}

// ---------------------------------------------------------------------
// wajib_login() : AUTENTIKASI -> "Siapa kamu?"
//   Memeriksa token. Jika tidak sah -> 401 dan berhenti.
//   Jika sah, mengembalikan data pengguna dari token.
// ---------------------------------------------------------------------
function wajib_login()
{
    global $jwt_secret, $username_log;

    $token   = ambil_token();
    $payload = $token ? cek_token($token, $jwt_secret) : null;

    if (!$payload) {
        header('WWW-Authenticate: Bearer');   // petunjuk standar: "pakai token Bearer"
        kirim(['status' => 'error', 'pesan' => 'Token tidak ada, tidak valid, atau sudah kedaluwarsa'], 401);
    }

    $username_log = $payload['username'];     // supaya tercatat di log
    return $payload;
}

// ---------------------------------------------------------------------
// wajib_admin() : OTORISASI -> "Bolehkah kamu melakukan ini?"
//   Pengguna sudah dikenali (login), tetapi rolenya harus 'admin'.
//   Jika bukan -> 403 Forbidden.
// ---------------------------------------------------------------------
function wajib_admin($pengguna)
{
    if ($pengguna['role'] != 'admin') {
        kirim(['status' => 'error', 'pesan' => 'Akses ditolak: hanya admin yang boleh'], 403);
    }
}

// ---------------------------------------------------------------------
// wajib_role() : OTORISASI berbasis daftar role (TUGAS P7 nomor 3)
//   Contoh: wajib_role($pengguna, ['admin', 'dosen']);
//   Jika role pengguna tidak ada di daftar -> 403 Forbidden.
//   Dipakai agar aturan hak akses mudah diperluas tanpa mengubah
//   fungsi wajib_login() (autentikasi) maupun wajib_admin().
// ---------------------------------------------------------------------
function wajib_role($pengguna, $roles)
{
    if (!in_array($pengguna['role'], $roles, true)) {
        kirim(['status' => 'error', 'pesan' => 'Akses ditolak: role Anda tidak diizinkan'], 403);
    }
}

// ---------------------------------------------------------------------
// batasi_request() : RATE LIMITING umum per IP
//   Menghitung jumlah request dari IP ini dalam 1 menit terakhir (dari tabel log_api).
//   Jika sudah terlalu banyak -> 429 Too Many Requests.
// ---------------------------------------------------------------------
function batasi_request()
{
    global $db, $batas_umum;

    $semenit_lalu = date('Y-m-d H:i:s', time() - 60);
    $stmt = $db->prepare("SELECT COUNT(*) FROM log_api WHERE ip = ? AND waktu >= ?");
    $stmt->execute([$_SERVER['REMOTE_ADDR'], $semenit_lalu]);
    $jumlah = $stmt->fetchColumn();

    if ($jumlah >= $batas_umum) {
        header('Retry-After: 60');            // beri tahu client: coba lagi 60 detik lagi
        kirim(['status' => 'error', 'pesan' => 'Terlalu banyak request. Coba lagi 1 menit lagi'], 429);
    }
}
