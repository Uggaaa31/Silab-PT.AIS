<?php
// ============================================================
//  exports/export_pdf.php
//  Filter: per Klien ATAU per Nomor Penerimaan (Batch)
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$klienFilter = trim($_GET['klien'] ?? '');
$recFilter   = trim($_GET['rec']   ?? '');   // nomor penerimaan
$modeCetak   = isset($_GET['cetak']) && $_GET['cetak'] == '1';

// ── Daftar klien & batch untuk dropdown ─────────────────────
$allKlien = $pdo->query(
    "SELECT DISTINCT klien FROM sampel WHERE klien IS NOT NULL AND klien != '' ORDER BY klien"
)->fetchAll(PDO::FETCH_COLUMN);

$allBatch = [];
if ($klienFilter) {
    $stB = $pdo->prepare(
        "SELECT nomor_penerimaan, tanggal_terima,
                (SELECT COUNT(*) FROM sampel WHERE penerimaan_id = p.id) AS jml
         FROM penerimaan_sampel p WHERE klien = ? ORDER BY tanggal_terima DESC"
    );
    $stB->execute([$klienFilter]);
    $allBatch = $stB->fetchAll();
}

// ── Ambil data hasil uji ─────────────────────────────────────
$hasilList = [];
$grouped   = [];
$batchInfo = null;

if ($klienFilter || $recFilter) {
    // Jika filter per batch, ambil klien dari batch tersebut
    if ($recFilter && !$klienFilter) {
        $row = $pdo->prepare("SELECT klien FROM penerimaan_sampel WHERE nomor_penerimaan = ?");
        $row->execute([$recFilter]);
        $r = $row->fetch();
        if ($r) $klienFilter = $r['klien'];
    }

    // Info batch
    if ($recFilter) {
        $stBI = $pdo->prepare("SELECT * FROM penerimaan_sampel WHERE nomor_penerimaan = ?");
        $stBI->execute([$recFilter]);
        $batchInfo = $stBI->fetch();
    }

    // Query data
    if ($recFilter) {
        // Per batch: hanya sampel dari batch ini
        $sql = "SELECT h.kode_uji, s.kode_sampel, s.jenis_material,
                       s.klien, s.metode_uji, s.tanggal_masuk,
                       h.parameter, h.nilai, h.satuan,
                       h.batas_min, h.batas_maks, h.metode,
                       p.nama AS analis, h.tanggal_uji, h.kesimpulan
                FROM hasil_uji h
                JOIN sampel s ON h.sampel_id = s.id
                JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
                LEFT JOIN pengguna p ON h.analis_id = p.id
                WHERE rec.nomor_penerimaan = ?
                ORDER BY s.kode_sampel, h.parameter";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$recFilter]);
    } else {
        // Per klien: semua sampel klien
        $sql = "SELECT h.kode_uji, s.kode_sampel, s.jenis_material,
                       s.klien, s.metode_uji, s.tanggal_masuk,
                       h.parameter, h.nilai, h.satuan,
                       h.batas_min, h.batas_maks, h.metode,
                       p.nama AS analis, h.tanggal_uji, h.kesimpulan
                FROM hasil_uji h
                JOIN sampel s ON h.sampel_id = s.id
                LEFT JOIN pengguna p ON h.analis_id = p.id
                WHERE s.klien = ?
                ORDER BY s.kode_sampel, h.parameter";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$klienFilter]);
    }
    $hasilList = $stmt->fetchAll();

    foreach ($hasilList as $h) {
        $key = $h['kode_sampel'];
        if (!isset($grouped[$key])) $grouped[$key] = ['info' => $h, 'results' => []];
        $grouped[$key]['results'][] = $h;
    }
}

// ── Helpers ──────────────────────────────────────────────────
function xe2($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function terbilang2($n) {
    $w = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
          'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen',
          'Eighteen','Nineteen','Twenty'];
    return $w[$n] ?? (string)$n;
}

// ── Kalkulasi ────────────────────────────────────────────────
$paramList    = array_unique(array_column($hasilList, 'parameter')); sort($paramList);
$metodeList   = array_unique(array_column($hasilList, 'metode'));
$metodeStr    = implode(', ', array_filter($metodeList));
$materialList = array_unique(array_column($hasilList, 'jenis_material'));
$materialStr  = implode(', ', array_filter($materialList));
$jumlahSampel = count($grouped);

