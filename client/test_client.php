<?php
// =====================================================================
// test_client.php  --  CLIENT penguji API (Pertemuan 7: dengan autentikasi)
//
// Program ini berperan sebagai "pengguna" API: login, memakai token, lalu
// memeriksa apakah server menolak / menerima dengan benar. Hasil: PASS / FAIL.
//
// Jalankan:
//   php test_client.php http://localhost/api-mhs-auth/server
//   php test_client.php http://localhost:8000          <- jika memakai php -S
// =====================================================================

// Boleh lewat terminal (argumen) atau browser (?base=...)
$base = $argv[1] ?? ($_GET['base'] ?? 'http://localhost/api-mhs-auth/server');
$base = rtrim($base, '/');
if (php_sapi_name() != 'cli') header('Content-Type: text/plain; charset=utf-8');

$lulus = 0;
$gagal = 0;

// ---------------------------------------------------------------------
// panggil() : mengirim satu request ke server dengan cURL
//   $token (opsional) -> dikirim sebagai header  Authorization: Bearer <token>
//   Mengembalikan array: [status code, isi jawaban JSON]
// ---------------------------------------------------------------------
function panggil($method, $path, $data = null, $token = null)
{
    global $base;

    $header = [];
    if ($data !== null) {
        $header[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $header[] = 'Authorization: Bearer ' . $token;      // <-- cara membawa token
    }

    $ch = curl_init($base . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);   // sertifikat lokal self-signed
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $isi    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$status, json_decode($isi, true)];
}

// Mencatat hasil satu pengujian
function cek($nama, $kondisi)
{
    global $lulus, $gagal;
    if ($kondisi) { $lulus++; echo "  [PASS] $nama\n"; }
    else          { $gagal++; echo "  [FAIL] $nama\n"; }
}

// Login dan mengembalikan token (atau null jika gagal)
function login($username, $password)
{
    list($status, $json) = panggil('POST', '/login', ['username' => $username, 'password' => $password]);
    return ($status == 200) ? $json['token'] : null;
}

// ---------------------------------------------------------------------
echo "Menguji server: $base\n\n";

list($status, $json) = panggil('GET', '/');

if ($status == 0) {
    $c = curl_init($base . '/'); curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false); curl_exec($c);
    echo "Server tidak bisa dihubungi: " . curl_error($c) . "\n";
    exit(2);
}

if ($status == 429) {
    echo "Server menolak karena terlalu banyak request dari komputer ini (rate limiting).\n";
    echo "Tunggu 1 menit, lalu jalankan lagi.\n";
    exit(3);
}

// ---------------------------------------------------------------------
echo "1. AUTENTIKASI: siapa kamu?\n";
list($status, $json) = panggil('GET', '/mhs');
cek('GET /mhs tanpa token -> 401', $status == 401);

list($status, $json) = panggil('POST', '/login', ['username' => 'admin', 'password' => 'salah']);
cek('Login password salah -> 401', $status == 401);

list($status, $json) = panggil('POST', '/login', ['username' => 'tidak_ada', 'password' => 'x']);
cek('Login username tidak ada -> 401 (pesan sama, tidak membocorkan)', $status == 401);

list($status, $json) = panggil('POST', '/login', ['username' => 'admin']);
cek('Login tanpa password -> 422', $status == 422);

list($status, $json) = panggil('POST', '/login', ['username' => "' OR '1'='1", 'password' => "' OR '1'='1"]);
cek("Login dengan SQL Injection ' OR '1'='1 -> 401 (gagal menembus)", $status == 401);

$tokenAdmin = login('admin', 'admin123');
cek('Login admin benar -> dapat token', $tokenAdmin !== null);
$tokenUser = login('budi', 'budi123');
cek('Login budi benar -> dapat token', $tokenUser !== null);

if ($tokenAdmin === null || $tokenUser === null) {
    echo "\nLogin gagal. Pastikan database sudah diimpor dari database/mhs.sql versi Pertemuan 7.\n";
    exit(1);
}

echo "   token berbentuk 3 bagian dipisah titik: " . substr($tokenAdmin, 0, 30) . "...\n";
cek('Token terdiri dari 3 bagian (header.payload.signature)', count(explode('.', $tokenAdmin)) == 3);

list($status, $json) = panggil('GET', '/me', null, $tokenUser);
cek('GET /me dengan token -> 200, username budi', $status == 200 && $json['data']['username'] == 'budi');

echo "\n2. TOKEN PALSU / RUSAK harus ditolak\n";
list($status, $json) = panggil('GET', '/mhs', null, 'ini.bukan.token');
cek('Token asal-asalan -> 401', $status == 401);

// Merusak 1 huruf tanda tangan
$rusak = substr($tokenUser, 0, -2) . (substr($tokenUser, -2) == 'AA' ? 'BB' : 'AA');
list($status, $json) = panggil('GET', '/mhs', null, $rusak);
cek('Tanda tangan diubah -> 401', $status == 401);

