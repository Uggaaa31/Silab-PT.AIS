<?php
require_once __DIR__ . '/config/db.php';
// Initial count, though it will be updated dynamically
$total_measurements = $pdo->query("SELECT COUNT(*) FROM xrf_measurements")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Removed meta refresh for smooth realtime AJAX polling -->
    <title>XRF Dashboard & Connection Monitor</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f172a;
            --bg-card: rgba(30, 41, 59, 0.7);
            --bg-card-hover: rgba(51, 65, 85, 0.8);
            --border-color: rgba(255, 255, 255, 0.1);
            --accent-emerald: #34d399;
            --accent-red: #ef4444;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-primary); color: var(--text-primary); min-height: 100vh; padding: 2rem; }
        .container { max-width: 1000px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        h1 { font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; }
        p { color: var(--text-secondary); font-size: 0.95rem; }
        
        .btn-db {
            display: inline-block;
            background: #eab308;
            color: #000;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-db:hover { background: #facc15; transform: translateY(-2px); }

        .device-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        .device-card:hover { background: var(--bg-card-hover); }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .status-online {
            background: rgba(52, 211, 153, 0.15);
            color: var(--accent-emerald);
            border: 1px solid rgba(52, 211, 153, 0.3);
        }

        .status-offline {
            background: rgba(239, 68, 68, 0.15);
            color: var(--accent-red);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .dot { width: 8px; height: 8px; border-radius: 50%; }
        .dot-online { background: var(--accent-emerald); box-shadow: 0 0 8px var(--accent-emerald); animation: pulse 2s infinite; }
        .dot-offline { background: var(--accent-red); }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(52, 211, 153, 0); }
            100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0); }
        }
        
        .auto-refresh {
            text-align: right;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }

        .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Initial state to prevent layout shift */
        #devices-container { min-height: 200px; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <div>
            <h1>XRF Connection Monitor</h1>
            <p>Real-time status of all registered XRF devices</p>
        </div>
        <div>
            <a href="pages/xrf_data.php" class="btn-db" id="btn-db-link">Buka Database XRF (<?= number_format($total_measurements) ?> Data)</a>
        </div>
    </header>

    <div class="auto-refresh">
        <div class="spinner"></div>
        Sinkronisasi Realtime Aktif (Tanpa Refresh)
    </div>

    <div id="devices-container">
        <!-- Devices will be rendered here dynamically -->
        <div class="device-card" style="justify-content: center; color: var(--text-secondary);">
            Memuat perangkat...
        </div>
    </div>

</div>

<script>
    const container = document.getElementById('devices-container');
    const dbBtn = document.getElementById('btn-db-link');
    
    function formatNumber(num) {
        return new Intl.NumberFormat('id-ID').format(num);
    }
    
    function formatDate(dateStr) {
        // Parse date string like '2026-08-31 15:28:03'
        const date = new Date(dateStr.replace(' ', 'T'));
        const options = { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
        return date.toLocaleDateString('id-ID', options).replace(/\./g, ':');
    }

    async function fetchStatus() {
        try {
            const response = await fetch('api_get_devices_status.php');
            const data = await response.json();
            
            if (data.status === 'success') {
                // Update Button Count
                dbBtn.innerText = `Buka Database XRF (${formatNumber(data.total_measurements)} Data)`;
                
                // Update Devices
                if (data.devices.length === 0) {
                    container.innerHTML = `<div class="device-card" style="justify-content: center; color: var(--text-secondary);">Belum ada perangkat XRF yang terdaftar.</div>`;
                    return;
                }

                let html = '';
                const nowUnix = data.server_time; // timestamp in seconds

                data.devices.forEach(dev => {
                    const lastSeenDate = new Date(dev.last_seen_at.replace(' ', 'T'));
                    const lastSeenUnix = Math.floor(lastSeenDate.getTime() / 1000);
                    
                    // Device is online if seen within last 30 seconds
                    const isOnline = (nowUnix - lastSeenUnix) <= 30;
                    
                    const badgeClass = isOnline ? 'status-online' : 'status-offline';
                    const dotClass = isOnline ? 'dot-online' : 'dot-offline';
                    const textStatus = isOnline ? 'TERHUBUNG (ONLINE)' : 'TERPUTUS (OFFLINE)';

                    html += `
                        <div class="device-card">
                            <div>
                                <h3 style="font-size: 1.2rem; margin-bottom: 4px;">${dev.device_name}</h3>
                                <div style="color: var(--text-secondary); font-size: 0.85rem;">
                                    ID: ${dev.device_id} | Tipe: ${dev.device_type}
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div class="status-badge ${badgeClass}">
                                    <div class="dot ${dotClass}"></div>
                                    ${textStatus}
                                </div>
                                <div style="color: var(--text-secondary); font-size: 0.75rem; margin-top: 6px;">
                                    Terakhir terlihat: ${formatDate(dev.last_seen_at)}
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                container.innerHTML = html;
            }
        } catch (error) {
            console.error('Error fetching XRF status:', error);
        }
    }

    // Fetch immediately on load
    fetchStatus();
    
    // Poll every 2 seconds for a smoother realtime feel
    setInterval(fetchStatus, 2000);
</script>

</body>
</html>
