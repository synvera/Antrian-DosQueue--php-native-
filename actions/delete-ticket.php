<?php
// actions/delete-ticket.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

requireStaffApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

if (!verifyCsrfToken()) {
    jsonResponse(false, 'Token keamanan tidak valid. Silakan muat ulang halaman.', [], 403);
}

$userId   = (int)$_SESSION['user_id'];
$ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT) ?: 0;

if ($ticketId <= 0) {
    jsonResponse(false, 'ID tiket tidak valid.', [], 400);
}

$result = archiveCompletedTicket($userId, $ticketId);
jsonResponse($result['success'], $result['message'], $result['data'] ?? [], $result['code'] ?? 200);