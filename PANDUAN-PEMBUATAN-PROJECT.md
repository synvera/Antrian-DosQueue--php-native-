# Langkah demi Langkah Membuat Website DosQueue dari Nol

> Panduan ini menyusun proses pembuatan DosQueue secara berurutan: mulai dari menyiapkan proyek kosong, merancang database dan alur, membuat backend serta halaman, sampai menjalankan dan memeriksa website. Nama file dan perilaku yang disebutkan mengikuti source code DosQueue di repository ini. Jika Anda sedang membangun dari folder kosong, ikuti tahapannya berurutan; jika Anda sudah memiliki source code, gunakan bagian persiapan dan menjalankan aplikasi.

## Tahap 1 — Siapkan alat kerja

Perangkat yang diperlukan:

- PHP 8.x (PHP 7.3+ sebaiknya menjadi batas minimum karena konfigurasi cookie session menggunakan opsi berbentuk array).
- Ekstensi PHP `PDO` dan `pdo_mysql`.
- MySQL atau MariaDB yang mendukung transaksi InnoDB.
- Browser modern. Fitur pembacaan nomor antrean menggunakan Web Speech API browser.

Proyek ini menggunakan PHP native dan tidak memiliki `composer.json` maupun langkah build frontend. Siapkan editor kode, terminal, dan Git agar perubahan source code dapat disimpan dan ditinjau.

Periksa instalasi PHP dan ekstensi database dari terminal:

```sh
php -v
php -m
```

Pastikan daftar modul memuat `PDO` dan `pdo_mysql`. Buat proyek serta direktori awal jika memulai dari folder kosong:

```sh
mkdir dosqueue
cd dosqueue
mkdir -p actions assets/css assets/icons assets/images assets/Fonts config database includes petugas user
touch index.php
```

## Tahap 2 — Tentukan kebutuhan dan alur website

Tentukan tiga status tiket: `waiting` (menunggu), `called` (sedang dipanggil), dan `completed` (selesai). Alur utamanya:

1. Pengunjung memilih gelombang yang kuotanya masih tersedia.
2. Server memvalidasi kuota, membuat nomor antrean dan kode tiket, lalu memilih ruangan aktif secara bergiliran.
3. Halaman hasil tiket memantau status tiket secara berkala.
4. Petugas yang sudah login melihat tiket sesuai penugasan gelombang dan ruangannya.
5. Petugas memanggil tiket, dapat memanggil ulang, lalu menandai pelayanan selesai.
6. Tiket selesai dapat diarsipkan dari daftar operasional.

Sebelum menulis kode, gambar alur tersebut di atas kertas dan tetapkan tiga status tiket (`waiting`, `called`, `completed`). Siapkan kerangka folder berikut agar halaman, endpoint, konfigurasi, dan aturan bisnis tidak tercampur:

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

Untuk memulai Git dan mencatat kerangka proyek:

```sh
git init
touch config/app.php config/database.php
touch includes/helpers.php includes/csrf.php includes/auth.php includes/functions.php
touch user/index.php user/ticket-result.php
touch petugas/login.php petugas/dashboard.php petugas/logout.php
```

## Tahap 3 — Buat database dan data awal

File [`database/antrian_db.sql`](./database/antrian_db.sql) menggabungkan struktur tabel dengan data dari ekspor database DosQueue. File ini membuat database `antrian_db`, lalu menjatuhkan dan membuat ulang tabel `call_logs`, `rooms`, `staff_assignments`, `tickets`, `users`, dan `waves` sebelum mengisi data ekspor, termasuk gelombang, ruangan, akun, penugasan, tiket, serta riwayat panggilan.

**Penting:** impor file ini hanya pada database kosong atau setelah membuat backup. Baris `DROP TABLE` akan menghapus data pada tabel dengan nama yang sama sebelum dipulihkan dari dump. Jangan mengimpor ulang ke database yang berisi data baru tanpa backup.

Role yang dikenali untuk dashboard adalah `petugas` dan `admin`. File ekspor membawa hash password akun yang ada; untuk penggunaan nyata, atur ulang password akun setelah impor.

Password baru harus disimpan sebagai hash yang dihasilkan `password_hash()` PHP, bukan teks biasa. Contoh membuat hash lewat terminal:

```sh
php -r '$password = readline("Password petugas: "); echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;'
```

Masukkan hash baru ke kolom `users.password` akun yang akan diatur ulang. Data gelombang, ruangan, akun, dan penugasan dari ekspor sudah tersedia; tambahkan atau ubah data tersebut sesuai kebutuhan operasional.

