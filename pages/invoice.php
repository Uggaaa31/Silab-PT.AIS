<?php
// ============================================================
//  invoice.php — #4 Modul Invoice
//  UPDATE: Role-based access - Admin full, Supervisor read only
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

// Cek akses invoice
if (!canAccessInvoice()) {
    $_SESSION['msg'] = 'ERROR: Anda tidak memiliki akses ke halaman Invoice.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Invoice';
$canEdit = isAdmin(); // Hanya admin yang bisa edit
$canView = isAdmin() || isSupervisor();
$isReadOnly = !$canEdit;

$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$tab = $_GET['tab'] ?? 'daftar';

// ── Auto-generate nomor invoice ──────────────────────────────
$lastInv = $pdo->query("SELECT nomor_invoice FROM invoice ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextInv = $lastInv ? (intval(substr($lastInv, -3)) + 1) : 1;
$noAuto  = 'INV-' . date('ym') . '-' . str_pad($nextInv, 3, '0', STR_PAD_LEFT);

// ── Data untuk form ──────────────────────────────────────────
$batchList  = $pdo->query(
    "SELECT p.id, p.nomor_penerimaan, p.klien, p.tanggal_terima,
            p.jumlah_sampel
     FROM penerimaan_sampel p
     WHERE p.id NOT IN (SELECT penerimaan_id FROM invoice WHERE penerimaan_id IS NOT NULL)
        OR p.id IS NULL
     ORDER BY p.created_at DESC"
)->fetchAll();

$tarifList  = $pdo->query(
    "SELECT * FROM tarif_pengujian WHERE aktif=1 ORDER BY metode, nama"
)->fetchAll();

$klienAll   = $pdo->query(
    "SELECT DISTINCT klien FROM penerimaan_sampel ORDER BY klien"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Daftar invoice ───────────────────────────────────────────
$fStatus    = $_GET['fstatus'] ?? '';
$search     = trim($_GET['q'] ?? '');
$sqlInv     = "SELECT i.*, u.nama AS operator,
                      p.nomor_penerimaan
               FROM invoice i
               LEFT JOIN pengguna u ON i.dibuat_oleh = u.id
               LEFT JOIN penerimaan_sampel p ON i.penerimaan_id = p.id
               WHERE 1=1";
$prmInv = [];
if ($fStatus) { $sqlInv .= " AND i.status=?";          $prmInv[]=$fStatus; }
if ($search)  { $sqlInv .= " AND (i.nomor_invoice LIKE ? OR i.klien LIKE ?)";
                $prmInv = array_merge($prmInv,["%$search%","%$search%"]); }
$sqlInv .= " ORDER BY i.created_at DESC";
$stInv  = $pdo->prepare($sqlInv); $stInv->execute($prmInv);
$invList = $stInv->fetchAll();

// ── Statistik ────────────────────────────────────────────────
$invDraft   = $pdo->query("SELECT COUNT(*) FROM invoice WHERE status='draft'")->fetchColumn();
$invTerbit  = $pdo->query("SELECT COUNT(*) FROM invoice WHERE status='diterbitkan'")->fetchColumn();
$invLunas   = $pdo->query("SELECT COUNT(*) FROM invoice WHERE status='lunas'")->fetchColumn();
$totalPiutang = $pdo->query("SELECT COALESCE(SUM(total),0) FROM invoice WHERE status='diterbitkan'")->fetchColumn();

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
.smc .v{font-size:1.4rem;font-weight:700;color:var(--green);}
.smc .v.gold{color:var(--gold);}.smc .v.yellow{color:var(--yellow);}.smc .v.red{color:var(--red);}
.smc .l{font-size:.7rem;color:var(--text3);margin-top:2px;}
/* Item table */
.item-tbl{width:100%;border-collapse:collapse;font-size:.8rem;}
.item-tbl thead th{background:var(--bg3);color:var(--text3);font-size:.72rem;padding:6px 8px;text-align:left;border-bottom:1px solid var(--border);}
.item-tbl tbody td{padding:5px 8px;border-bottom:1px solid #1a3525;vertical-align:middle;}
.item-tbl tfoot td{padding:8px;font-size:.82rem;border-top:1px solid var(--border);}
.cell-in{width:100%;background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:5px 8px;border-radius:5px;font-size:.78rem;outline:none;}
.cell-in:focus{border-color:var(--green3);}
/* Total box */
.total-box{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:14px 16px;font-size:.82rem;}
.total-row{display:flex;justify-content:space-between;padding:4px 0;}
.total-row.grand{border-top:1px solid var(--border);margin-top:6px;padding-top:8px;font-size:1rem;font-weight:700;color:var(--gold);}
/* Status badges */
.inv-draft    {background:#2d1a00;color:#e89c30;border-radius:10px;font-size:.68rem;padding:2px 9px;font-weight:600;}
.inv-diterbitkan{background:#0d2a3d;color:#3498db;border-radius:10px;font-size:.68rem;padding:2px 9px;font-weight:600;}
.inv-lunas    {background:#1a4d2e;color:#2ecc71;border-radius:10px;font-size:.68rem;padding:2px 9px;font-weight:600;}
.inv-dibatalkan{background:#3d1010;color:#e74c3c;border-radius:10px;font-size:.68rem;padding:2px 9px;font-weight:600;}
.readonly-badge{background:#f39c12;color:#1a1a2e;padding:4px 12px;border-radius:20px;font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:6px;}
</style>

<div class="sec-title">Invoice &amp; Penagihan</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR')?'alert-red':'alert-green' ?>" style="margin-bottom:14px">
        <?= str_starts_with($msg,'ERROR')?'&#9888;':'&#10003;' ?> <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<?php if ($isReadOnly && !$canEdit): ?>
<div class="alert-box alert-yellow" style="margin-bottom:14px">
    🔒 <strong>Mode Read Only</strong> - Anda hanya dapat melihat data. Untuk mengedit, login sebagai Administrator.
</div>
<?php endif; ?>

<div class="stat-mini">
    <div class="smc"><div class="v yellow"><?= $invDraft ?></div><div class="l">Draft</div></div>
    <div class="smc"><div class="v gold"><?= $invTerbit ?></div><div class="l">Diterbitkan / Belum Lunas</div></div>
    <div class="smc"><div class="v"><?= $invLunas ?></div><div class="l">Lunas</div></div>
    <div class="smc">
        <div class="v gold" style="font-size:1.1rem">Rp <?= number_format($totalPiutang, 0, ',', '.') ?></div>
        <div class="l">Total Piutang</div>
    </div>
</div>

<div class="tabs">
    <button class="tab-btn <?= $tab==='daftar'?'active':'' ?>" onclick="switchTab('daftar',this)">&#128203; Daftar Invoice</button>
    <?php if ($canEdit): ?>
        <button class="tab-btn <?= $tab==='buat'?'active':'' ?>"   onclick="switchTab('buat',this)">&#10133; Buat Invoice</button>
        <button class="tab-btn <?= $tab==='tarif'?'active':'' ?>"  onclick="switchTab('tarif',this)">&#128176; Tarif Pengujian</button>
    <?php else: ?>
        <button class="tab-btn <?= $tab==='tarif'?'active':'' ?>"  onclick="switchTab('tarif',this)">&#128176; Tarif Pengujian (Read Only)</button>
    <?php endif; ?>
</div>

<!-- ══ TAB 1 — DAFTAR INVOICE ════════════════════════════ -->
<div id="tab-daftar" class="tab-pane <?= $tab==='daftar'?'active':'' ?>">

    <form method="GET" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
        <input type="hidden" name="tab" value="daftar"/>
        <input name="q" value="<?= bersihkan($search) ?>" placeholder="&#128269; Cari no invoice / klien..."
               style="flex:1;background:var(--bg3);border:1px solid var(--border);color:var(--text);
                      padding:7px 12px;border-radius:6px;font-size:.8rem;outline:none"/>
        <select name="fstatus" style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:.8rem;outline:none">
            <option value="">Semua Status</option>
            <?php foreach (['draft','diterbitkan','lunas','dibatalkan'] as $st): ?>
                <option value="<?= $st ?>" <?= $fStatus===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-green btn-sm">Cari</button>
    </form>

    <div class="card">
        <div class="card-title">&#128179; Daftar Invoice <span><?= count($invList) ?> data</span></div>
        <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                    <th>No. Invoice</th><th>Batch</th><th>Klien</th>
                    <th>Tgl Invoice</th><th>Jatuh Tempo</th>
                    <th style="text-align:right">Total</th>
                    <th>Status</th><th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invList as $inv): ?>
            <tr>
                <td style="font-weight:700;color:var(--gold);font-family:monospace">
                    <?= bersihkan($inv['nomor_invoice']) ?>
                </td>
                <td style="font-size:.75rem;color:var(--text3)">
                    <?= bersihkan($inv['nomor_penerimaan'] ?? '—') ?>
                </td>
                <td><?= bersihkan($inv['klien']) ?></td>
                <td><?= fmtTgl($inv['tanggal_invoice']) ?></td>
                <td>
                    <?php
                    $jt   = $inv['tanggal_jatuh_tempo'];
                    $lewat= $jt && strtotime($jt) < time() && $inv['status']==='diterbitkan';
                    ?>
                    <span style="color:<?= $lewat ? 'var(--red)' : 'var(--text2)' ?>">
                        <?= $jt ? fmtTgl($jt) : '—' ?>
                        <?= $lewat ? ' &#9888;' : '' ?>
                    </span>
                </td>
                <td style="text-align:right;font-weight:700;color:var(--gold)">
                    Rp <?= number_format($inv['total'], 0, ',', '.') ?>
                </td>
                <td>
                    <span class="inv-<?= $inv['status'] ?>"><?= ucfirst($inv['status']) ?></span>
                </td>
                <td style="white-space:nowrap">
                    <!-- Cetak (semua role bisa) -->
                    <a href="<?= BASE_URL ?>/exports/cetak_invoice.php?id=<?= $inv['id'] ?>"
                       target="_blank" class="btn btn-gold btn-sm" style="font-size:.68rem;padding:3px 8px">
                        &#128196; Cetak
                    </a>
                    <!-- Ubah status (hanya admin) -->
                    <?php if ($canEdit): ?>
                        <?php if ($inv['status']==='draft'): ?>
                            <form method="POST" action="<?= BASE_URL ?>/actions/simpan_invoice.php" style="display:inline">
                                <input type="hidden" name="action" value="terbitkan"/>
                                <input type="hidden" name="id" value="<?= $inv['id'] ?>"/>
                                <button type="submit" class="btn btn-green btn-sm" style="font-size:.68rem;padding:3px 8px">
                                    &#9654; Terbitkan
                                </button>
                            </form>
                        <?php elseif ($inv['status']==='diterbitkan'): ?>
                            <form method="POST" action="<?= BASE_URL ?>/actions/simpan_invoice.php" style="display:inline">
                                <input type="hidden" name="action" value="lunas"/>
                                <input type="hidden" name="id" value="<?= $inv['id'] ?>"/>
                                <button type="submit" class="btn btn-sm" style="background:var(--green3);color:#fff;font-size:.68rem;padding:3px 8px">
                                    &#10003; Lunas
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$invList): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:20px">Belum ada invoice.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ══ TAB 2 — BUAT INVOICE (HANYA ADMIN) ═══════════════════ -->
<?php if ($canEdit): ?>
<div id="tab-buat" class="tab-pane <?= $tab==='buat'?'active':'' ?>">
<form method="POST" action="<?= BASE_URL ?>/actions/simpan_invoice.php" id="formInvoice">
<input type="hidden" name="action" value="buat"/>

<div class="grid2">
    <!-- KIRI: info invoice -->
    <div>
        <div class="card" style="margin-bottom:14px">
            <div class="card-title">&#128179; Informasi Invoice</div>
            <div class="form-row">
                <div class="form-group"><label>No. Invoice</label>
                    <input name="nomor_invoice" value="<?= $noAuto ?>" readonly/></div>
                <div class="form-group"><label>Tanggal Invoice</label>
                    <input type="date" name="tanggal_invoice" value="<?= date('Y-m-d') ?>" required/></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Klien / Perusahaan</label>
                    <input name="klien" placeholder="Nama klien" required list="klienInvDL"
                           oninput="updateKlienInfo(this.value)"/>
                    <datalist id="klienInvDL">
                        <?php foreach ($klienAll as $k): ?><option value="<?= bersihkan($k) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group"><label>Tanggal Jatuh Tempo</label>
                    <input type="date" name="tanggal_jatuh_tempo"
                           value="<?= date('Y-m-d', strtotime('+14 days')) ?>"/></div>
            </div>
            <div class="form-group" style="margin-bottom:12px">
                <label>Tautkan ke Batch Penerimaan (opsional)</label>
                <select name="penerimaan_id" id="selBatch" onchange="loadBatchItems(this)">
                    <option value="">— Tanpa batch / manual —</option>
                    <?php foreach ($batchList as $b): ?>
                        <option value="<?= $b['id'] ?>"
                                data-klien="<?= bersihkan($b['klien']) ?>"
                                data-tgl="<?= $b['tanggal_terima'] ?>"
                                data-jml="<?= $b['jumlah_sampel'] ?>">
                            <?= bersihkan($b['nomor_penerimaan']) ?> — <?= bersihkan($b['klien']) ?>
                            (<?= $b['jumlah_sampel'] ?> sampel, <?= fmtTgl($b['tanggal_terima']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:12px">
                <label>Alamat Klien</label>
                <textarea name="alamat_klien" rows="2" id="alamatKlien" placeholder="Alamat penagihan..."></textarea>
            </div>
            <div class="form-group">
                <label>Catatan</label>
                <input name="catatan" placeholder="Catatan tambahan di invoice..."/>
            </div>
        </div>

        <!-- Total box -->
        <div class="total-box">
            <div class="total-row"><span style="color:var(--text3)">Subtotal</span>
                <span id="dispSubtotal">Rp 0</span></div>
            <div class="total-row" style="align-items:center">
                <span style="color:var(--text3)">Diskon</span>
                <span style="display:flex;align-items:center;gap:6px">
                    <input type="number" step="0.01" name="diskon_pct" id="diskonPct"
                           value="0" min="0" max="100" oninput="hitungTotal()"
                           style="width:60px;background:var(--bg3);border:1px solid var(--border);color:var(--text);
                                  padding:4px 6px;border-radius:4px;font-size:.78rem;outline:none;text-align:right"/>
                    <span style="color:var(--text3)">%</span>
                    <span id="dispDiskon" style="color:var(--red)">— Rp 0</span>
                </span>
            </div>
            <div class="total-row" style="align-items:center">
                <span style="color:var(--text3)">PPN</span>
                <span style="display:flex;align-items:center;gap:6px">
                    <input type="number" step="0.01" name="ppn_pct" id="ppnPct"
                           value="11" min="0" max="100" oninput="hitungTotal()"
                           style="width:60px;background:var(--bg3);border:1px solid var(--border);color:var(--text);
                                  padding:4px 6px;border-radius:4px;font-size:.78rem;outline:none;text-align:right"/>
                    <span style="color:var(--text3)">%</span>
                    <span id="dispPPN">+ Rp 0</span>
                </span>
            </div>
            <div class="total-row grand">
                <span>TOTAL</span>
                <span id="dispTotal">Rp 0</span>
            </div>
            <!-- Hidden fields untuk submit -->
            <input type="hidden" name="subtotal"       id="hidSubtotal" value="0"/>
            <input type="hidden" name="diskon_nominal" id="hidDiskon"   value="0"/>
            <input type="hidden" name="ppn_nominal"    id="hidPPN"      value="0"/>
            <input type="hidden" name="total"          id="hidTotal"    value="0"/>
        </div>
    </div>

    <!-- KANAN: item-item invoice -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <div class="card-title" style="margin-bottom:0">&#128176; Item Tagihan</div>
            <div style="display:flex;gap:8px">
                <button type="button" class="btn btn-green btn-sm" onclick="tambahItem()">&#10133; Tambah Item</button>
                <button type="button" class="btn btn-sm"
                        style="background:var(--bg3);border:1px solid var(--border);color:var(--text2)"
                        onclick="tambahItemDariTarif()">&#128176; Dari Tarif</button>
            </div>
        </div>
        <table class="item-tbl" id="itemTable">
            <thead>
                32tr
                    <th style="width:36px">No.</th>
                    <th>Deskripsi Layanan</th>
                    <th style="width:80px">Qty</th>
                    <th style="width:160px">Harga Satuan (Rp)</th>
                    <th style="width:140px">Subtotal (Rp)</th>
                    <th style="width:36px"></th>
                </tr>
            </thead>
            <tbody id="itemRows"></tbody>
            <tfoot>
                <tr><td colspan="6" style="font-size:.72rem;color:var(--text3);padding:8px">
                    Klik <strong>Tambah Item</strong> untuk menambah baris tagihan manual,
                    atau <strong>Dari Tarif</strong> untuk pilih dari daftar tarif.
                </td></tr>
            </tfoot>
        </table>
    </div>
</div>

<div style="margin-top:14px;display:flex;gap:10px">
    <button type="submit" name="status_awal" value="draft" class="btn btn-sm"
            style="background:var(--bg3);border:1px solid var(--border);color:var(--text2)">
        &#128196; Simpan Draft
    </button>
    <button type="submit" name="status_awal" value="diterbitkan" class="btn btn-gold">
        &#9654; Simpan &amp; Terbitkan
    </button>
</div>
</form>

<!-- Modal pilih tarif -->
<div id="modalTarif" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;align-items:center;justify-content:center">
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:20px;width:560px;max-width:95vw;max-height:80vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <span style="font-size:.9rem;font-weight:700;color:var(--gold)">&#128176; Pilih dari Tarif Pengujian</span>
            <button onclick="document.getElementById('modalTarif').style.display='none'"
                    style="background:none;border:none;color:var(--text3);font-size:1.2rem;cursor:pointer">&#10005;</button>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:.8rem">
            <thead>32tr
                <th style="padding:6px;text-align:left;border-bottom:1px solid var(--border);color:var(--text3)">Nama Layanan</th>
                <th style="padding:6px;text-align:left;border-bottom:1px solid var(--border);color:var(--text3)">Metode</th>
                <th style="padding:6px;text-align:right;border-bottom:1px solid var(--border);color:var(--text3)">Harga</th>
                <th style="padding:6px;border-bottom:1px solid var(--border)"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($tarifList as $t): ?>
            <tr>
                <td style="padding:7px 6px;border-bottom:1px solid #1a3525"><?= bersihkan($t['nama']) ?></td>
                <td style="padding:7px 6px;border-bottom:1px solid #1a3525;color:var(--text3)"><?= bersihkan($t['metode'] ?? '—') ?></td>
                <td style="padding:7px 6px;border-bottom:1px solid #1a3525;text-align:right;color:var(--gold)">
                    Rp <?= number_format($t['harga'],0,',','.') ?>
                </td>
                <td style="padding:7px 6px;border-bottom:1px solid #1a3525">
                    <button type="button"
                            onclick="pilihTarif(<?= $t['id'] ?>,'<?= addslashes($t['nama']) ?>',<?= $t['harga'] ?>)"
                            class="btn btn-green btn-sm" style="font-size:.68rem;padding:3px 8px">
                        &#10133; Pilih
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- /tab-buat -->
<?php else: ?>
<!-- Jika bukan admin, tampilkan pesan bahwa tab buat invoice tidak tersedia -->
<div id="tab-buat" class="tab-pane <?= $tab==='buat'?'active':'' ?>">
    <div class="card">
        <div class="card-title">&#128179; Buat Invoice</div>
        <div class="alert-box alert-yellow" style="margin:20px">
            🔒 Hanya Administrator yang dapat membuat invoice baru.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ TAB 3 — TARIF PENGUJIAN ════════════════════════════ -->
<div id="tab-tarif" class="tab-pane <?= $tab==='tarif'?'active':'' ?>">
    <div class="grid2">
        <div class="card">
            <div class="card-title">&#128176; Daftar Tarif Aktif</div>
            <table class="data-table">
                <thead>32tr<th>Nama Layanan</th><th>Metode</th><th>Satuan</th><th style="text-align:right">Harga (Rp)</th></tr></thead>
                <tbody>
                <?php foreach ($tarifList as $t): ?>
                <tr>
                    <td><?= bersihkan($t['nama']) ?></td>
                    <td style="color:var(--text3)"><?= bersihkan($t['metode'] ?? '—') ?></td>
                    <td style="color:var(--text3);font-size:.75rem"><?= bersihkan($t['satuan']) ?></td>
                    <td style="text-align:right;color:var(--gold);font-weight:700">
                        <?= number_format($t['harga'],0,',','.') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEdit): ?>
        <div class="card">
            <div class="card-title">&#10133; Tambah / Update Tarif</div>
            <form method="POST" action="<?= BASE_URL ?>/actions/simpan_invoice.php">
                <input type="hidden" name="action" value="tarif"/>
                <div class="form-group" style="margin-bottom:12px">
                    <label>Nama Layanan</label>
                    <input name="nama" placeholder="AAS — Logam tunggal" required/>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Metode</label>
                        <select name="metode">
                            <option value="">— Umum —</option>
                            <?php foreach (['AAS','XRF','ICP-OES','Gravimetri','Fire Assay','Volumetri'] as $m): ?>
                                <option><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Parameter (opsional)</label>
                        <input name="parameter" placeholder="Au, Ni, Fe..."/></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Harga (Rp)</label>
                        <input type="number" name="harga" placeholder="0" required/></div>
                    <div class="form-group"><label>Satuan</label>
                        <select name="satuan">
                            <option>per parameter</option>
                            <option>per sampel</option>
                            <option>per batch</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-gold">&#128190; Simpan Tarif</button>
            </form>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-title">&#128176; Informasi Tarif</div>
            <div class="alert-box alert-yellow" style="margin:20px">
                🔒 Hanya Administrator yang dapat menambah atau mengubah tarif.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function switchTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    if (el) el.classList.add('active');
    history.replaceState(null,'','?tab='+name);
}

// ── Item rows ─────────────────────────────────────────────────
let itemCount = 0;
function tambahItem(desc='', qty=1, harga=0, tarifId='') {
    itemCount++;
    const i = itemCount;
    const tr = document.createElement('tr');
    tr.id = 'irow' + i;
    tr.innerHTML = `
        <td class="row-num" style="text-align:center;color:var(--text3);font-size:.72rem">${i}</td>
        <td><input class="cell-in" name="items[${i}][deskripsi]" value="${desc}" placeholder="Deskripsi layanan" required oninput="hitungTotal()"/></td>
        <td><input type="number" class="cell-in" name="items[${i}][qty]" value="${qty}" min="1" style="text-align:center" oninput="hitungBaris(${i})"/></td>
        <td><input type="number" class="cell-in" name="items[${i}][harga_satuan]" value="${harga}" placeholder="0" style="text-align:right" oninput="hitungBaris(${i})"/></td>
        <td style="text-align:right;font-weight:700;color:var(--gold)" id="sub${i}">Rp 0</td>
        <td><input type="hidden" name="items[${i}][tarif_id]" value="${tarifId}"/>
            <button type="button" onclick="document.getElementById('irow${i}').remove();hitungTotal()"
                    style="background:var(--red);color:#fff;border:none;border-radius:4px;padding:3px 8px;cursor:pointer;font-size:.7rem">&#10005;</button>
        </td>
    `;
    document.getElementById('itemRows').appendChild(tr);
    if (harga > 0) hitungBaris(i);
}

function hitungBaris(i) {
    const qty    = parseInt(document.querySelector(`[name="items[${i}][qty]"]`)?.value) || 0;
    const harga  = parseInt(document.querySelector(`[name="items[${i}][harga_satuan]"]`)?.value) || 0;
    const sub    = qty * harga;
    const el     = document.getElementById('sub' + i);
    if (el) el.textContent = 'Rp ' + sub.toLocaleString('id-ID');
    hitungTotal();
}

function hitungTotal() {
    let subtotal = 0;
    document.querySelectorAll('[name*="[harga_satuan]"]').forEach(inp => {
        const i   = inp.name.match(/\d+/)[0];
        const qty = parseInt(document.querySelector(`[name="items[${i}][qty]"]`)?.value) || 0;
        subtotal += (parseInt(inp.value) || 0) * qty;
    });
    const diskon = (parseFloat(document.getElementById('diskonPct').value) || 0) / 100;
    const ppn    = (parseFloat(document.getElementById('ppnPct').value)    || 0) / 100;
    const disNom = Math.round(subtotal * diskon);
    const ppnNom = Math.round((subtotal - disNom) * ppn);
    const total  = subtotal - disNom + ppnNom;
    const fmt    = n => 'Rp ' + n.toLocaleString('id-ID');

    document.getElementById('dispSubtotal').textContent = fmt(subtotal);
    document.getElementById('dispDiskon').textContent   = '— ' + fmt(disNom);
    document.getElementById('dispPPN').textContent      = '+ ' + fmt(ppnNom);
    document.getElementById('dispTotal').textContent    = fmt(total);
    document.getElementById('hidSubtotal').value = subtotal;
    document.getElementById('hidDiskon').value   = disNom;
    document.getElementById('hidPPN').value      = ppnNom;
    document.getElementById('hidTotal').value    = total;
}

function tambahItemDariTarif() {
    const m = document.getElementById('modalTarif');
    m.style.display = 'flex';
}

function pilihTarif(id, nama, harga) {
    tambahItem(nama, 1, harga, id);
    document.getElementById('modalTarif').style.display = 'none';
}

function loadBatchItems(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    const klien = opt.dataset.klien || '';
    const kInput = document.querySelector('[name=klien]');
    if (kInput && !kInput.value) kInput.value = klien;
}

// Tambah 1 baris kosong awal (hanya jika tab buat aktif dan user admin)
<?php if ($canEdit && $tab === 'buat'): ?>
tambahItem();
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
