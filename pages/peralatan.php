<?php
// ============================================================
//  peralatan.php
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

// Cek akses
if (!canAccessPeralatan()) {
    $_SESSION['msg'] = 'ERROR: Anda tidak memiliki akses ke halaman Peralatan.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Kondisi Peralatan';

$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);

$list  = $pdo->query("SELECT * FROM peralatan ORDER BY status DESC, nama ASC")->fetchAll();
$tot   = count($list);
$ters  = count(array_filter($list, fn($a) => $a['status'] === 'tersedia'));
$maint = count(array_filter($list, fn($a) => $a['status'] === 'maintenance'));
$rusak = count(array_filter($list, fn($a) => $a['status'] === 'rusak'));

$jadwal = $pdo->query(
    "SELECT nama, kode_alat, jadwal_maintenance, masa_berlaku_kalibrasi, pic
     FROM peralatan
     WHERE jadwal_maintenance IS NOT NULL
        OR masa_berlaku_kalibrasi <= DATE_ADD(NOW(), INTERVAL 30 DAY)
     ORDER BY COALESCE(jadwal_maintenance, masa_berlaku_kalibrasi) ASC
     LIMIT 8"
)->fetchAll();

$statusOpts = ['tersedia','digunakan','maintenance','rusak'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="sec-title">Kondisi &amp; Manajemen Peralatan</div>

<?php if ($msg): ?>
    <div class="alert-box alert-green" style="margin-bottom:14px">&#10003; <?= bersihkan($msg) ?></div>
<?php endif; ?>

<div class="stats">
    <div class="stat-card"><div class="label">Total Peralatan</div><div class="val"><?= $tot ?></div></div>
    <div class="stat-card"><div class="label">Tersedia</div><div class="val"><?= $ters ?></div></div>
    <div class="stat-card"><div class="label">Maintenance</div><div class="val yellow"><?= $maint ?></div></div>
    <div class="stat-card"><div class="label">Rusak / Off</div><div class="val red"><?= $rusak ?></div></div>
</div>

<!-- KARTU PERALATAN -->
<div class="equip-grid" style="margin-bottom:20px">
<?php foreach ($list as $a):
    $exp  = $a['masa_berlaku_kalibrasi'] ? strtotime($a['masa_berlaku_kalibrasi']) : null;
    $days = $exp ? ceil(($exp - time()) / 86400) : null;
    $kLbl = '&mdash;'; $kCol = 'var(--text2)';
    if ($exp) {
        if      ($days < 0)   { $kLbl = 'KADALUARSA';              $kCol = 'var(--red)';    }
        elseif  ($days <= 14) { $kLbl = $days . ' hari lagi';      $kCol = 'var(--yellow)'; }
        else                  { $kLbl = 'Valid ' . date('d M Y', $exp); $kCol = 'var(--green)'; }
    }
?>
    <div class="equip-card">
        <div class="ename"><?= bersihkan($a['nama']) ?></div>
        <div class="eid"><?= bersihkan($a['kode_alat']) ?> | <?= bersihkan($a['lokasi'] ?? '&mdash;') ?></div>
        <?= badgeStatus($a['status']) ?>
        <div class="edet">Kalibrasi: <span style="color:<?= $kCol ?>"><?= $kLbl ?></span></div>
        <?php if ($a['jam_pakai']): ?>
            <div class="edet">Jam Pakai: <?= number_format($a['jam_pakai']) ?> jam</div>
        <?php endif; ?>
        <?php if ($a['catatan']): ?>
            <div class="edet" style="color:var(--yellow)"><?= bersihkan($a['catatan']) ?></div>
        <?php endif; ?>
        <!-- Update status langsung -->
        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_peralatan.php" style="margin-top:8px">
            <input type="hidden" name="action" value="update_status"/>
            <input type="hidden" name="id" value="<?= $a['id'] ?>"/>
            <select name="status" class="sel-inline" onchange="this.form.submit()">
                <?php foreach ($statusOpts as $st): ?>
                    <option value="<?= $st ?>" <?= $a['status'] === $st ? 'selected' : '' ?>>
                        <?= ucfirst($st) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
<?php endforeach; ?>
</div>

<div class="grid2">
    <!-- JADWAL -->
    <div class="card">
        <div class="card-title">&#128197; Jadwal Kalibrasi &amp; Maintenance</div>
        <table>
            <thead><tr><th>Alat</th><th>Kegiatan</th><th>Tanggal</th><th>PIC</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($jadwal as $j):
                    $tgl   = $j['jadwal_maintenance'] ?? $j['masa_berlaku_kalibrasi'];
                    $days2 = $tgl ? ceil((strtotime($tgl) - time()) / 86400) : 999;
                    $jenis = $j['jadwal_maintenance'] ? 'Maintenance' : 'Kalibrasi';
                    $stBdg = $days2 < 0 ? 'kritis' : ($days2 <= 3 ? 'kritis' : ($days2 <= 7 ? 'rendah' : 'aman'));
                ?>
                <tr>
                    <td><?= bersihkan($j['nama']) ?></td>
                    <td><?= $jenis ?></td>
                    <td><?= $tgl ? date('d M Y', strtotime($tgl)) : '&mdash;' ?></td>
                    <td><?= bersihkan($j['pic'] ?? '&mdash;') ?></td>
                    <td><?= badgeStatus($stBdg) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$jadwal): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--text3)">Tidak ada jadwal mendatang.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- FORM TAMBAH ALAT -->
    <div class="card">
        <div class="card-title">&#10133; Tambah Peralatan</div>
        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_peralatan.php">
            <input type="hidden" name="action" value="tambah"/>
            <div class="form-row">
                <div class="form-group"><label>Kode Alat</label><input name="kode_alat" placeholder="AAS-02" required/></div>
                <div class="form-group"><label>Nama Alat</label><input name="nama" placeholder="Nama alat" required/></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Lokasi</label><input name="lokasi" placeholder="Lab Kimia Basah"/></div>
                <div class="form-group">
                    <label>Status Awal</label>
                    <select name="status">
                        <?php foreach ($statusOpts as $st): ?><option><?= $st ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Tgl Kalibrasi</label><input type="date" name="tanggal_kalibrasi"/></div>
                <div class="form-group"><label>Berlaku Sampai</label><input type="date" name="masa_berlaku_kalibrasi"/></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Jadwal Maintenance</label><input type="date" name="jadwal_maintenance"/></div>
                <div class="form-group"><label>PIC</label><input name="pic" placeholder="Penanggung jawab"/></div>
            </div>
            <div class="form-group" style="margin-bottom:14px"><label>Catatan</label><textarea name="catatan" rows="2"></textarea></div>
            <button type="submit" class="btn btn-gold">&#128190; Simpan Peralatan</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
