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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ambil Nomor Antrian</title>
    <link rel="stylesheet" href="../assets/css/index-guest.css">
    <style>
        .wave-item.is-clickable {
            cursor: pointer;
            transition: background-color 0.15s ease, transform 0.1s ease;
        }
        .wave-item.is-clickable:hover {
            background-color: rgba(0, 0, 0, 0.04);
        }
        .wave-item.is-clickable:active {
            transform: scale(0.99);
        }
        .wave-item.is-full {
            cursor: not-allowed;
            opacity: 0.55;
        }
    </style>
</head>
<body>
    <div class="wrapped-guest">
        <header class="header-guest">
            <h1>Sistem<br>Antrian</h1>
            <p class="subtitle">Pilih Gelombang Antrian</p>
            <p>Tanggal: <strong><?= e($initialData['service_date']) ?></strong></p>
        </header>

        <div id="alert-box" class="alert-box"></div>

        <main id="waves-container" class="waves-list">
            <?php if ($initialData['all_full']): ?>
                <div class="empty-notice"><?= e($initialData['empty_quota_notice']) ?></div>
            <?php endif; ?>

            <?php foreach ($initialData['waves'] as $w): 
                $dotClass   = 'dot-inactive';
                $statusText = 'Penuh / Tutup';

                if ($w['is_available']) {
                    if ($w['remaining_quota'] <= 35) {
                        $dotClass   = 'dot-inactive'; // Merah: Sisa 35 tiket atau kurang
                    } elseif ($w['usage_percent'] >= 50) {
                        $dotClass   = 'dot-warning'; // Kuning: Sisa setengah atau kurang                    
                    } else {
                        $dotClass   = 'dot-active'; // Hijau: Kuota masih banyak
                    }
                }
                $itemClass = $w['is_available'] ? 'wave-item is-clickable' : 'wave-item is-full';
            ?>
                <div 
                    class="<?= $itemClass ?>"
                    <?= $w['is_available'] ? 'onclick="takeTicket(' . (int)$w['id'] . ')" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();takeTicket(' . (int)$w['id'] . ');}" role="button" tabindex="0"' : '' ?>>
                    <div class="wave-row-top">
                        <div class="wave-title">
                            <span><?= e($w['wave_name']) ?></span>
                            <span class="status-dot <?= $dotClass ?>"></span>
                        </div>
                        <div class="wave-quota">
                            <?= e((string)$w['used_quota']) ?>/<?= e((string)$w['quota']) ?>
                        </div>
                    </div>

                    <div class="wave-row-bottom">
                        <div class="wave-meta">
                            <p>Sesi: <?= e(substr($w['start_time'], 0, 5)) ?> - <?= e(substr($w['end_time'], 0, 5)) ?></p>
                        </div>
                        <div class="wave-action">
                            <span class="btn-bracket">
                                <?= $w['is_available'] ? '[Ambil Tiket]' : '[Penuh]' ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </main>

        <section class="reopen-section">
            <p class="footnote">
                *Silakan pilih gelombang yang aktif untuk mengambil nomor antrian. Kuota tiket diperbarui secara otomatis berdasarkan ketersediaan.
            </p>
        </section>
    </div>

    <div class="bg-watermark" aria-hidden="true">DosQueue</div>

    <script>
        const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
        let isSubmitting = false;

        async function takeTicket(waveId) {
            if (isSubmitting) return;
            isSubmitting = true;

            const alertBox = document.getElementById('alert-box');
            alertBox.textContent = '> Memproses tiket...';
            alertBox.style.display = 'block';

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
                    alertBox.textContent = '> ' + (json.message || 'Gagal mengambil tiket.');
                    isSubmitting = false;
                    refreshWaves();
                }
            } catch (err) {
                alertBox.textContent = '> Terjadi kesalahan jaringan saat mengambil tiket.';
                isSubmitting = false;
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
                    html += `<div class="empty-notice">${escapeHtml(data.empty_quota_notice)}</div>`;
                }

data.waves.forEach(w => {
    let dotClass   = 'dot-inactive';
    let statusText = 'Penuh / Tutup';

    if (w.is_available) {
        if (w.remaining_quota <= 35) {
            dotClass   = 'dot-inactive'; // Merah: Sisa 35 tiket atau kurang
        } else if (w.usage_percent >= 50) {
            dotClass   = 'dot-warning'; // Kuning: Sisa setengah atau kurang
        } else {
            dotClass   = 'dot-active'; // Hijau: Kuota masih banyak
        }
    }

    const itemClass   = w.is_available ? 'wave-item is-clickable' : 'wave-item is-full';
    const clickAttr   = w.is_available ? `onclick="takeTicket(${w.id})" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();takeTicket(${w.id});}" role="button" tabindex="0"` : '';
    const actionLabel = w.is_available ? '[Ambil Tiket]' : '[Penuh]';

    html += `
        <div class="${itemClass}" ${clickAttr}>
            <div class="wave-row-top">
                <div class="wave-title">
                    <span>${escapeHtml(w.wave_name)}</span>
                    <span class="status-dot ${dotClass}"></span>
                </div>
                <div class="wave-quota">
                    ${w.used_quota}/${w.quota}
                </div>
            </div>
            <div class="wave-row-bottom">
                <div class="wave-meta">
                    <p>Sesi: ${escapeHtml(w.start_time.substring(0, 5))} - ${escapeHtml(w.end_time.substring(0, 5))}</p>
                </div>
                <div class="wave-action">
                    <span class="btn-bracket">${actionLabel}</span>
                </div>
            </div>
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