<?php
/**
 * XRF Explorer 7000 Connection Test & Real-time Monitor Page
 * Path: /pages/xrf_dashboard.php (or /xrf_dashboard.php)
 */

$log_file = __DIR__ . '/xrf_connection_log.json';
$connection_logs = [];

if (file_exists($log_file)) {
    $raw = @file_get_contents($log_file);
    $connection_logs = json_decode($raw, true) ?: [];
}

$latest_log = $connection_logs[0] ?? null;
$total_pings = count($connection_logs);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XRF Explorer 7000 - Connection Test Monitor</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f172a;
            --bg-card: rgba(30, 41, 59, 0.7);
            --bg-card-hover: rgba(51, 65, 85, 0.8);
            --border-color: rgba(255, 255, 255, 0.1);
            --accent-blue: #38bdf8;
            --accent-purple: #818cf8;
            --accent-emerald: #34d399;
            --accent-amber: #f59e0b;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 2rem;
            background-image: 
                radial-gradient(at 0% 0%, rgba(56, 189, 248, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(245, 158, 11, 0.15) 0px, transparent 50%);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .title-group h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #38bdf8, #f59e0b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .title-group p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 4px;
        }

        .status-badge {
            background: rgba(245, 158, 11, 0.15);
            color: var(--accent-amber);
            border: 1px solid rgba(245, 158, 11, 0.3);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background-color: var(--accent-amber);
            border-radius: 50%;
            box-shadow: 0 0 10px var(--accent-amber);
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.3; }
            100% { opacity: 1; }
        }

        .notice-banner {
            background: rgba(56, 189, 248, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.3);
            color: #7dd3fc;
            padding: 1.25rem;
            border-radius: 14px;
            margin-bottom: 2rem;
            line-height: 1.5;
            font-size: 0.92rem;
        }

        .notice-banner code {
            background: rgba(0, 0, 0, 0.3);
            padding: 2px 6px;
            border-radius: 4px;
            color: #f1f5f9;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .card-table {
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3);
        }

        .table-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }

        th {
            background: rgba(15, 23, 42, 0.4);
            padding: 1rem 1.5rem;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border-color);
        }

        td {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        tr:hover {
            background: var(--bg-card-hover);
        }

        .tag-success {
            background: rgba(52, 211, 153, 0.15);
            color: var(--accent-emerald);
            border: 1px solid rgba(52, 211, 153, 0.3);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .empty-state {
            padding: 3rem;
            text-align: center;
            color: var(--text-secondary);
            line-height: 1.6;
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <div class="title-group">
            <h1>📶 Monitor Uji Koneksi XRF Explorer 7000</h1>
            <p>Direct Wi-Fi Connection Checker | labmineral Integration</p>
        </div>
        <div class="status-badge">
            <div class="status-dot"></div>
            MODE: UJI KONEKSI ONLY (TIDAK SIMPAN DB)
        </div>
    </header>

    <div class="notice-banner">
        ℹ️ <strong>Sistem Berada dalam Mode Uji Koneksi:</strong><br>
        Saat ini API diset dalam mode <code>CONNECTION_TEST_ONLY = true</code>.<br>
        Setiap kali alat XRF mendeteksi material atau mengirim sinyal, halaman ini akan mencatat log koneksi Wi-Fi secara real-time untuk memastikan sinyal dari XRF Explorer 7000 masuk ke server web Anda <strong>tanpa menyimpan data ke database</strong>.<br>
        <small style="margin-top: 6px; display: block; color: var(--text-secondary);">
            (Nanti jika koneksi sudah terbukti stabil dan Anda ingin mulai menyimpan data ke MySQL, ubah <code>define('CONNECTION_TEST_ONLY', false);</code> di <code>api_xrf_receive.php</code>).
        </small>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Sinyal Masuk (Pings)</div>
            <div class="stat-value"><?= number_format($total_pings) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Sinyal Terakhir Terdeteksi</div>
            <div class="stat-value" style="font-size: 1.2rem; margin-top: 6px; color: var(--accent-emerald);">
                <?= $latest_log ? htmlspecialchars($latest_log['timestamp']) : 'Belum Ada Sinyal' ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">IP Perangkat XRF Terakhir</div>
            <div class="stat-value" style="font-size: 1.2rem; margin-top: 6px; color: var(--accent-blue);">
                <?= $latest_log ? htmlspecialchars($latest_log['client_ip']) : '-' ?>
            </div>
        </div>
    </div>

    <div class="card-table">
        <div class="table-header">
            <h2 style="font-size: 1.1rem; font-weight: 600;">Log Aktivitas Koneksi Wi-Fi Real-time</h2>
            <small style="color: var(--text-secondary);">Otomatis Diperbarui Setiap 3 Detik 🔄</small>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Waktu Diterima</th>
                    <th>IP Perangkat XRF</th>
                    <th>ID Alat</th>
                    <th>File DB Sumber</th>
                    <th>Nama Sampel Terdeteksi</th>
                    <th>Jml Elemen</th>
                    <th>Status Koneksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($connection_logs)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                📡 Menunggu sinyal dari XRF Explorer 7000...<br>
                                Jalankan aplikasi <strong>XRF Sync Service</strong> di alat XRF dan tekan tombol <strong>TEST PING</strong> atau lakukan pengujian material.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($connection_logs as $log): ?>
                        <tr>
                            <td style="white-space: nowrap; font-weight: 500;">
                                <?= htmlspecialchars($log['timestamp']) ?>
                            </td>
                            <td style="font-family: monospace; color: var(--accent-blue);">
                                <?= htmlspecialchars($log['client_ip']) ?>
                            </td>
                            <td><strong><?= htmlspecialchars($log['device_id']) ?></strong></td>
                            <td><code><?= htmlspecialchars($log['db_source']) ?></code></td>
                            <td><?= htmlspecialchars($log['sample_name']) ?></td>
                            <td><?= intval($log['elements_cnt']) ?> Elemen</td>
                            <td>
                                <span class="tag-success">✓ <?= htmlspecialchars($log['status']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Auto-refresh page every 3 seconds to show incoming pings in real-time
setTimeout(function() {
    window.location.reload();
}, 3000);
</script>
</body>
</html>
