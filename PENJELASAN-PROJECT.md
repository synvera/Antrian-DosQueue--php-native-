# Penjelasan Project DosQueue

Dokumen ini menjelaskan alasan pembuatan DosQueue, cara kerja API pada PHP native, lalu rancangan data dan hubungan antarentitasnya. Penjelasan mengikuti implementasi aplikasi di repository ini.

## 1. Masalah dan Tujuan Pembuatan Project

### Masalah yang ingin ditangani

Pengelolaan antrean secara manual dapat membuat pengunjung harus bertanya langsung kepada petugas untuk mengetahui giliran, sementara petugas perlu mencatat dan memanggil nomor secara berulang. Cara tersebut berisiko menimbulkan antrean yang sulit dipantau, nomor terlewat atau tercatat ganda, serta informasi status yang tidak segera diketahui pengunjung. Pengaturan jumlah layanan per sesi dan pembagian pengunjung ke beberapa ruangan juga lebih sulit jika hanya mengandalkan pencatatan manual.

DosQueue dibuat sebagai website antrean untuk menata proses tersebut dalam satu sistem. Pengunjung dapat melihat gelombang yang tersedia, mengambil tiket, dan memantau statusnya melalui perangkat. Petugas dapat melihat antrean sesuai penugasannya, memanggil atau memanggil ulang tiket, menyelesaikan pelayanan, lalu melihat dan mengarsipkan tiket selesai pada halaman Riwayat Antrian.

### Tujuan dan ruang lingkup

- Menyediakan nomor antrean dan status yang tersimpan terpusat di database.
- Membatasi jumlah tiket berdasarkan kuota gelombang dan tanggal pelayanan.
- Membagikan tiket ke ruangan aktif secara bergiliran (round-robin).
- Membantu petugas menjalankan antrean berdasarkan urutan nomor (FIFO) dan penugasan.
- Menyediakan pembaruan status berkala di browser tanpa memuat ulang seluruh halaman.
- Memisahkan halaman, endpoint pemrosesan, aturan bisnis, autentikasi, dan akses database agar alur aplikasi lebih mudah dipelihara.

### Jenis pengguna

| Pengguna | Tanggung jawab dalam sistem |
|---|---|
| Pengunjung | Melihat gelombang dan kuota, mengambil satu tiket aktif per perangkat per hari, lalu memantau tiket. |
| Petugas | Login, melihat antrean pada penugasan gelombang/ruangannya, memanggil, memanggil ulang, dan menyelesaikan tiket. |
| Admin | Menggunakan akses petugas dengan role admin; data akun, gelombang, ruangan, dan penugasan menjadi data pendukung operasional. |

## 2. Mengapa Menggunakan API dan Bagaimana Cara Kerjanya?

### Pengertian API pada DosQueue

API (Application Programming Interface) adalah alamat yang menerima permintaan dari browser dan menjalankan operasi tertentu di server. Pada project ini API dibuat dengan PHP native: file-file di `actions/` menerima request HTTP, membaca input, memanggil fungsi di `includes/functions.php`, berinteraksi dengan MySQL melalui PDO, kemudian mengirim hasil sebagai JSON.

API dipakai agar JavaScript di halaman dapat meminta atau mengubah data tanpa memuat ulang seluruh halaman. Contohnya, dashboard petugas dapat memperbarui daftar tiket secara berkala, sedangkan halaman pengunjung dapat menampilkan perubahan status tiket. API juga menjadi satu pintu untuk validasi dan aturan bisnis di server. Validasi tetap dilakukan di server karena data dari browser tidak boleh langsung dipercaya.

### Alur umum request API

1. Pengguna melakukan aksi pada halaman, misalnya memilih gelombang atau menekan tombol panggil.
2. JavaScript mengirim request HTTP ke endpoint di folder `actions/`, atau formulir mengirim request langsung ke endpoint tersebut.
3. Endpoint memeriksa metode request, data masukan, token CSRF untuk perubahan data, serta session/role/penugasan bila aksi dilakukan petugas.
4. Endpoint memanggil fungsi yang sesuai di `includes/functions.php`.
5. Fungsi bisnis mengakses database melalui `getDBConnection()` di `config/database.php`. Query menggunakan PDO prepared statement; proses yang mengubah beberapa data penting dapat dibungkus dalam transaksi.
6. Endpoint mengirim respons JSON berisi keberhasilan, pesan, data, dan kode status HTTP.
7. JavaScript membaca JSON dan memperbarui bagian halaman, atau menampilkan pesan jika request gagal.