## Tahap 4 — Hubungkan aplikasi dengan database

1. Inisialisasi zona waktu dan session di `config/app.php`.
2. Buat koneksi PDO MySQL di `config/database.php`; aktifkan exception mode, prepared statement native, dan charset `utf8mb4`.
3. Isi kredensial database untuk lingkungan lokal; contoh di bawah membaca kredensial dari environment variable agar rahasia tidak tersimpan di source code.
4. Tambahkan helper untuk escaping HTML, redirect, tanggal layanan, dan respons JSON.
5. Tambahkan CSRF token untuk form dan request perubahan data.

Contoh `config/app.php` untuk waktu Jakarta dan cookie session:

```php
<?php
date_default_timezone_set('Asia/Jakarta');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
```

Contoh koneksi `config/database.php` memakai environment variable supaya password tidak ditulis di source code:

```php
<?php
require_once __DIR__ . '/app.php';

function getDBConnection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = getenv('DOSQUEUE_DB_HOST') ?: '127.0.0.1';
    $name = getenv('DOSQUEUE_DB_NAME') ?: 'antrian_db';
    $user = getenv('DOSQUEUE_DB_USER') ?: 'root';
    $pass = getenv('DOSQUEUE_DB_PASS') ?: '';
    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}
```

Sebelum menjalankan PHP, isi `DOSQUEUE_DB_HOST`, `DOSQUEUE_DB_NAME`, `DOSQUEUE_DB_USER`, dan `DOSQUEUE_DB_PASS` di lingkungan terminal/server. Di shell Bash, variabel dapat diberikan untuk proses lokal seperti ini:

```sh
DOSQUEUE_DB_HOST=127.0.0.1 DOSQUEUE_DB_NAME=antrian_db \
DOSQUEUE_DB_USER=root DOSQUEUE_DB_PASS='' php -S 127.0.0.1:8000
```

Helper dasar di `includes/helpers.php`:

```php
<?php
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function jsonResponse(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
```

CSRF token dibuat satu kali per session, ditampilkan pada halaman dan diverifikasi pada setiap request yang mengubah data:

```php
<?php
require_once __DIR__ . '/../config/app.php';

function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(): bool
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}
```

## Tahap 5 — Buat autentikasi dan aturan antrean

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

Contoh fungsi login yang dapat ditempatkan di `includes/auth.php`. Fungsi ini mengambil hash dari database, memverifikasi password, memeriksa status akun, lalu mengganti session ID:

```php
function attemptLogin(string $username, string $password): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare(
        'SELECT id, name, username, password, role, is_active FROM users WHERE username = :username LIMIT 1'
    );
    $stmt->execute(['username' => trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !(int)$user['is_active'] || !password_verify($password, $user['password'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    return true;
}

function isStaffLoggedIn(): bool
{
    return !empty($_SESSION['logged_in'])
        && in_array($_SESSION['role'] ?? '', ['petugas', 'admin'], true);
}
```

Di `includes/functions.php`, mulai dengan fungsi membaca gelombang dan pemakaian kuota. Kondisi tanggal layanan dan tiket yang belum diarsipkan harus ikut dalam hitungan:

```php
function getActiveWavesWithQuota(): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT w.id, w.wave_name, w.start_time, w.end_time, w.quota,
            COUNT(t.id) AS used_quota
        FROM waves w
        LEFT JOIN tickets t ON t.wave_id = w.id
            AND t.service_date = :service_date AND t.deleted_at IS NULL
        WHERE w.is_active = 1
        GROUP BY w.id, w.wave_name, w.start_time, w.end_time, w.quota
        ORDER BY w.start_time, w.id");
    $stmt->execute(['service_date' => date('Y-m-d')]);
    return $stmt->fetchAll();
}
```

Pembuatan tiket perlu transaksi. Berikut contoh inti: kunci baris gelombang saat memeriksa kuota, tentukan nomor berikutnya, pilih ruang secara bergiliran, lalu simpan tiket. Constraint unik di database tetap menjadi perlindungan tambahan saat ada request bersamaan.

