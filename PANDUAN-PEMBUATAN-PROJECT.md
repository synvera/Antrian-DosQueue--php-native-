# Panduan Pembuatan DosQueue dari Awal

Panduan ini menjelaskan urutan praktis untuk membangun aplikasi antrean seperti DosQueue dari proyek kosong sampai siap diuji. Isinya disusun dari struktur dan perilaku source code yang tersedia saat ini; ini bukan klaim tentang urutan historis penulisan setiap file.

## 1. Siapkan lingkungan

Perangkat yang diperlukan:

- PHP 8.x (PHP 7.3+ sebaiknya menjadi batas minimum karena konfigurasi cookie session menggunakan opsi berbentuk array).
- Ekstensi PHP `PDO` dan `pdo_mysql`.
- MySQL atau MariaDB yang mendukung transaksi InnoDB.
- Browser modern. Fitur pembacaan nomor antrean menggunakan Web Speech API browser.

Proyek ini menggunakan PHP native dan tidak memiliki `composer.json` maupun langkah build frontend.

## 2. Rancang alur dan struktur

Tentukan tiga status tiket: `waiting` (menunggu), `called` (sedang dipanggil), dan `completed` (selesai). Alur utamanya:

1. Pengunjung memilih gelombang yang kuotanya masih tersedia.
2. Server memvalidasi kuota, membuat nomor antrean dan kode tiket, lalu memilih ruangan aktif secara bergiliran.
3. Halaman hasil tiket memantau status tiket secara berkala.
4. Petugas yang sudah login melihat tiket sesuai penugasan gelombang dan ruangannya.
5. Petugas memanggil tiket, dapat memanggil ulang, lalu menandai pelayanan selesai.
6. Tiket selesai dapat diarsipkan dari daftar operasional.

Struktur file yang digunakan:

```text
actions/       Endpoint JSON untuk tiket dan operasi petugas
assets/css/    Gaya halaman pengunjung dan hasil tiket
config/        Konfigurasi aplikasi, session, dan koneksi database
database/      Skrip skema database
includes/      Autentikasi, CSRF, helper, dan logika antrean
petugas/       Login, dashboard, dan logout
user/          Halaman pilih gelombang dan hasil tiket
index.php      Pengarah ke halaman pengunjung
```

## 3. Buat database

Buat database bernama `antrian_db`, kemudian siapkan tabel yang dibutuhkan oleh kode aplikasi. Berkas `database/antrian_db.sql` pada proyek saat ini masih kosong, jadi skema belum dapat dibuat hanya dengan mengimpor berkas tersebut.

Contoh skema awal yang sesuai dengan nama tabel dan kolom yang dipakai aplikasi:

```sql
CREATE DATABASE antrian_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE antrian_db;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE waves (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wave_name VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    quota INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE staff_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    wave_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_assignment_user (user_id, is_active),
    CONSTRAINT fk_assignment_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_assignment_wave FOREIGN KEY (wave_id) REFERENCES waves(id),
    CONSTRAINT fk_assignment_room FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB;

CREATE TABLE tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_code VARCHAR(80) NOT NULL UNIQUE,
    queue_number INT UNSIGNED NOT NULL,
    service_date DATE NOT NULL,
    wave_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    status ENUM('waiting', 'called', 'completed') NOT NULL DEFAULT 'waiting',
    created_at DATETIME NOT NULL,
    called_at DATETIME NULL,
    completed_at DATETIME NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_ticket_sequence (service_date, wave_id, queue_number),
    INDEX idx_ticket_staff (service_date, wave_id, room_id, status, deleted_at),
    CONSTRAINT fk_ticket_wave FOREIGN KEY (wave_id) REFERENCES waves(id),
    CONSTRAINT fk_ticket_room FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB;

CREATE TABLE call_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    called_at DATETIME NOT NULL,
    call_number INT UNSIGNED NOT NULL,
    INDEX idx_call_ticket (ticket_id, call_number),
    CONSTRAINT fk_call_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id),
    CONSTRAINT fk_call_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
```

Skema ini merupakan titik awal yang mengikuti query aplikasi; tinjau dan sesuaikan sebelum dipakai di produksi. Siapkan juga data master berupa gelombang, ruangan, akun petugas, dan baris penugasan `staff_assignments` yang aktif. Role yang dikenali untuk dashboard adalah `petugas` dan `admin`.

Password akun harus disimpan sebagai hash yang dihasilkan `password_hash()` PHP, bukan teks biasa. Contoh membuat hash lewat terminal:

```sh
php -r '$password = readline("Password petugas: "); echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;'
```

Masukkan hasil hash tersebut ke kolom `users.password`, lalu buat penugasan untuk kombinasi petugas, gelombang, dan ruangan yang memang boleh diakses.

## 4. Sambungkan konfigurasi dan helper

1. Inisialisasi zona waktu dan session di `config/app.php`.
2. Buat koneksi PDO MySQL di `config/database.php`; aktifkan exception mode, prepared statement native, dan charset `utf8mb4`.
3. Isi kredensial database lokal sesuai lingkungan. Saat ini nilai koneksi ditulis langsung di file konfigurasi; jangan gunakan atau publikasikan kredensial lokal sebagai kredensial produksi.
4. Tambahkan helper untuk escaping HTML, redirect, tanggal layanan, dan respons JSON.
5. Tambahkan CSRF token untuk form dan request perubahan data.

## 5. Bangun autentikasi dan logika inti

Di `includes/auth.php`, implementasikan login memakai `password_verify()`, validasi status akun, regenerasi ID session setelah login, pembatasan halaman/API berdasarkan role, validasi penugasan, serta logout.

Di `includes/functions.php`, pisahkan logika database dari halaman:

