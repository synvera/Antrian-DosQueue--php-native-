<?php
// actions/get-staff-tickets.php
require_once __DIR__ . '/../includes/functions.php';

requireStaffApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

$userId = (int)$_SESSION['user_id'];
$waveId = filter_input(INPUT_GET, 'wave_id', FILTER_VALIDATE_INT) ?: 0;
$roomId = filter_input(INPUT_GET, 'room_id', FILTER_VALIDATE_INT) ?: 0;

if ($waveId <= 0 || $roomId <= 0) {
    jsonResponse(false, 'Parameter gelombang atau ruangan tidak valid.', [], 400);
}

$result = getStaffDashboardData($userId, $waveId, $roomId);
jsonResponse($result['success'], $result['message'], $result['data'] ?? [], $result['code'] ?? 200);