```php
function createQueueTicket(int $waveId): array
{
    $pdo = getDBConnection();
    $date = date('Y-m-d');
    try {
        $pdo->beginTransaction();
        $waveStmt = $pdo->prepare('SELECT * FROM waves WHERE id = ? AND is_active = 1 FOR UPDATE');
        $waveStmt->execute([$waveId]);
        $wave = $waveStmt->fetch();
        if (!$wave) throw new RuntimeException('Gelombang tidak tersedia.');

        $count = $pdo->prepare("SELECT COUNT(*) FROM tickets
            WHERE wave_id = ? AND service_date = ? AND deleted_at IS NULL");
        $count->execute([$waveId, $date]);
        $usedQuota = (int)$count->fetchColumn();
        if ($usedQuota >= (int)$wave['quota']) throw new RuntimeException('Kuota gelombang penuh.');

        $sequence = $pdo->prepare('SELECT COALESCE(MAX(queue_number), 0) + 1
            FROM tickets WHERE wave_id = ? AND service_date = ?');
        $sequence->execute([$waveId, $date]);
        $number = (int)$sequence->fetchColumn();

        $rooms = $pdo->query('SELECT id, room_name FROM rooms WHERE is_active = 1 ORDER BY id')->fetchAll();
        if (!$rooms) throw new RuntimeException('Belum ada ruangan aktif.');
        $room = $rooms[($number - 1) % count($rooms)];
        $code = sprintf('G%d-%s-%03d', $waveId, str_replace('-', '', $date), $number);

        $insert = $pdo->prepare("INSERT INTO tickets
            (ticket_code, queue_number, service_date, wave_id, room_id, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'waiting', NOW())");
        $insert->execute([$code, $number, $date, $waveId, $room['id']]);
        $pdo->commit();
        return ['success' => true, 'ticket_code' => $code, 'queue_number' => $number,
            'room_name' => $room['room_name']];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => $error->getMessage()];
    }
}
```

Ini contoh pembelajaran untuk inti pembuatan tiket. Lengkapi dengan pencatatan tiket perangkat, pemeriksaan kuota sesuai aturan aplikasi, respons HTTP yang sesuai, logging error, serta pengujian request bersamaan sebelum dipakai operasional.

## Tahap 6 — Buat endpoint API

Endpoint di folder `actions/` menjadi penghubung halaman dengan logika inti:

- `get-waves.php` dan `create-ticket.php` untuk pilihan gelombang dan pembuatan tiket.
- `get-ticket-status.php` untuk pembaruan status tiket pengunjung.
- `get-staff-tickets.php` untuk data dashboard petugas.
- `call-ticket.php`, `recall-ticket.php`, `complete-ticket.php`, dan `delete-ticket.php` untuk aksi petugas.
- `release-guest-ticket.php` untuk melepaskan penanda tiket aktif pada perangkat.

Batasi metode HTTP, validasi parameter, dan periksa CSRF pada request POST. Endpoint petugas juga harus memeriksa session serta penugasan, bukan hanya mengandalkan tombol yang ditampilkan di browser. Untuk tiap endpoint, susun alur yang sama: muat konfigurasi/helper, validasi request, panggil fungsi bisnis, tetapkan kode HTTP, lalu keluarkan JSON.

Contoh endpoint `actions/create-ticket.php`:

```php
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metode tidak diizinkan.', [], 405);
}
if (!verifyCsrfToken()) {
    jsonResponse(false, 'Token keamanan tidak valid.', [], 403);
}
$waveId = filter_input(INPUT_POST, 'wave_id', FILTER_VALIDATE_INT);
if (!$waveId || $waveId < 1) {
    jsonResponse(false, 'Gelombang tidak valid.', [], 400);
}
$result = createQueueTicket($waveId);
if (!$result['success']) {
    jsonResponse(false, $result['message'], [], 409);
}
jsonResponse(true, 'Tiket berhasil dibuat.', $result, 201);
```

Jangan menerima `room_id`, status, atau identitas petugas sebagai bukti hak akses dari browser. Server mengambil identitas dari session dan menguji ulang penugasan serta status tiket dari database.

Contoh pola endpoint aksi petugas `actions/call-ticket.php`. Fungsi bisnis `callQueueTicket()` harus memastikan tiket memang berada pada gelombang dan ruangan yang ditugaskan kepada petugas:

```php
<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

requireStaffApiAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metode tidak diizinkan.', [], 405);
}
if (!verifyCsrfToken()) {
    jsonResponse(false, 'Token keamanan tidak valid.', [], 403);
}
$ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
if (!$ticketId || $ticketId < 1) {
    jsonResponse(false, 'ID tiket tidak valid.', [], 400);
}
$result = callQueueTicket((int)$_SESSION['user_id'], $ticketId);
jsonResponse($result['success'], $result['message'], $result['data'] ?? [], $result['code'] ?? 200);
```

