<?php
// petugas/login.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (isLoggedIn()) {
    redirectTo('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $result   = attemptLogin($username, $password);

        if ($result['success']) {
            redirectTo('/petugas/dashboard.php');
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Petugas</title>
    <!-- Font Monospace agar mirip dengan referensi -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/login-petugas.css">
</head>
<body>
    <main class="login-wrapper">
        <!-- Bagian Gambar (Kiri di Desktop, Atas di HP) -->
        <div class="image-section">
            <!-- GANTI src di bawah ini dengan path foto Anda -->
            <img src="../assets/images/login-image.jpeg" alt="Ilustrasi Login" onerror="this.style.display='none'">
        </div>

        <!-- Bagian Form (Kanan di Desktop, Bawah di HP) -->
        <div class="form-section">
            <div class="form-container">
                <?php if ($error !== ''): ?>
                    <div class="error-message">
                        <strong>Error:</strong> <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrfInput() ?>
                    
                    <div class="input-group">
                        <input type="text" id="username" name="username" placeholder="Username" required autofocus>
                    </div>

                    <div class="input-group">
                        <input type="password" id="password" name="password" placeholder="Password" required>
                    </div>

                    <button type="submit" class="btn-login">Login</button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>