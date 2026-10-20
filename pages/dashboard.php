<?php
// ============================================================
//  dashboard.php — Halaman Dashboard dengan Notifikasi Work Order
//  UPDATE: Layout notifikasi di sebelah kanan, sejajar dengan stok bahan
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

if (isClient()) {
    header('Location: ' . BASE_URL . '/pages/client_monitoring.php');
    exit;
}

$pageTitle = 'Dashboard';
$userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'guest';
$userNama = $_SESSION['user_nama'] ?? $_SESSION['nama'] ?? 'User';
$userId = $_SESSION['user_id'] ?? 0;

// ── Statistik Sampel ─────────────────────────────────────────
$sampelAktif   = $pdo->query("SELECT COUNT(*) FROM sampel WHERE status NOT IN('selesai','ditolak')")->fetchColumn();
$sampelSelesai = $pdo->query("SELECT COUNT(*) FROM sampel WHERE status='selesai'")->fetchColumn();
$sampelTotal   = (int)$sampelAktif + (int)$sampelSelesai;
$pctSelesai    = $sampelTotal > 0 ? round($sampelSelesai / $sampelTotal * 100) : 0;

// ── Statistik Pengujian ──────────────────────────────────────
$ujiLulus   = $pdo->query("SELECT COUNT(*) FROM hasil_uji WHERE kesimpulan='lulus'")->fetchColumn();
$ujiPending = $pdo->query("SELECT COUNT(*) FROM hasil_uji WHERE kesimpulan='pending'")->fetchColumn();
$ujiTLulus  = $pdo->query("SELECT COUNT(*) FROM hasil_uji WHERE kesimpulan='tidak_lulus'")->fetchColumn();
$ujiTotal   = (int)$ujiLulus + (int)$ujiPending + (int)$ujiTLulus;
$pctLulus   = $ujiTotal > 0 ? round($ujiLulus / $ujiTotal * 100) : 0;

// ── NOTIFIKASI WORK ORDER UNTUK ANALIS ────────────────────────
$woNotifications = [];
$woCount = 0;
$woSampelTotal = 0;
$woPrepCount = 0;