## Tahap 7 — Buat pengalaman pengunjung

Di `user/index.php`, tampilkan gelombang aktif, jam sesi, dan kuota. Saat pengunjung memilih gelombang, kirim `wave_id` ke endpoint pembuatan tiket dan arahkan ke halaman hasil dengan kode tiket.

Di `user/ticket-result.php`, tampilkan kode dan status tiket, ruangan, gelombang, nomor yang sedang dilayani, serta jumlah antrean di depan jika status masih menunggu. Poll status secara berkala; saat dipanggil tampilkan pemberitahuan, dan saat selesai tampilkan tampilan tiket grayscale serta opsi kembali mengambil tiket.

`index.php` pada root mengarahkan pengunjung ke halaman pilih gelombang.

Contoh pengarah root `index.php`:

```php
<?php
require_once __DIR__ . '/config/app.php';
header('Location: user/index.php');
exit;
```

Contoh elemen halaman dan JavaScript untuk memanggil endpoint. Token CSRF dibuat di PHP lalu ditambahkan ke POST:

```php
<div id="waves">
  <?php foreach ($waves as $wave): ?>
    <button type="button" onclick="takeTicket(<?= (int)$wave['id'] ?>)">
      <?= e($wave['wave_name']) ?> — sisa <?= (int)$wave['remaining_quota'] ?>
    </button>
  <?php endforeach; ?>
</div>
<p id="message" role="status"></p>
<script>
const csrfToken = <?= json_encode(getCsrfToken()) ?>;
async function takeTicket(waveId) {
  const body = new FormData();
  body.append('wave_id', waveId);
  body.append('csrf_token', csrfToken);
  const response = await fetch('../actions/create-ticket.php', { method: 'POST', body });
  const result = await response.json();
  if (result.success) {
    location.href = `ticket-result.php?code=${encodeURIComponent(result.data.ticket_code)}`;
  } else {
    document.querySelector('#message').textContent = result.message;
  }
}
</script>
```

Saat menampilkan data dari server, escape teks HTML dengan `e()` atau gunakan `textContent` di JavaScript. Jangan memasukkan teks yang belum dipercaya langsung ke `innerHTML`.

Pada halaman hasil tiket, baca kode dari query string dan minta status terbaru secara berkala. Endpoint status harus memvalidasi kode dan memastikan tiket tersebut dapat dilihat oleh perangkat pemiliknya:

```html
<p>Nomor: <strong id="ticket-number">Memuat...</strong></p>
<p>Status: <span id="ticket-status">Memuat...</span></p>
<p>Dipanggil sekarang: <span id="current-number">-</span></p>
<script>
const ticketCode = new URLSearchParams(location.search).get('code');
async function refreshTicket() {
  if (!ticketCode) return;
  const url = `../actions/get-ticket-status.php?code=${encodeURIComponent(ticketCode)}`;
  const response = await fetch(url);
  const result = await response.json();
  if (!result.success) return;
  document.querySelector('#ticket-number').textContent = result.data.display_code;
  let statusText = result.data.status;
  document.querySelector('#current-number').textContent = result.data.current_serving ?? '-';
  if (result.data.status === 'waiting') {
    statusText += ` (${result.data.ahead_count} antrean di depan)`;
  }
  document.querySelector('#ticket-status').textContent = statusText;
  if (result.data.status === 'completed') clearInterval(polling);
}
refreshTicket();
const polling = setInterval(refreshTicket, 4000);
</script>
```

## Tahap 8 — Buat halaman petugas

1. Buat `petugas/login.php` dan lindungi `petugas/dashboard.php` dengan autentikasi.
2. Tampilkan identitas akun dan daftar penugasan aktif petugas.
3. Sediakan pilihan gelombang/ruangan, ringkasan status, tabel tiket aktif, tabel tiket selesai, refresh manual, dan tes suara.
4. Tampilkan aksi sesuai status: `Panggil` untuk `waiting`; `Panggil Ulang` dan `Selesai` untuk `called`; arsipkan hanya tiket `completed`.
5. Setelah panggilan berhasil, bacakan nomor antrean dengan Web Speech API bila browser mendukungnya.
6. Buat `petugas/logout.php` untuk mengakhiri session.

Form login sederhana mengirim username/password melalui POST, sedangkan halaman dashboard wajib memeriksa session sebelum menampilkan data:

