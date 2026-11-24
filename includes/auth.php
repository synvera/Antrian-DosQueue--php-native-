<?php
// includes/auth.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * Proses login petugas / admin
 */
function attemptLogin(string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['success' => false, 'message' => 'Username dan password wajib diisi.'];
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, name, username, password, role, is_active 
        FROM users 
        WHERE username = :username 
        LIMIT 1
    ");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Username atau password salah.'];
    }

    if ((int)$user['is_active'] !== 1) {
        return ['success' => false, 'message' => 'Akun Anda berstatus nonaktif. Hubungi administrator.'];
    }

    // Wajib regenerasi session ID setelah login berhasil (mencegah Session Fixation)
    session_regenerate_id(true);

    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['logged_in'] = true;

    return ['success' => true, 'message' => 'Login berhasil.'];
}

/**
 * Mengecek apakah user sedang login
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
}

/**
 * Middleware proteksi halaman HTML petugas
 */
function requireStaffPageAuth(): void
{
    if (!isLoggedIn()) {
        redirectTo('login.php');
    }

    if (!in_array($_SESSION['role'] ?? '', ['petugas', 'admin'], true)) {
        logoutUser();
        redirectTo('login.php');
    }
}

/**
 * Middleware proteksi endpoint AJAX/JSON petugas
 */
function requireStaffApiAuth(): void
{
    if (!isLoggedIn() || !in_array($_SESSION['role'] ?? '', ['petugas', 'admin'], true)) {
        jsonResponse(false, 'Sesi Anda telah berakhir. Silakan login kembali.', [], 401);
    }
}

/**
 * Mengambil daftar penugasan gelombang & ruangan yang sah milik petugas dari database
 */
function getStaffAssignments(int $userId): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT 
            sa.id AS assignment_id,
            sa.wave_id,
            w.wave_name,
            w.start_time,
            w.end_time,
            w.is_active AS wave_active,
            sa.room_id,
            r.room_name,
            r.is_active AS room_active
        FROM staff_assignments sa
        INNER JOIN waves w ON sa.wave_id = w.id
        INNER JOIN rooms r ON sa.room_id = r.id
        WHERE sa.user_id = :user_id
          AND sa.is_active = 1
          AND w.is_active = 1
          AND r.is_active = 1
        ORDER BY w.id ASC, r.id ASC
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Validasi backend apakah petugas berhak mengakses kombinasi wave_id dan room_id tertentu
 */
function verifyStaffAssignment(int $userId, int $waveId, int $roomId): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM staff_assignments sa
        INNER JOIN waves w ON sa.wave_id = w.id
        INNER JOIN rooms r ON sa.room_id = r.id
        WHERE sa.user_id = :user_id
          AND sa.wave_id = :wave_id
          AND sa.room_id = :room_id
          AND sa.is_active = 1
          AND w.is_active = 1
          AND r.is_active = 1
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':wave_id' => $waveId,
        ':room_id' => $roomId
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Menghapus seluruh data session saat logout
 */
function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}