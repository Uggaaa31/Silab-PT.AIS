<?php
// ============================================================
//  qc.php — P2 QC & Validasi Hasil Analisis
//  UPDATE: Role-based access - Analis full, Admin/Supervisor read only
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

// Cek akses
if (!canAccessQC()) {
    $_SESSION['msg'] = 'ERROR: Anda tidak memiliki akses ke halaman QC.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'QC & Validasi';
$isEditable = canEditQC(); // Analis, Supervisor, dan Admin bisa edit/input
$isReadOnly = !$isEditable;

$msg  = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$tab  = $_GET['tab']    ?? 'dashboard';
$woId = (int)($_GET['wo_id'] ?? 0);

// ── Data QC keseluruhan ──────────────────────────────────────
$qcList = $pdo->query("
    SELECT q.*,
           s.kode_sampel, s.jenis_material, s.klien,
           w.nomor_wo,
           r.nama AS nama_reviewer
    FROM qc_sampel q
    JOIN sampel s ON q.sampel_id = s.id
    LEFT JOIN preparasi_sampel pr ON q.preparasi_id = pr.id
    LEFT JOIN work_order w ON pr.work_order_id = w.id
    LEFT JOIN pengguna r ON q.reviewer_id = r.id
    WHERE NOT (q.tipe_qc = 'standar' AND q.nilai_qc IS NULL AND q.preparasi_id IS NULL)
    ORDER BY q.created_at DESC
")->fetchAll();

$qcManajemenRows = [];
$qcManajemenData = $pdo->query("
    SELECT q.sampel_id, q.parameter, q.nilai_expected
    FROM qc_sampel q
    WHERE q.tipe_qc = 'standar'
      AND q.nilai_qc IS NULL
      AND q.preparasi_id IS NULL
    ORDER BY q.created_at DESC
")->fetchAll();
foreach ($qcManajemenData as $m) {
    $qcManajemenRows[$m['sampel_id']] = [
        'parameter'      => $m['parameter'],
        'nilai_expected' => $m['nilai_expected'],
    ];
}

// ── Filter per WO ─────────────────────────────────────────────
$woFilter  = null;
if ($woId) {
    $stWoF = $pdo->prepare(
        "SELECT w.*, s.kode_sampel, s.jenis_material
         FROM work_order w JOIN sampel s ON w.sampel_id=s.id WHERE w.id=?"
    );
    $stWoF->execute([$woId]); $woFilter = $stWoF->fetch();
}

// ── Statistik QC ─────────────────────────────────────────────
$qcPass    = count(array_filter($qcList, fn($q) => $q['flag'] === 'pass'));
$qcFail    = count(array_filter($qcList, fn($q) => $q['flag'] === 'fail'));
$qcWarn    = count(array_filter($qcList, fn($q) => $q['flag'] === 'warning'));
$qcPending = count(array_filter($qcList, fn($q) => $q['status_qc'] === 'pending'));
$total     = count($qcList);

// ── Sampel dengan preparasi (untuk form input QC) ────────────
$sampelDenganPrep = $pdo->query("
    SELECT pr.id AS prep_id, pr.sampel_id, pr.faktor_pengenceran,
           pr.blanko_disiapkan, pr.standar_disiapkan,
           pr.spike_disiapkan, pr.duplikat_disiapkan,
           s.kode_sampel, s.jenis_material, w.nomor_wo
    FROM preparasi_sampel pr
    JOIN sampel s ON pr.sampel_id = s.id
    LEFT JOIN work_order w ON pr.work_order_id = w.id
    ORDER BY pr.created_at DESC
")->fetchAll();

$manajemenSamples = $pdo->query("
    SELECT NULL AS prep_id, q.sampel_id, NULL AS faktor_pengenceran,
           NULL AS blanko_disiapkan, NULL AS standar_disiapkan,
           NULL AS spike_disiapkan, NULL AS duplikat_disiapkan,
           s.kode_sampel, s.jenis_material, NULL AS nomor_wo
    FROM qc_sampel q
    JOIN sampel s ON q.sampel_id = s.id
    WHERE q.tipe_qc = 'standar'
      AND q.nilai_qc IS NULL
      AND q.preparasi_id IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM preparasi_sampel pr WHERE pr.sampel_id = q.sampel_id
      )
    ORDER BY q.created_at DESC
")->fetchAll();

$sampelDenganPrep = array_merge($sampelDenganPrep, $manajemenSamples);

$analisList = $pdo->query(
    "SELECT id, nama FROM pengguna WHERE role IN('admin','analis') AND status='aktif'"
)->fetchAll();
$supervisorList = $pdo->query(
    "SELECT id, nama FROM pengguna WHERE role='admin' AND status='aktif'"
)->fetchAll();

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
.smc .v.red{color:var(--red);}.smc .v.yellow{color:var(--yellow);}.smc .v.gold{color:var(--gold);}
.smc .l{font-size:.7rem;color:var(--text3);margin-top:2px;}
.flag-pass{background:#1a4d2e;color:#2ecc71;border-radius:10px;font-size:.68rem;padding:2px 9px;font-weight:600;}
.flag-fail{background:#3d1010;color:#e74c3c;border-radius:10px;font-size:.68rem;padding:2px 9px;font-weight:600;}
.flag-warn{background:#3d2e00;color:#f39c12;border-radius:10px;font-size:.68rem;padding:2px 9px;font-weight:600;}
.rec-bar-wrap{width:100px;background:#1a3525;border-radius:4px;height:8px;overflow:hidden;display:inline-block;vertical-align:middle;}
.rec-bar{height:100%;border-radius:4px;}
.card form{display:grid;gap:14px;}
.card form .form-group{margin:0;}
.card form .form-group input{width:100%;}
.tipe-blanko  {background:#0d2a3d;color:#3498db;border-radius:8px;font-size:.65rem;padding:1px 7px;}
.tipe-standar {background:#1a2e0a;color:#8acd40;border-radius:8px;font-size:.65rem;padding:1px 7px;}
.tipe-spike   {background:#2e1a00;color:#e89c30;border-radius:8px;font-size:.65rem;padding:1px 7px;}
.tipe-duplikat{background:#2a0a2e;color:#b56fd8;border-radius:8px;font-size:.65rem;padding:1px 7px;}
.readonly-badge{background:#f39c12;color:#1a1a2e;padding:4px 12px;border-radius:20px;font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:6px;}
</style>

<div class="sec-title">QC &amp; Validasi Hasil Analisis</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR')?'alert-red':'alert-green' ?>" style="margin-bottom:14px">
        <?= str_starts_with($msg,'ERROR')?'&#9888;':'&#10003;' ?> <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<?php if ($isReadOnly): ?>
<div class="alert-box alert-yellow" style="margin-bottom:14px">
    🔒 <strong>Mode Read Only</strong> - Anda hanya dapat melihat data. Untuk mengedit, login sebagai Analis.
</div>
<?php endif; ?>

<?php if ($woFilter): ?>
    <div class="alert-box alert-green" style="margin-bottom:14px">
        &#128300; Menampilkan QC untuk WO <strong><?= bersihkan($woFilter['nomor_wo']) ?></strong>
        — <?= bersihkan($woFilter['kode_sampel']) ?> (<?= bersihkan($woFilter['jenis_material']) ?>)
    </div>
<?php endif; ?>

<div class="stat-mini">
    <div class="smc"><div class="v"><?= $qcPass ?></div><div class="l">&#10003; Pass QC</div></div>
    <div class="smc"><div class="v red"><?= $qcFail ?></div><div class="l">&#10007; Fail QC</div></div>
    <div class="smc"><div class="v yellow"><?= $qcWarn ?></div><div class="l">&#9888; Warning</div></div>
    <div class="smc"><div class="v gold"><?= $qcPending ?></div><div class="l">&#8987; Menunggu Review</div></div>
</div>

<div class="tabs">
    <button class="tab-btn <?= $tab==='dashboard'?'active':'' ?>" onclick="switchTab('dashboard',this)">&#128202; QC Dashboard</button>
    <button class="tab-btn <?= $tab==='manajemen'?'active':'' ?>" onclick="switchTab('manajemen',this)">&#128221; Manajemen Sampel QC</button>
    <button class="tab-btn <?= $tab==='input'?'active':'' ?>"     onclick="switchTab('input',this)" <?= $isReadOnly ? 'disabled' : '' ?>>&#10133; Input Data QC</button>
    <?php if (isSupervisor()): ?>
    <button class="tab-btn <?= $tab==='review'?'active':'' ?>"    onclick="switchTab('review',this)">
        &#128269; Review Supervisor
        <?php if ($qcPending > 0): ?><span class="badge-alert" style="margin-left:4px"><?= $qcPending ?></span><?php endif; ?>
    </button>
    <?php endif; ?>
</div>

<!-- ── TAB 1: DASHBOARD QC ──────────────────────────────── -->
<div id="tab-dashboard" class="tab-pane <?= $tab==='dashboard'?'active':'' ?>">
    <div class="card" style="margin-bottom:16px">
        <div class="card-title">&#128300; Semua Data QC</div>
        <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                    <th>No. WO</th><th>Sampel</th><th>Tipe QC</th>
                    <th>Parameter</th><th>Nilai Terukur</th><th>Nilai Expected</th>
                    <th>% Recovery</th><th>Batas QC</th><th>Flag</th>
                    <th>Status Review</th><th>Tanggal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($qcList as $q):
                $recPct = $q['persen_recovery'];
                $barW   = $recPct ? min(100, $recPct) : 0;
                $barCol = $q['flag']==='pass' ? 'var(--green3)' : ($q['flag']==='warning' ? 'var(--yellow)' : 'var(--red)');
            ?>
            <tr>
                <td style="font-family:monospace;font-size:.72rem"><?= bersihkan($q['nomor_wo'] ?? '&mdash;') ?></td>
                <td style="color:var(--gold)"><?= bersihkan($q['kode_sampel']) ?></td>
                <td><span class="tipe-<?= $q['tipe_qc'] ?>"><?= ucfirst($q['tipe_qc']) ?></span></td>
                <td><?= bersihkan($q['parameter'] ?? '&mdash;') ?></td>
                <td><strong><?= $q['nilai_qc'] ?? '&mdash;' ?></strong> <?= bersihkan($q['satuan'] ?? '') ?></td>
                <td><?= $q['nilai_expected'] ?? '&mdash;' ?></td>
                <td>
                    <?php if ($recPct !== null): ?>
                        <span style="font-size:.78rem"><?= number_format($recPct, 1) ?>%</span>
                        <div class="rec-bar-wrap">
                            <div class="rec-bar" style="width:<?= $barW ?>%;background:<?= $barCol ?>"></div>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--text3)">N/A</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.72rem;color:var(--text3)">
                    <?= $q['batas_min_pct'] ?>% – <?= $q['batas_maks_pct'] ?>%
                </td>
                <td><span class="flag-<?= $q['flag'] ?>"><?= strtoupper($q['flag']) ?></span></td>
                <td><?= badgeStatus($q['status_qc']) ?></td>
                <td><?= fmtTgl($q['tanggal_uji']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$qcList): ?>
                <tr><td colspan="11" style="text-align:center;color:var(--text3);padding:20px">Belum ada data QC.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="grid2">
        <?php
        $tipes = ['blanko','standar','spike','duplikat'];
        foreach (array_chunk($tipes, 2) as $chunk):
        ?>
        <?php foreach ($chunk as $tipe):
            $data = array_filter($qcList, fn($q) => $q['tipe_qc'] === $tipe);
            $tPass = count(array_filter($data, fn($q) => $q['flag']==='pass'));
            $tFail = count(array_filter($data, fn($q) => $q['flag']==='fail'));
            $tWarn = count(array_filter($data, fn($q) => $q['flag']==='warning'));
            $tTotal= count($data);
            $avgRec= $tTotal > 0
                ? array_sum(array_column(array_filter($data, fn($q)=>$q['persen_recovery']!==null), 'persen_recovery')) / max(1,count(array_filter($data, fn($q)=>$q['persen_recovery']!==null)))
                : null;
        ?>
        <div class="card">
            <div class="card-title">
                <span class="tipe-<?= $tipe ?>"><?= ucfirst($tipe) ?></span>
                <span><?= $tTotal ?> data</span>
            </div>
            <div style="display:flex;gap:16px;font-size:.8rem">
                <span style="color:var(--green)">&#10003; <?= $tPass ?> Pass</span>
                <span style="color:var(--red)">&#10007; <?= $tFail ?> Fail</span>
                <span style="color:var(--yellow)">&#9888; <?= $tWarn ?> Warn</span>
                <?php if ($avgRec !== null): ?>
                    <span style="color:var(--text3)">Avg Recovery: <strong style="color:var(--gold)"><?= number_format($avgRec,1) ?>%</strong></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── TAB 2: MANAJEMEN SAMPEL QC ──────────────────────── -->
<div id="tab-manajemen" class="tab-pane <?= $tab==='manajemen'?'active':'' ?>">
    <div class="card">
        <div class="card-title">&#128221; Manajemen Sampel QC</div>
        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_qc.php" style="display:grid;gap:14px">
            <input type="hidden" name="action" value="manage" />
            <div class="form-group">
                <label>ID / Kode Sampel</label>
                <?php 
                $listSampel = $pdo->query("SELECT kode_sampel, jenis_material FROM sampel ORDER BY id DESC")->fetchAll();
                ?>
                <select name="manajemen_id_sampel" <?= $isReadOnly ? 'disabled' : '' ?>>
                    <option value="">-- Pilih Sampel --</option>
                    <?php foreach($listSampel as $ls): ?>
                        <option value="<?= bersihkan($ls['kode_sampel']) ?>"><?= bersihkan($ls['kode_sampel']) ?> (<?= bersihkan($ls['jenis_material']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Nilai Sertifikat</label>
                <input type="number" step="any" name="manajemen_nilai_sertifikat" placeholder="Masukkan nilai sertifikat (contoh: 10.05)" <?= $isReadOnly ? 'readonly disabled' : '' ?> />
            </div>
            <div class="form-group">
                <label>Parameter</label>
                <input name="manajemen_parameter" placeholder="Masukkan parameter" <?= $isReadOnly ? 'readonly disabled' : '' ?> />
            </div>
            <?php if (!$isReadOnly): ?>
                <button type="submit" class="btn btn-gold" style="justify-self:start">&#128190; Simpan Sampel QC</button>
            <?php else: ?>
                <div class="readonly-badge" style="display:inline-flex;margin-top:8px">🔒 Mode Read Only - Tidak dapat menyimpan</div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── TAB 2: INPUT QC ──────────────────────────────────── -->
<div id="tab-input" class="tab-pane <?= $tab==='input'?'active':'' ?>">
    <div class="card">
        <div class="card-title">&#10133; Input Data QC</div>
        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_qc.php">

            <div class="form-row">
                <div class="form-group">
                    <label>Sampel / Preparasi <span style="color:var(--red)">*</span></label>
                    <!-- Searchable sampel wrapper -->
                    <div style="position:relative" id="sampelSearchWrapper">
                        <input type="text" id="sampelSearchInput"
                               placeholder="&#128269; Cari kode sampel / jenis material / nomor WO..."
                               style="width:100%;box-sizing:border-box;background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px 6px 0 0;font-size:.82rem;outline:none"
                               <?= $isReadOnly ? 'readonly disabled' : '' ?>
                               oninput="filterSampelOptions()"
                               autocomplete="off"/>
                        <select name="preparasi_id" id="sampelSelect"
                                size="1"
                                style="width:100%;box-sizing:border-box;border-radius:0 0 6px 6px;border-top:none"
                                <?= $isReadOnly ? 'disabled' : '' ?> required
                                onchange="syncInputFromSelectedSample()">
                            <option value="" data-sampel="" data-label="">— Pilih Sampel —</option>
                            <?php foreach ($sampelDenganPrep as $p): ?>
                                <?php
                                $label = bersihkan($p['kode_sampel'])
                                    . ($p['jenis_material'] && $p['jenis_material'] !== 'unknown' ? ' — '.bersihkan($p['jenis_material']) : '')
                                    . ($p['nomor_wo'] ? ' ['.bersihkan($p['nomor_wo']).']' : '')
                                    . ($p['prep_id'] === null ? ' ✦ Manajemen QC' : '');
                                ?>
                                <option value="<?= $p['prep_id'] !== null ? $p['prep_id'] : 'man_'.intval($p['sampel_id']) ?>"
                                        data-sampel="<?= $p['sampel_id'] ?>"
                                        data-faktor="<?= $p['faktor_pengenceran'] ?>"
                                        data-blanko="<?= $p['blanko_disiapkan'] ?>"
                                        data-standar="<?= $p['standar_disiapkan'] ?>"
                                        data-spike="<?= $p['spike_disiapkan'] ?>"
                                        data-duplikat="<?= $p['duplikat_disiapkan'] ?>"
                                        data-label="<?= htmlspecialchars($label, ENT_QUOTES) ?>">
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Tipe QC <span style="color:var(--red)">*</span></label>
                    <select name="tipe_qc" id="tipeQc" onchange="updateTipeHelp()" <?= $isReadOnly ? 'disabled' : '' ?> required>
                        <option value="blanko">Blanko</option>
                        <option value="standar">Standar Bersertifikat</option>
                        <option value="spike">Spike / Fortifikasi</option>
                        <option value="duplikat">Duplikat</option>
                    </select>
                </div>
            </div>

            <div id="tipeHelp" style="background:var(--bg3);border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:.75rem;color:var(--text3)">
                <strong style="color:var(--gold)">Blanko:</strong> Sampel tanpa analit untuk mendeteksi kontaminasi.
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Parameter</label>
                    <input name="parameter" placeholder="Au, Cu, Ni..." <?= $isReadOnly ? 'readonly disabled' : '' ?>/>
                </div>
                <div class="form-group">
                    <label>Satuan</label>
                    <select name="satuan" <?= $isReadOnly ? 'disabled' : '' ?>>
                        <?php foreach (['g/t','%','mg/L','ppm','ppb'] as $u): ?><option><?= $u ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Nilai Terukur (dari instrumen) <span style="color:var(--red)">*</span></label>
                    <input type="number" step="any" name="nilai_qc"
                           id="nilaiQcInput" placeholder="0.0000" <?= $isReadOnly ? 'readonly disabled' : '' ?> required oninput="hitungRecovery()"/>
                </div>
                <div class="form-group">
                    <label>Nilai Expected / Referensi
                        <span id="expSourceBadge" style="display:none;font-size:.68rem;background:var(--green3);color:#000;padding:1px 6px;border-radius:20px;margin-left:4px">&#10003; dari Manajemen QC</span>
                    </label>
                    <input type="number" step="any" min="0" name="nilai_expected"
                           id="nilaiExpInput" placeholder="Otomatis dari Manajemen QC"
                           readonly
                           style="background:var(--bg2);color:var(--text3);cursor:not-allowed"/>
                    <small id="expHint" style="font-size:.7rem;color:var(--text3);margin-top:3px;display:block">&#128274; Nilai Expected hanya bisa diisi lewat tab <strong>Manajemen Sampel QC</strong>.</small>
                </div>
            </div>

            <div id="recoveryPreview" style="display:none;background:#0d2318;border:1px solid var(--green3);border-radius:6px;padding:10px 14px;margin-bottom:12px">
                <div style="font-size:.75rem;color:var(--text3);margin-bottom:4px">% Recovery Otomatis</div>
                <div style="display:flex;align-items:center;gap:12px">
                    <span id="recVal" style="font-size:1.2rem;font-weight:700;color:var(--gold)">—</span>
                    <div id="recBar" style="flex:1;background:#1a3525;border-radius:4px;height:10px;overflow:hidden">
                        <div id="recBarFill" style="height:100%;background:var(--green3);transition:.3s"></div>
                    </div>
                    <span id="recFlag" class="flag-pass">PASS</span>
                </div>
            </div>

            <div class="form-row" style="display:none;">
                <div class="form-group">
                    <label>Batas Min Recovery (%)</label>
                    <input type="number" step="0.01" name="batas_min_pct" id="bMinPct" value="85" placeholder="85" <?= $isReadOnly ? 'readonly disabled' : '' ?>/>
                </div>
                <div class="form-group">
                    <label>Batas Maks Recovery (%)</label>
                    <input type="number" step="0.01" name="batas_maks_pct" id="bMaksPct" value="115" placeholder="115" <?= $isReadOnly ? 'readonly disabled' : '' ?>/>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Tanggal Uji</label>
                    <input type="date" name="tanggal_uji" value="<?= date('Y-m-d') ?>" <?= $isReadOnly ? 'readonly disabled' : '' ?> required/>
                </div>
                <div class="form-group">
                    <label>Catatan</label>
                    <input name="catatan_review" placeholder="Catatan opsional..." <?= $isReadOnly ? 'readonly disabled' : '' ?>/>
                </div>
            </div>

            <?php if ($isEditable): ?>
                <button type="submit" class="btn btn-gold">&#128190; Simpan Data QC</button>
            <?php else: ?>
                <div class="readonly-badge" style="display:inline-block;margin-top:14px">
                    🔒 Mode Read Only - Anda tidak dapat menyimpan data
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── TAB 3: REVIEW SUPERVISOR ─────────────────────────── -->
<div id="tab-review" class="tab-pane <?= $tab==='review'?'active':'' ?>">
<?php if (!isSupervisor()): ?>
    <div class="card" style="text-align:center;padding:40px;color:var(--text3)">
        <div style="font-size:2.5rem;margin-bottom:12px">&#128274;</div>
        <div style="font-size:1rem;font-weight:600;color:var(--red)">Akses Ditolak</div>
        <div style="font-size:.85rem;margin-top:6px">Tab ini hanya dapat diakses oleh <strong>Supervisor</strong>.</div>
    </div>
<?php else: ?>
    <?php
    $pending = array_filter($qcList, fn($q) => $q['status_qc'] === 'pending');
    $reviewed = array_filter($qcList, fn($q) => $q['status_qc'] !== 'pending');
    ?>
    <?php if ($pending): ?>
    <div class="card" style="margin-bottom:16px">
        <div class="card-title">&#9888; Menunggu Review <span><?= count($pending) ?> data</span></div>
        <?php foreach ($pending as $q): ?>
        <div style="background:var(--bg3);border:1px solid <?= $q['flag']==='fail'?'var(--red)':'var(--border)' ?>;border-radius:8px;padding:14px;margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
                <div>
                    <span class="tipe-<?= $q['tipe_qc'] ?>"><?= ucfirst($q['tipe_qc']) ?></span>
                    &nbsp;<strong style="color:var(--gold)"><?= bersihkan($q['kode_sampel']) ?></strong>
                    &nbsp;<span style="color:var(--text3)"><?= bersihkan($q['parameter'] ?? '') ?></span>
                    &nbsp;<span class="flag-<?= $q['flag'] ?>"><?= strtoupper($q['flag']) ?></span>
                    <br>
                    <small style="color:var(--text3)">
                        Terukur: <strong><?= $q['nilai_qc'] ?></strong> |
                        Expected: <strong><?= $q['nilai_expected'] ?? 'N/A' ?></strong> |
                        Recovery: <strong style="color:<?= $q['flag']==='pass'?'var(--green)':'var(--red)' ?>">
                            <?= $q['persen_recovery'] !== null ? number_format($q['persen_recovery'],1).'%' : 'N/A' ?>
                        </strong>
                    </small>
                </div>
                <form method="POST" action="<?= BASE_URL ?>/actions/simpan_qc.php" style="display:flex;gap:8px;align-items:center">
                    <input type="hidden" name="action" value="review"/>
                    <input type="hidden" name="qc_id" value="<?= $q['id'] ?>"/>
                    <select name="reviewer_id" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:5px 8px;border-radius:5px;font-size:.75rem;outline:none">
                        <?php foreach ($supervisorList as $sv): ?>
                            <option value="<?= $sv['id'] ?>"><?= bersihkan($sv['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="catatan_review" placeholder="Catatan review..."
                           style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:5px 8px;border-radius:5px;font-size:.75rem;outline:none;width:160px"/>
                    <button type="submit" name="keputusan" value="disetujui" class="btn btn-green btn-sm">&#10003; Setujui</button>
                    <button type="submit" name="keputusan" value="ditolak"   class="btn btn-red btn-sm">&#10007; Tolak</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="alert-box alert-green" style="margin-bottom:14px">&#10003; Semua data QC sudah direview.</div>
    <?php endif; ?>

    <?php if ($reviewed): ?>
    <div class="card">
        <div class="card-title">&#128203; Riwayat Review <span><?= count($reviewed) ?> data</span></div>
        <table class="data-table">
            <thead>
                <tr><th>Sampel</th><th>Tipe</th><th>Flag</th><th>Recovery</th><th>Status</th><th>Reviewer</th><th>Catatan</th></tr>
            </thead>
            <tbody>
            <?php foreach ($reviewed as $q): ?>
            <tr>
                <td style="color:var(--gold)"><?= bersihkan($q['kode_sampel']) ?></td>
                <td><span class="tipe-<?= $q['tipe_qc'] ?>"><?= ucfirst($q['tipe_qc']) ?></span></td>
                <td><span class="flag-<?= $q['flag'] ?>"><?= strtoupper($q['flag']) ?></span></td>
                <td><?= $q['persen_recovery'] !== null ? number_format($q['persen_recovery'],1).'%' : '&mdash;' ?></td>
                <td><?= badgeStatus($q['status_qc']) ?></td>
                <td style="font-size:.75rem"><?= bersihkan($q['nama_reviewer'] ?? '&mdash;') ?></td>
                <td style="font-size:.75rem;color:var(--text3)"><?= bersihkan($q['catatan_review'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
function switchTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    if (el) el.classList.add('active');
    history.replaceState(null, '', '?tab=' + name);
    if (name === 'input') {
        syncInputFromSelectedSample();
    }
}

const tipeHelp = {
    blanko:   '<strong style="color:var(--gold)">Blanko:</strong> Sampel tanpa analit. Nilai harus mendekati nol (di bawah LOD).',
    standar:  '<strong style="color:var(--gold)">Standar Bersertifikat (CRM):</strong> Verifikasi akurasi metode. Isi nilai sertifikat sebagai Expected. Target recovery 85–115%.',
    spike:    '<strong style="color:var(--gold)">Spike / Fortifikasi:</strong> Sampel dengan penambahan analit diketahui. Target recovery 85–115%.',
    duplikat: '<strong style="color:var(--gold)">Duplikat:</strong> Sampel yang sama dianalisis dua kali. RPD harus &lt; 10%.',
};

function updateTipeHelp() {
    const t = document.getElementById('tipeQc').value;
    document.getElementById('tipeHelp').innerHTML = tipeHelp[t] || '';
}

const qcManajemenData = <?= json_encode($qcManajemenRows, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

function filterSampelOptions() {
    const q = document.getElementById('sampelSearchInput').value.toLowerCase();
    const select = document.getElementById('sampelSelect');
    const options = select.querySelectorAll('option');
    options.forEach(opt => {
        if (!opt.value) return; // keep placeholder
        const label = (opt.dataset.label || opt.textContent).toLowerCase();
        opt.hidden = q.length > 0 && !label.includes(q);
    });
    // if current selection becomes hidden, reset
    const chosen = select.selectedOptions[0];
    if (chosen && chosen.hidden) {
        select.value = '';
        syncInputFromSelectedSample();
    }
}

function syncInputFromSelectedSample() {
    const select = document.getElementById('sampelSelect');
    const parameterField = document.querySelector('input[name="parameter"]');
    const nilaiExpectedField = document.getElementById('nilaiExpInput');
    const badge = document.getElementById('expSourceBadge');
    const hint  = document.getElementById('expHint');
    if (!select || !parameterField || !nilaiExpectedField) return;

    const selected = select.selectedOptions[0];
    if (!selected || !selected.value) {
        parameterField.value = '';
        nilaiExpectedField.value = '';
        badge.style.display = 'none';
        return;
    }
    const sampleId = (selected.dataset.sampel || '').trim();

    const stored = qcManajemenData[sampleId];
    if (!stored) {
        // Sampel reguler — kosongkan expected
        nilaiExpectedField.value = '';
        badge.style.display = 'none';
        hint.style.display = 'block';
        hitungRecovery();
        return;
    }

    if (stored.parameter) parameterField.value = stored.parameter;
    if (stored.nilai_expected) {
        nilaiExpectedField.value = stored.nilai_expected;
        badge.style.display = 'inline';
        hint.style.display  = 'none';
        hitungRecovery();
    }
}

function setupQcManajemenSync() {
    syncInputFromSelectedSample();
}

window.addEventListener('DOMContentLoaded', setupQcManajemenSync);

function hitungRecovery() {
    const nilai    = parseFloat(document.getElementById('nilaiQcInput').value);
    const expected = parseFloat(document.getElementById('nilaiExpInput').value);
    const prev     = document.getElementById('recoveryPreview');

    if (!isNaN(nilai) && !isNaN(expected) && expected > 0) {
        const diff = Math.abs(nilai - expected);
        const diffRounded = Number(diff.toFixed(4));
        const rec = (nilai / expected) * 100;
        prev.style.display = 'block';
        document.getElementById('recVal').textContent = 'Selisih: ' + diffRounded.toFixed(4) + ' (' + rec.toFixed(2) + '%)';

        let flag = 'pass', col = 'var(--green3)';
        if (diffRounded <= 0.05) { 
            flag = 'pass'; col = 'var(--green3)'; 
        } else { 
            flag = 'fail'; col = 'var(--red)'; 
        }

        document.getElementById('recBarFill').style.width = diffRounded <= 0.05 ? '100%' : '20%';
        document.getElementById('recBarFill').style.background = col;
        const flagEl = document.getElementById('recFlag');
        flagEl.textContent = flag.toUpperCase();
        flagEl.className   = 'flag-' + flag;
    } else {
        prev.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
