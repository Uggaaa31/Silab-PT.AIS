<?php
// ============================================================
//  bahan.php
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

// Cek akses
if (!canAccessBahan()) {
    $_SESSION['msg'] = 'ERROR: Anda tidak memiliki akses ke halaman Bahan.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Inventaris Bahan & Reagen';

$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);

$list    = $pdo->query("SELECT * FROM bahan ORDER BY stok ASC")->fetchAll();
$kritis  = 0; $rendah = 0;
foreach ($list as $b) {
    $st = statusBahan($b['stok'], $b['stok_minimum']);
    if ($st === 'kritis') $kritis++;
    elseif ($st === 'rendah') $rendah++;
}

$satuanOpts = ['Liter','mL','kg','gram','Ampul','Botol','Pak'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="sec-title">Inventaris Bahan &amp; Reagen</div>

<?php if ($msg): ?>
    <div class="alert-box alert-green" style="margin-bottom:14px">&#10003; <?= bersihkan($msg) ?></div>
<?php endif; ?>

<div class="stats" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat-card">
        <div class="label">Total Jenis Bahan</div>
        <div class="val"><?= count($list) ?></div>
        <div class="sub">Aktif tersedia</div>
    </div>
    <div class="stat-card">
        <div class="label">Stok Kritis</div>
        <div class="val red"><?= $kritis ?></div>
        <div class="sub">Perlu reorder segera</div>
    </div>
    <div class="stat-card">
        <div class="label">Stok Rendah</div>
        <div class="val yellow"><?= $rendah ?></div>
        <div class="sub">Monitor penggunaan</div>
    </div>
</div>

<div class="grid2">
    <!-- DAFTAR BAHAN -->
    <div class="card">
        <div class="card-title">&#129514; Daftar Stok Bahan</div>
        <table>
            <thead>
                <tr><th>Kode</th><th>Nama Bahan</th><th>Stok</th><th>Satuan</th><th>Min</th><th>Kadaluarsa</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($list as $b):
                    $st  = statusBahan($b['stok'], $b['stok_minimum']);
                    $col = $st === 'kritis' ? 'var(--red)' : ($st === 'rendah' ? 'var(--yellow)' : 'var(--text2)');
                ?>
                <tr>
                    <td><?= bersihkan($b['kode_bahan']) ?></td>
                    <td><?= bersihkan($b['nama']) ?></td>
                    <td style="font-weight:700;color:<?= $col ?>"><?= $b['stok'] ?></td>
                    <td><?= bersihkan($b['satuan']) ?></td>
                    <td><?= $b['stok_minimum'] ?></td>
                    <td><?= fmtTgl($b['tanggal_kadaluarsa']) ?></td>
                    <td><?= badgeStatus($st) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- FORM TAMBAH / UPDATE -->
    <div class="card">
        <div class="card-title">&#10133; Tambah / Update Stok</div>
        <p style="font-size:.75rem;color:var(--text3);margin-bottom:12px">
            Jika kode bahan sudah ada, stok akan <strong>ditambahkan</strong> secara otomatis.
        </p>
        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_bahan.php">
            <div class="form-row">
                <div class="form-group">
                    <label>Nama Bahan</label>
                    <input name="nama" placeholder="Nama bahan kimia" required/>
                </div>
                <div class="form-group">
                    <label>Kode Bahan</label>
                    <input name="kode_bahan" placeholder="BH-XXX" required/>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Jumlah Stok</label>
                    <input type="number" step="0.001" name="stok" placeholder="0.000" required/>
                </div>
                <div class="form-group">
                    <label>Satuan</label>
                    <select name="satuan">
                        <?php foreach ($satuanOpts as $u): ?><option><?= $u ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Stok Minimum</label>
                    <input type="number" step="0.001" name="stok_minimum" placeholder="0.000" required/>
                </div>
                <div class="form-group">
                    <label>Tanggal Kadaluarsa</label>
                    <input type="date" name="tanggal_kadaluarsa"/>
                </div>
            </div>
            <div class="form-group" style="margin-bottom:14px">
                <label>Supplier</label>
                <input name="supplier" placeholder="Nama supplier"/>
            </div>
            <button type="submit" class="btn btn-gold">&#128190; Simpan</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
