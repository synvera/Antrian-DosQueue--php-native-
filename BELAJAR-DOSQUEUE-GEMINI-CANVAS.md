# Modul Belajar DosQueue — Bahan untuk Gemini Canvas

> Gunakan dokumen ini sebagai bahan belajar interaktif di Gemini Canvas. Isinya menyatukan penjelasan sistem, panduan pembuatan, panduan menjalankan dan memahami aplikasi, serta brief halaman petugas. Jika suatu contoh umum berbeda dari source code, ikuti catatan **Implementasi saat ini**.

## Cara belajar dengan dokumen ini

Pelajari modul secara berurutan. Setelah tiap modul, coba jelaskan kembali dengan kata-katamu sendiri, lalu kerjakan latihan singkat. Minta Gemini Canvas membuat kuis, kartu tanya-jawab, diagram, atau penjelasan ulang berdasarkan isi dokumen ini. Jangan menyebut saran pengembangan sebagai fitur yang sudah tersedia.

### Tujuan belajar

Setelah mempelajari materi, kamu diharapkan mampu:

1. Menjelaskan masalah yang diselesaikan DosQueue dan siapa penggunanya.
2. Menelusuri alur browser, endpoint PHP, fungsi bisnis, PDO, dan database.
3. Menjelaskan tabel, relasi, kuota, nomor tiket, dan distribusi ruangan.
4. Menerangkan login petugas, penugasan, transisi status, dan riwayat.
5. Menjelaskan fungsi CSRF, prepared statement, transaksi, session, dan escaping.
6. Menjalankan aplikasi lokal serta menelusuri masalah dengan DevTools.
7. Menyampaikan batasan sistem dan rencana pengembangan secara akurat.

## 1. Gambaran besar DosQueue

DosQueue adalah website antrean layanan yang dibuat dengan PHP native, JavaScript, dan MySQL/MariaDB. Pengunjung melihat gelombang layanan, mengambil tiket, lalu memantau status. Petugas login, memilih penugasan gelombang dan ruangan, memanggil tiket, memanggil ulang, menyelesaikan layanan, dan mengarsipkan tiket selesai.

Masalah yang ingin ditangani adalah antrean manual yang sulit dipantau, pencatatan nomor yang rawan terlewat atau ganda, keterbatasan kuota sesi, dan pembagian pengunjung ke beberapa ruangan.

| Peran | Tugas |
|---|---|
| Pengunjung | Melihat gelombang/kuota, mengambil tiket, memantau status. Tidak mempunyai akun. |
| Petugas | Login dan mengelola tiket yang sesuai dengan penugasannya. |
| Admin | Login dengan role admin. Pada implementasi fungsi bisnis saat ini, role admin tetap dibatasi penugasan; nama role tidak otomatis memberi akses global. |

### Peta folder

| Folder/file | Tanggung jawab |
|---|---|
| `config/` | Inisialisasi aplikasi, session, dan koneksi PDO. |
| `includes/` | Helper, autentikasi, CSRF, dan aturan bisnis. |
| `actions/` | Endpoint HTTP, validasi request, dan respons JSON. |
| `user/` | Memilih gelombang dan memantau tiket. |
| `petugas/` | Login, dashboard, riwayat antrean, logout. |
| `assets/` | CSS, ikon, font, dan aset tampilan. |
| `database/` | Skrip SQL untuk skema dan data project. |

Pemisahan ini membuat tampilan, komunikasi HTTP, aturan bisnis, dan akses database lebih mudah ditelusuri daripada menumpuk semuanya pada satu file.

## 2. Arsitektur request: dari halaman sampai database

API di project ini adalah endpoint HTTP PHP sederhana di folder `actions/`, bukan API dari framework terpisah. PHP menghasilkan halaman; JavaScript memakai `fetch()` untuk membaca atau mengubah data. Endpoint memvalidasi request, memanggil fungsi bisnis, lalu umumnya mengirim JSON.

```text
Pengguna
   ↓ aksi di halaman
HTML/PHP + JavaScript fetch()
   ↓ HTTP GET atau POST
actions/*.php
   ↓ validasi metode, input, CSRF, session, role, penugasan
includes/functions.php
   ↓ aturan bisnis dan query menggunakan PDO
MySQL/MariaDB
   ↑ hasil
JSON → JavaScript memperbarui bagian halaman
```

