<?php
// ============================================================
//  client_monitoring.php — Portal monitoring sampel untuk client
//  UPDATE: Real-time Multi-Role Stage Tracking & Stepper
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

if (!isClient()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Monitoring Sampel';
$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$userId = (int)($_SESSION['user_id'] ?? 0);
$accessList = [];

if (clientAccessTableReady($pdo)) {
    $stmt = $pdo->prepare("
        SELECT ca.*,
               sub.nomor_submission, sub.status AS submission_status,
               sub.tanggal_submit, sub.kontak_person, sub.po_referensi,
               p.nomor_penerimaan, p.tanggal_terima, p.status AS penerimaan_status,
               p.jumlah_sampel, p.jenis_material, p.metode_uji, p.keterangan AS penerimaan_keterangan
        FROM client_access ca
        LEFT JOIN submission_sampel sub ON sub.id = ca.submission_id
        LEFT JOIN penerimaan_sampel p ON p.id = ca.penerimaan_id
        WHERE ca.pengguna_id = ?
        ORDER BY ca.created_at DESC
    ");
    $stmt->execute([$userId]);
    $accessList = $stmt->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.mon-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:24px;box-shadow:0 4px 20px rgba(0,0,0,0.25)}
.mon-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;gap:12px;flex-wrap:wrap}
.mon-title{font-size:1.15rem;font-weight:700;color:var(--gold)}
.mon-meta{font-size:.82rem;color:var(--text3);margin-top:2px}

/* Live Operational Banner */
.live-status-box{
    background: linear-gradient(135deg, rgba(15,23,42,0.9), rgba(30,41,59,0.8));
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.live-role-badge{
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: .74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.role-client { background: rgba(56,189,248,0.2); color: #38bdf8; border: 1px solid #0284c7; }
.role-admin  { background: rgba(245,158,11,0.2); color: #fbbf24; border: 1px solid #d97706; }
.role-prep   { background: rgba(168,85,247,0.2); color: #c084fc; border: 1px solid #9333ea; }
.role-analis { background: rgba(59,130,246,0.2); color: #60a5fa; border: 1px solid #2563eb; }
.role-spv    { background: rgba(236,72,153,0.2); color: #f472b6; border: 1px solid #db2777; }
.role-done   { background: rgba(52,211,153,0.2); color: #34d399; border: 1px solid #059669; }

/* Stepper / Timeline */
.stepper{display:flex;justify-content:space-between;position:relative;margin:22px 0 16px;padding-bottom:10px}
.stepper::before{content:"";position:absolute;top:16px;left:0;right:0;height:3px;background:var(--border);z-index:1}
.step{position:relative;z-index:2;text-align:center;flex:1}
.step-icon{
    width:34px;height:34px;background:var(--bg3);border:2px solid var(--border);border-radius:50%;
    display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:.82rem;color:var(--text3);
    font-weight:700;transition:.3s
}
.step.active .step-icon{background:var(--gold);border-color:var(--gold);color:#000;box-shadow:0 0 14px rgba(255,215,0,.45);animation:pulse-gold 2s infinite}
.step.done .step-icon{background:var(--green);border-color:var(--green);color:#fff}
.step.skipped .step-icon{background:#1e293b;border-color:#475569;color:#64748b}
.step-label{font-size:.73rem;font-weight:600;color:var(--text3)}
.step-role{font-size:.65rem;color:var(--text3);opacity:.8;margin-top:2px}
.step.active .step-label{color:var(--gold);font-weight:700}
.step.done .step-label{color:var(--text)}

@keyframes pulse-gold {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0.5); }
    50% { box-shadow: 0 0 0 8px rgba(245,158,11,0); }
}

/* Progress bar */
.progress-track{width:100%;height:6px;background:var(--bg3);border-radius:3px;overflow:hidden;margin-top:10px}
.progress-fill{height:100%;background:linear-gradient(90deg, #f59e0b, #10b981);border-radius:3px;transition:width .5s ease}

.mon-stats{display:flex;gap:16px;margin:16px 0;font-size:.78rem;background:var(--bg3);padding:10px 16px;border-radius:8px;flex-wrap:wrap;border:1px solid var(--border)}
.mon-stat-item{display:flex;align-items:center;gap:6px}
.mon-stat-val{font-weight:700;color:var(--gold)}

.invoice-box{background:#0d2318;border:1px solid var(--green3);border-radius:10px;padding:14px 18px;margin-top:18px;display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}
.invoice-box .total{color:var(--gold);font-size:1.15rem;font-weight:800}
.muted-note{color:var(--text3);font-size:.76rem;line-height:1.5}
</style>

<div class="sec-title">📊 Monitoring Progress Sampel Real-time</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR')?'alert-red':'alert-green' ?>" style="margin-bottom:16px">
        <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<?php if (!clientAccessTableReady($pdo)): ?>
    <div class="alert-box alert-red">Tabel client_access belum tersedia. Admin perlu menjalankan skrip inisialisasi database.</div>
<?php elseif (empty($accessList)): ?>
    <div class="card" style="text-align:center;padding:40px;color:var(--text3)">
        <div style="font-size:2rem;margin-bottom:8px">📭</div>
        <div style="font-size:1rem;font-weight:600;color:var(--text)">Belum Ada Sampel Aktif</div>
        <p style="font-size:.82rem;margin-top:4px">Belum ada pengajuan atau penerimaan sampel yang terhubung ke akun perusahaan ini.</p>
        <a href="<?= BASE_URL ?>/pages/ssf.php" class="btn btn-gold btn-sm" style="margin-top:12px">📝 Ajukan Sampel Baru (SSF)</a>
    </div>
<?php endif; ?>

<?php foreach ($accessList as $access): ?>
    <?php
    $penerimaanId = (int)($access['penerimaan_id'] ?? 0);
    $submissionId = (int)($access['submission_id'] ?? 0);
    $samples = [];
    $results = [];
    $invoice = null;
    $totalSamples = 0;
    $hasWo = 0;
    $butuhPrep = 1;
    $countPrep = 0;
    $countUji = 0;
    $countQcLulus = 0;
    $countQcPending = 0;
    $countSelesai = 0;
    $allDone = false;

    if ($penerimaanId > 0) {
        // 1. Ambil daftar sampel fisik aktual
        $stSamples = $pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM preparasi_sampel WHERE sampel_id = s.id) AS has_prep,
                   (SELECT COUNT(*) FROM hasil_uji WHERE sampel_id = s.id) AS has_uji,
                   (SELECT COUNT(*) FROM hasil_uji WHERE sampel_id = s.id AND kesimpulan = 'lulus') AS has_lulus,
                   (SELECT COUNT(*) FROM hasil_uji WHERE sampel_id = s.id AND kesimpulan = 'pending') AS has_pending
            FROM sampel s
            WHERE s.penerimaan_id = ?
            ORDER BY s.kode_sampel
        ");
        $stSamples->execute([$penerimaanId]);
        $samples = $stSamples->fetchAll(PDO::FETCH_ASSOC);
        $totalSamples = count($samples);

        // 2. Cek Work Order
        $stWo = $pdo->prepare("SELECT COUNT(*) FROM work_order WHERE penerimaan_id = ? AND status IN ('aktif','selesai')");
        $stWo->execute([$penerimaanId]);
        $hasWo = (int)$stWo->fetchColumn();

        $stWoInfo = $pdo->prepare("SELECT butuh_preparasi, nomor_wo, prioritas, status FROM work_order WHERE penerimaan_id = ? ORDER BY id DESC LIMIT 1");
        $stWoInfo->execute([$penerimaanId]);
        $woInfo = $stWoInfo->fetch(PDO::FETCH_ASSOC);
        if ($woInfo && $woInfo['butuh_preparasi'] !== null) {
            $butuhPrep = (int)$woInfo['butuh_preparasi'];
        }

        // 3. Hitung progress agregat
        $stPrep = $pdo->prepare("
            SELECT COUNT(DISTINCT pr.sampel_id)
            FROM preparasi_sampel pr
            JOIN sampel s ON pr.sampel_id = s.id
            WHERE s.penerimaan_id = ?
        ");
        $stPrep->execute([$penerimaanId]);
        $countPrep = (int)$stPrep->fetchColumn();

        $stUji = $pdo->prepare("
            SELECT COUNT(DISTINCT h.sampel_id)
            FROM hasil_uji h
            JOIN sampel s ON h.sampel_id = s.id
            WHERE s.penerimaan_id = ?
        ");
        $stUji->execute([$penerimaanId]);
        $countUji = (int)$stUji->fetchColumn();

        $stQcLulus = $pdo->prepare("
            SELECT COUNT(DISTINCT h.sampel_id)
            FROM hasil_uji h
            JOIN sampel s ON h.sampel_id = s.id
            WHERE s.penerimaan_id = ? AND h.kesimpulan = 'lulus'
        ");
        $stQcLulus->execute([$penerimaanId]);
        $countQcLulus = (int)$stQcLulus->fetchColumn();

        $countSelesai = count(array_filter($samples, fn($s) => $s['status'] === 'selesai' || $s['has_lulus'] > 0));
        $allDone = ($totalSamples > 0 && $countSelesai >= $totalSamples);

        // 4. Ambil hasil uji yang sudah divalidasi atau tersedia
        $stResults = $pdo->prepare("
            SELECT h.*, s.kode_sampel
            FROM hasil_uji h
            JOIN sampel s ON s.id = h.sampel_id
            WHERE s.penerimaan_id = ?
            ORDER BY s.kode_sampel, h.parameter
        ");
        $stResults->execute([$penerimaanId]);
        $results = $stResults->fetchAll(PDO::FETCH_ASSOC);

        // 5. Cek Invoice
        $stInv = $pdo->prepare("
            SELECT *
            FROM invoice
            WHERE penerimaan_id = ?
              AND status IN ('diterbitkan','lunas')
            ORDER BY FIELD(status,'diterbitkan','lunas'), created_at DESC
            LIMIT 1
        ");
        $stInv->execute([$penerimaanId]);
        $invoice = $stInv->fetch(PDO::FETCH_ASSOC);

    } elseif ($submissionId > 0 && tableExists($pdo, 'submission_sampel_detail')) {
        // Belum diterima fisik oleh admin (hanya data submission)
        $stSubSamples = $pdo->prepare("
            SELECT jenis_material, berat_gram, metode_uji, parameter, keterangan
            FROM submission_sampel_detail
            WHERE submission_id = ?
            ORDER BY id
        ");
        $stSubSamples->execute([$submissionId]);
        $samples = $stSubSamples->fetchAll(PDO::FETCH_ASSOC);
        $totalSamples = count($samples);
    }

    // ── PERHITUNGAN STATUS REAL-TIME 6 TAHAP & ROLE PENANGGUNG JAWAB ──
    $s1 = 'done'; // Step 1: Pengajuan (Klien) selalu done
    
    // Step 2: Penerimaan Fisik (Admin Lab)
    $s2 = ($penerimaanId > 0) ? 'done' : 'active';

    // Step 3: Work Order & Preparasi (Admin / Analis Preparasi)
    if ($penerimaanId === 0) {
        $s3 = 'pending';
    } elseif ($hasWo === 0) {
        $s3 = 'active'; // Menunggu WO dibuat admin/spv
    } elseif ($butuhPrep === 0) {
        $s3 = 'skipped'; // Langsung ke pengujian tanpa preparasi
    } elseif ($totalSamples > 0 && $countPrep >= $totalSamples) {
        $s3 = 'done';
    } else {
        $s3 = 'active'; // Sedang preparasi
    }

    // Step 4: Pengujian Laboratorium (Analis Kimia / XRF)
    if ($s3 !== 'done' && $s3 !== 'skipped') {
        $s4 = 'pending';
    } elseif ($totalSamples > 0 && $countUji >= $totalSamples) {
        $s4 = 'done';
    } else {
        $s4 = 'active'; // Sedang diuji
    }

    // Step 5: QC & Validasi Mutu (Supervisor)
    if ($s4 !== 'done') {
        $s5 = 'pending';
    } elseif ($totalSamples > 0 && $countQcLulus >= $totalSamples) {
        $s5 = 'done';
    } else {
        $s5 = 'active'; // Menunggu validasi QC
    }

    // Step 6: Selesai & CoA / Invoice (Admin & Klien)
    $s6 = ($s5 === 'done') ? 'done' : 'pending';

    // Perhitungan Persentase Progres Real-time
    $progressPct = 10; // Submission
    if ($penerimaanId > 0) $progressPct += 20; // Reception
    if ($hasWo > 0) $progressPct += 15; // WO
    if ($butuhPrep === 0 || ($totalSamples > 0 && $countPrep >= $totalSamples)) {
        $progressPct += 20; // Prep
    } elseif ($totalSamples > 0 && $countPrep > 0) {
        $progressPct += round(($countPrep / $totalSamples) * 20);
    }
    if ($totalSamples > 0 && $countUji >= $totalSamples) {
        $progressPct += 20; // Uji
    } elseif ($totalSamples > 0 && $countUji > 0) {
        $progressPct += round(($countUji / $totalSamples) * 20);
    }
    if ($totalSamples > 0 && $countQcLulus >= $totalSamples) {
        $progressPct += 15; // QC & Selesai
    }
    $progressPct = min(100, max(10, $progressPct));

    // Narasi Status & Penanggung Jawab Terkini
    $activeRoleBadge = '<span class="live-role-badge role-client">👤 Klien</span>';
    $activeStatusTitle = 'Pengajuan Terkirim';
    $activeStatusDesc = 'Formulir pengajuan telah diterima sistem. Menunggu fisik sampel dikirimkan / tiba di laboratorium.';

    if ($penerimaanId === 0) {
        $activeRoleBadge = '<span class="live-role-badge role-admin">📥 Admin Lab</span>';
        $activeStatusTitle = 'Menunggu Penerimaan Fisik Sampel';
        $activeStatusDesc = 'Pengajuan online tercatat. Petugas Admin Laboratorium sedang menunggu kedatangan fisik sampel untuk verifikasi dan registrasi.';
    } elseif ($hasWo === 0) {
        $activeRoleBadge = '<span class="live-role-badge role-admin">⚙️ Admin / Supervisor</span>';
        $activeStatusTitle = 'Sampel Diterima — Menunggu Penerbitan Work Order';
        $activeStatusDesc = 'Fisik sampel telah diterima dan teregistrasi di laboratorium (' . bersihkan($access['nomor_penerimaan']) . '). Saat ini menunggu penerbitan Perintah Kerja (Work Order) dan penugasan analis.';
    } elseif ($butuhPrep === 1 && ($totalSamples === 0 || $countPrep < $totalSamples)) {
        $activeRoleBadge = '<span class="live-role-badge role-prep">🧪 Analis Preparasi</span>';
        $activeStatusTitle = 'Sedang Dalam Proses Preparasi Fisik';
        $activeStatusDesc = "Work Order telah terbit. Analis sedang melakukan preparasi sampel (pengeringan, penghancuran & penggilingan halus). Selesai dipreparasi: $countPrep dari $totalSamples sampel.";
    } elseif ($totalSamples === 0 || $countUji < $totalSamples) {
        $activeRoleBadge = '<span class="live-role-badge role-analis">🔬 Analis Pengujian (XRF / Kimia)</span>';
        $activeStatusTitle = 'Sedang Dalam Pengujian Laboratorium';
        $activeStatusDesc = "Sampel siap uji. Analis laboratorium sedang melakukan pengujian instrumental (XRF/AAS/ICP/Kimia). Selesai diuji: $countUji dari $totalSamples sampel.";
    } elseif ($totalSamples === 0 || $countQcLulus < $totalSamples) {
        $activeRoleBadge = '<span class="live-role-badge role-spv">🔍 Supervisor Lab</span>';
        $activeStatusTitle = 'Menunggu Validasi Mutu & QC';
        $activeStatusDesc = "Semua pengujian sampel telah selesai diinput. Saat ini sedang dalam tahap pemeriksaan kendali mutu (QC) dan validasi kelulusan oleh Supervisor Laboratorium.";
    } else {
        $activeRoleBadge = '<span class="live-role-badge role-done">✅ Selesai (Lulus Validasi)</span>';
        $activeStatusTitle = 'Seluruh Pengujian Selesai & Valid';
        $activeStatusDesc = "Semua sampel telah lulus validasi mutu. Laporan Sertifikat Hasil Uji (Certificate of Analysis / CoA) siap diunduh dan tagihan invoice telah diterbitkan.";
    }

    $mainCode = $access['nomor_penerimaan'] ?: ($access['nomor_submission'] ?: $access['kode_akses']);
    ?>

    <div class="mon-card">
        <!-- Header Pesanan -->
        <div class="mon-header">
            <div>
                <div class="mon-title"><?= bersihkan($mainCode) ?> &mdash; <?= bersihkan($access['klien']) ?></div>
                <div class="mon-meta">
                    <?php if ($access['nomor_submission']): ?>
                        No. Submission: <strong><?= bersihkan($access['nomor_submission']) ?></strong> &bull;
                    <?php endif; ?>
                    Tanggal: <?= fmtTgl($access['tanggal_terima'] ?: $access['tanggal_submit']) ?> &bull;
                    Total: <strong><?= $totalSamples ?> Sampel</strong>
                </div>
            </div>
            <div>
                <?php if ($penerimaanId > 0): ?>
                    <?= badgeStatus($allDone ? 'selesai' : $access['penerimaan_status']) ?>
                <?php else: ?>
                    <?= badgeStatus($access['submission_status'] ?? 'pending') ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Banner Status Operasional Real-time & Role Penanggung Jawab -->
        <div class="live-status-box">
            <div style="flex:1;min-width:240px">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                    <span style="font-size:.75rem;color:var(--text3);text-transform:uppercase;font-weight:600">Tahap Saat Ini:</span>
                    <?= $activeRoleBadge ?>
                </div>
                <div style="font-size:.95rem;font-weight:700;color:var(--gold)"><?= $activeStatusTitle ?></div>
                <div style="font-size:.78rem;color:var(--text2);margin-top:3px"><?= $activeStatusDesc ?></div>
            </div>
            <div style="text-align:right;min-width:130px">
                <div style="font-size:.72rem;color:var(--text3);text-transform:uppercase;font-weight:600">Total Progress</div>
                <div style="font-size:1.6rem;font-weight:800;color:var(--gold)"><?= $progressPct ?>%</div>
            </div>
            <div class="progress-track">
                <div class="progress-fill" style="width: <?= $progressPct ?>%"></div>
            </div>
        </div>

        <!-- Stepper Timeline Real-time 6 Tahap -->
        <div class="stepper">
            <div class="step <?= $s1 ?>">
                <div class="step-icon">1</div>
                <div class="step-label">Pengajuan</div>
                <div class="step-role">[Klien]</div>
            </div>
            <div class="step <?= $s2 ?>">
                <div class="step-icon">2</div>
                <div class="step-label">Penerimaan</div>
                <div class="step-role">[Admin]</div>
            </div>
            <div class="step <?= $s3 ?>">
                <div class="step-icon"><?= $s3 === 'skipped' ? '—' : '3' ?></div>
                <div class="step-label">Work Order &amp; Prep <?= $s3 === 'skipped' ? '<small>(N/A)</small>' : '' ?></div>
                <div class="step-role">[Admin &amp; Analis]</div>
            </div>
            <div class="step <?= $s4 ?>">
                <div class="step-icon">4</div>
                <div class="step-label">Pengujian Lab</div>
                <div class="step-role">[Analis Kimia/XRF]</div>
            </div>
            <div class="step <?= $s5 ?>">
                <div class="step-icon">5</div>
                <div class="step-label">QC &amp; Validasi</div>
                <div class="step-role">[Supervisor]</div>
            </div>
            <div class="step <?= $s6 ?>">
                <div class="step-icon">6</div>
                <div class="step-label">Selesai</div>
                <div class="step-role">[Sertifikat &amp; Inv]</div>
            </div>
        </div>

        <!-- KPI Ringkasan Metrik -->
        <div class="mon-stats">
            <div class="mon-stat-item">📦 Penerimaan: <span class="mon-stat-val"><?= $penerimaanId ? 'Diterima di Lab' : 'Menunggu Fisik' ?></span></div>
            <div class="mon-stat-item">🧪 Preparasi: <span class="mon-stat-val"><?= $butuhPrep == 0 ? 'Tidak Perlu' : "$countPrep/$totalSamples" ?></span></div>
            <div class="mon-stat-item">🔬 Pengujian: <span class="mon-stat-val"><?= "$countUji/$totalSamples" ?></span></div>
            <div class="mon-stat-item">🛡️ Validasi QC: <span class="mon-stat-val"><?= "$countQcLulus/$totalSamples" ?></span></div>
            <div class="mon-stat-item">🏁 Status Akhir: <span class="mon-stat-val"><?= $allDone ? 'Selesai' : 'Sedang Diproses' ?></span></div>
        </div>

        <!-- Tabel Detail Sampel & Status Per Item -->
        <div style="overflow-x:auto;margin-top:10px">
            <table class="data-table" style="font-size:.78rem">
                <thead>
                    <tr>
                        <th style="width:130px">Kode Sampel</th>
                        <th>Jenis Material</th>
                        <th style="width:110px">Berat</th>
                        <th style="width:110px">Metode Uji</th>
                        <th style="width:140px">Status Pelacakan</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($samples as $i => $sample): 
                        // Tentukan status spesifik per item sampel
                        $sampleBadge = '';
                        if ($penerimaanId === 0) {
                            $sampleBadge = '<span style="color:var(--yellow);font-weight:600">⏳ Menunggu Fisik</span>';
                        } elseif (!empty($sample['has_lulus']) && $sample['has_lulus'] > 0) {
                            $sampleBadge = '<span style="color:var(--green);font-weight:700">✅ Lulus QC (Selesai)</span>';
                        } elseif (!empty($sample['has_uji']) && $sample['has_uji'] > 0) {
                            $sampleBadge = '<span style="color:#60a5fa;font-weight:600">🔬 Diuji (Review QC)</span>';
                        } elseif (!empty($sample['has_prep']) && $sample['has_prep'] > 0) {
                            $sampleBadge = '<span style="color:#c084fc;font-weight:600">📦 Preparasi Selesai</span>';
                        } elseif ($hasWo > 0) {
                            $sampleBadge = '<span style="color:#fbbf24;font-weight:600">📋 Dalam Antrian WO</span>';
                        } else {
                            $sampleBadge = '<span style="color:var(--text2)">📥 Diterima di Lab</span>';
                        }
                    ?>
                    <tr>
                        <td style="font-weight:700;color:var(--gold);font-family:monospace">
                            <?= bersihkan($sample['kode_sampel'] ?? ('Submission #' . ($i + 1))) ?>
                        </td>
                        <td><?= bersihkan($sample['jenis_material'] ?? '-') ?></td>
                        <td><?= !empty($sample['berat_gram']) ? number_format((float)$sample['berat_gram'], 3, ',', '.') . ' g' : '-' ?></td>
                        <td><span style="background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border)"><?= bersihkan($sample['metode_uji'] ?? '-') ?></span></td>
                        <td><?= $sampleBadge ?></td>
                        <td style="font-size:.74rem;color:var(--text3)"><?= bersihkan($sample['keterangan'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($samples)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text3);padding:18px">Belum ada rincian sampel.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Tabel Hasil Uji (Jika Sudah Selesai / Tervalidasi) -->
        <?php if (!empty($results)): ?>
            <div class="card-title" style="margin-top:20px;font-size:.88rem">🔬 Hasil Uji Laboratorium <span><?= count($results) ?> parameter teruji</span></div>
            <div style="overflow-x:auto">
                <table class="data-table" style="font-size:.78rem">
                    <thead>
                        <tr>
                            <th>Kode Sampel</th>
                            <th>Parameter Unsur</th>
                            <th>Hasil / Konsentrasi</th>
                            <th>Satuan</th>
                            <th>Metode Uji</th>
                            <th>Tgl Uji</th>
                            <th>Status Validasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                        <tr>
                            <td style="font-weight:700;color:var(--gold);font-family:monospace"><?= bersihkan($result['kode_sampel']) ?></td>
                            <td><strong><?= bersihkan($result['parameter']) ?></strong></td>
                            <td><strong style="color:#6ee7b7;font-size:.84rem"><?= bersihkan($result['nilai']) ?></strong></td>
                            <td><?= bersihkan($result['satuan']) ?></td>
                            <td><?= bersihkan($result['metode']) ?></td>
                            <td style="color:var(--text3);font-size:.73rem"><?= $result['tanggal_uji'] ? date('d/m/Y', strtotime($result['tanggal_uji'])) : '—' ?></td>
                            <td><?= badgeStatus($result['kesimpulan']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Box Tindakan Akhir: Unduh CoA & Invoice -->
        <?php if ($allDone): ?>
            <div class="invoice-box">
                <div>
                    <div style="font-weight:800;color:var(--green);font-size:.95rem">✅ Pengujian Selesai &amp; Lulus Validasi</div>
                    <div class="muted-note">Sertifikat Hasil Uji (Certificate of Analysis / CoA) resmi telah diterbitkan.</div>
                    <?php if ($invoice): ?>
                        <div style="font-size:.75rem;color:var(--text3);margin-top:4px">Invoice: <strong><?= bersihkan($invoice['nomor_invoice']) ?></strong> &bull; Status: <?= badgeStatus($invoice['status']) ?></div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <?php if ($invoice): ?>
                        <div class="total">Rp <?= number_format((float)$invoice['total'], 0, ',', '.') ?></div>
                        <a href="<?= BASE_URL ?>/exports/cetak_invoice.php?id=<?= $invoice['id'] ?>" target="_blank" class="btn btn-green btn-sm">&#128196; Cetak Invoice</a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/exports/export_pdf.php" target="_blank" class="btn btn-gold btn-sm">&#128196; Unduh Laporan / CoA (PDF)</a>
                </div>
            </div>
        <?php elseif ($countUji > 0): ?>
            <div class="muted-note" style="margin-top:14px;background:var(--bg3);padding:10px 14px;border-radius:6px;border:1px solid var(--border)">
                💡 <strong>Catatan:</strong> Pengujian laboratorium sedang berlangsung / menunggu validasi mutu supervisor. Dokumen Sertifikat CoA dan Invoice akan otomatis dapat diunduh di sini setelah seluruh sampel selesai dan lulus QC.
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
