# Penjelasan Mendalam Project DosQueue

Dokumen ini adalah bahan belajar dan persiapan tanya jawab QA tentang implementasi DosQueue. Isinya menjelaskan alur aplikasi, keputusan teknis, database, endpoint, keamanan, pengujian, serta batasan yang perlu disebutkan dengan jujur. Dokumen ini melengkapi [`PENJELASAN-PROJECT.md`](./PENJELASAN-PROJECT.md), bukan menggantikannya.

> **Cara membaca:** bedakan fitur yang memang sudah diterapkan di source, tujuan desain, dan saran pengembangan. Contoh yang belum ada jangan dipresentasikan seolah-olah sudah berjalan.

## 1. Ringkasan Sistem

DosQueue adalah aplikasi antrean berbasis PHP native. Pengunjung dapat melihat gelombang layanan, mengambil tiket, dan memantau status. Petugas login untuk melayani tiket berdasarkan penugasan gelombang dan ruangan. Tiket memiliki tiga status: `waiting`, `called`, dan `completed`.

```text
Pengunjung
  ├─ GET gelombang dan kuota
  ├─ POST pembuatan tiket
  └─ GET status tiket berkala

Petugas
  ├─ login dan memilih penugasan
  ├─ GET antrean aktif berkala
  ├─ POST panggil / panggil ulang / selesai
  └─ membuka Riwayat Antrian dan mengarsipkan tiket selesai

Browser → file PHP di actions/ → fungsi bisnis → PDO → MySQL/MariaDB
Browser ← JSON dari endpoint ← hasil query/perubahan
```

PHP mengirim halaman HTML. JavaScript di browser memakai `fetch()` untuk meminta atau mengubah data tanpa memuat ulang seluruh halaman. Endpoint PHP memvalidasi request, menjalankan aturan di `includes/functions.php`, dan mengirim JSON. MySQL menjadi sumber data bersama sehingga tiket yang dibuat pengunjung dapat terlihat oleh petugas.

## 2. Jawaban Pertanyaan Arsitektur

### Mengapa ada sisi pengunjung dan sisi petugas?

Kebutuhan pengunjung berbeda dari kebutuhan operator. Pengunjung hanya perlu mengambil dan memantau tiket; petugas perlu menjalankan panggilan dan perubahan status. Pemisahan halaman dan autentikasi mengurangi pencampuran fungsi, tetapi keamanan tetap harus ditegakkan pada backend—menyembunyikan tombol saja tidak cukup.

### Mengapa PHP native, bukan framework?

PHP native dipilih agar alur HTTP, session, SQL, dan pemisahan tanggung jawab terlihat langsung untuk tujuan belajar. Keuntungannya sedikit dependensi dan mudah mengikuti eksekusi. Kekurangannya, routing, validasi, otorisasi, logging, migrasi, pengujian, dan pola arsitektur perlu dibuat dan dipelihara sendiri. Framework dapat menyediakan struktur dan komponen siap pakai, tetapi tetap perlu dipahami dan diamankan.

### Apa fungsi tiap folder?

| Folder/file | Tanggung jawab |
|---|---|
| `config/` | Inisialisasi aplikasi/session dan koneksi database. |
| `includes/` | Helper umum, autentikasi, CSRF, dan aturan bisnis. |
| `actions/` | Endpoint HTTP untuk membaca/mengubah data; mayoritas mengirim JSON. |
| `user/` | Halaman untuk memilih gelombang dan memantau tiket. |
| `petugas/` | Login, dashboard operasional, riwayat tiket selesai, dan logout. |
| `assets/` | CSS, ikon, font, dan aset tampilan. |
| `database/` | SQL untuk skema dan data project. |

Jika seluruh kode diletakkan dalam satu file, tampilan, validasi, query, dan aturan bisnis akan bercampur. Perubahan kecil lebih sulit ditinjau, fungsi cenderung diduplikasi, dan pengujian bagian tertentu menjadi lebih sulit.

### Apakah project memakai API?