GET umumnya membaca data; POST digunakan untuk perubahan. Backend tetap memvalidasi semua data, karena pengguna dapat mengirim request sendiri tanpa menekan tombol UI.

### Endpoint penting

| Endpoint | Kegunaan |
|---|---|
| `get-waves.php` | Membaca gelombang aktif dan penggunaan kuota. |
| `create-ticket.php` | Membuat tiket setelah validasi dan pemeriksaan CSRF. |
| `get-ticket-status.php` | Membaca status/rincian tiket pengunjung. |
| `release-guest-ticket.php` | Melepaskan ikatan tiket guest dari perangkat. |
| `get-staff-tickets.php` | Membaca tiket untuk penugasan petugas yang sah. |
| `call-ticket.php` | Memanggil tiket menunggu. |
| `recall-ticket.php` | Memanggil ulang tiket yang sudah dipanggil. |
| `complete-ticket.php` | Menyelesaikan layanan tiket. |
| `delete-ticket.php` | Mengarsipkan tiket selesai dengan soft delete. |

Respons API umumnya memuat penanda sukses, pesan, data, dan status HTTP. Status yang perlu dikenali: `400` input salah, `401` session tidak berlaku, `403` CSRF/hak akses ditolak, `404` data tidak ditemukan, `405` metode salah, `409` konflik aturan/status/kuota, dan `500` kesalahan server.

## 3. Alur pengunjung dan aturan antrean

1. `index.php` mengarahkan ke `user/index.php`.
2. Halaman meminta daftar gelombang aktif dan kuota melalui `get-waves.php`.
3. Pengunjung memilih gelombang. Browser mengirim `wave_id` dan token CSRF ke `create-ticket.php`.
4. Server memeriksa gelombang, kuota, ruangan aktif, serta apakah perangkat sudah terikat ke tiket aktif.
5. Server menentukan nomor dan ruangan, lalu menyimpan tiket berstatus `waiting`.
6. Kode tiket diikat ke session/cookie perangkat, dan browser membuka halaman hasil.
7. `ticket-result.php` meminta status berkala melalui `get-ticket-status.php`.

Status tiket:

```text
waiting ──panggil──> called ──selesai──> completed
                         └──panggil ulang──> called (status sama, log bertambah)
```

### Kuota, nomor, dan ruangan

- Kuota dihitung untuk tanggal layanan dan gelombang yang sama dari tiket yang belum diarsipkan. Status `waiting`, `called`, dan `completed` sama-sama sudah memakai kuota.
- Server memeriksa kuota kembali saat membuat tiket; tampilan kuota di browser bukan otoritas akhir.
- Nomor antrean adalah integer, dikelompokkan menurut tanggal layanan dan gelombang. Tampilan memberi padding minimal tiga digit, misalnya `1` menjadi `001`.
- Kode internal tiket dan kode tampilan dapat berbeda. Contoh bentuk kode yang dijelaskan dokumen: `G1-20261126-012` untuk kode tiket dan `G1-R3-012` untuk tampilan.
- Ruangan aktif diurutkan berdasarkan ID lalu dibagikan round-robin: `roomIndex = (queue_number - 1) MOD jumlah_ruangan_aktif`.
- Arsip mengisi `deleted_at`, bukan menghapus permanen. Kuota operasional tidak lagi menghitung tiket arsip; nomor yang pernah dipakai tetap tidak digunakan ulang.

### Catatan konkurensi

Pembuatan tiket dirancang memakai transaksi dan lock baris gelombang sebelum menghitung kuota/nomor, dengan unique constraint sebagai perlindungan tambahan. Kondisi request paralel tetap perlu diuji di lingkungan database yang dipakai. Logika panggilan juga memeriksa tiket `called` pada penugasan, tetapi belum ada constraint database yang secara eksplisit menjamin hanya satu tiket `called` per penugasan.

## 4. Model data dan relasi

| Tabel | Isi utama |
|---|---|
| `users` | Akun petugas/admin, username, hash password, role, status aktif. |
| `waves` | Nama dan waktu gelombang, kuota, status aktif. |
| `rooms` | Nama ruangan dan status aktif. |
| `staff_assignments` | Relasi user dengan kombinasi gelombang dan ruangan. |
| `tickets` | Kode/nomor tiket, tanggal, gelombang, ruangan, status, waktu kejadian, penanda arsip. |
| `call_logs` | Riwayat panggilan: tiket, petugas, waktu, dan nomor panggilan. |

