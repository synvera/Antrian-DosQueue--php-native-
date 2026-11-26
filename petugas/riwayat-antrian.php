<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requireStaffPageAuth();

$userId      = (int)$_SESSION['user_id'];
$assignments = getStaffAssignments($userId);
$csrfToken   = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Antrian Petugas</title>
    <link rel="stylesheet" href="../assets/css/dashboard-monochrome.css">
</head>
<body>

<div class="app-layout">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">
                <img src="../assets/icons/ticket.svg" alt="Logo" class="icon-svg">
            </div>
            <div class="brand-text">
                <span class="brand-title">AntrianKu</span>
                <span class="brand-sub">Panel Petugas</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item">
                <img src="../assets/icons/dashboard.svg" alt="Dashboard" class="icon-svg">
                <span>Dashboard</span>
            </a>

            <a href="riwayat-antrian.php" class="nav-item active" aria-current="page">
                <img src="../assets/icons/clock.svg" alt="Riwayat Antrian" class="icon-svg">
                <span>Riwayat Antrian</span>
            </a>

            <button type="button" class="nav-item" onclick="alert('Fitur masih dalam tahap pengembangan')">
                <img src="../assets/icons/setting.svg" alt="Pengaturan" class="icon-svg">
                <span>Pengaturan</span>
            </button>
        </nav>

        <div class="sidebar-footer">
            <a href="logout.php" class="btn-logout">
                <img src="../assets/icons/logout.svg" alt="Logout" class="icon-svg">
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <h1 class="page-title">Riwayat Antrian</h1>
                <p class="page-subtitle">Tanggal Layanan: <strong id="srv-date">-</strong></p>
            </div>
            <div class="staff-badge">
                <div class="staff-avatar">
                    <img src="../assets/icons/users.svg" alt="Petugas" class="icon-svg">
                </div>
                <div class="staff-info">
                    <span class="staff-name"><?= e($_SESSION['user_name']) ?></span>
                    <span class="staff-meta"><?= e($_SESSION['username']) ?> &bull; <?= e($_SESSION['role']) ?></span>
                </div>
            </div>
        </header>

        <?php if (empty($assignments)): ?>
            <div class="card empty-state-card">
                <img src="../assets/icons/meeting_room.svg" alt="Kosong" class="icon-svg icon-lg">
                <h3>Belum Ada Penugasan Aktif</h3>
                <p>Anda belum memiliki penugasan gelombang/ruangan yang aktif saat ini.</p>
            </div>
        <?php else: ?>
            <div class="card control-panel">
                <div class="control-group">
                    <label for="assignment-select">Pilih Penugasan (Gelombang &amp; Ruangan)</label>
                    <select id="assignment-select" class="form-select" onchange="loadHistoryData()">
                        <?php foreach ($assignments as $a): ?>
                            <option value="<?= (int)$a['wave_id'] ?>|<?= (int)$a['room_id'] ?>">
                                <?= e($a['wave_name']) ?> (<?= e(substr($a['start_time'], 0, 5)) ?>-<?= e(substr($a['end_time'], 0, 5)) ?>) — <?= e($a['room_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="control-actions">
                    <button type="button" class="btn btn-secondary" onclick="loadHistoryData()">
                        <img src="../assets/icons/schedule.svg" alt="Refresh" class="icon-svg">
                        <span>Refresh Manual</span>
                    </button>
                </div>
            </div>

            <div id="status-msg" class="status-banner" style="display: none;"></div>

            <div class="table-container">
                <div class="table-header">
                    <h3>Tiket Selesai</h3>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No. Antrian</th>
                                <th>Kode Tiket</th>
                                <th>Waktu Ambil</th>
                                <th>Waktu Selesai</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-completed">
                            <tr><td colspan="6" class="text-center">Memuat data...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if (!empty($assignments)): ?>
<script>
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

    function showMsg(text) {
        const el = document.getElementById('status-msg');
        el.textContent = text;
        el.style.display = text ? 'block' : 'none';
    }

    async function loadHistoryData() {
        const [waveId, roomId] = document.getElementById('assignment-select').value.split('|');
        try {
            const res = await fetch(`../actions/get-staff-tickets.php?wave_id=${encodeURIComponent(waveId)}&room_id=${encodeURIComponent(roomId)}`);
            if (res.status === 401) {
                window.location.href = 'login.php';
                return;
            }

            const json = await res.json();
            if (!json.success) {
                showMsg(json.message || 'Riwayat tiket gagal dimuat.');
                return;
            }

            document.getElementById('srv-date').textContent = json.data.service_date;
            showMsg('');
            renderCompletedTable(json.data.completed_tickets);
        } catch (err) {
            console.error('Error memuat riwayat antrian:', err);
            showMsg('Terjadi gangguan koneksi saat memuat riwayat antrian.');
        }
    }

    function renderCompletedTable(tickets) {
        const tbody = document.getElementById('tbody-completed');
        if (!tickets.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Belum ada tiket yang selesai pada sesi ini.</td></tr>';
            return;
        }

        tbody.innerHTML = tickets.map(t => `
            <tr class="row-completed">
                <td><span class="queue-number">${escapeHtml(t.display_code)}</span></td>
                <td><code>${escapeHtml(t.ticket_code)}</code></td>
                <td>${escapeHtml(t.created_at)}</td>
                <td>${escapeHtml(t.completed_at || '-')}</td>
                <td><span class="badge badge-completed">${escapeHtml(t.status.toUpperCase())}</span></td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline" onclick="archiveTicket(${t.id})">
                        <img src="../assets/icons/delete.svg" alt="" class="icon-svg icon-xs">
                        <span>Arsipkan</span>
                    </button>
                </td>
            </tr>
        `).join('');
    }

    async function archiveTicket(ticketId) {
        if (!confirm('Arsipkan tiket yang sudah selesai ini dari daftar riwayat?')) {
            return;
        }

        const formData = new FormData();
        formData.append('ticket_id', ticketId);
        formData.append('csrf_token', CSRF_TOKEN);

        try {
            const res = await fetch('../actions/delete-ticket.php', {
                method: 'POST',
                body: formData
            });
            if (res.status === 401) {
                window.location.href = 'login.php';
                return;
            }

            const json = await res.json();
            showMsg(json.message || (json.success ? 'Tiket berhasil diarsipkan.' : 'Tiket gagal diarsipkan.'));
            if (json.success) {
                loadHistoryData();
            }
        } catch (err) {
            console.error('Error mengarsipkan tiket:', err);
            showMsg('Terjadi gangguan koneksi saat mengarsipkan tiket.');
        }
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    loadHistoryData();
    setInterval(loadHistoryData, 4000);
</script>
<?php endif; ?>

</body>
</html>