```text
Pengguna → Halaman HTML/JavaScript → actions/*.php
         → validasi & autentikasi → includes/functions.php
         → PDO → MySQL/MariaDB
         ← JSON ← endpoint ← hasil pemrosesan
         → halaman diperbarui
```

### Contoh alur pengunjung: mengambil tiket

1. `user/index.php` meminta data gelombang dan kuota melalui `actions/get-waves.php`.
2. Pengunjung memilih gelombang. Browser mengirim `POST` dengan `wave_id` dan token CSRF ke `actions/create-ticket.php`.
3. Endpoint memastikan metode `POST`, token CSRF sah, perangkat belum memiliki tiket aktif hari itu, dan `wave_id` valid.
4. `createQueueTicket()` memeriksa gelombang dan kuota kembali di server. Pemeriksaan kuota dan penyimpanan tiket dilakukan dalam transaksi agar permintaan bersamaan tidak mudah melewati kuota.
5. Nomor berikutnya dihitung untuk gelombang dan tanggal layanan tersebut. Ruangan dipilih bergiliran dari ruangan aktif berdasarkan urutan ID.
6. Baris tiket berstatus `waiting` disimpan; kode tiket diikat ke session/cookie perangkat pengunjung.
7. API mengembalikan JSON dengan nomor dan kode tiket. Browser membuka halaman hasil tiket.

### Pembagian ruangan dengan round-robin