Relasi inti:

- Satu gelombang dan satu ruangan dapat terkait dengan banyak tiket; satu tiket mengarah ke satu gelombang dan satu ruangan.
- Satu user dapat memiliki banyak penugasan. Setiap penugasan menghubungkan user, gelombang, dan ruangan.
- Satu tiket dapat memiliki banyak log panggilan. Satu user dapat menghasilkan banyak log.
- Primary key mengidentifikasi baris; foreign key menjaga hubungan antarbaris. Unique key mencegah nilai/kombinasi penting berulang.

`call_logs` menyimpan riwayat panggil ulang tanpa menambah baris tiket. `deleted_at` adalah penanda soft delete. Tipe penting: `DATE` untuk tanggal layanan, `TIME` untuk jam gelombang, dan `DATETIME` untuk waktu kejadian.

## 5. Cara kerja backend PHP

- `config/database.php` menyediakan koneksi PDO ke MySQL/MariaDB. PDO diatur untuk exception, hasil associative, charset `utf8mb4`, dan prepared statement.
- `includes/functions.php` menyimpan aturan kuota, pembuatan tiket, query dashboard, transisi status, log panggilan, arsip, dan ikatan tiket guest.
- `includes/auth.php` menangani login, session, role, dan penugasan.
- `includes/csrf.php` membuat/memeriksa token CSRF.
- `includes/helpers.php` menyediakan helper seperti escaping dan respons JSON.
- Endpoint `actions/` menangani validasi HTTP dan meneruskan operasi ke fungsi bisnis.

**Prepared statement** memisahkan nilai input dari struktur SQL, sehingga nilai input parameterized tidak dibaca sebagai sintaks SQL. **Transaksi** memungkinkan beberapa perubahan terkait commit bersama atau rollback jika gagal. Keduanya perlu dipakai bersama validasi dan constraint database.

## 6. Keamanan dan privasi

Yang diterapkan menurut dokumentasi project:

- Password diverifikasi menggunakan `password_verify()` dan disimpan sebagai hash.
- Session ID diregenerasi setelah login; cookie session menggunakan `HttpOnly`, `SameSite=Lax`, dan `Secure` saat HTTPS aktif.
- Token CSRF diperiksa dengan perbandingan aman `hash_equals()` untuk aksi perubahan.
- Halaman/API petugas memeriksa login dan role; fungsi bisnis memeriksa penugasan serta status tiket.
- Output HTML perlu di-escape menggunakan helper `e()`; JavaScript sebaiknya menaruh data sebagai teks melalui `textContent`.
- Query parameterized menggunakan PDO prepared statement.

CSRF bukan pengganti autentikasi, otorisasi, validasi input, atau pencegahan XSS.

### Batasan yang perlu diakui

- Pembatasan satu tiket guest berbasis cookie/session perangkat, bukan identitas orang. Cookie dapat dihapus atau perangkat lain digunakan.
- Endpoint status menerima kode tiket yang berurutan dan tidak mensyaratkan bukti kepemilikan. Kode itu tidak boleh dianggap rahasia kuat; token acak dan pemeriksaan kepemilikan server-side adalah saran peningkatan.
- Riwayat menampilkan tiket selesai untuk tanggal layanan aktif, bukan laporan lintas tanggal; fitur restore arsip belum tersedia.
- Perilaku request simultan perlu pengujian, khususnya untuk kuota/nomor dan pemanggilan.
- PHP built-in server hanya untuk development.
- Halaman testing manual bukan suite otomatis; jangan mengklaim test coverage atau uji beban tanpa bukti.

## 7. Halaman petugas dan interaksi

### Login `petugas/login.php`

Form username dan password wajib diisi. Error muncul jika kredensial gagal atau token keamanan tidak valid. Pengguna yang sudah login diarahkan ke dashboard.

### Dashboard `petugas/dashboard.php`

Halaman memuat nama, username, role, logout, pilihan penugasan, refresh manual, uji suara, pesan aksi/koneksi, dan ringkasan jumlah waiting/called/completed. Tabel tiket aktif berisi nomor, kode, waktu tiket dibuat, status, jumlah panggilan, dan aksi.

- `waiting`: tombol **Panggil**.
- `called`: tombol **Panggil Ulang** dan **Selesai**.
- Tombol panggil berikutnya dinonaktifkan jika masih ada tiket `called`; backend juga wajib memvalidasi aturan tersebut.
- Setelah panggil, browser dapat membacakan nomor melalui Web Speech API.

