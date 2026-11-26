# Panduan Detail Memahami dan Menjalankan DosQueue

Dokumen ini adalah panduan pendamping yang lebih rinci untuk mempelajari alur dan menguji DosQueue. Dokumen ini terpisah dari [`PANDUAN-PEMBUATAN-PROJECT.md`](./PANDUAN-PEMBUATAN-PROJECT.md), yang tetap berisi tahapan ringkas dan contoh implementasi.

## 1. Prasyarat dan Persiapan

Siapkan PHP 8.x, ekstensi `PDO` dan `pdo_mysql`, MySQL/MariaDB dengan InnoDB, browser modern, dan Git. Jalankan `php -v` serta `php -m` untuk memeriksa PHP dan modul database. Proyek ini PHP native tanpa Composer dan tanpa bundler frontend.

Folder kerja yang utama:

```text
actions/     endpoint request browser
assets/      CSS, ikon, font
config/      session dan koneksi database
database/    skema/dump SQL
includes/    auth, CSRF, helper, business logic
petugas/     login, dashboard, riwayat, logout
user/        halaman ambil dan pantau tiket
```

## 2. Pahami Model Data Sebelum Menjalankan

Database mempunyai enam tabel pokok:

- `users`: akun petugas/admin.
- `waves`: gelombang dan kuota.
- `rooms`: ruangan.
- `staff_assignments`: assignment petugas.
- `tickets`: tiket, urutan, status dan waktu.
- `call_logs`: catatan panggil dan panggil ulang.

Pelajari foreign key dan unique key di `database/antrian_db.sql`, bukan hanya nama kolom. Username, kode tiket, kombinasi tanggal/gelombang/nomor antrean, dan kombinasi assignment memiliki constraint unik. Tiket diarsipkan dengan `deleted_at`, bukan dihapus permanen.

**Perhatian sebelum import:** SQL dalam repository adalah dump project, bukan migrasi aman yang mempertahankan semua data. Baca file dan cek backup/database target sebelum mengimpor. Jalankan hanya pada database lokal yang boleh diubah. Jika perintah import gagal karena foreign key, periksa urutan pembuatan tabel dan kondisi database target; jangan menghapus constraint untuk memaksa import.

Data contoh berisi akun dan assignment pengembangan. Jangan memakai password contoh atau akun bawaan di server publik. Buat akun lokal yang aman dan ganti hash password melalui `password_hash()`.

## 3. Konfigurasi Koneksi: Source Saat Ini dan Saran

`config/app.php` menetapkan zona waktu `Asia/Jakarta` dan memulai session dengan opsi cookie. `config/database.php` membuat koneksi PDO, mengatur exception mode, associative fetch, native prepared statement, charset `utf8mb4`, serta zona waktu database `+07:00`.

**Penting untuk yang menjalankan source sekarang:** koneksi database dalam file saat ini memakai konfigurasi lokal langsung dan tidak membaca environment variable. Contoh perintah dengan `DOSQUEUE_DB_HOST`, `DOSQUEUE_DB_USER`, dan variabel lain pada panduan lama tidak mengubah konfigurasi tersebut. Sesuaikan koneksi lokal di file tersebut atau ubah implementasinya agar membaca environment variable. Jangan menaruh/membagikan password database dalam dokumen atau Git.

Untuk kerja tim/produksi, konfigurasi sebaiknya membaca variable lingkungan/secret manager, kredensial hanya memiliki hak minimal, error detail dicatat server-side, dan HTTPS diaktifkan. Ini adalah tindakan hardening; jangan menganggapnya sudah diterapkan hanya karena contoh konfigurasi memperlihatkannya.

## 4. Jalankan Aplikasi Lokal

1. Pastikan MySQL/MariaDB berjalan.
2. Siapkan database dan data lokal dengan mengikuti instruksi aman pada tahap sebelumnya.
3. Pastikan konfigurasi PDO mengarah ke database tersebut.
4. Dari root repository jalankan:

   ```sh
   php -S 127.0.0.1:8000
   ```

5. Buka `http://127.0.0.1:8000/`.
6. Jika koneksi gagal, periksa konfigurasi database aktual, nama database, hak user MySQL, service database, dan ekstensi `pdo_mysql`.

PHP built-in server hanya untuk development. Untuk produksi diperlukan web server yang sesuai, HTTPS, konfigurasi secret, backup, logging/monitoring, dan proses deployment yang aman.

## 5. Telusuri Alur per Fitur

### Pengunjung mengambil tiket

