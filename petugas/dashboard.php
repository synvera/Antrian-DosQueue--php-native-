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
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Operasional Petugas</title>
    <link rel="stylesheet" href="../assets/css/dashboard-monochrome.css">
</head>
<body>

<div class="app-layout">
    <!-- SIDEBAR NAVIGASI -->
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
            <button type="button" class="nav-item active" data-target="view-dashboard" onclick="switchTab('view-dashboard', this)">
                <img src="../assets/icons/dashboard.svg" alt="Dashboard" class="icon-svg">
                <span>Dashboard</span>
            </button>

            <a href="riwayat-antrian.php" class="nav-item">
                <img src="../assets/icons/clock.svg" alt="Riwayat Antrian" class="icon-svg">
                <span>Riwayat Antrian</span>
            </a>

            <button type="button" class="nav-item" data-target="view-settings" onclick="switchTab('view-settings', this)">
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

    <!-- KONTEN UTAMA -->
    <main class="main-content">
        <!-- HEADER PROFIL PETUGAS -->
        <header class="topbar">
            <div>
                <h1 class="page-title" id="topbar-title">Dashboard Operasional</h1>
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

        <!-- VIEW 1: DASHBOARD OPERASIONAL -->
        <section id="view-dashboard" class="view-section active">
            <?php if (empty($assignments)): ?>
                <div class="card empty-state-card">
                    <img src="../assets/icons/meeting_room.svg" alt="Kosong" class="icon-svg icon-lg">
                    <h3>Belum Ada Penugasan Aktif</h3>
                    <p>Anda belum memiliki penugasan gelombang/ruangan yang aktif saat ini.</p>
                </div>
            <?php else: ?>
                <!-- PANEL KONTROL PENUGASAN -->
                <div class="card control-panel">
                    <div class="control-group">
                        <label for="assignment-select">Pilih Penugasan (Gelombang &amp; Ruangan)</label>
                        <select id="assignment-select" class="form-select" onchange="loadDashboardData()">
                            <?php foreach ($assignments as $a): ?>
                                <option value="<?= (int)$a['wave_id'] ?>|<?= (int)$a['room_id'] ?>">
                                    <?= e($a['wave_name']) ?> (<?= e(substr($a['start_time'], 0, 5)) ?>-<?= e(substr($a['end_time'], 0, 5)) ?>) — <?= e($a['room_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="control-actions">
                        <button type="button" class="btn btn-secondary" onclick="loadDashboardData()">
                            <img src="../assets/icons/schedule.svg" alt="Refresh" class="icon-svg">
                            <span>Refresh Manual</span>
                        </button>
                        <button type="button" class="btn btn-primary" onclick="testSpeaker()">
                            <img src="../assets/icons/volume_up.svg" alt="Speaker" class="icon-svg">
                            <span>Uji Suara Speaker</span>
                        </button>
                    </div>
                </div>

                <!-- STATUS MESSAGE ALERT -->
                <div id="status-msg" class="status-banner" style="display: none;"></div>

                <!-- RINGKASAN STATISTIK -->
                <div class="stats-grid">
                    <div class="card stat-card">
                        <div class="stat-header">
                            <span>Tiket Menunggu (Waiting)</span>
                            <img src="../assets/icons/hourglass_empty.svg" alt="Menunggu" class="icon-svg">
                        </div>
                        <div class="stat-value" id="sum-waiting">0</div>
                    </div>

                    <div class="card stat-card stat-highlight">
                        <div class="stat-header">
                            <span>Sedang Dipanggil (Called)</span>
                            <img src="../assets/icons/campaign.svg" alt="Dipanggil" class="icon-svg">
                        </div>
                        <div class="stat-value" id="sum-called">0</div>
                    </div>

                    <div class="card stat-card">
                        <div class="stat-header">
                            <span>Tiket Selesai (Completed)</span>
                            <img src="../assets/icons/check_circle.svg" alt="Selesai" class="icon-svg">
                        </div>
                        <div class="stat-value" id="sum-completed">0</div>
                    </div>
                </div>

                <!-- TABEL 1: TIKET AKTIF & MENUNGGU -->
                <div class="table-container">
                    <div class="table-header">
                        <h3>1. Tiket Aktif &amp; Menunggu</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No. Antrian</th>
                                    <th>Kode Tiket</th>
                                    <th>Waktu Ambil</th>
                                    <th>Status</th>
                                    <th>Panggilan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-active">
                                <tr><td colspan="6" class="text-center">Memuat data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php endif; ?>
        </section>

        <!-- VIEW 2: PENGATURAN (KOSONG + ALERT PENGEMBANGAN) -->
        <section id="view-settings" class="view-section">
            <div class="dev-alert-box">
                <img src="../assets/icons/setting.svg" alt="Pengembangan" class="icon-svg icon-lg">
                <div>
                    <h4>Informasi Sistem</h4>
                    <p>Fitur masih dalam tahap pengembangan.</p>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
    // Navigasi Sidebar
    function switchTab(targetId, btnElement) {
        document.querySelectorAll('.nav-item').forEach(btn => btn.classList.remove('active'));
        btnElement.classList.add('active');

        document.querySelectorAll('.view-section').forEach(sec => sec.classList.remove('active'));
        document.getElementById(targetId).classList.add('active');

        const titles = {
            'view-dashboard': 'Dashboard Operasional',
            'view-settings': 'Pengaturan Sistem'
        };
        document.getElementById('topbar-title').textContent = titles[targetId] || 'Dashboard';

        if (targetId === 'view-settings') {
            alert('Fitur masih dalam tahap pengembangan');
        }
    }
</script>

<?php if (!empty($assignments)): ?>
<script>
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

    function getSelectedAssignment() {
        const val = document.getElementById('assignment-select').value;
        const [waveId, roomId] = val.split('|');
        return { waveId, roomId };
    }

    function showMsg(text) {
        const el = document.getElementById('status-msg');
        if (!text) {
            el.style.display = 'none';
            return;
        }
        el.textContent = text;
        el.style.display = 'block';
    }

    // Web Speech API untuk membacakan nomor antrian
    function speakQueueText(text) {
        if (!('speechSynthesis' in window)) {
            console.warn('Browser ini tidak mendukung Web Speech API.');
            return;
        }
        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'id-ID';
        utterance.rate = 0.95;

        const voices = window.speechSynthesis.getVoices();
        const idVoice = voices.find(v => v.lang && v.lang.toLowerCase().includes('id'));
        if (idVoice) {
            utterance.voice = idVoice;
        }

        window.speechSynthesis.speak(utterance);
    }

    function testSpeaker() {
        speakQueueText('Tes suara speaker sistem antrian berhasil.');
        showMsg('Suara uji coba dikirim ke speaker perangkat.');
    }

    async function loadDashboardData() {
        const { waveId, roomId } = getSelectedAssignment();
        try {
            const res = await fetch(`../actions/get-staff-tickets.php?wave_id=${encodeURIComponent(waveId)}&room_id=${encodeURIComponent(roomId)}`);
            if (res.status === 401) {
                window.location.href = 'login.php';
                return;
            }
            const json = await res.json();
            if (!json.success) {
                showMsg(json.message);
                return;
            }

            const d = json.data;
            document.getElementById('srv-date').textContent      = d.service_date;
            document.getElementById('sum-waiting').textContent   = d.summary.waiting_count;
            document.getElementById('sum-called').textContent    = d.summary.called_count;
            document.getElementById('sum-completed').textContent = d.summary.completed_count;

            renderActiveTable(d.active_tickets, d.summary.has_active_call);
        } catch (err) {
            console.error('Error memuat data dashboard:', err);
        }
    }

    function renderActiveTable(tickets, hasActiveCall) {
        const tbody = document.getElementById('tbody-active');
        if (!tickets.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Tidak ada tiket menunggu atau aktif saat ini.</td></tr>';
            return;
        }

        tbody.innerHTML = tickets.map(t => {
            let actionButtons = '';
            let badgeClass = 'badge-waiting';

            if (t.status === 'called') {
                badgeClass = 'badge-called';
                actionButtons = `
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="ticketAction('recall-ticket.php', ${t.id}, true)">
                            <img src="../assets/icons/bullhorn.svg" alt="" class="icon-svg icon-xs">
                            <span>Panggil Ulang</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" onclick="ticketAction('complete-ticket.php', ${t.id}, false)">
                            <img src="../assets/icons/circle-check.svg" alt="" class="icon-svg icon-xs">
                            <span>Selesai</span>
                        </button>
                    </div>
                `;
            } else if (t.status === 'waiting') {
                const disabledAttr = hasActiveCall ? 'disabled title="Selesaikan tiket yang sedang dipanggil terlebih dahulu"' : '';
                actionButtons = `
                    <button type="button" class="btn btn-sm btn-primary" onclick="ticketAction('call-ticket.php', ${t.id}, true)" ${disabledAttr}>
                        <img src="../assets/icons/call.svg" alt="" class="icon-svg icon-xs">
                        <span>Panggil</span>
                    </button>
                `;
            }

            return `
                <tr class="${t.status === 'called' ? 'row-active' : ''}">
                    <td><span class="queue-number">${escapeHtml(t.display_code)}</span></td>
                    <td><code>${escapeHtml(t.ticket_code)}</code></td>
                    <td>${escapeHtml(t.created_at)}</td>
                    <td><span class="badge ${badgeClass}">${escapeHtml(t.status.toUpperCase())}</span></td>
                    <td>${t.total_calls}x</td>
                    <td>${actionButtons}</td>
                </tr>
            `;
        }).join('');
    }

    async function ticketAction(endpoint, ticketId, shouldSpeak) {
        const formData = new FormData();
        formData.append('ticket_id', ticketId);
        formData.append('csrf_token', CSRF_TOKEN);

        try {
            const res = await fetch(`../actions/${endpoint}`, {
                method: 'POST',
                body: formData
            });
            const json = await res.json();

            if (json.success) {
                showMsg(json.message);
                if (shouldSpeak && json.data && json.data.speech_text) {
                    speakQueueText(json.data.speech_text);
                }
                loadDashboardData();
            } else {
                showMsg(json.message || 'Aksi gagal diproses.');
                loadDashboardData();
            }
        } catch (err) {
            showMsg('Terjadi gangguan koneksi saat mengirim perintah.');
        }
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    if ('speechSynthesis' in window) {
        window.speechSynthesis.getVoices();
    }

    loadDashboardData();
    setInterval(loadDashboardData, 4000);
</script>
<?php endif; ?>

</body>
</html>