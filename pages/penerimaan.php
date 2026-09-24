<?php
// ============================================================
//  penerimaan.php — Penerimaan Sampel
//  UPDATE: Dapat mengambil data dari submission yang diterima
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

// Hanya admin yang boleh mengakses
if (!isAdmin()) {
    $_SESSION['msg'] = 'ERROR: Hanya Administrator yang dapat mengakses halaman Penerimaan Sampel.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Penerimaan Sampel';

$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$tab = $_GET['tab'] ?? 'daftar';
$submissionTablesReady = tableExists($pdo, 'submission_sampel') && tableExists($pdo, 'submission_sampel_detail');

// ── Proses konfirmasi penerimaan dari submission ─────────────
$processSubmission = (int)($_GET['process_submission'] ?? 0);
if ($processSubmission) {
    if (!$submissionTablesReady) {
        $_SESSION['msg'] = 'ERROR: Tabel submission belum tersedia. Jalankan scripts/sql/patch_client_role.sql terlebih dahulu.';
        header('Location: ' . BASE_URL . '/pages/penerimaan.php');
        exit;
    }

    // Ambil data submission (tanpa status filter untuk memastikan)
    $subStmt = $pdo->prepare("SELECT * FROM submission_sampel WHERE id = ?");
    $subStmt->execute([$processSubmission]);
    $submission = $subStmt->fetch();
    
    if ($submission) {
        // Jika status masih pending, ubah ke diterima dulu
        if ($submission['status'] === 'pending') {
            $pdo->prepare("UPDATE submission_sampel SET status = 'diterima' WHERE id = ?")->execute([$processSubmission]);
            $submission['status'] = 'diterima';
        }
        
        if ($submission['status'] === 'diterima') {
            // Ambil detail sampel
            $detailStmt = $pdo->prepare("SELECT * FROM submission_sampel_detail WHERE submission_id = ?");
            $detailStmt->execute([$processSubmission]);
            $sampelDetails = $detailStmt->fetchAll();
            
            if (!empty($sampelDetails)) {
                // Generate nomor penerimaan
                $lastRec = $pdo->query("SELECT nomor_penerimaan FROM penerimaan_sampel ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextNum = $lastRec ? (intval(substr($lastRec, -3)) + 1) : 1;
                $noPenerimaan = 'REC-' . date('ym') . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                
                // Kumpulkan jenis material dan metode unik
                $materials = array_unique(array_column($sampelDetails, 'jenis_material'));
                $metodes = array_unique(array_column($sampelDetails, 'metode_uji'));
                $materialStr = implode(', ', array_filter($materials));
                $metodeStr = implode(', ', array_filter($metodes));
                
                // Buat keterangan dari instruksi submission
                $keterangan = 'Dari submission: ' . $submission['nomor_submission'];
                if (!empty($submission['instruksi_khusus'])) {
                    $keterangan .= ' - Instruksi: ' . $submission['instruksi_khusus'];
                }
                
                try {
                    $pdo->beginTransaction();
                    
                    // Insert ke penerimaan_sampel
                    $stmtRec = $pdo->prepare("
                        INSERT INTO penerimaan_sampel 
                        (nomor_penerimaan, klien, tanggal_terima, jumlah_sampel, jenis_material, metode_uji, keterangan, status, dibuat_oleh)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'diterima', ?)
                    ");
                    $stmtRec->execute([
                        $noPenerimaan,
                        $submission['klien'],
                        date('Y-m-d'),
                        count($sampelDetails),
                        $materialStr,
                        $metodeStr,
                        $keterangan,
                        $_SESSION['user_id']
                    ]);
                    $penerimaanId = $pdo->lastInsertId();
                    
                    // Generate kode sampel
                    $prefix = 'S-' . date('ym') . '-';
                    $lastKode = $pdo->prepare("SELECT kode_sampel FROM sampel WHERE kode_sampel LIKE ? ORDER BY kode_sampel DESC LIMIT 1");
                    $lastKode->execute([$prefix . '%']);
                    $lastKodeStr = $lastKode->fetchColumn();
                    $nextKodeNum = $lastKodeStr ? (intval(substr($lastKodeStr, -3)) + 1) : 1;
                    
                    // Insert sampel
                    $stmtSampel = $pdo->prepare("
                        INSERT INTO sampel 
                        (penerimaan_id, kode_sampel, tanggal_masuk, jenis_material, berat_gram, klien, metode_uji, keterangan, dibuat_oleh)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($sampelDetails as $detail) {
                        $kodeSampel = 'S-' . date('ym') . '-' . str_pad($nextKodeNum, 3, '0', STR_PAD_LEFT);
                        $sampelKeterangan = $detail['keterangan'] ?? '';
                        if (!empty($submission['nomor_submission'])) {
                            $sampelKeterangan .= ' (dari submission: ' . $submission['nomor_submission'] . ')';
                        }
                        
                        $stmtSampel->execute([
                            $penerimaanId,
                            $kodeSampel,
                            date('Y-m-d'),
                            $detail['jenis_material'],
                            $detail['berat_gram'],
                            $submission['klien'],
                            $detail['metode_uji'],
                            trim($sampelKeterangan),
                            $_SESSION['user_id']
                        ]);
                        $nextKodeNum++;
                    }
                    
                    // Update status submission menjadi diproses
                    $pdo->prepare("UPDATE submission_sampel SET status = 'diproses' WHERE id = ?")->execute([$processSubmission]);
                    
                    $pdo->commit();
                    
                    $linkedClients = attachClientAccessToPenerimaan($pdo, $processSubmission, (int)$penerimaanId);
                    $clientMsg = '';
                    $clientAccount = createClientAccountForAccess($pdo, [
                        'kode_akses' => $submission['nomor_submission'],
                        'submission_id' => $processSubmission,
                        'penerimaan_id' => $penerimaanId,
                        'klien' => $submission['klien'],
                        'email' => $submission['email'] ?? '',
                    ]);
                    if ($clientAccount['created'] ?? false) {
                        $teleponRaw = preg_replace('/[^0-9]/', '', $submission['telepon'] ?? '');
                        if (substr($teleponRaw, 0, 1) === '0') {
                            $teleponRaw = '62' . substr($teleponRaw, 1);
                        }
                        $waMsg = "Halo {$submission['klien']}, Akun Monitoring LabMineral Anda sudah aktif.\n\nUsername: {$clientAccount['username']}\nPassword: {$clientAccount['password']}\nLink Login: " . BASE_URL . "/index.php\n\nTerima kasih.";
                        $waLink = $teleponRaw ? "https://wa.me/" . $teleponRaw . "?text=" . urlencode($waMsg) : '';

                        $clientMsg = $linkedClients > 0
                            ? ' Akun client dari submission sudah ditautkan ke penerimaan ini.'
                            : ' Akun client berhasil dibuat.';
                        $clientMsg .= " Username: <strong>{$clientAccount['username']}</strong>, Password: <strong>{$clientAccount['password']}</strong>.";
                        if ($waLink) {
                            $clientMsg .= " <a href='$waLink' target='_blank' class='btn btn-green btn-sm' style='margin-left:10px;text-decoration:none;display:inline-flex;align-items:center;gap:5px'><span style='font-size:1.1rem'>??</span> Kirim via WA</a>";
                        } else {
                            $clientMsg .= " Nomor WhatsApp client belum valid, jadi link kirim WA belum bisa dibuat.";
                        }
                    } elseif (!empty($clientAccount['message'])) {
                        $clientMsg = " Akun client belum dibuat: {$clientAccount['message']}";
                    }

                    $_SESSION['msg'] = "✅ Penerimaan $noPenerimaan berhasil dibuat dari submission {$submission['nomor_submission']}. " . count($sampelDetails) . " sampel ditambahkan." . $clientMsg;
                    header('Location: ' . BASE_URL . '/pages/penerimaan.php?tab=daftar');
                    exit;
                    
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $_SESSION['msg'] = 'ERROR: Gagal memproses submission. ' . $e->getMessage();
                    header('Location: ' . BASE_URL . '/pages/penerimaan.php');
                    exit;
                }
            } else {
                $_SESSION['msg'] = 'ERROR: Tidak ada detail sampel dalam submission ini.';
                header('Location: ' . BASE_URL . '/pages/penerimaan.php');
                exit;
            }
        } else {
            $_SESSION['msg'] = 'ERROR: Submission sudah diproses sebelumnya.';
            header('Location: ' . BASE_URL . '/pages/penerimaan.php');
            exit;
        }
    } else {
        $_SESSION['msg'] = 'ERROR: Submission tidak ditemukan.';
        header('Location: ' . BASE_URL . '/pages/penerimaan.php');
        exit;
    }
}

// ── Ambil data dari submission jika ada (untuk form) ─────────
$fromSubmission = (int)($_GET['from_submission'] ?? 0);
$submissionData = null;
$submissionSampel = [];

if ($fromSubmission) {
    if (!$submissionTablesReady) {
        $msg = 'ERROR: Tabel submission belum tersedia. Jalankan scripts/sql/patch_client_role.sql terlebih dahulu.';
        $fromSubmission = 0;
    } else {
        // Ambil data submission
        $subStmt = $pdo->prepare("SELECT * FROM submission_sampel WHERE id = ? AND status = 'diterima'");
        $subStmt->execute([$fromSubmission]);
        $submissionData = $subStmt->fetch();
        
        if ($submissionData) {
            // Ambil detail sampel
            $detailStmt = $pdo->prepare("SELECT * FROM submission_sampel_detail WHERE submission_id = ?");
            $detailStmt->execute([$fromSubmission]);
            $submissionSampel = $detailStmt->fetchAll();
            
            // Tampilkan pesan info
            $msg = '<strong>📋 Data dari Submission:</strong> ' . bersihkan($submissionData['nomor_submission']) . 
                   ' - ' . bersihkan($submissionData['klien']) . 
                   ' (' . count($submissionSampel) . ' sampel)';
            
            // Auto-set tab ke batch untuk memudahkan input
            $tab = 'batch';
            
            // Pre-fill data untuk form
            $prefillKlien = $submissionData['klien'];
            $prefillKontak = $submissionData['kontak_person'];
            $prefillEmail = $submissionData['email'];
            $prefillTelepon = $submissionData['telepon'];
            $prefillAlamat = $submissionData['alamat'];
            $prefillPoReferensi = $submissionData['po_referensi'];
            $prefillInstruksi = $submissionData['instruksi_khusus'];
            $prefillCatatan = $submissionData['catatan'];
        } else {
            $msg = 'ERROR: Submission tidak ditemukan atau belum diterima.';
            $fromSubmission = 0;
        }
    }
}

// ── Auto-generate nomor penerimaan ───────────────────────────
$lastRec = $pdo->query("SELECT nomor_penerimaan FROM penerimaan_sampel ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextNum = $lastRec ? (intval(substr($lastRec, -3)) + 1) : 1;
$noAuto  = 'REC-' . date('ym') . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

// ── Daftar penerimaan ────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$fStatus = $_GET['status'] ?? '';
$sqlRec  = "SELECT p.*, u.nama AS operator,
                   (SELECT COUNT(*) FROM sampel s WHERE s.penerimaan_id = p.id) AS total_sampel,
                   (SELECT COUNT(*) FROM sampel s JOIN hasil_uji h ON h.sampel_id = s.id
                    WHERE s.penerimaan_id = p.id AND h.kesimpulan='lulus') AS total_lulus,
                   (SELECT COUNT(*) FROM sampel s JOIN hasil_uji h ON h.sampel_id = s.id
                    WHERE s.penerimaan_id = p.id AND h.kesimpulan='tidak_lulus') AS total_tlulus
            FROM penerimaan_sampel p
            LEFT JOIN pengguna u ON p.dibuat_oleh = u.id
            WHERE 1=1";
$pRec = [];
if ($search)  { $sqlRec .= " AND (p.nomor_penerimaan LIKE ? OR p.klien LIKE ?)"; $pRec = array_merge($pRec, ["%$search%","%$search%"]); }
if ($fStatus) { $sqlRec .= " AND p.status = ?"; $pRec[] = $fStatus; }
$sqlRec .= " ORDER BY p.created_at DESC";
$stRec   = $pdo->prepare($sqlRec); $stRec->execute($pRec);
$recList = $stRec->fetchAll();

$materialOpts = ['Bijih Emas','Nikel Laterit','Tembaga','Bauksit','Bijih Besi','Timbal/Seng','Mangan','Kromit','Lainnya'];
$metodeOpts   = ['AAS','XRF','ICP-OES','Gravimetri','Fire Assay','Volumetri'];

// Ambil daftar submission yang sudah diterima untuk dropdown
$submissionsList = [];
if ($submissionTablesReady) {
    $submissionsList = $pdo->query("
        SELECT id, nomor_submission, klien, email, telepon, tanggal_submit,
               (SELECT COUNT(*) FROM submission_sampel_detail WHERE submission_id = submission_sampel.id) AS jumlah_sampel
        FROM submission_sampel
        WHERE status = 'diterima'
        ORDER BY tanggal_submit DESC
    ")->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border);}
.tab-btn{padding:8px 18px;background:none;border:none;border-bottom:3px solid transparent;color:var(--text3);font-size:.82rem;cursor:pointer;font-weight:600;transition:.2s;margin-bottom:-1px;}
.tab-btn:hover{color:var(--text2);}
.tab-btn.active{color:var(--gold);border-bottom-color:var(--gold);}
.tab-pane{display:none;}.tab-pane.active{display:block;}

/* Submission info card */
.submission-info-card {
    background: #0d2318;
    border: 1px solid var(--gold);
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
}
.submission-info-card .title {
    color: var(--gold);
    font-weight: 600;
    margin-bottom: 8px;
    font-size: .85rem;
}
.submission-info-card .details {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 8px;
    font-size: .75rem;
}
.submission-info-card .details span {
    color: var(--text3);
}
.submission-info-card .details strong {
    color: var(--text);
}

/* Rapikan tabel daftar penerimaan */
#tab-daftar .card-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
}
#tab-daftar .data-table th,
#tab-daftar .data-table td{
    vertical-align:middle;
}
#tab-daftar .data-table th:nth-child(4),
#tab-daftar .data-table th:nth-child(5),
#tab-daftar .data-table th:nth-child(6),
#tab-daftar .data-table td:nth-child(4),
#tab-daftar .data-table td:nth-child(5),
#tab-daftar .data-table td:nth-child(6){
    text-align:center;
    width:90px;
}
#tab-daftar .data-table th:nth-child(7),
#tab-daftar .data-table td:nth-child(7){
    width:120px;
    text-align:center;
}
#tab-daftar .data-table th:nth-child(8),
#tab-daftar .data-table td:nth-child(8){
    width:200px;
}
.aksi-wrap{
    display:flex;
    gap:6px;
    align-items:center;
}
.detail-wrap{
    display:grid;
    grid-template-columns: 170px 1.2fr 120px 120px 120px;
    gap:12px;
    align-items:center;
    font-size:.75rem;
    color:var(--text2);
}
.detail-row td{
    background:#08160d;
    border-top:none !important;
    padding:8px 10px !important;
}
.detail-code{
    color:var(--text3);
    font-family:monospace;
    font-size:.74rem;
}
@media (max-width: 980px){
    .detail-wrap{
        grid-template-columns: 1fr 1fr;
        row-gap:8px;
    }
}

/* Print */
@media print{
    .print-bar, .tabs, .sel-card, #tab-daftar, #tab-batch, .sidebar-footer,
    #sidebar, #topbar, .no-print { display:none !important; }
    #main, #content { display:block !important; overflow:visible !important; height:auto !important; }
    body { overflow:visible !important; height:auto !important; }
    @page { size:A4 portrait; margin:12mm 15mm; }
}
</style>

<div class="sec-title">Penerimaan Sampel</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR')?'alert-red':'alert-green' ?>" style="margin-bottom:14px">
        <?= str_starts_with($msg,'ERROR')?'&#9888;':'&#10003;' ?> <?= $msg ?>
    </div>
<?php endif; ?>

<!-- TABS -->
<div class="tabs no-print">
    <button class="tab-btn <?= $tab==='daftar'?'active':'' ?>" onclick="switchTab('daftar',this)">&#128203; Daftar Penerimaan</button>
    <button class="tab-btn <?= $tab==='batch'?'active':'' ?>"  onclick="switchTab('batch',this)">&#10133; Penerimaan Baru</button>
</div>

<!-- ══════════════════════════════════════════════════
     TAB 1 — DAFTAR PENERIMAAN
══════════════════════════════════════════════════ -->
<div id="tab-daftar" class="tab-pane <?= $tab==='daftar'?'active':'' ?>">

    <!-- Filter -->
    <form method="GET" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
        <input type="hidden" name="tab" value="daftar"/>
        <input name="q" value="<?= bersihkan($search) ?>" placeholder="&#128269; Cari no. penerimaan / klien..."
               style="flex:1;background:var(--bg3);border:1px solid var(--border);color:var(--text);
                      padding:7px 12px;border-radius:6px;font-size:.8rem;outline:none"/>
        <select name="status" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:.8rem;outline:none">
            <option value="">Semua Status</option>
            <?php foreach (['diterima','diproses','selesai'] as $st): ?>
                <option value="<?= $st ?>" <?= $fStatus===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-green btn-sm">Cari</button>
    </form>

    <div class="card">
        <div class="card-title">&#128230; Daftar Penerimaan Sampel <span><?= count($recList) ?> batch</span></div>
        <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>No. Penerimaan</th><th>Klien</th><th>Tgl Terima</th>
                    <th>Sampel</th><th>Lulus</th><th>Tdk Lulus</th>
                    <th>Status</th><th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recList as $r): ?>
            <tr>
                <td style="font-weight:700;color:var(--gold)"><?= bersihkan($r['nomor_penerimaan']) ?></td>
                <td><?= bersihkan($r['klien']) ?></td>
                <td><?= date('d/m/Y', strtotime($r['tanggal_terima'])) ?></td>
                <td style="text-align:center"><strong><?= $r['total_sampel'] ?></strong></td>
                <td style="text-align:center;color:var(--green)"><?= $r['total_lulus'] ?></td>
                <td style="text-align:center;color:var(--red)"><?= $r['total_tlulus'] ?></td>
                <td>
                    <form method="POST" action="<?= BASE_URL ?>/actions/simpan_penerimaan.php" style="display:inline">
                        <input type="hidden" name="action" value="update_status"/>
                        <input type="hidden" name="id" value="<?= $r['id'] ?>"/>
                        <select name="status" class="sel-inline" onchange="this.form.submit()" style="width:88px;font-size:.7rem">
                            <?php foreach (['diterima','diproses','selesai'] as $st): ?>
                                <option value="<?= $st ?>" <?= $r['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td>
                    <div class="aksi-wrap">
                        <a href="<?= BASE_URL ?>/exports/export_pdf.php?rec=<?= urlencode($r['nomor_penerimaan']) ?>&cetak=1"
                           target="_blank" class="btn btn-red btn-sm" style="font-size:.68rem;padding:3px 8px" title="Export PDF Laporan">
                            &#128196; PDF
                        </a>
                        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_penerimaan.php" style="display:inline" onsubmit="return confirm('⚠️ Yakin ingin menghapus batch penerimaan <?= bersihkan($r['nomor_penerimaan']) ?> (<?= bersihkan($r['klien']) ?>)?\n\nSeluruh data sampel, hasil uji, dan relasi terkait batch ini akan ikut terhapus secara permanen.');">
                            <input type="hidden" name="action" value="hapus"/>
                            <input type="hidden" name="id" value="<?= $r['id'] ?>"/>
                            <button type="submit" class="btn btn-sm" style="font-size:.68rem;padding:3px 8px;background:#7f1d1d;color:#fca5a5;border:1px solid #b91c1c;cursor:pointer" title="Hapus Batch Penerimaan">
                                🗑️ Hapus
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <!-- Detail sampel dalam batch -->
            <?php
            $sbList = $pdo->prepare(
                "SELECT s.kode_sampel, s.jenis_material, s.berat_gram, s.metode_uji, s.status
                 FROM sampel s WHERE s.penerimaan_id = ? ORDER BY s.kode_sampel"
            );
            $sbList->execute([$r['id']]);
            $sbs = $sbList->fetchAll();
            foreach ($sbs as $sb): ?>
            <tr class="detail-row">
                <td colspan="8">
                    <div class="detail-wrap">
                        <div class="detail-code">&#9492; <?= bersihkan($sb['kode_sampel']) ?></div>
                        <div><?= bersihkan($sb['jenis_material']) ?></div>
                        <div><?= $sb['berat_gram'] ? number_format($sb['berat_gram'],2).' g' : '-' ?></div>
                        <div><?= bersihkan($sb['metode_uji']) ?></div>
                        <div><?= badgeStatus($sb['status']) ?></div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (!$recList): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:20px">Tidak ada data.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    
    <!-- Tampilkan daftar submission yang siap diproses -->
    <?php if (!empty($submissionsList)): ?>
    <div class="card" style="margin-top:16px">
        <div class="card-title">📋 Submission Klien Siap Diproses</div>
        <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>No. Submission</th>
                    <th>Klien</th>
                    <th>Email</th>
                    <th>Tgl Submit</th>
                    <th>Sampel</th>
                    <th>Aksi</th>
                 </tr>
            </thead>
            <tbody>
            <?php foreach ($submissionsList as $sub): ?>
            <tr>
                <td style="font-family:monospace"><?= bersihkan($sub['nomor_submission']) ?></td>
                <td><?= bersihkan($sub['klien']) ?></td>
                <td><?= bersihkan($sub['email']) ?></td>
                <td><?= fmtTgl($sub['tanggal_submit']) ?></td>
                <td style="text-align:center"><?= $sub['jumlah_sampel'] ?></td>
                <td>
                    <a href="<?= BASE_URL ?>/pages/penerimaan.php?process_submission=<?= $sub['id'] ?>" 
                       class="btn btn-gold btn-sm" style="font-size:.68rem;padding:3px 8px"
                       onclick="return confirm('Proses submission ini ke penerimaan? Data akan langsung masuk ke database.')">
                        📦 Proses ke Penerimaan
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════
     TAB 2 — PENERIMAAN BARU (BATCH)
══════════════════════════════════════════════════ -->
<div id="tab-batch" class="tab-pane <?= $tab==='batch'?'active':'' ?>">
    
    <?php if ($fromSubmission && $submissionData): ?>
    <!-- Informasi Submission yang diproses -->
    <div class="submission-info-card">
        <div class="title">📋 Data Submission: <?= bersihkan($submissionData['nomor_submission']) ?></div>
        <div class="details">
            <div><span>Klien:</span> <strong><?= bersihkan($submissionData['klien']) ?></strong></div>
            <div><span>Kontak Person:</span> <strong><?= bersihkan($submissionData['kontak_person'] ?? '-') ?></strong></div>
            <div><span>Email:</span> <strong><?= bersihkan($submissionData['email'] ?? '-') ?></strong></div>
            <div><span>Telepon:</span> <strong><?= bersihkan($submissionData['telepon'] ?? '-') ?></strong></div>
            <div><span>Tanggal Submit:</span> <strong><?= fmtTgl($submissionData['tanggal_submit']) ?></strong></div>
            <?php if ($submissionData['po_referensi']): ?>
            <div><span>PO/Referensi:</span> <strong><?= bersihkan($submissionData['po_referensi']) ?></strong></div>
            <?php endif; ?>
        </div>
        <?php if ($submissionData['instruksi_khusus']): ?>
        <div style="margin-top:8px; font-size:.75rem">
            <span style="color:var(--text3)">Instruksi Khusus:</span> 
            <strong><?= nl2br(bersihkan($submissionData['instruksi_khusus'])) ?></strong>
        </div>
        <?php endif; ?>
        <div style="margin-top:8px; font-size:.75rem; color:var(--green)">
            📦 <?= count($submissionSampel) ?> sampel akan diproses
        </div>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-title">&#10133; Penerimaan Sampel Baru</div>
        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_penerimaan.php" id="formPenerimaan">
            <input type="hidden" name="from_submission" value="<?= $fromSubmission ?>">
            <div class="form-row">
                <div class="form-group"><label>No. Penerimaan</label><input name="nomor_penerimaan" value="<?= $noAuto ?>" readonly/></div>
                <div class="form-group"><label>Tanggal Terima</label><input type="date" name="tanggal_terima" value="<?= date('Y-m-d') ?>" required/></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Klien / Perusahaan</label>
                    <input name="klien" id="inputKlien" placeholder="Nama perusahaan" required 
                           value="<?= isset($prefillKlien) ? bersihkan($prefillKlien) : '' ?>" 
                           list="klienSuggest"/>
                    <datalist id="klienSuggest">
                        <?php $klienAll = $pdo->query("SELECT DISTINCT klien FROM sampel WHERE klien IS NOT NULL ORDER BY klien")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($klienAll as $k): ?><option value="<?= bersihkan($k) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>Keterangan Batch</label>
                    <textarea name="keterangan" rows="1" placeholder="Catatan penerimaan..."><?= isset($prefillCatatan) ? bersihkan($prefillCatatan) : '' ?></textarea>
                </div>
            </div>
            
            <div style="border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <span style="font-size:.8rem;font-weight:600;color:var(--gold)">&#129704; Daftar Sampel dalam Batch</span>
                    <button type="button" class="btn btn-green btn-sm" onclick="tambahBarisBatch()">&#10133; Tambah Sampel</button>
                </div>
                <div id="batchRows"></div>
                <div style="font-size:.72rem;color:var(--text3);margin-top:6px">Total: <strong id="totalBatch" style="color:var(--gold)">0</strong> sampel</div>
            </div>

            <button type="submit" class="btn btn-gold">&#128190; Simpan Penerimaan &amp; Semua Sampel</button>
        </form>
    </div>
</div>

<script>
// ── TAB SWITCH ────────────────────────────────────────────────
function switchTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    if (el) el.classList.add('active');
    history.replaceState(null,'','?tab='+name);
}

