<?php
// ============================================================
//  monitoring.php — Monitoring Alur & Status Sampel Real-time
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
/** @var PDO $pdo */
cekLogin();

$pageTitle = 'Monitoring Sampel';

// ── Ambil Data Monitoring Batch ──────────────────────────────
$sql = "
    SELECT 
        rec.id AS batch_id,
        rec.nomor_penerimaan,
        rec.klien,
        rec.tanggal_terima,
        rec.jumlah_sampel,
        rec.is_confirmed,
        -- Status Work Order
        (SELECT COUNT(*) FROM work_order WHERE penerimaan_id = rec.id) AS has_wo,
        (SELECT butuh_preparasi FROM work_order WHERE penerimaan_id = rec.id ORDER BY id DESC LIMIT 1) AS batch_butuh_prep,
        -- Status Preparasi
        (SELECT COUNT(DISTINCT pr.sampel_id) 
         FROM preparasi_sampel pr 
         JOIN sampel s ON pr.sampel_id = s.id 
         WHERE s.penerimaan_id = rec.id) AS count_prep,
        -- Status Pengujian
        (SELECT COUNT(DISTINCT h.sampel_id) 
         FROM hasil_uji h 
         JOIN sampel s ON h.sampel_id = s.id 
         WHERE s.penerimaan_id = rec.id) AS count_uji,
        -- Status QC
        (SELECT COUNT(DISTINCT q.sampel_id) 
         FROM qc_sampel q 
         JOIN sampel s ON q.sampel_id = s.id 
         WHERE s.penerimaan_id = rec.id) AS count_qc,
        -- Status Invoice
        (SELECT COUNT(*) FROM invoice WHERE penerimaan_id = rec.id) AS has_invoice,
        -- Global Sample Status
        (SELECT COUNT(*) FROM sampel WHERE penerimaan_id = rec.id AND status = 'selesai') AS count_selesai
    FROM penerimaan_sampel rec
    ORDER BY rec.created_at DESC
";
$batches = $pdo->query($sql)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.mon-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}
.mon-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.mon-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--gold);
}
.mon-meta {
    font-size: 0.85rem;
    color: var(--text3);
}

/* Stepper / Timeline */
.stepper {
    display: flex;
    justify-content: space-between;
    position: relative;
    margin-top: 20px;
    padding-bottom: 10px;
}
.stepper::before {
    content: "";
    position: absolute;
    top: 15px;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--border);
    z-index: 1;
}
.step {
    position: relative;
    z-index: 2;
    text-align: center;
    flex: 1;
}
.step-icon {
    width: 32px;
    height: 32px;
    background: var(--bg3);
    border: 2px solid var(--border);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 8px;
    font-size: 0.8rem;
    color: var(--text3);
    transition: 0.3s;
}
.step.active .step-icon {
    background: var(--gold);
    border-color: var(--gold);
    color: #000;
    box-shadow: 0 0 10px rgba(255, 215, 0, 0.3);
}
.step.done .step-icon {
    background: var(--green);
    border-color: var(--green);
    color: #fff;
}
.step.skipped .step-icon {
    background: var(--bg-base);
    border-color: var(--text-dim);
    color: var(--text-dim);
    opacity: 0.6;
}
.step-label {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text3);
}
.step.active .step-label, .step.done .step-label {
    color: var(--text);
}

.mon-stats {
    display: flex;
    gap: 20px;
    margin-top: 15px;
    font-size: 0.78rem;
    background: var(--bg3);
    padding: 10px 15px;
    border-radius: 8px;
}
.mon-stat-item {
    display: flex;
    align-items: center;
    gap: 6px;
}
.mon-stat-val {
    font-weight: 700;
    color: var(--gold);
}
</style>

<div class="sec-title">📊 Monitoring Progress Sampel</div>

<?php if (!$batches): ?>
    <div class="card" style="text-align:center;padding:40px;color:var(--text3)">
        Belum ada data penerimaan sampel untuk dipantau.
    </div>
<?php endif; ?>