Ya. File PHP dalam `actions/` adalah endpoint HTTP sederhana yang berfungsi sebagai API internal untuk halaman web. Ini bukan API framework terpisah. GET digunakan untuk membaca data seperti gelombang, status tiket, dan data dashboard; POST digunakan untuk pembuatan tiket dan aksi petugas. Respons JSON dibuat konsisten melalui helper `jsonResponse()`.

Satu endpoint dibuat untuk satu operasi/aturan bisnis, bukan selalu satu endpoint untuk setiap tombol. Tombol panggil dan panggil ulang memakai endpoint berbeda karena validasi status dan pencatatan log-nya berbeda. Refresh dashboard dan riwayat menggunakan endpoint pembacaan tiket yang sama dengan parameter penugasan.

## 3. Alur Pengunjung dan Aturan Kuota

### Dari halaman awal sampai tiket terbit

1. `index.php` mengarahkan pengunjung ke `user/index.php`.
2. Halaman meminta `actions/get-waves.php`.
3. `getActiveWavesWithQuota()` mengambil gelombang aktif dan menghitung tiket nonarsip pada tanggal layanan.
4. Browser menampilkan kuota. Saat gelombang dipilih, browser mengirim `POST` berisi `wave_id` dan token CSRF ke `actions/create-ticket.php`.
5. Backend memeriksa metode request, token CSRF, input, tiket aktif yang sudah terikat pada perangkat, status gelombang, kuota, dan ketersediaan ruangan.
6. Backend membuat nomor, memilih ruangan, dan menyimpan tiket `waiting` dalam transaksi.
7. Kode tiket disimpan pada session/cookie perangkat, lalu respons JSON mengarahkan browser ke halaman hasil tiket.
8. `user/ticket-result.php` meminta status secara berkala ke `actions/get-ticket-status.php`.

### Bagaimana kuota dihitung?

Kuota digunakan oleh tiket `waiting`, `called`, maupun `completed` selama `deleted_at IS NULL`, pada tanggal layanan dan gelombang yang sama. Tiket selesai tetap menggunakan kuota karena tiket itu sudah diterbitkan untuk sesi tersebut. Pemanggilan atau penyelesaian tidak mengembalikan kuota.

Jumlah yang terlihat di browser bukan sumber kebenaran. Jika gelombang penuh setelah halaman dibuka, `createQueueTicket()` memeriksa ulang kuota pada server di dalam transaksi. Request yang kalah pada persaingan kuota harus gagal, bukan menciptakan tiket tambahan.

Arsip mengisi `deleted_at`. Query kuota mengecualikan tiket arsip, jadi kuota operasionalnya tidak lagi terpakai. Namun nomor yang pernah diterbitkan tidak digunakan ulang: penghitungan nomor berikutnya memakai nomor maksimum tiket tanggal/gelombang, termasuk baris arsip.

### Apakah pengunjung perlu login?

Tidak ada akun pengunjung. Aplikasi mengikat satu tiket aktif pada session dan cookie perangkat untuk membatasi pengambilan ulang pada perangkat yang sama. Ini bukan identitas orang yang kuat: pengguna dapat menghapus cookie atau memakai browser/perangkat berbeda.

Kode tiket disimpan di perangkat agar alur dapat dilanjutkan setelah refresh. Akan tetapi, endpoint status saat ini mencari tiket berdasarkan kode dan tidak mensyaratkan cookie/session pemilik. Kode tiket berurutan dan mudah ditebak; karena itu jangan menganggapnya sebagai rahasia yang mengamankan data. Peningkatan yang disarankan adalah token akses acak berentropi tinggi dan pemeriksaan kepemilikan di backend.

## 4. Nomor Antrean dan Distribusi Ruangan

### Nomor dan kode

`queue_number` adalah integer untuk perbandingan dan pengurutan numerik. Nomor dihitung sebagai nilai maksimum pada tanggal/gelombang yang sama ditambah satu. Saat ditampilkan, angkanya dipadatkan minimal tiga digit: `1` menjadi `001`, `12` menjadi `012`.

Contoh:

- `queue_number`: `12`
- `ticket_code`: `G1-20261126-012` (ID gelombang, tanggal layanan, nomor)
- `display_code`: `G1-R3-012` (gelombang, ID ruangan, nomor; dibuat untuk tampilan/panggilan)

