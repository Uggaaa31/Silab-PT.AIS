<?php
// ============================================================
//  submission.php — Manajemen Submission Sampel dari Klien
//  HANYA ADMIN yang dapat mengakses
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

if (!isAdmin()) {
    $_SESSION['msg'] = 'ERROR: Hanya Administrator yang dapat mengakses halaman ini.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Manajemen Submission Sampel';
$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$tab = $_GET['tab'] ?? 'pending';

// Ambil data submission
$fStatus = $_GET['status'] ?? '';
$sql = "SELECT s.*, 
               (SELECT COUNT(*) FROM submission_sampel_detail WHERE submission_id = s.id) AS jumlah_sampel
        FROM submission_sampel s";
if ($fStatus) {
    $sql .= " WHERE s.status = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fStatus]);
} else {
    $stmt = $pdo->query($sql);
}
$submissions = $stmt->fetchAll();

// Statistik
$pending = $pdo->query("SELECT COUNT(*) FROM submission_sampel WHERE status='pending'")->fetchColumn();
$diterima = $pdo->query("SELECT COUNT(*) FROM submission_sampel WHERE status='diterima'")->fetchColumn();
$ditolak = $pdo->query("SELECT COUNT(*) FROM submission_sampel WHERE status='ditolak'")->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border);}
.tab-btn{padding:8px 18px;background:none;border:none;border-bottom:3px solid transparent;color:var(--text3);font-size:.82rem;cursor:pointer;font-weight:600;transition:.2s;margin-bottom:-1px;}
.tab-btn:hover{color:var(--text2);}
.tab-btn.active{color:var(--gold);border-bottom-color:var(--gold);}
.tab-pane{display:none;}.tab-pane.active{display:block;}
.stat-mini{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;}
.smc{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 14px;}
.smc .v{font-size:1.5rem;font-weight:700;}
.smc .v.pending{color:var(--yellow);}
.smc .v.diterima{color:var(--green);}
.smc .v.ditolak{color:var(--red);}
.smc .l{font-size:.7rem;color:var(--text3);margin-top:2px;}
.submission-card{background:var(--card);border:1px solid var(--border);border-radius:10px;margin-bottom:12px;overflow:hidden;}
.submission-header{display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--bg3);cursor:pointer;flex-wrap:wrap;}
.submission-no{font-weight:700;color:var(--gold);font-family:monospace;}
.submission-body{display:none;padding:16px;border-top:1px solid var(--border);}
.submission-body.open{display:block;}
.submission-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:12px;}
.sample-list{margin-top:12px;}
.sample-list table{width:100%;border-collapse:collapse;font-size:.75rem;}
.sample-list th,.sample-list td{padding:6px 8px;border-bottom:1px solid var(--border);text-align:left;}
</style>

<div class="sec-title">📋 Manajemen Submission Sampel</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR')?'alert-red':'alert-green' ?>">
        <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<div class="stat-mini">
    <div class="smc"><div class="v pending"><?= $pending ?></div><div class="l">Menunggu Proses</div></div>
    <div class="smc"><div class="v diterima"><?= $diterima ?></div><div class="l">Diterima / Diproses</div></div>
    <div class="smc"><div class="v ditolak"><?= $ditolak ?></div><div class="l">Ditolak</div></div>
</div>

<div class="tabs">
    <button class="tab-btn <?= $tab==='pending'?'active':'' ?>" onclick="switchTab('pending',this)">⏳ Menunggu (<?= $pending ?>)</button>
    <button class="tab-btn <?= $tab==='diterima'?'active':'' ?>" onclick="switchTab('diterima',this)">✅ Diterima (<?= $diterima ?>)</button>
    <button class="tab-btn <?= $tab==='ditolak'?'active':'' ?>" onclick="switchTab('ditolak',this)">❌ Ditolak (<?= $ditolak ?>)</button>
    <button class="tab-btn <?= $tab==='semua'?'active':'' ?>" onclick="switchTab('semua',this)">📋 Semua</button>
