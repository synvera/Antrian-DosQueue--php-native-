<?php
// petugas/dashboard.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requireStaffPageAuth();

$userId      = (int)$_SESSION['user_id'];
$assignments = getStaffAssignments($userId);
$csrfToken   = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Petugas — DOSQUEUE</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard-petugas.css">
</head>
<body>
<div class="aside">
    <div class="logo">
        <img src="../assets/icons/dashboard.svg" alt="">
        <p>DosQueue</p>
    </div>
    <div class="nav">
        <a href="#">Dashboard Petugas</a>
        <a href="#">Riwayat Data</a>
        <a href="#">Pengaturan</a>
    </div>
    <div class="action">
        <a href="logout.php" class="btn-logout" title="Keluar dari sesi">
            <img src="../assets/icons/logout.svg" alt="Logout">
            <span>Logout</span>
        </a>
    </div>
</div>
<div class="wrapped">
    <main>
        <div class="header">
            <h1>Sistem Antrian</h1>
            <div class="staff-profile-card">
                <div class="staff-avatar">
                    <img src="../assets/icons/users.svg" alt="Petugas">
                </div>
                <div class="staff-details">
                    <span class="staff-name"><?= e($_SESSION['user_name']) ?></span>
                    <?php if (!empty($assignments)): ?>
                        <select id="assignment-select" class="assignment-dropdown" onchange="loadDashboardData()" title="Pilih Gelombang & Ruangan">
                            <?php foreach ($assignments as $a): ?>
                                <option value="<?= (int)$a['wave_id'] ?>|<?= (int)$a['room_id'] ?>">
                                    <?= e($a['wave_name']) ?> (<?= e(substr($a['start_time'], 0, 5)) ?>-<?= e(substr($a['end_time'], 0, 5)) ?>) | <?= e($a['room_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="staff-role-fallback"><?= e($_SESSION['role']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (empty($assignments)): ?>
            <main class="content-area">
                <div class="empty-assignment-box">
                    <strong>Anda belum memiliki penugasan gelombang/ruangan yang aktif.</strong>
                    <p>Silakan hubungi administrator untuk mendapatkan jadwal tugas.</p>
                </div>
            </main>
        <?php else: ?>
    </main>
</div>
</body>
</html>