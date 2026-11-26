<?php
// config/database.php

require_once __DIR__ . '/app.php';

function getDBConnection(): PDO
{
    static $pdo = null;

    // nanti isi ini diubah saja, sesuai dengan "root" bawaan laragon
    if ($pdo === null) {
        $host    = 'localhost';
        $dbName  = 'antrian_db';
        $dbUser  = 'kahfi';
        $dbPass  = '534412mkh'; // Sesuaikan jika MySQL lokal Anda menggunakan password
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$dbName};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Wajib false untuk keamanan SQL Injection maksimal
        ];

        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            error_log('Database Connection Error: ' . $e->getMessage());
            http_response_code(500);
            exit('Terjadi gangguan koneksi pada server. Silakan coba beberapa saat lagi.');
        }
    }

    return $pdo;
}