Tanggal layanan menjadi bagian konteks nomor karena penomoran dimulai lagi pada tanggal berikutnya. Database memiliki unique constraint untuk `ticket_code` dan kombinasi `(service_date, wave_id, queue_number)`.

### Bagaimana mencegah nomor ganda saat request bersamaan?

Pembuatan tiket menggunakan transaksi dan mengambil lock baris gelombang (`SELECT ... FOR UPDATE`) sebelum menghitung kuota/nomor. Unique constraint gabungan pada database menjadi perlindungan tambahan. Perilaku konkurensi tetap perlu diuji pada versi MySQL/MariaDB, engine, isolation level, dan pola request yang digunakan.

### Round Robin

Ruangan aktif diambil dan diurutkan menurut ID. Index ruangan dipilih dengan:

```text
roomIndex = (queue_number - 1) MOD jumlah_ruangan_aktif
```

Dengan tiga ruangan aktif, urutan tiket 1–12 adalah R1, R2, R3, R1, R2, R3, R1, R2, R3, R1, R2, R3. Rumus memilih berdasarkan nomor urut, bukan jumlah tiket yang sedang menunggu. Jika ruangan dinonaktifkan, tiket yang sudah dibuat tidak dipindahkan; tiket berikutnya menggunakan daftar ruangan aktif terbaru sehingga pola rotasi dapat berubah.

## 5. Database dan Relasi

| Tabel | Penjelasan |
|---|---|
| `users` | Akun petugas/admin, username, hash password, role, dan status aktif. |
| `waves` | Gelombang pelayanan, waktu mulai/selesai, kuota, dan status aktif. |
| `rooms` | Ruangan tujuan dan status aktif. |
| `staff_assignments` | Relasi user dengan satu kombinasi gelombang dan ruangan. |
| `tickets` | Data tiket, nomor urut, tanggal layanan, gelombang, ruangan, status, timestamp, dan penanda arsip. |
| `call_logs` | Setiap pemanggilan, petugas yang melakukan, waktu, dan nomor pemanggilan. |

### Relasi dan constraint

- Primary key `id` mengidentifikasi baris secara unik.
- Foreign key menghubungkan tiket ke gelombang/ruangan; assignment ke user/gelombang/ruangan; dan log ke tiket/user.
- `users.username` unik agar satu username tidak dipakai dua akun.
- `tickets.ticket_code` unik untuk pencarian kode.
- `(service_date, wave_id, queue_number)` unik untuk mencegah nomor urut yang sama dalam gelombang dan tanggal yang sama.
- `(user_id, wave_id, room_id)` pada assignment unik agar kombinasi penugasan tidak ganda.
- Index mempercepat pencarian yang berulang, misalnya filter tiket berdasar tanggal/gelombang/ruangan/status.

`call_logs` dipisah agar riwayat panggilan berulang tersimpan lengkap, bukan hanya menyimpan waktu panggilan terakhir pada tiket. Jika satu tiket dipanggil tiga kali, status tiket tetap satu baris, sedangkan log dapat mencatat call number 1, 2, dan 3.

`called_at`, `completed_at`, dan `deleted_at` nullable karena kejadian tersebut belum tentu terjadi. Arsip adalah soft delete: baris tiket tidak dihapus, tetapi query operasional yang memeriksa `deleted_at IS NULL` tidak lagi menampilkannya. Menghapus permanen dapat menghilangkan jejak audit dan terhalang foreign key.

Skema menggunakan unsigned `INT` untuk sejumlah ID master dan unsigned `BIGINT` untuk ID tiket/log. `VARCHAR` menyimpan teks berkepanjangan variabel; `DATE` untuk tanggal layanan; `TIME` untuk waktu gelombang; `DATETIME` untuk waktu kejadian. Di MySQL, `BOOLEAN` adalah alias tipe integer kecil; flag pada SQL memakai `TINYINT(1)`.

## 6. PHP, PDO, dan Penanganan Request

PDO adalah lapisan akses database PHP. Koneksi menggunakan exception mode, associative fetch, charset `utf8mb4`, dan native prepared statements. Prepared statement memisahkan struktur query dari nilai input. Ini mencegah nilai user ditafsirkan sebagai sintaks SQL pada query parameterized; placeholder tidak dapat dipakai untuk nama tabel/kolom dinamis.