$tglTerima  = $batchInfo
    ? date('d/m/Y', strtotime($batchInfo['tanggal_terima']))
    : (!empty($hasilList) ? date('d/m/Y', strtotime($hasilList[0]['tanggal_masuk'])) : date('d/m/Y'));
$tglAnalisa = !empty($hasilList)
    ? date('d/m/Y', strtotime(end($hasilList)['tanggal_uji'] ?? 'now'))
    : date('d/m/Y');

$analisList = [];
foreach ($hasilList as $h) {
    if ($h['analis'] && !in_array($h['analis'], $analisList)) $analisList[] = $h['analis'];
}

// Nomor sertifikat: gunakan nomor penerimaan jika ada
$certNum = $recFilter
    ? $recFilter
    : 'LAB-' . date('Ym') . '-' . str_pad(rand(1,999), 3, '0', STR_PAD_LEFT);

$lulus = $tLulus = 0;
foreach ($grouped as $g) {
    $kes = array_column($g['results'], 'kesimpulan');
    if (in_array('tidak_lulus', $kes)) $tLulus++; else $lulus++;
}

// ── CSS ──────────────────────────────────────────────────────
$css = '<style>
.dokumen{font-family:Arial,sans-serif;font-size:10pt;color:#222;background:#fff;}
.kop{display:flex;align-items:center;border-bottom:3px solid #1e4028;padding-bottom:14px;margin-bottom:6px;}
.kop-logo{width:74px;height:74px;background:#1e4028;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#f0c040;font-weight:900;font-size:10pt;text-align:center;flex-shrink:0;margin-right:18px;line-height:1.3;}
.kop-info h1{font-size:15pt;font-weight:900;color:#1e4028;letter-spacing:.5px;}
.kop-info p{font-size:8.5pt;color:#444;margin-top:2px;line-height:1.6;}
.divider{height:3px;background:linear-gradient(to right,#1e4028,#f0c040,#1e4028);margin:6px 0 18px;}
.report-title{text-align:center;margin-bottom:16px;}
.report-title h2{font-size:14pt;font-weight:900;text-decoration:underline;letter-spacing:1px;}
.report-title p{font-size:10pt;font-style:italic;color:#555;margin-top:2px;}
.cert-num{text-align:right;font-size:8.5pt;color:#666;margin-bottom:12px;}
.cert-num strong{color:#1e4028;}
.batch-badge{display:inline-block;background:#1e4028;color:#f0c040;padding:3px 12px;border-radius:10px;font-size:8pt;font-weight:700;margin-bottom:10px;}
.info-box{border:1px solid #ccc;border-radius:4px;padding:12px 16px;margin-bottom:18px;background:#fafffe;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:3px 24px;}
.info-row{display:flex;font-size:9.5pt;line-height:1.85;}
.ilabel{min-width:185px;font-weight:700;flex-shrink:0;}
.icolon{margin:0 6px;}
.stats-row{display:flex;gap:12px;margin-bottom:16px;}
.scard{flex:1;text-align:center;border:1px solid #c8e6c9;border-radius:6px;padding:8px;background:#f0fff4;}
.scard .num{font-size:16pt;font-weight:900;color:#1e8449;}
.scard .num.red{color:#c0392b;}.scard .num.gold{color:#d4a017;}
.scard .lbl{font-size:7.5pt;color:#555;margin-top:1px;}
.result-title{text-align:center;font-size:11pt;font-weight:900;text-decoration:underline;margin:16px 0 10px;}
table.hasil{width:100%;border-collapse:collapse;font-size:8.5pt;margin-bottom:8px;}
table.hasil thead th{background:#1e4028;color:#f0c040;padding:6px;text-align:center;border:1px solid #2e6040;font-size:8pt;}
table.hasil thead tr.sub th{background:#2e6040;color:#e8f5e9;font-weight:normal;font-size:7.5pt;padding:4px 6px;}
table.hasil tbody td{padding:5px 6px;border:1px solid #c8e6c9;text-align:center;vertical-align:middle;}
table.hasil tbody tr:nth-child(even) td{background:#f0fff4;}
table.hasil tfoot td{padding:4px 6px;border:1px solid #c8e6c9;font-size:7.5pt;color:#666;font-style:italic;background:#f9f9f9;}
.kl{color:#1e8449;font-weight:700;}.ktl{color:#c0392b;font-weight:700;}.kp{color:#d4a017;font-weight:700;}
.disclaimer{font-size:8.5pt;font-weight:700;text-align:justify;margin:20px 0 24px;line-height:1.7;text-transform:uppercase;}
.ttd-area{display:flex;gap:50px;margin-top:8px;}
.ttd-box .ttd-lbl{font-size:9.5pt;font-weight:700;margin-bottom:52px;}
.ttd-box .ttd-name{font-size:10pt;font-weight:700;border-top:1px solid #333;padding-top:4px;min-width:160px;}
.ttd-box .ttd-pos{font-size:8pt;color:#555;}
.pfooter{margin-top:24px;padding-top:8px;border-top:2px solid #1e4028;display:flex;justify-content:space-between;font-size:7.5pt;color:#888;}
.pfooter strong{color:#1e4028;}
</style>';

// ============================================================
//  MODE CETAK
// ============================================================
if ($modeCetak && ($klienFilter || $recFilter) && !empty($hasilList)):
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<title>Report of Analysis — <?= xe2($recFilter ?: $klienFilter) ?></title>
<?= $css ?>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#fff;padding:20px 30px;}
@page{size:A4 portrait;margin:12mm 15mm;}
@media print{body{padding:0;}.no-print{display:none!important;}tr{page-break-inside:avoid;}}
.print-bar{display:flex;gap:12px;align-items:center;margin-bottom:20px;padding:12px 16px;background:#f0f9f3;border:1px solid #c8e6c9;border-radius:8px;}
.btn-p{padding:9px 24px;background:#1e8449;color:#fff;border:none;border-radius:6px;font-size:10pt;font-weight:700;cursor:pointer;}
.btn-p:hover{background:#27ae60;}
.btn-k{padding:9px 18px;background:#555;color:#fff;border:none;border-radius:6px;font-size:10pt;cursor:pointer;}
.hint{font-size:8pt;color:#666;}
</style>
</head>
<body>
<div class="print-bar no-print">
    <button class="btn-p" onclick="window.print()">&#128196; Cetak / Simpan PDF</button>
    <button class="btn-k" onclick="window.close()">&#10005; Tutup</button>
    <span class="hint">Pilih <strong>"Save as PDF"</strong> → klik <strong>Simpan</strong>.</span>
</div>
<div class="dokumen">
<?php include __DIR__ . '/laporan_isi.php'; ?>
</div>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print();},500);});</script>
</body>
</html>
<?php exit; endif;

// ============================================================
//  MODE NORMAL — UI Selector
// ============================================================
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<title>Export Report of Analysis — AISPEKTRA LABORATORY</title>
<?= $css ?>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:Arial,sans-serif;font-size:10pt;color:#222;background:#f0f4f0;}
.wrap{max-width:900px;margin:0 auto;padding:24px 20px;}
.sel-card{background:#fff;border:1px solid #c8e6c9;border-radius:10px;padding:20px 24px;margin-bottom:22px;box-shadow:0 2px 8px rgba(0,0,0,.06);}
.sel-card h3{color:#1e4028;font-size:11pt;margin-bottom:14px;}
.sel-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;}
.sel-group{flex:1;min-width:160px;}
.sel-group label{display:block;font-size:8.5pt;color:#555;margin-bottom:5px;}
.sel-group select{width:100%;padding:9px 12px;border:1px solid #c8e6c9;border-radius:6px;font-size:9.5pt;outline:none;background:#fff;}
.sel-group select:focus{border-color:#1e8449;}
.btn-l{padding:9px 18px;background:#1e8449;color:#fff;border:none;border-radius:6px;font-size:9.5pt;font-weight:700;cursor:pointer;}
.btn-l:hover{background:#27ae60;}
.btn-c{padding:9px 18px;background:#d4a017;color:#fff;border:none;border-radius:6px;font-size:9.5pt;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;}
.btn-c:hover{background:#f0c040;color:#1a0f00;}
.no-data{text-align:center;padding:16px;color:#999;font-style:italic;}
.preview-wrap{background:#fff;border-radius:10px;padding:30px 36px;box-shadow:0 2px 16px rgba(0,0,0,.10);}
.batch-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
.batch-btn{background:#f0fff4;border:1px solid #c8e6c9;border-radius:6px;padding:7px 12px;font-size:.78rem;text-decoration:none;color:#1e4028;transition:.2s;}
.batch-btn:hover{background:#1e4028;color:#f0c040;border-color:#1e4028;}
.batch-btn.active{background:#1e4028;color:#f0c040;border-color:#1e4028;}
.sep{color:#c8e6c9;margin:0 6px;}
@media print{.sel-card,.wrap>*:not(.preview-wrap){display:none!important;}.preview-wrap{box-shadow:none;padding:0;border-radius:0;}@page{size:A4 portrait;margin:12mm 15mm;}body{background:#fff;}tr{page-break-inside:avoid;}}
</style>
</head>
<body>
<div class="wrap">

    <!-- STEP 1: Pilih Klien -->
    <div class="sel-card">
        <h3>&#128196; Export Report of Analysis</h3>
        <form method="GET" action="" id="formKlien">
            <div class="sel-row">
                <div class="sel-group">
                    <label>&#9312; Pilih Klien</label>
                    <select name="klien" id="selKlien" onchange="document.getElementById('formKlien').submit()">
                        <option value="">-- Pilih Klien --</option>
                        <?php foreach ($allKlien as $k): ?>
                            <option value="<?= xe2($k) ?>" <?= $klienFilter===$k?'selected':'' ?>><?= xe2($k) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>

        <!-- STEP 2: Pilih Batch -->
        <?php if ($klienFilter && $allBatch): ?>
        <div style="margin-top:16px">
            <label style="font-size:8.5pt;color:#555;display:block;margin-bottom:8px">
                &#9313; Pilih Batch Penerimaan <em>(atau biarkan untuk semua batch)</em>
            </label>
            <div class="batch-list">
                <!-- Opsi: Semua batch -->
                <a href="?klien=<?= urlencode($klienFilter) ?>"
                   class="batch-btn <?= !$recFilter ? 'active' : '' ?>">
                    &#128101; Semua Batch (<?= count($allBatch) ?>)
                </a>
                <?php foreach ($allBatch as $b): ?>
                    <a href="?klien=<?= urlencode($klienFilter) ?>&rec=<?= urlencode($b['nomor_penerimaan']) ?>"
                       class="batch-btn <?= $recFilter===$b['nomor_penerimaan']?'active':'' ?>">
                        &#128196; <?= xe2($b['nomor_penerimaan']) ?>
                        <span class="sep">|</span>
                        <?= date('d/m/Y', strtotime($b['tanggal_terima'])) ?>
                        <span class="sep">|</span>
                        <?= $b['jml'] ?> sampel
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tombol Cetak -->
        <?php if ($klienFilter && !empty($hasilList)): ?>
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e8f5e9;display:flex;gap:10px;align-items:center">
            <?php
            $urlCetak = 'export_pdf.php?cetak=1&klien=' . urlencode($klienFilter);
            if ($recFilter) $urlCetak .= '&rec=' . urlencode($recFilter);
            ?>
            <a href="<?= $urlCetak ?>" target="_blank" class="btn-c">
                &#128196; Cetak / Simpan PDF
                <?= $recFilter ? '— ' . xe2($recFilter) : '— Semua Batch' ?>
            </a>
            <span style="font-size:8pt;color:#888">Tab baru akan terbuka &amp; dialog cetak muncul otomatis</span>
        </div>
        <?php endif; ?>

        <?php if ($klienFilter && empty($hasilList)): ?>
            <div class="no-data">&#9888; Tidak ada data hasil uji untuk pilihan ini.</div>
        <?php endif; ?>
    </div>

    <!-- PREVIEW -->
    <?php if ($klienFilter && !empty($hasilList)): ?>
    <div class="preview-wrap">
        <div style="font-size:.75rem;color:#888;margin-bottom:14px;text-align:center">
            &#128065; Preview Laporan —
            <?= $recFilter ? xe2($recFilter) : 'Semua Batch ' . xe2($klienFilter) ?>
        </div>
        <div class="dokumen">
            <?php include __DIR__ . '/laporan_isi.php'; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