if (isAnalis()) {
    // Ambil Work Order yang ditugaskan ke analis ini dengan status aktif
    $woStmt = $pdo->prepare("
        SELECT w.*, 
               rec.nomor_penerimaan, rec.klien,
               COUNT(wos.sampel_id) AS jumlah_sampel
        FROM work_order w
        LEFT JOIN penerimaan_sampel rec ON w.penerimaan_id = rec.id
        LEFT JOIN work_order_sampel wos ON wos.wo_id = w.id
        WHERE w.analis_id = ? 
          AND w.status = 'aktif'
        GROUP BY w.id
        ORDER BY 
            FIELD(w.prioritas, 'urgent', 'tinggi', 'normal'),
            w.jadwal_mulai ASC
        LIMIT 6
    ");
    $woStmt->execute([$userId]);
    $woNotifications = $woStmt->fetchAll();
    
    $woCountStmt = $pdo->prepare("SELECT COUNT(*) FROM work_order WHERE analis_id = ? AND status = 'aktif'");
    $woCountStmt->execute([$userId]);
    $woCount = (int)$woCountStmt->fetchColumn();

    foreach ($woNotifications as $wo) {
        $woSampelTotal += (int)($wo['jumlah_sampel'] ?? 0);
        if (!empty($wo['butuh_preparasi'])) {
            $woPrepCount++;
        }
    }
}

// ── NOTIFIKASI PERMINTAAN SSF UNTUK ADMIN ─────────────────────
$ssfNotifications = [];
$ssfTotalCount = 0;

if (isAdmin()) {
    $ssfTotalCount = (int)$pdo->query("SELECT COUNT(*) FROM submission_sampel WHERE status IN ('diterima', 'pending')")->fetchColumn();
    if ($ssfTotalCount > 0) {
        $ssfStmt = $pdo->prepare("
            SELECT s.*, 
                   (SELECT COUNT(*) FROM submission_sampel_detail WHERE submission_id = s.id) AS jumlah_sampel,
                   (SELECT GROUP_CONCAT(DISTINCT jenis_material SEPARATOR ', ') FROM submission_sampel_detail WHERE submission_id = s.id) AS material_list,
                   (SELECT GROUP_CONCAT(DISTINCT metode_uji SEPARATOR ', ') FROM submission_sampel_detail WHERE submission_id = s.id) AS metode_list,
                   (SELECT GROUP_CONCAT(DISTINCT parameter SEPARATOR ', ') FROM submission_sampel_detail WHERE submission_id = s.id AND parameter IS NOT NULL AND parameter != '') AS parameter_list
            FROM submission_sampel s
            WHERE s.status IN ('diterima', 'pending')
            ORDER BY s.id DESC
            LIMIT 5
        ");
        $ssfStmt->execute();
        $ssfNotifications = $ssfStmt->fetchAll();
    }
}

// ── Alert ─────────────────────────────────────────────────────
$alertData   = hitungAlert($pdo);
$bahanKritis = $alertData['bahan_kritis'];
$alatMasalah = $alertData['alat_masalah'];

$alertBahan  = $pdo->query(
    "SELECT nama, stok, satuan, stok_minimum FROM bahan
     WHERE stok_minimum > 0 AND stok <= stok_minimum
     ORDER BY stok ASC LIMIT 5"
)->fetchAll();

$alertAlat = $pdo->query(
    "SELECT nama, status, masa_berlaku_kalibrasi FROM peralatan
     WHERE status IN('maintenance','rusak') LIMIT 4"
)->fetchAll();

// ── Penerimaan terbaru ────────────────────────────────────────
$penerimaanTerbaru = $pdo->query("
    SELECT p.nomor_penerimaan, p.klien, p.tanggal_terima, p.status,
            p.jumlah_sampel,
            (SELECT COUNT(*) FROM sampel s
             JOIN hasil_uji h ON h.sampel_id=s.id
             WHERE s.penerimaan_id=p.id AND h.kesimpulan='lulus') AS lulus,
            (SELECT COUNT(*) FROM sampel s
             JOIN hasil_uji h ON h.sampel_id=s.id
             WHERE s.penerimaan_id=p.id AND h.kesimpulan='tidak_lulus') AS tlulus
     FROM penerimaan_sampel p
     ORDER BY p.created_at DESC LIMIT 6
")->fetchAll();

// ── Peralatan utama ───────────────────────────────────────────
$alatUtama = $pdo->query(
    "SELECT nama, status, masa_berlaku_kalibrasi FROM peralatan ORDER BY id LIMIT 6"
)->fetchAll();

// ── Chart data ────────────────────────────────────────────────
$chartData = $pdo->query(
    "SELECT DATE(tanggal_uji) AS tgl, COUNT(*) AS jml
     FROM hasil_uji
     WHERE tanggal_uji >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY DATE(tanggal_uji) ORDER BY tgl ASC"
)->fetchAll();

// ── Target bulan ini ─────────────────────────────────────────
$selesaiBulan = $pdo->query(
    "SELECT COUNT(*) FROM sampel WHERE status='selesai'
     AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"
)->fetchColumn();
$target      = 50;
$persenTarget = min(100, round($selesaiBulan / $target * 100));

$jamSekarang = (int)date('H');
if ($jamSekarang < 11) {
    $sapaan = 'pagi';
} elseif ($jamSekarang < 15) {
    $sapaan = 'siang';
} elseif ($jamSekarang < 18) {
    $sapaan = 'sore';
} else {
    $sapaan = 'malam';
}

$roleLabels = [
    'admin'      => 'Administrator',
    'analis'     => 'Analis Laboratorium',
    'supervisor' => 'Supervisor',
    'klien'      => 'Klien',
    'guest'      => 'Pengguna',
];
$roleLabel = $roleLabels[$userRole] ?? ucfirst($userRole);
$alertTotal = (int)($alertData['total'] ?? 0);

if (isAnalis()) {
    $quickActions = [
        ['href' => 'work_order.php', 'icon' => '&#128203;', 'label' => 'Work Order' . ($woCount > 0 ? " ($woCount)" : '')],
        ['href' => 'pengujian.php', 'icon' => '&#128300;', 'label' => 'Pengujian'],
        ['href' => 'preparasi.php', 'icon' => '&#128260;', 'label' => 'Preparasi' . ($woPrepCount > 0 ? " ($woPrepCount)" : '')],
    ];
} elseif (isSupervisor()) {
    $quickActions = [
        ['href' => 'qc.php', 'icon' => '&#10003;', 'label' => 'QC Validasi'],
        ['href' => 'laporan.php', 'icon' => '&#128196;', 'label' => 'Laporan'],
        ['href' => 'work_order.php', 'icon' => '&#128203;', 'label' => 'Work Order'],
    ];
} else {
    $quickActions = [
        ['href' => 'penerimaan.php', 'icon' => '&#128230;', 'label' => 'Penerimaan'],
        ['href' => 'submission.php', 'icon' => '📋', 'label' => 'Permintaan SSF' . ($ssfTotalCount > 0 ? " ($ssfTotalCount)" : '')],
        ['href' => 'work_order.php', 'icon' => '&#128203;', 'label' => 'Work Order'],
        ['href' => 'laporan.php', 'icon' => '&#128196;', 'label' => 'Laporan'],
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.welcome-screen {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.85fr);
    gap: 18px;
    margin-bottom: 20px;
    padding: 20px;
    background:
        linear-gradient(135deg, rgba(30, 132, 73, 0.18), transparent 42%),
        var(--card);
    border: 1px solid var(--border);
    border-top: 2px solid var(--gold2);
    border-radius: 12px;
}
.welcome-kicker {
    color: var(--gold);
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 1.4px;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.welcome-main h2 {
    color: var(--text);
    font-size: 1.55rem;
    line-height: 1.25;
    margin-bottom: 8px;
}
.welcome-main p {
    color: var(--text2);
    font-size: 0.82rem;
    line-height: 1.6;
    max-width: 720px;
}
.welcome-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
}
.welcome-action {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 36px;
    padding: 8px 12px;
    border-radius: 8px;
    background: var(--bg3);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
    transition: 0.2s;
}
.welcome-action:hover {
    border-color: var(--gold2);
    color: var(--gold);
    transform: translateY(-1px);
}
.welcome-side {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.welcome-status {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: rgba(10, 26, 15, 0.55);
}
.welcome-status strong,
.welcome-status span {
    display: block;
}
.welcome-status strong {
    color: var(--text);
    font-size: 0.84rem;
    margin-bottom: 4px;
}
.welcome-status span {
    color: var(--text3);
    font-size: 0.7rem;
    line-height: 1.45;
}
.status-dot {
    width: 10px;
    height: 10px;
    flex: 0 0 10px;
    margin-top: 4px;
    border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 0 4px rgba(46, 204, 113, 0.12);
}
.welcome-status.needs-attention .status-dot {
    background: var(--yellow);
    box-shadow: 0 0 0 4px rgba(243, 156, 18, 0.13);
}
.welcome-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}
.welcome-metric {
    min-height: 74px;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg2);
}
.welcome-metric span,
.welcome-metric strong {
    display: block;
}
.welcome-metric span {
    color: var(--text3);
    font-size: 0.64rem;
    line-height: 1.35;
}
.welcome-metric strong {
    color: var(--gold);
    font-size: 1.28rem;
    line-height: 1.2;
    margin-bottom: 5px;
}

/* Layout Grid untuk 2 kolom */
.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}

/* Card style */
.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    transition: all 0.2s;
}

.stat-card .label {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--text3);
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.stat-card .val {
    font-size: 1.8rem;
    font-weight: 700;
}

.stat-card .val.gold { color: var(--gold); }
.stat-card .val.red { color: var(--red); }
.stat-card .val.yellow { color: var(--yellow); }
.stat-card .val.green { color: var(--green); }

.stat-card .sub {
    font-size: 0.65rem;
    color: var(--text3);
    margin-top: 4px;
}

/* Progress bar */
.prog-wrap {
    margin-top: 8px;
}
.prog-label {
    display: flex;
    justify-content: space-between;
    font-size: 0.65rem;
    color: var(--text3);
    margin-bottom: 4px;
}
.prog {
    background: var(--bg3);
    border-radius: 4px;
    height: 6px;
    overflow: hidden;
}
.prog-bar {
    height: 100%;
    border-radius: 4px;
}
.prog-bar.pb-green { background: var(--green3); }
.prog-bar.pb-gold { background: var(--gold); }

/* Status Pengujian - mini progress */
.mini-progress {
    display: flex;
    height: 6px;
    border-radius: 4px;
    overflow: hidden;
    gap: 2px;
    margin: 8px 0;
}

/* Notifikasi Work Order */
.wo-notification-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    height: 100%;
    display: flex;
    flex-direction: column;
}
.wo-notification-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 6px;
}
.wo-notification-header h4 {
    margin: 0;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--text3);
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.wo-notification-header .badge-count {
    background: var(--gold);
    color: #1a1a2e;
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 0.6rem;
    font-weight: 700;
}
.wo-list {
    flex: 1;
}
.wo-item {
    background: var(--bg3);
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 10px;
    border-left: 3px solid var(--gold);
    transition: all 0.2s;
}
.wo-item:hover {
    transform: translateX(2px);
    background: #1a2e1a;
}
.wo-item.urgent {
    border-left-color: var(--red);
    background: #2a1010;
}
.wo-item.tinggi {
    border-left-color: var(--yellow);
}
.wo-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
    flex-wrap: wrap;
    gap: 4px;
}
.wo-no {
    font-family: monospace;
    font-weight: 700;
    color: var(--gold);
    font-size: 0.8rem;
}
.wo-priority {
    font-size: 0.6rem;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 600;
}
.priority-urgent {
    background: #3d1010;
    color: var(--red);
}
.priority-tinggi {
    background: #3d2e00;
    color: var(--yellow);
}
.priority-normal {
    background: #1a2e1a;
    color: #6aab7e;
}
.wo-detail {
    display: flex;
    gap: 16px;
    font-size: 0.7rem;
    color: var(--text3);
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.wo-detail span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.wo-action {
    margin-top: 8px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.btn-notif {
    padding: 4px 10px;
    font-size: 0.65rem;
    border-radius: 4px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-notif-primary {
    background: var(--gold);
    color: #1a1a2e;
}
.btn-notif-primary:hover {
    background: #e8b030;
}
.btn-notif-secondary {
    background: var(--bg3);
    border: 1px solid var(--border);
    color: var(--text2);
}
.empty-notif {
    text-align: center;
    padding: 24px 16px;
    color: var(--text3);
    font-size: 0.75rem;
}
.empty-notif span {
    font-size: 2rem;
}
.view-all-link {
    text-align: center;
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid var(--border);
}
.view-all-link a {
    font-size: 0.7rem;
    color: var(--gold);
    text-decoration: none;
}
.view-all-link a:hover {
    text-decoration: underline;
}

/* Alert box */
.alert-box {
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    margin-bottom: 8px;
}
.alert-red {
    background: #3d1010;
    border: 1px solid #7a2020;
    color: #e74c3c;
}
.alert-yellow {
    background: #3d2e00;
    border: 1px solid #5a4000;
    color: var(--yellow);
}
.alert-green {
    background: #1a4d2e;
    border: 1px solid var(--green3);
    color: var(--green);
}

/* Bar chart */
.bar-chart {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    height: 100px;
    margin: 10px 0;
}
.bar-col {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}
.bar {
    width: 100%;
    background: var(--green3);
    border-radius: 4px;
    transition: height 0.3s;
}
.bar.gold {
    background: var(--gold);
}
.bar-lbl {
    font-size: 0.6rem;
    color: var(--text3);
}

/* Data table */
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.7rem;
}
.data-table th,
.data-table td {
    padding: 8px 10px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}
.data-table th {
    color: var(--text3);
    font-weight: 600;
    background: var(--bg3);
}

@media (max-width: 960px) {
    .welcome-screen,
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
    .welcome-metrics {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 600px) {
    .welcome-screen {
        padding: 16px;
    }
    .welcome-main h2 {
        font-size: 1.3rem;
    }
    .welcome-action {
        flex: 1 1 100%;
        justify-content: center;
    }
    .welcome-metrics {
        grid-template-columns: 1fr;
    }
}

/* ── Notifikasi SSF untuk Admin ── */
.ssf-notification-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    height: 100%;
    display: flex;
    flex-direction: column;
}
.ssf-notification-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 6px;
}
.ssf-notification-header h4 {
    margin: 0;
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--text);
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.badge-count-ssf {
    background: #25d366;
    color: #04240d;
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 0.62rem;
    font-weight: 700;
    box-shadow: 0 0 8px rgba(37, 211, 102, 0.35);
}
.ssf-sub-label {
    font-size: 0.62rem;
    color: var(--text3);
}
.ssf-list {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.ssf-item {
    background: var(--bg3);
    border-radius: 8px;
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-left: 4px solid #25d366;
    transition: all 0.2s;
}
.ssf-item:hover {
    transform: translateX(2px);
    background: #173822;
    border-color: rgba(37, 211, 102, 0.4);
}
.ssf-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
    flex-wrap: wrap;
    gap: 4px;
}
.ssf-no {
    font-family: monospace;
    font-weight: 700;
    color: var(--gold);
    font-size: 0.8rem;
}
.badge-status-sistem {
    font-size: 0.58rem;
    padding: 2px 7px;
    border-radius: 10px;
    font-weight: 700;
    background: rgba(52, 152, 219, 0.18);
    color: #5dade2;
    border: 1px solid rgba(52, 152, 219, 0.35);
    text-transform: uppercase;
}
.ssf-date {
    font-size: 0.65rem;
    color: var(--text3);
    margin-left: auto;
}
.ssf-client-info {
    font-size: 0.78rem;
    color: var(--text);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.ssf-contact {
    font-size: 0.7rem;
    color: var(--text2);
}
.ssf-detail {
    display: flex;
    gap: 10px;
    font-size: 0.68rem;
    color: var(--text3);
    flex-wrap: wrap;
    margin-bottom: 6px;
    background: rgba(0, 0, 0, 0.15);
    padding: 5px 8px;
    border-radius: 5px;
}
.ssf-notes {
    font-size: 0.65rem;
    color: var(--text3);
    margin-bottom: 8px;
    background: rgba(240, 192, 64, 0.05);
    padding: 4px 8px;
    border-radius: 4px;
    border-left: 2px solid var(--gold2);
}
.ssf-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.btn-ssf {
    padding: 5px 10px;
    font-size: 0.68rem;
    border-radius: 5px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 600;
    transition: 0.2s;
}
.btn-ssf-wa {
    background: #25d366;
    color: #04240d;
    font-weight: 700;
}
.btn-ssf-wa:hover {
    background: #1eb956;
    color: #000;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(37, 211, 102, 0.35);
}
.btn-ssf-input {
    background: var(--gold);
    color: #1a1000;
    font-weight: 700;
}
.btn-ssf-input:hover {
    background: var(--gold-light, #f5cc3a);
    transform: translateY(-1px);
}
.btn-ssf-detail {
    background: var(--bg2);
    border: 1px solid var(--border);
    color: var(--text2);
}
.btn-ssf-detail:hover {
    border-color: var(--gold2);
    color: var(--gold);
}
.btn-ssf-disabled {
    background: #2a1b1b;
    color: #e57373;
    border: 1px solid #5c2020;
    font-size: 0.65rem;
    padding: 5px 8px;
    border-radius: 5px;
}

/* Alert Banner di Dashboard */
.ssf-alert-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 12px 18px;
    background: linear-gradient(135deg, rgba(37, 211, 102, 0.16), rgba(240, 192, 64, 0.12));
    border: 1px solid rgba(37, 211, 102, 0.35);
    border-left: 4px solid #25d366;
    border-radius: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.ssf-alert-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #25d366;
    color: #04240d;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
}
.ssf-alert-msg {
    flex: 1;
    font-size: 0.76rem;
    color: var(--text);
    line-height: 1.45;
}
.btn-alert-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--bg3);
    border: 1px solid var(--gold2);
    color: var(--gold);
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 700;
    text-decoration: none;
    transition: 0.2s;
}
.btn-alert-action:hover {
    background: var(--gold);
    color: #1a1000;
}
.status-dot-gold {
    background: #25d366 !important;
    box-shadow: 0 0 0 4px rgba(37, 211, 102, 0.2) !important;
}
</style>

