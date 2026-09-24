<?php
// ============================================================
//  exports/cetak_invoice.php — Cetak Invoice (HTML → PDF)
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.BASE_URL.'/pages/invoice.php'); exit; }

// ── Ambil data invoice ───────────────────────────────────────
$stInv = $pdo->prepare(
    "SELECT i.*, u.nama AS operator, p.nomor_penerimaan
     FROM invoice i
     LEFT JOIN pengguna u ON i.dibuat_oleh = u.id
     LEFT JOIN penerimaan_sampel p ON i.penerimaan_id = p.id
     WHERE i.id = ?"
);
$stInv->execute([$id]);
$inv = $stInv->fetch();
if (!$inv) { header('Location: '.BASE_URL.'/pages/invoice.php'); exit; }

if (isClient() && (!in_array($inv['status'], ['diterbitkan','lunas'], true) || !clientCanAccessInvoice($pdo, $id, (int)$_SESSION['user_id']))) {
    $_SESSION['msg'] = 'ERROR: Invoice tidak tersedia untuk akun ini.';
    header('Location: '.BASE_URL.'/pages/client_monitoring.php');
    exit;
}

// ── Ambil item ───────────────────────────────────────────────
$items = $pdo->prepare(
    "SELECT * FROM invoice_item WHERE invoice_id = ? ORDER BY id"
);
$items->execute([$id]);
$itemList = $items->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<title>Invoice <?= bersihkan($inv['nomor_invoice']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size: 10pt; color: #222; background: #fff; padding: 30px 40px; }

/* Print bar */
.print-bar { display:flex; gap:12px; align-items:center; margin-bottom:24px;
             padding:12px 16px; background:#f0f9f3; border:1px solid #c8e6c9; border-radius:8px; }
.btn-p { padding:8px 22px; background:#1e8449; color:#fff; border:none; border-radius:6px;
         font-size:10pt; font-weight:700; cursor:pointer; }
.btn-p:hover { background:#27ae60; }
.btn-k { padding:8px 18px; background:#555; color:#fff; border:none; border-radius:6px;
         font-size:10pt; cursor:pointer; }

/* KOP */
.kop { display:flex; align-items:center; border-bottom:3px solid #1e4028; padding-bottom:14px; margin-bottom:6px; }
.kop-logo { width:64px; height:64px; background:#1e4028; border-radius:8px; display:flex;
            flex-direction:column; align-items:center; justify-content:center; color:#f0c040;
            font-weight:900; font-size:9pt; text-align:center; margin-right:14px; line-height:1.3; }
.kop-info h1 { font-size:14pt; font-weight:900; color:#1e4028; }
.kop-info p  { font-size:8pt; color:#444; margin-top:2px; line-height:1.5; }
.divider { height:3px; background:linear-gradient(to right,#1e4028,#f0c040,#1e4028); margin:4px 0 18px; }

/* Invoice heading */
.inv-heading { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; }
.inv-title h2 { font-size:18pt; font-weight:900; color:#1e4028; letter-spacing:1px; }
.inv-title p  { font-size:9pt; color:#666; margin-top:2px; }
.inv-meta { text-align:right; font-size:9pt; }
.inv-meta .no { font-size:11pt; font-weight:700; color:#1e4028; }

/* Bill to / info -->
.bill-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
.bill-box { background:#f9fff9; border:1px solid #c8e6c9; border-radius:6px; padding:12px 14px; }
.bill-box .lbl { font-size:7.5pt; color:#666; font-weight:700; text-transform:uppercase;
                 letter-spacing:.5px; margin-bottom:6px; }
.bill-box p { font-size:9pt; color:#222; line-height:1.7; }

/* Status badge */
.status-badge { display:inline-block; padding:4px 14px; border-radius:12px; font-size:8pt;
                font-weight:700; letter-spacing:.5px; }
.s-draft       { background:#FFF8E1; color:#E65100; }
.s-diterbitkan { background:#E3F2FD; color:#0D47A1; }
.s-lunas       { background:#E8F5E9; color:#1B5E20; }
.s-dibatalkan  { background:#FFEBEE; color:#B71C1C; }

/* Item table */
table.items { width:100%; border-collapse:collapse; font-size:9pt; margin-bottom:16px; }
table.items thead th { background:#1e4028; color:#f0c040; padding:8px 10px; text-align:left;
                       border:1px solid #2e6040; font-size:8pt; }
table.items tbody td { padding:8px 10px; border:1px solid #c8e6c9; vertical-align:middle; }
table.items tbody tr:nth-child(even) td { background:#f0fff4; }

/* Total -->
.total-section { display:flex; justify-content:flex-end; margin-bottom:20px; }
.total-box { width:300px; }
.total-row { display:flex; justify-content:space-between; padding:5px 0;
             font-size:9.5pt; border-bottom:1px solid #f0f0f0; }
.total-row.grand { border-top:2px solid #1e4028; border-bottom:none; margin-top:6px;
                   padding-top:8px; font-size:12pt; font-weight:700; color:#1e4028; }

/* Footer -->
.inv-footer { margin-top:30px; display:flex; justify-content:space-between; gap:40px; }
.sign-box .sign-lbl { font-size:9pt; font-weight:700; margin-bottom:50px; }
.sign-box .sign-line { border-top:1px solid #333; min-width:160px; padding-top:4px; font-size:8.5pt; }
.disclaimer { font-size:7.5pt; color:#888; text-align:center; margin-top:20px;
              padding-top:10px; border-top:1px solid #ddd; }

@media print {
    .print-bar { display:none !important; }
    body { padding: 10px 15px; }
    @page { size: A4 portrait; margin: 12mm 15mm; }
}
</style>
</head>
<body>

<div class="print-bar">
    <button class="btn-p" onclick="window.print()">&#128196; Cetak / Simpan PDF</button>
    <button class="btn-k" onclick="window.close()">&#10005; Tutup</button>
    <span style="font-size:8pt;color:#666">
        Pilih <strong>Save as PDF</strong> saat dialog cetak muncul.
    </span>
</div>

<!-- KOP -->
<div class="kop">
    <div class="kop-logo">&#9879;<br>LAB<br>MINERAL</div>
    <div class="kop-info">
        <h1>AISPEKTRA LABORATORY</h1>
        <p>Laboratorium Uji Mineral &amp; Logam<br>
           Jl. Tamalanrea Raya, Makassar 90245, Sulawesi Selatan<br>
           Telp/Fax. +62 411 000-0000 &nbsp;|&nbsp; Email: lab@labmineral.co.id<br>
           NPWP: 00.000.000.0-000.000</p>
    </div>
</div>
<div class="divider"></div>

<!-- HEADING -->
<div class="inv-heading">
    <div class="inv-title">
        <h2>INVOICE</h2>
        <p>Tagihan Layanan Analisis Laboratorium</p>
        <div style="margin-top:8px">
            <span class="status-badge s-<?= $inv['status'] ?>"><?= strtoupper($inv['status']) ?></span>
        </div>
    </div>
    <div class="inv-meta">
        <div class="no"><?= bersihkan($inv['nomor_invoice']) ?></div>
        <div style="margin-top:6px;color:#555">
            Tanggal: <strong><?= fmtTglPanjang($inv['tanggal_invoice']) ?></strong>
        </div>
        <?php if ($inv['tanggal_jatuh_tempo']): ?>
        <div style="color:#555">
            Jatuh Tempo: <strong style="color:<?= strtotime($inv['tanggal_jatuh_tempo'])<time() && $inv['status']==='diterbitkan' ? '#c0392b' : '#222' ?>">
                <?= fmtTglPanjang($inv['tanggal_jatuh_tempo']) ?>
            </strong>
        </div>
        <?php endif; ?>
        <?php if ($inv['nomor_penerimaan']): ?>
        <div style="margin-top:4px;color:#555;font-size:8.5pt">
            Ref. Penerimaan: <strong><?= bersihkan($inv['nomor_penerimaan']) ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- BILL TO -->
<div class="bill-grid">
    <div class="bill-box">
        <div class="lbl">Tagihan Kepada / Bill To</div>
        <p><strong><?= bersihkan($inv['klien']) ?></strong></p>
        <?php if ($inv['alamat_klien']): ?>
            <p style="color:#555;font-size:8.5pt"><?= nl2br(bersihkan($inv['alamat_klien'])) ?></p>
        <?php endif; ?>
    </div>
    <div class="bill-box">
        <div class="lbl">Dari / From</div>
        <p><strong>Aispektra Laboratory</strong></p>
        <p style="color:#555;font-size:8.5pt">
            Jl. Tamalanrea Raya, Makassar 90245<br>
            lab@labmineral.co.id &nbsp;|&nbsp; +62 411 000-0000
        </p>
        <p style="margin-top:4px;font-size:8pt;color:#888">
            Dibuat oleh: <?= bersihkan($inv['operator'] ?? '') ?>
        </p>
    </div>
</div>

<!-- ITEM TABLE -->
<table class="items">
    <thead>
        <tr>
            <th style="width:36px">No.</th>
            <th>Deskripsi Layanan</th>
            <th style="width:50px;text-align:center">Qty</th>
            <th style="width:130px;text-align:right">Harga Satuan (Rp)</th>
            <th style="width:130px;text-align:right">Subtotal (Rp)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($itemList as $i => $item): ?>
    <tr>
        <td style="text-align:center;color:#888"><?= $i+1 ?></td>
        <td><?= bersihkan($item['deskripsi']) ?>
            <?php if ($item['catatan']): ?>
                <br><small style="color:#888"><?= bersihkan($item['catatan']) ?></small>
            <?php endif; ?>
        </td>
        <td style="text-align:center"><?= $item['qty'] ?></td>
        <td style="text-align:right"><?= number_format($item['harga_satuan'],0,',','.') ?></td>
        <td style="text-align:right;font-weight:700"><?= number_format($item['subtotal'],0,',','.') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- TOTAL -->
<div class="total-section">
    <div class="total-box">
        <div class="total-row">
            <span style="color:#555">Subtotal</span>
            <span>Rp <?= number_format($inv['subtotal'],0,',','.') ?></span>
        </div>
        <?php if ($inv['diskon_pct'] > 0): ?>
        <div class="total-row" style="color:#e74c3c">
            <span>Diskon (<?= $inv['diskon_pct'] ?>%)</span>
            <span>— Rp <?= number_format($inv['diskon_nominal'],0,',','.') ?></span>
        </div>
        <?php endif; ?>
        <div class="total-row">
            <span style="color:#555">PPN (<?= $inv['ppn_pct'] ?>%)</span>
            <span>+ Rp <?= number_format($inv['ppn_nominal'],0,',','.') ?></span>
        </div>
        <div class="total-row grand">
            <span>TOTAL</span>
            <span>Rp <?= number_format($inv['total'],0,',','.') ?></span>
        </div>
    </div>
</div>

<?php if ($inv['catatan']): ?>
<div style="background:#f9fff9;border:1px solid #c8e6c9;border-radius:6px;padding:10px 14px;margin-bottom:20px;font-size:8.5pt;color:#333">
    <strong>Catatan:</strong> <?= nl2br(bersihkan($inv['catatan'])) ?>
</div>
<?php endif; ?>

<!-- FOOTER TTD -->
<div class="inv-footer">
    <div>
        <p style="font-size:8.5pt;color:#555;max-width:280px;line-height:1.6">
            Mohon transfer ke rekening:<br>
            <strong>Bank BNI — 0123456789</strong><br>
            a.n. ANUGRAH INTI SPEKTRA<br>
            Cantumkan nomor invoice sebagai keterangan transfer.
        </p>
    </div>
    <div class="sign-box">
        <div class="sign-lbl">Makassar, <?= fmtTglPanjang($inv['tanggal_invoice']) ?><br>LabMineral Pro</div>
        <div class="sign-line">Pimpinan / Manager</div>
    </div>
</div>

<div class="disclaimer">
    Invoice ini digenerate otomatis oleh Aispektra LMS<?= APP_VERSION ?>.
    Dokumen sah tanpa tanda tangan basah jika diterbitkan secara digital.
</div>

<script>
window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });
</script>
</body>
</html>
