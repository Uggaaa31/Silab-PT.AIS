<?php
// ============================================================
//  preparasi.php — P1 Preparasi Sampel
//  UPDATE: Referensi WO via work_order_sampel pivot (batch-aware)
//  UPDATE: Role-based access - Analis full, Admin/Supervisor read only
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
/** @var PDO $pdo */
cekLogin();

// Cek akses
if (!canAccessPreparasi()) {
    $_SESSION['msg'] = 'ERROR: Anda tidak memiliki akses ke halaman Preparasi.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Preparasi Sampel';
$isEditable = canEditPreparasi(); // Analis, Supervisor, dan Admin bisa edit/input
$isReadOnly = !$isEditable;

$msg  = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$tab  = $_GET['tab']   ?? 'daftar';
$woId = (int)($_GET['wo_id'] ?? 0);

// ── Work order terpilih (dari work_order.php) ────────────────
$woInfo      = null;
$woSampelList = [];
if ($woId) {
    $stWo = $pdo->prepare("
        SELECT w.*,
               rec.nomor_penerimaan, rec.klien AS klien_batch,
               a.nama AS nama_analis,
               p.kode_alat, p.nama AS nama_alat
        FROM work_order w
        LEFT JOIN penerimaan_sampel rec ON w.penerimaan_id = rec.id
        LEFT JOIN pengguna a ON w.analis_id = a.id
        LEFT JOIN peralatan p ON w.peralatan_id = p.id
        WHERE w.id = ?
    ");
    $stWo->execute([$woId]);
    $woInfo = $stWo->fetch();

    if ($woInfo) {
        $stWoS = $pdo->prepare("
            SELECT s.id, s.kode_sampel, s.jenis_material, s.klien,
                   s.berat_gram, s.metode_uji,
                   rec.nomor_penerimaan
            FROM work_order_sampel wos
            JOIN sampel s ON wos.sampel_id = s.id
            LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
            WHERE wos.wo_id = ?
            ORDER BY s.kode_sampel
        ");
        $stWoS->execute([$woId]);
        $woSampelList = $stWoS->fetchAll();
        $tab = 'input';
    }
}

// ── Data untuk form ──────────────────────────────────────────
$sampelAktif = $pdo->query("
    SELECT s.id, s.kode_sampel, s.jenis_material,
           rec.nomor_penerimaan,
           w.id     AS wo_id,
           w.nomor_wo,
           w.analis_id AS wo_analis_id
    FROM sampel s
    LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
    LEFT JOIN work_order_sampel wos ON wos.sampel_id = s.id
    LEFT JOIN work_order w ON wos.wo_id = w.id AND w.status = 'aktif'
    WHERE s.status IN ('antrian','diuji')
    ORDER BY rec.nomor_penerimaan, s.kode_sampel
")->fetchAll();

$sampelGrouped = [];
foreach ($sampelAktif as $s) {
    $grp = $s['nomor_penerimaan'] ?? 'Tanpa Batch';
    $sampelGrouped[$grp][] = $s;
}

$woAktifList = $pdo->query("
    SELECT w.id, w.nomor_wo, w.metode, w.parameter, w.analis_id,
           rec.nomor_penerimaan, rec.klien,
           COUNT(wos.sampel_id) AS jumlah_sampel,
           a.nama AS nama_analis
    FROM work_order w
    LEFT JOIN penerimaan_sampel rec ON w.penerimaan_id = rec.id
    LEFT JOIN work_order_sampel wos ON wos.wo_id = w.id
    LEFT JOIN pengguna a ON w.analis_id = a.id
    WHERE w.status = 'aktif'
    GROUP BY w.id
    ORDER BY FIELD(w.prioritas,'urgent','tinggi','normal'), w.jadwal_mulai ASC
")->fetchAll();

$bahanList  = $pdo->query(
    "SELECT id, kode_bahan, nama, stok, satuan FROM bahan WHERE stok > 0 ORDER BY nama"
)->fetchAll();

$analisList = $pdo->query(
    "SELECT id, nama FROM pengguna WHERE role IN('admin','analis') AND status='aktif'"
)->fetchAll();

$prepList = $pdo->query("
    SELECT pr.*,
           s.kode_sampel, s.jenis_material, s.klien,
           rec.nomor_penerimaan,
           w.nomor_wo,
           a.nama AS nama_analis
    FROM preparasi_sampel pr
    JOIN sampel s ON pr.sampel_id = s.id
    LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
    LEFT JOIN work_order w ON pr.work_order_id = w.id
    LEFT JOIN pengguna a ON pr.analis_id = a.id
    ORDER BY pr.created_at DESC
")->fetchAll();

$prepByWo = [];
foreach ($prepList as $p) {
    $key = $p['nomor_wo'] ?? '_tanpa_wo';
    $prepByWo[$key][] = $p;
}

ensureMetodePreparasiTable($pdo);
$metodePrepOpts = $pdo->query(
    "SELECT metode FROM metode_preparasi ORDER BY metode ASC"
)->fetchAll(PDO::FETCH_COLUMN);

if (!$metodePrepOpts) {
    $metodePrepOpts = ['destruksi_asam','ekstraksi','pengenceran','fusion','lainnya'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border);}
.tab-btn{padding:8px 18px;background:none;border:none;border-bottom:3px solid transparent;color:var(--text3);font-size:.82rem;cursor:pointer;font-weight:600;transition:.2s;margin-bottom:-1px;}
.tab-btn:hover{color:var(--text2);}
.tab-btn.active{color:var(--gold);border-bottom-color:var(--gold);}
.tab-pane{display:none;}.tab-pane.active{display:block;}
.qc-check{display:flex;gap:20px;flex-wrap:wrap;padding:12px;background:var(--bg3);border-radius:6px;border:1px solid var(--border);}
.qc-item{display:flex;align-items:center;gap:6px;font-size:.8rem;}
.qc-item input[type=checkbox]{width:15px;height:15px;accent-color:var(--green3);}
.reagen-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:8px;margin-bottom:8px;align-items:end;}
.ref-badge{display:inline-block;background:#1a2e0a;color:#a8d58a;border:1px solid #2e5018;border-radius:4px;font-size:.68rem;padding:1px 8px;font-family:monospace;}
.wo-badge{display:inline-block;background:#1a240a;color:var(--gold);border:1px solid var(--gold2);border-radius:4px;font-size:.68rem;padding:1px 8px;font-family:monospace;}
.wo-sampel-list{background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 12px;margin-bottom:12px;}
.wo-sampel-list .title{font-size:.72rem;color:var(--text3);margin-bottom:8px;}
.sampel-chips{display:flex;flex-wrap:wrap;gap:6px;}
.sampel-chip{background:#162e1a;color:var(--green);border:1px solid var(--green3);border-radius:14px;font-size:.68rem;padding:3px 10px;}
.mode-toggle{display:flex;gap:8px;margin-bottom:14px;}
.mode-btn{padding:6px 16px;border-radius:6px;border:1px solid var(--border);background:var(--bg3);color:var(--text3);font-size:.78rem;cursor:pointer;font-weight:600;}
.mode-btn.active{background:var(--gold2);border-color:var(--gold);color:#000;}
.readonly-badge{background:#f39c12;color:#1a1a2e;padding:4px 12px;border-radius:20px;font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:6px;}
</style>

<div class="sec-title">Preparasi Sampel</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR') ? 'alert-red' : 'alert-green' ?>" style="margin-bottom:14px">
        <?= str_starts_with($msg,'ERROR') ? '&#9888;' : '&#10003;' ?> <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<?php if ($isReadOnly): ?>
<div class="alert-box alert-yellow" style="margin-bottom:14px">
    🔒 <strong>Mode Read Only</strong> - Anda hanya dapat melihat data. Untuk mengedit, login sebagai Analis.
</div>
<?php endif; ?>

<?php if ($woInfo): ?>
<div class="alert-box alert-green" style="margin-bottom:14px">
    &#128203; Work Order <strong><?= bersihkan($woInfo['nomor_wo']) ?></strong>
    &mdash; Ref: <span class="ref-badge"><?= bersihkan($woInfo['nomor_penerimaan'] ?? '—') ?></span>
    &mdash; <strong><?= count($woSampelList) ?> sampel</strong>
    &mdash; Analis: <strong><?= bersihkan($woInfo['nama_analis'] ?? 'Belum ditugaskan') ?></strong>
    <?php if ($woInfo['metode']): ?>
        &mdash; Metode: <strong><?= bersihkan($woInfo['metode']) ?></strong>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="tabs">
    <button class="tab-btn <?= $tab==='daftar' ? 'active' : '' ?>" onclick="switchTab('daftar',this)">&#128203; Catatan Preparasi</button>
    <button class="tab-btn <?= $tab==='input'  ? 'active' : '' ?>" onclick="switchTab('input',this)">&#10133; Input Preparasi</button>
</div>

<!-- ── TAB 1: DAFTAR PREPARASI ──────────────────────────── -->
<div id="tab-daftar" class="tab-pane <?= $tab==='daftar' ? 'active' : '' ?>">
    <div class="card">
        <div class="card-title">&#128300; Catatan Preparasi Sampel</div>
        <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                    <th>No. WO</th>
                    <th>No. Ref. Batch</th>
                    <th>Sampel</th>
                    <th>Material</th>
                    <th>Metode Preparasi</th>
                    <th>Faktor Pengenceran</th>
                    <th>QC Disiapkan</th>
                    <th>Suhu (°C)</th>
                    <th>Analis</th>
                    <th>Tanggal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($prepList as $p):
                $qcList = [];
                if ($p['blanko_disiapkan'])   $qcList[] = 'Blanko';
                if ($p['standar_disiapkan'])  $qcList[] = 'Standar';
                if ($p['spike_disiapkan'])    $qcList[] = 'Spike';
                if ($p['duplikat_disiapkan']) $qcList[] = 'Duplikat';
            ?>
            <tr>
                <td>
                    <?php if ($p['nomor_wo']): ?>
                        <span class="wo-badge">&#128203; <?= bersihkan($p['nomor_wo']) ?></span>
                    <?php else: ?>
                        <span style="color:var(--text3);font-size:.75rem">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($p['nomor_penerimaan']): ?>
                        <span class="ref-badge">&#128230; <?= bersihkan($p['nomor_penerimaan']) ?></span>
                    <?php else: ?>
                        <span style="color:var(--text3);font-size:.75rem">—</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--gold)"><?= bersihkan($p['kode_sampel']) ?></td>
                <td><?= bersihkan($p['jenis_material']) ?></td>
                <td><span style="font-size:.75rem"><?= bersihkan(str_replace('_',' ', ucfirst($p['metode_preparasi']))) ?></span></td>
                <td style="text-align:center">
                    <?= $p['faktor_pengenceran'] != 1
                        ? '<strong style="color:var(--gold)">×'.number_format($p['faktor_pengenceran'],0).'</strong>'
                        : '1' ?>
                </td>
                <td>
                    <?php if ($qcList): ?>
                        <?php foreach ($qcList as $q): ?>
                            <span style="font-size:.65rem;background:#162e1a;color:var(--green);border:1px solid var(--green3);border-radius:8px;padding:1px 6px;margin-right:2px"><?= $q ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span style="color:var(--text3)">Tidak ada</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center"><?= $p['suhu_ruang'] ?? '—' ?></td>
                <td><?= bersihkan($p['nama_analis'] ?? '—') ?></td>
                <td><?= fmtTgl($p['tanggal_preparasi']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$prepList): ?>
                <tr><td colspan="10" style="text-align:center;color:var(--text3);padding:20px">Belum ada catatan preparasi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ── TAB 2: INPUT PREPARASI ─────────────────────────── -->
<div id="tab-input" class="tab-pane <?= $tab==='input' ? 'active' : '' ?>">

    <div class="mode-toggle">
        <button type="button" class="mode-btn active" id="modeWoBtn" onclick="setMode('wo')" <?= $isReadOnly ? 'disabled' : '' ?>>
            &#128203; Mode Work Order (Batch)
        </button>
        <button type="button" class="mode-btn" id="modeSampelBtn" onclick="setMode('sampel')" <?= $isReadOnly ? 'disabled' : '' ?>>
            &#128300; Mode Sampel Tunggal
        </button>
    </div>

    <form method="POST" action="<?= BASE_URL ?>/actions/simpan_preparasi.php" id="formPreparasi">
        <input type="hidden" name="work_order_id"   id="hiddenWoId"       value="<?= $woId ?: '' ?>"/>
        <input type="hidden" name="mode_input"       id="hiddenMode"       value="wo"/>
        <input type="hidden" name="sampel_ids_batch" id="hiddenSampelBatch" value=""/>

        <div class="grid2">
            <div>
                <div class="card" style="margin-bottom:14px">
                    <div class="card-title">&#129704; Info Work Order &amp; Sampel</div>

                    <div id="panelModeWo">
                        <div class="form-group" style="margin-bottom:12px">
                            <label>&#128203; Work Order <span style="color:var(--red)">*</span></label>
                            <select id="selWo" onchange="onWoChange(this)" <?= $isReadOnly ? 'disabled' : '' ?>>
                                <option value="">— Pilih Work Order —</option>
                                <?php foreach ($woAktifList as $wo): ?>
                                    <option value="<?= $wo['id'] ?>"
                                            data-ref="<?= bersihkan($wo['nomor_penerimaan'] ?? '') ?>"
                                            data-klien="<?= bersihkan($wo['klien'] ?? '') ?>"
                                            data-analis="<?= $wo['analis_id'] ?>"
                                            data-jumlah="<?= $wo['jumlah_sampel'] ?>"
                                            <?= $woId && $woId==$wo['id'] ? 'selected' : '' ?>>
                                        <?= bersihkan($wo['nomor_wo']) ?>
                                        <?php if ($wo['nomor_penerimaan']): ?>
                                            — <?= bersihkan($wo['nomor_penerimaan']) ?>
                                        <?php endif; ?>
                                        (<?= $wo['jumlah_sampel'] ?> sampel)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="wo-sampel-list" id="woSampelPanel" style="display:none">
                            <div class="title">&#128203; Sampel dalam Work Order ini</div>
                            <div class="sampel-chips" id="woSampelChips"></div>
                        </div>

                        <div class="form-group" style="margin-bottom:12px" id="panelSampelDariWo">
                            <label>Sampel Spesifik
                                <small style="color:var(--text3)">(opsional)</small>
                            </label>
                            <select name="sampel_id" id="selSampelWo" onchange="onSampelWoChange(this)" <?= $isReadOnly ? 'disabled' : '' ?>>
                                <option value="">— Semua sampel dalam WO —</option>
                                <?php foreach ($woSampelList as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= bersihkan($s['kode_sampel']) ?> — <?= bersihkan($s['jenis_material']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="panelModeSampel" style="display:none">
                        <div class="form-group" style="margin-bottom:12px">
                            <label>Sampel <span style="color:var(--red)">*</span></label>
                            <select name="sampel_id_single" id="selSampelSingle" <?= $isReadOnly ? 'disabled' : '' ?>>
                                <option value="">— Pilih Sampel —</option>
                                <?php foreach ($sampelGrouped as $grpName => $samples): ?>
                                <optgroup label="<?= bersihkan($grpName) ?>">
                                    <?php foreach ($samples as $s): ?>
                                        <option value="<?= $s['id'] ?>"
                                                data-wo="<?= $s['wo_id'] ?? '' ?>"
                                                data-wono="<?= bersihkan($s['nomor_wo'] ?? '') ?>"
                                                data-analis="<?= $s['wo_analis_id'] ?? '' ?>">
                                            <?= bersihkan($s['kode_sampel']) ?> — <?= bersihkan($s['jenis_material']) ?>
                                            <?= $s['nomor_wo'] ? '[WO: '.bersihkan($s['nomor_wo']).']' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Metode Preparasi <span style="color:var(--red)">*</span></label>
                            <select name="metode_preparasi" <?= $isReadOnly ? 'disabled' : '' ?> required>
                                <?php foreach ($metodePrepOpts as $m): ?>
                                    <option value="<?= bersihkan($m) ?>"><?= ucfirst(str_replace('_',' ', bersihkan($m))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Analis</label>
                            <select name="analis_id" id="selAnalis" <?= $isReadOnly ? 'disabled' : '' ?>>
                                <option value="">— Pilih —</option>
                                <?php foreach ($analisList as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= bersihkan($a['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Tanggal Preparasi</label>
                            <input type="date" name="tanggal_preparasi" value="<?= date('Y-m-d') ?>" <?= $isReadOnly ? 'readonly disabled' : '' ?> required/>
                        </div>
                        <div class="form-group">
                            <label>Prosedur / Langkah</label>
                            <textarea name="prosedur" rows="2" placeholder="Langkah-langkah preparasi..." <?= $isReadOnly ? 'readonly disabled' : '' ?>></textarea>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom:14px">
                    <div class="card-title">&#128260; Faktor Pengenceran <small style="color:var(--text3);font-weight:400">(P4)</small></div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Faktor Pengenceran</label>
                            <input type="number" step="0.0001" name="faktor_pengenceran"
                                   id="faktorInput" value="1" min="1"
                                   oninput="updateFaktorPreview()"
                                   <?= $isReadOnly ? 'readonly disabled' : '' ?>
                                   placeholder="1 = tidak diencerkan"/>
                        </div>
                        <div class="form-group">
                            <label>Contoh hasil terkoreksi</label>
                            <div style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:8px 12px;font-size:.82rem;color:var(--gold)" id="faktorPreview">
                                Nilai instrumen × 1 = nilai aktual
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Volume Awal (mL)</label>
                            <input type="number" step="0.001" name="volume_awal_ml" placeholder="mL" <?= $isReadOnly ? 'readonly disabled' : '' ?>/>
                        </div>
                        <div class="form-group">
                            <label>Volume Akhir (mL)</label>
                            <input type="number" step="0.001" name="volume_akhir_ml"
                                   placeholder="mL" oninput="hitungFaktorOtomatis()" <?= $isReadOnly ? 'readonly disabled' : '' ?>/>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">&#127777; Kondisi Lingkungan</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Suhu Ruang (°C)</label>
                            <input type="number" step="0.1" name="suhu_ruang" placeholder="25.0" <?= $isReadOnly ? 'readonly disabled' : '' ?>/>
                        </div>
                        <div class="form-group">
                            <label>Kelembaban (%)</label>
                            <input type="number" step="0.1" name="kelembaban" placeholder="60.0" <?= $isReadOnly ? 'readonly disabled' : '' ?>/>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Catatan</label>
                        <textarea name="catatan" rows="2" placeholder="Catatan kondisi atau anomali..." <?= $isReadOnly ? 'readonly disabled' : '' ?>></textarea>
                    </div>
                </div>
            </div>

            <div>
                <div class="card" style="margin-bottom:14px">
                    <div class="card-title">&#10003; QC Samples yang Disiapkan <small style="color:var(--text3);font-weight:400">(P2)</small></div>
                    <div class="qc-check">
                        <label class="qc-item">
                            <input type="checkbox" name="blanko_disiapkan" value="1" <?= $isReadOnly ? 'disabled' : '' ?>/>
                            <span>&#9898; Blanko</span>
                        </label>
                        <label class="qc-item">
                            <input type="checkbox" name="standar_disiapkan" value="1" <?= $isReadOnly ? 'disabled' : '' ?>/>
                            <span>&#128203; Standar</span>
                        </label>
                        <label class="qc-item">
                            <input type="checkbox" name="spike_disiapkan" value="1" <?= $isReadOnly ? 'disabled' : '' ?>/>
                            <span>&#128200; Spike</span>
                        </label>
                        <label class="qc-item">
                            <input type="checkbox" name="duplikat_disiapkan" value="1" <?= $isReadOnly ? 'disabled' : '' ?>/>
                            <span>&#128260; Duplikat</span>
                        </label>
                    </div>
                </div>

                <div class="card" style="margin-bottom:14px">
                    <div class="card-title">&#129514; Reagen yang Digunakan</div>
                    <div id="reagenRows"></div>
                    <?php if (!$isReadOnly): ?>
                        <button type="button" class="btn btn-green btn-sm" onclick="tambahReagen()" style="margin-top:6px">
                            &#10133; Tambah Reagen
                        </button>
                    <?php endif; ?>
                </div>

                <div class="card" id="cardRingkasanWo" style="display:none">
                    <div class="card-title">&#128203; Ringkasan — Sampel yang Akan Dipreparasi</div>
                    <table class="data-table">
                        <thead><tr><th>#</th><th>Kode Sampel</th><th>Material</th><th>Berat (g)</th></tr></thead>
                        <tbody id="tbodyRingkasanSampel"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($isEditable): ?>
            <div style="margin-top:14px">
                <button type="submit" class="btn btn-gold">&#128190; Simpan Catatan Preparasi</button>
            </div>
        <?php else: ?>
            <div style="margin-top:14px">
                <div class="readonly-badge">🔒 Mode Read Only - Anda hanya dapat melihat data</div>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
function switchTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    if (el) el.classList.add('active');
    history.replaceState(null, '', '?tab=' + name);
}

function setMode(mode) {
    <?php if ($isReadOnly): ?>
        return;
    <?php endif; ?>
    const isWo = (mode === 'wo');
    document.getElementById('panelModeWo').style.display     = isWo ? '' : 'none';
    document.getElementById('panelModeSampel').style.display = isWo ? 'none' : '';
    document.getElementById('hiddenMode').value = mode;
    document.getElementById('modeWoBtn').classList.toggle('active', isWo);
    document.getElementById('modeSampelBtn').classList.toggle('active', !isWo);
    if (!isWo) {
        document.getElementById('hiddenWoId').value = '';
        document.getElementById('woSampelPanel').style.display = 'none';
        document.getElementById('cardRingkasanWo').style.display = 'none';
    }
}

const woSampelData = <?= json_encode(
    array_reduce(
        $woAktifList,
        function($carry, $wo) use ($pdo) {
            $st = $pdo->prepare("
                SELECT s.id, s.kode_sampel, s.jenis_material, s.berat_gram
                FROM work_order_sampel wos
                JOIN sampel s ON wos.sampel_id = s.id
                WHERE wos.wo_id = ?
                ORDER BY s.kode_sampel
            ");
            $st->execute([$wo['id']]);
            $carry[$wo['id']] = $st->fetchAll(PDO::FETCH_ASSOC);
            return $carry;
        },
        []
    )
) ?>;

function onWoChange(sel) {
    const woId   = sel.value;
    const opt    = sel.options[sel.selectedIndex];
    const analis = opt.dataset.analis || '';

    document.getElementById('hiddenWoId').value = woId;

    if (!woId) {
        document.getElementById('woSampelPanel').style.display     = 'none';
        document.getElementById('cardRingkasanWo').style.display   = 'none';
        document.getElementById('panelSampelDariWo').style.display = 'none';
        document.getElementById('hiddenSampelBatch').value         = '';
        return;
    }

    if (analis) {
        const aSel = document.getElementById('selAnalis');
        for (let i = 0; i < aSel.options.length; i++) {
            if (aSel.options[i].value == analis) { aSel.selectedIndex = i; break; }
        }
    }

    const sampelList = woSampelData[woId] || [];
    _updateBatchHidden(sampelList.map(s => s.id), null);

    const chips = sampelList.map(s =>
        `<span class="sampel-chip">&#128300; ${s.kode_sampel} <small style="opacity:.7">${s.jenis_material}</small></span>`
    ).join('');
    document.getElementById('woSampelChips').innerHTML = chips || '<span style="color:var(--text3);font-size:.75rem">Tidak ada sampel.</span>';
    document.getElementById('woSampelPanel').style.display     = 'block';
    document.getElementById('panelSampelDariWo').style.display = 'block';

    const selSampelWo = document.getElementById('selSampelWo');
    selSampelWo.innerHTML = '<option value="">— Semua sampel dalam WO —</option>';
    sampelList.forEach(s => {
        const o = document.createElement('option');
        o.value       = s.id;
        o.textContent = s.kode_sampel + ' — ' + s.jenis_material;
        selSampelWo.appendChild(o);
    });

    const tbody = document.getElementById('tbodyRingkasanSampel');
    tbody.innerHTML = sampelList.map((s, i) =>
        `<tr>
            <td style="color:var(--text3)">${i+1}</td>
            <td style="color:var(--gold)">${s.kode_sampel}</td>
            <td>${s.jenis_material}</td>
            <td>${s.berat_gram ? s.berat_gram + ' g' : '—'}</td>
         </tr>`
    ).join('');
    document.getElementById('cardRingkasanWo').style.display = sampelList.length ? 'block' : 'none';
}

function onSampelWoChange(sel) {
    const woId       = document.getElementById('hiddenWoId').value;
    const sampelList = woSampelData[woId] || [];
    const chosenId   = parseInt(sel.value) || null;
    _updateBatchHidden(sampelList.map(s => s.id), chosenId);
}

function _updateBatchHidden(ids, singleId) {
    const batchInput = document.getElementById('hiddenSampelBatch');
    if (singleId) {
        batchInput.value = JSON.stringify([singleId]);
    } else {
        batchInput.value = JSON.stringify(ids);
    }
}

document.getElementById('formPreparasi').addEventListener('submit', function(e) {
    <?php if ($isReadOnly): ?>
        e.preventDefault();
        alert('Mode Read Only - Anda tidak dapat menyimpan data.');
        return;
    <?php endif; ?>
    const mode  = document.getElementById('hiddenMode').value;
    const woId  = document.getElementById('hiddenWoId').value;
    const batch = document.getElementById('hiddenSampelBatch').value;
    const single = document.getElementById('selSampelSingle')
                    ? document.getElementById('selSampelSingle').value : '';

    if (mode === 'wo') {
        if (!woId) {
            e.preventDefault();
            alert('Pilih Work Order terlebih dahulu.');
            return;
        }
        if (!batch || batch === '[]') {
            e.preventDefault();
            alert('WO yang dipilih tidak memiliki sampel.');
            return;
        }
    } else {
        if (!single) {
            e.preventDefault();
            alert('Pilih sampel terlebih dahulu.');
            return;
        }
    }
});

<?php if ($woId && $woInfo): ?>
window.addEventListener('load', () => {
    const sel = document.getElementById('selWo');
    if (sel && sel.value) onWoChange(sel);
});
<?php endif; ?>

function updateFaktorPreview() {
    const f = parseFloat(document.getElementById('faktorInput').value) || 1;
    document.getElementById('faktorPreview').textContent =
        'Nilai instrumen × ' + f.toFixed(4) + ' = nilai aktual yang dilaporkan';
}

function hitungFaktorOtomatis() {
    const vAwal  = parseFloat(document.querySelector('[name=volume_awal_ml]').value)  || 0;
    const vAkhir = parseFloat(document.querySelector('[name=volume_akhir_ml]').value) || 0;
    if (vAwal > 0 && vAkhir > 0) {
        const f = vAkhir / vAwal;
        document.getElementById('faktorInput').value = f.toFixed(4);
        updateFaktorPreview();
    }
}

const bahanOpts = <?= json_encode(array_map(fn($b) => [
    'id'     => $b['id'],
    'label'  => $b['kode_bahan'].' — '.$b['nama'],
    'satuan' => $b['satuan'],
    'stok'   => $b['stok'],
], $bahanList)) ?>;
let reagenCount = 0;

function tambahReagen() {
    reagenCount++;
    const i    = reagenCount;
    const opts = bahanOpts.map(b =>
        `<option value="${b.id}" data-sat="${b.satuan}" data-stok="${b.stok}">${b.label} (stok: ${b.stok})</option>`
    ).join('');
    const div = document.createElement('div');
    div.className = 'reagen-row';
    div.id = 'rrow' + i;
    div.innerHTML = `
        <div class="form-group">
            <label style="font-size:.7rem;color:var(--text3)">Bahan / Reagen</label>
            <select name="reagen[${i}][bahan_id]">
                <option value="">— Pilih —</option>${opts}
            </select>
        </div>
        <div class="form-group">
            <label style="font-size:.7rem;color:var(--text3)">Volume/Jumlah</label>
            <input type="number" step="0.001" name="reagen[${i}][jumlah]" placeholder="0.000"/>
        </div>
        <div class="form-group">
            <label style="font-size:.7rem;color:var(--text3)">Satuan</label>
            <input type="text" name="reagen[${i}][satuan]" placeholder="mL / g"/>
        </div>
        <div class="form-group">
            <label style="font-size:.7rem;color:var(--text3)">No. Lot</label>
            <input type="text" name="reagen[${i}][lot]" placeholder="LOT-xxx"/>
        </div>
        <div>
            <label style="display:block;margin-bottom:5px">&nbsp;</label>
            <button type="button" onclick="document.getElementById('rrow${i}').remove()"
                    style="background:var(--red);color:#fff;border:none;border-radius:4px;padding:6px 10px;cursor:pointer;font-size:.75rem">&#10005;</button>
        </div>`;
    document.getElementById('reagenRows').appendChild(div);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