</div>

<div id="tab-pending" class="tab-pane <?= $tab==='pending'?'active':'' ?>">
    <?php renderSubmissionList(array_filter($submissions, fn($s) => $s['status'] === 'pending')); ?>
</div>
<div id="tab-diterima" class="tab-pane <?= $tab==='diterima'?'active':'' ?>">
    <?php renderSubmissionList(array_filter($submissions, fn($s) => $s['status'] === 'diterima')); ?>
</div>
<div id="tab-ditolak" class="tab-pane <?= $tab==='ditolak'?'active':'' ?>">
    <?php renderSubmissionList(array_filter($submissions, fn($s) => $s['status'] === 'ditolak')); ?>
</div>
<div id="tab-semua" class="tab-pane <?= $tab==='semua'?'active':'' ?>">
    <?php renderSubmissionList($submissions); ?>
</div>

<script>
function switchTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    if (el) el.classList.add('active');
    history.replaceState(null,'','?tab='+name);
}

function toggleSubmission(id) {
    const body = document.getElementById('submission-body-' + id);
    if (body) body.classList.toggle('open');
}

function updateStatus(submissionId, status) {
    if (status === 'diterima') {
        if (confirm('Terima submission ini? Akun client akan disiapkan lalu bisa dikirim via WhatsApp.')) {
            fetch('<?= BASE_URL ?>/actions/update_submission_status.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id=' + submissionId + '&status=' + status
            }).then(response => response.json()).then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Gagal mengubah status: ' + data.message);
                }
            });
        }
    } else if (status === 'ditolak') {
        if (confirm('Tolak submission ini?')) {
            fetch('<?= BASE_URL ?>/actions/update_submission_status.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id=' + submissionId + '&status=' + status
            }).then(response => response.json()).then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Gagal mengubah status: ' + data.message);
                }
            });
        }
    }
}
</script>

