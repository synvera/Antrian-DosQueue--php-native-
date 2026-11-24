<?php
// actions/get-waves.php
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

$data = getActiveWavesWithQuota();
jsonResponse(true, 'Data gelombang berhasil dimuat.', $data);