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
    <title>Login Petugas (Basic)</title>
</head>
<body>
    <h2>Login Petugas</h2>

    <?php if ($error !== ''): ?>
        <p><strong>Error:</strong> <?= e($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="">
        <?= csrfInput() ?>
        <div>
            <label for="username">Username:</label><br>
            <input type="text" id="username" name="username" required autofocus>
        </div>
        <br>
        <div>
            <label for="password">Password:</label><br>
            <input type="password" id="password" name="password" required>
        </div>
        <br>
        <button type="submit">Masuk</button>
    </form>
</body>
</html>