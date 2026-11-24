# Penjelasan Project DosQueue

## Ringkasan

DosQueue adalah aplikasi antrean berbasis PHP native dengan database MySQL/MariaDB. Ada dua alur utama:

- **Pengunjung** melihat gelombang layanan yang aktif, mengambil tiket, lalu memantau posisi/status antrean.
- **Petugas** login, memilih gelombang dan ruangan dari penugasannya, memanggil tiket, menyelesaikan layanan, lalu mengarsipkan tiket selesai.

Halaman root `index.php` mengarahkan pengunjung ke `user/index.php`. Data tiket disimpan di database, sedangkan informasi autentikasi petugas disimpan dalam session.

## Peran dan data utama

| Data | Fungsi |
|---|---|
| `users` | Akun petugas/admin, termasuk nama, username, hash password, role, dan status aktif. |
| `waves` | Gelombang layanan, waktu mulai/selesai, kuota, dan status aktif. |
| `rooms` | Ruangan yang tersedia untuk pelayanan. |
| `staff_assignments` | Membatasi gelombang dan ruangan yang boleh diakses petugas. |
| `tickets` | Nomor, kode, tanggal layanan, gelombang, ruangan, status, dan waktu perubahan status tiket. |
| `call_logs` | Riwayat panggilan dan nomor panggilan untuk tiket. |

Status tiket berjalan dari `waiting` ke `called`, kemudian `completed`. Pengarsipan mengisi `deleted_at` (soft delete), sehingga tiket dikeluarkan dari tampilan operasional tetapi barisnya tidak dihapus permanen.

## Alur pengunjung

1. `user/index.php` memuat gelombang aktif dan kuota hari itu.
2. Setiap gelombang menampilkan nama, waktu sesi, jumlah tiket terpakai dibanding kuota, dan aksi untuk mengambil tiket jika masih tersedia.
3. `actions/create-ticket.php` memvalidasi request dan CSRF; fungsi pembuatan tiket memeriksa ulang kuota di dalam transaksi.
4. Nomor tiket berikutnya dihitung per gelombang dan tanggal layanan. Ruangan dipilih berputar dari daftar ruangan aktif yang diurutkan berdasarkan ID.
5. Tiket baru memiliki status `waiting`, lalu browser diarahkan ke `user/ticket-result.php`.
6. Halaman hasil memperlihatkan kode tampilan (format seperti `G1-R2-003`), status, ruangan, jumlah antrean di depan, serta nomor yang sedang dipanggil pada gelombang/ruangan/tanggal yang sama.
7. Status diperbarui setiap 4 detik. Ketika dipanggil, halaman menampilkan pemberitahuan dan bunyi; ketika selesai, kartu menjadi grayscale dan pengunjung dapat memulai pengambilan tiket lagi.

Perangkat pengunjung diikat ke tiket aktif melalui session dan cookie. Implementasi menolak pengambilan tiket baru pada perangkat yang sama selama masih memiliki tiket aktif pada tanggal layanan tersebut.

## Alur petugas

1. Petugas masuk melalui `petugas/login.php`. Password diverifikasi menggunakan hash, dan hanya akun aktif dengan role `petugas` atau `admin` yang dapat menggunakan dashboard.
2. `petugas/dashboard.php` menampilkan akun dan pilihan penugasan gelombang/ruangan yang aktif.
3. Data dibaca dari `actions/get-staff-tickets.php`; server memeriksa kembali bahwa kombinasi pilihan benar-benar ditugaskan kepada akun tersebut.
4. Ringkasan menampilkan tiket menunggu, dipanggil, dan selesai. Tabel aktif diurutkan berdasarkan nomor antrean.
5. Tiket `waiting` dapat dipanggil. Tiket `called` dapat dipanggil ulang atau diselesaikan. Server menolak pemanggilan tiket berikutnya jika masih ada tiket `called` pada kombinasi layanan yang sama.
6. Pemanggilan memakai Web Speech API browser jika tersedia. Tombol uji suara menggunakan fasilitas pembacaan yang sama.
7. Tiket `completed` tampil di tabel selesai dan dapat diarsipkan setelah konfirmasi. Logout tersedia melalui `petugas/logout.php`.

