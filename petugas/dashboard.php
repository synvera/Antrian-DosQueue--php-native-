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
    <title>Dashboard Petugas (Basic - Tahap 5)</title>
</head>
<body>
    <h2>Dashboard Operasional Petugas (Tampilan Basic Tahap 5)</h2>
    <p>
        Petugas: <strong><?= e($_SESSION['user_name']) ?></strong> 
        (<?= e($_SESSION['username']) ?> | Role: <?= e($_SESSION['role']) ?>) | 
        <a href="logout.php">Logout</a>
    </p>

    <hr>

    <?php if (empty($assignments)): ?>
        <p><strong>Anda belum memiliki penugasan gelombang/ruangan yang aktif.</strong></p>
    <?php else: ?>
        <div>
            <label for="assignment-select"><strong>Pilih Penugasan (Gelombang &amp; Ruangan):</strong></label><br>
            <select id="assignment-select" onchange="loadDashboardData()">
                <?php foreach ($assignments as $a): ?>
                    <option value="<?= (int)$a['wave_id'] ?>|<?= (int)$a['room_id'] ?>">
                        <?= e($a['wave_name']) ?> (<?= e(substr($a['start_time'], 0, 5)) ?>-<?= e(substr($a['end_time'], 0, 5)) ?>) — <?= e($a['room_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" onclick="loadDashboardData()">Refresh Manual</button>
            <button type="button" onclick="testSpeaker()">Uji Suara Speaker</button>
        </div>

        <p id="status-msg"></p>

        <hr>
        <h3>Ringkasan Antrian Hari Ini (<span id="srv-date">-</span>)</h3>
        <ul>
            <li>Tiket Menunggu (Waiting): <strong id="sum-waiting">0</strong></li>
            <li>Tiket Sedang Dipanggil (Called): <strong id="sum-called">0</strong></li>
            <li>Tiket Selesai (Completed): <strong id="sum-completed">0</strong></li>
        </ul>

        <hr>
        <h3>1. Tabel Tiket Aktif &amp; Menunggu</h3>
        <table>
            <thead>
                <tr>
                    <th>No. Urut</th>
                    <th>Kode Tiket</th>
                    <th>Waktu Ambil</th>
                    <th>Status</th>
                    <th>Jumlah Panggilan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="tbody-active">
                <tr><td colspan="6">Memuat data...</td></tr>
            </tbody>
        </table>

        <br>
        <h3>2. Tabel Tiket Selesai (Completed)</h3>
        <table>
            <thead>
                <tr>
                    <th>No. Urut</th>
                    <th>Kode Tiket</th>
                    <th>Waktu Ambil</th>
                    <th>Waktu Selesai</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="tbody-completed">
                <tr><td colspan="6">Memuat data...</td></tr>
            </tbody>
        </table>

        <script>
            const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

            function getSelectedAssignment() {
                const val = document.getElementById('assignment-select').value;
                const [waveId, roomId] = val.split('|');
                return { waveId, roomId };
            }

            function showMsg(text) {
                const el = document.getElementById('status-msg');
                el.textContent = text;
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
                    renderCompletedTable(d.completed_tickets);
                } catch (err) {
                    console.error('Error memuat data dashboard:', err);
                }
            }

            function renderActiveTable(tickets, hasActiveCall) {
                const tbody = document.getElementById('tbody-active');
                if (!tickets.length) {
                    tbody.innerHTML = '<tr><td colspan="6">Tidak ada tiket menunggu atau aktif saat ini.</td></tr>';
                    return;
                }

                tbody.innerHTML = tickets.map(t => {
                    let actionButtons = '';
                    if (t.status === 'called') {
                        actionButtons = `
                            <button type="button" onclick="ticketAction('recall-ticket.php', ${t.id}, true)">Panggil Ulang</button>
                            <button type="button" onclick="ticketAction('complete-ticket.php', ${t.id}, false)">Selesai</button>
                        `;
                    } else if (t.status === 'waiting') {
                        // Kunci tombol Panggil jika masih ada tiket lain berstatus called
                        const disabledAttr = hasActiveCall ? 'disabled title="Selesaikan tiket yang sedang dipanggil terlebih dahulu"' : '';
                        actionButtons = `
                            <button type="button" onclick="ticketAction('call-ticket.php', ${t.id}, true)" ${disabledAttr}>Panggil</button>
                        `;
                    }

                    return `
                        <tr>
                            <td>${escapeHtml(t.formatted_number)}</td>
                            <td>${escapeHtml(t.ticket_code)}</td>
                            <td>${escapeHtml(t.created_at)}</td>
                            <td>${escapeHtml(t.status.toUpperCase())}</td>
                            <td>${t.total_calls}x</td>
                            <td>${actionButtons}</td>
                        </tr>
                    `;
                }).join('');
            }

            function renderCompletedTable(tickets) {
                const tbody = document.getElementById('tbody-completed');
                if (!tickets.length) {
                    tbody.innerHTML = '<tr><td colspan="6">Belum ada tiket yang selesai pada sesi ini.</td></tr>';
                    return;
                }

                tbody.innerHTML = tickets.map(t => `
                    <tr>
                        <td>${escapeHtml(t.formatted_number)}</td>
                        <td>${escapeHtml(t.ticket_code)}</td>
                        <td>${escapeHtml(t.created_at)}</td>
                        <td>${escapeHtml(t.completed_at || '-')}</td>
                        <td>${escapeHtml(t.status.toUpperCase())}</td>
                        <td>
                            <button type="button" onclick="archiveTicket(${t.id})">Arsipkan / Hapus</button>
                        </td>
                    </tr>
                `).join('');
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

            async function archiveTicket(ticketId) {
                if (!confirm('Arsipkan tiket yang sudah selesai ini dari daftar operasional?')) {
                    return;
                }
                await ticketAction('delete-ticket.php', ticketId, false);
            }

            function escapeHtml(str) {
                return String(str).replace(/[&<>"']/g, m => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                }[m]));
            }

            // Muat suara speech synthesis lebih awal
            if ('speechSynthesis' in window) {
                window.speechSynthesis.getVoices();
            }

            loadDashboardData();
            setInterval(loadDashboardData, 4000);
        </script>
    <?php endif; ?>
</body>
</html>