- Menghitung pemakaian dan sisa kuota setiap gelombang pada tanggal layanan.
- Membuat tiket dalam transaksi, memeriksa kuota lagi di server, dan menolak jika tidak ada ruangan aktif.
- Menentukan urutan ruangan aktif berdasarkan ID; nomor tiket bergilir di antara ruangan tersebut.
- Membentuk kode tiket dan nomor tampilan.
- Menghitung tiket yang sedang dipanggil dan jumlah antrean di depan tiket.
- Memuat data dashboard hanya untuk penugasan petugas yang valid.
- Menangani panggil, panggil ulang, selesai, dan arsip tiket.
- Mengikat tiket aktif ke session/cookie perangkat pengunjung.

Gunakan prepared statements untuk input database dan transaksi untuk operasi yang harus konsisten, khususnya pembuatan dan perubahan status tiket.

## 6. Buat endpoint aplikasi

Endpoint di folder `actions/` menjadi penghubung halaman dengan logika inti:

- `get-waves.php` dan `create-ticket.php` untuk pilihan gelombang dan pembuatan tiket.
- `get-ticket-status.php` untuk pembaruan status tiket pengunjung.
- `get-staff-tickets.php` untuk data dashboard petugas.
- `call-ticket.php`, `recall-ticket.php`, `complete-ticket.php`, dan `delete-ticket.php` untuk aksi petugas.
- `release-guest-ticket.php` untuk melepaskan penanda tiket aktif pada perangkat.

Batasi metode HTTP, validasi parameter, dan periksa CSRF pada request POST. Endpoint petugas juga harus memeriksa session serta penugasan, bukan hanya mengandalkan tombol yang ditampilkan di browser.

## 7. Buat halaman pengunjung

Di `user/index.php`, tampilkan gelombang aktif, jam sesi, dan kuota. Saat pengunjung memilih gelombang, kirim `wave_id` ke endpoint pembuatan tiket dan arahkan ke halaman hasil dengan kode tiket.

Di `user/ticket-result.php`, tampilkan kode dan status tiket, ruangan, gelombang, nomor yang sedang dilayani, serta jumlah antrean di depan jika status masih menunggu. Poll status secara berkala; saat dipanggil tampilkan pemberitahuan, dan saat selesai tampilkan tampilan tiket grayscale serta opsi kembali mengambil tiket.

`index.php` pada root mengarahkan pengunjung ke halaman pilih gelombang.

## 8. Buat halaman petugas

1. Buat `petugas/login.php` dan lindungi `petugas/dashboard.php` dengan autentikasi.
2. Tampilkan identitas akun dan daftar penugasan aktif petugas.
3. Sediakan pilihan gelombang/ruangan, ringkasan status, tabel tiket aktif, tabel tiket selesai, refresh manual, dan tes suara.
4. Tampilkan aksi sesuai status: `Panggil` untuk `waiting`; `Panggil Ulang` dan `Selesai` untuk `called`; arsipkan hanya tiket `completed`.
5. Setelah panggilan berhasil, bacakan nomor antrean dengan Web Speech API bila browser mendukungnya.
6. Buat `petugas/logout.php` untuk mengakhiri session.

## 9. Tambahkan gaya dan interaksi

Gunakan CSS untuk membedakan antrean menunggu, dipanggil, selesai, kuota tersedia, dan kuota penuh. Pastikan tabel dan tombol mudah digunakan saat operasional serta tetap terbaca di layar kecil. Gaya yang ada saat ini berada di `assets/css/index-guest.css` dan `assets/css/ticket-result.css`; dashboard petugas masih berupa HTML dasar.

## 10. Jalankan secara lokal

Pastikan MySQL/MariaDB berjalan, database dan data master sudah tersedia, serta koneksi PDO di konfigurasi benar. Dari direktori root proyek, jalankan:

```sh
php -S 127.0.0.1:8000
```

Buka `http://127.0.0.1:8000/`. Jika ada kegagalan koneksi, periksa nama database, user/password lokal, server database, dan ekstensi `pdo_mysql`.

## 11. Uji dari awal sampai selesai

- Halaman awal mengarahkan ke pemilihan gelombang; daftar gelombang dan status kuota berhasil dimuat.
- Tiket dapat dibuat untuk gelombang aktif; kuota penuh atau gelombang nonaktif ditolak oleh server.
- Tiket pada perangkat yang sama tidak dapat diambil berulang kali selama masih aktif pada hari yang sama.
- Kode tiket menampilkan gelombang dan ruangan yang telah ditetapkan.
- Halaman hasil memperbarui status; jumlah antrean di depan berkurang saat tiket lain dipanggil atau selesai.
- Login petugas berhasil, dan petugas hanya dapat membaca serta mengubah tiket dari penugasannya.
- Satu tiket yang sedang dipanggil harus diselesaikan sebelum tiket berikutnya dapat dipanggil.
- Panggil ulang menambah catatan/jumlah panggilan; aksi selesai memindahkan tiket ke daftar selesai.
- Arsip meminta konfirmasi dan menyembunyikan tiket dari daftar operasional tanpa menghapus barisnya secara permanen.
- Uji pembacaan suara di browser/perangkat yang akan dipakai; fitur suara bergantung pada dukungan browser dan pengaturan audio.

## 12. Siapkan untuk penggunaan nyata

Sebelum dipublikasikan, isi skema database yang saat ini kosong, pindahkan rahasia koneksi dari source code ke konfigurasi lingkungan yang tidak dipublikasikan, gunakan HTTPS, buat akun dan penugasan resmi, serta uji skenario bersamaan dan pencadangan database. Jangan menganggap server PHP bawaan cocok sebagai server produksi.
