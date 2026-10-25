<?php
// =====================================================================
// index.php  --  API Mahasiswa (versi sederhana)
//
// Semua alamat masuk ke file ini berkat .htaccess. Alurnya:
//   1. Baca alamat (URL) dan jenis request (GET/POST/PUT/DELETE)
//   2. Tentukan mau ke mana (routing)
//   3. Jalankan query database
//   4. Kirim jawaban JSON  (+ catat ke tabel log_api)
//
// Daftar endpoint:
//   POST   /login        -> login, mendapat token JWT          (publik)
//   POST   /register     -> daftar akun baru, role 'user'      (publik, TUGAS 1)
//   GET    /me           -> lihat data akun yang sedang login  (perlu token)
//   PUT    /me/password  -> ganti password sendiri             (perlu token, TUGAS 2)
//   GET    /mhs          -> ambil semua mahasiswa              (perlu token)
//   GET    /mhs/3        -> ambil mahasiswa dengan id 3        (perlu token)
//   POST   /mhs          -> tambah mahasiswa baru              (token + role admin)
//   PUT    /mhs/3        -> ubah data mahasiswa id 3           (token + admin/dosen)
//   DELETE /mhs/3        -> hapus mahasiswa id 3               (token + role admin)
//   GET    /log          -> lihat 20 catatan log terakhir      (token + role admin)
//
// Perubahan dari Pertemuan 6 ditandai komentar  "<-- BARU P7"
// Tambahan Tugas Pertemuan 7 ditandai komentar  "TUGAS"
// =====================================================================

require 'koneksi.php';   // menghasilkan variabel $db (koneksi database) + pengaturan keamanan
require 'jwt.php';       // <-- BARU P7: fungsi buat_token() dan cek_token()
require 'fungsi.php';    // fungsi kirim(), catat_log(), wajib_login(), wajib_admin(), ...

// ---------------------------------------------------------------------
// Izin untuk client web (browser) yang alamatnya berbeda dari server ini.
// Tanpa ini browser akan memblokir permintaan (aturan CORS).
// ---------------------------------------------------------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');   // <-- BARU P7: izinkan header Authorization

// Browser kadang bertanya dulu "boleh tidak?" dengan method OPTIONS. Jawab saja "boleh".
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------
// LANGKAH 1: membaca alamat
// Contoh alamat: /api-mhs-sederhana/server/mhs/3
// Kita buang folder server-nya, sisanya "mhs/3" dipecah jadi ['mhs', '3'].
// ---------------------------------------------------------------------
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);   // tanpa ?query
$folder = dirname($_SERVER['SCRIPT_NAME']);                   // folder tempat index.php
if ($folder != '/' && strpos($path, $folder) === 0) {
    $path = substr($path, strlen($folder));                   // buang nama folder
}
$bagian   = explode('/', trim($path, '/'));                   // pecah per tanda "/"
$resource = $bagian[0];                                       // 'mhs' atau 'log'
$id       = isset($bagian[1]) ? $bagian[1] : null;            // '3' (boleh kosong)

$method = $_SERVER['REQUEST_METHOD'];                         // GET / POST / PUT / DELETE

// Data JSON yang dikirim client (untuk POST dan PUT), diubah menjadi array PHP.
$input = json_decode(file_get_contents('php://input'), true);

// <-- BARU P7: RATE LIMITING umum. Terlalu banyak request dari satu IP -> 429.
batasi_request();

