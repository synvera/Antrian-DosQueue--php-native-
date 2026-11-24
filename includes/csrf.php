<?php
// includes/csrf.php

require_once __DIR__ . '/../config/app.php';

/**
 * Mengambil atau membuat CSRF token pada session aktif
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Menghasilkan input hidden HTML berisi CSRF token
 */
function csrfInput(): string
{
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Memvalidasi token CSRF dari form POST atau Header AJAX (X-CSRF-TOKEN)
 */
function verifyCsrfToken(?string $token = null): bool
{
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}