<?php
// ============================================================
//  pengujian.php — Pusat Manajemen Pengujian Sampel
//  UPDATE: Tambah fungsi edit hasil uji
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$pageTitle = 'Pengujian';



$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$tab = $_GET['tab'] ?? 'hasil';

// ── Pre-fill dari sampel.php (klik tombol Uji) ───────────────
$preSampelId = (int)($_GET['sampel_id'] ?? 0);

// ── Data untuk form ──────────────────────────────────────────
// Sampel aktif — exclude yang sudah ada hasil_uji
$sampelAktif = $pdo->query("
    SELECT s.id, s.kode_sampel, s.jenis_material, s.klien,
           rec.nomor_penerimaan,
           CONCAT(s.kode_sampel,
               CASE WHEN rec.nomor_penerimaan IS NOT NULL
                    THEN CONCAT(' [', rec.nomor_penerimaan, ']')
                    ELSE '' END
           ) AS label_lengkap
    FROM sampel s
    LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
    WHERE s.status IN ('antrian','diuji','selesai')
      AND s.id NOT IN (SELECT DISTINCT sampel_id FROM hasil_uji)
      AND (
          s.id IN (SELECT DISTINCT sampel_id FROM preparasi_sampel)
          OR s.penerimaan_id IN (SELECT penerimaan_id FROM work_order WHERE butuh_preparasi = 0)
          OR s.status = 'diuji'
      )
    ORDER BY rec.nomor_penerimaan, s.kode_sampel
")->fetchAll();

// Kelompokkan sampel per batch untuk dropdown grouped
$sampelGrouped = [];
foreach ($sampelAktif as $s) {
    $grp = $s['nomor_penerimaan'] ?? 'Tanpa Batch';
    $sampelGrouped[$grp][] = $s;
}

$alatList   = $pdo->query("SELECT id, kode_alat, nama FROM peralatan WHERE status='tersedia' ORDER BY nama")->fetchAll();
$analisList = $pdo->query("SELECT id, nama FROM pengguna WHERE role IN('admin','analis') AND status='aktif'")->fetchAll();

// ── Daftar WO aktif — untuk selector batch di tab input & batch ──
$woAktifList = $pdo->query("
    SELECT w.id, w.nomor_wo, w.metode, w.parameter, w.prioritas,
           rec.nomor_penerimaan, rec.klien,
           COUNT(wos.sampel_id) AS jumlah_sampel
    FROM work_order w
    LEFT JOIN penerimaan_sampel rec ON w.penerimaan_id = rec.id
    LEFT JOIN work_order_sampel wos ON wos.wo_id = w.id
    WHERE w.status = 'aktif'
      AND (
          w.butuh_preparasi = 0 
          OR w.id IN (SELECT DISTINCT work_order_id FROM preparasi_sampel)
          OR EXISTS (SELECT 1 FROM sampel sp WHERE sp.penerimaan_id = w.penerimaan_id AND sp.status = 'diuji')
      )
    GROUP BY w.id
    ORDER BY FIELD(w.prioritas,'urgent','tinggi','normal'), w.jadwal_mulai ASC
")->fetchAll();

// ── Daftar hasil uji (dengan filter) ────────────────────────
$fSampel = trim($_GET['fsampel'] ?? '');
$fKes    = $_GET['fkes']    ?? '';
$fBatch  = trim($_GET['fbatch']  ?? '');

$sqlH = "SELECT h.*, s.kode_sampel, s.jenis_material, s.klien,
                rec.nomor_penerimaan,
                p.nama AS analis_nama,
                alat.nama AS alat_nama
         FROM hasil_uji h
         JOIN sampel s ON h.sampel_id = s.id
         LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
         LEFT JOIN pengguna p ON h.analis_id = p.id
         LEFT JOIN peralatan alat ON h.alat_id = alat.id
         WHERE 1=1";
$prmH = [];
if ($fSampel) { $sqlH.=" AND (s.kode_sampel LIKE ? OR s.klien LIKE ? OR h.parameter LIKE ?)"; $prmH=array_merge($prmH,["%$fSampel%","%$fSampel%","%$fSampel%"]); }
if ($fKes)    { $sqlH.=" AND h.kesimpulan=?"; $prmH[]=$fKes; }
if ($fBatch)  { $sqlH.=" AND rec.nomor_penerimaan=?"; $prmH[]=$fBatch; }
$sqlH .= " ORDER BY h.created_at DESC";
$stH  = $pdo->prepare($sqlH); $stH->execute($prmH);
$hasilList = $stH->fetchAll();

$total  = count($hasilList);
$lulus  = count(array_filter($hasilList, fn($r)=>$r['kesimpulan']==='lulus'));
$tLulus = count(array_filter($hasilList, fn($r)=>$r['kesimpulan']==='tidak_lulus'));
$pct    = $total>0 ? round($lulus/$total*100) : 0;

// ── Daftar batch untuk filter ────────────────────────────────
$batchAll = $pdo->query("SELECT nomor_penerimaan, klien FROM penerimaan_sampel ORDER BY created_at DESC")->fetchAll();

$metodeOpts = ['AAS','XRF','ICP-OES','Gravimetri','Fire Assay','Volumetri'];
$satuanOpts = ['g/t','%','mg/L','ppm','ppb','mg/kg'];

// Cek apakah user bisa edit (semua role bisa edit pengujian)
$canEdit = canEditPengujian();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border);}
.tab-btn{padding:8px 18px;background:none;border:none;border-bottom:3px solid transparent;color:var(--text3);font-size:.82rem;cursor:pointer;font-weight:600;transition:.2s;margin-bottom:-1px;}
.tab-btn:hover{color:var(--text2);}
.tab-btn.active{color:var(--gold);border-bottom-color:var(--gold);}
.tab-pane{display:none;}.tab-pane.active{display:block;}

