# Laporan Tugas Pertemuan 7 — Autentikasi dan Otorisasi JWT

Proyek: `api-mhs-auth` (PHP 8 + MySQL)

## 1. Fitur yang ditambahkan

| No | Tugas | Implementasi |
|----|-------|--------------|
| 1 | `POST /register` | `server/index.php` — username unik, password minimal 8 karakter, `password_hash()`, role selalu `user` |
| 2 | `PUT /me/password` | `server/index.php` — wajib token dan password lama yang benar |
| 3 | Role `dosen` + `wajib_role()` | `server/fungsi.php` + `server/index.php` — dosen boleh `PUT`, tidak boleh `POST`/`DELETE`/`GET /log` |
| 4 | Analisis log | `database/analisis_log.sql` — 3 query |
| 5 | Pengujian baru | `client/test_client.php` — 22 pengecekan baru (total 50) |

File pendukung:

- `database/mhs.sql` — menambah akun `dosen`.
- `database/migrasi_p7_tugas.sql` — menambah akun dosen pada database yang sudah ada tanpa menghapus data.
- `README.md` — dokumentasi endpoint dan cara uji.

## 2. Endpoint baru dan status

### POST /register (publik)

Body:

```json
{"username": "siti", "password": "rahasia123"}
```

| Kondisi | Status |
|---------|--------|
| Berhasil | 201 |
| Username sudah dipakai | 409 |
| Field kurang / password < 8 karakter | 422 |

Catatan keamanan: field `role` dari client diabaikan; role selalu `user`,
sehingga tidak ada pendaftaran sebagai admin/dosen.

### PUT /me/password (perlu token)

Body:

```json
{"password_lama": "rahasia123", "password_baru": "rahasia456"}
```

| Kondisi | Status |
|---------|--------|
| Berhasil | 200 |
| Tanpa token / token tidak sah | 401 |
| Password lama salah | 401 |
| Data tidak valid / password baru < 8 karakter / sama dengan lama | 422 |

## 3. Matriks hak akses

| Endpoint | user | dosen | admin |
|----------|------|-------|-------|
| `GET /mhs`, `GET /mhs/{id}` | ya | ya | ya |
| `POST /mhs` | tidak (403) | tidak (403) | ya |
| `PUT /mhs/{id}` | tidak (403) | ya | ya |
| `DELETE /mhs/{id}` | tidak (403) | tidak (403) | ya |
| `GET /log` | tidak (403) | tidak (403) | ya |

Autentikasi gagal (tanpa token / token tidak sah) selalu **401**.
Otorisasi gagal (role tidak berhak) selalu **403**.

## 4. Query analisis log

Ada di `database/analisis_log.sql`.

1. **Jumlah login gagal per username**

```sql
SELECT COALESCE(username, '(tanpa username)') AS username,
       COUNT(*) AS jumlah_gagal
FROM log_api
WHERE path LIKE '%/login' AND status = 401
GROUP BY username
ORDER BY jumlah_gagal DESC;
```

2. **5 IP dengan request terbanyak**

```sql
SELECT ip, COUNT(*) AS jumlah_request
FROM log_api
GROUP BY ip
ORDER BY jumlah_request DESC
LIMIT 5;
```

3. **Jumlah status 401/403/429 per hari**

```sql
SELECT DATE(waktu) AS tanggal,
       SUM(CASE WHEN status = 401 THEN 1 ELSE 0 END) AS jumlah_401,
       SUM(CASE WHEN status = 403 THEN 1 ELSE 0 END) AS jumlah_403,
       SUM(CASE WHEN status = 429 THEN 1 ELSE 0 END) AS jumlah_429
FROM log_api
WHERE status IN (401, 403, 429)
GROUP BY DATE(waktu)
ORDER BY tanggal DESC;
```

Contoh hasil saat pengujian:

```text
== Login gagal per username ==
(tanpa username) | 5
penyerang6569    | 5
admin            | 3

== 5 IP request terbanyak ==
127.0.0.1 | 885

== 401/403/429 per hari ==
2026-10-25 | 218 | 13 | 6
2026-10-24 | 236 |  0 | 0
```

## 5. Hasil pengujian

Perintah:

```bash
php client/test_client.php http://localhost:8000
```

Hasil: **50 dari 50 pengujian lulus**, mencakup:

- autentikasi dan penolakan token palsu/rusak,
- otorisasi user vs admin vs dosen,
- CRUD dan validasi input,
- SQL injection pada login,
- rate limiting login,
- logging username dan status,
- register (valid, duplikat, password lemah, role tidak bisa dipilih),
- ganti password (tanpa token, password lama salah, password baru lemah, berhasil, login lama gagal),
- role dosen (boleh PUT, ditolak saat DELETE/POST/log).

## 6. Catatan keamanan

- Password disimpan dengan `password_hash()` dan diperiksa dengan `password_verify()`.
- Semua query yang memakai input client memakai prepared statement.
- Pesan login dibuat seragam agar tidak membocorkan username yang terdaftar.
- Password tidak pernah dimasukkan ke payload JWT.
- Perubahan password tidak membatalkan token lama; token tetap sah sampai `exp`.
  Untuk pembatalan seketika diperlukan `jti` + tabel blacklist (tantangan tambahan).