<section class="welcome-screen" aria-label="Welcome screen dashboard">
    <div class="welcome-main">
        <div class="welcome-kicker">Selamat <?= bersihkan($sapaan) ?> &bull; <?= fmtTglPanjang(date('Y-m-d')) ?></div>
        <h2>Selamat datang, <?= bersihkan($userNama) ?></h2>
        <p>
            Anda masuk sebagai <?= bersihkan($roleLabel) ?>. Fokus hari ini:
            <?php if (isAnalis() && $woCount > 0): ?>
                <strong style="color:var(--gold);background:rgba(240,192,64,0.14);padding:2px 8px;border-radius:6px;border:1px solid rgba(240,192,64,0.3)"><?= $woCount ?> Work Order aktif ditugaskan kepada Anda</strong> (<?= $woSampelTotal ?> sampel),
            <?php endif; ?>
            <?= $sampelAktif ?> sampel aktif di lab, <?= $alertTotal ?> alert aktif,
            <?php if (isAdmin() && $ssfTotalCount > 0): ?>
                <strong style="color:var(--gold);background:rgba(240,192,64,0.12);padding:2px 8px;border-radius:6px;border:1px solid rgba(240,192,64,0.3)"><?= $ssfTotalCount ?> permohonan SSF baru</strong>,
            <?php endif; ?>
            dan target bulan ini sudah <?= $persenTarget ?>%.
        </p>
        <div class="welcome-actions">
            <?php foreach ($quickActions as $action): ?>
            <a class="welcome-action" href="<?= BASE_URL ?>/<?= $action['href'] ?>">
                <span><?= $action['icon'] ?></span>
                <?= bersihkan($action['label']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="welcome-side">
        <div class="welcome-status <?= ($alertTotal > 0 || (isAdmin() && $ssfTotalCount > 0) || (isAnalis() && $woCount > 0)) ? 'needs-attention' : 'stable' ?>">
            <span class="status-dot <?= ((isAnalis() && $woCount > 0) || (isAdmin() && $ssfTotalCount > 0)) ? 'status-dot-gold' : '' ?>" aria-hidden="true"></span>
            <div>
                <strong>
                    <?php if (isAnalis() && $woCount > 0): ?>
                        <?= $woCount ?> Work Order Perlu Dikerjakan
                    <?php elseif (isAdmin() && $ssfTotalCount > 0): ?>
                        <?= $ssfTotalCount ?> Permintaan SSF Baru
                    <?php elseif ($alertTotal > 0): ?>
                        <?= $alertTotal ?> alert aktif
                    <?php else: ?>
                        Operasional stabil
                    <?php endif; ?>
                </strong>
                <span>
                    <?php if (isAnalis() && $woCount > 0): ?>
                        Ada <?= $woCount ?> tugas aktif yang ditugaskan kepada Anda (<?= $woSampelTotal ?> sampel). Cek persiapan sampel &amp; hasil uji di bawah.
                    <?php elseif (isAdmin() && $ssfTotalCount > 0): ?>
                        Status "Diterima" sistem &bull; Konfirmasi via WhatsApp lalu input ke penerimaan.
                    <?php elseif ($alertTotal > 0): ?>
                        Cek stok bahan, status alat, atau jadwal kalibrasi.
                    <?php else: ?>
                        Tidak ada alert prioritas untuk bahan dan peralatan.
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <div class="welcome-metrics">
            <?php if (isAnalis()): ?>
            <div class="welcome-metric">
                <strong style="color:var(--gold)"><?= $woCount ?></strong>
                <span>Work Order Aktif</span>
            </div>
            <div class="welcome-metric">
                <strong style="color:#2ecc71"><?= $woSampelTotal ?></strong>
                <span>Sampel Ditugaskan</span>
            </div>
            <div class="welcome-metric">
                <strong><?= $pctLulus ?>%</strong>
                <span>Tingkat Kelulusan</span>
            </div>
            <?php elseif (isAdmin()): ?>
            <div class="welcome-metric">
                <strong><?= $sampelAktif ?></strong>
                <span>Sampel aktif</span>
            </div>
            <div class="welcome-metric">
                <strong style="color:<?= $ssfTotalCount > 0 ? '#2ecc71' : 'var(--gold)' ?>"><?= $ssfTotalCount ?></strong>
                <span>Permintaan SSF</span>
            </div>
            <div class="welcome-metric">
                <strong><?= $persenTarget ?>%</strong>
                <span>Target bulan ini</span>
            </div>
            <?php else: ?>
            <div class="welcome-metric">
                <strong><?= $sampelAktif ?></strong>
                <span>Sampel aktif</span>
            </div>
            <div class="welcome-metric">
                <strong><?= $pctLulus ?>%</strong>
                <span>Tingkat kelulusan</span>
            </div>
            <div class="welcome-metric">
                <strong><?= $persenTarget ?>%</strong>
                <span>Target bulan ini</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (isAnalis() && $woCount > 0): ?>
<div class="ssf-alert-banner" style="background: linear-gradient(135deg, rgba(240, 192, 64, 0.16), rgba(30, 132, 73, 0.12)); border-color: rgba(240, 192, 64, 0.4); border-left-color: var(--gold);">
    <div class="ssf-alert-badge" style="background: var(--gold); color: #1a0f00;">
        <span>🔔 <?= $woCount ?> Work Order Ditugaskan</span>
    </div>
    <div class="ssf-alert-msg">
        <strong>Tugas Aktif Anda:</strong> Terdapat <?= $woCount ?> Work Order aktif yang ditugaskan kepada Anda (total <?= $woSampelTotal ?> sampel).
        <?= $woPrepCount > 0 ? "($woPrepCount tugas memerlukan tahapan preparasi sebelum pengujian)." : "(Sampel siap untuk langsung dilakukan pengujian)." ?>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="#notif-wo" class="btn-alert-action">Lihat Daftar Tugas &darr;</a>
        <a href="<?= BASE_URL ?>/pages/pengujian.php" class="btn-alert-action" style="border-color:var(--green);color:var(--green)">🔬 Form Pengujian &rarr;</a>
    </div>
</div>
<?php endif; ?>

<?php if (isAdmin() && $ssfTotalCount > 0): ?>
<div class="ssf-alert-banner">
    <div class="ssf-alert-badge">
        <span>🔔 <?= $ssfTotalCount ?> Permintaan SSF Baru</span>
    </div>
    <div class="ssf-alert-msg">
        <strong>Status "Diterima" Sistem:</strong> Ada <?= $ssfTotalCount ?> permohonan pengujian via SSF yang baru masuk. Silakan konfirmasi ke klien via WhatsApp. Setelah konfirmasi OK, lanjutkan dengan input manual ke Penerimaan Sampel Baru.
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="#notif-ssf" class="btn-alert-action">Lihat Daftar &darr;</a>
        <a href="<?= BASE_URL ?>/pages/submission.php?tab=diterima" class="btn-alert-action" style="border-color:var(--green);color:var(--green)">Kelola Semua SSF &rarr;</a>
    </div>
</div>
<?php endif; ?>

<div class="sec-title">Ringkasan Hari Ini &mdash; <?= fmtTglPanjang(date('Y-m-d')) ?></div>

<!-- ── BARIS 1: STATUS SAMPEL + STATUS PENGUJIAN ────────────── -->
<div class="dashboard-grid">
    <!-- Status Sampel -->
    <div class="stat-card">
        <div class="label">STATUS SAMPEL</div>
        <div style="display:flex;gap:20px;align-items:baseline;margin:4px 0 6px">
            <div>
                <span class="val gold"><?= $sampelAktif ?></span>
                <span style="font-size:0.7rem;color:var(--text3);margin-left:4px">Aktif</span>
            </div>
            <div style="color:var(--border);font-size:1rem">/</div>
            <div>
                <span class="val"><?= $sampelSelesai ?></span>
                <span style="font-size:0.7rem;color:var(--text3);margin-left:4px">Selesai</span>
            </div>
        </div>
        <div class="prog-wrap">
            <div class="prog-label">
                <span>Selesai <?= $pctSelesai ?>% dari <?= $sampelTotal ?> total</span>
            </div>
            <div class="prog">
                <div class="prog-bar pb-green" style="width:<?= $pctSelesai ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Status Pengujian -->
    <div class="stat-card">
        <div class="label">STATUS PENGUJIAN</div>
        <div style="display:flex;gap:16px;align-items:baseline;margin:4px 0 6px;flex-wrap:wrap">
            <div>
                <span class="val green"><?= $ujiLulus ?></span>
                <span style="font-size:0.7rem;color:var(--text3);margin-left:3px">Lulus</span>
            </div>
            <div>
                <span class="val yellow"><?= $ujiPending ?></span>
                <span style="font-size:0.7rem;color:var(--text3);margin-left:3px">Pending</span>
            </div>
            <div>
                <span class="val red"><?= $ujiTLulus ?></span>
                <span style="font-size:0.7rem;color:var(--text3);margin-left:3px">Tidak Lulus</span>
            </div>
        </div>
        <?php if ($ujiTotal > 0): ?>
        <div class="mini-progress">
            <div style="width:<?= round($ujiLulus/$ujiTotal*100) ?>%;background:var(--green3)"></div>
            <div style="width:<?= round($ujiPending/$ujiTotal*100) ?>%;background:var(--yellow)"></div>
            <div style="width:<?= round($ujiTLulus/$ujiTotal*100) ?>%;background:var(--red)"></div>
        </div>
        <div class="prog-label">
            <span><?= $pctLulus ?>% tingkat kelulusan dari <?= $ujiTotal ?> pengujian</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── BARIS 2: STOK BAHAN + NOTIFIKASI WORK ORDER ──────────── -->
<div class="dashboard-grid">
    <!-- Stok Bahan Kritis -->
    <div class="stat-card">
        <div class="label">STOK BAHAN KRITIS</div>
        <div class="val red"><?= $bahanKritis ?></div>
        <div class="sub">Perlu pengadaan segera</div>
        <?php if (!empty($alertBahan)): ?>
        <div style="margin-top:12px">
            <?php foreach (array_slice($alertBahan, 0, 3) as $b): ?>
            <div style="font-size:0.7rem; color:var(--text3); margin-bottom:4px">
                • <?= bersihkan($b['nama']) ?>: <?= $b['stok'] ?> <?= bersihkan($b['satuan']) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- NOTIFIKASI WORK ORDER (untuk analis) -->
    <?php if (isAnalis()): ?>
    <div class="wo-notification-card" id="notif-wo">
        <div class="wo-notification-header">
            <h4>
                <span>🔔</span> NOTIFIKASI WORK ORDER
                <?php if ($woCount > 0): ?>
                <span class="badge-count"><?= $woCount ?> tugas</span>
                <?php endif; ?>
            </h4>
            <span style="font-size:0.6rem; color:var(--text3)">Ditugaskan kepada Anda</span>
        </div>
        
        <?php if ($woCount > 0): ?>
        <div class="wo-list">
            <?php foreach ($woNotifications as $wo): 
                $priorityClass = 'priority-' . $wo['prioritas'];
                $isUrgent = $wo['prioritas'] === 'urgent';
                $isTinggi = $wo['prioritas'] === 'tinggi';
                $isButuhPrep = !empty($wo['butuh_preparasi']);
            ?>
            <div class="wo-item <?= $isUrgent ? 'urgent' : ($isTinggi ? 'tinggi' : '') ?>">
                <div class="wo-item-header">
                    <span class="wo-no">📋 <?= bersihkan($wo['nomor_wo']) ?></span>
                    <?php if ($isButuhPrep): ?>
                        <span style="font-size:0.58rem;padding:2px 7px;border-radius:10px;background:rgba(230,126,34,0.18);color:#f39c12;border:1px solid rgba(230,126,34,0.35);font-weight:700">⚗️ Wajib Preparasi</span>
                    <?php else: ?>
                        <span style="font-size:0.58rem;padding:2px 7px;border-radius:10px;background:rgba(46,204,113,0.18);color:#2ecc71;border:1px solid rgba(46,204,113,0.35);font-weight:700">⚡ Langsung Uji</span>
                    <?php endif; ?>
                    <span class="wo-priority <?= $priorityClass ?>"><?= strtoupper($wo['prioritas']) ?></span>
                </div>
                <div class="wo-detail">
                    <span>🏢 <?= bersihkan($wo['klien'] ?? '—') ?></span>
                    <span>📦 <?= $wo['jumlah_sampel'] ?> sampel</span>
                    <?php if ($wo['jadwal_mulai']): ?>
                    <span>📅 <?= date('d/m', strtotime($wo['jadwal_mulai'])) ?></span>
                    <?php endif; ?>
                </div>
                <div class="wo-action">
                    <?php if ($isButuhPrep): ?>
                        <a href="<?= BASE_URL ?>/pages/preparasi.php?wo_id=<?= $wo['id'] ?>" class="btn-notif btn-notif-primary">⚗️ Preparasi</a>
                        <a href="<?= BASE_URL ?>/pages/pengujian.php?wo_id=<?= $wo['id'] ?>&tab=batch" class="btn-notif btn-notif-secondary">🔬 Hasil Uji</a>
                    <?php else: ?>
                        <a href="<?= BASE_URL ?>/pages/pengujian.php?wo_id=<?= $wo['id'] ?>&tab=batch" class="btn-notif btn-notif-primary" style="background:var(--gold);color:#1a0f00;font-weight:700">🔬 Input Hasil Uji</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/pages/work_order.php?tab=daftar" class="btn-notif btn-notif-secondary">📋 Detail</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($woCount > 3): ?>
        <div class="view-all-link">
            <a href="<?= BASE_URL ?>/pages/work_order.php?tab=daftar">Lihat semua tugas →</a>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="empty-notif">
            <span>✅</span>
            <p style="margin-top:8px">Belum ada Work Order yang ditugaskan.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif (isAdmin()): ?>
    <!-- ── NOTIFIKASI PERMINTAAN SSF (ADMIN) ────────────────────── -->
    <div class="ssf-notification-card" id="notif-ssf">
        <div class="ssf-notification-header">
            <h4>
                <span>🔔</span> NOTIFIKASI PERMINTAAN SSF
                <?php if ($ssfTotalCount > 0): ?>
                <span class="badge-count-ssf"><?= $ssfTotalCount ?> Diterima</span>
                <?php endif; ?>
            </h4>
            <span class="ssf-sub-label">Diterima Sistem &bull; Konfirmasi via WA</span>
        </div>
        
        <?php if ($ssfTotalCount > 0): ?>
        <div class="ssf-list">
            <?php foreach ($ssfNotifications as $sub): 
                $waPhone = preg_replace('/[^0-9]/', '', (string)($sub['telepon'] ?? ''));
                if (str_starts_with($waPhone, '0')) {
                    $waPhone = '62' . substr($waPhone, 1);
                } elseif (str_starts_with($waPhone, '8')) {
                    $waPhone = '62' . $waPhone;
                }
                
                $contactName = !empty($sub['kontak_person']) ? $sub['kontak_person'] : $sub['klien'];
                $matSummary = !empty($sub['material_list']) ? $sub['material_list'] : 'Sampel mineral';
                if (!empty($sub['metode_list'])) {
                    $matSummary .= ' (Metode: ' . $sub['metode_list'] . ')';
                }
                
                $waText = "Halo {$contactName},\n\nKami dari Tim Laboratorium AISPEKTRA mengonfirmasi permohonan pengujian sampel via SSF:\n📋 No: {$sub['nomor_submission']}\n🏢 Klien: {$sub['klien']}\n📦 Jumlah: {$sub['jumlah_sampel']} sampel ({$matSummary})\n\nStatus permohonan Anda telah DITERIMA SISTEM kami. Mohon konfirmasi kesiapan pengiriman sampel fisik ke laboratorium kami.\n\nTerima kasih.\n— AISPEKTRA Laboratory";
                $waLink = $waPhone ? ('https://wa.me/' . $waPhone . '?text=' . rawurlencode($waText)) : '';
            ?>
            <div class="ssf-item">
                <div class="ssf-item-header">
                    <span class="ssf-no">📋 <?= bersihkan($sub['nomor_submission']) ?></span>
                    <span class="badge-status-sistem">Diterima Sistem</span>
                    <span class="ssf-date"><?= fmtTgl($sub['tanggal_submit'] ?? $sub['created_at'], 'd/m H:i') ?></span>
                </div>
                <div class="ssf-client-info">
                    <strong><?= bersihkan($sub['klien']) ?></strong>
                    <?php if (!empty($sub['kontak_person'])): ?>
                        <span class="ssf-contact">&bull; PIC: <?= bersihkan($sub['kontak_person']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="ssf-detail">
                    <span>📦 <?= (int)$sub['jumlah_sampel'] ?> sampel</span>
                    <?php if (!empty($sub['material_list'])): ?>
                    <span>🧪 <?= bersihkan($sub['material_list']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($sub['metode_list'])): ?>
                    <span>🔬 <?= bersihkan($sub['metode_list']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($sub['parameter_list'])): ?>
                    <span>📊 <?= bersihkan(mb_strimwidth($sub['parameter_list'], 0, 45, '...')) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($sub['instruksi_khusus'])): ?>
                <div class="ssf-notes">
                    <em>Instruksi:</em> <?= bersihkan(mb_strimwidth($sub['instruksi_khusus'], 0, 80, '...')) ?>
                </div>
                <?php endif; ?>
                <div class="ssf-actions">
                    <?php if ($waLink): ?>
                    <a href="<?= $waLink ?>" target="_blank" class="btn-ssf btn-ssf-wa" title="Konfirmasi ke Klien via WhatsApp">
                        💬 Konfirmasi WA
                    </a>
                    <?php else: ?>
                    <span class="btn-ssf btn-ssf-disabled" title="Nomor telepon tidak valid">⚠️ No WA Kosong</span>
                    <?php endif; ?>
                    
                    <a href="<?= BASE_URL ?>/pages/penerimaan.php?from_submission=<?= $sub['id'] ?>" class="btn-ssf btn-ssf-input" title="Input manual data ke Penerimaan Sampel Baru setelah konfirmasi">
                        📥 Input Penerimaan
                    </a>
                    
                    <a href="<?= BASE_URL ?>/pages/submission.php?tab=<?= $sub['status'] ?>" class="btn-ssf btn-ssf-detail" title="Lihat detail lengkap submission">
                        🔍 Detail
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($ssfTotalCount > count($ssfNotifications)): ?>
        <div class="view-all-link">
            <a href="<?= BASE_URL ?>/pages/submission.php?tab=diterima">Lihat semua (<?= $ssfTotalCount ?>) permohonan SSF &rarr;</a>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="empty-notif">
            <span>✅</span>
            <p style="margin-top:8px">Tidak ada permintaan SSF baru yang menunggu konfirmasi.</p>
            <p style="font-size:0.68rem;color:var(--text3);margin-top:2px">Semua permohonan SSF berstatus diproses / selesai.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── BARIS 3: PENERIMAAN TERBARU + PERALATAN UTAMA ────────── -->
<div class="dashboard-grid">
    <!-- Penerimaan Terbaru -->
    <div class="stat-card">
        <div class="label">PENERIMAAN TERBARU</div>
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead>
                    <tr><th>No. Rec</th><th>Klien</th><th>Sampel</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($penerimaanTerbaru as $p): ?>
                    <tr>
                        <td style="font-weight:700;color:var(--gold)"><?= $p['nomor_penerimaan'] ?></td>
                        <td><?= bersihkan($p['klien']) ?></td>
                        <td><?= $p['jumlah_sampel'] ?></td>
                        <td><?= badgeStatus($p['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Peralatan Utama -->
    <div class="stat-card">
        <div class="label">STATUS PERALATAN</div>
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead>
                    <tr><th>Nama Alat</th><th>Status</th><th>Kalibrasi</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($alatUtama as $a): ?>
                    <tr>
                        <td><?= bersihkan($a['nama']) ?></td>
                        <td><?= badgeStatus($a['status']) ?></td>
                        <td><?= fmtTgl($a['masa_berlaku_kalibrasi']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
