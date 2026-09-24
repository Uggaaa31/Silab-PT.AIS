<?php
// ============================================================
//  sampel.php — Manajemen Sampel
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();
$pageTitle = 'Manajemen Sampel';

$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$tab = $_GET['tab'] ?? 'daftar';

// ── Auto-generate kode ────────────────────────────────────────
$lastKode = $pdo->query("SELECT kode_sampel FROM sampel ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextNum  = $lastKode ? (intval(substr($lastKode, -3)) + 1) : 1;
$kodeAuto = 'S-' . date('ym') . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

// ── Auto-generate no. penerimaan baru ────────────────────────
$lastRec = $pdo->query("SELECT nomor_penerimaan FROM penerimaan_sampel ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextRec = $lastRec ? (intval(substr($lastRec, -3)) + 1) : 1;
$noRecAuto = 'REC-' . date('ym') . '-' . str_pad($nextRec, 3, '0', STR_PAD_LEFT);

// ── Daftar batch untuk dropdown ──────────────────────────────
$batchList = $pdo->query(
    "SELECT id, nomor_penerimaan, klien, tanggal_terima
     FROM penerimaan_sampel ORDER BY created_at DESC"
)->fetchAll();

// ── Daftar klien unik ────────────────────────────────────────
$klienAll = $pdo->query(
    "SELECT DISTINCT klien FROM sampel WHERE klien IS NOT NULL AND klien != '' ORDER BY klien"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Filter & search ──────────────────────────────────────────
$search  = trim($_GET['q']     ?? '');
$fStatus = $_GET['status']     ?? '';
$fMetode = $_GET['metode']     ?? '';
$fBatch  = $_GET['batch']      ?? '';

$sql    = "SELECT s.*,
                  rec.nomor_penerimaan,
                  (SELECT COUNT(*) FROM hasil_uji h WHERE h.sampel_id=s.id) AS jumlah_uji,
                  (SELECT COUNT(*) FROM hasil_uji h WHERE h.sampel_id=s.id AND h.kesimpulan='lulus') AS jumlah_lulus,
                  (SELECT COUNT(*) FROM hasil_uji h WHERE h.sampel_id=s.id AND h.kesimpulan='tidak_lulus') AS jumlah_tlulus
           FROM sampel s
           LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id=rec.id
           WHERE 1=1";
$params = [];
if ($search)  { $sql.=" AND (s.kode_sampel LIKE ? OR s.klien LIKE ? OR s.jenis_material LIKE ?)"; $params=array_merge($params,["%$search%","%$search%","%$search%"]); }
if ($fStatus) { $sql.=" AND s.status=?"; $params[]=$fStatus; }
if ($fMetode) { $sql.=" AND s.metode_uji=?"; $params[]=$fMetode; }
if ($fBatch)  { $sql.=" AND rec.nomor_penerimaan=?"; $params[]=$fBatch; }
$sql .= " ORDER BY s.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$sampelList = $stmt->fetchAll();

// Kelompokkan batch vs single
$grouped = []; $singles = [];
foreach ($sampelList as $s) {
    if ($s['nomor_penerimaan']) $grouped[$s['nomor_penerimaan']][] = $s;
    else $singles[] = $s;
}

// ── Statistik ────────────────────────────────────────────────
$statTotal    = $pdo->query("SELECT COUNT(*) FROM sampel")->fetchColumn();
$statAktif    = $pdo->query("SELECT COUNT(*) FROM sampel WHERE status NOT IN('selesai','ditolak')")->fetchColumn();
$statSelesai  = $pdo->query("SELECT COUNT(*) FROM sampel WHERE status='selesai'")->fetchColumn();
$statBelumUji = $pdo->query("SELECT COUNT(*) FROM sampel s WHERE NOT EXISTS (SELECT 1 FROM hasil_uji h WHERE h.sampel_id=s.id)")->fetchColumn();

$materialOpts = ['Bijih Emas','Nikel Laterit','Tembaga','Bauksit','Bijih Besi','Timbal/Seng','Mangan','Kromit','Lainnya'];
$metodeOpts   = ['AAS','XRF','ICP-OES','Gravimetri','Fire Assay','Volumetri'];
$statusOpts   = ['antrian','diuji','review','selesai','ditolak'];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── TABS ─────────────────────────────────────────── */
.tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border);}
.tab-btn{padding:8px 18px;background:none;border:none;border-bottom:3px solid transparent;color:var(--text3);font-size:.82rem;cursor:pointer;font-weight:600;transition:.2s;margin-bottom:-1px;}
.tab-btn:hover{color:var(--text2);}
.tab-btn.active{color:var(--gold);border-bottom-color:var(--gold);}
.tab-pane{display:none;}.tab-pane.active{display:block;}

/* ── INPUT MODE TOGGLE ───────────────────────────── */
.mode-toggle{display:flex;gap:0;margin-bottom:18px;border:1px solid var(--border);border-radius:8px;overflow:hidden;width:fit-content;}
.mode-btn{padding:8px 22px;background:none;border:none;color:var(--text3);font-size:.82rem;font-weight:600;cursor:pointer;transition:.2s;}
.mode-btn.active{background:var(--green3);color:#fff;}
.mode-btn:not(.active):hover{background:var(--bg3);color:var(--text);}
.mode-pane{display:none;}.mode-pane.active{display:block;}

/* ── TABEL SAMPEL ────────────────────────────────── */
.tbl-sampel{width:100%;border-collapse:collapse;font-size:.8rem;}
.tbl-sampel thead th{background:var(--bg3);color:var(--text3);font-weight:600;padding:8px 10px;text-align:left;border-bottom:2px solid var(--border);font-size:.72rem;letter-spacing:.5px;white-space:nowrap;}
.tbl-sampel thead th.th-gold{color:var(--gold);}
.tbl-sampel tbody td{padding:9px 10px;border-bottom:1px solid #1a3525;color:var(--text2);vertical-align:middle;}
.tbl-sampel tbody tr:hover td{background:#152d1e;}
.tbl-sampel tbody tr.batch-parent td{background:#0f2318;}
.tbl-sampel tbody tr.batch-child td{background:#0a1e10;padding-left:28px;font-size:.76rem;}

/* ── ACTION BAR ──────────────────────────────────── */
.action-bar{display:none;align-items:center;gap:10px;background:var(--bg3);border:1px solid var(--green3);border-radius:8px;padding:10px 16px;margin-bottom:12px;flex-wrap:wrap;}
.action-bar.show{display:flex;}
.action-bar-count{font-size:.82rem;color:var(--gold);font-weight:700;margin-right:4px;}

/* ── STAT MINI ───────────────────────────────────── */
.stat-mini{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.smc{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 14px;}
.smc .v{font-size:1.5rem;font-weight:700;color:var(--green);}
.smc .v.gold{color:var(--gold);}.smc .v.yellow{color:var(--yellow);}
.smc .l{font-size:.7rem;color:var(--text3);margin-top:2px;}

/* ── BATCH BADGE ─────────────────────────────────── */
.batch-badge-sm{display:inline-block;background:#162e1a;color:var(--green);border:1px solid var(--green3);border-radius:10px;font-size:.65rem;padding:1px 7px;}

/* ── BATCH INPUT TABLE ───────────────────────────── */
.batch-input-tbl{width:100%;border-collapse:collapse;font-size:.8rem;}
.batch-input-tbl thead th{background:var(--bg3);color:var(--text3);font-size:.72rem;padding:7px 8px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;}
.batch-input-tbl tbody td{padding:5px 6px;border-bottom:1px solid #1a3525;vertical-align:middle;}
.batch-input-tbl tbody tr:last-child td{border-bottom:none;}
.batch-input-tbl .cell-in{width:100%;background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:5px 8px;border-radius:5px;font-size:.78rem;outline:none;}
.batch-input-tbl .cell-in:focus{border-color:var(--green3);}
.batch-input-tbl .row-num{color:var(--text3);font-size:.72rem;text-align:center;font-weight:700;}

/* ── BATCH INFO STRIP ────────────────────────────── */
.batch-info-strip{background:#0d2318;border:1px solid var(--green3);border-radius:8px;padding:10px 14px;margin-bottom:14px;display:none;font-size:.78rem;}
.batch-info-strip.show{display:flex;gap:24px;flex-wrap:wrap;align-items:center;}
.bi-item .bi-lbl{font-size:.68rem;color:var(--text3);}
.bi-item .bi-val{color:var(--gold);font-weight:700;}

/* ── STEPPER ─────────────────────────────────────── */
.stepper{display:flex;align-items:center;gap:0;margin-bottom:20px;}
.step{display:flex;align-items:center;gap:8px;font-size:.78rem;color:var(--text3);}
.step.active{color:var(--gold);}
.step.done{color:var(--green);}
.step-num{width:24px;height:24px;border-radius:50%;border:2px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;flex-shrink:0;}
.step-line{width:40px;height:1px;background:var(--border);margin:0 4px;}
</style>

<div class="sec-title">Manajemen Sampel</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR')?'alert-red':'alert-green' ?>" style="margin-bottom:14px">
        <?= str_starts_with($msg,'ERROR')?'&#9888;':'&#10003;' ?> <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<!-- STAT MINI -->
<div class="stat-mini">
    <div class="smc"><div class="v"><?= $statTotal ?></div><div class="l">Total Sampel</div></div>
    <div class="smc"><div class="v gold"><?= $statAktif ?></div><div class="l">Sedang Diproses</div></div>
    <div class="smc"><div class="v"><?= $statSelesai ?></div><div class="l">Selesai</div></div>
    <div class="smc"><div class="v yellow"><?= $statBelumUji ?></div><div class="l">Belum Ada Hasil Uji</div></div>
</div>

<!-- TABS -->
<div class="tabs">
    <button class="tab-btn <?= $tab==='daftar' ?'active':'' ?>" onclick="switchTab('daftar',this)">&#128203; Daftar Sampel</button>
    <button class="tab-btn <?= $tab==='input'  ?'active':'' ?>" onclick="switchTab('input',this)">&#10133; Input Sampel</button>
    <button class="tab-btn <?= $tab==='riwayat'?'active':'' ?>" onclick="switchTab('riwayat',this)">&#128202; Statistik</button>
</div>

<!-- ══════════════════════════════════════════════════
     TAB 1 — DAFTAR SAMPEL
══════════════════════════════════════════════════ -->
<div id="tab-daftar" class="tab-pane <?= $tab==='daftar'?'active':'' ?>">

    <form method="GET" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
        <input type="hidden" name="tab" value="daftar"/>
        <input name="q" value="<?= bersihkan($search) ?>" placeholder="&#128269; Cari kode / klien / material..."
               style="flex:1;min-width:180px;background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:6px;font-size:.8rem;outline:none"/>
        <select name="status" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:.8rem;outline:none">
            <option value="">Semua Status</option>
            <?php foreach ($statusOpts as $st): ?><option value="<?= $st ?>" <?= $fStatus===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
        </select>
        <select name="metode" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:.8rem;outline:none">
            <option value="">Semua Metode</option>
            <?php foreach ($metodeOpts as $m): ?><option value="<?= $m ?>" <?= $fMetode===$m?'selected':'' ?>><?= $m ?></option><?php endforeach; ?>
        </select>
        <select name="batch" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:.8rem;outline:none">
            <option value="">Semua Batch</option>
            <?php foreach ($batchList as $b): ?><option value="<?= bersihkan($b['nomor_penerimaan']) ?>" <?= $fBatch===$b['nomor_penerimaan']?'selected':'' ?>><?= bersihkan($b['nomor_penerimaan']) ?> — <?= bersihkan($b['klien']) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-green btn-sm">Terapkan</button>
        <a href="?tab=daftar" class="btn btn-sm" style="background:var(--bg3);color:var(--text2);border:1px solid var(--border)">Reset</a>
    </form>

    <!-- Action bar -->
    <div class="action-bar" id="actionBar">
        <span class="action-bar-count" id="selCount">0</span>
        <span style="font-size:.78rem;color:var(--text2)">sampel dipilih</span>
        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_sampel.php" id="massForm" style="display:contents">
            <input type="hidden" name="action" value="mass_status"/>
            <input type="hidden" name="ids" id="massIds"/>
            <select name="status" id="massStatus" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:5px 8px;border-radius:5px;font-size:.78rem;outline:none">
                <option value="">Ubah Status →</option>
                <?php foreach ($statusOpts as $st): ?><option value="<?= $st ?>"><?= ucfirst($st) ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-gold btn-sm" onclick="massUpdate()">&#10003; Terapkan</button>
        </form>
        <a id="btnGoUji" href="<?= BASE_URL ?>/pages/pengujian.php" class="btn btn-green btn-sm">&#128300; Input Hasil Uji</a>
        <button type="button" class="btn btn-sm" style="background:var(--bg3);color:var(--text3);border:1px solid var(--border)" onclick="clearSel()">&#10005; Batal</button>
    </div>

    <!-- Tabel -->
    <div style="overflow-x:auto">
    <table class="tbl-sampel">
        <thead>
            <tr>
                <th style="width:32px"><input type="checkbox" id="chkAll" onchange="toggleAll(this)"/></th>
                <th class="th-gold">Kode Sampel</th>
                <th>Batch / No. Penerimaan</th>
                <th>Klien</th>
                <th>Material</th>
                <th>Berat (g)</th>
                <th>Metode</th>
                <th>Tgl Masuk</th>
                <th>Status</th>
                <th>Hasil Uji</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($grouped as $noRec => $rows):
            $first = $rows[0]; $jml = count($rows); ?>
            <tr class="batch-parent" style="cursor:pointer" onclick="toggleBatch('<?= htmlspecialchars($noRec,ENT_QUOTES) ?>')">
                <td><input type="checkbox" class="chk-batch" onclick="event.stopPropagation()" onchange="toggleBatchCheck('<?= htmlspecialchars($noRec,ENT_QUOTES) ?>',this)"/></td>
                <td colspan="10">
                    <span style="color:var(--gold);font-weight:700">&#128230; <?= bersihkan($noRec) ?></span>
                    &nbsp;&#8212;&nbsp;<span style="color:var(--text2)"><?= bersihkan($first['klien']) ?></span>
                    &nbsp;<span class="batch-badge-sm"><?= $jml ?> sampel</span>
                    <span style="float:right;color:var(--text3);font-size:.78rem" id="ico-<?= htmlspecialchars($noRec,ENT_QUOTES) ?>">&#9660; expand</span>
                </td>
            </tr>
            <?php foreach ($rows as $s): ?>
            <tr class="batch-child" data-batch="<?= bersihkan($noRec) ?>" style="display:none">
                <td onclick="event.stopPropagation()"><input type="checkbox" class="chk-row" value="<?= $s['id'] ?>" onchange="updateSel()"/></td>
                <td><strong style="color:var(--gold)"><?= bersihkan($s['kode_sampel']) ?></strong>
                    <?php if (!$s['jumlah_uji']): ?><span style="font-size:.65rem;background:#2d1a00;color:var(--yellow);border-radius:8px;padding:1px 6px;margin-left:4px">Belum Diuji</span><?php endif; ?></td>
                <td><span class="batch-badge-sm"><?= bersihkan($s['nomor_penerimaan']) ?></span></td>
                <td><?= bersihkan($s['klien']) ?></td>
                <td><?= bersihkan($s['jenis_material']) ?></td>
                <td><?= $s['berat_gram'] ? number_format($s['berat_gram'],2) : '—' ?></td>
                <td><?= bersihkan($s['metode_uji']??'—') ?></td>
                <td><?= date('d/m/Y',strtotime($s['tanggal_masuk'])) ?></td>
                <td onclick="event.stopPropagation()">
                    <form method="POST" action="<?= BASE_URL ?>/actions/simpan_sampel.php">
                        <input type="hidden" name="action" value="update_status"/>
                        <input type="hidden" name="id" value="<?= $s['id'] ?>"/>
                        <select name="status" class="sel-inline" onchange="this.form.submit()" style="font-size:.7rem;width:88px">
                            <?php foreach ($statusOpts as $st): ?><option value="<?= $st ?>" <?= $s['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td><?php if ($s['jumlah_uji']): ?><span style="font-size:.75rem;color:<?= $s['jumlah_tlulus']?'var(--red)':'var(--green)' ?>"><?= $s['jumlah_lulus'] ?>L/<?= $s['jumlah_tlulus'] ?>TL</span><?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?></td>
                <td><a href="<?= BASE_URL ?>/pages/pengujian.php?sampel_id=<?= $s['id'] ?>" class="btn btn-green btn-sm" style="font-size:.68rem;padding:3px 8px">&#128300; Uji</a></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php foreach ($singles as $s): ?>
        <tr>
            <td onclick="event.stopPropagation()"><input type="checkbox" class="chk-row" value="<?= $s['id'] ?>" onchange="updateSel()"/></td>
            <td><strong style="color:var(--gold)"><?= bersihkan($s['kode_sampel']) ?></strong>
                <?php if (!$s['jumlah_uji']): ?><span style="font-size:.65rem;background:#2d1a00;color:var(--yellow);border-radius:8px;padding:1px 6px;margin-left:4px">Belum Diuji</span><?php endif; ?></td>
            <td style="color:var(--text3)">—</td>
            <td><?= bersihkan($s['klien']) ?></td>
            <td><?= bersihkan($s['jenis_material']) ?></td>
            <td><?= $s['berat_gram'] ? number_format($s['berat_gram'],2) : '—' ?></td>
            <td><?= bersihkan($s['metode_uji']??'—') ?></td>
            <td><?= date('d/m/Y',strtotime($s['tanggal_masuk'])) ?></td>
            <td onclick="event.stopPropagation()">
                <form method="POST" action="<?= BASE_URL ?>/actions/simpan_sampel.php">
                    <input type="hidden" name="action" value="update_status"/>
                    <input type="hidden" name="id" value="<?= $s['id'] ?>"/>
                    <select name="status" class="sel-inline" onchange="this.form.submit()" style="font-size:.7rem;width:88px">
                        <?php foreach ($statusOpts as $st): ?><option value="<?= $st ?>" <?= $s['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td><?php if ($s['jumlah_uji']): ?><span style="font-size:.75rem;color:<?= $s['jumlah_tlulus']?'var(--red)':'var(--green)' ?>"><?= $s['jumlah_lulus'] ?>L/<?= $s['jumlah_tlulus'] ?>TL</span><?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?></td>
            <td><a href="<?= BASE_URL ?>/pages/pengujian.php?sampel_id=<?= $s['id'] ?>" class="btn btn-green btn-sm" style="font-size:.68rem;padding:3px 8px">&#128300; Uji</a></td>
        </tr>
        <?php endforeach; ?>

        <?php if (!$sampelList): ?>
            <tr><td colspan="11" style="text-align:center;color:var(--text3);padding:24px">Tidak ada sampel ditemukan.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <div style="font-size:.72rem;color:var(--text3);margin-top:8px">
        <?= count($sampelList) ?> sampel &nbsp;·&nbsp;
        <a href="<?= BASE_URL ?>/pages/pengujian.php" style="color:var(--green3)">&#128300; Ke Laman Pengujian</a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════
     TAB 2 — INPUT SAMPEL (TUNGGAL & BATCH)
══════════════════════════════════════════════════ -->
<div id="tab-input" class="tab-pane <?= $tab==='input'?'active':'' ?>">

    <!-- MODE TOGGLE -->
    <div class="mode-toggle">
        <button class="mode-btn active" id="btnModeTunggal" onclick="switchMode('tunggal')">
            &#129704; Sampel Tunggal
        </button>
        <button class="mode-btn" id="btnModeBatch" onclick="switchMode('batch')">
            &#128230; Sampel Batch
        </button>
    </div>

    <!-- ── MODE TUNGGAL ──────────────────────────────── -->
    <div id="mode-tunggal" class="mode-pane active">
        <div class="grid2">
            <div class="card">
                <div class="card-title">&#10133; Input Sampel Tunggal</div>
                <form method="POST" action="<?= BASE_URL ?>/actions/simpan_sampel.php">
                    <input type="hidden" name="action" value="tambah"/>
                    <div class="form-row">
                        <div class="form-group"><label>ID Sampel</label><input name="kode_sampel" value="<?= $kodeAuto ?>" readonly/></div>
                        <div class="form-group"><label>Tanggal Masuk</label><input type="date" name="tanggal_masuk" value="<?= date('Y-m-d') ?>" required/></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Jenis Material</label>
                            <select name="jenis_material" required>
                                <option value="">— Pilih —</option>
                                <?php foreach ($materialOpts as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>Berat (gram)</label><input type="number" step="0.001" name="berat_gram" placeholder="0.000" required/></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Klien</label>
                            <input name="klien" placeholder="Nama perusahaan" required list="klienDL"/>
                        </div>
                        <div class="form-group">
                            <label>Metode Uji</label>
                            <select name="metode_uji" required>
                                <option value="">— Pilih —</option>
                                <?php foreach ($metodeOpts as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:12px">
                        <label>Tautkan ke Batch (opsional)</label>
                        <select name="penerimaan_id">
                            <option value="">— Tanpa batch —</option>
                            <?php foreach ($batchList as $b): ?><option value="<?= $b['id'] ?>"><?= bersihkan($b['nomor_penerimaan']) ?> — <?= bersihkan($b['klien']) ?> (<?= date('d/m/Y',strtotime($b['tanggal_terima'])) ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:14px">
                        <label>Keterangan</label>
                        <textarea name="keterangan" rows="2" placeholder="Catatan..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-gold">&#128190; Simpan Sampel</button>
                </form>
                <datalist id="klienDL">
                    <?php foreach ($klienAll as $k): ?><option value="<?= bersihkan($k) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="card">
                <div class="card-title">&#128203; 10 Sampel Terakhir</div>
                <table>
                    <thead><tr><th>Kode</th><th>Material</th><th>Klien</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php $recent=$pdo->query("SELECT kode_sampel,jenis_material,klien,status FROM sampel ORDER BY created_at DESC LIMIT 10")->fetchAll();
                    foreach ($recent as $r): ?>
                    <tr>
                        <td style="color:var(--gold);font-size:.78rem"><?= bersihkan($r['kode_sampel']) ?></td>
                        <td><?= bersihkan($r['jenis_material']) ?></td>
                        <td><?= bersihkan($r['klien']) ?></td>
                        <td><?= badgeStatus($r['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── MODE BATCH ────────────────────────────────── -->
    <div id="mode-batch" class="mode-pane">

        <!-- Stepper visual -->
        <div class="stepper">
            <div class="step done" id="step1">
                <div class="step-num">1</div>
                <span>Info Penerimaan</span>
            </div>
            <div class="step-line"></div>
            <div class="step" id="step2">
                <div class="step-num">2</div>
                <span>Daftar Sampel</span>
            </div>
            <div class="step-line"></div>
            <div class="step" id="step3">
                <div class="step-num">3</div>
                <span>Simpan</span>
            </div>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_penerimaan.php" id="formBatch">

            <!-- ── SEKSI 1: INFO PENERIMAAN ────────────── -->
            <div class="card" style="margin-bottom:14px">
                <div class="card-title">&#128230; Informasi Penerimaan Batch</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>No. Penerimaan <small style="color:var(--text3)">(auto-generate)</small></label>
                        <input name="nomor_penerimaan" id="noRec" value="<?= $noRecAuto ?>" readonly/>
                    </div>
                    <div class="form-group">
                        <label>Tanggal Terima</label>
                        <input type="date" name="tanggal_terima" id="batchTgl" value="<?= date('Y-m-d') ?>" required/>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Klien / Perusahaan</label>
                        <input name="klien" id="batchKlien" placeholder="Nama perusahaan" required
                               list="klienDL2" oninput="updateBatchHeader()"/>
                        <datalist id="klienDL2">
                            <?php foreach ($klienAll as $k): ?><option value="<?= bersihkan($k) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label>Keterangan Batch</label>
                        <input name="keterangan" placeholder="Catatan penerimaan..."/>
                    </div>
                </div>

                <!-- Shortcut: isi metode & material yang sama untuk semua baris -->
                <div style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:12px;margin-top:4px">
                    <div style="font-size:.72rem;color:var(--gold);font-weight:700;margin-bottom:8px">&#9881; Isi Cepat — Terapkan ke Semua Baris</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end">
                        <div class="form-group">
                            <label>Material (semua)</label>
                            <select id="quickMaterial">
                                <option value="">— Pilih —</option>
                                <?php foreach ($materialOpts as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Metode (semua)</label>
                            <select id="quickMetode">
                                <option value="">— Pilih —</option>
                                <?php foreach ($metodeOpts as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Berat Default (g)</label>
                            <input type="number" step="0.001" id="quickBerat" placeholder="0.000"/>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:5px">&nbsp;</label>
                            <button type="button" class="btn btn-green btn-sm" onclick="applyQuickFill()">&#9654; Terapkan</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── SEKSI 2: TABEL SAMPEL ────────────────── -->
            <div class="card" style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <div class="card-title" style="margin-bottom:0">&#129704; Daftar Sampel dalam Batch</div>
                    <div style="display:flex;gap:8px">
                        <button type="button" class="btn btn-green btn-sm" onclick="tambahBarisBatch()">&#10133; Tambah Baris</button>
                        <button type="button" class="btn btn-sm" style="background:var(--bg3);border:1px solid var(--border);color:var(--text2)"
                                onclick="tambahBanyak()">&#10133; Tambah 5 Sekaligus</button>
                    </div>
                </div>

                <!-- Batch info strip (muncul setelah klien diisi) -->
                <div class="batch-info-strip" id="batchInfoStrip">
                    <div class="bi-item"><div class="bi-lbl">No. Penerimaan</div><div class="bi-val" id="biNoRec">—</div></div>
                    <div class="bi-item"><div class="bi-lbl">Klien</div><div class="bi-val" id="biKlien">—</div></div>
                    <div class="bi-item"><div class="bi-lbl">Tanggal</div><div class="bi-val" id="biTgl">—</div></div>
                    <div class="bi-item"><div class="bi-lbl">Total Sampel</div><div class="bi-val" id="biTotal">0</div></div>
                </div>

                <div style="overflow-x:auto">
                <table class="batch-input-tbl" id="batchInputTbl">
                    <thead>
                        <tr>
                            <th style="width:36px">No.</th>
                            <th style="width:130px">Kode Sampel <small style="font-weight:400">(auto)</small></th>
                            <th>Jenis Material <span style="color:var(--red)">*</span></th>
                            <th style="width:90px">Berat (g)</th>
                            <th>Metode Uji <span style="color:var(--red)">*</span></th>
                            <th>Keterangan</th>
                            <th style="width:36px"></th>
                        </tr>
                    </thead>
                    <tbody id="batchRows">
                        <!-- Baris diisi oleh JS -->
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" style="padding:8px 6px">
                                <div style="display:flex;justify-content:space-between;align-items:center">
                                    <span style="font-size:.72rem;color:var(--text3)">
                                        Total: <strong id="totalBatch" style="color:var(--gold)">0</strong> sampel
                                        &nbsp;·&nbsp;
                                        Total berat: <strong id="totalBerat" style="color:var(--green)">0.000</strong> g
                                    </span>
                                    <span style="font-size:.72rem;color:var(--text3)">
                                        * Kode sampel digenerate otomatis saat disimpan
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>

            <!-- ── SEKSI 3: SIMPAN ──────────────────────── -->
            <div class="card">
                <div class="card-title">&#128190; Konfirmasi &amp; Simpan</div>

                <!-- Preview ringkasan sebelum simpan -->
                <div id="summaryPreview" style="background:var(--bg3);border-radius:6px;padding:12px;margin-bottom:14px;font-size:.8rem;display:none">
                    <div style="color:var(--gold);font-weight:700;margin-bottom:8px">&#128204; Ringkasan Penerimaan</div>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
                        <div><span style="color:var(--text3)">No. Penerimaan</span><br><strong id="sumNoRec">—</strong></div>
                        <div><span style="color:var(--text3)">Klien</span><br><strong id="sumKlien">—</strong></div>
                        <div><span style="color:var(--text3)">Tanggal</span><br><strong id="sumTgl">—</strong></div>
                        <div><span style="color:var(--text3)">Total Sampel</span><br><strong id="sumTotal" style="color:var(--gold)">0</strong></div>
                        <div><span style="color:var(--text3)">Total Berat</span><br><strong id="sumBerat" style="color:var(--green)">0.000 g</strong></div>
                        <div><span style="color:var(--text3)">Metode</span><br><strong id="sumMetode">—</strong></div>
                    </div>
                </div>

                <div style="display:flex;gap:10px;align-items:center">
                    <button type="button" class="btn btn-green" onclick="previewSimpan()">&#128065; Preview &amp; Verifikasi</button>
                    <button type="submit" class="btn btn-gold" id="btnSimpanBatch" style="display:none">&#128190; Simpan Penerimaan &amp; Semua Sampel</button>
                    <button type="button" class="btn btn-sm" style="background:var(--bg3);border:1px solid var(--border);color:var(--text2)" onclick="resetBatch()">&#8635; Reset Form</button>
                </div>
            </div>

        </form>
    </div><!-- /mode-batch -->
</div><!-- /tab-input -->

<!-- ══════════════════════════════════════════════════
     TAB 3 — STATISTIK
══════════════════════════════════════════════════ -->
<div id="tab-riwayat" class="tab-pane <?= $tab==='riwayat'?'active':'' ?>">
    <div class="grid2">
        <div class="card">
            <div class="card-title">&#128202; Per Material</div>
            <?php $sByMat=$pdo->query("SELECT jenis_material,COUNT(*) n FROM sampel GROUP BY jenis_material ORDER BY n DESC LIMIT 8")->fetchAll();
            $mx=max(array_column($sByMat,'n')?:[1]);
            foreach ($sByMat as $r): ?>
            <div class="prog-wrap" style="margin-bottom:8px">
                <div class="prog-label"><span><?= bersihkan($r['jenis_material']) ?></span><span><?= $r['n'] ?></span></div>
                <div class="prog"><div class="prog-bar pb-green" style="width:<?= round($r['n']/$mx*100) ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <div class="card-title">&#128202; Per Klien</div>
            <?php $sByKlien=$pdo->query("SELECT klien,COUNT(*) n FROM sampel GROUP BY klien ORDER BY n DESC LIMIT 8")->fetchAll();
            $mx2=max(array_column($sByKlien,'n')?:[1]);
            foreach ($sByKlien as $r): ?>
            <div class="prog-wrap" style="margin-bottom:8px">
                <div class="prog-label"><span><?= bersihkan($r['klien']) ?></span><span><?= $r['n'] ?></span></div>
                <div class="prog"><div class="prog-bar pb-gold" style="width:<?= round($r['n']/$mx2*100) ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php $belum=$pdo->query("SELECT s.kode_sampel,s.jenis_material,s.klien,s.tanggal_masuk,s.status,rec.nomor_penerimaan FROM sampel s LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id=rec.id WHERE NOT EXISTS(SELECT 1 FROM hasil_uji h WHERE h.sampel_id=s.id) ORDER BY s.tanggal_masuk ASC")->fetchAll(); ?>
    <?php if ($belum): ?>
    <div class="card">
        <div class="card-title">&#9888; Sampel Belum Ada Hasil Uji <span><?= count($belum) ?> sampel</span></div>
        <table>
            <thead><tr><th>Kode</th><th>Batch</th><th>Material</th><th>Klien</th><th>Tgl Masuk</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($belum as $b): ?>
            <tr>
                <td style="color:var(--gold)"><?= bersihkan($b['kode_sampel']) ?></td>
                <td><?= $b['nomor_penerimaan']?'<span class="batch-badge-sm">'.bersihkan($b['nomor_penerimaan']).'</span>':'—' ?></td>
                <td><?= bersihkan($b['jenis_material']) ?></td>
                <td><?= bersihkan($b['klien']) ?></td>
                <td><?= date('d/m/Y',strtotime($b['tanggal_masuk'])) ?></td>
                <td><?= badgeStatus($b['status']) ?></td>
                <td><a href="<?= BASE_URL ?>/pages/pengujian.php" class="btn btn-gold btn-sm" style="font-size:.68rem">&#128300; Input Uji</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
// ── TAB SWITCH ────────────────────────────────────────────────
function switchTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    if (el) el.classList.add('active');
    history.replaceState(null,'','?tab='+name);
}

// ── MODE SWITCH (tunggal / batch) ─────────────────────────────
function switchMode(mode) {
    document.querySelectorAll('.mode-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.mode-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('mode-'+mode).classList.add('active');
    document.getElementById('btnMode'+mode.charAt(0).toUpperCase()+mode.slice(1)).classList.add('active');
    updateStepper();
}

// ── BATCH INPUT ───────────────────────────────────────────────
const matOpts = <?= json_encode($materialOpts) ?>;
const metOpts = <?= json_encode($metodeOpts) ?>;
let rowCnt = 0;
// Kode sampel preview (hanya tampilan)
let baseNextNum = <?= $nextNum ?>;

function genKodePreview(offset) {
    const n = baseNextNum + offset;
    const ym = '<?= date('ym') ?>';
    return 'S-' + ym + '-' + String(n).padStart(3,'0');
}

function tambahBarisBatch(matDefault='', metDefault='', beratDefault='') {
    rowCnt++;
    const i    = rowCnt;
    const idx  = document.querySelectorAll('#batchRows tr').length; // offset untuk kode preview
    const kode = genKodePreview(idx);
    const mO   = matOpts.map(m=>`<option ${m===matDefault?'selected':''}>${m}</option>`).join('');
    const eO   = metOpts.map(m=>`<option ${m===metDefault?'selected':''}>${m}</option>`).join('');

    const tr = document.createElement('tr');
    tr.id = 'br' + i;
    tr.innerHTML = `
        <td class="row-num">${idx+1}</td>
        <td>
            <input class="cell-in" value="${kode}" readonly
                   style="color:var(--text3);font-family:monospace;font-size:.72rem"/>
        </td>
        <td>
            <select name="sampel[${i}][jenis_material]" class="cell-in" onchange="updateTotals()" required>
                <option value="">— Pilih —</option>${mO}
            </select>
        </td>
        <td>
            <input type="number" step="0.001" name="sampel[${i}][berat_gram]"
                   class="cell-in berat-input" placeholder="0.000"
                   value="${beratDefault}" oninput="updateTotals()"/>
        </td>
        <td>
            <select name="sampel[${i}][metode_uji]" class="cell-in" required>
                <option value="">— Pilih —</option>${eO}
            </select>
        </td>
        <td>
            <input type="text" name="sampel[${i}][keterangan]"
                   class="cell-in" placeholder="Opsional"/>
        </td>
        <td style="text-align:center">
            <button type="button" onclick="hapusBaris(${i})"
                    style="background:var(--red);color:#fff;border:none;border-radius:4px;
                           padding:3px 9px;cursor:pointer;font-size:.75rem">&#10005;</button>
        </td>`;
    document.getElementById('batchRows').appendChild(tr);
    renumberRows();
    updateTotals();
    updateBatchHeader();
}

function hapusBaris(i) {
    document.getElementById('br'+i)?.remove();
    renumberRows();
    updateTotals();
    updateBatchHeader();
}

function renumberRows() {
    const rows = document.querySelectorAll('#batchRows tr');
    rows.forEach((tr, idx) => {
        // Update nomor
        const numCell = tr.querySelector('.row-num');
        if (numCell) numCell.textContent = idx + 1;
        // Update kode preview
        const kodeInput = tr.querySelectorAll('input')[0];
        if (kodeInput) kodeInput.value = genKodePreview(idx);
    });
}

function tambahBanyak() {
    for (let i=0;i<5;i++) tambahBarisBatch();
}

function applyQuickFill() {
    const mat   = document.getElementById('quickMaterial').value;
    const met   = document.getElementById('quickMetode').value;
    const berat = document.getElementById('quickBerat').value;

    document.querySelectorAll('#batchRows tr').forEach(tr => {
        if (mat)   { const s=tr.querySelector('select[name*="jenis_material"]'); if(s){ [...s.options].forEach(o=>o.selected=o.text===mat); } }
        if (met)   { const s=tr.querySelector('select[name*="metode_uji"]');     if(s){ [...s.options].forEach(o=>o.selected=o.text===met); } }
        if (berat) { const b=tr.querySelector('input[name*="berat_gram"]');      if(b) b.value=berat; }
    });
    updateTotals();
}

function updateTotals() {
    const rows   = document.querySelectorAll('#batchRows tr');
    const n      = rows.length;
    let totalB   = 0;
    rows.forEach(tr => {
        const bInput = tr.querySelector('.berat-input');
        if (bInput && bInput.value) totalB += parseFloat(bInput.value) || 0;
    });
    document.getElementById('totalBatch').textContent = n;
    document.getElementById('totalBerat').textContent = totalB.toFixed(3);
    document.getElementById('biTotal').textContent = n + ' sampel';
    hideSummary();
}

function updateBatchHeader() {
    const klien = document.getElementById('batchKlien').value;
    const tgl   = document.getElementById('batchTgl').value;
    const noRec = document.getElementById('noRec').value;
    const strip = document.getElementById('batchInfoStrip');

    if (klien) {
        strip.classList.add('show');
        document.getElementById('biNoRec').textContent = noRec;
        document.getElementById('biKlien').textContent = klien;
        document.getElementById('biTgl').textContent   = tgl ? new Date(tgl).toLocaleDateString('id-ID') : '—';
    } else {
        strip.classList.remove('show');
    }
    updateStepper();
}

function hideSummary() {
    document.getElementById('summaryPreview').style.display = 'none';
    document.getElementById('btnSimpanBatch').style.display = 'none';
}

function previewSimpan() {
    const klien   = document.getElementById('batchKlien').value.trim();
    const tgl     = document.getElementById('batchTgl').value;
    const noRec   = document.getElementById('noRec').value;
    const rows    = document.querySelectorAll('#batchRows tr');
    const total   = rows.length;
    const totalB  = document.getElementById('totalBerat').textContent;

    if (!klien) { alert('Isi nama klien terlebih dahulu.'); return; }
    if (total === 0) { alert('Tambahkan minimal 1 sampel.'); return; }

    // Validasi: semua baris harus punya material & metode
    let valid = true;
    rows.forEach((tr, idx) => {
        const mat = tr.querySelector('select[name*="jenis_material"]')?.value;
        const met = tr.querySelector('select[name*="metode_uji"]')?.value;
        if (!mat || !met) { valid = false; alert('Baris '+(idx+1)+': Material dan Metode wajib diisi.'); }
    });
    if (!valid) return;

    // Kumpulkan metode unik
    const metSet = new Set();
    rows.forEach(tr => {
        const met = tr.querySelector('select[name*="metode_uji"]')?.value;
        if (met) metSet.add(met);
    });

    // Tampilkan summary
    document.getElementById('sumNoRec').textContent  = noRec;
    document.getElementById('sumKlien').textContent  = klien;
    document.getElementById('sumTgl').textContent    = tgl ? new Date(tgl).toLocaleDateString('id-ID') : '—';
    document.getElementById('sumTotal').textContent  = total;
    document.getElementById('sumBerat').textContent  = totalB + ' g';
    document.getElementById('sumMetode').textContent = [...metSet].join(', ');
    document.getElementById('summaryPreview').style.display = 'block';
    document.getElementById('btnSimpanBatch').style.display = 'inline-block';

    // Scroll ke tombol simpan
    document.getElementById('btnSimpanBatch').scrollIntoView({behavior:'smooth',block:'center'});
    updateStepper(3);
}

function resetBatch() {
    if (!confirm('Reset semua data form batch?')) return;
    document.getElementById('batchRows').innerHTML = '';
    document.getElementById('batchKlien').value = '';
    document.getElementById('batchTgl').value   = '<?= date('Y-m-d') ?>';
    rowCnt = 0; baseNextNum = <?= $nextNum ?>;
    updateTotals();
    hideSummary();
    document.getElementById('batchInfoStrip').classList.remove('show');
    updateStepper(1);
    // Tambah 1 baris kosong
    tambahBarisBatch();
}

function updateStepper(forceStep) {
    const klien = document.getElementById('batchKlien')?.value?.trim();
    const rows  = document.querySelectorAll('#batchRows tr').length;
    let step = forceStep || (klien ? (rows > 0 ? 2 : 1) : 1);

    ['step1','step2','step3'].forEach((id, i) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.className = 'step ' + (i+1 < step ? 'done' : (i+1 === step ? 'active' : ''));
    });
}

// Init: 1 baris kosong
tambahBarisBatch();
updateStepper(1);

// ── DAFTAR SAMPEL JS ──────────────────────────────────────────
function toggleBatch(noRec) {
    const rows = document.querySelectorAll('[data-batch="'+CSS.escape(noRec)+'"]');
    const ico  = document.getElementById('ico-'+noRec);
    const hidden = rows.length > 0 && rows[0].style.display === 'none';
    rows.forEach(r => r.style.display = hidden ? 'table-row' : 'none');
    if (ico) ico.innerHTML = hidden ? '&#9650; collapse' : '&#9660; expand';
}

function updateSel() {
    const checked = document.querySelectorAll('.chk-row:checked');
    const n = checked.length;
    document.getElementById('selCount').textContent = n;
    document.getElementById('actionBar').classList.toggle('show', n>0);
    const ids = Array.from(checked).map(c=>c.value).join(',');
    document.getElementById('massIds').value = ids;
    const params = ids.split(',').filter(Boolean).map(id=>'sampel_id[]='+id).join('&');
    document.getElementById('btnGoUji').href = '<?= BASE_URL ?>/pages/pengujian.php?' + params;
}

function toggleAll(el) {
    document.querySelectorAll('.chk-row').forEach(c=>c.checked=el.checked);
    updateSel();
}

function toggleBatchCheck(noRec, el) {
    document.querySelectorAll('[data-batch="'+CSS.escape(noRec)+'"] .chk-row').forEach(c=>c.checked=el.checked);
    updateSel();
}

function clearSel() {
    document.querySelectorAll('.chk-row,#chkAll,.chk-batch').forEach(c=>c.checked=false);
    updateSel();
}

function massUpdate() {
    const st = document.getElementById('massStatus').value;
    if (!st) { alert('Pilih status terlebih dahulu.'); return; }
    document.getElementById('massForm').submit();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