1. Buka `user/index.php`; daftar gelombang dimuat dari `actions/get-waves.php`.
2. Periksa respons JSON: gelombang aktif, kuota digunakan, kuota sisa, persentase pemakaian, dan tanggal layanan.
3. Pilih gelombang. Browser mengirim `wave_id` dan CSRF token ke `actions/create-ticket.php`.
4. Backend memvalidasi input lagi, mengecek tiket aktif perangkat, mengunci gelombang dalam transaksi, menghitung kuota dan nomor, memilih ruangan aktif, menyimpan `waiting`, lalu bind kode tiket ke session/cookie.
5. Buka hasil tiket dan lihat request berkala ke `actions/get-ticket-status.php`.

Ticket code dan display code berbeda: yang pertama disimpan dan digunakan mencari tiket; yang kedua menampilkan gelombang/ruangan/nomor. Nomor urut integer dan hanya diformat minimal tiga digit saat ditampilkan.

### Petugas mengelola tiket

1. Login di `petugas/login.php`. Password dibandingkan dengan hash menggunakan `password_verify()`.
2. Setelah login, server meregenerasi session ID. Role harus `petugas` atau `admin`.
3. Pilih assignment gelombang dan ruangan.
4. Dashboard meminta data dari `actions/get-staff-tickets.php` setiap empat detik. Tiket `waiting` dan `called` ada di tabel aktif; angka selesai tetap muncul di ringkasan.
5. Panggil mengubah `waiting` menjadi `called` dan menulis `call_logs`. Panggil ulang menambah log; selesai mengubah status menjadi `completed` dan mengisi waktu.
6. Tiket selesai muncul di `petugas/riwayat-antrian.php`, yang memakai tema/sidebar sama, assignment yang dipilih, refresh manual dan polling.
7. Arsip mengonfirmasi aksi lalu mengisi `deleted_at`. Baris database tidak dihapus permanen.

Tombol browser bukan keamanan. Uji endpoint secara langsung untuk memastikan role, assignment, CSRF, status tiket, dan ownership dicek backend.

## 6. Ringkasan Endpoint dan Jenis Request

| Endpoint | Request | Tujuan |
|---|---|---|
| `get-waves.php` | GET | Gelombang aktif dan hitungan kuota. |
| `create-ticket.php` | POST | Membuat tiket setelah validasi/CSRF. |
| `get-ticket-status.php` | GET | Membaca tiket dari kode tiket. |
| `release-guest-ticket.php` | GET/redirect | Menghapus ikatan tiket guest dari session/cookie. |
| `get-staff-tickets.php` | GET | Data tiket untuk assignment petugas yang tervalidasi. |
| `call-ticket.php` | POST | Memanggil tiket menunggu. |
| `recall-ticket.php` | POST | Mencatat panggilan ulang. |
| `complete-ticket.php` | POST | Menyelesaikan tiket yang sedang dipanggil. |
| `delete-ticket.php` | POST | Mengarsipkan tiket selesai secara soft delete. |

Untuk setiap endpoint periksa: metode yang diizinkan, validasi input, pemeriksaan session/role bila perlu, CSRF untuk perubahan, pemanggilan fungsi bisnis, HTTP status, serta bentuk JSON. Kode status umum: 400 input salah, 401 session tidak sah, 403 akses/CSRF ditolak, 404 data tidak ditemukan, 405 metode tidak diizinkan, 409 konflik status/kuota, 500 kesalahan server.

## 7. Cara Membaca Backend

`includes/functions.php` memuat aturan kuota, pembuatan tiket, status pengunjung, daftar dashboard, panggil, recall, selesai, arsip, dan binding tiket guest. `includes/auth.php` memproses login serta assignment. `includes/csrf.php` membuat dan memeriksa token. `includes/helpers.php` menyediakan fungsi lintas fitur. `config/database.php` mengatur PDO.

Prepared statement memisahkan nilai parameter dari teks SQL. Transaksi memberi atomicity untuk perubahan yang terkait: jika satu langkah gagal, rollback membatalkan perubahan sebelum commit. Unique constraint tetap penting sebagai penjaga akhir terhadap duplikasi. Transaksi bukan alasan untuk mengabaikan pengujian dua request bersamaan.

Status valid dibatasi oleh enum database:

```text
waiting → called → completed
```

Aksi yang tidak sesuai status harus ditolak server. Panggil ulang bukan transisi status baru: status tetap `called`, tetapi sebuah baris log baru dibuat.

## 8. Cara Membaca Frontend