// try ... catch : jika ada error database, jangan tampilkan layar error PHP,
// tapi kirim jawaban JSON yang rapi.
try {

    // =================================================================
    // <-- BARU P7: POST /login  -> AUTENTIKASI (publik, tidak perlu token)
    // =================================================================
    if ($resource == 'login' && $method == 'POST') {

        // 1) Validasi input: username dan password wajib diisi (teks).
        if (!is_array($input) || empty($input['username']) || empty($input['password'])
            || !is_string($input['username']) || !is_string($input['password'])) {
            kirim(['status' => 'error', 'pesan' => 'username dan password wajib diisi'], 422);
        }
        $username = trim($input['username']);
        $username_log = $username;                      // ikut dicatat di log

        // 2) RATE LIMITING login: hitung gagal login untuk username ini dari IP ini dalam 1 menit.
        $semenit_lalu = date('Y-m-d H:i:s', time() - 60);
        $stmt = $db->prepare("SELECT COUNT(*) FROM log_api
                              WHERE ip = ? AND username = ? AND status = 401 AND waktu >= ?
                                AND path LIKE '%/login'");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $username, $semenit_lalu]);
        if ($stmt->fetchColumn() >= $batas_login) {
            header('Retry-After: 60');
            kirim(['status' => 'error', 'pesan' => 'Terlalu banyak percobaan login. Coba lagi 1 menit lagi'], 429);
        }

        // 3) Cari akun di database (prepared statement -> aman dari SQL Injection).
        $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $akun = $stmt->fetch();

        // 4) Cocokkan password. password_verify membandingkan password dengan HASH di database.
        //    Pesan error SENGAJA sama untuk "username salah" dan "password salah",
        //    supaya penyerang tidak tahu username mana yang benar-benar ada.
        if (!$akun || !password_verify($input['password'], $akun['password'])) {
            kirim(['status' => 'error', 'pesan' => 'Username atau password salah'], 401);
        }

        // 5) Berhasil -> buat token JWT berisi identitas pengguna (BUKAN password!).
        $token = buat_token(
            ['sub' => (int)$akun['id'], 'username' => $akun['username'], 'role' => $akun['role']],
            $jwt_secret,
            $jwt_berlaku
        );
        kirim([
            'status'        => 'sukses',
            'pesan'         => 'Login berhasil',
            'token'         => $token,
            'tipe'          => 'Bearer',
            'berlaku_detik' => $jwt_berlaku,
            'user'          => ['username' => $akun['username'], 'role' => $akun['role']]
        ]);
    }

    // =================================================================
    // TUGAS 1: POST /register  -> pendaftaran akun baru (publik)
    //   - username unik
    //   - password minimal 8 karakter, disimpan dengan password_hash()
    //   - role SELALU 'user' (tidak boleh dipilih dari client)
    //   Jawaban: 201 berhasil | 409 username sudah dipakai | 422 data tidak valid
    // =================================================================
    elseif ($resource == 'register' && $method == 'POST') {

        // 1) Validasi bentuk data.
        if (!is_array($input)
            || !isset($input['username']) || !isset($input['password'])
            || !is_string($input['username']) || !is_string($input['password'])) {
            kirim(['status' => 'error', 'pesan' => 'username dan password wajib diisi'], 422);
        }

        $username = trim($input['username']);
        $password = $input['password'];

        if ($username === '' || strlen($username) > 50) {
            kirim(['status' => 'error', 'pesan' => 'username wajib diisi (maksimal 50 karakter)'], 422);
        }
        // Batas 72 byte mengikuti batas aman algoritma bcrypt (PASSWORD_DEFAULT).
        if (strlen($password) < 8 || strlen($password) > 72) {
            kirim(['status' => 'error', 'pesan' => 'password minimal 8 dan maksimal 72 karakter'], 422);
        }

        $username_log = $username;                 // ikut dicatat di log

        // 2) Username harus unik (prepared statement).
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            kirim(['status' => 'error', 'pesan' => 'Username sudah terdaftar'], 409);
        }

        // 3) Simpan password sebagai HASH. Role ditulis tetap 'user',
        //    sehingga client tidak bisa mendaftar sebagai admin/dosen.
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'user')");
            $stmt->execute([$username, $hash]);
        } catch (PDOException $e) {
            // Kode 23000 = pelanggaran UNIQUE (dua pendaftaran bersamaan).
            if ($e->getCode() == '23000') {
                kirim(['status' => 'error', 'pesan' => 'Username sudah terdaftar'], 409);
            }
            throw $e;
        }

        kirim([
            'status' => 'sukses',
            'pesan'  => 'Registrasi berhasil',
            'user'   => [
                'id'       => (int)$db->lastInsertId(),
                'username' => $username,
                'role'     => 'user'
            ]
        ], 201);
    }

    // =================================================================
    // <-- BARU P7: SEMUA alamat di bawah ini WAJIB membawa token yang sah.
    // wajib_login() mengembalikan data pengguna, atau langsung 401 dan berhenti.
    // =================================================================
    elseif ($resource != '') {
        $pengguna = wajib_login();
    }

    // =================================================================
    // LANGKAH 2: ROUTING  -> /me   (BARU P7)
    // =================================================================
    if ($resource == 'me' && $id === null && $method == 'GET') {
        kirim(['status' => 'sukses', 'data' => [
            'id' => $pengguna['sub'], 'username' => $pengguna['username'],
            'role' => $pengguna['role'], 'token_habis_pada' => date('Y-m-d H:i:s', $pengguna['exp'])
        ]]);
    }

    // =================================================================
    // TUGAS 2: PUT /me/password  -> ganti password sendiri (perlu token)
    //   Body: {"password_lama": "...", "password_baru": "..."}
    //   Password lama WAJIB benar. Hash lama diambil dari database,
    //   bukan dari token (token tidak pernah berisi password).
    // =================================================================
    elseif ($resource == 'me' && $id == 'password' && $method == 'PUT') {

        // 1) Validasi input.
        if (!is_array($input)
            || !isset($input['password_lama']) || !isset($input['password_baru'])
            || !is_string($input['password_lama']) || !is_string($input['password_baru'])) {
            kirim(['status' => 'error', 'pesan' => 'password_lama dan password_baru wajib diisi'], 422);
        }

        $lama = $input['password_lama'];
        $baru = $input['password_baru'];

        if (strlen($baru) < 8 || strlen($baru) > 72) {
            kirim(['status' => 'error', 'pesan' => 'password baru minimal 8 dan maksimal 72 karakter'], 422);
        }
        if ($lama === $baru) {
            kirim(['status' => 'error', 'pesan' => 'Password baru harus berbeda dari password lama'], 422);
        }

        // 2) Ambil hash password saat ini berdasarkan id dari token.
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$pengguna['sub']]);
        $baris = $stmt->fetch();
        if (!$baris) {
            kirim(['status' => 'error', 'pesan' => 'Akun tidak ditemukan'], 404);
        }

        // 3) Cocokkan password lama dengan hash di database.
        if (!password_verify($lama, $baris['password'])) {
            kirim(['status' => 'error', 'pesan' => 'Password lama salah'], 401);
        }

        // 4) Simpan hash password baru.
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([password_hash($baru, PASSWORD_DEFAULT), $pengguna['sub']]);

        kirim(['status' => 'sukses', 'pesan' => 'Password berhasil diubah']);
    }

    // =================================================================
    // ROUTING  -> /mhs
    // =================================================================
    elseif ($resource == 'mhs') {

        // ---------- ID harus angka (jika diisi) ----------
        if ($id !== null && !ctype_digit($id)) {
            kirim(['status' => 'error', 'pesan' => 'ID harus berupa angka'], 400);
        }

        // ---------- READ: GET /mhs  (semua data) ----------
        if ($method == 'GET' && $id === null) {
            $hasil = $db->query("SELECT * FROM mhs ORDER BY id")->fetchAll();
            kirim(['status' => 'sukses', 'data' => $hasil]);
        }

        // ---------- READ: GET /mhs/3  (satu data) ----------
        elseif ($method == 'GET') {
            $stmt = $db->prepare("SELECT * FROM mhs WHERE id = ?");
            $stmt->execute([$id]);
            $baris = $stmt->fetch();                  // false jika tidak ada
            if (!$baris) {
                kirim(['status' => 'error', 'pesan' => 'Mahasiswa tidak ditemukan'], 404);
            }
            kirim(['status' => 'sukses', 'data' => $baris]);
        }

        // ---------- CREATE: POST /mhs ----------
        elseif ($method == 'POST' && $id === null) {
            wajib_admin($pengguna);                   // <-- BARU P7: OTORISASI, hanya admin
            cek_input($input);                        // data wajib lengkap, jika tidak -> berhenti (400)

            // NIM tidak boleh kembar
            $stmt = $db->prepare("SELECT id FROM mhs WHERE nim = ?");
            $stmt->execute([$input['nim']]);
            if ($stmt->fetch()) {
                kirim(['status' => 'error', 'pesan' => 'NIM sudah terdaftar'], 409);
            }

            $stmt = $db->prepare("INSERT INTO mhs (nim, name, major) VALUES (?, ?, ?)");
            $stmt->execute([$input['nim'], $input['name'], $input['major']]);

            $idBaru = $db->lastInsertId();            // id yang baru dibuat MySQL
            kirim(['status' => 'sukses', 'pesan' => 'Data ditambahkan', 'id' => (int)$idBaru], 201);
        }

        // ---------- UPDATE: PUT /mhs/3 ----------
        elseif ($method == 'PUT' && $id !== null) {
            // <-- TUGAS 3: admin DAN dosen boleh mengubah data.
            // Dosen tidak boleh POST/DELETE (tetap wajib_admin di bawah).
            wajib_role($pengguna, ['admin', 'dosen']);
            cek_input($input);

            // Pastikan datanya ada dulu
            $stmt = $db->prepare("SELECT id FROM mhs WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                kirim(['status' => 'error', 'pesan' => 'Mahasiswa tidak ditemukan'], 404);
            }

            // NIM baru tidak boleh dipakai mahasiswa LAIN (id <> ?)
            $stmt = $db->prepare("SELECT id FROM mhs WHERE nim = ? AND id <> ?");
            $stmt->execute([$input['nim'], $id]);
            if ($stmt->fetch()) {
                kirim(['status' => 'error', 'pesan' => 'NIM sudah dipakai mahasiswa lain'], 409);
            }

            $stmt = $db->prepare("UPDATE mhs SET nim = ?, name = ?, major = ? WHERE id = ?");
            $stmt->execute([$input['nim'], $input['name'], $input['major'], $id]);
            kirim(['status' => 'sukses', 'pesan' => 'Data diubah']);
        }

        // ---------- DELETE: DELETE /mhs/3 ----------
        elseif ($method == 'DELETE' && $id !== null) {
            wajib_admin($pengguna);                   // <-- BARU P7
            $stmt = $db->prepare("DELETE FROM mhs WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() == 0) {             // tidak ada baris yang terhapus
                kirim(['status' => 'error', 'pesan' => 'Mahasiswa tidak ditemukan'], 404);
            }
            kirim(['status' => 'sukses', 'pesan' => 'Data dihapus']);
        }

        // ---------- Kombinasi lain (mis. DELETE /mhs tanpa id) ----------
        else {
            kirim(['status' => 'error', 'pesan' => 'Method tidak diizinkan'], 405);
        }
    }

    // =================================================================
    // ROUTING  -> /log   (melihat catatan log dari database)
    // =================================================================
    elseif ($resource == 'log' && $method == 'GET') {
        wajib_admin($pengguna);                       // <-- BARU P7: log hanya boleh dilihat admin
        $hasil = $db->query("SELECT * FROM log_api ORDER BY id DESC LIMIT 20")->fetchAll();
        // Perhatian: kirim() juga mencatat request /log ini ke log_api.
        kirim(['status' => 'sukses', 'data' => $hasil]);
    }

    // =================================================================
    // ROUTING  -> alamat kosong  (cek server hidup)
    // =================================================================
    elseif ($resource == '') {
        kirim(['status' => 'sukses', 'pesan' => 'API Mahasiswa aktif']);
    }

    // Alamat lain yang tidak dikenal
    else {
        kirim(['status' => 'error', 'pesan' => 'Alamat tidak ditemukan'], 404);
    }

} catch (PDOException $e) {
    // Error database tak terduga. Detailnya TIDAK dikirim ke client (bisa membocorkan struktur tabel).
    kirim(['status' => 'error', 'pesan' => 'Terjadi kesalahan pada database'], 500);
}


// =====================================================================
// FUNGSI: cek_input()  -- memastikan data dari client lengkap
// Jika ada yang kurang, langsung kirim error 400 dan berhenti.
// =====================================================================
function cek_input($input)
{
    // Jika body bukan JSON yang benar, $input bukan array.
    if (!is_array($input)) {
        kirim(['status' => 'error', 'pesan' => 'Kirim data dalam format JSON'], 400);
    }

    // Tiga field ini wajib ada dan tidak boleh kosong.
    foreach (['nim', 'name', 'major'] as $field) {
        if (empty($input[$field])) {
            kirim(['status' => 'error', 'pesan' => "Field $field wajib diisi"], 422);
        }
    }

    // NIM harus angka saja.
    if (!ctype_digit((string)$input['nim'])) {
        kirim(['status' => 'error', 'pesan' => 'NIM harus berupa angka'], 422);
    }
}
