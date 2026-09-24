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
    
    $menu[] = ['href' => 'work_order.php',  'ico' => '&#128203;', 'label' => 'Work Order'];
    
    // Tambahkan menu Preparasi dan QC hanya untuk non-admin
    if (!isAdmin()) {
        $menu[] = ['href' => 'preparasi.php',   'ico' => '&#128260;', 'label' => 'Preparasi'];
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
        $menu[] = ['href' => 'xrf_data.php', 'ico' => '⚡', 'label' => 'Data XRF Explorer'];
        $menu[] = ['href' => 'submission.php', 'ico' => '📋', 'label' => 'Online Submissions'];
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
               class="<?= $cur === $m['href'] ? 'active' : '' ?>">
                <span class="ico"><?= $m['ico'] ?></span>
                <?= $m['label'] ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        v<?= APP_VERSION ?> &bull; <?= bersihkan($_SESSION['nama'] ?? '') ?><br>
        <a href="<?= BASE_URL ?>/logout.php" style="color:var(--red)">&#128682; Logout</a>
    </div>
</div>