- HTML/PHP menghasilkan markup dan memasukkan data awal yang diperlukan.
- CSS mengatur layout dan state visual.
- JavaScript memakai `fetch()` untuk GET/POST dan membaca JSON.
- `setInterval(..., 4000)` menjalankan polling; bukan koneksi real-time.
- `textContent` atau escaping harus dipakai agar teks data tidak menjadi markup.
- Web Speech API opsional; jika browser/perangkat tidak mendukung suara, status database tetap menjadi sumber kebenaran.

Saat debugging, gunakan DevTools Network untuk memeriksa URL, metode, status HTTP, payload, response JSON, dan request polling. Gunakan Console untuk error JavaScript. Jangan menampilkan password/token atau data sensitif dalam screenshot publik.

## 9. Skenario Pengujian Langkah demi Langkah

### Fungsional

- Gelombang aktif dan tersedia tampil; gelombang nonaktif tidak bisa dipakai.
- Kuota dihitung benar pada tanggal layanan yang sama.
- Kuota penuh menolak tiket; seluruh ruangan nonaktif tidak membuat tiket.
- Tiket baru memiliki status `waiting`, nomor, kode, tanggal, dan ruangan yang sesuai.
- Reload mempertahankan alur tiket pada perangkat yang sama.
- `waiting` bisa dipanggil; `called` dapat diulang dan diselesaikan; tiket completed tampil di Riwayat; hanya completed dapat diarsip.
- Pesan kosong tampil saat tidak ada tiket.

### Validasi dan otorisasi

- `wave_id` tidak ada, nol, string, atau ID nonaktif ditolak.
- Request GET ke endpoint POST-only dan sebaliknya menghasilkan 405.
- CSRF kosong/salah ditolak pada endpoint perubahan.
- Session petugas habis menghasilkan redirect di halaman atau 401 di endpoint.
- Petugas mencoba membaca/mengubah assignment orang lain: backend menolak.
- ID tiket tidak ditemukan, sudah diarsip, atau statusnya berubah: hasil konflik/404 yang sesuai.

### Database dan konkurensi

- Buat tiket bersamaan mendekati kuota terakhir; tidak ada kuota berlebih.
- Pastikan unique key mencegah kode dan nomor urut duplikat.
- Uji dua petugas memanggil tiket pada assignment yang sama pada waktu berdekatan.
- Arsip mengisi `deleted_at`, tiket hilang dari query operasional, call log tetap terhubung, dan nomor lama tidak digunakan kembali.
- Pastikan foreign key mencegah data parent yang masih direferensikan terhapus tanpa kebijakan yang benar.

### Browser dan ketahanan

- Polling memperbarui informasi setelah perubahan dari tab petugas lain.
- Jaringan putus/JSON invalid/database mati menghasilkan pesan/log yang sesuai.
- Uji layar kecil, tabel lebar, keyboard, fokus, dan kontras status.
- Uji karakter `<`, `>`, `"`, `'`, dan `&` pada data yang ditampilkan.
- Uji browser/perangkat tanpa `speechSynthesis`.

## 10. Batas Implementasi dan Pengembangan Selanjutnya

1. **Privasi status tiket:** endpoint status memakai kode yang bisa ditebak dan belum mewajibkan cookie/session pemilik. Tambahkan token acak serta pemeriksaan server-side bila data perlu privat.
2. **Identitas guest:** cookie/session membatasi perangkat, bukan satu orang. Untuk batasan identitas perlu mekanisme tambahan dengan pertimbangan data pribadi.
3. **Konkurensi panggilan:** kode memeriksa tiket `called` di transaksi, tetapi skema tidak memiliki constraint unik yang langsung membatasi satu baris `called` per assignment. Uji paralel dan pertimbangkan lock yang tepat.
4. **Riwayat:** halaman riwayat menampilkan completed untuk tanggal layanan aktif, bukan laporan lintas tanggal atau recycle bin; belum tersedia pemulihan arsip.
5. **Konfigurasi rahasia:** koneksi lokal disimpan dalam source; pindahkan ke environment/secret manager sebelum dibagikan atau diproduksi.
6. **Observability dan test automation:** tambahkan logging yang terstruktur, suite test, pemeriksaan regresi, dan uji beban sebelum menyebut aplikasi siap produksi.
7. **Real-time:** polling cocok untuk versi sederhana; evaluasi SSE/WebSocket hanya jika beban dan kebutuhan keterlambatan memang memerlukannya.

Untuk ringkasan jawaban QA dan detail model data, lihat [`PENJELASAN-PROJECT-DETAIL.md`](./PENJELASAN-PROJECT-DETAIL.md).