<?php
function renderSubmissionList($submissions) {
    global $pdo;
    if (empty($submissions)) {
        echo '<div class="card" style="text-align:center;padding:30px;color:var(--text3)">Tidak ada data submission.</div>';
        return;
    }
    
    foreach ($submissions as $sub) {
        $cred = null;
        if ($sub['status'] === 'diterima') {
            $credStmt = $pdo->prepare("
                SELECT p.username, s.telepon
                FROM client_access ca
                JOIN pengguna p ON p.id = ca.pengguna_id
                JOIN submission_sampel s ON s.id = ca.submission_id
                WHERE ca.submission_id = ?
                ORDER BY ca.id DESC
                LIMIT 1
            ");
            $credStmt->execute([$sub['id']]);
            $cred = $credStmt->fetch();
        }

        // Ambil detail sampel
        $detailStmt = $pdo->prepare("SELECT * FROM submission_sampel_detail WHERE submission_id = ?");
        $detailStmt->execute([$sub['id']]);
        $details = $detailStmt->fetchAll();
        ?>
        <div class="submission-card">
            <div class="submission-header" onclick="toggleSubmission(<?= $sub['id'] ?>)">
                <span class="submission-no">📋 <?= bersihkan($sub['nomor_submission']) ?></span>
                <span><?= bersihkan($sub['klien']) ?></span>
                <span style="color:var(--text3);font-size:.75rem"><?= fmtTgl($sub['tanggal_submit']) ?></span>
                <span><?= count($details) ?> sampel</span>
                <?= badgeStatus($sub['status']) ?>
                <span style="margin-left:auto">▼</span>
            </div>
            <div id="submission-body-<?= $sub['id'] ?>" class="submission-body">
                <div class="submission-grid">
                    <div><strong>Kontak Person:</strong> <?= bersihkan($sub['kontak_person'] ?? '-') ?></div>
                    <div><strong>Email:</strong> <?= bersihkan($sub['email'] ?? '-') ?></div>
                    <div><strong>Telepon:</strong> <?= bersihkan($sub['telepon'] ?? '-') ?></div>
                    <div><strong>PO/Referensi:</strong> <?= bersihkan($sub['po_referensi'] ?? '-') ?></div>
                    <div><strong>Alamat:</strong> <?= bersihkan($sub['alamat'] ?? '-') ?></div>
                    <div><strong>Tanggal Submit:</strong> <?= fmtTgl($sub['tanggal_submit'], 'd/m/Y H:i') ?></div>
                </div>
                
                <?php if ($sub['instruksi_khusus']): ?>
                <div style="margin-bottom:12px"><strong>Instruksi Khusus:</strong><br><?= nl2br(bersihkan($sub['instruksi_khusus'])) ?></div>
                <?php endif; ?>
                
                <div class="sample-list">
                    <strong>Daftar Sampel:</strong>
                    <table>
                        <thead><tr><th>#</th><th>Jenis Material</th><th>Berat (g)</th><th>Metode</th><th>Parameter</th><th>Keterangan</th></tr></thead>
                        <tbody>
                        <?php foreach ($details as $i => $d): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= bersihkan($d['jenis_material']) ?></td>
                            <td><?= $d['berat_gram'] ? number_format($d['berat_gram'],2) : '-' ?></td>
                            <td><?= bersihkan($d['metode_uji']) ?></td>
                            <td><?= bersihkan($d['parameter']) ?></td>
                            <td><?= bersihkan($d['keterangan']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($sub['catatan']): ?>
                <div style="margin-top:12px"><strong>Catatan:</strong> <?= nl2br(bersihkan($sub['catatan'])) ?></div>
                <?php endif; ?>
                
                <?php if ($sub['status'] === 'diterima'): ?>
                <div style="margin-top:12px;padding:10px 12px;border:1px dashed var(--gold);border-radius:8px;background:rgba(240,192,64,.08)">
                    <div style="font-size:.72rem;color:var(--text3);margin-bottom:6px">Akun Monitoring Client</div>
                    <?php if ($cred): ?>
                        <?php
                        $waPhone = preg_replace('/[^0-9]/', '', (string)($cred['telepon'] ?? ''));
                        if (substr($waPhone, 0, 1) === '0') {
                            $waPhone = '62' . substr($waPhone, 1);
                        }
                        $loginUrl = BASE_URL . '/index.php';
                        $waText = "Halo {$sub['klien']}, akun monitoring sampel Anda sudah aktif.\n\nUsername: {$cred['username']}\nPassword: client123\nLink Login: {$loginUrl}\n\nSilakan login untuk memantau progres sampel.";
                        $waLink = $waPhone ? ('https://wa.me/' . $waPhone . '?text=' . urlencode($waText)) : '';
                        ?>
                        <div><strong>Username:</strong> <?= bersihkan($cred['username']) ?></div>
                        <div><strong>Password:</strong> <code>client123</code></div>
                        <?php if ($waLink): ?>
                            <div style="margin-top:8px">
                                <a href="<?= $waLink ?>" target="_blank" class="btn btn-green btn-sm">Kirim Username/Password ke WhatsApp</a>
                            </div>
                        <?php else: ?>
                            <div style="margin-top:8px;color:var(--yellow)">Nomor WhatsApp client belum valid.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="color:var(--yellow)">Akun client belum ditemukan. Klik refresh atau cek data client_access.</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end">
                    <?php if ($sub['status'] === 'pending'): ?>
                        <button onclick="updateStatus(<?= $sub['id'] ?>, 'diterima')" class="btn btn-green btn-sm">Terima</button>
                        <button onclick="updateStatus(<?= $sub['id'] ?>, 'ditolak')" class="btn btn-red btn-sm">Tolak</button>
                    <?php elseif ($sub['status'] === 'diterima'): ?>
                        <button onclick="location.href='<?= BASE_URL ?>/pages/penerimaan.php?process_submission=<?= $sub['id'] ?>'" class="btn btn-gold btn-sm">Proses ke Penerimaan</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