### Riwayat `petugas/riwayat-antrian.php`

Menampilkan tiket selesai untuk penugasan terpilih dan tanggal layanan aktif. Ada pilihan penugasan, refresh, polling berkala, konfirmasi arsip, tautan kembali ke dashboard, dan sidebar bersama. Logout mengakhiri session.

Dashboard dan riwayat memperbarui data sekitar setiap empat detik. Ini disebut **short polling**, bukan koneksi real-time. Suara bergantung pada dukungan browser, suara bahasa yang tersedia, audio, dan kebijakan autoplay; kegagalan suara tidak membatalkan perubahan status yang sudah disimpan.

## 8. Panduan membaca frontend dan debugging

HTML/PHP menyusun halaman, CSS mengatur tampilan, dan JavaScript memanggil endpoint melalui `fetch()`, membaca JSON, lalu memperbarui tabel/status. Saat debugging, buka DevTools:

1. Di tab **Network**, periksa URL, metode, payload, status HTTP, dan response JSON.
2. Di **Console**, periksa error JavaScript.
3. Bandingkan respons endpoint dengan data database dan kondisi session/penugasan.
4. Jangan memasukkan data tak tepercaya ke `innerHTML` tanpa escaping.

## 9. Menjalankan aplikasi lokal

Prasyarat: PHP 8.x, ekstensi `PDO` dan `pdo_mysql`, MySQL/MariaDB dengan InnoDB, serta browser modern. Proyek tidak memerlukan Composer atau frontend bundler.

1. Pastikan server database hidup.
2. Baca `database/antrian_db.sql` dan pastikan targetnya database lokal yang boleh diubah. Skrip dump dapat menjatuhkan/membuat ulang tabel; backup data lebih dulu dan jangan impor sembarangan ke database berisi data penting.
3. Pastikan konfigurasi koneksi lokal benar.
4. Dari root proyek jalankan `php -S 127.0.0.1:8000`, lalu buka `http://127.0.0.1:8000/`.
5. Jika gagal, periksa konfigurasi aktual, database, hak akses user database, layanan MySQL, dan ekstensi `pdo_mysql`.

### Perbedaan penting antar dokumen sumber

Salah satu panduan berisi contoh koneksi yang membaca `DOSQUEUE_DB_*` dari environment variable. Namun panduan detail menyatakan source saat ini menyimpan konfigurasi lokal langsung di `config/database.php` dan tidak membaca environment variable. Perlakukan contoh environment variable sebagai **contoh/saran implementasi**, bukan bukti bahwa source saat ini menggunakannya. Periksa file source sebelum menjalankan perintah. Jangan menaruh kredensial rahasia di dokumentasi atau Git.

Dokumen panduan pembuatan juga memiliki kalimat lama yang menyebut skema SQL kosong, sementara penjelasan lain menyebut file SQL berisi skema dan data ekspor. Kondisi file yang benar hanya dapat dipastikan dengan membaca `database/antrian_db.sql` versi repository. Selalu baca SQL sebelum impor; jangan menganggap dump sebagai migrasi aman.

## 10. Panduan membangun dari nol

Urutan kerja konseptual dari panduan proyek:

1. Siapkan PHP, PDO MySQL, database, browser, editor, dan Git.
2. Tetapkan kebutuhan, aktor, status tiket, alur pengunjung/petugas, dan struktur folder.
3. Rancang tabel, relasi, indeks, constraint, dan data awal.
4. Atur zona waktu/session, PDO, helper, escaping, JSON, serta CSRF.
5. Buat autentikasi dan fungsi bisnis tiket.
6. Buat endpoint dengan metode HTTP, validasi, otorisasi, kode status, dan JSON yang sesuai.
7. Buat halaman pengunjung dan pemantauan tiket.
8. Buat login, dashboard, riwayat, aksi petugas, dan logout.
9. Tambahkan CSS responsif, polling, pesan, dan suara opsional.
10. Jalankan lokal, periksa alur end-to-end, lalu rencanakan hardening sebelum penggunaan nyata.

Contoh kode pada panduan adalah bahan belajar, bukan otomatis salinan tepat dari source sekarang. Khususnya, contoh query, konfigurasi environment, dan kode endpoint harus dicocokkan dengan implementasi aktual.