/* Badge batch */
.batch-badge-sm{display:inline-block;background:#162e1a;color:var(--green);border:1px solid var(--green3);border-radius:10px;font-size:.65rem;padding:1px 7px;}

/* Highlight batch info di form */
.batch-info-box{background:#0d2318;border:1px solid var(--green3);border-radius:6px;padding:8px 12px;margin-bottom:10px;font-size:.78rem;display:none;}
.batch-info-box.show{display:block;}
.batch-info-row{display:flex;gap:16px;flex-wrap:wrap;}
.batch-info-item .lbl{font-size:.68rem;color:var(--text3);}
.batch-info-item .val{color:var(--gold);font-weight:700;}

/* No. Ref highlight */
.ref-badge{display:inline-block;background:#1a2e0a;color:#a8d58a;border:1px solid #2e5018;border-radius:4px;font-size:.68rem;padding:1px 8px;font-family:monospace;}

/* Modal Edit */
.modal-edit {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}
.modal-edit-content {
    background: var(--bg2);
    border-radius: 12px;
    width: 550px;
    max-width: 90%;
    padding: 20px;
    border: 1px solid var(--gold);
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.modal-edit-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}
.modal-edit-header h3 {
    color: var(--gold);
    margin: 0;
}
.modal-edit-close {
    background: none;
    border: none;
    color: var(--text3);
    font-size: 1.5rem;
    cursor: pointer;
}
.modal-edit-close:hover {
    color: var(--red);
}
.modal-edit-footer {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.form-control {
    width: 100%;
    background: var(--bg3);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 10px;
    border-radius: 6px;
    font-size: .8rem;
    outline: none;
}
.form-control:focus {
    border-color: var(--gold);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
}
.form-group {
    margin-bottom: 12px;
}
.form-group label {
    display: block;
    font-size: .75rem;
    color: var(--text3);
    margin-bottom: 4px;
}

/* Modal XRF Picker - Fixed Consistent Dimension */
/* Modal XRF Picker - Sleek Minimalist & Clean */
.modal-xrf {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.85);
    backdrop-filter: blur(6px);
    z-index: 1050;
    justify-content: center;
    align-items: center;
}
.modal-xrf-content {
    background: var(--bg2);
    border-radius: 12px;
    width: 980px;
    max-width: 95vw;
    height: 590px;
    max-height: 90vh;
    padding: 16px 20px;
    border: 1px solid rgba(232, 180, 0, 0.45);
    box-shadow: 0 16px 44px rgba(0,0,0,0.75);
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    gap: 10px;
}
.modal-xrf-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.xrf-filter-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 12px;
    flex-shrink: 0;
    flex-wrap: nowrap;
}
.xrf-filter-bar input,
.xrf-filter-bar select {
    background: var(--bg2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 5px 9px;
    border-radius: 5px;
    font-size: .75rem;
    outline: none;
}
.xrf-filter-bar input:focus,
.xrf-filter-bar select:focus {
    border-color: var(--gold);
}
.xrf-table-container {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg3);
}
.xrf-table-container thead th {
    position: sticky;
    top: 0;
    background: #122117;
    z-index: 3;
    padding: 8px 10px;
    font-size: .73rem;
    color: var(--text3);
    font-weight: 600;
    border-bottom: 1px solid var(--border);
}
.xrf-table-container tbody td {
    padding: 8px 10px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    vertical-align: middle;
}
.xrf-table-container tbody tr:hover {
    background: rgba(232, 180, 0, 0.04);
}
.btn-outline-xrf {
    background: #0f2942;
    color: #38bdf8;
    border: 1px solid #0284c7;
    font-size: .72rem;
    padding: 4px 8px;
    border-radius: 4px;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    cursor: pointer;
    transition: .15s ease;
}
.btn-outline-xrf:hover {
    background: #0284c7;
    color: #ffffff;
}
.btn-outline-xrf.loaded {
    background: #064e3b;
    color: #6ee7b7;
    border-color: #059669;
}
.xrf-badge-mode {
    display: inline-block;
    padding: 1px 5px;
    border-radius: 3px;
    font-size: .65rem;
    font-weight: 600;
}
.xrf-mode-mineral { background: #064e3b; color: #6ee7b7; border: 1px solid #047857; }
.xrf-mode-alloy { background: #1e3a8a; color: #93c5fd; border: 1px solid #3b82f6; }
.xrf-mode-metal { background: #451a03; color: #fde047; border: 1px solid #d97706; }

/* Clean Elements Tags */
.xrf-element-target {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: rgba(232, 180, 0, 0.16);
    color: #fde047;
    border: 1px solid rgba(232, 180, 0, 0.55);
    border-radius: 4px;
    padding: 2px 7px;
    font-size: .72rem;
    font-weight: 700;
    margin-right: 5px;
}
.xrf-element-target.none {
    background: rgba(255, 255, 255, 0.04);
    color: var(--text3);
    border: 1px dashed var(--border);
    font-weight: normal;
}
.xrf-element-subtle {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: rgba(255, 255, 255, 0.04);
    color: var(--text2);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 4px;
    padding: 2px 6px;
    font-size: .7rem;
    margin-right: 4px;
}
.xrf-element-subtle strong {
    color: var(--text);
}
.xrf-element-more {
    font-size: .66rem;
    color: var(--text3);
    background: rgba(255,255,255,0.03);
    padding: 2px 5px;
    border-radius: 3px;
    cursor: help;
}

.param-tag{display:inline-block;background:rgba(232,180,0,0.12);color:var(--gold);border:1px solid rgba(232,180,0,0.25);border-radius:4px;padding:2px 7px;font-size:.72rem;font-weight:700;}

/* Multi-Scan Average Compact Floating Bar */
.xrf-avg-drawer {
    display: none;
    background: linear-gradient(90deg, #0d2a1e 0%, #081a13 100%);
    border: 1px solid var(--gold);
    border-radius: 8px;
    padding: 7px 14px;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(0,0,0,0.4);
}
.xrf-scan-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #143526;
    color: #a7f3d0;
    border: 1px solid #059669;
    border-radius: 4px;
    padding: 2px 7px;
    font-size: .68rem;
    font-weight: 600;
}
.xrf-scan-chip-remove {
    cursor: pointer;
    color: #fca5a5;
    font-weight: bold;
    font-size: .72rem;
    margin-left: 2px;
}
.xrf-scan-chip-remove:hover {
    color: #ef4444;
}
.xrf-avg-mini-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #091710;
    border: 1px solid rgba(232, 180, 0, 0.35);
    border-radius: 4px;
    padding: 2px 6px;
    font-size: .68rem;
}
.xrf-avg-mini-pill.target-matched {
    background: rgba(232, 180, 0, 0.15);
    border-color: var(--gold);
    color: #fff;
}
</style>

<div class="sec-title">Pengujian Sampel</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR')?'alert-red':'alert-green' ?>" style="margin-bottom:14px">
        <?= str_starts_with($msg,'ERROR')?'&#9888;':'&#10003;' ?> <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<!-- TABS -->
<div class="tabs">
    <button class="tab-btn <?= $tab==='hasil'?'active':'' ?>"  onclick="switchTab('hasil',this)">&#128300; Hasil Uji</button>
    <button class="tab-btn <?= $tab==='batch'?'active':'' ?>"  onclick="switchTab('batch',this)">&#128230; Input Batch Uji</button>
    <button class="tab-btn <?= $tab==='stat'?'active':'' ?>"   onclick="switchTab('stat',this)">&#128202; Statistik</button>
</div>

<!-- ══════════════════════════════════════════════
     TAB 1 — DAFTAR HASIL UJI
══════════════════════════════════════════════ -->
<div id="tab-hasil" class="tab-pane <?= $tab==='hasil'?'active':'' ?>">

    <!-- Filter -->
    <form method="GET" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
        <input type="hidden" name="tab" value="hasil"/>
        <input name="fsampel" value="<?= bersihkan($fSampel) ?>" placeholder="&#128269; Cari kode / klien / parameter..."
               style="flex:1;min-width:180px;background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:6px;font-size:.8rem;outline:none"/>
        <select name="fkes" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:.8rem;outline:none">
            <option value="">Semua Kesimpulan</option>
            <option value="lulus" <?= $fKes==='lulus'?'selected':'' ?>>&#10003; Lulus</option>
            <option value="tidak_lulus" <?= $fKes==='tidak_lulus'?'selected':'' ?>>&#10007; Tidak Lulus</option>
            <option value="pending" <?= $fKes==='pending'?'selected':'' ?>>Pending</option>
        </select>
        <select name="fbatch" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:.8rem;outline:none">
            <option value="">Semua Batch</option>
            <?php foreach ($batchAll as $b): ?>
                <option value="<?= bersihkan($b['nomor_penerimaan']) ?>" <?= $fBatch===$b['nomor_penerimaan']?'selected':'' ?>><?= bersihkan($b['nomor_penerimaan']) ?> — <?= bersihkan($b['klien']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-green btn-sm">Terapkan</button>
        <a href="?tab=hasil" class="btn btn-sm" style="background:var(--bg3);color:var(--text2);border:1px solid var(--border)">Reset</a>
        <a href="<?= BASE_URL ?>/exports/export_excel.php" class="btn btn-green btn-sm">&#128202; Excel</a>
        <a href="<?= BASE_URL ?>/exports/export_pdf.php" target="_blank" class="btn btn-red btn-sm">&#128196; PDF</a>
    </form>

    <!-- Tabel hasil uji -->
    <div class="card" style="margin-bottom:16px">
        <div class="card-title">&#128300; Hasil Uji Kimia &amp; Mineral <span><?= $total ?> data</span></div>
        <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                    <th>ID Uji</th>
                    <th>No. Referensi Batch</th>
                    <th>Sampel</th>
                    <th>Material</th>
                    <th>Klien</th>
                    <th>Parameter</th>
                    <th>Hasil</th>
                    <th>Satuan</th>
                    <th>Metode</th>
                    <th>Alat</th>
                    <th>Analis</th>
                    <th>Tgl Uji</th>
                    <th>Kesimpulan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($hasilList as $h): ?>
            <tr>
                <td style="color:var(--text3);font-size:.75rem"><?= bersihkan($h['kode_uji']) ?></td>
                <td>
                    <?php if ($h['nomor_penerimaan']): ?>
                        <span class="ref-badge">&#128230; <?= bersihkan($h['nomor_penerimaan']) ?></span>
                    <?php else: ?>
                        <span style="color:var(--text3);font-size:.75rem">—</span>
                    <?php endif; ?>
                </td>
                <td><strong style="color:var(--gold)"><?= bersihkan($h['kode_sampel']) ?></strong></td>
                <td><?= bersihkan($h['jenis_material']) ?></td>
                <td style="font-size:.75rem"><?= bersihkan($h['klien']) ?></td>
                <td><span class="param-tag"><?= bersihkan($h['parameter']) ?></span></td>
                <td><strong><?= $h['nilai'] ?></strong></td>
                <td><?= bersihkan($h['satuan']) ?></td>
                <td><?= bersihkan($h['metode']) ?></td>
                <td style="font-size:.75rem"><?= bersihkan($h['alat_nama'] ?? '—') ?></td>
                <td style="font-size:.75rem"><?= bersihkan($h['analis_nama'] ?? '—') ?></td>
                <td style="font-size:.75rem"><?= $h['tanggal_uji'] ? date('d/m/Y',strtotime($h['tanggal_uji'])) : '—' ?></td>
                <td><?= badgeStatus($h['kesimpulan']) ?></td>
                <td style="white-space:nowrap">
                    <?php if ($canEdit): ?>
                        <div style="display:flex;gap:4px">
                            <button onclick="openEditModal(<?= $h['id'] ?>)" 
                                    class="btn btn-gold btn-sm" 
                                    style="font-size:.68rem;padding:3px 8px"
                                    title="Edit hasil uji">
                                ✏️ Edit
                            </button>
                            <form method="POST" action="<?= BASE_URL ?>/actions/simpan_hasil_uji.php" style="display:inline;" onsubmit="return confirm('Yakin ingin menghapus hasil uji ini?');">
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id" value="<?= $h['id'] ?>">
                                <input type="hidden" name="redirect" value="<?= BASE_URL ?>/pages/pengujian.php?tab=hasil">
                                <button type="submit" class="btn btn-red btn-sm" style="font-size:.68rem;padding:3px 8px" title="Hapus hasil uji">
                                    🗑️ Hapus
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$hasilList): ?>
                <tr><td colspan="14" style="text-align:center;color:var(--text3);padding:24px">Belum ada data.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Ringkasan -->
    <div class="grid2">
        <div class="card">
            <div class="card-title">&#128202; Distribusi Hasil</div>
            <div class="prog-wrap" style="margin-bottom:10px"><div class="prog-label"><span>&#10003; Lulus</span><span><?= $lulus ?> (<?= $pct ?>%)</span></div><div class="prog"><div class="prog-bar pb-green" style="width:<?= $pct ?>%"></div></div></div>
            <div class="prog-wrap" style="margin-bottom:10px"><div class="prog-label"><span>&#10007; Tidak Lulus</span><span><?= $tLulus ?></span></div><div class="prog"><div class="prog-bar pb-red" style="width:<?= $total>0?round($tLulus/$total*100):0 ?>%"></div></div></div>
            <div class="prog-wrap"><div class="prog-label"><span>&#8987; Pending</span><span><?= $total-$lulus-$tLulus ?></span></div><div class="prog"><div class="prog-bar pb-yellow" style="width:<?= $total>0?round(($total-$lulus-$tLulus)/$total*100):0 ?>%"></div></div></div>
        </div>
        <div class="card">
            <div class="card-title">&#128230; Ringkasan per Batch</div>
            <?php
            $batchStat = $pdo->query("
                SELECT rec.nomor_penerimaan, rec.klien,
                       COUNT(h.id) AS total,
                       SUM(h.kesimpulan='lulus') AS lulus,
                       SUM(h.kesimpulan='tidak_lulus') AS tlulus
                FROM hasil_uji h
                JOIN sampel s ON h.sampel_id=s.id
                JOIN penerimaan_sampel rec ON s.penerimaan_id=rec.id
                GROUP BY rec.id ORDER BY rec.created_at DESC LIMIT 5
            ")->fetchAll();
            if ($batchStat): ?>
            <table class="data-table">
                <thead><tr><th>No. Referensi</th><th>Klien</th><th>Total Uji</th><th>Lulus</th><th>Tdk Lulus</th></tr></thead>
                <tbody>
                <?php foreach ($batchStat as $bs): ?>
                <tr>
                    <td><span class="ref-badge"><?= bersihkan($bs['nomor_penerimaan']) ?></span></td>
                    <td style="font-size:.75rem"><?= bersihkan($bs['klien']) ?></td>
                    <td style="text-align:center"><?= $bs['total'] ?></td>
                    <td style="text-align:center;color:var(--green)"><?= $bs['lulus'] ?></td>
                    <td style="text-align:center;color:var(--red)"><?= $bs['tlulus'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?><div style="color:var(--text3);font-size:.78rem;text-align:center;padding:14px">Belum ada data batch.</div><?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     TAB 2 — INPUT BATCH UJI
══════════════════════════════════════════════ -->
<div id="tab-batch" class="tab-pane <?= $tab==='batch'?'active':'' ?>">
    <div class="card">
        <div class="card-title">&#128230; Input Batch Hasil Uji</div>
        <p style="font-size:.78rem;color:var(--text3);margin-bottom:14px">
            Pilih <strong style="color:var(--gold)">Work Order</strong> — sampel akan dimuat (1 baris per sampel).
            Klik <strong style="color:#60a5fa">⚡ Pilih XRF</strong> pada tiap sampel untuk memunculkan seluruh kolom parameter unsur beserta nilainya secara otomatis (mendukung single scan maupun rata-rata multi-scan).
        </p>

        <!-- Pilih WO -->
        <div class="form-group" style="margin-bottom:14px">
            <label>&#128203; Pilih Work Order <small style="color:var(--text3)">(otomatis memuat sampel &amp; parameter terkait)</small></label>
            <select id="batchWoSel" onchange="loadBatchFromWo(this)" style="max-width:600px">
                <option value="">— Pilih Work Order atau isi manual —</option>
                <?php foreach ($woAktifList as $wo): ?>
                    <option value="<?= $wo['id'] ?>"
                            data-ref="<?= bersihkan($wo['nomor_penerimaan'] ?? '') ?>"
                            data-metode="<?= bersihkan($wo['metode'] ?? '') ?>"
                            data-param="<?= bersihkan($wo['parameter'] ?? '') ?>">
                        <?= bersihkan($wo['nomor_wo']) ?>
                        <?php if ($wo['nomor_penerimaan']): ?>
                            — <?= bersihkan($wo['nomor_penerimaan']) ?>
                        <?php endif; ?>
                        (<?= $wo['jumlah_sampel'] ?> sampel<?= $wo['parameter'] ? ' · '.bersihkan($wo['parameter']) : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Banner Info Multi-Parameter Work Order -->
        <div id="woBatchParamInfo" style="display:none;margin-bottom:14px;padding:10px 14px;background:#0d2318;border:1px solid var(--green3);border-radius:8px;font-size:.78rem">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <span style="color:var(--gold);font-weight:700">📊 Parameter Uji Terpisah:</span> 
                    <span id="woBatchParamText"></span>
                </div>
                <span id="woBatchSummaryText" style="font-size:.7rem;color:var(--text3);background:var(--bg3);padding:3px 8px;border-radius:4px;border:1px solid var(--border)"></span>
            </div>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_hasil_uji_batch.php">
            <div style="border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <span style="font-size:.8rem;font-weight:600;color:var(--gold)">&#128300; Baris Hasil Uji</span>
                    <button type="button" class="btn btn-green btn-sm" onclick="tambahBarisUji()">&#10133; Tambah Baris</button>
                </div>
                <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.78rem" id="batchUjiTable">
                    <thead>
                        <tr>
                            <th style="padding:6px;text-align:left;color:var(--text3);font-size:.72rem;border-bottom:1px solid var(--border)">Sampel</th>
                            <th style="padding:6px;text-align:left;color:var(--text3);font-size:.72rem;border-bottom:1px solid var(--border);color:#60a5fa">Data XRF</th>
                            <th style="padding:6px;text-align:left;color:var(--text3);font-size:.72rem;border-bottom:1px solid var(--border)">Parameter</th>
                            <th style="padding:6px;text-align:left;color:var(--text3);font-size:.72rem;border-bottom:1px solid var(--border)">Nilai</th>
                            <th style="padding:6px;text-align:left;color:var(--text3);font-size:.72rem;border-bottom:1px solid var(--border)">Satuan</th>
                            <th style="padding:6px;text-align:left;color:var(--text3);font-size:.72rem;border-bottom:1px solid var(--border)">Metode</th>
                            <th style="padding:6px;text-align:left;color:var(--text3);font-size:.72rem;border-bottom:1px solid var(--border)">Kesimpulan</th>
                            <th style="padding:6px;border-bottom:1px solid var(--border);width:36px"></th>
                        </tr>
                    </thead>
                    <tbody id="batchUjiRows"></tbody>
                </table>
                </div>
                <div style="font-size:.72rem;color:var(--text3);margin-top:6px">Total: <strong id="totalUjiBatch" style="color:var(--gold)">0</strong> baris</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Analis (berlaku semua baris)</label>
                    <select name="analis_id_all">
                        <?php foreach ($analisList as $a): ?><option value="<?= $a['id'] ?>"><?= bersihkan($a['nama']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tanggal Uji (berlaku semua baris)</label>
                    <input type="date" name="tanggal_uji_all" value="<?= date('Y-m-d') ?>" required/>
                </div>
            </div>
            <button type="submit" class="btn btn-gold">&#128190; Simpan Semua Hasil Uji</button>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     TAB 3 — STATISTIK
══════════════════════════════════════════════ -->
<div id="tab-stat" class="tab-pane <?= $tab==='stat'?'active':'' ?>">
    <div class="grid2">
        <div class="card">
            <div class="card-title">&#128202; Per Parameter</div>
            <?php $sByParam=$pdo->query("SELECT parameter, COUNT(*) n, SUM(kesimpulan='lulus') l FROM hasil_uji GROUP BY parameter ORDER BY n DESC LIMIT 8")->fetchAll();
            $mx=max(array_column($sByParam,'n')?:[1]);
            foreach ($sByParam as $r): ?>
            <div class="prog-wrap" style="margin-bottom:8px">
                <div class="prog-label">
                    <span><?= bersihkan($r['parameter']) ?></span>
                    <span><?= $r['n'] ?> uji &nbsp;·&nbsp; <span style="color:var(--green)"><?= $r['l'] ?> lulus</span></span>
                </div>
                <div class="prog"><div class="prog-bar pb-green" style="width:<?= round($r['n']/$mx*100) ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <div class="card-title">&#128202; Per Metode</div>
            <?php $sByMet=$pdo->query("SELECT metode, COUNT(*) n FROM hasil_uji GROUP BY metode ORDER BY n DESC")->fetchAll();
            $mx2=max(array_column($sByMet,'n')?:[1]);
            foreach ($sByMet as $r): ?>
            <div class="prog-wrap" style="margin-bottom:8px">
                <div class="prog-label"><span><?= bersihkan($r['metode']) ?></span><span><?= $r['n'] ?></span></div>
                <div class="prog"><div class="prog-bar pb-gold" style="width:<?= round($r['n']/$mx2*100) ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- MODAL EDIT HASIL UJI -->
<div id="editModal" class="modal-edit">
    <div class="modal-edit-content">
        <div class="modal-edit-header">
            <h3>✏️ Edit Hasil Uji</h3>
            <button class="modal-edit-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form id="editForm" method="POST" action="<?= BASE_URL ?>/actions/simpan_hasil_uji.php">
            <input type="hidden" name="action" value="edit"/>
            <input type="hidden" name="id" id="edit_id"/>
            <input type="hidden" name="redirect" value="<?= BASE_URL ?>/pages/pengujian.php?tab=hasil"/>
            
            <div class="form-group">
                <label>Kode Uji</label>
                <input type="text" id="edit_kode_uji" class="form-control" readonly disabled style="background:var(--bg3);"/>
            </div>
            
            <div class="form-group">
                <label>Sampel</label>
                <input type="text" id="edit_sampel" class="form-control" readonly disabled style="background:var(--bg3);"/>
            </div>
            
            <div class="form-group">
                <label>Parameter</label>
                <input type="text" name="parameter" id="edit_parameter" class="form-control" required/>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Nilai</label>
                    <input type="number" step="any" name="nilai" id="edit_nilai" class="form-control" required/>
                </div>
                <div class="form-group">
                    <label>Satuan</label>
                    <select name="satuan" id="edit_satuan" class="form-control">
                        <?php foreach ($satuanOpts as $sat): ?>
                            <option value="<?= $sat ?>"><?= $sat ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Metode</label>
                    <select name="metode" id="edit_metode" class="form-control">
                        <?php foreach ($metodeOpts as $met): ?>
                            <option value="<?= $met ?>"><?= $met ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Alat</label>
                    <select name="alat_id" id="edit_alat_id" class="form-control">
                        <option value="">— Pilih Alat —</option>
                        <?php foreach ($alatList as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= bersihkan($a['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Analis</label>
                    <select name="analis_id" id="edit_analis_id" class="form-control">
                        <option value="">— Pilih Analis —</option>
                        <?php foreach ($analisList as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= bersihkan($a['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tanggal Uji</label>
                    <input type="date" name="tanggal_uji" id="edit_tanggal_uji" class="form-control" required/>
                </div>
            </div>
            
            <div class="form-group">
                <label>Kesimpulan</label>
                <select name="kesimpulan" id="edit_kesimpulan" class="form-control">
                    <option value="lulus">Lulus</option>
                    <option value="tidak_lulus">Tidak Lulus</option>
                    <option value="pending">Pending</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Catatan</label>
                <textarea name="catatan" id="edit_catatan" rows="2" class="form-control" placeholder="Catatan tambahan..."></textarea>
            </div>
            
            <div class="modal-edit-footer">
                <button type="button" class="btn btn-sm" onclick="closeEditModal()">Batal</button>
                <button type="submit" class="btn btn-gold">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PILIH DATA XRF -->
<div id="xrfPickerModal" class="modal-xrf">
    <div class="modal-xrf-content">
        <!-- Compact Header -->
        <div class="modal-xrf-header">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="font-size:1.1rem;color:var(--gold)">⚡</span>
                <span style="color:var(--gold);font-size:.92rem;font-weight:700">Pilih Data Scan XRF</span>
                <span id="xrfActiveTargetRow" style="font-size:.7rem;color:var(--text2);background:var(--bg3);padding:2px 8px;border-radius:4px;border:1px solid var(--border)"></span>
            </div>
            <div style="display:flex;align-items:center;gap:12px">
                <span id="xrfModalCount" style="font-size:.72rem;color:var(--text3)"></span>
                <button type="button" class="modal-edit-close" onclick="closeXrfPickerModal()" style="font-size:1.2rem;line-height:1;background:transparent;border:none;color:var(--text3);cursor:pointer">&times;</button>
            </div>
        </div>

        <!-- 1-Line Compact Filter Bar -->
        <div class="xrf-filter-bar">
            <input type="text" id="xrfModalSearch" placeholder="🔍 Cari nama sampel, ID alat, kurva..." style="flex:1;min-width:180px" onkeydown="if(event.key==='Enter'){event.preventDefault();fetchXrfModalData();}"/>

            <select id="xrfModalMode" style="width:130px" onchange="fetchXrfModalData()">
                <option value="all">Semua Mode</option>
                <option value="mineral.db">mineral.db</option>
                <option value="alloy.db">alloy.db</option>
                <option value="metal.db">metal.db</option>
            </select>

            <select id="xrfModalDevice" style="width:115px" onchange="fetchXrfModalData()">
                <option value="">Semua Alat</option>
            </select>

            <div style="display:flex;align-items:center;gap:3px">
                <input type="date" id="xrfModalStartDate" style="width:105px" title="Tanggal Mulai"/>
                <span style="color:var(--text3);font-size:.7rem">-</span>
                <input type="date" id="xrfModalEndDate" style="width:105px" title="Tanggal Selesai"/>
            </div>

            <button type="button" class="btn btn-green btn-sm" onclick="fetchXrfModalData()" style="padding:4px 10px;font-size:.72rem">🔍 Filter</button>
            <button type="button" class="btn btn-sm" onclick="resetXrfModalFilter()" style="background:var(--bg2);color:var(--text2);border:1px solid var(--border);padding:4px 8px;font-size:.72rem" title="Reset Filter">✕</button>
            
            <div id="xrfAutoSuggestWrap" style="display:none;margin-left:auto">
                <button type="button" class="btn btn-sm" id="btnAutoSuggest" onclick="autoSelectSampleScans()" style="background:#133324;border:1px solid var(--green3);color:#6ee7b7;font-size:.68rem;padding:3px 8px;white-space:nowrap;cursor:pointer">
                    ⚡ Auto-Pilih
                </button>
            </div>
        </div>

        <!-- Multi-Scan Average Compact Bar -->
        <div id="xrfAverageDrawer" class="xrf-avg-drawer">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;flex:1;min-width:0">
                    <span style="color:var(--gold);font-weight:700;font-size:.75rem;white-space:nowrap">📊 Rata-rata (<span id="xrfSelectedCount">0</span> Scan):</span>
                    <div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap" id="xrfSelectedScanChips"></div>
                    <div style="display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap;margin-left:6px" id="xrfAveragePills"></div>
                </div>
                <div style="display:flex;gap:5px;align-items:center;flex-shrink:0" id="xrfAverageActionBtns">
                    <button type="button" class="btn btn-gold btn-sm" onclick="applySelectedXrfAverage('all')" style="font-weight:700;padding:3px 10px;font-size:.72rem">
                        ✨ Terapkan Rata-rata (<span id="btnAvgCount">0</span>)
                    </button>
                    <button type="button" class="btn btn-sm" onclick="clearXrfSelection()" style="background:var(--bg3);color:var(--text2);border:1px solid var(--border);padding:3px 6px;font-size:.7rem">
                        ✕
                    </button>
                </div>
            </div>
        </div>

        <!-- Data Table in Modal -->
        <div class="xrf-table-container">
            <table class="data-table" style="font-size:.75rem;width:100%" id="xrfModalTable">
                <thead>
                    <tr>
                        <th style="width:36px;text-align:center;padding:7px 5px">
                            <input type="checkbox" id="xrfSelectAll" onchange="toggleSelectAllXrf(this)" title="Pilih Semua" style="cursor:pointer"/>
                        </th>
                        <th style="width:130px;padding:7px 8px">Waktu &amp; Alat</th>
                        <th style="width:160px;padding:7px 8px">Nama Sampel</th>
                        <th style="padding:7px 8px">Kandungan Unsur (Elements)</th>
                        <th style="width:80px;text-align:center;padding:7px 8px">Aksi</th>
                    </tr>
                </thead>
                <tbody id="xrfModalTableBody">
                    <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text3)">Memuat data XRF...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Modal Footer -->
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.7rem;color:var(--text3);padding-top:2px;flex-shrink:0">
            <span>💡 Centang 2+ scan untuk menghitung rata-rata, atau klik <strong>✅ Pilih</strong> untuk 1 scan.</span>
            <button type="button" class="btn btn-sm" onclick="closeXrfPickerModal()" style="background:var(--bg3);color:var(--text2);border:1px solid var(--border);padding:3px 12px;font-size:.72rem">Tutup</button>
        </div>
    </div>
</div>


<script>
// ── TAB ───────────────────────────────────────────────────────
function switchTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    if (el) el.classList.add('active');
    history.replaceState(null,'','?tab='+name);
}

// ── ON SAMPEL CHANGE → isi No. Referensi + info box ──────────
const batchData = <?= json_encode(array_column(
    $pdo->query("SELECT nomor_penerimaan, klien, (SELECT COUNT(*) FROM sampel WHERE penerimaan_id=p.id) jml FROM penerimaan_sampel p")->fetchAll(),
    null, 'nomor_penerimaan'
)) ?>;

function onSampelChange(sel) {
    const opt  = sel.options[sel.selectedIndex];
    const rec  = opt.dataset.batch  || '';
    const klien= opt.dataset.klien  || '';
    const mat  = opt.dataset.mat    || '';
    const infoBox = document.getElementById('batchInfoBox');
    const noRef   = document.getElementById('noRefDisplay');
    const noRefH  = document.getElementById('noRefHidden');

    if (rec) {
        noRef.value  = rec;
        noRefH.value = rec;
        document.getElementById('biRec').textContent   = rec;
        document.getElementById('biKlien').textContent = klien;
        document.getElementById('biMat').textContent   = mat;
        const bd = batchData[rec];
        document.getElementById('biTotal').textContent = bd ? bd.jml + ' sampel' : '—';
        infoBox.classList.add('show');
    } else {
        noRef.value  = '—';
        noRefH.value = '';
        infoBox.classList.remove('show');
    }
}

// Auto-trigger jika ada pre-fill sampel
<?php if ($preSampelId): ?>
window.addEventListener('load', () => {
    const sel = document.getElementById('selSampel');
    if (sel) { onSampelChange(sel); switchTab('input', document.getElementById('tabInputBtn')); }
});
<?php endif; ?>

// ── BATCH UJI TABLE ───────────────────────────────────────────
const sampelOpts = <?= json_encode(array_map(fn($s)=>['id'=>$s['id'],'label'=>$s['label_lengkap'],'batch'=>$s['nomor_penerimaan']??'','klien'=>$s['klien']], $sampelAktif)) ?>;
const metOpts    = <?= json_encode($metodeOpts) ?>;
const satOpts    = <?= json_encode($satuanOpts) ?>;

// ── XRF MODAL PICKER & BATCH UJI ──────────────────────────────
let currentTargetRowIndex = null;
let currentTargetSampleName = '';
let currentBatchWoId = '';
let activeWoTargetParams = [];
let xrfModalDataCache = [];
let selectedXrfIds = new Set();

function openXrfPickerModal(rowIndex) {
    currentTargetRowIndex = rowIndex;
    selectedXrfIds.clear();
    
    // Get row sample info and specific parameter for this row
    const row = document.getElementById(`ubr${rowIndex}`);
    let targetLabel = `Baris #${rowIndex}`;
    currentTargetSampleName = '';
    let rowParam = '';

    if (row) {
        const selectSampel = row.querySelector(`select[name="rows[${rowIndex}][sampel_id]"]`) || row.querySelector(`input[name="rows[${rowIndex}][sampel_id]"]`);
        if (selectSampel) {
            let fullText = '';
            if (selectSampel.tagName === 'SELECT' && selectSampel.selectedIndex > 0) {
                fullText = selectSampel.options[selectSampel.selectedIndex].text;
            } else if (selectSampel.value) {
                const found = sampelOpts.find(s => String(s.id) === String(selectSampel.value));
                if (found) fullText = found.label;
            }
            if (fullText) {
                targetLabel += ` · Sampel: ${fullText}`;
                currentTargetSampleName = fullText.split(' ')[0].trim();
            }
        }
        const pInput = row.querySelector(`input[name="rows[${rowIndex}][parameter]"]`);
        if (pInput && pInput.value.trim()) {
            rowParam = pInput.value.trim();
            targetLabel += ` · Parameter: ${rowParam}`;
        }
    }

    if (rowParam) {
        activeWoTargetParams = [rowParam];
    }

    document.getElementById('xrfActiveTargetRow').textContent = `🎯 Mengisi untuk: ${targetLabel}`;
    document.getElementById('xrfPickerModal').style.display = 'flex';
    
    updateXrfAverageDrawer();
    fetchXrfModalData();
}

function closeXrfPickerModal() {
    document.getElementById('xrfPickerModal').style.display = 'none';
}

function fetchXrfModalData() {
    const startDate = document.getElementById('xrfModalStartDate').value;
    const endDate   = document.getElementById('xrfModalEndDate').value;
    const device    = document.getElementById('xrfModalDevice') ? document.getElementById('xrfModalDevice').value : '';
    const mode      = document.getElementById('xrfModalMode').value;
    const search    = document.getElementById('xrfModalSearch').value.trim();
    
    const tbody = document.getElementById('xrfModalTableBody');
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text3)">⏳ Memuat data XRF dari server...</td></tr>`;
    document.getElementById('xrfModalCount').textContent = 'Memuat...';

    const params = new URLSearchParams();
    if (startDate) params.append('start_date', startDate);
    if (endDate)   params.append('end_date', endDate);
    if (device)    params.append('device', device);
    if (mode && mode !== 'all') params.append('mode', mode);
    if (search)    params.append('search', search);

    fetch(`<?= BASE_URL ?>/actions/get_xrf_measurements.php?${params.toString()}`)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--red)">⚠️ ${res.message || 'Gagal mengambil data'}</td></tr>`;
                document.getElementById('xrfModalCount').textContent = 'Gagal memuat';
                return;
            }

            xrfModalDataCache = res.data || [];
            document.getElementById('xrfModalCount').textContent = `Ditemukan ${xrfModalDataCache.length} data scan XRF`;

            // Check auto-suggest matching for current target sample
            const autoSuggestWrap = document.getElementById('xrfAutoSuggestWrap');
            const btnAutoSuggest = document.getElementById('btnAutoSuggest');
            if (autoSuggestWrap && btnAutoSuggest) {
                if (currentTargetSampleName && currentTargetSampleName !== '') {
                    const cleanTarget = currentTargetSampleName.toLowerCase();
                    const matchingScans = xrfModalDataCache.filter(item => {
                        const sn = (item.sample_name || '').toLowerCase();
                        return sn === cleanTarget || sn.includes(cleanTarget) || cleanTarget.includes(sn);
                    });
                    if (matchingScans.length >= 2) {
                        btnAutoSuggest.innerHTML = `⚡ Auto-Pilih ${matchingScans.length} Scan (${escapeHtml(currentTargetSampleName)})`;
                        autoSuggestWrap.style.display = 'block';
                    } else {
                        autoSuggestWrap.style.display = 'none';
                    }
                } else {
                    autoSuggestWrap.style.display = 'none';
                }
            }

            // Dynamically populate device dropdown if returned
            const devSelect = document.getElementById('xrfModalDevice');
            if (devSelect && res.devices && res.devices.length > 0 && devSelect.options.length <= 1) {
                res.devices.forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d;
                    opt.textContent = `Alat: ${d}`;
                    devSelect.appendChild(opt);
                });
            }

            // Dynamically populate mode dropdown with work curves if not yet populated
            const modeSelect = document.getElementById('xrfModalMode');
            if (res.work_curves && res.work_curves.length > 0 && modeSelect.options.length <= 4) {
                const optGroup = document.createElement('optgroup');
                optGroup.label = 'Kurva Kerja (Work Curve)';
                res.work_curves.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = `Kurva: ${c}`;
                    optGroup.appendChild(opt);
                });
                modeSelect.appendChild(optGroup);
            }

            if (xrfModalDataCache.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text3)">🔍 Tidak ditemukan data scan XRF yang sesuai filter.</td></tr>`;
                return;
            }

            let html = '';
            xrfModalDataCache.forEach(item => {
                const isChecked = selectedXrfIds.has(item.id);
                let badgeClass = 'xrf-mode-mineral';
                if (item.db_source === 'alloy.db') badgeClass = 'xrf-mode-alloy';
                else if (item.db_source === 'metal.db') badgeClass = 'xrf-mode-metal';

                let elementBadges = '';
                const hasElements = item.elements && item.elements.length > 0;
                
                if (hasElements) {
                    let targetParamName = (activeWoTargetParams && activeWoTargetParams.length > 0) ? activeWoTargetParams[0] : '';
                    let targetMatch = targetParamName ? findMatchingXrfElement(item.elements, targetParamName) : null;
                    
                    if (targetParamName) {
                        if (targetMatch) {
                            const val = parseFloat(targetMatch.concentration) || 0;
                            elementBadges += `<span class="xrf-element-target">🎯 <strong>${escapeHtml(targetMatch.element_name)}</strong>: ${val.toFixed(3)}${targetMatch.unit || '%'}</span>`;
                        } else {
                            elementBadges += `<span class="xrf-element-target none">🎯 ${escapeHtml(targetParamName)}: 0.000%</span>`;
                        }
                    }

                    // Show up to 3 dominant other elements
                    const otherElements = item.elements.filter(el => !targetMatch || el !== targetMatch);
                    const topOthers = otherElements.slice(0, targetParamName ? 3 : 4);
                    
                    topOthers.forEach(el => {
                        const val = parseFloat(el.concentration) || 0;
                        elementBadges += `<span class="xrf-element-subtle"><strong>${escapeHtml(el.element_name)}</strong> ${val.toFixed(2)}${el.unit || '%'}</span>`;
                    });

                    if (otherElements.length > topOthers.length) {
                        const remainingCount = otherElements.length - topOthers.length;
                        const remainingNames = otherElements.slice(topOthers.length).map(e => `${e.element_name}: ${(parseFloat(e.concentration)||0).toFixed(2)}${e.unit||'%'}`).join(', ');
                        elementBadges += `<span class="xrf-element-more" title="Unsur lainnya: ${remainingNames}">+${remainingCount} lainnya</span>`;
                    }
                } else {
                    elementBadges = `<span style="color:var(--text3);font-size:.7rem">— Tidak ada unsur —</span>`;
                }

                html += `
                <tr id="xrf-row-${item.id}" style="${isChecked ? 'background:rgba(232,180,0,0.08)' : ''}">
                    <td style="text-align:center;vertical-align:middle;width:36px">
                        <input type="checkbox" class="xrf-checkbox" value="${item.id}" ${isChecked ? 'checked' : ''} onchange="toggleXrfCheck(${item.id})" style="cursor:pointer"/>
                    </td>
                    <td style="white-space:nowrap;width:130px">
                        <div style="font-weight:600;color:var(--text);font-size:.74rem">${item.formatted_date}</div>
                        <div style="font-size:.65rem;color:var(--text3);margin-top:2px">📱 ${escapeHtml(item.device_id || 'XRF04')}</div>
                    </td>
                    <td style="width:160px">
                        <div style="font-weight:700;color:var(--gold);font-size:.82rem">${escapeHtml(item.sample_name)}</div>
                        <div style="font-size:.65rem;color:var(--text3);margin-top:2px">
                            ID #${item.report_id || item.id} · <span class="xrf-badge-mode ${badgeClass}">${item.db_source || '—'}</span>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px">
                            ${elementBadges}
                        </div>
                    </td>
                    <td style="text-align:center;white-space:nowrap;width:80px">
                        <button type="button" class="btn btn-green btn-sm" style="font-size:.72rem;padding:4px 12px;font-weight:600;border-radius:4px" onclick="selectXrfItem(${item.id})">
                            ✅ Pilih
                        </button>
                    </td>
                </tr>`;
            });

            tbody.innerHTML = html;
            updateXrfAverageDrawer();
        })
        .catch(err => {
            console.error('Error fetching XRF data:', err);
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--red)">⚠️ Terjadi kesalahan jaringan saat memuat data.</td></tr>`;
            document.getElementById('xrfModalCount').textContent = 'Error';
        });
}

function resetXrfModalFilter() {
    document.getElementById('xrfModalStartDate').value = '';
    document.getElementById('xrfModalEndDate').value = '';
    if (document.getElementById('xrfModalDevice')) document.getElementById('xrfModalDevice').value = '';
    document.getElementById('xrfModalMode').value = 'all';
    document.getElementById('xrfModalSearch').value = '';
    fetchXrfModalData();
}

function toggleXrfCheck(id) {
    if (selectedXrfIds.has(id)) {
        selectedXrfIds.delete(id);
    } else {
        selectedXrfIds.add(id);
    }
    updateXrfAverageDrawer();
}

function toggleSelectAllXrf(checkbox) {
    if (checkbox.checked) {
        xrfModalDataCache.forEach(item => selectedXrfIds.add(item.id));
    } else {
        selectedXrfIds.clear();
    }
    updateXrfAverageDrawer();
}

function clearXrfSelection() {
    selectedXrfIds.clear();
    const selectAllCb = document.getElementById('xrfSelectAll');
    if (selectAllCb) selectAllCb.checked = false;
    updateXrfAverageDrawer();
}

function removeSelectedXrfId(id) {
    selectedXrfIds.delete(id);
    updateXrfAverageDrawer();
}

function autoSelectSampleScans() {
    if (!currentTargetSampleName || xrfModalDataCache.length === 0) return;
    const cleanTarget = currentTargetSampleName.toLowerCase();
    
    const matching = xrfModalDataCache.filter(item => {
        const sn = (item.sample_name || '').toLowerCase();
        return sn === cleanTarget || sn.includes(cleanTarget) || cleanTarget.includes(sn);
    });

    if (matching.length === 0) {
        alert(`Tidak ditemukan scan dengan nama yang sesuai "${currentTargetSampleName}".`);
        return;
    }

    selectedXrfIds.clear();
    matching.forEach(item => selectedXrfIds.add(item.id));
    updateXrfAverageDrawer();
}

function calculateAveragesFromSelected() {
    const selectedScans = xrfModalDataCache.filter(item => selectedXrfIds.has(item.id));
    if (selectedScans.length === 0) return null;

    const elementMap = {}; // upperName -> { originalName, values: [], unit: '', errors: [] }
    selectedScans.forEach(scan => {
        if (scan.elements && Array.isArray(scan.elements)) {
            scan.elements.forEach(el => {
                const name = (el.element_name || '').trim();
                if (!name) return;
                const upperName = name.toUpperCase();
                if (!elementMap[upperName]) {
                    elementMap[upperName] = {
                        originalName: name,
                        values: [],
                        unit: el.unit || '%',
                        errors: []
                    };
                }
                const val = parseFloat(el.concentration) || 0;
                elementMap[upperName].values.push(val);
                if (el.element_error) {
                    elementMap[upperName].errors.push(parseFloat(el.element_error) || 0);
                }
            });
        }
    });

    const averagedElements = [];
    const scanCount = selectedScans.length;
    for (const [key, data] of Object.entries(elementMap)) {
        const sum = data.values.reduce((acc, v) => acc + v, 0);
        const avg = sum / scanCount;
        const avgFormatted = parseFloat(avg.toFixed(4));
        
        averagedElements.push({
            element_name: data.originalName,
            concentration: avgFormatted,
            unit: data.unit,
            values: data.values,
            scans_count: scanCount
        });
    }

    averagedElements.sort((a, b) => b.concentration - a.concentration);

    return {
        selectedScans,
        elements: averagedElements,
        scanCount: scanCount
    };
}

function updateXrfAverageDrawer() {
    const drawer = document.getElementById('xrfAverageDrawer');
    if (!drawer) return;

    // Sync checkboxes & row styles in table
    document.querySelectorAll('.xrf-checkbox').forEach(cb => {
        const id = parseInt(cb.value);
        const isChecked = selectedXrfIds.has(id);
        cb.checked = isChecked;
        const tr = document.getElementById(`xrf-row-${id}`);
        if (tr) {
            tr.style.background = isChecked ? 'rgba(232, 180, 0, 0.08)' : '';
        }
    });

    const selectAllCb = document.getElementById('xrfSelectAll');
    if (selectAllCb && xrfModalDataCache.length > 0) {
        selectAllCb.checked = selectedXrfIds.size === xrfModalDataCache.length;
    }

    if (selectedXrfIds.size === 0) {
        drawer.style.display = 'none';
        return;
    }

    drawer.style.display = 'block';
    const calc = calculateAveragesFromSelected();
    if (!calc) return;

    document.getElementById('xrfSelectedCount').textContent = calc.scanCount;

    // Render chips of selected scans
    const chipsContainer = document.getElementById('xrfSelectedScanChips');
    chipsContainer.innerHTML = calc.selectedScans.map((s, idx) => `
        <span class="xrf-scan-chip">
            <span>#${idx+1}</span> <strong>${escapeHtml(s.sample_name)}</strong>
            <span style="font-size:.62rem;opacity:.8">(${s.formatted_date ? s.formatted_date.split(' ')[1] || s.formatted_date : ''})</span>
            <span class="xrf-scan-chip-remove" onclick="removeSelectedXrfId(${s.id})" title="Hapus scan ini">✕</span>
        </span>
    `).join('');

    // Render Live Average Mini-Pills (Compact Minimalist)
    const pillsContainer = document.getElementById('xrfAveragePills');
    const targetParamName = (activeWoTargetParams && activeWoTargetParams.length > 0) ? activeWoTargetParams[0] : '';

    if (targetParamName) {
        // HANYA tampilkan rata-rata parameter target (misal: Au)
        const targetMatch = findMatchingXrfElement(calc.elements, targetParamName);
        if (targetMatch) {
            const valList = (targetMatch.values || []).map(v => v.toFixed(3)).join(' + ');
            const formula = targetMatch.scans_count > 1 ? `(${valList}) / ${targetMatch.scans_count}` : `${valList}`;
            pillsContainer.innerHTML = `
                <span class="xrf-avg-mini-pill target-matched" style="font-size:.74rem;padding:3px 8px" title="Perhitungan: ${formula} = ${targetMatch.concentration} ${targetMatch.unit}">
                    <strong style="color:#fde047">🎯 Rata-rata ${escapeHtml(targetMatch.element_name)}:</strong>
                    <span style="font-weight:700;margin-left:4px;color:#fff">${targetMatch.concentration}</span>
                    <span style="font-size:.65rem;color:var(--text3);margin-left:2px">${targetMatch.unit}</span>
                    <span style="font-size:.65rem;opacity:.75;margin-left:6px;border-left:1px solid rgba(255,255,255,0.2);padding-left:6px">Rumus: ${formula}</span>
                </span>
            `;
        } else {
            pillsContainer.innerHTML = `
                <span class="xrf-avg-mini-pill" style="font-size:.74rem;padding:3px 8px;color:var(--text3)">
                    <strong>🎯 ${escapeHtml(targetParamName)}:</strong> 0.000 (tidak terdeteksi)
                </span>
            `;
        }
    } else {
        // Jika tanpa target spesifik, tampilkan unsur-unsur dominan
        if (calc.elements.length === 0) {
            pillsContainer.innerHTML = '<span style="color:var(--text3);font-size:.68rem">Tidak ada unsur terdeteksi.</span>';
        } else {
            const topElements = calc.elements.slice(0, 4);
            let pillsHtml = topElements.map(el => {
                const valList = el.values.map(v => v.toFixed(3)).join(' + ');
                const formula = el.scans_count > 1 ? `(${valList}) / ${el.scans_count}` : `${valList}`;
                return `
                <span class="xrf-avg-mini-pill" title="Perhitungan: ${formula} = ${el.concentration} ${el.unit}">
                    <strong style="color:var(--gold)">${escapeHtml(el.element_name)}:</strong>
                    <span>${el.concentration}</span>
                    <span style="font-size:.62rem;color:var(--text3)">${el.unit}</span>
                </span>`;
            }).join('');

            if (calc.elements.length > 4) {
                const moreCount = calc.elements.length - 4;
                const remainingNames = calc.elements.slice(4).map(e => `${e.element_name}: ${e.concentration}${e.unit}`).join(', ');
                pillsHtml += `<span style="font-size:.65rem;color:var(--text3);cursor:help;padding:2px 4px" title="Unsur lainnya: ${remainingNames}">+${moreCount} unsur</span>`;
            }
            pillsContainer.innerHTML = pillsHtml;
        }
    }

    // Update Action Buttons inside Drawer
    const actBox = document.getElementById('xrfAverageActionBtns');
    if (actBox) {
        const targetBtnLabel = targetParamName ? `🎯 Terapkan Rata-rata ${escapeHtml(targetParamName)} (${calc.scanCount} Scan)` : `✨ Terapkan Rata-rata (${calc.scanCount} Scan)`;
        actBox.innerHTML = `
            <button type="button" class="btn btn-gold btn-sm" onclick="applySelectedXrfAverage('target_only')" style="font-weight:700;padding:4px 12px;font-size:.72rem">
                ${targetBtnLabel}
            </button>
            <button type="button" class="btn btn-sm" onclick="clearXrfSelection()" style="background:var(--bg3);color:var(--text2);border:1px solid var(--border);padding:4px 8px;font-size:.72rem" title="Batal">✕</button>
        `;
    }
}

function applySelectedXrfAverage(mode = 'all') {
    const calc = calculateAveragesFromSelected();
    if (!calc || calc.selectedScans.length === 0) {
        alert('Pilih setidaknya 1 data scan XRF.');
        return;
    }

    const scanNames = calc.selectedScans.map(s => s.sample_name).join(', ');
    const scanIds = calc.selectedScans.map(s => s.id).join(',');

    const compositeXrfData = {
        id: scanIds,
        sample_name: calc.scanCount === 1 
            ? calc.selectedScans[0].sample_name 
            : `Rata-rata ${calc.scanCount} Scan (${scanNames})`,
        is_average: calc.scanCount > 1,
        scan_count: calc.scanCount,
        db_source: calc.selectedScans[0].db_source,
        formatted_date: calc.selectedScans[0].formatted_date,
        elements: calc.elements,
        raw_scans: calc.selectedScans
    };

    applyXrfDataToBatchRows(compositeXrfData, currentTargetRowIndex, mode);
    clearXrfSelection();
    closeXrfPickerModal();
}

function findMatchingXrfElement(elements, paramName) {
    if (!paramName || !elements || !elements.length) return null;
    const cleanParam = paramName.trim().toLowerCase();
    
    // 1. Exact match (case-insensitive)
    let match = elements.find(e => (e.element_name || '').trim().toLowerCase() === cleanParam);
    if (match) return match;
    
    // 2. Chemical synonym mappings
    const synonyms = {
        'au': ['gold', 'emas', 'au'],
        'ag': ['silver', 'perak', 'ag'],
        'cu': ['copper', 'tembaga', 'cu'],
        'fe': ['iron', 'besi', 'fe'],
        'ni': ['nickel', 'nikel', 'ni'],
        'pb': ['lead', 'timbal', 'pb'],
        'zn': ['zinc', 'seng', 'zn'],
        'al': ['aluminium', 'aluminum', 'al'],
        'si': ['silicon', 'silika', 'sio2', 'si'],
        'mn': ['manganese', 'mangan', 'mn'],
        'ti': ['titanium', 'ti'],
        'co': ['cobalt', 'kobalt', 'co'],
        'cr': ['chromium', 'kromium', 'cr'],
        'as': ['arsenic', 'arsenik', 'as'],
        'hg': ['mercury', 'merkuri', 'hg'],
        'pt': ['platinum', 'platina', 'pt'],
        'pd': ['palladium', 'paladium', 'pd'],
        'sn': ['tin', 'timah', 'sn'],
        'sb': ['antimony', 'antimon', 'sb'],
        'bi': ['bismuth', 'bismut', 'bi']
    };
    
    for (const [sym, list] of Object.entries(synonyms)) {
        if (cleanParam === sym || list.includes(cleanParam)) {
            match = elements.find(e => {
                const elClean = (e.element_name || '').trim().toLowerCase();
                return elClean === sym || list.includes(elClean);
            });
            if (match) return match;
        }
    }
    
    // 3. Partial match (starts with or contains)
    match = elements.find(e => {
        const elClean = (e.element_name || '').trim().toLowerCase();
        return elClean && (elClean.includes(cleanParam) || cleanParam.includes(elClean));
    });
    
    return match || null;
}

function selectXrfItem(xrfId, mode = 'all') {
    if (!currentTargetRowIndex) return;
    const xrfData = xrfModalDataCache.find(x => x.id === xrfId);
    if (!xrfData) {
        closeXrfPickerModal();
        return;
    }
    applyXrfDataToBatchRows(xrfData, currentTargetRowIndex, mode);
    closeXrfPickerModal();
}

function applyXrfDataToBatchRows(xrfData, rowIndex, mode = 'all') {
    const targetRow = document.getElementById(`ubr${rowIndex}`);
    if (!targetRow) return;

    const selectSampel = targetRow.querySelector(`select[name="rows[${rowIndex}][sampel_id]"]`) || targetRow.querySelector(`input[name="rows[${rowIndex}][sampel_id]"]`);
    const targetSampelId = selectSampel ? selectSampel.value : '';
    const refInput = targetRow.querySelector(`input[name="rows[${rowIndex}][no_referensi]"]`) || targetRow.querySelector(`#ubr-refh-${rowIndex}`);
    const batchRefVal = refInput ? refInput.value : '';

    const pInput = targetRow.querySelector(`input[name="rows[${rowIndex}][parameter]"]`);
    const nInput = targetRow.querySelector(`input[name="rows[${rowIndex}][nilai]"]`);
    const sSelect = targetRow.querySelector(`select[name="rows[${rowIndex}][satuan]"]`);
    const mSelect = targetRow.querySelector(`select[name="rows[${rowIndex}][metode]"]`);
    const xHidden = targetRow.querySelector(`input[name="rows[${rowIndex}][xrf_id]"]`);
    const btnXrf = targetRow.querySelector('.btn-outline-xrf');

    const currentParam = pInput ? pInput.value.trim() : '';

    // Jika baris ini sudah memiliki parameter tertentu (misal 'Au' di baris 1 atau 'Ag' di baris 2):
    if (currentParam !== '') {
        const matched = findMatchingXrfElement(xrfData.elements, currentParam);
        const concentrationVal = matched ? matched.concentration : '0.0000';
        
        if (nInput) nInput.value = concentrationVal;
        if (sSelect && matched && matched.unit) {
            for (let opt of sSelect.options) {
                if (opt.value.toLowerCase() === matched.unit.toLowerCase()) {
                    sSelect.value = opt.value;
                    break;
                }
            }
        }
        if (mSelect) mSelect.value = 'XRF';
        if (xHidden) xHidden.value = xrfData.id;

        if (btnXrf) {
            btnXrf.classList.add('loaded');
            if (xrfData.is_average) {
                btnXrf.innerHTML = `⚡ Avg (${currentParam}: ${concentrationVal})`;
                btnXrf.title = `Rata-rata ${xrfData.scan_count} scan. ${currentParam}: ${concentrationVal}`;
            } else {
                btnXrf.innerHTML = `⚡ ${escapeHtml(xrfData.sample_name)} (${currentParam}: ${concentrationVal})`;
                btnXrf.title = `Scan: ${xrfData.sample_name}. ${currentParam}: ${concentrationVal}`;
            }
        }

        updateUjiCount();
        return;
    }

    // Jika parameter pada baris masih kosong, gunakan unsur yang terdeteksi
    let elementsToApply = xrfData.elements || [];
    if (elementsToApply.length === 0) {
        alert('Tidak ada data unsur yang dapat dimuat dari scan XRF ini.');
        return;
    }

    const firstEl = elementsToApply[0];
    if (pInput) pInput.value = firstEl.element_name;
    if (nInput) nInput.value = firstEl.concentration;
    if (sSelect && firstEl.unit) {
        for (let opt of sSelect.options) {
            if (opt.value.toLowerCase() === firstEl.unit.toLowerCase()) {
                sSelect.value = opt.value;
                break;
            }
        }
    }
    if (mSelect) mSelect.value = 'XRF';
    if (xHidden) xHidden.value = xrfData.id;

    if (btnXrf) {
        btnXrf.classList.add('loaded');
        btnXrf.innerHTML = `⚡ ${escapeHtml(xrfData.sample_name)} (${firstEl.element_name}: ${firstEl.concentration})`;
    }

    updateUjiCount();
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Map wo_id → { sampelList, paramList, metode, ref }
const woDetailMap = <?= json_encode(
    array_reduce($woAktifList, function($carry, $wo) use ($pdo) {
        $st = $pdo->prepare("
            SELECT s.id, s.kode_sampel, s.jenis_material,
                   CONCAT(s.kode_sampel,
                       CASE WHEN rec.nomor_penerimaan IS NOT NULL
                             THEN CONCAT(' [', rec.nomor_penerimaan, ']')
                             ELSE '' END
                   ) AS label_lengkap,
                   rec.nomor_penerimaan
            FROM work_order_sampel wos
            JOIN sampel s ON wos.sampel_id = s.id
            LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
            WHERE wos.wo_id = ?
              AND s.id NOT IN (SELECT DISTINCT sampel_id FROM hasil_uji)
            ORDER BY s.kode_sampel
        ");
        $st->execute([$wo['id']]);
        $sampelList = $st->fetchAll(PDO::FETCH_ASSOC);

        $paramStr  = trim($wo['parameter'] ?? '');
        $paramList = $paramStr
            ? array_values(array_filter(array_map('trim', explode(',', $paramStr))))
            : [];

        $carry[$wo['id']] = [
            'sampelList' => $sampelList,
            'paramList'  => $paramList,
            'metode'     => $wo['metode'] ?? '',
            'ref'        => $wo['nomor_penerimaan'] ?? '',
            'nomor_wo'   => $wo['nomor_wo'],
        ];
        return $carry;
    }, [])
) ?>;

let ujiRowCnt = 0;

// ── Load dari WO: 1 baris per sampel sesuai urutan parameter WO ──
function loadBatchFromWo(sel) {
    currentBatchWoId = sel.value;
    document.getElementById('batchUjiRows').innerHTML = '';
    ujiRowCnt = 0;

    if (!currentBatchWoId) {
        activeWoTargetParams = [];
        updateUjiCount(); return;
    }

    const wo = woDetailMap[currentBatchWoId];
    if (!wo) {
        activeWoTargetParams = [];
        updateUjiCount(); return;
    }

    const paramList  = wo.paramList.length ? wo.paramList : [];
    activeWoTargetParams = paramList;
    const sampelList = wo.sampelList;
    const metode     = wo.metode || 'XRF';
    const ref        = wo.ref   || '';

    // Tampilkan banner info
    const paramInfoBox = document.getElementById('woBatchParamInfo');
    const paramInfoText = document.getElementById('woBatchParamText');
    const paramSummary = document.getElementById('woBatchSummaryText');

    if (paramInfoBox) {
        if (sampelList.length > 0) {
            paramInfoBox.style.display = 'block';
            if (paramInfoText) {
                paramInfoText.innerHTML = paramList.length > 0
                    ? `Target Parameter: ` + paramList.map(p => `<span class="param-tag" style="margin-right:4px">${p}</span>`).join('')
                    : `<span style="color:var(--text3)">Semua Parameter XRF</span>`;
            }
            if (paramSummary) {
                paramSummary.textContent = `${sampelList.length} Sampel termuat (${sampelList.length} baris). Klik "⚡ Pilih XRF" pada tiap baris untuk mengisi nilai parameter terkait.`;
            }
        } else {
            paramInfoBox.style.display = 'none';
        }
    }

    if (!sampelList.length) {
        alert('Semua sampel dalam WO ini sudah memiliki hasil uji.');
        updateUjiCount(); return;
    }

    // Buat 1 baris per sampel sesuai urutan parameter pada Work Order
    sampelList.forEach((s, idx) => {
        let assignedParam = '';
        if (paramList.length > 0) {
            if (idx < paramList.length) {
                assignedParam = paramList[idx];
            } else if (paramList.length === 1) {
                assignedParam = paramList[0];
            } else {
                assignedParam = paramList[idx % paramList.length];
            }
        }
        tambahBarisUji(s.id, ref, assignedParam, metode);
    });

    updateUjiCount();
}

function tambahBarisUji(sampelId='', batchRef='', paramDef='', metodeDef='', insertAfterEl=null, isChildOf=null) {
    ujiRowCnt++;
    const i = ujiRowCnt;
    const sOpts = sampelOpts.map(s =>
        `<option value="${s.id}" data-batch="${s.batch}" ${s.id==sampelId?'selected':''}>${s.label}</option>`
    ).join('');
    const mOpts = metOpts.map(m =>
        `<option ${m===metodeDef?'selected':''}>${m}</option>`
    ).join('');
    const satO  = satOpts.map(s => `<option>${s}</option>`).join('');

    let selectedSampleLabel = '';
    if (sampelId) {
        const found = sampelOpts.find(s => String(s.id) === String(sampelId));
        if (found) selectedSampleLabel = found.label;
    }

    const rowHtml = `<tr id="ubr${i}" ${isChildOf ? `data-xrf-parent="${isChildOf}" style="background:rgba(232,180,0,0.02)"` : ''}>
        <input type="hidden" name="rows[${i}][no_referensi]" id="ubr-refh-${i}" value="${batchRef}"/>
        <td style="padding:4px">
            ${isChildOf ? `
                <input type="hidden" name="rows[${i}][sampel_id]" value="${sampelId}"/>
                <div style="display:flex;align-items:center;gap:5px;font-size:.73rem;color:var(--text2);padding:4px 6px">
                    <span style="color:var(--gold);font-weight:bold;font-size:.85rem">↳</span>
                    <span style="opacity:.85;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px" title="${escapeHtml(selectedSampleLabel)}">${escapeHtml(selectedSampleLabel || 'Sampel')}</span>
                </div>
            ` : `
                <select name="rows[${i}][sampel_id]" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:4px 6px;border-radius:4px;font-size:.75rem;width:180px">
                    <option value="">— Pilih Sampel —</option>${sOpts}
                </select>
            `}
         </td>
        <td style="padding:4px">
            ${isChildOf ? `
                <div style="font-size:.68rem;color:var(--text3);padding:4px 6px;display:flex;align-items:center;gap:4px">
                    <span style="color:var(--gold);opacity:.7">↳</span> <span style="opacity:.7">Unsur XRF</span>
                </div>
                <input type="hidden" name="rows[${i}][xrf_id]" id="xrf-id-row-${i}" value=""/>
            ` : `
                <button type="button" id="btn-xrf-row-${i}" class="btn-outline-xrf" onclick="openXrfPickerModal(${i})" title="Klik untuk membuka pop-up pemilih data XRF">
                    ⚡ Pilih XRF
                </button>
                <input type="hidden" name="rows[${i}][xrf_id]" id="xrf-id-row-${i}" value=""/>
            `}
         </td>
        <td style="padding:4px"><input name="rows[${i}][parameter]" value="${paramDef}" placeholder="Au, Fe..." style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:4px 6px;border-radius:4px;font-size:.75rem;width:90px;${isChildOf ? 'border-left:3px solid var(--gold);' : ''}"/></td>
        <td style="padding:4px"><input type="number" step="any" name="rows[${i}][nilai]" placeholder="0.0000" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:4px 6px;border-radius:4px;font-size:.75rem;width:80px"/></td>
        <td style="padding:4px"><select name="rows[${i}][satuan]" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:4px 6px;border-radius:4px;font-size:.75rem">${satO}</select></td>
        <td style="padding:4px">
            <select name="rows[${i}][metode]" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:4px 6px;border-radius:4px;font-size:.75rem">
                ${mOpts}
            </select>
        </td>
        <td style="padding:4px">
            <select name="rows[${i}][kesimpulan]" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:4px 6px;border-radius:4px;font-size:.75rem">
                <option value="pending" selected>Pending</option>
                <option value="lulus">Lulus</option>
                <option value="tidak_lulus">Tidak Lulus</option>
            </select>
        </td>
        <td style="padding:4px">
            <button type="button" onclick="${isChildOf ? `document.getElementById('ubr${i}').remove();updateUjiCount()` : `document.querySelectorAll('tr[data-xrf-parent=\\'ubr${i}\\']').forEach(e=>e.remove());document.getElementById('ubr${i}').remove();updateUjiCount()`}"
               style="background:var(--red);color:#fff;border:none;border-radius:4px;padding:3px 8px;cursor:pointer;font-size:.7rem" title="Hapus baris">&#10005;</button>
        </td>
     </tr>`;

    if (insertAfterEl && insertAfterEl.parentNode) {
        insertAfterEl.insertAdjacentHTML('afterend', rowHtml);
    } else {
        document.getElementById('batchUjiRows').insertAdjacentHTML('beforeend', rowHtml);
    }

    return i;
}

function updateUjiCount() {
    document.getElementById('totalUjiBatch').textContent =
        document.getElementById('batchUjiRows').querySelectorAll('tr').length;
}

// ── EDIT HASIL UJI ────────────────────────────────────────────
function openEditModal(id) {
    fetch('<?= BASE_URL ?>/actions/get_hasil_uji.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_id').value = data.id;
                document.getElementById('edit_kode_uji').value = data.kode_uji;
                document.getElementById('edit_sampel').value = data.kode_sampel + ' - ' + data.jenis_material;
                document.getElementById('edit_parameter').value = data.parameter;
                document.getElementById('edit_nilai').value = data.nilai;
                document.getElementById('edit_satuan').value = data.satuan;
                document.getElementById('edit_metode').value = data.metode;
                document.getElementById('edit_alat_id').value = data.alat_id || '';
                document.getElementById('edit_analis_id').value = data.analis_id || '';
                document.getElementById('edit_tanggal_uji').value = data.tanggal_uji;
                document.getElementById('edit_kesimpulan').value = data.kesimpulan;
                document.getElementById('edit_catatan').value = data.catatan || '';
                
                document.getElementById('editModal').style.display = 'flex';
            } else {
                alert('Gagal mengambil data: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat mengambil data.');
        });
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Auto 1 baris kosong saat pertama load
tambahBarisUji();

// Tutup modal jika klik di luar
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('editModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
    }
    const xrfModal = document.getElementById('xrfPickerModal');
    if (xrfModal) {
        xrfModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeXrfPickerModal();
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
