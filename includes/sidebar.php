<?php
// ============================================================
//  includes/sidebar.php — Menu Role-Based
// ============================================================

if (isClient()) {
    $menu = [
        ['href' => 'client_monitoring.php', 'ico' => '📊', 'label' => 'Monitoring Sampel'],
    ];
} else if (currentUserRole() === 'supervisor') {
    // Menu khusus untuk Supervisor: hanya QC & Validasi dan Laporan
    $menu = [
        ['href' => 'qc.php',          'ico' => '&#10003;',  'label' => 'QC &amp; Validasi'],
        ['href' => 'laporan.php',     'ico' => '&#128196;', 'label' => 'Laporan'],
    ];
} else {
    $menu = [
        ['href' => 'dashboard.php',   'ico' => '&#128202;', 'label' => 'Dashboard'],
    ];
    
    // Tambahkan Penerimaan Sampel hanya untuk non-analis
    if (currentUserRole() !== 'analis') {
        $menu[] = ['href' => 'penerimaan.php',  'ico' => '&#128230;', 'label' => 'Penerimaan Sampel'];
    }
    
    $woBadge = '';
    if (isAnalis() && isset($pdo)) {
        try {
            $analisId = (int)($_SESSION['user_id'] ?? 0);
            $stmtWoBadge = $pdo->prepare("SELECT COUNT(*) FROM work_order WHERE analis_id = ? AND status = 'aktif'");
            $stmtWoBadge->execute([$analisId]);
            $cWo = (int)$stmtWoBadge->fetchColumn();
            if ($cWo > 0) {
                $woBadge = ' <span style="background:var(--accent);color:#04240d;padding:1px 7px;border-radius:10px;font-size:0.65rem;font-weight:700;margin-left:auto">' . $cWo . '</span>';
            }
        } catch (Exception $e) {}
    }
    $menu[] = ['href' => 'work_order.php',  'ico' => '&#128203;', 'label' => 'Work Order' . $woBadge];
    
    // Tambahkan menu Preparasi dan QC hanya untuk non-admin
    if (!isAdmin()) {
        $prepBadge = '';
        if (isAnalis() && isset($pdo)) {
            try {
                $analisId = (int)($_SESSION['user_id'] ?? 0);
                $stmtPrepBadge = $pdo->prepare("SELECT COUNT(*) FROM work_order WHERE analis_id = ? AND status = 'aktif' AND butuh_preparasi = 1");
                $stmtPrepBadge->execute([$analisId]);
                $cPrep = (int)$stmtPrepBadge->fetchColumn();
                if ($cPrep > 0) {
                    $prepBadge = ' <span style="background:var(--accent);color:#04240d;padding:1px 7px;border-radius:10px;font-size:0.65rem;font-weight:700;margin-left:auto">' . $cPrep . '</span>';
                }
            } catch (Exception $e) {}
        }
        $menu[] = ['href' => 'preparasi.php',   'ico' => '&#128260;', 'label' => 'Preparasi' . $prepBadge];
        $menu[] = ['href' => 'qc.php',          'ico' => '&#10003;',  'label' => 'QC &amp; Validasi'];
    }
    
    $menu[] = ['href' => 'pengujian.php',   'ico' => '&#128300;', 'label' => 'Pengujian'];
    
    // Tambahkan Invoice hanya untuk non-analis
    if (currentUserRole() !== 'analis') {
        $menu[] = ['href' => 'invoice.php',     'ico' => '&#128179;', 'label' => 'Invoice'];
    }
    
    $menu[] = ['href' => 'bahan.php',       'ico' => '&#129514;', 'label' => 'Inventaris Bahan'];
    $menu[] = ['href' => 'peralatan.php',   'ico' => '&#9881;',   'label' => 'Peralatan'];
    $menu[] = ['href' => 'monitoring.php',  'ico' => '📊',         'label' => 'Monitoring Sampel'];
    $menu[] = ['href' => 'laporan.php',     'ico' => '&#128196;', 'label' => 'Laporan'];
    
    // Tambahkan menu admin
    if (isAdmin()) {
        $ssfPendingBadge = '';
        if ($pdo) {
            try {
                $countSsf = (int)$pdo->query("SELECT COUNT(*) FROM submission_sampel WHERE status IN ('diterima', 'pending')")->fetchColumn();
                if ($countSsf > 0) {
                    $ssfPendingBadge = ' <span style="background:#25d366;color:#04240d;padding:1px 7px;border-radius:10px;font-size:0.65rem;font-weight:700;margin-left:auto">' . $countSsf . '</span>';
                }
            } catch (Exception $e) {}
        }
        $menu[] = ['href' => 'xrf_data.php', 'ico' => '⚡', 'label' => 'Data XRF Explorer'];
        $menu[] = ['href' => 'submission.php', 'ico' => '📋', 'label' => 'Permintaan SSF' . $ssfPendingBadge];
        $menu[] = ['href' => 'metode_preparasi.php', 'ico' => '🧪', 'label' => 'Metode Preparasi'];
        $menu[] = ['href' => 'pengguna.php', 'ico' => '&#128101;', 'label' => 'Pengguna'];
    }
}
$cur = basename($_SERVER['PHP_SELF']);
?>
<div id="sidebar">
    <div class="logo">
        <h2>&#9879; AISPEKTRA LABORATORY</h2>
        <p>Sistem Informasi Laboratorium</p>
    </div>
    <nav>
        <?php foreach ($menu as $m): ?>
            <a href="<?= BASE_URL ?>/pages/<?= $m['href'] ?>"
               class="<?= $cur === $m['href'] ? 'active' : '' ?>"
               style="display:flex;align-items:center;">
                <span class="ico"><?= $m['ico'] ?></span>
                <span style="flex:1;display:flex;align-items:center;justify-content:space-between"><?= $m['label'] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        v<?= APP_VERSION ?> &bull; <?= bersihkan($_SESSION['nama'] ?? '') ?><br>
        <a href="<?= BASE_URL ?>/logout.php" style="color:var(--red)">&#128682; Logout</a>
    </div>
</div>