Input browser tetap tidak dipercaya:

- Query string dibaca dari `$_GET` atau `filter_input(INPUT_GET, ...)`.
- Form POST dibaca dari `$_POST` atau `filter_input(INPUT_POST, ...)`.
- JSON mentah dapat dibaca dari `php://input`.
- Server memvalidasi tipe, rentang, status, kepemilikan, dan hak akses.

`includes/functions.php` menjalankan aturan bisnis/data. `includes/helpers.php` menyediakan fungsi umum seperti escaping, JSON response, redirect, atau tanggal layanan. File endpoint memeriksa metode/input/otorisasi, memanggil fungsi bisnis, lalu mengirim respons.

Operasi perubahan menggunakan transaksi agar perubahan terkait dapat commit bersama atau rollback. Membuat tiket memvalidasi gelombang/kuota/ruangan sebelum insert. Aksi petugas memeriksa kembali status tiket dan assignment sebelum update. Untuk masalah database, detail exception dicatat oleh beberapa operasi ke error log, sedangkan browser menerima pesan umum.

Kode status HTTP penting:

| Status | Makna umum |
|---|---|
| 400 | Input/parameter tidak valid. |
| 401 | Session petugas API tidak aktif. |
| 403 | CSRF atau hak akses tidak valid. |
| 404 | Tiket tidak ditemukan. |
| 405 | Metode HTTP tidak diizinkan. |
| 409 | Konflik aturan, seperti kuota penuh atau status berubah. |
| 500 | Kegagalan server yang tidak dapat diproses sebagai request normal. |

## 7. Petugas dan Transisi Status

Login mengambil user berdasarkan username, memeriksa hash dengan `password_verify()`, menolak akun nonaktif, lalu meregenerasi session ID. Halaman dan endpoint petugas memeriksa role `petugas` atau `admin`. Fungsi bisnis juga memeriksa penugasan user terhadap gelombang/ruangan tiket; mengubah parameter di browser tidak memberikan hak tambahan.

| Aksi | Status yang diizinkan | Dampak |
|---|---|---|
| Panggil | `waiting` | Menjadi `called`, mencatat log pertama, menyusun teks panggilan. |
| Panggil ulang | `called` | Status tetap `called`, log baru dengan nomor panggilan bertambah. |
| Selesai | `called` | Menjadi `completed`, mengisi `completed_at`. |
| Arsip | `completed` | Mengisi `deleted_at`, bukan menghapus baris permanen. |

Server memeriksa agar tidak memanggil tiket berikutnya saat masih ada tiket `called` pada penugasan yang sama. Dashboard menonaktifkan tombol panggil sebagai bantuan UX, tetapi pengecekan backend tetap diperlukan karena request dapat dikirim tanpa tombol.

Tiket selesai hilang dari tabel aktif dan muncul otomatis di `petugas/riwayat-antrian.php`. Dashboard masih menampilkan jumlah tiket selesai pada kartu ringkasan. Halaman riwayat saat ini memakai tanggal layanan aktif, polling empat detik, pilihan penugasan, refresh manual, dan aksi arsip dengan konfirmasi; ini bukan laporan historis lintas tanggal.

Role `admin` tidak memiliki bypass assignment di fungsi bisnis yang dipakai. Jika admin harus memiliki akses global, aturan itu harus dirancang eksplisit dan diuji, bukan diasumsikan dari nama role.

## 8. Browser, AJAX, Polling, dan Suara

HTML memberikan struktur; CSS mengatur tema, layout, dan responsive behavior; JavaScript menangani fetch, parsing JSON, pembaruan tabel, pesan, konfirmasi arsip, dan suara.

Polling sekitar empat detik adalah *short polling*, bukan WebSocket atau push. Perubahan dapat tertunda sampai request berikutnya; banyak tab akan menghasilkan banyak request. Jika kebutuhan berubah menjadi volume tinggi atau pembaruan real-time, evaluasi SSE/WebSocket dan strategi skala.

