<?php
// actions/create-ticket.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

if (!verifyCsrfToken()) {
    jsonResponse(false, 'Token keamanan tidak valid. Silakan muat ulang halaman.', [], 403);
}

// Validasi backend: Cek apakah device ini sudah memiliki tiket hari ini
$existingTicket = getActiveGuestDeviceTicket();
if ($existingTicket !== null) {
    jsonResponse(false, 'Perangkat ini sudah mengambil tiket antrian hari ini.', [
        'ticket_code' => $existingTicket['ticket_code']
    ], 409);
}

$waveId = filter_input(INPUT_POST, 'wave_id', FILTER_VALIDATE_INT);
if (!$waveId) {
    $rawInput = json_decode(file_get_contents('php://input'), true);
    $waveId = isset($rawInput['wave_id']) ? (int)$rawInput['wave_id'] : 0;
}

if ($waveId <= 0) {
    jsonResponse(false, 'Pilihan gelombang tidak valid.', [], 400);
}

$result = createQueueTicket($waveId);

// Jika tiket berhasil dibuat, kunci tiket ke perangkat guest ini
if ($result['success'] && !empty($result['data']['ticket_code'])) {
    bindTicketToGuestDevice($result['data']['ticket_code']);
}

jsonResponse(
    $result['success'],
    $result['message'],
    $result['data'] ?? [],
    $result['code'] ?? 200
);