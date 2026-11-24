<?php
// user/ticket-result.php
require_once __DIR__ . '/../includes/functions.php';

$deviceTicket  = getActiveGuestDeviceTicket();
$requestedCode = trim($_GET['code'] ?? '');

if ($deviceTicket !== null) {
    // Jika masih ada tiket waiting/called di device ini, paksa stay di tiket aktif tersebut
    if (strtoupper($requestedCode) !== $deviceTicket['ticket_code']) {
        redirectTo('ticket-result.php?code=' . urlencode($deviceTicket['ticket_code']));
    }
    $ticket = $deviceTicket;
} else {
    // Jika tiket sudah completed atau dibuka via kode tiket manual
    $ticket = getTicketStatusDetail($requestedCode);

    // Jika tiket masih waiting/called hari ini, ikat ke perangkat ini
    if ($ticket !== null && $ticket['service_date'] === getTodayServiceDate() && $ticket['status'] !== 'completed') {
        bindTicketToGuestDevice($ticket['ticket_code']);
        $deviceTicket = $ticket;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Hasil Tiket Antrian</title>
</head>
<body>
    <h2>Detail Tiket Antrian Anda</h2>

    <?php if (!$ticket): ?>
        <p><strong>Tiket tidak ditemukan.</strong></p>
        <p><a href="index.php">&larr; Ambil Tiket Antrian</a></p>
    <?php else: ?>
        <p id="device-lock-note" <?= $ticket['status'] === 'completed' ? 'hidden' : '' ?>>
            <small><em>Perangkat ini terdaftar pada tiket aktif di bawah ini.</em></small>
        </p>

        <div id="ticket-card">
            <p>Status Tiket: <strong id="t-status"><?= e(strtoupper($ticket['status'])) ?></strong></p>
            <p id="t-status-desc">
                <?php if ($ticket['status'] === 'waiting'): ?>
                    Tiket Anda sedang menunggu dipanggil.
                <?php elseif ($ticket['status'] === 'called'): ?>
                    <strong>PANGGILAN! Silakan segera menuju <?= e($ticket['room_name']) ?>.</strong>
                <?php else: ?>
                    Pelayanan tiket ini telah selesai.
                <?php endif; ?>
            </p>
            <hr>
            <p>Kode Tiket: <strong id="t-code"><?= e($ticket['ticket_code']) ?></strong></p>
            <p>Nomor Urut: <strong><?= e($ticket['formatted_number']) ?></strong></p>
            <p>Gelombang: <strong><?= e($ticket['wave_name']) ?></strong> (<?= e(substr($ticket['start_time'], 0, 5)) ?> - <?= e(substr($ticket['end_time'], 0, 5)) ?>)</p>
            <p>Ruangan Tujuan: <strong><?= e($ticket['room_name']) ?></strong></p>
            <hr>
            <p>Antrian Sedang Dipanggil di <?= e($ticket['room_name']) ?>: 
                <strong id="t-current"><?= e($ticket['current_serving'] ?? 'Belum ada') ?></strong>
            </p>
            <p id="t-ahead-wrap" <?= $ticket['status'] === 'waiting' ? '' : 'hidden' ?>>
                Sisa Antrian di Depan Anda (Ruangan Ini): <strong id="t-ahead"><?= e((string)$ticket['ahead_count']) ?></strong>
            </p>
        </div>

        <!-- MUNCUL OTOMATIS DI BAWAH KARTU TIKET SAAT STATUS COMPLETED -->
        <div id="requeue-section" <?= $ticket['status'] === 'completed' ? '' : 'hidden' ?>>
            <p>Terima kasih, pelayanan antrian Anda telah selesai.</p>
            <a href="../actions/release-guest-ticket.php">Ambil Kartu Antrian Lagi</a>
        </div>

        <!-- POP-UP NOTIFIKASI SAAT TIKET DIPANGGIL -->
        <div id="call-popup" role="dialog" hidden>
            <div>
                <h2>🔔 GILIRAN ANDA DIPANGGIL!</h2>
                <p>Nomor Antrian: <strong id="popup-ticket-code"><?= e($ticket['ticket_code']) ?></strong></p>
                <p>Silakan segera menuju ke:<br><strong id="popup-room-name"><?= e($ticket['room_name']) ?></strong></p>
                <button type="button" onclick="closeCallPopup()">
                    Saya Mengerti
                </button>
            </div>
        </div>

        <script>
            const TICKET_CODE = <?= json_encode($ticket['ticket_code']) ?>;
            let lastStatus    = <?= json_encode($ticket['status']) ?>;
            let lastCalledAt  = <?= json_encode($ticket['called_at']) ?>;

            if (lastStatus === 'called') {
                openCallPopup();
            }

            function playNotificationBeep() {
                try {
                    const AudioCtx = window.AudioContext || window.webkitAudioContext;
                    if (!AudioCtx) return;
                    const ctx = new AudioCtx();
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(880, ctx.currentTime);
                    gain.gain.setValueAtTime(0.15, ctx.currentTime);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start();
                    osc.stop(ctx.currentTime + 0.35);
                } catch (e) {}
            }

            function openCallPopup() {
                document.getElementById('call-popup').hidden = false;
                playNotificationBeep();
            }

            function closeCallPopup() {
                document.getElementById('call-popup').hidden = true;
            }

            async function pollTicketStatus() {
                try {
                    const res = await fetch(`../actions/get-ticket-status.php?code=${encodeURIComponent(TICKET_CODE)}`);
                    const json = await res.json();
                    if (!json.success) return;

                    const d = json.data;
                    const statusEl       = document.getElementById('t-status');
                    const descEl         = document.getElementById('t-status-desc');
                    const currentEl      = document.getElementById('t-current');
                    const aheadWrap      = document.getElementById('t-ahead-wrap');
                    const aheadEl        = document.getElementById('t-ahead');
                    const lockNote       = document.getElementById('device-lock-note');
                    const requeueSection = document.getElementById('requeue-section');

                    statusEl.textContent  = d.status.toUpperCase();
                    currentEl.textContent = d.current_serving ? d.current_serving : 'Belum ada';

                    if (d.status === 'waiting') {
                        descEl.innerHTML             = 'Tiket Anda sedang menunggu dipanggil.';
                        aheadWrap.hidden              = false;
                        aheadEl.textContent          = d.ahead_count;
                        lockNote.hidden               = false;
                        requeueSection.hidden         = true;
                    } else if (d.status === 'called') {
                        descEl.innerHTML             = `<strong>PANGGILAN! Silakan segera menuju ${escapeHtml(d.room_name)}.</strong>`;
                        aheadWrap.hidden              = true;
                        lockNote.hidden               = false;
                        requeueSection.hidden         = true;

                        if (lastStatus !== 'called' || d.called_at !== lastCalledAt) {
                            document.getElementById('popup-ticket-code').textContent = d.ticket_code;
                            document.getElementById('popup-room-name').textContent   = d.room_name;
                            openCallPopup();
                        }
                    } else if (d.status === 'completed') {
                        closeCallPopup();
                        descEl.innerHTML             = 'Pelayanan tiket ini telah selesai.';
                        aheadWrap.hidden              = true;
                        lockNote.hidden               = true;
                        requeueSection.hidden         = false;
                    }

                    lastStatus   = d.status;
                    lastCalledAt = d.called_at;
                } catch (err) {
                    console.error('Gagal memuat pembaruan status tiket:', err);
                }
            }

            function escapeHtml(str) {
                return String(str).replace(/[&<>"']/g, m => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                }[m]));
            }

            setInterval(pollTicketStatus, 4000);
        </script>
    <?php endif; ?>
</body>
</html>