Web Speech API membacakan nomor jika `speechSynthesis` tersedia. Ketersediaan suara bahasa Indonesia bergantung pada browser/perangkat, suara terpasang, audio, serta kebijakan autoplay. Kegagalan suara tidak boleh membatalkan perubahan status yang sudah tersimpan.

Saat memasukkan data ke HTML, gunakan escaping PHP `e()` atau DOM `textContent`. Jika template dibangun memakai `innerHTML`, semua data dinamis wajib di-escape agar tidak menjadi markup/script.

## 9. Keamanan dan Batasan yang Harus Dijelaskan

### Yang diterapkan

- PDO prepared statements untuk query parameterized.
- Password diverifikasi sebagai hash.
- Session ID diregenerasi setelah login.
- Cookie session memiliki `HttpOnly`, `SameSite=Lax`, dan opsi `Secure` ketika HTTPS aktif.
- Token CSRF diverifikasi dengan `hash_equals()`.
- Halaman/API petugas memeriksa login dan role.
- Operasi petugas memeriksa assignment, status tiket, dan ID tiket di server.
- HTML yang dirender melalui PHP di-escape.

CSRF melindungi dari situs lain yang mencoba memicu request menggunakan session korban; CSRF tidak menggantikan XSS prevention, autentikasi, authorization, atau validasi SQL.

### Batasan yang tidak boleh ditutupi

- Pembatasan tiket guest berbasis cookie/session perangkat, bukan identitas unik orang.
- Endpoint status tiket menerima kode tiket dan tidak mewajibkan bukti kepemilikan; kode urut mudah ditebak. Gunakan token rahasia acak dan authorization server-side untuk privasi yang lebih kuat.
- Data riwayat saat ini berdasarkan tanggal layanan aktif, bukan arsip laporan lintas hari; belum ada fitur restore arsip.
- Tidak ada constraint database yang secara eksplisit memastikan maksimum satu tiket `called` per assignment. Pengecekan berada di logika aplikasi dalam transaksi; request simultan perlu diuji dan mungkin perlu locking/strategi database tambahan.
- Konfigurasi koneksi lokal saat ini berada langsung di source dan tidak membaca environment variable. Jangan membagikan kredensial dalam dokumentasi/repository; pindahkan ke environment variable atau secret manager sebelum dipakai bersama/produksi.
- Halaman testing bukan suite otomatis. Belum boleh mengklaim coverage atau pengujian beban tanpa bukti.
- PHP built-in server hanya untuk pengembangan, bukan server produksi.

## 10. Checklist QA

1. Gelombang aktif, kuota tersedia, kuota penuh, dan gelombang nonaktif.
2. Ruangan aktif tersedia dan seluruh ruangan nonaktif.
3. Tiket pertama dan beberapa tiket selanjutnya: nomor, kode, tanggal, kuota, dan distribusi ruangan.
4. Dua request pembuatan tiket paralel dekat batas kuota: tidak ada kuota berlebih atau nomor ganda.
5. Perangkat dengan tiket aktif mencoba mengambil tiket lagi; ulangi setelah cookie dihapus/perangkat berbeda untuk menunjukkan batas identifikasi.
6. Request membaca/mengubah penugasan yang bukan milik petugas harus ditolak di backend.
7. Panggil hanya menerima `waiting`; recall/selesai hanya menerima `called`; arsip hanya menerima `completed`.
8. Uji aksi ganda dan dua petugas memanggil bersamaan; pastikan tidak ada status inkonsisten.
9. Setelah selesai: tiket pindah dari tabel aktif ke riwayat dan jumlah selesai bertambah. Setelah arsip: tiket tersembunyi, tetapi baris tetap ada dan `deleted_at` terisi.
10. Request tanpa CSRF, ID salah, metode salah, dan session habis.
11. Koneksi database terputus, jaringan gagal, browser tanpa Web Speech API, layar kecil, serta input teks dengan karakter HTML.
12. Verifikasi kuota setelah arsip dan pastikan nomor arsip tidak dipakai ulang.

Saat menjawab temuan QA, sampaikan kondisi awal, langkah reproduksi, hasil yang diharapkan, hasil aktual, bukti request/log/database, dampak, dan apakah temuan merupakan bug, batas desain, atau saran pengembangan.
