<?php
// includes/helpers.php

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function jsonResponse(bool $success, string $message, array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getTodayServiceDate(): string
{
    return date('Y-m-d');
}

function redirectTo(string $path): void
{
    header('Location: ' . $path);
    exit;
}