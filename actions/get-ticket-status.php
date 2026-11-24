<?php
// actions/get-ticket-status.php
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

$ticketCode = $_GET['code'] ?? '';
$ticketData = getTicketStatusDetail($ticketCode);

if (!$ticketData) {
    jsonResponse(false, 'Tiket tidak ditemukan atau sudah diarsipkan.', [], 404);
}

jsonResponse(true, 'Status tiket ditemukan.', $ticketData);