<?php foreach ($batches as $b): 
    $total = $b['jumlah_sampel'];
    
    // Logic status per step
    $s_rec  = 'done';
    $s_conf = $b['is_confirmed'] ? 'done' : 'active';
    $s_wo   = $b['has_wo'] > 0 ? 'done' : ($s_conf == 'done' ? 'active' : 'pending');
    
    $butuhPrep = ($b['batch_butuh_prep'] !== null) ? $b['batch_butuh_prep'] : 1;
    if ($butuhPrep == 0) {
        $s_prep = 'skipped';
    } else {
        $s_prep = ($b['count_prep'] >= $total) ? 'done' : ($b['count_prep'] > 0 ? 'active' : ($s_wo == 'done' ? 'active' : 'pending'));
    }
    
    $s_uji  = ($b['count_uji'] >= $total) ? 'done' : ($b['count_uji'] > 0 ? 'active' : (($s_prep == 'done' || $s_prep == 'skipped') ? 'active' : 'pending'));
    $s_qc   = ($b['count_qc'] >= $total) ? 'done' : ($b['count_qc'] > 0 ? 'active' : ($s_uji == 'done' ? 'active' : 'pending'));
    $s_done = ($b['count_selesai'] >= $total) ? 'done' : ($s_qc == 'done' ? 'active' : 'pending');
    $s_inv  = $b['has_invoice'] > 0 ? 'done' : ($s_done == 'done' ? 'active' : 'pending');
?>
<div class="mon-card">
    <div class="mon-header">
        <div>
            <div class="mon-title"><?= bersihkan($b['nomor_penerimaan']) ?> — <?= bersihkan($b['klien']) ?></div>
            <div class="mon-meta">Tanggal Terima: <?= fmtTgl($b['tanggal_terima']) ?> | Total Sampel: <strong><?= $total ?></strong></div>
        </div>
        <div>
            <?= badgeStatus($b['count_selesai'] >= $total ? 'selesai' : 'diproses') ?>
        </div>
    </div>

    <div class="stepper">
        <div class="step <?= $s_rec ?>">
            <div class="step-icon">1</div>
            <div class="step-label">Penerimaan</div>
        </div>
        <div class="step <?= $s_conf ?>">
            <div class="step-icon">2</div>
            <div class="step-label">Konfirmasi</div>
        </div>
        <div class="step <?= $s_wo ?>">
            <div class="step-icon">3</div>
            <div class="step-label">Work Order</div>
        </div>
        <div class="step <?= $s_prep ?>">
            <div class="step-icon"><?= $s_prep == 'skipped' ? '—' : '4' ?></div>
            <div class="step-label">Preparasi <?= $s_prep == 'skipped' ? '(N/A)' : '' ?></div>
        </div>
        <div class="step <?= $s_uji ?>">
            <div class="step-icon">5</div>
            <div class="step-label">Pengujian</div>
        </div>
        <div class="step <?= $s_qc ?>">
            <div class="step-icon">6</div>
            <div class="step-label">QC Validasi</div>
        </div>
        <div class="step <?= $s_done ?>">
            <div class="step-icon">7</div>
            <div class="step-label">Selesai</div>
        </div>
        <div class="step <?= $s_inv ?>">
            <div class="step-icon">8</div>
            <div class="step-label">Invoiced</div>
        </div>
    </div>

    <div class="mon-stats">
        <div class="mon-stat-item">📦 Preparasi: <span class="mon-stat-val"><?= $b['count_prep'] ?>/<?= $total ?></span></div>
        <div class="mon-stat-item">🔬 Pengujian: <span class="mon-stat-val"><?= $b['count_uji'] ?>/<?= $total ?></span></div>
        <div class="mon-stat-item">✅ QC: <span class="mon-stat-val"><?= $b['count_qc'] ?>/<?= $total ?></span></div>
        <div class="mon-stat-item">🏁 Selesai: <span class="mon-stat-val"><?= $b['count_selesai'] ?>/<?= $total ?></span></div>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/header.php'; ?>