```php
<?php
// Bagian pemrosesan paling atas petugas/login.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $errorMessage = 'Token keamanan tidak valid. Muat ulang halaman.';
    } elseif (attemptLogin((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
        header('Location: dashboard.php');
        exit;
    } else {
        $errorMessage = 'Username atau password salah, atau akun tidak aktif.';
    }
}
?>
```

```php
<!-- petugas/login.php -->
<form method="post">
  <?= csrfInput() ?>
  <?php if ($errorMessage !== ''): ?><p role="alert"><?= e($errorMessage) ?></p><?php endif; ?>
  <label>Username <input name="username" autocomplete="username" required></label>
  <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
  <button type="submit">Masuk</button>
</form>
```

```php
<!-- Awal petugas/dashboard.php -->
<?php
require_once __DIR__ . '/../includes/functions.php';
requireStaffPageAuth();
?>
<h1>Dashboard Petugas</h1>
<p>Halo, <?= e($_SESSION['user_name'] ?? '') ?></p>
```

Untuk mengubah status, endpoint memeriksa login, CSRF, dan hak petugas terhadap tiket sebelum menjalankan query. Pola request dari browser:

```js
async function postStaffAction(url, ticketId, csrfToken) {
  const body = new FormData();
  body.append('ticket_id', ticketId);
  body.append('csrf_token', csrfToken);
  const response = await fetch(url, { method: 'POST', body });
  return response.json();
}

function speakQueueNumber(number) {
  if (!('speechSynthesis' in window)) return;
  const utterance = new SpeechSynthesisUtterance(`Nomor antrean ${number}, silakan menuju petugas`);
  utterance.lang = 'id-ID';
  window.speechSynthesis.speak(utterance);
}
```

## Tahap 9 — Tambahkan desain dan interaksi

Gunakan CSS untuk membedakan antrean menunggu, dipanggil, selesai, kuota tersedia, dan kuota penuh. Pastikan tabel dan tombol mudah digunakan saat operasional serta tetap terbaca di layar kecil. Pisahkan stylesheet berdasarkan halaman, seperti `assets/css/index-guest.css`, `assets/css/ticket-result.css`, `assets/css/login-petugas.css`, dan stylesheet dashboard di `assets/css/`. Letakkan ikon, font, dan gambar di subfolder `assets/` yang sesuai. JavaScript halaman menangani pengambilan data, polling, pesan kesalahan, konfirmasi aksi, dan pembacaan nomor menggunakan Web Speech API.

Contoh stylesheet dasar responsif:

```css
:root { font-family: Arial, sans-serif; color: #202020; background: #f4f4f4; }
body { margin: 0; padding: 1.5rem; }
.panel { max-width: 60rem; margin: auto; padding: 1.25rem; background: #fff; border: 1px solid #ddd; }
button { min-height: 2.75rem; padding: .5rem 1rem; cursor: pointer; }
.status-waiting { color: #805b00; }
.status-called { color: #12653c; font-weight: 700; }
.status-completed { color: #555; }
@media (max-width: 600px) { body { padding: .75rem; } table { display: block; overflow-x: auto; } }
```

## Tahap 10 — Jalankan website secara lokal

Pastikan MySQL/MariaDB berjalan, database dan data master sudah tersedia, serta environment variable koneksi PDO sudah diisi. Dari direktori root proyek, jalankan perintah yang mengatur kredensial sekaligus menyalakan server:

```sh
DOSQUEUE_DB_HOST=127.0.0.1 DOSQUEUE_DB_NAME=antrian_db \
DOSQUEUE_DB_USER=root DOSQUEUE_DB_PASS='' php -S 127.0.0.1:8000
```

Buka `http://127.0.0.1:8000/`. Jika ada kegagalan koneksi, periksa nama database, user/password lokal, server database, dan ekstensi `pdo_mysql`.

Jika membuat database lewat file SQL, jalankan dari terminal di terminal lain:

```sh
mysql -u root -p < database/antrian_db.sql
```

Pastikan kredensial database sudah tersedia untuk proses PHP sebelum membuka halaman.

## Tahap 11 — Periksa alur dari awal sampai selesai

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

## Tahap 12 — Siapkan untuk penggunaan nyata

Sebelum dipublikasikan, isi skema database yang saat ini kosong, pindahkan rahasia koneksi dari source code ke konfigurasi lingkungan yang tidak dipublikasikan, gunakan HTTPS, buat akun dan penugasan resmi, serta uji skenario bersamaan dan pencadangan database. Jangan menganggap server PHP bawaan cocok sebagai server produksi.
