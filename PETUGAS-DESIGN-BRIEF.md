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

#### Tabel tiket selesai

Kolom:

- Nomor antrean.
- Kode tiket.
- Waktu tiket diambil.
- Waktu selesai.
- Status.
- Aksi **Arsipkan / Hapus**.

Aksi arsip meminta konfirmasi terlebih dahulu.

#### Kondisi dan pembaruan

- Jika petugas tidak memiliki penugasan aktif, tampilkan pesan bahwa belum ada penugasan gelombang/ruangan.
- Jika tabel tidak memiliki tiket, tampilkan pesan keadaan kosong yang sesuai.
- Data dashboard diperbarui otomatis setiap 4 detik.
- Jika sesi login kedaluwarsa, petugas diarahkan kembali ke halaman login.

### Logout — `petugas/logout.php`

Mengakhiri sesi petugas dan mengarahkan pengguna kembali ke halaman login.

## Arahan untuk jawaban

Jelaskan usulan desain dalam bahasa Indonesia. Prioritaskan dashboard untuk penggunaan operasional yang cepat dan mudah dibaca petugas. Sertakan struktur/layout yang disarankan, hierarki visual, komponen penting, serta cara menampilkan status dan aksi tiket. Pastikan seluruh fitur dan kondisi yang disebutkan di atas tetap terwakili.
