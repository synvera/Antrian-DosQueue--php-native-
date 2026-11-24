<?php
// user/index.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

// Jika perangkat ini sudah memiliki tiket hari ini, langsung arahkan dan stay di halaman tiket
$existingTicket = getActiveGuestDeviceTicket();
if ($existingTicket !== null) {
    redirectTo('ticket-result.php?code=' . urlencode($existingTicket['ticket_code']));
}

$initialData = getActiveWavesWithQuota();
$csrfToken   = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Ambil Nomor Antrian (Basic)</title>
</head>
<body>
    <h2>Pengambilan Nomor Antrian</h2>
    <p>Tanggal Pelayanan: <strong><?= e($initialData['service_date']) ?></strong></p>

    <div id="alert-box"></div>

    <div id="waves-container">
        <?php if ($initialData['all_full']): ?>
            <p><strong><?= e($initialData['empty_quota_notice']) ?></strong></p>
        <?php endif; ?>

        <?php foreach ($initialData['waves'] as $w): ?>
            <div>
                <h3><?= e($w['wave_name']) ?></h3>
                <p>Waktu Sesi: <?= e(substr($w['start_time'], 0, 5)) ?> - <?= e(substr($w['end_time'], 0, 5)) ?></p>
                <p>
                    Kuota Terpakai: <?= e((string)$w['used_quota']) ?> / <?= e((string)$w['quota']) ?> | 
                    Sisa Kuota: <strong><?= e((string)$w['remaining_quota']) ?></strong> 
                    (<?= e((string)$w['usage_percent']) ?>%)
                </p>
                <button 
                    type="button" 
                    onclick="takeTicket(<?= (int)$w['id'] ?>)" 
                    <?= $w['is_available'] ? '' : 'disabled' ?>>
                    <?= $w['is_available'] ? 'Ambil Tiket Gelombang Ini' : 'Kuota Penuh' ?>
                </button>
            </div>
        <?php endforeach; ?>
    </div>

    <hr>
    <h3>Sudah Punya Kode Tiket? Buka Kembali Tiket Anda:</h3>
    <form method="GET" action="ticket-result.php">
        <label for="code">Masukkan Kode Tiket:</label><br>
        <input type="text" id="code" name="code" required placeholder="G1-YYYYMMDD-001">
        <button type="submit">Buka Tiket</button>
    </form>

    <script>
        const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

        async function takeTicket(waveId) {
            const alertBox = document.getElementById('alert-box');
            alertBox.textContent = 'Memproses tiket...';

            const formData = new FormData();
            formData.append('wave_id', waveId);
            formData.append('csrf_token', CSRF_TOKEN);

            try {
                const res = await fetch('../actions/create-ticket.php', {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();

                if (json.data && json.data.ticket_code) {
                    window.location.href = `ticket-result.php?code=${encodeURIComponent(json.data.ticket_code)}`;
                } else {
                    alertBox.textContent = json.message || 'Gagal mengambil tiket.';
                    refreshWaves();
                }
            } catch (err) {
                alertBox.textContent = 'Terjadi kesalahan jaringan saat mengambil tiket.';
            }
        }

        async function refreshWaves() {
            try {
                const res = await fetch('../actions/get-waves.php');
                const json = await res.json();
                if (!json.success) return;

                const data = json.data;
                const container = document.getElementById('waves-container');
                let html = '';

                if (data.all_full) {
                    html += `<p><strong>${escapeHtml(data.empty_quota_notice)}</strong></p>`;
                }

                data.waves.forEach(w => {
                    html += `
                        <div>
                            <h3>${escapeHtml(w.wave_name)}</h3>
                            <p>Waktu Sesi: ${escapeHtml(w.start_time.substring(0, 5))} - ${escapeHtml(w.end_time.substring(0, 5))}</p>
                            <p>
                                Kuota Terpakai: ${w.used_quota} / ${w.quota} | 
                                Sisa Kuota: <strong>${w.remaining_quota}</strong> (${w.usage_percent}%)
                            </p>
                            <button type="button" onclick="takeTicket(${w.id})" ${w.is_available ? '' : 'disabled'}>
                                ${w.is_available ? 'Ambil Tiket Gelombang Ini' : 'Kuota Penuh'}
                            </button>
                        </div>
                    `;
                });

                container.innerHTML = html;
            } catch (err) {
                console.error('Gagal memperbarui kuota gelombang:', err);
            }
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[m]));
        }

        setInterval(refreshWaves, 5000);
    </script>
</body>
</html>