// Mengganti isi payload menjadi role admin, tanda tangan tetap yang lama
list($h, $p, $t) = explode('.', $tokenUser);
$payloadPalsu = rtrim(strtr(base64_encode(json_encode(['sub' => 2, 'username' => 'budi', 'role' => 'admin', 'exp' => time() + 3600])), '+/', '-_'), '=');
list($status, $json) = panggil('GET', '/mhs', null, "$h.$payloadPalsu.$t");
cek('Payload diubah jadi admin (tanpa kunci) -> 401', $status == 401);

echo "\n3. OTORISASI: bolehkah kamu?  (budi = role user)\n";
list($status, $json) = panggil('GET', '/mhs', null, $tokenUser);
cek('user: GET /mhs -> 200 (boleh melihat)', $status == 200);

$baru = ['nim' => '99' . rand(100000, 999999), 'name' => 'Mahasiswa Uji', 'major' => 'Informatika'];
list($status, $json) = panggil('POST', '/mhs', $baru, $tokenUser);
cek('user: POST /mhs -> 403 (tidak boleh menambah)', $status == 403);

list($status, $json) = panggil('DELETE', '/mhs/1', null, $tokenUser);
cek('user: DELETE /mhs/1 -> 403', $status == 403);

list($status, $json) = panggil('GET', '/log', null, $tokenUser);
cek('user: GET /log -> 403', $status == 403);

echo "\n4. ADMIN boleh melakukan CRUD\n";
list($status, $json) = panggil('POST', '/mhs', $baru, $tokenAdmin);
cek('admin: POST /mhs -> 201', $status == 201);
$id = isset($json['id']) ? $json['id'] : 0;

list($status, $json) = panggil('POST', '/mhs', $baru, $tokenAdmin);
cek('admin: POST NIM kembar -> 409', $status == 409);

list($status, $json) = panggil('POST', '/mhs', ['nim' => 'abc'], $tokenAdmin);
cek('admin: POST data tidak valid -> 422', $status == 422);

$baru['name'] = 'Nama Diubah';
list($status, $json) = panggil('PUT', "/mhs/$id", $baru, $tokenAdmin);
cek('admin: PUT -> 200', $status == 200);

list($status, $json) = panggil('GET', '/mhs/' . rawurlencode('1 OR 1=1'), null, $tokenAdmin);
cek('admin: id "1 OR 1=1" -> 400 (validasi input)', $status == 400);

list($status, $json) = panggil('DELETE', "/mhs/$id", null, $tokenAdmin);
cek('admin: DELETE -> 200', $status == 200);

echo "\n5. RATE LIMITING: pembatasan percobaan login\n";
$penyerang = 'penyerang' . rand(1000, 9999);          // username acak agar tidak mengunci akun asli
$kode = [];
for ($i = 1; $i <= 7; $i++) {
    list($status, $json) = panggil('POST', '/login', ['username' => $penyerang, 'password' => 'tebak' . $i]);
    $kode[] = $status;
}
echo "   status 7 percobaan berturut-turut: " . implode(', ', $kode) . "\n";
cek('5 percobaan pertama ditolak 401', array_slice($kode, 0, 5) == [401, 401, 401, 401, 401]);
cek('Percobaan ke-6 dst diblokir 429 (Too Many Requests)', $kode[5] == 429 && $kode[6] == 429);

$tokenAdmin2 = login('admin', 'admin123');
cek('Akun asli (admin) tidak ikut terkunci', $tokenAdmin2 !== null);

// =====================================================================
// TUGAS 1: REGISTER
// =====================================================================
echo "\n6. REGISTER: pendaftaran akun baru (publik)\n";
$userBaru = 'uji' . rand(100000, 999999);
$passBaru = 'rahasia123';

list($status, $json) = panggil('POST', '/register', ['username' => $userBaru, 'password' => 'pendek']);
cek('register password < 8 karakter -> 422', $status == 422);

list($status, $json) = panggil('POST', '/register', ['username' => $userBaru]);
cek('register tanpa password -> 422', $status == 422);

// Client mencoba mendaftar sebagai admin -> role harus tetap 'user'.
list($status, $json) = panggil('POST', '/register', [
    'username' => $userBaru, 'password' => $passBaru, 'role' => 'admin'
]);
cek('register valid -> 201 dan role tetap user (tidak bisa pilih role)',
    $status == 201 && ($json['user']['role'] ?? '') == 'user');

list($status, $json) = panggil('POST', '/register', ['username' => $userBaru, 'password' => $passBaru]);
cek('register username kembar -> 409', $status == 409);

$tokenBaru = login($userBaru, $passBaru);
cek('login dengan akun hasil register -> dapat token', $tokenBaru !== null);

list($status, $json) = panggil('GET', '/mhs', null, $tokenBaru);
cek('akun baru (role user): GET /mhs -> 200', $status == 200);

list($status, $json) = panggil('POST', '/mhs', ['nim' => '88' . rand(100000, 999999), 'name' => 'X', 'major' => 'Y'], $tokenBaru);
cek('akun baru (role user): POST /mhs -> 403', $status == 403);

