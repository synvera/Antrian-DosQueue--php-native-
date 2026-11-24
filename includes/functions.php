<?php
// includes/functions.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// =====================================================================
// BAGIAN 1: FUNGSI SISI USER (TAHAP 4)
// =====================================================================

function getActiveWavesWithQuota(?string $serviceDate = null): array
{
    $pdo = getDBConnection();
    $serviceDate = $serviceDate ?? getTodayServiceDate();

    $stmt = $pdo->prepare("
        SELECT 
            w.id,
            w.wave_name,
            w.start_time,
            w.end_time,
            w.quota,
            COALESCE(COUNT(t.id), 0) AS used_quota
        FROM waves w
        LEFT JOIN tickets t 
            ON t.wave_id = w.id 
            AND t.service_date = :service_date 
            AND t.deleted_at IS NULL
            AND t.status IN ('waiting', 'called', 'completed')
        WHERE w.is_active = 1
        GROUP BY w.id, w.wave_name, w.start_time, w.end_time, w.quota
        ORDER BY w.start_time ASC, w.id ASC
    ");
    $stmt->execute([':service_date' => $serviceDate]);
    $waves = $stmt->fetchAll();

    $hasAnyAvailableQuota = false;

    foreach ($waves as &$wave) {
        $maxQuota  = (int)$wave['quota'];
        $usedQuota = (int)$wave['used_quota'];
        $remaining = max(0, $maxQuota - $usedQuota);
        $percent   = $maxQuota > 0 ? round(($usedQuota / $maxQuota) * 100, 1) : 100;

        $wave['id']              = (int)$wave['id'];
        $wave['quota']           = $maxQuota;
        $wave['used_quota']      = $usedQuota;
        $wave['remaining_quota'] = $remaining;
        $wave['usage_percent']   = min(100, $percent);
        $wave['is_available']    = ($remaining > 0);

        if ($remaining > 0) {
            $hasAnyAvailableQuota = true;
        }
    }
    unset($wave);

    return [
        'service_date'       => $serviceDate,
        'waves'              => $waves,
        'all_full'           => !$hasAnyAvailableQuota,
        'empty_quota_notice' => !$hasAnyAvailableQuota
            ? 'Pengambilan tiket antrian tidak tersedia dikarenakan tidak ada kuota antrian yang tersedia di berbagai gelombang.'
            : ''
    ];
}

function createQueueTicket(int $waveId): array
{
    $pdo = getDBConnection();
    $serviceDate = getTodayServiceDate();

    try {
        $pdo->beginTransaction();

        $stmtWave = $pdo->prepare("
            SELECT id, wave_name, start_time, end_time, quota, is_active 
            FROM waves 
            WHERE id = :wave_id 
            FOR UPDATE
        ");
        $stmtWave->execute([':wave_id' => $waveId]);
        $wave = $stmtWave->fetch();

        if (!$wave || (int)$wave['is_active'] !== 1) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Gelombang yang dipilih tidak valid atau sedang nonaktif.', 'code' => 400];
        }

        $stmtCount = $pdo->prepare("
            SELECT COUNT(*) 
            FROM tickets 
            WHERE wave_id = :wave_id 
              AND service_date = :service_date 
              AND deleted_at IS NULL
              AND status IN ('waiting', 'called', 'completed')
        ");
        $stmtCount->execute([
            ':wave_id'      => $waveId,
            ':service_date' => $serviceDate
        ]);
        $usedQuota = (int)$stmtCount->fetchColumn();

        if ($usedQuota >= (int)$wave['quota']) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Kuota pada gelombang ini sudah penuh. Silakan pilih gelombang lain.', 'code' => 409];
        }

        $stmtRooms = $pdo->query("
            SELECT id, room_name 
            FROM rooms 
            WHERE is_active = 1 
            ORDER BY id ASC
        ");
        $activeRooms = $stmtRooms->fetchAll();

        if (empty($activeRooms)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Tidak ada ruangan pelayanan yang aktif saat ini.', 'code' => 500];
        }

        $stmtMax = $pdo->prepare("
            SELECT COALESCE(MAX(queue_number), 0) 
            FROM tickets 
            WHERE wave_id = :wave_id 
              AND service_date = :service_date
        ");
        $stmtMax->execute([
            ':wave_id'      => $waveId,
            ':service_date' => $serviceDate
        ]);
        $nextQueueNumber = (int)$stmtMax->fetchColumn() + 1;

        $roomCount = count($activeRooms);
        $roomIndex = ($nextQueueNumber - 1) % $roomCount;
        $assignedRoom = $activeRooms[$roomIndex];

        $dateCompact = str_replace('-', '', $serviceDate);
        $formattedSeq = str_pad((string)$nextQueueNumber, 3, '0', STR_PAD_LEFT);
        $ticketCode = sprintf('G%d-%s-%s', $waveId, $dateCompact, $formattedSeq);
        $displayCode = sprintf('G%d-R%d-%s', $waveId, (int)$assignedRoom['id'], $formattedSeq);

        $stmtInsert = $pdo->prepare("
            INSERT INTO tickets (
                ticket_code, queue_number, service_date, wave_id, room_id, status, created_at
            ) VALUES (
                :ticket_code, :queue_number, :service_date, :wave_id, :room_id, 'waiting', NOW()
            )
        ");
        $stmtInsert->execute([
            ':ticket_code'  => $ticketCode,
            ':queue_number' => $nextQueueNumber,
            ':service_date' => $serviceDate,
            ':wave_id'      => $waveId,
            ':room_id'      => (int)$assignedRoom['id']
        ]);

        $ticketId = (int)$pdo->lastInsertId();
        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Tiket antrian berhasil dibuat.',
            'code'    => 201,
            'data'    => [
                'id'               => $ticketId,
                'ticket_code'      => $ticketCode,
                'display_code'     => $displayCode,
                'queue_number'     => $nextQueueNumber,
                'formatted_number' => $formattedSeq,
                'service_date'     => $serviceDate,
                'wave_id'          => (int)$wave['id'],
                'wave_name'        => $wave['wave_name'],
                'start_time'       => $wave['start_time'],
                'end_time'         => $wave['end_time'],
                'room_id'          => (int)$assignedRoom['id'],
                'room_name'        => $assignedRoom['room_name'],
                'status'           => 'waiting'
            ]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Create Ticket Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal memproses pengambilan tiket. Silakan coba lagi.', 'code' => 500];
    }
}

function getTicketStatusDetail(string $ticketCode): ?array
{
    $ticketCode = strtoupper(trim($ticketCode));
    if ($ticketCode === '') {
        return null;
    }

    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.ticket_code,
            t.queue_number,
            t.service_date,
            t.wave_id,
            w.wave_name,
            w.start_time,
            w.end_time,
            t.room_id,
            r.room_name,
            t.status,
            t.created_at,
            t.called_at,
            t.completed_at
        FROM tickets t
        INNER JOIN waves w ON t.wave_id = w.id
        INNER JOIN rooms r ON t.room_id = r.id
        WHERE t.ticket_code = :ticket_code
          AND t.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':ticket_code' => $ticketCode]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        return null;
    }

    // Cari tiket yang sedang dipanggil (called) di ruangan, gelombang, dan tanggal yang sama
    $stmtCurrent = $pdo->prepare("
        SELECT ticket_code, queue_number, wave_id, room_id 
        FROM tickets 
        WHERE service_date = :service_date 
          AND wave_id = :wave_id 
          AND room_id = :room_id 
          AND status = 'called' 
          AND deleted_at IS NULL
        ORDER BY called_at DESC 
        LIMIT 1
    ");
    $stmtCurrent->execute([
        ':service_date' => $ticket['service_date'],
        ':wave_id'      => (int)$ticket['wave_id'],
        ':room_id'      => (int)$ticket['room_id']
    ]);
    $currentServing = $stmtCurrent->fetch();

    $currentServingShort = null;
    if ($currentServing) {
        $currSeq = str_pad((string)$currentServing['queue_number'], 3, '0', STR_PAD_LEFT);
        $currentServingShort = sprintf('G%d-R%d-%s', $currentServing['wave_id'], $currentServing['room_id'], $currSeq);
    }

    $aheadCount = 0;
    if ($ticket['status'] === 'waiting') {
        $stmtAhead = $pdo->prepare("
            SELECT COUNT(*) 
            FROM tickets 
            WHERE service_date = :service_date 
              AND wave_id = :wave_id 
              AND room_id = :room_id 
              AND status = 'waiting' 
              AND queue_number < :queue_number 
              AND deleted_at IS NULL
        ");
        $stmtAhead->execute([
            ':service_date' => $ticket['service_date'],
            ':wave_id'      => (int)$ticket['wave_id'],
            ':room_id'      => (int)$ticket['room_id'],
            ':queue_number' => (int)$ticket['queue_number']
        ]);
        $aheadCount = (int)$stmtAhead->fetchColumn();
    }

    $formattedSeq = str_pad((string)$ticket['queue_number'], 3, '0', STR_PAD_LEFT);
    $displayCode  = sprintf('G%d-R%d-%s', $ticket['wave_id'], $ticket['room_id'], $formattedSeq);

    return [
        'id'               => (int)$ticket['id'],
        'ticket_code'      => $ticket['ticket_code'],
        'display_code'     => $displayCode,
        'queue_number'     => (int)$ticket['queue_number'],
        'formatted_number' => $formattedSeq,
        'service_date'     => $ticket['service_date'],
        'wave_id'          => (int)$ticket['wave_id'],
        'wave_name'        => $ticket['wave_name'],
        'start_time'       => $ticket['start_time'],
        'end_time'         => $ticket['end_time'],
        'room_id'          => (int)$ticket['room_id'],
        'room_name'        => $ticket['room_name'],
        'status'           => $ticket['status'],
        'created_at'       => $ticket['created_at'],
        'called_at'        => $ticket['called_at'],
        'completed_at'     => $ticket['completed_at'],
        'current_serving'  => $currentServingShort,
        'ahead_count'      => $aheadCount
    ];
}

// =====================================================================
// BAGIAN 2: FUNGSI OPERASIONAL DASHBOARD PETUGAS (TAHAP 5)
// =====================================================================

function getStaffDashboardData(int $userId, int $waveId, int $roomId, ?string $serviceDate = null): array
{
    if (!verifyStaffAssignment($userId, $waveId, $roomId)) {
        return ['success' => false, 'message' => 'Akses ditolak. Anda tidak ditugaskan pada gelombang dan ruangan ini.', 'code' => 403];
    }

    $pdo = getDBConnection();
    $serviceDate = $serviceDate ?? getTodayServiceDate();

    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.ticket_code,
            t.queue_number,
            t.wave_id,
            t.room_id,
            t.status,
            t.created_at,
            t.called_at,
            t.completed_at,
            r.room_name,
            w.wave_name,
            (SELECT COALESCE(MAX(call_number), 0) FROM call_logs cl WHERE cl.ticket_id = t.id) AS total_calls
        FROM tickets t
        INNER JOIN rooms r ON t.room_id = r.id
        INNER JOIN waves w ON t.wave_id = w.id
        WHERE t.service_date = :service_date
          AND t.wave_id = :wave_id
          AND t.room_id = :room_id
          AND t.deleted_at IS NULL
        ORDER BY t.queue_number ASC
    ");
    $stmt->execute([
        ':service_date' => $serviceDate,
        ':wave_id'      => $waveId,
        ':room_id'      => $roomId
    ]);
    $allTickets = $stmt->fetchAll();

    $activeQueue = [];
    $completedList = [];
    $waitingCount = 0;
    $calledTicket = null;

    foreach ($allTickets as $row) {
        $row['id']               = (int)$row['id'];
        $row['queue_number']     = (int)$row['queue_number'];
        $row['formatted_number'] = str_pad((string)$row['queue_number'], 3, '0', STR_PAD_LEFT);
        $row['display_code']     = sprintf('G%d-R%d-%s', $row['wave_id'], $row['room_id'], $row['formatted_number']);
        $row['total_calls']      = (int)$row['total_calls'];

        if ($row['status'] === 'called') {
            $calledTicket = $row;
            $activeQueue[] = $row;
        } elseif ($row['status'] === 'waiting') {
            $waitingCount++;
            $activeQueue[] = $row;
        } elseif ($row['status'] === 'completed') {
            $completedList[] = $row;
        }
    }

    usort($completedList, static function ($a, $b) {
        return strcmp((string)$b['completed_at'], (string)$a['completed_at']);
    });

    return [
        'success' => true,
        'message' => 'Data dashboard berhasil dimuat.',
        'code'    => 200,
        'data'    => [
            'service_date'      => $serviceDate,
            'summary'           => [
                'waiting_count'   => $waitingCount,
                'called_count'    => $calledTicket ? 1 : 0,
                'completed_count' => count($completedList),
                'has_active_call' => ($calledTicket !== null)
            ],
            'current_called'    => $calledTicket,
            'active_tickets'    => $activeQueue,
            'completed_tickets' => $completedList
        ]
    ];
}

function callQueueTicket(int $userId, int $ticketId): array
{
    $pdo = getDBConnection();

    try {
        $pdo->beginTransaction();

        $stmtTicket = $pdo->prepare("
            SELECT t.id, t.ticket_code, t.queue_number, t.service_date, t.wave_id, t.room_id, t.status, t.deleted_at,
                   r.room_name, w.wave_name
            FROM tickets t
            INNER JOIN rooms r ON t.room_id = r.id
            INNER JOIN waves w ON t.wave_id = w.id
            WHERE t.id = :ticket_id
            FOR UPDATE
        ");
        $stmtTicket->execute([':ticket_id' => $ticketId]);
        $ticket = $stmtTicket->fetch();

        if (!$ticket || $ticket['deleted_at'] !== null) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Tiket tidak ditemukan.', 'code' => 404];
        }

        if (!verifyStaffAssignment($userId, (int)$ticket['wave_id'], (int)$ticket['room_id'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Akses ditolak. Anda tidak berwenang memanggil tiket ruangan/gelombang ini.', 'code' => 403];
        }

        if ($ticket['status'] !== 'waiting') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Tiket tidak dapat dipanggil karena statusnya sudah berubah (' . $ticket['status'] . ').', 'code' => 409];
        }

        $stmtActiveCheck = $pdo->prepare("
            SELECT id, ticket_code 
            FROM tickets 
            WHERE service_date = :service_date 
              AND wave_id = :wave_id 
              AND room_id = :room_id 
              AND status = 'called' 
              AND deleted_at IS NULL
            LIMIT 1
            FOR UPDATE
        ");
        $stmtActiveCheck->execute([
            ':service_date' => $ticket['service_date'],
            ':wave_id'      => (int)$ticket['wave_id'],
            ':room_id'      => (int)$ticket['room_id']
        ]);
        $existingCalled = $stmtActiveCheck->fetch();

        if ($existingCalled) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Masih ada tiket aktif (' . $existingCalled['ticket_code'] . ') yang sedang dilayani. Selesaikan terlebih dahulu sebelum memanggil tiket berikutnya.',
                'code'    => 409
            ];
        }

        $stmtUpdate = $pdo->prepare("
            UPDATE tickets 
            SET status = 'called', called_at = NOW() 
            WHERE id = :id
        ");
        $stmtUpdate->execute([':id' => $ticketId]);

        $stmtLog = $pdo->prepare("
            INSERT INTO call_logs (ticket_id, user_id, called_at, call_number)
            VALUES (:ticket_id, :user_id, NOW(), 1)
        ");
        $stmtLog->execute([
            ':ticket_id' => $ticketId,
            ':user_id'   => $userId
        ]);

        $pdo->commit();

        $formattedNum = str_pad((string)$ticket['queue_number'], 3, '0', STR_PAD_LEFT);
        $displayCode  = sprintf('G%d-R%d-%s', $ticket['wave_id'], $ticket['room_id'], $formattedNum);
        $speechText   = sprintf('Nomor antrian %s, silakan menuju %s.', $displayCode, $ticket['room_name']);

        return [
            'success' => true,
            'message' => 'Nomor antrian ' . $displayCode . ' berhasil dipanggil.',
            'code'    => 200,
            'data'    => [
                'ticket_id'        => (int)$ticket['id'],
                'ticket_code'      => $ticket['ticket_code'],
                'display_code'     => $displayCode,
                'formatted_number' => $formattedNum,
                'room_name'        => $ticket['room_name'],
                'call_number'      => 1,
                'speech_text'      => $speechText
            ]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Call Ticket Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Terjadi kesalahan saat memanggil tiket.', 'code' => 500];
    }
}

function recallQueueTicket(int $userId, int $ticketId): array
{
    $pdo = getDBConnection();

    try {
        $pdo->beginTransaction();

        $stmtTicket = $pdo->prepare("
            SELECT t.id, t.ticket_code, t.queue_number, t.wave_id, t.room_id, t.status, t.deleted_at,
                   r.room_name
            FROM tickets t
            INNER JOIN rooms r ON t.room_id = r.id
            WHERE t.id = :ticket_id
            FOR UPDATE
        ");
        $stmtTicket->execute([':ticket_id' => $ticketId]);
        $ticket = $stmtTicket->fetch();

        if (!$ticket || $ticket['deleted_at'] !== null) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Tiket tidak ditemukan.', 'code' => 404];
        }

        if (!verifyStaffAssignment($userId, (int)$ticket['wave_id'], (int)$ticket['room_id'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Akses ditolak. Anda tidak berwenang memanggil ulang tiket ini.', 'code' => 403];
        }

        if ($ticket['status'] !== 'called') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Hanya tiket yang sedang dipanggil (called) yang dapat dipanggil ulang.', 'code' => 409];
        }

        $stmtCallNum = $pdo->prepare("
            SELECT COALESCE(MAX(call_number), 0) + 1 
            FROM call_logs 
            WHERE ticket_id = :ticket_id
        ");
        $stmtCallNum->execute([':ticket_id' => $ticketId]);
        $nextCallNumber = (int)$stmtCallNum->fetchColumn();

        $stmtLog = $pdo->prepare("
            INSERT INTO call_logs (ticket_id, user_id, called_at, call_number)
            VALUES (:ticket_id, :user_id, NOW(), :call_number)
        ");
        $stmtLog->execute([
            ':ticket_id'   => $ticketId,
            ':user_id'     => $userId,
            ':call_number' => $nextCallNumber
        ]);

        $stmtUpdate = $pdo->prepare("UPDATE tickets SET called_at = NOW() WHERE id = :id");
        $stmtUpdate->execute([':id' => $ticketId]);

        $pdo->commit();

        $formattedNum = str_pad((string)$ticket['queue_number'], 3, '0', STR_PAD_LEFT);
        $displayCode  = sprintf('G%d-R%d-%s', $ticket['wave_id'], $ticket['room_id'], $formattedNum);
        $speechText   = sprintf('Panggilan ulang. Nomor antrian %s, silakan menuju %s.', $displayCode, $ticket['room_name']);

        return [
            'success' => true,
            'message' => 'Panggil ulang nomor antrian ' . $displayCode . ' (ke-' . $nextCallNumber . ') berhasil.',
            'code'    => 200,
            'data'    => [
                'ticket_id'        => (int)$ticket['id'],
                'ticket_code'      => $ticket['ticket_code'],
                'display_code'     => $displayCode,
                'formatted_number' => $formattedNum,
                'room_name'        => $ticket['room_name'],
                'call_number'      => $nextCallNumber,
                'speech_text'      => $speechText
            ]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Recall Ticket Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Terjadi kesalahan saat melakukan panggil ulang.', 'code' => 500];
    }
}

function completeQueueTicket(int $userId, int $ticketId): array
{
    $pdo = getDBConnection();

    try {
        $pdo->beginTransaction();

        $stmtTicket = $pdo->prepare("
            SELECT id, ticket_code, wave_id, room_id, status, deleted_at 
            FROM tickets 
            WHERE id = :ticket_id 
            FOR UPDATE
        ");
        $stmtTicket->execute([':ticket_id' => $ticketId]);
        $ticket = $stmtTicket->fetch();

        if (!$ticket || $ticket['deleted_at'] !== null) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Tiket tidak ditemukan.', 'code' => 404];
        }

        if (!verifyStaffAssignment($userId, (int)$ticket['wave_id'], (int)$ticket['room_id'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Akses ditolak. Anda tidak berwenang menyelesaikan tiket ini.', 'code' => 403];
        }

        if ($ticket['status'] !== 'called') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Hanya tiket berstatus called yang dapat diselesaikan.', 'code' => 409];
        }

        $stmtUpdate = $pdo->prepare("
            UPDATE tickets 
            SET status = 'completed', completed_at = NOW() 
            WHERE id = :id
        ");
        $stmtUpdate->execute([':id' => $ticketId]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Pelayanan tiket ' . $ticket['ticket_code'] . ' telah selesai.',
            'code'    => 200,
            'data'    => ['ticket_id' => (int)$ticket['id'], 'ticket_code' => $ticket['ticket_code']]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Complete Ticket Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal menyelesaikan tiket.', 'code' => 500];
    }
}

function archiveCompletedTicket(int $userId, int $ticketId): array
{
    $pdo = getDBConnection();

    try {
        $pdo->beginTransaction();

        $stmtTicket = $pdo->prepare("
            SELECT id, ticket_code, wave_id, room_id, status, deleted_at 
            FROM tickets 
            WHERE id = :ticket_id 
            FOR UPDATE
        ");
        $stmtTicket->execute([':ticket_id' => $ticketId]);
        $ticket = $stmtTicket->fetch();

        if (!$ticket || $ticket['deleted_at'] !== null) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Tiket tidak ditemukan atau sudah diarsipkan.', 'code' => 404];
        }

        if (!verifyStaffAssignment($userId, (int)$ticket['wave_id'], (int)$ticket['room_id'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Akses ditolak. Anda tidak berwenang mengarsipkan tiket ini.', 'code' => 403];
        }

        if ($ticket['status'] !== 'completed') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Tiket yang masih waiting atau called tidak boleh dihapus/diarsipkan.', 'code' => 409];
        }

        $stmtArchive = $pdo->prepare("
            UPDATE tickets 
            SET deleted_at = NOW() 
            WHERE id = :id
        ");
        $stmtArchive->execute([':id' => $ticketId]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Tiket ' . $ticket['ticket_code'] . ' berhasil diarsipkan dari tampilan operasional.',
            'code'    => 200,
            'data'    => ['ticket_id' => (int)$ticket['id']]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Archive Ticket Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal mengarsipkan tiket.', 'code' => 500];
    }
}

// =====================================================================
// BAGIAN 3: KUNCI PERANGKAT GUEST (1 DEVICE = 1 TIKET AKTIF PER HARI)
// =====================================================================

function bindTicketToGuestDevice(string $ticketCode): void
{
    $serviceDate = getTodayServiceDate();
    $_SESSION['guest_ticket'] = [
        'code' => $ticketCode,
        'date' => $serviceDate
    ];

    $endOfDay = strtotime('tomorrow') - 1;
    setcookie('guest_ticket_code', $ticketCode, [
        'expires'  => $endOfDay,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function clearGuestDeviceTicket(): void
{
    unset($_SESSION['guest_ticket']);
    setcookie('guest_ticket_code', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function getActiveGuestDeviceTicket(): ?array
{
    $today = getTodayServiceDate();
    $savedCode = '';

    if (!empty($_SESSION['guest_ticket']['code']) && ($_SESSION['guest_ticket']['date'] ?? '') === $today) {
        $savedCode = $_SESSION['guest_ticket']['code'];
    } elseif (!empty($_COOKIE['guest_ticket_code'])) {
        $savedCode = $_COOKIE['guest_ticket_code'];
    }

    if ($savedCode === '') {
        return null;
    }

    $ticket = getTicketStatusDetail($savedCode);

    if (!$ticket || $ticket['service_date'] !== $today || $ticket['status'] === 'completed') {
        clearGuestDeviceTicket();
        return null;
    }

    $_SESSION['guest_ticket'] = [
        'code' => $ticket['ticket_code'],
        'date' => $today
    ];

    return $ticket;
}