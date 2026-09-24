<?php
// ============================================================
//  work_order.php — P3 Work Order & Penugasan Analis
//  UPDATE: WO mengacu Nomor Penerimaan (batch), bukan sampel_id
//          Relasi N sampel via tabel pivot work_order_sampel
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();
ensureWorkOrderSchema($pdo);

// Cek akses
if (!canAccessWorkOrder()) {
    $_SESSION['msg'] = 'ERROR: Anda tidak memiliki akses ke halaman Work Order.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Work Order';

$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$tab = $_GET['tab'] ?? 'daftar';

// ── Auto-generate nomor WO ────────────────────────────────────
$lastWo = $pdo->query("SELECT nomor_wo FROM work_order ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextWo = $lastWo ? (intval(substr($lastWo, -3)) + 1) : 1;
$noAuto = 'WO-' . date('ym') . '-' . str_pad($nextWo, 3, '0', STR_PAD_LEFT);

// ── Penerimaan (batch) yang belum punya WO aktif/draft ───────
// Satu penerimaan = satu WO batch. Tampilkan per batch, bukan per sampel.
$penerimaanAntri = $pdo->query("
    SELECT
        rec.id                  AS penerimaan_id,
        rec.nomor_penerimaan,
        rec.klien,
        rec.tanggal_terima,
        COUNT(s.id)             AS jumlah_sampel,
        GROUP_CONCAT(s.kode_sampel ORDER BY s.kode_sampel SEPARATOR ', ')         AS daftar_sampel,
        GROUP_CONCAT(DISTINCT s.jenis_material ORDER BY s.jenis_material SEPARATOR ', ') AS jenis_material
    FROM penerimaan_sampel rec
    JOIN sampel s ON s.penerimaan_id = rec.id
    WHERE s.status IN ('antrian','diuji')
      AND rec.id NOT IN (
          SELECT penerimaan_id FROM work_order
          WHERE status IN ('draft','aktif')
            AND penerimaan_id IS NOT NULL
      )
      AND s.id NOT IN (
          SELECT wos.sampel_id FROM work_order_sampel wos
          JOIN work_order wo ON wos.wo_id = wo.id
          WHERE wo.status IN ('draft','aktif')
      )
    GROUP BY rec.id
    ORDER BY rec.tanggal_terima ASC
")->fetchAll();

// ── Sampel individual (untuk mode single / tanpa batch) ──────
// Hanya sampel yang belum masuk pivot WO manapun yang aktif
$sampelSingle = $pdo->query("
    SELECT s.id, s.kode_sampel, s.jenis_material, s.klien,
           rec.nomor_penerimaan, rec.id AS penerimaan_id
    FROM sampel s
    LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
    WHERE s.status IN ('antrian','diuji')
      AND s.id NOT IN (
          SELECT wos.sampel_id FROM work_order_sampel wos
          JOIN work_order wo ON wos.wo_id = wo.id
          WHERE wo.status IN ('draft','aktif')
      )
    ORDER BY rec.nomor_penerimaan, s.kode_sampel
")->fetchAll();

// Kelompokkan sampel per batch untuk multi-select
$sampelSingleGrouped = [];
foreach ($sampelSingle as $s) {
    $grp = $s['nomor_penerimaan'] ?? 'Tanpa Batch';
    $sampelSingleGrouped[$grp][] = $s;
}

$analisList = $pdo->query(
    "SELECT id, nama FROM pengguna WHERE role IN('admin','analis') AND status='aktif'"
)->fetchAll();

$alatList = $pdo->query(
    "SELECT id, kode_alat, nama FROM peralatan WHERE status IN('tersedia','digunakan') ORDER BY nama"
)->fetchAll();

// ── Daftar WO dengan filter ───────────────────────────────────
// UPDATE: tidak JOIN sampel langsung, ambil info via pivot + penerimaan
$fStatus = $_GET['fstatus'] ?? 'aktif_draft';
$search  = trim($_GET['q'] ?? '');

$sqlWo = "
    SELECT w.*,
           rec.nomor_penerimaan,
           rec.klien            AS klien_batch,
           rec.tanggal_terima,
           COUNT(wos.sampel_id) AS jumlah_sampel,
           a.nama               AS nama_analis,
           p.nama               AS nama_alat,
           p.kode_alat
    FROM work_order w
    LEFT JOIN penerimaan_sampel rec ON w.penerimaan_id = rec.id
    LEFT JOIN work_order_sampel wos ON wos.wo_id = w.id
    LEFT JOIN pengguna a ON w.analis_id = a.id
    LEFT JOIN peralatan p ON w.peralatan_id = p.id
    WHERE 1=1";
$prmWo = [];
if ($fStatus === 'aktif_draft') {
    $sqlWo .= " AND w.status IN ('aktif','draft')";
} elseif ($fStatus && $fStatus !== 'semua') {
    $sqlWo .= " AND w.status=?";
    $prmWo[] = $fStatus;
}
if ($search) {
    $sqlWo .= " AND (w.nomor_wo LIKE ? OR rec.nomor_penerimaan LIKE ? OR rec.klien LIKE ?)";
    $prmWo  = array_merge($prmWo, ["%$search%", "%$search%", "%$search%"]);
}
$sqlWo .= "
    GROUP BY w.id
    ORDER BY
        FIELD(w.prioritas,'urgent','tinggi','normal'),
        FIELD(w.status,'aktif','draft','selesai','dibatalkan'),
        w.jadwal_mulai ASC";
$stWo   = $pdo->prepare($sqlWo);
$stWo->execute($prmWo);
$woList = $stWo->fetchAll();

// ── Pre-load sampel per WO untuk ditampilkan di kartu ────────
// Satu query sekaligus, kelompokkan di PHP
$woSampelMap = [];
if ($woList) {
    $woIds       = implode(',', array_column($woList, 'id'));
    $stWoSampel  = $pdo->query("
        SELECT wos.wo_id,
               s.kode_sampel, s.jenis_material
        FROM work_order_sampel wos
        JOIN sampel s ON wos.sampel_id = s.id
        WHERE wos.wo_id IN ($woIds)
        ORDER BY s.kode_sampel
    ");
    foreach ($stWoSampel->fetchAll() as $row) {
        $woSampelMap[$row['wo_id']][] = $row;
    }
}

// ── Statistik ─────────────────────────────────────────────────
$statDraft   = $pdo->query("SELECT COUNT(*) FROM work_order WHERE status='draft'")->fetchColumn();
$statAktif   = $pdo->query("SELECT COUNT(*) FROM work_order WHERE status='aktif'")->fetchColumn();
$statUrgent  = $pdo->query("SELECT COUNT(*) FROM work_order WHERE prioritas='urgent' AND status!='selesai'")->fetchColumn();
$statSelesai = $pdo->query("SELECT COUNT(*) FROM work_order WHERE status='selesai'")->fetchColumn();

$metodeOpts = ['AAS','XRF','ICP-OES','Gravimetri','Fire Assay','Volumetri'];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border);}
.tab-btn{padding:8px 18px;background:none;border:none;border-bottom:3px solid transparent;color:var(--text3);font-size:.82rem;cursor:pointer;font-weight:600;transition:.2s;margin-bottom:-1px;}
.tab-btn:hover{color:var(--text2);}
.tab-btn.active{color:var(--gold);border-bottom-color:var(--gold);}
.tab-pane{display:none;}.tab-pane.active{display:block;}
.stat-mini{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.smc{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 14px;}
.smc .v{font-size:1.5rem;font-weight:700;color:var(--green);}
.smc .v.gold{color:var(--gold);}.smc .v.red{color:var(--red);}.smc .v.yellow{color:var(--yellow);}
.smc .l{font-size:.7rem;color:var(--text3);margin-top:2px;}

/* WO Card */
.wo-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:0;margin-bottom:10px;overflow:hidden;}
.wo-header{display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--bg3);cursor:pointer;flex-wrap:wrap;}
.wo-header:hover{background:#162e20;}
.wo-no{font-size:.9rem;font-weight:700;color:var(--gold);font-family:monospace;}
.wo-body{display:none;padding:16px;border-top:1px solid var(--border);}
.wo-body.open{display:block;}
.wo-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;font-size:.8rem;}
.wo-field .lbl{font-size:.68rem;color:var(--text3);margin-bottom:2px;}
.wo-field .val{color:var(--text);}

/* Priority badges */
.pri-normal{background:#1a2e1a;color:#6aab7e;border:1px solid var(--green3);border-radius:10px;font-size:.65rem;padding:2px 8px;}
.pri-tinggi{background:#2e2000;color:var(--yellow);border:1px solid #5a4000;border-radius:10px;font-size:.65rem;padding:2px 8px;}
.pri-urgent{background:#3d1010;color:var(--red);border:1px solid #7a2020;border-radius:10px;font-size:.65rem;padding:2px 8px;animation:pulse 1.5s infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}

/* Ref & WO badges */
.ref-badge{display:inline-block;background:#1a2e0a;color:#a8d58a;border:1px solid #2e5018;border-radius:4px;font-size:.68rem;padding:1px 8px;font-family:monospace;}
.count-badge{display:inline-block;background:var(--bg3);color:var(--text2);border:1px solid var(--border);border-radius:10px;font-size:.65rem;padding:2px 8px;}

/* Sampel chips dalam WO card */
.sampel-chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:8px;}
.sampel-chip{background:#162e1a;color:var(--green);border:1px solid var(--green3);border-radius:12px;font-size:.65rem;padding:2px 8px;}

/* Timeline status */
.wo-timeline{display:flex;align-items:center;gap:0;margin:10px 0;}
.wt-step{display:flex;flex-direction:column;align-items:center;gap:4px;}
.wt-dot{width:18px;height:18px;border-radius:50%;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:.6rem;}
.wt-dot.done{background:var(--green3);border-color:var(--green3);color:#fff;}
.wt-dot.active{background:var(--gold2);border-color:var(--gold2);color:#fff;}
.wt-dot.pending{background:var(--bg3);}
.wt-label{font-size:.62rem;color:var(--text3);}
.wt-line{flex:1;height:2px;background:var(--border);margin:0 4px;margin-bottom:18px;}
.wt-line.done{background:var(--green3);}

/* Mode toggle di form buat WO */
.mode-toggle{display:flex;gap:8px;margin-bottom:16px;}
.mode-btn{padding:7px 18px;border-radius:6px;border:1px solid var(--border);background:var(--bg3);color:var(--text3);font-size:.78rem;cursor:pointer;font-weight:600;transition:.15s;}
.mode-btn.active{background:var(--gold2);border-color:var(--gold);color:#000;}

/* Preview sampel dalam form */
.sampel-preview{background:#0d2318;border:1px solid var(--green3);border-radius:6px;padding:10px 14px;margin-bottom:12px;display:none;}
.sampel-preview.show{display:block;}
.sampel-preview .sp-title{font-size:.7rem;color:var(--text3);margin-bottom:6px;}
.sp-row{display:flex;gap:14px;flex-wrap:wrap;}
.sp-item .lbl{font-size:.68rem;color:var(--text3);}
.sp-item .val{color:var(--gold);font-weight:700;font-size:.82rem;}
</style>

<div class="sec-title">Work Order &amp; Penugasan Analisis</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR') ? 'alert-red' : 'alert-green' ?>" style="margin-bottom:14px">
        <?= str_starts_with($msg,'ERROR') ? '&#9888;' : '&#10003;' ?> <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<div class="stat-mini">
    <div class="smc"><div class="v yellow"><?= $statDraft ?></div><div class="l">Draft / Belum Aktif</div></div>
    <div class="smc"><div class="v gold"><?= $statAktif ?></div><div class="l">Sedang Berjalan</div></div>
    <div class="smc"><div class="v red"><?= $statUrgent ?></div><div class="l">Prioritas Urgent</div></div>
    <div class="smc"><div class="v"><?= $statSelesai ?></div><div class="l">Selesai</div></div>
</div>

<div class="tabs">
    <button class="tab-btn <?= $tab==='daftar' ? 'active' : '' ?>" onclick="switchTab('daftar',this)">&#128203; Daftar Work Order</button>
    <button class="tab-btn <?= $tab==='buat'   ? 'active' : '' ?>" onclick="switchTab('buat',this)">&#10133; Buat Work Order</button>
    <button class="tab-btn <?= $tab==='jadwal' ? 'active' : '' ?>" onclick="switchTab('jadwal',this)">&#128197; Jadwal Instrumen</button>
</div>

<!-- ═══════════════════════════════════════════════════
     TAB 1: DAFTAR WO
═══════════════════════════════════════════════════ -->
<div id="tab-daftar" class="tab-pane <?= $tab==='daftar' ? 'active' : '' ?>">

    <form method="GET" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
        <input type="hidden" name="tab" value="daftar"/>
        <input name="q" value="<?= bersihkan($search) ?>"
               placeholder="&#128269; Cari nomor WO / nomor penerimaan / klien..."
               style="flex:1;background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:6px;font-size:.8rem;outline:none"/>
        <select name="fstatus" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:.8rem;outline:none">
            <option value="aktif_draft" <?= $fStatus==='aktif_draft' ? 'selected' : '' ?>>Aktif & Draft</option>
            <option value="semua" <?= $fStatus==='semua' ? 'selected' : '' ?>>Semua Status</option>
            <?php foreach (['draft','aktif','selesai','dibatalkan'] as $st): ?>
                <option value="<?= $st ?>" <?= $fStatus===$st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-green btn-sm">Cari</button>
    </form>

    <?php if (!$woList): ?>
        <div style="text-align:center;padding:30px;color:var(--text3)">Belum ada work order.</div>
    <?php endif; ?>

    <?php foreach ($woList as $w):
        $steps  = ['draft','aktif','selesai'];
        $curIdx = array_search($w['status'], $steps);
        $priClass = 'pri-' . $w['prioritas'];
        $sampelDalamWo = $woSampelMap[$w['id']] ?? [];
        $klienTampil   = $w['klien_batch'] ?? '—';
    ?>
    <div class="wo-card">
        <div class="wo-header" onclick="toggleWO(<?= $w['id'] ?>)">
            <span class="wo-no"><?= bersihkan($w['nomor_wo']) ?></span>
            <span class="<?= $priClass ?>"><?= strtoupper($w['prioritas']) ?></span>
            <?= badgeStatus($w['status']) ?>

            <!-- Referensi penerimaan sebagai identitas utama WO -->
            <?php if ($w['nomor_penerimaan']): ?>
                <span class="ref-badge">&#128230; <?= bersihkan($w['nomor_penerimaan']) ?></span>
            <?php endif; ?>

            <span class="count-badge">
                <?= $w['jumlah_sampel'] ?> sampel
            </span>

            <span style="color:var(--text3);font-size:.78rem">
                <?= bersihkan($klienTampil) ?>
            </span>

            <!-- Timeline mini -->
            <div class="wo-timeline" style="margin-left:auto;margin-bottom:0">
                <?php foreach ($steps as $idx => $step): ?>
                    <div class="wt-step">
                        <div class="wt-dot <?= $idx < $curIdx ? 'done' : ($idx === $curIdx ? 'active' : 'pending') ?>">
                            <?= $idx < $curIdx ? '&#10003;' : ($idx === $curIdx ? '&#9654;' : '') ?>
                        </div>
                        <span class="wt-label"><?= ucfirst($step) ?></span>
                    </div>
                    <?php if ($idx < count($steps) - 1): ?>
                        <div class="wt-line <?= $idx < $curIdx ? 'done' : '' ?>"></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <span style="color:var(--text3);font-size:.8rem;margin-left:8px" id="wo-ico-<?= $w['id'] ?>">&#9660;</span>
        </div>

        <div id="wo-body-<?= $w['id'] ?>" class="wo-body">

            <!-- Chips daftar sampel dalam WO ini (Sembunyikan jika sudah selesai) -->
            <?php if ($sampelDalamWo && $w['status'] !== 'selesai'): ?>
            <div style="margin-bottom:14px">
                <div style="font-size:.7rem;color:var(--text3);margin-bottom:6px">&#128300; Sampel dalam Work Order ini</div>
                <div class="sampel-chips">
                    <?php foreach ($sampelDalamWo as $sc): ?>
                        <span class="sampel-chip">
                            <?= bersihkan($sc['kode_sampel']) ?>
                            <small style="opacity:.7"><?= bersihkan($sc['jenis_material']) ?></small>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="wo-grid">
                <div class="wo-field">
                    <div class="lbl">Analis Ditugaskan</div>
                    <div class="val"><?= bersihkan($w['nama_analis'] ?? '&mdash; Belum ditugaskan') ?></div>
                </div>
                <div class="wo-field">
                    <div class="lbl">Instrumen / Alat</div>
                    <div class="val"><?= $w['kode_alat'] ? bersihkan($w['kode_alat'] . ' — ' . $w['nama_alat']) : '&mdash;' ?></div>
                </div>
                <div class="wo-field">
                    <div class="lbl">Metode</div>
                    <div class="val"><?= bersihkan($w['metode'] ?? '&mdash;') ?></div>
                </div>
                <div class="wo-field">
                    <div class="lbl">Parameter Uji</div>
                    <div class="val"><?= bersihkan($w['parameter'] ?? '&mdash;') ?></div>
                </div>
                <div class="wo-field">
                    <div class="lbl">Jadwal Mulai</div>
                    <div class="val"><?= $w['jadwal_mulai'] ? date('d/m/Y H:i', strtotime($w['jadwal_mulai'])) : '&mdash;' ?></div>
                </div>
                <div class="wo-field">
                    <div class="lbl">Jadwal Selesai</div>
                    <div class="val"><?= $w['jadwal_selesai'] ? date('d/m/Y H:i', strtotime($w['jadwal_selesai'])) : '&mdash;' ?></div>
                </div>
            </div>

            <?php if ($w['catatan']): ?>
                <div style="margin-top:10px;font-size:.78rem;color:var(--text3)">
                    &#128221; <?= bersihkan($w['catatan']) ?>
                </div>
            <?php endif; ?>

            <!-- Tombol aksi -->
            <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
                <?php if ($w['status'] === 'draft'): ?>
                    <form method="POST" action="<?= BASE_URL ?>/actions/simpan_work_order.php" style="display:inline">
                        <input type="hidden" name="action" value="aktivasi"/>
                        <input type="hidden" name="id"     value="<?= $w['id'] ?>"/>
                        <button type="submit" class="btn btn-gold btn-sm">&#9654; Aktifkan WO</button>
                    </form>
                <?php elseif ($w['status'] === 'aktif'): ?>
                    <a href="<?= BASE_URL ?>/pages/preparasi.php?wo_id=<?= $w['id'] ?>"
                       class="btn btn-green btn-sm">&#128300; Input Preparasi</a>
                    <a href="<?= BASE_URL ?>/pages/pengujian.php?wo_id=<?= $w['id'] ?>&tab=input"
                       class="btn btn-gold btn-sm">&#128202; Input Hasil Uji</a>
                    <form method="POST" action="<?= BASE_URL ?>/actions/simpan_work_order.php" style="display:inline">
                        <input type="hidden" name="action" value="selesaikan"/>
                        <input type="hidden" name="id"     value="<?= $w['id'] ?>"/>
                        <button type="submit" class="btn btn-sm" style="background:var(--green3);color:#fff">
                            &#10003; Tandai Selesai
                        </button>
                    </form>
                <?php elseif ($w['status'] === 'selesai'): ?>
                    <a href="<?= BASE_URL ?>/pages/qc.php?wo_id=<?= $w['id'] ?>"
                       class="btn btn-sm" style="background:var(--bg3);border:1px solid var(--border);color:var(--text2)">
                        &#128300; Lihat QC Report
                    </a>
                <?php endif; ?>

                <?php if ($w['status'] !== 'selesai'): ?>
                    <form method="POST" action="<?= BASE_URL ?>/actions/simpan_work_order.php" style="display:inline">
                        <input type="hidden" name="action" value="batalkan"/>
                        <input type="hidden" name="id"     value="<?= $w['id'] ?>"/>
                        <button type="submit" class="btn btn-red btn-sm"
                                onclick="return confirm('Batalkan work order ini?')">&#10005; Batalkan</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════
     TAB 2: BUAT WO
     Mode Batch: referensi ke nomor penerimaan
     Mode Single: pilih sampel spesifik (multi-select)
═══════════════════════════════════════════════════ -->
<div id="tab-buat" class="tab-pane <?= $tab==='buat' ? 'active' : '' ?>">
    <div class="grid2">
        <div class="card">
            <div class="card-title">&#10133; Buat Work Order Baru</div>

            <!-- Mode toggle -->
            <div class="mode-toggle">
                <button type="button" class="mode-btn active" id="modeBatchBtn" onclick="setMode('batch')">
                    &#128230; Batch (per Penerimaan)
                </button>
                <button type="button" class="mode-btn" id="modeSingleBtn" onclick="setMode('single')">
                    &#128300; Sampel Spesifik
                </button>
            </div>

            <form method="POST" action="<?= BASE_URL ?>/actions/simpan_work_order.php">
                <input type="hidden" name="action"  value="buat"/>
                <input type="hidden" name="mode_wo" id="hiddenModeWo" value="batch"/>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nomor WO</label>
                        <input name="nomor_wo" value="<?= $noAuto ?>" readonly/>
                    </div>
                    <div class="form-group">
                        <label>Prioritas</label>
                        <select name="prioritas">
                            <option value="normal">Normal</option>
                            <option value="tinggi">Tinggi</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>

                <!-- ── MODE BATCH: pilih nomor penerimaan ── -->
                <div id="panelBatch">
                    <div class="form-group" style="margin-bottom:10px">
                        <label>Nomor Penerimaan (Batch) <span style="color:var(--red)">*</span></label>
                        <select name="penerimaan_id" id="selPenerimaan" onchange="onPenerimaanChange(this)">
                            <option value="">— Pilih batch penerimaan —</option>
                            <?php foreach ($penerimaanAntri as $rec): ?>
                                <option value="<?= $rec['penerimaan_id'] ?>"
                                        data-klien="<?= bersihkan($rec['klien']) ?>"
                                        data-jml="<?= $rec['jumlah_sampel'] ?>"
                                        data-material="<?= bersihkan(substr($rec['jenis_material'], 0, 60)) ?>"
                                        data-sampel="<?= bersihkan(substr($rec['daftar_sampel'], 0, 120)) ?>"
                                        data-tgl="<?= $rec['tanggal_terima'] ?>">
                                    <?= bersihkan($rec['nomor_penerimaan']) ?>
                                    — <?= bersihkan($rec['klien']) ?>
                                    (<?= $rec['jumlah_sampel'] ?> sampel)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Preview info batch yang dipilih -->
                    <div class="sampel-preview" id="batchPreview">
                        <div class="sp-title">&#128230; Detail Batch yang Akan Dimasukkan ke WO</div>
                        <div class="sp-row">
                            <div class="sp-item"><div class="lbl">Klien</div><div class="val" id="bpKlien">—</div></div>
                            <div class="sp-item"><div class="lbl">Jumlah Sampel</div><div class="val" id="bpJml">—</div></div>
                            <div class="sp-item"><div class="lbl">Tgl Terima</div><div class="val" id="bpTgl">—</div></div>
                        </div>
                        <div style="margin-top:6px;font-size:.75rem;color:var(--text3)">
                            <span style="color:var(--text2)">Material:</span> <span id="bpMat">—</span>
                        </div>
                        <div style="margin-top:4px;font-size:.72rem;color:var(--text3)">
                            <span style="color:var(--text2)">Sampel:</span> <span id="bpSampel">—</span>
                        </div>
                    </div>
                </div>

                <!-- ── MODE SINGLE: pilih sampel spesifik ── -->
                <div id="panelSingle" style="display:none">
                    <div class="form-group" style="margin-bottom:10px">
                        <label>Sampel <span style="color:var(--red)">*</span>
                            <small style="color:var(--text3)">(Ctrl/Cmd untuk pilih lebih dari satu)</small>
                        </label>
                        <?php foreach ($sampelSingleGrouped as $grpName => $samples): ?>
                            <div style="font-size:.7rem;color:var(--text3);margin-top:8px;margin-bottom:4px">
                                &#128230; <?= bersihkan($grpName) ?>
                            </div>
                            <?php foreach ($samples as $s): ?>
                            <label style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:var(--bg3);border:1px solid var(--border);border-radius:5px;margin-bottom:4px;cursor:pointer;font-size:.78rem">
                                <input type="checkbox" name="sampel_ids[]" value="<?= $s['id'] ?>"
                                       style="accent-color:var(--gold);width:14px;height:14px"/>
                                <strong style="color:var(--gold)"><?= bersihkan($s['kode_sampel']) ?></strong>
                                — <?= bersihkan($s['jenis_material']) ?>
                                <span style="color:var(--text3);font-size:.7rem">(<?= bersihkan($s['klien']) ?>)</span>
                                <?php if ($s['nomor_penerimaan']): ?>
                                    <span class="ref-badge" style="margin-left:auto"><?= bersihkan($s['nomor_penerimaan']) ?></span>
                                <?php endif; ?>
                            </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php if (!$sampelSingle): ?>
                            <div style="color:var(--text3);font-size:.78rem;padding:10px;text-align:center">
                                Tidak ada sampel tersedia.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Field bersama untuk kedua mode -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Analis</label>
                        <select name="analis_id">
                            <option value="">— Belum ditugaskan —</option>
                            <?php foreach ($analisList as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= bersihkan($a['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Instrumen / Alat</label>
                        <select name="peralatan_id">
                            <option value="">— Pilih alat —</option>
                            <?php foreach ($alatList as $a): ?>
                                <option value="<?= $a['id'] ?>">
                                    <?= bersihkan($a['kode_alat']) ?> — <?= bersihkan($a['nama']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Metode Analisis</label>
                        <select name="metode">
                            <option value="">— Pilih —</option>
                            <?php foreach ($metodeOpts as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Parameter yang Diuji</label>
                        <input name="parameter" placeholder="Au, Ag, Cu, Fe... (pisah koma)"/>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Jadwal Mulai</label>
                        <input type="datetime-local" name="jadwal_mulai"
                               value="<?= date('Y-m-d\TH:i') ?>"/>
                    </div>
                    <div class="form-group">
                        <label>Jadwal Selesai</label>
                        <input type="datetime-local" name="jadwal_selesai"
                               value="<?= date('Y-m-d\TH:i', strtotime('+8 hours')) ?>"/>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:14px">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="butuh_preparasi" value="1" checked
                               style="width:16px;height:16px;accent-color:var(--gold)"/>
                        <span style="font-weight:600;color:var(--text)">Wajib Preparasi Sampel</span>
                        <small style="color:var(--text3)">(Uncheck jika sampel siap uji tanpa preparasi)</small>
                    </label>
                </div>
                <div class="form-group" style="margin-bottom:14px">
                    <label>Catatan / Instruksi Khusus</label>
                    <textarea name="catatan" rows="2" placeholder="Instruksi khusus untuk analis..."></textarea>
                </div>
                <div style="display:flex;gap:10px">
                    <button type="submit" name="status_awal" value="draft" class="btn btn-sm"
                            style="background:var(--bg3);border:1px solid var(--border);color:var(--text2)">
                        &#128196; Simpan sebagai Draft
                    </button>
                    <button type="submit" name="status_awal" value="aktif" class="btn btn-gold">
                        &#9654; Simpan &amp; Aktifkan
                    </button>
                </div>
            </form>
        </div>

        <!-- PANEL KANAN: daftar batch menunggu WO -->
        <div class="card">
            <div class="card-title">&#128230; Batch Penerimaan Menunggu WO</div>
            <?php if (!$penerimaanAntri): ?>
                <div style="text-align:center;padding:20px;color:var(--text3)">
                    Semua batch sudah memiliki work order.
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>No. Penerimaan</th>
                        <th>Klien</th>
                        <th>Jml Sampel</th>
                        <th>Material</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($penerimaanAntri as $rec): ?>
                <tr>
                    <td>
                        <span class="ref-badge">&#128230; <?= bersihkan($rec['nomor_penerimaan']) ?></span>
                    </td>
                    <td style="font-size:.78rem"><?= bersihkan($rec['klien']) ?></td>
                    <td style="text-align:center">
                        <span class="count-badge"><?= $rec['jumlah_sampel'] ?></span>
                    </td>
                    <td style="font-size:.72rem;color:var(--text3)">
                        <?= bersihkan(substr($rec['jenis_material'], 0, 40)) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ($sampelSingle): ?>
            <div style="margin-top:16px">
                <div class="card-title" style="font-size:.78rem">&#128300; Sampel Tanpa Batch / Belum Ada WO</div>
                <table>
                    <thead><tr><th>Kode</th><th>Material</th><th>Klien</th><th>Ref.</th></tr></thead>
                    <tbody>
                    <?php foreach ($sampelSingle as $s): ?>
                    <tr>
                        <td style="color:var(--gold)"><?= bersihkan($s['kode_sampel']) ?></td>
                        <td style="font-size:.75rem"><?= bersihkan($s['jenis_material']) ?></td>
                        <td style="font-size:.75rem"><?= bersihkan($s['klien']) ?></td>
                        <td>
                            <?php if ($s['nomor_penerimaan']): ?>
                                <span class="ref-badge"><?= bersihkan($s['nomor_penerimaan']) ?></span>
                            <?php else: ?>
                                <span style="color:var(--text3)">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     TAB 3: JADWAL INSTRUMEN
     UPDATE: JOIN via pivot, tampilkan jumlah sampel
═══════════════════════════════════════════════════ -->
<div id="tab-jadwal" class="tab-pane <?= $tab==='jadwal' ? 'active' : '' ?>">
    <div class="card">
        <div class="card-title">&#128197; Jadwal Penggunaan Instrumen — 7 Hari ke Depan</div>
        <?php
        $jadwalAlat = $pdo->query("
            SELECT w.id, w.nomor_wo, w.jadwal_mulai, w.jadwal_selesai, w.status, w.prioritas,
                   rec.nomor_penerimaan, rec.klien,
                   COUNT(wos.sampel_id) AS jumlah_sampel,
                   a.nama AS nama_analis,
                   p.kode_alat, p.nama AS nama_alat
            FROM work_order w
            LEFT JOIN penerimaan_sampel rec ON w.penerimaan_id = rec.id
            LEFT JOIN work_order_sampel wos ON wos.wo_id = w.id
            LEFT JOIN pengguna a ON w.analis_id = a.id
            LEFT JOIN peralatan p ON w.peralatan_id = p.id
            WHERE w.jadwal_mulai BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
              AND w.status IN ('draft','aktif')
            GROUP BY w.id
            ORDER BY p.kode_alat, w.jadwal_mulai
        ")->fetchAll();
        ?>
        <?php if (!$jadwalAlat): ?>
            <div style="text-align:center;padding:20px;color:var(--text3)">
                Tidak ada jadwal dalam 7 hari ke depan.
            </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Instrumen</th>
                    <th>No. WO</th>
                    <th>No. Referensi Batch</th>
                    <th>Klien</th>
                    <th>Jml Sampel</th>
                    <th>Analis</th>
                    <th>Jadwal Mulai</th>
                    <th>Jadwal Selesai</th>
                    <th>Prioritas</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jadwalAlat as $j): ?>
            <tr>
                <td style="font-weight:700;color:var(--gold)"><?= bersihkan($j['kode_alat'] ?? '&mdash;') ?></td>
                <td style="font-family:monospace;font-size:.78rem"><?= bersihkan($j['nomor_wo']) ?></td>
                <td>
                    <?php if ($j['nomor_penerimaan']): ?>
                        <span class="ref-badge">&#128230; <?= bersihkan($j['nomor_penerimaan']) ?></span>
                    <?php else: ?>
                        <span style="color:var(--text3)">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.78rem"><?= bersihkan($j['klien'] ?? '—') ?></td>
                <td style="text-align:center">
                    <span class="count-badge"><?= $j['jumlah_sampel'] ?></span>
                </td>
                <td><?= bersihkan($j['nama_analis'] ?? '&mdash;') ?></td>
                <td><?= date('d/m H:i', strtotime($j['jadwal_mulai'])) ?></td>
                <td><?= date('d/m H:i', strtotime($j['jadwal_selesai'])) ?></td>
                <td><span class="pri-<?= $j['prioritas'] ?>"><?= strtoupper($j['prioritas']) ?></span></td>
                <td><?= badgeStatus($j['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ── TAB ───────────────────────────────────────────────────────
function switchTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    if (el) el.classList.add('active');
    history.replaceState(null, '', '?tab=' + name);
}

function toggleWO(id) {
    const body = document.getElementById('wo-body-' + id);
    const ico  = document.getElementById('wo-ico-' + id);
    const open = body.classList.toggle('open');
    if (ico) ico.innerHTML = open ? '&#9650;' : '&#9660;';
}

// ── Mode toggle: Batch vs Single ──────────────────────────────
function setMode(mode) {
    const isBatch = (mode === 'batch');
    document.getElementById('panelBatch').style.display  = isBatch ? '' : 'none';
    document.getElementById('panelSingle').style.display = isBatch ? 'none' : '';
    document.getElementById('hiddenModeWo').value = mode;
    document.getElementById('modeBatchBtn').classList.toggle('active', isBatch);
    document.getElementById('modeSingleBtn').classList.toggle('active', !isBatch);
    if (!isBatch) {
        // Reset preview saat pindah ke mode single
        document.getElementById('batchPreview').classList.remove('show');
    }
}

// ── Preview info batch saat penerimaan dipilih ────────────────
function onPenerimaanChange(sel) {
    const opt     = sel.options[sel.selectedIndex];
    const preview = document.getElementById('batchPreview');
    if (!sel.value) {
        preview.classList.remove('show');
        return;
    }
    document.getElementById('bpKlien').textContent  = opt.dataset.klien   || '—';
    document.getElementById('bpJml').textContent    = opt.dataset.jml + ' sampel';
    document.getElementById('bpTgl').textContent    = opt.dataset.tgl
        ? new Date(opt.dataset.tgl).toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'numeric'})
        : '—';
    document.getElementById('bpMat').textContent    = opt.dataset.material || '—';
    document.getElementById('bpSampel').textContent = opt.dataset.sampel   || '—';
    preview.classList.add('show');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