Round-robin adalah pola penjadwalan siklik: setiap giliran diberikan secara bergantian ke anggota dalam daftar, lalu urutan kembali ke anggota pertama. Artikel [Round-Robin Scheduling dari BINUS @Malang](https://binus.ac.id/malang/2021/11/round-robin-scheduling/) menjelaskan pola ini dalam konteks penjadwalan proses CPU, yaitu proses mendapat giliran berurutan dan siklus berulang. DosQueue menerapkan gagasan giliran bergantian yang sama untuk membagi **tiket ke ruangan aktif**. Jadi, di project ini round-robin tidak membagi waktu pelayanan atau menetapkan *time quantum* CPU.

Saat tiket dibuat, `createQueueTicket()` mengambil ruangan aktif menurut urutan ID, menghitung nomor antrean berikutnya untuk gelombang dan tanggal layanan tersebut, lalu menentukan indeks ruangan dengan rumus:

```text
indeks_ruangan = (nomor_antrean - 1) MOD jumlah_ruangan_aktif
```

`MOD` menghasilkan sisa pembagian, sehingga indeksnya berputar dari awal daftar setelah mencapai ruangan terakhir. Contohnya, jika ada tiga ruangan aktif berurutan A, B, dan C, tiket bernomor 1, 2, 3, 4, 5, 6 akan dibagikan ke A, B, C, A, B, C. Rumus yang sama diterapkan ulang untuk setiap kombinasi gelombang dan tanggal layanan karena nomor antrean dihitung dalam lingkup tersebut.

Pembagian ini menyeimbangkan **jumlah tiket yang dialokasikan** ke ruangan selama urutan tiket berjalan. Ini tidak menjamin waktu tunggu atau beban kerja aktual setiap ruangan sama, sebab durasi pelayanan dan ketersediaan petugas dapat berbeda. Setelah dialokasikan, tiket tetap dilayani oleh petugas sesuai penugasan ruangannya; urutan nomor pada antrean ruangan mengikuti aturan FIFO yang terpisah dari pembagian round-robin.

### Contoh alur pemantauan dan petugas

Halaman `user/ticket-result.php` memanggil `actions/get-ticket-status.php` secara berkala (sekitar setiap 4 detik). Server mencari tiket, status, ruangan, gelombang, serta informasi antrean terkait, lalu mengirimkannya sebagai JSON. JavaScript memperbarui status di halaman. Ini disebut *short polling*: browser bertanya berulang dalam jeda waktu tertentu; bukan koneksi real-time yang terus terbuka.

Pada sisi petugas, dashboard meminta daftar tiket melalui `actions/get-staff-tickets.php`. Untuk perubahan, endpoint `actions/call-ticket.php`, `actions/recall-ticket.php`, dan `actions/complete-ticket.php` memeriksa session petugas serta hak atas penugasan dan tiket, kemudian mengubah status/riwayat di database. Alur status normal adalah `waiting` → `called` → `completed`. Data JSON yang diterima dashboard digunakan untuk memperbarui tabel dan ringkasan.

### Endpoint yang digunakan

| Endpoint | Fungsi |
|---|---|
| `actions/get-waves.php` | Mengambil gelombang aktif beserta pemakaian dan sisa kuota. |
| `actions/create-ticket.php` | Membuat tiket untuk gelombang terpilih. |
| `actions/get-ticket-status.php` | Mengambil status dan rincian pemantauan tiket. |
| `actions/release-guest-ticket.php` | Melepas ikatan tiket aktif dari perangkat pengunjung. |
| `actions/get-staff-tickets.php` | Mengambil tiket pada gelombang/ruangan yang ditugaskan kepada petugas. |
| `actions/call-ticket.php` | Memanggil tiket yang menunggu. |
| `actions/recall-ticket.php` | Memanggil ulang tiket yang sudah dipanggil. |
| `actions/complete-ticket.php` | Menandai pelayanan tiket selesai. |
| `actions/delete-ticket.php` | Mengarsipkan tiket selesai (soft delete). |

### Lokasi kode terkait API

| Lokasi | Peran |
|---|---|
| `actions/` | Endpoint HTTP dan pembentukan respons JSON. |
| `includes/functions.php` | Query serta aturan bisnis gelombang, tiket, antrean, dan log panggilan. |
| `includes/auth.php` | Session login, pemeriksaan role, dan pemeriksaan penugasan petugas. |
| `includes/csrf.php` | Pembuatan dan verifikasi token CSRF pada permintaan perubahan data. |
| `includes/helpers.php` | Helper respons JSON, tanggal, escaping, dan redirect. |
| `config/database.php` | Pembuatan koneksi PDO ke MySQL/MariaDB. |

## 3. Database: Entitas, Atribut, dan Relasi

Database menyimpan data agar tiket, kuota, status, dan akses petugas tetap konsisten walaupun halaman dibuka dari perangkat berbeda. Entitas adalah kelompok data yang umumnya diwujudkan sebagai tabel. Atribut adalah kolom pada tabel. Relasi menunjukkan keterkaitan antarbaris tabel, umumnya melalui primary key dan foreign key.

> **Skema dan data database:** file [`database/antrian_db.sql`](./database/antrian_db.sql) membuat database `antrian_db`, enam tabel yang digunakan aplikasi, indeks dan foreign key, lalu memulihkan data dari ekspor database project. Skrip menjatuhkan dan membuat ulang tabel yang sama; backup database sebelum mengimpornya.

### Entitas dan atribut

| Entitas | Atribut yang digunakan/dibutuhkan | Keterangan |
|---|---|---|
| `users` | `id`, `name`, `username`, `password`, `role`, `is_active` | Akun petugas/admin. `password` sebaiknya menyimpan hash, bukan kata sandi asli. `role` membedakan hak akses; `is_active` menentukan apakah akun dapat digunakan. |
| `waves` | `id`, `wave_name`, `start_time`, `end_time`, `quota`, `is_active` | Sesi/gelombang pelayanan, waktu sesi, batas tiket, dan status ketersediaan. |
| `rooms` | `id`, `room_name`, `is_active` | Daftar ruang pelayanan yang dapat menerima pembagian tiket. |
| `staff_assignments` | `id`, `user_id`, `wave_id`, `room_id` | Penugasan petugas ke kombinasi gelombang dan ruangan. Kombinasi tersebut digunakan untuk membatasi data yang dapat dikelola petugas. |
| `tickets` | `id`, `ticket_code`, `queue_number`, `service_date`, `wave_id`, `room_id`, `status`, `created_at`, `called_at`, `completed_at`, `deleted_at` | Satu tiket antrean. `deleted_at` menandai arsip secara soft delete; kolom waktu status mencatat tahapan pelayanan. |
| `call_logs` | `id`, `ticket_id`, `user_id`, `called_at`, `call_number` | Riwayat pemanggilan tiket, termasuk petugas, waktu, dan nomor pemanggilan. |

`id` pada masing-masing entitas berperan sebagai primary key. Kolom `user_id`, `wave_id`, `room_id`, dan `ticket_id` menjadi foreign key yang menghubungkan record terkait. Nilai status tiket yang dipakai aplikasi adalah `waiting`, `called`, dan `completed`.

### Relasi antarentitas

| Relasi | Kardinalitas | Makna |
|---|---|---|
| `waves` → `tickets` | Satu gelombang memiliki banyak tiket; satu tiket berada pada satu gelombang. | `tickets.wave_id` mengacu ke `waves.id`. Kuota dihitung per gelombang dan tanggal layanan. |
| `rooms` → `tickets` | Satu ruangan dapat melayani banyak tiket; satu tiket dialokasikan ke satu ruangan. | `tickets.room_id` mengacu ke `rooms.id`. |
| `users` → `staff_assignments` | Satu petugas dapat memiliki beberapa penugasan; satu penugasan dimiliki satu petugas. | `staff_assignments.user_id` mengacu ke `users.id`. |
| `waves` → `staff_assignments` | Satu gelombang dapat muncul pada banyak penugasan. | `staff_assignments.wave_id` mengacu ke `waves.id`. |
| `rooms` → `staff_assignments` | Satu ruangan dapat muncul pada banyak penugasan. | `staff_assignments.room_id` mengacu ke `rooms.id`. |
| `tickets` → `call_logs` | Satu tiket dapat memiliki beberapa catatan pemanggilan; setiap log terkait satu tiket. | `call_logs.ticket_id` mengacu ke `tickets.id`; log bertambah saat panggil atau panggil ulang. |
| `users` → `call_logs` | Satu petugas dapat membuat banyak catatan pemanggilan. | `call_logs.user_id` mengacu ke `users.id`. |

Dengan demikian, `staff_assignments` menghubungkan petugas, gelombang, dan ruangan sebagai relasi penugasan. Tiket terhubung langsung ke satu gelombang dan satu ruangan, sedangkan log panggilan menyimpan kejadian yang berhubungan dengan tiket dan petugas.

### Aturan data yang penting

- Kuota dihitung dari tiket pada tanggal layanan dan gelombang yang sama; tiket yang diarsipkan tidak masuk hitungan operasional.
- Nomor antrean berjalan berdasarkan gelombang dan tanggal pelayanan.
- Pembagian ruangan menggunakan daftar ruangan aktif secara berurutan dan berulang.
- Pengarsipan mengisi `deleted_at`; record tiket tidak dihapus secara permanen.
- Relasi sebaiknya dilengkapi indeks dan foreign key. Untuk mencegah duplikasi nomor pada kondisi request bersamaan, skema perlu menetapkan constraint unik yang sesuai, misalnya pada kombinasi `service_date`, `wave_id`, dan `queue_number`.

## Struktur Project yang Relevan

| Lokasi | Isi |
|---|---|
| `index.php` | Halaman awal/pengarah pengunjung. |
| `user/` | Halaman pilihan gelombang dan hasil tiket. |
| `petugas/` | Login, dashboard operasional, riwayat antrian tiket selesai, dan logout petugas. |
| `actions/` | Endpoint API berbasis file PHP. |
| `includes/functions.php` | Fungsi pemrosesan dan aturan antrean. |
| `includes/auth.php`, `includes/csrf.php`, `includes/helpers.php` | Autentikasi, perlindungan CSRF, dan fungsi bantuan. |
| `config/app.php`, `config/database.php` | Konfigurasi aplikasi dan koneksi database. |
| `database/antrian_db.sql` | Skrip untuk membuat database dan memulihkan tabel beserta data ekspor DosQueue. |
| `assets/` | CSS, ikon, font, dan aset halaman. |

## Teknologi dan Perlindungan

- PHP native tanpa framework Laravel, dengan PDO untuk koneksi database dan prepared statements.
- MySQL/MariaDB sebagai penyimpanan relasional.
- JavaScript `fetch` dan JSON untuk komunikasi halaman dengan endpoint.
- Session untuk login petugas dan pengikatan tiket pengunjung ke perangkat; aplikasi memakai zona waktu `Asia/Jakarta`.
- Token CSRF untuk request yang mengubah data; `password_verify()` untuk memeriksa hash kata sandi.
- Pemeriksaan role, penugasan, status tiket, kuota, dan transaksi dilakukan di server.
- Web Speech API dipakai browser untuk membacakan panggilan jika didukung perangkat.