// =====================================================================
// TUGAS 2: GANTI PASSWORD
// =====================================================================
echo "\n7. GANTI PASSWORD: PUT /me/password\n";
$passBaru2 = 'rahasia456';

list($status, $json) = panggil('PUT', '/me/password',
    ['password_lama' => $passBaru, 'password_baru' => $passBaru2]);
cek('ganti password tanpa token -> 401', $status == 401);

list($status, $json) = panggil('PUT', '/me/password',
    ['password_lama' => 'salah', 'password_baru' => $passBaru2], $tokenBaru);
cek('password lama salah -> 401', $status == 401);

list($status, $json) = panggil('PUT', '/me/password',
    ['password_lama' => $passBaru, 'password_baru' => 'pendek'], $tokenBaru);
cek('password baru < 8 karakter -> 422', $status == 422);

list($status, $json) = panggil('PUT', '/me/password',
    ['password_lama' => $passBaru, 'password_baru' => $passBaru], $tokenBaru);
cek('password baru sama dengan password lama -> 422', $status == 422);

list($status, $json) = panggil('PUT', '/me/password',
    ['password_lama' => $passBaru, 'password_baru' => $passBaru2], $tokenBaru);
cek('ganti password dengan data benar -> 200', $status == 200);

$tokenLama = login($userBaru, $passBaru);
cek('login dengan password lama -> 401', $tokenLama === null);

$tokenBaru2 = login($userBaru, $passBaru2);
cek('login dengan password BARU -> dapat token', $tokenBaru2 !== null);

// =====================================================================
// TUGAS 3: ROLE DOSEN
// =====================================================================
echo "\n8. ROLE DOSEN: boleh PUT, tidak boleh DELETE/POST/log\n";
$tokenDosen = login('dosen', 'dosen123');
cek('login dosen benar -> dapat token', $tokenDosen !== null);

if ($tokenDosen === null) {
    echo "   Akun dosen belum ada. Jalankan database/migrasi_p7_tugas.sql,\n";
    echo "   atau impor ulang database/mhs.sql versi tugas ini.\n";
} else {
    list($status, $json) = panggil('GET', '/me', null, $tokenDosen);
    cek('GET /me dosen -> role dosen', $status == 200 && ($json['data']['role'] ?? '') == 'dosen');

    // Admin menyiapkan satu data uji untuk diubah oleh dosen.
    $dataUji = ['nim' => '77' . rand(100000, 999999), 'name' => 'Data Uji Dosen', 'major' => 'Informatika'];
    list($status, $json) = panggil('POST', '/mhs', $dataUji, $tokenAdmin);
    $idUji = isset($json['id']) ? $json['id'] : 0;
    cek('admin menyiapkan data uji -> 201', $status == 201);

    $dataUji['name'] = 'Diubah oleh Dosen';
    list($status, $json) = panggil('PUT', "/mhs/$idUji", $dataUji, $tokenDosen);
    cek('dosen: PUT /mhs/{id} -> 200 (boleh mengubah)', $status == 200);

    list($status, $json) = panggil('DELETE', "/mhs/$idUji", null, $tokenDosen);
    cek('dosen: DELETE /mhs/{id} -> 403 (tidak boleh menghapus)', $status == 403);

    list($status, $json) = panggil('POST', '/mhs',
        ['nim' => '66' . rand(100000, 999999), 'name' => 'X', 'major' => 'Y'], $tokenDosen);
    cek('dosen: POST /mhs -> 403 (hanya admin yang menambah)', $status == 403);

    list($status, $json) = panggil('GET', '/log', null, $tokenDosen);
    cek('dosen: GET /log -> 403 (log hanya admin)', $status == 403);

    list($status, $json) = panggil('DELETE', "/mhs/$idUji", null, $tokenAdmin);
    cek('admin membersihkan data uji -> 200', $status == 200);
}

echo "\n9. LOGGING: siapa melakukan apa\n";
list($status, $json) = panggil('GET', '/log', null, $tokenAdmin);
cek('admin: GET /log -> 200', $status == 200);
echo "   10 catatan log terakhir:\n";
$adaUser = false; $ada401 = false; $ada403 = false; $ada429 = false;
foreach ($json['data'] as $i => $b) {
    if ($i < 10) {
        printf("   %s  %-6s %-14s -> %d  user=%s\n", $b['waktu'], $b['method'], substr(basename($b['path']), 0, 14), $b['status'], $b['username'] ?? '-');
    }
}
// Cek lebih banyak baris langsung dari log 20 terakhir
foreach ($json['data'] as $b) {
    if ($b['username'] == 'admin') $adaUser = true;
    if ($b['status'] == 401) $ada401 = true;
    if ($b['status'] == 403) $ada403 = true;
    if ($b['status'] == 429) $ada429 = true;
}
cek('Log mencatat nama pengguna (kolom username)', $adaUser);
cek('Log mencatat percobaan ditolak (401 / 403 / 429)', $ada401 || $ada403 || $ada429);

// ---------------------------------------------------------------------
$total = $lulus + $gagal;
echo "\nHASIL: $lulus dari $total pengujian lulus\n";
exit($gagal == 0 ? 0 : 1);
