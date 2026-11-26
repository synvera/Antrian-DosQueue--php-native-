# Brief Desain Halaman Petugas

Saya sedang membuat aplikasi sistem antrean menggunakan PHP. Tolong bantu jelaskan dan rancang ide tampilan untuk halaman di folder `petugas`. Fokuskan penjelasan pada susunan halaman, prioritas informasi, komponen UI, dan interaksi yang perlu terlihat. Jangan mengubah atau menghilangkan fungsi yang sudah ada.

## Halaman yang tersedia

### Login Petugas — `petugas/login.php`

- Form login dengan kolom username dan password.
- Kedua kolom wajib diisi.
- Pesan error ditampilkan jika login gagal atau token keamanan tidak valid.
- Petugas yang sudah login otomatis diarahkan ke dashboard.

### Dashboard Petugas — `petugas/dashboard.php`

Halaman ini hanya dapat dibuka setelah petugas login.

#### Informasi akun

- Nama petugas.
- Username dan role.
- Tautan atau tombol Logout.

#### Penugasan dan kontrol

- Pilihan penugasan yang berisi gelombang dan ruangan.
- Isi dashboard mengikuti penugasan yang dipilih.
- Tombol **Refresh Manual** untuk memuat ulang data.
- Tombol **Uji Suara Speaker** untuk menguji pembacaan suara.
- Area pesan untuk menampilkan hasil aksi atau gangguan koneksi.

#### Ringkasan antrean

Menampilkan tanggal pelayanan dan jumlah:

- Tiket Menunggu (*Waiting*).
- Tiket Sedang Dipanggil (*Called*).
- Tiket Selesai (*Completed*).

#### Tabel tiket aktif dan menunggu

Kolom:

- Nomor antrean.
- Kode tiket.
- Waktu tiket diambil.
- Status.
- Jumlah panggilan.
- Aksi.

Aksi berdasarkan status:

- Tiket *Waiting*: tombol **Panggil**.
- Tiket *Called*: tombol **Panggil Ulang** dan **Selesai**.
- Tombol **Panggil** dinonaktifkan jika masih ada tiket lain yang berstatus *Called*.
- Saat tiket dipanggil, nomor antrean dibacakan melalui fitur suara browser jika tersedia.

### Riwayat Antrian Petugas — `petugas/riwayat-antrian.php`

Dashboard hanya menampilkan tiket aktif dan menunggu. Jumlah tiket selesai tetap ditampilkan dalam ringkasan, sedangkan daftar tiket selesai berada pada halaman terpisah **Riwayat Antrian** (`petugas/riwayat-antrian.php`) yang dapat dibuka melalui sidebar.

Halaman ini hanya dapat dibuka setelah petugas login. Gunakan tema, profil petugas, dan sidebar yang sama dengan dashboard, dengan menu **Riwayat Antrian** dalam keadaan aktif. Sediakan tautan kembali ke Dashboard serta pilihan penugasan gelombang dan ruangan.

Kolom:

- Nomor antrean.
- Kode tiket.
- Waktu tiket diambil.
- Waktu selesai.
- Status.
- Aksi **Arsipkan**.

Daftar hanya menampilkan tiket selesai untuk penugasan yang dipilih dan tanggal layanan aktif, diperbarui otomatis setiap 4 detik, dan menyediakan refresh manual. Aksi arsip meminta konfirmasi terlebih dahulu dan mengarsipkan tiket dari daftar.

#### Kondisi dan pembaruan

- Jika petugas tidak memiliki penugasan aktif, tampilkan pesan bahwa belum ada penugasan gelombang/ruangan.
- Jika tabel antrean aktif atau riwayat tidak memiliki tiket, tampilkan pesan keadaan kosong yang sesuai.
- Data dashboard diperbarui otomatis setiap 4 detik.
- Data riwayat diperbarui otomatis setiap 4 detik.
- Jika sesi login kedaluwarsa, petugas diarahkan kembali ke halaman login.

### Logout — `petugas/logout.php`

Mengakhiri sesi petugas dan mengarahkan pengguna kembali ke halaman login.