## 11. Checklist pemahaman dan QA

Gunakan skenario ini untuk latihan manual:

- Gelombang aktif/nonaktif, kuota tersedia/penuh, dan tidak ada ruangan aktif.
- Tiket baru: nomor, kode, tanggal, ruangan, status awal, dan hitungan kuota benar.
- Perangkat dengan tiket aktif mencoba mengambil tiket kedua; ulangi setelah cookie dihapus untuk memahami keterbatasan identitas.
- Panggil hanya menerima `waiting`; recall dan selesai hanya menerima `called`; arsip hanya menerima `completed`.
- Petugas mencoba assignment milik orang lain; server harus menolak.
- Request tanpa CSRF, ID salah, metode salah, session habis, dan status tiket yang berubah.
- Request paralel dekat kuota terakhir dan dua petugas memanggil bersamaan.
- Setelah selesai, tiket muncul pada riwayat; setelah arsip, tiket tersembunyi tetapi record tetap ada.
- Jaringan/database gagal, layar kecil, karakter HTML, browser tanpa Web Speech API.

Saat mencatat bug, tulis kondisi awal, langkah reproduksi, hasil yang diharapkan, hasil aktual, bukti request/log/database, dampak, dan klasifikasi: bug, batas desain, atau saran.

## 12. Latihan belajar

### Pertanyaan pemahaman

1. Apa perbedaan endpoint `actions/` dan fungsi bisnis di `includes/functions.php`?
2. Mengapa kuota harus diperiksa lagi oleh server saat tiket dibuat?
3. Mengapa pemanggilan ulang menambah `call_logs` tetapi status tetap `called`?
4. Apa yang dijaga oleh foreign key dan unique constraint?
5. Mengapa menonaktifkan tombol di browser bukan otorisasi?
6. Apa beda polling empat detik dengan WebSocket?
7. Apa risiko kode tiket yang berurutan untuk endpoint status?
8. Apa arti soft delete pada tiket?
9. Mana yang merupakan implementasi saat ini dan mana yang saran: membaca kredensial dari environment variable?
10. Sebutkan setidaknya tiga batasan yang harus disampaikan saat presentasi.

### Mini proyek

Buat diagram urutan untuk satu alur: pengunjung memilih gelombang sampai tiket tampil. Tandai setiap langkah yang terjadi di browser, endpoint, fungsi bisnis, dan database. Setelah itu buat diagram status tiket dan relasi enam tabel.

## Prompt yang bisa ditempel ke Gemini Canvas

> Jadilah tutor pemrograman yang mengajar berdasarkan dokumen ini saja. Ajari saya DosQueue dalam modul pendek berurutan: gambaran sistem, arsitektur request, database, aturan tiket, keamanan, halaman petugas, dan menjalankan/debugging. Setelah setiap modul, tanyakan 3 soal singkat, tunggu jawaban saya, koreksi dengan penjelasan yang ramah, dan lanjutkan setelah saya paham. Bedakan implementasi saat ini, contoh pembelajaran, dan saran pengembangan. Jangan mengarang fitur yang tidak tertulis. Jika dokumen sumber memberi keterangan yang berbeda, jelaskan perbedaannya dan minta saya memeriksa source code.

## Glosarium singkat

| Istilah | Arti sederhana |
|---|---|
| API endpoint | URL/file server yang menerima request untuk operasi tertentu. |
| JSON | Format teks terstruktur untuk pertukaran data browser-server. |
| PDO | Antarmuka PHP untuk mengakses database. |
| Prepared statement | Query dengan nilai input terpisah dari sintaks SQL. |
| Transaksi | Sekumpulan operasi database yang berhasil bersama atau dibatalkan bersama. |
| CSRF | Serangan yang mencoba membuat browser pengguna mengirim aksi tanpa sengaja. |
| Session/cookie | Mekanisme menyimpan status antar-request; pada guest dipakai untuk mengikat tiket ke perangkat. |
| FIFO | First In, First Out: yang lebih dahulu masuk dilayani lebih dahulu. |
| Round-robin | Pembagian berulang bergiliran ke beberapa ruangan. |
| Soft delete | Menandai data sebagai diarsipkan tanpa menghapus baris database. |
| Polling | Browser meminta pembaruan berulang dalam jeda waktu tertentu. |
| Constraint | Aturan database untuk menjaga keunikan atau hubungan data. |