Dashboard memuat ulang data otomatis setiap 4 detik dan juga memiliki tombol refresh manual. Jika session API berakhir, browser diarahkan kembali ke login.

## Peta direktori

| Lokasi | Isi |
|---|---|
| `actions/` | Endpoint JSON untuk membaca gelombang/tiket, membuat tiket, serta aksi panggil, selesai, dan arsip. |
| `assets/css/` | Gaya halaman pemilihan gelombang dan kartu hasil tiket. |
| `config/app.php` | Zona waktu dan konfigurasi session. |
| `config/database.php` | Koneksi PDO MySQL. |
| `database/antrian_db.sql` | Lokasi yang disiapkan untuk skrip skema database. |
| `includes/auth.php` | Login, session, pemeriksaan role, dan validasi penugasan. |
| `includes/csrf.php` | Pembuatan serta verifikasi token CSRF. |
| `includes/functions.php` | Query dan aturan bisnis antrean. |
| `includes/helpers.php` | Helper escaping HTML, JSON, tanggal, dan redirect. |
| `petugas/` | Halaman login, dashboard, dan logout petugas. |
| `user/` | Halaman pemilihan gelombang dan hasil tiket. |

## Teknologi dan perlindungan yang digunakan

- PHP native dengan PDO untuk koneksi dan prepared statements MySQL.
- Session untuk login petugas serta pengikat tiket pengunjung; zona waktu aplikasi adalah `Asia/Jakarta`.
- CSRF token untuk request perubahan data.
- Password akun diverifikasi memakai `password_verify()`.
- Server memeriksa role dan penugasan untuk aksi petugas, sehingga pembatasan tidak hanya bergantung pada tampilan.
- Perubahan status tiket dan alokasi kuota memakai transaksi database.
- Web Speech API untuk menyuarakan panggilan; hasilnya bergantung pada dukungan browser/perangkat.

## Batasan dan perbedaan terhadap brief desain

Catatan ini membedakan implementasi yang ada dari ide pada `PETUGAS-DESIGN-BRIEF.md`:

- Berkas `database/antrian_db.sql` masih **kosong**. Skema tabel dan data awal perlu disiapkan sebelum aplikasi dapat berjalan dengan database baru.
- Halaman gelombang saat ini menampilkan pemakaian dalam format jumlah terpakai/kuota; belum menampilkan persentase atau angka sisa kuota secara eksplisit, dan belum membuka panel deskripsi setelah gelombang dipilih.
- Semua gelombang aktif dengan kuota tersedia dapat dipilih. Pembatasan khusus agar hanya gelombang berikutnya yang bisa dipilih belum diterapkan.
- Kartu tiket memuat status, kode, dan ruangan serta label gelombang; waktu sesi tidak ditampilkan pada kartu hasil saat ini.
- Room dipilih otomatis secara bergiliran berdasarkan urutan ID ruangan aktif, bukan pengacakan.
- Aksi tabel selesai diberi label “Arsipkan / Hapus”, tetapi implementasinya soft delete, bukan penghapusan permanen.
- Dashboard petugas sudah berfungsi secara operasional tetapi tampilannya masih HTML dasar; brief desain dapat menjadi acuan pengembangan UI selanjutnya.
- Konfigurasi koneksi database saat ini menyimpan nilai langsung di file PHP. Ganti dengan konfigurasi lokal/lingkungan sebelum penggunaan nyata dan jangan membagikan rahasia tersebut.

## Dokumen terkait

- [Panduan pembuatan project](./PANDUAN-PEMBUATAN-PROJECT.md)
- [Brief desain petugas](./PETUGAS-DESIGN-BRIEF.md)
