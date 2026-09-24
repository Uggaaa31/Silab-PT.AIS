<?php
// ============================================================
//  pengguna.php — Manajemen Pengguna
//  HANYA ADMIN yang dapat mengakses
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

// Hanya admin yang boleh akses
if (!isAdmin()) {
    $_SESSION['msg'] = 'ERROR: Hanya Administrator yang dapat mengakses halaman ini.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Manajemen Pengguna';
$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);

$list = $pdo->query("SELECT * FROM pengguna ORDER BY role, nama")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="sec-title">Manajemen Pengguna</div>
<?php if ($msg): ?>
    <div class="alert-box alert-green" style="margin-bottom:14px">&#10003; <?= bersihkan($msg) ?></div>
<?php endif; ?>

<div class="grid2">
    <div class="card">
        <div class="card-title">&#128101; Daftar Pengguna</div>
        <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $u): ?>
                <tr>
                    <td><?= bersihkan($u['nama']) ?></td>
                    <td><?= bersihkan($u['username']) ?></td>
                    <td><?= bersihkan($u['email'] ?? '&mdash;') ?></td>
                    <td><?= roleBadge($u['role']) ?></td>
                    <td><?= badgeStatus($u['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <div class="card">
        <div class="card-title">&#10133; Tambah Pengguna</div>
        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_pengguna.php">
            <div class="form-row">
                <div class="form-group"><label>Nama Lengkap</label><input name="nama" required placeholder="Nama pengguna"/></div>
                <div class="form-group"><label>Username</label><input name="username" required placeholder="username"/></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="email@lab.com"/></div>
                <div class="form-group"><label>Role</label>
                    <select name="role">
                        <option value="analis">Analis</option>
                        <option value="admin">Admin</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="client">Client</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Password</label><input type="password" name="password" required/></div>
                <div class="form-group"><label>Konfirmasi</label><input type="password" name="password2" required/></div>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
            </div>
            <button type="submit" class="btn btn-gold" style="margin-top:4px">&#128190; Simpan Pengguna</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
