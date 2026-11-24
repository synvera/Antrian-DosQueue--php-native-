<?php
// user/ticket-result.php
require_once __DIR__ . '/../includes/functions.php';

$deviceTicket  = getActiveGuestDeviceTicket();
$requestedCode = trim($_GET['code'] ?? '');

if ($deviceTicket !== null) {
    if (strtoupper($requestedCode) !== $deviceTicket['ticket_code']) {
        redirectTo('ticket-result.php?code=' . urlencode($deviceTicket['ticket_code']));
    }
    $ticket = $deviceTicket;
} else {
    $ticket = getTicketStatusDetail($requestedCode);

    if ($ticket !== null && $ticket['service_date'] === getTodayServiceDate() && $ticket['status'] !== 'completed') {
        bindTicketToGuestDevice($ticket['ticket_code']);
        $deviceTicket = $ticket;
    }
}

// Bentuk label tampilan ringkas seperti "G1-R3-003" dan stub vertikal "GEL 1"
$displayShortCode = '';
$stubWaveLabel    = '';
if ($ticket) {
    $displayShortCode = sprintf('G%d-R%d-%s', $ticket['wave_id'], $ticket['room_id'], $ticket['formatted_number']);
    $stubWaveLabel    = 'GEL ' . $ticket['wave_id'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Tiket Antrian</title>
    <link rel="stylesheet" href="../assets/css/ticket-result.css">
</head>
<body>
    <div class="ticket-wrapper">
        <!-- HEADER ATAS -->
        <header class="ticket-header">
            <div class="ticket-icon-box" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#111111" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/>
                    <path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>
                </svg>
            </div>

            <div class="serving-info">
                <div class="serving-label">SEDANG DIPANGGIL</div>
                <div class="serving-number" id="t-current">
                    <?= $ticket ? e($ticket['current_serving'] ?? '-') : '-' ?>
                </div>
                <div class="serving-ahead" id="t-ahead-wrap" <?= ($ticket && $ticket['status'] === 'waiting') ? '' : 'hidden' ?>>
                    Sisa: <span id="t-ahead"><?= $ticket ? e((string)$ticket['ahead_count']) : '0' ?></span> antrian
                </div>
            </div>
        </header>

        <!-- KONTEN UTAMA -->
        <main class="ticket-main">
            <?php if (!$ticket): ?>
                <div class="requeue-box">
                    <p><strong>Tiket tidak ditemukan.</strong><br>Pastikan kode tiket benar atau silakan ambil antrian baru.</p>
                    <a href="index.php" class="btn-requeue">[Ambil Tiket Antrian]</a>
                </div>
            <?php else: ?>
                <div id="ticket-card" class="boarding-card <?= $ticket['status'] === 'completed' ? 'is-completed' : ($ticket['status'] === 'called' ? 'is-called' : '') ?>">
                    <!-- Sisi Kiri Kartu -->
                    <div class="card-left">
                        <div class="card-top-row">
                            <span class="meta-label">NOMOR ANTRIAN</span>
                            <span id="t-status" class="status-badge <?= $ticket['status'] === 'called' ? 'status-called' : '' ?>">
                                <?= e(strtoupper($ticket['status'])) ?>
                            </span>
                        </div>

                        <div class="queue-display-number"><?= e($displayShortCode) ?></div>

                        <div class="barcode-strip" aria-hidden="true"></div>

                        <hr class="card-divider">

                        <div class="card-bottom-row">
                            <div>
                                <div class="meta-label">RUANGAN</div>
                                <div class="room-value"><?= e($ticket['room_name']) ?></div>
                            </div>
                            <div class="code-col">
                                <div class="meta-label">KODE TIKET</div>
                                <div class="code-value" title="<?= e($ticket['ticket_code']) ?>">
                                    <?= e($ticket['ticket_code']) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stub Hitam Kanan -->
                    <div class="card-right-stub">
                        <span class="stub-text"><?= e($stubWaveLabel) ?></span>
                    </div>
                </div>

                <p id="t-status-desc" class="ticket-footnote <?= $ticket['status'] === 'called' ? 'is-called-note' : '' ?>">
                    <?php if ($ticket['status'] === 'waiting'): ?>
                        *Harap bersiap di area tunggu. Halaman ini diperbarui otomatis setiap 4 detik.
                    <?php elseif ($ticket['status'] === 'called'): ?>
                        *PANGGILAN! Silakan segera menuju ke <?= e($ticket['room_name']) ?> sekarang.
                    <?php else: ?>
                        *Pelayanan tiket ini telah selesai.
                    <?php endif; ?>
                </p>

                <!-- MUNCUL OTOMATIS SAAT STATUS COMPLETED -->
                <div id="requeue-section" class="requeue-box" <?= $ticket['status'] === 'completed' ? '' : 'hidden' ?>>
                    <p>Terima kasih, pelayanan antrian Anda telah selesai.</p>
                    <a href="../actions/release-guest-ticket.php" class="btn-requeue">[Ambil Kartu Antrian Lagi]</a>
                </div>
            <?php endif; ?>
        </main>

        <!-- FOOTER BAWAH -->
        <footer class="ticket-footer">
            <div>
                <?php if (!$ticket || $ticket['status'] === 'completed'): ?>
                    <a href="index.php" id="footer-home-link" class="footer-link">&larr; Beranda</a>
                <?php else: ?>
                    <a href="index.php" id="footer-home-link" class="footer-link" hidden>&larr; Beranda</a>
                    <span id="footer-lock-label">Tiket Aktif</span>
                <?php endif; ?>
            </div>
            <div>Antrian V01</div>
        </footer>
    </div>

    <?php if ($ticket): ?>
        <!-- POP-UP NOTIFIKASI SAAT TIKET DIPANGGIL -->
        <div id="call-popup" class="modal-overlay" role="dialog" aria-modal="true" hidden>
            <div class="modal-box">
                <span class="modal-badge">GILIRAN ANDA</span>
                <h2 class="modal-title">Nomor Antrian Dipanggil</h2>
                <div class="modal-ticket" id="popup-ticket-code"><?= e($displayShortCode) ?></div>
                <p class="modal-room">
                    Silakan segera menuju ke:<br>
                    <strong id="popup-room-name"><?= e($ticket['room_name']) ?></strong>
                </p>
                <button type="button" class="btn-modal-close" onclick="closeCallPopup()">
                    [Saya Mengerti]
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
                    const card           = document.getElementById('ticket-card');
                    const statusEl       = document.getElementById('t-status');
                    const descEl         = document.getElementById('t-status-desc');
                    const currentEl      = document.getElementById('t-current');
                    const aheadWrap      = document.getElementById('t-ahead-wrap');
                    const aheadEl        = document.getElementById('t-ahead');
                    const requeueSection = document.getElementById('requeue-section');
                    const homeLink       = document.getElementById('footer-home-link');
                    const lockLabel      = document.getElementById('footer-lock-label');

                    statusEl.textContent  = d.status.toUpperCase();
                    currentEl.textContent = d.current_serving ? d.current_serving : '-';

                    if (d.status === 'waiting') {
                        card.classList.remove('is-completed', 'is-called');
                        statusEl.classList.remove('status-called');
                        descEl.classList.remove('is-called-note');
                        descEl.textContent     = '*Harap bersiap di area tunggu. Halaman ini diperbarui otomatis setiap 4 detik.';
                        aheadWrap.hidden       = false;
                        aheadEl.textContent    = d.ahead_count;
                        requeueSection.hidden  = true;
                        if (homeLink) homeLink.hidden = true;
                        if (lockLabel) lockLabel.hidden = false;
                    } else if (d.status === 'called') {
                        card.classList.remove('is-completed');
                        card.classList.add('is-called');
                        statusEl.classList.add('status-called');
                        descEl.classList.add('is-called-note');
                        descEl.textContent     = `*PANGGILAN! Silakan segera menuju ke ${d.room_name} sekarang.`;
                        aheadWrap.hidden       = true;
                        requeueSection.hidden  = true;
                        if (homeLink) homeLink.hidden = true;
                        if (lockLabel) lockLabel.hidden = false;

                        if (lastStatus !== 'called' || d.called_at !== lastCalledAt) {
                            document.getElementById('popup-room-name').textContent = d.room_name;
                            openCallPopup();
                        }
                    } else if (d.status === 'completed') {
                        closeCallPopup();
                        card.classList.remove('is-called');
                        card.classList.add('is-completed');
                        statusEl.classList.remove('status-called');
                        descEl.classList.remove('is-called-note');
                        descEl.textContent     = '*Pelayanan tiket ini telah selesai.';
                        aheadWrap.hidden       = true;
                        requeueSection.hidden  = false;
                        if (homeLink) homeLink.hidden = false;
                        if (lockLabel) lockLabel.hidden = true;
                    }

                    lastStatus   = d.status;
                    lastCalledAt = d.called_at;
                } catch (err) {
                    console.error('Gagal memuat pembaruan status tiket:', err);
                }
            }

            setInterval(pollTicketStatus, 4000);
        </script>
    <?php endif; ?>
</body>
</html>