// ── BATCH FORM ────────────────────────────────────────────────
const matOpts = <?= json_encode($materialOpts) ?>;
const metOpts = <?= json_encode($metodeOpts) ?>;
let batchRowCnt = 0;

function tambahBarisBatch(jenisMaterial = '', beratGram = '', metodeUji = '', keterangan = '') {
    batchRowCnt++;
    const i = batchRowCnt;
    const mO = matOpts.map(m => `<option ${m === jenisMaterial ? 'selected' : ''}>${m}</option>`).join('');
    const eO = metOpts.map(m => `<option ${m === metodeUji ? 'selected' : ''}>${m}</option>`).join('');
    const html = `
    <div id="br${i}" style="display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr auto;gap:8px;margin-bottom:8px;align-items:end">
        <div class="form-group">
            <label style="font-size:.7rem;color:var(--text3)">Material #${i}</label>
            <select name="sampel[${i}][jenis_material]">${mO}</select>
        </div>
        <div class="form-group">
            <label style="font-size:.7rem;color:var(--text3)">Berat (g)</label>
            <input type="number" step="0.001" name="sampel[${i}][berat_gram]" value="${beratGram}" placeholder="0.000"/>
        </div>
        <div class="form-group">
            <label style="font-size:.7rem;color:var(--text3)">Metode</label>
            <select name="sampel[${i}][metode_uji]">${eO}</select>
        </div>
        <div class="form-group">
            <label style="font-size:.7rem;color:var(--text3)">Keterangan</label>
            <input type="text" name="sampel[${i}][keterangan]" value="${keterangan}" placeholder="Opsional"/>
        </div>
        <div><label style="display:block;margin-bottom:5px">&nbsp;</label>
            <button type="button" onclick="hapusBarisBatch(${i})"
                    style="background:var(--red);color:#fff;border:none;border-radius:5px;padding:6px 10px;cursor:pointer;font-size:.75rem">&#10005;</button>
        </div>
    </div>`;
    document.getElementById('batchRows').insertAdjacentHTML('beforeend', html);
    updateBatchCount();
}

function hapusBarisBatch(i) {
    document.getElementById('br' + i)?.remove();
    updateBatchCount();
}

function updateBatchCount() {
    document.getElementById('totalBatch').textContent =
        document.querySelectorAll('#batchRows > div').length;
}

// Inisialisasi: tambah baris batch (dengan data dari submission jika ada)
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($submissionSampel): ?>
    <?php foreach ($submissionSampel as $idx => $s): ?>
    tambahBarisBatch(
        '<?= addslashes($s['jenis_material']) ?>',
        '<?= $s['berat_gram'] ?>',
        '<?= addslashes($s['metode_uji']) ?>',
        '<?= addslashes($s['keterangan']) ?>'
    );
    <?php endforeach; ?>
    <?php else: ?>
    tambahBarisBatch();
    <?php endif; ?>
});

</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
