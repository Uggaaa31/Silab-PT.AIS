<?php
// ============================================================
//  metode_preparasi.php — Admin-only CRUD Metode Preparasi
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
/** @var PDO $pdo */
cekLogin();

if (!canAccessMetodePreparasi()) {
    $_SESSION['msg'] = 'ERROR: Hanya Administrator yang dapat mengakses halaman ini.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Metode Preparasi';
$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);

ensureMetodePreparasiTable($pdo);

$editId = (int)($_GET['edit_id'] ?? 0);
$editItem = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT id, metode FROM metode_preparasi WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editItem = $stmt->fetch();
}

$methodList = $pdo->query('SELECT id, metode, created_at FROM metode_preparasi ORDER BY metode ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="sec-title">Metode Preparasi</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg, 'ERROR') ? 'alert-red' : 'alert-green' ?>" style="margin-bottom:14px">
        <?= str_starts_with($msg, 'ERROR') ? '&#9888;' : '&#10003;' ?> <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<div class="grid2">
    <div class="card">
        <div class="card-title">Daftar Metode Preparasi</div>
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Metode Preparasi</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($methodList as $item): ?>
                    <tr>
                        <td><?= bersihkan($item['metode']) ?></td>
                        <td><?= fmtTgl($item['created_at'], 'd M Y H:i') ?></td>
                        <td style="white-space:nowrap">
                            <a class="btn btn-small" href="<?= BASE_URL ?>/pages/metode_preparasi.php?edit_id=<?= (int)$item['id'] ?>">Edit</a>
                            <form method="POST" action="<?= BASE_URL ?>/actions/simpan_metode_preparasi.php" style="display:inline-block;margin:0">
                                <input type="hidden" name="action" value="hapus"/>
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>"/>
                                <button type="submit" class="btn btn-red btn-small" onclick="return confirm('Hapus metode ini?');">Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$methodList): ?>
                    <tr><td colspan="3" style="text-align:center;color:var(--text3);padding:18px">Belum ada metode preparasi.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><?= $editItem ? '&#9998; Ubah Metode Preparasi' : '&#10133; Tambah Metode Preparasi' ?></div>
        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_metode_preparasi.php">
            <input type="hidden" name="action" value="<?= $editItem ? 'update' : 'tambah' ?>"/>
            <?php if ($editItem): ?>
                <input type="hidden" name="id" value="<?= $editItem['id'] ?>"/>
            <?php endif; ?>
            <div class="form-group">
                <label>Metode Preparasi</label>
                <input name="metode" required placeholder="Masukkan nama metode" value="<?= bersihkan($editItem['metode'] ?? '') ?>" />
            </div>
            <button type="submit" class="btn btn-gold" style="margin-top:4px">
                <?= $editItem ? 'Simpan Perubahan' : 'Tambahkan Metode' ?>
            </button>
            <?php if ($editItem): ?>
                <a href="<?= BASE_URL ?>/pages/metode_preparasi.php" class="btn btn-secondary" style="margin-left:10px">Batal</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
