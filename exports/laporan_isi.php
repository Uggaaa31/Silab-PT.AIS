<?php
// ============================================================
//  exports/laporan_isi.php
//  Variabel yang WAJIB tersedia sebelum di-include:
//    $klienFilter, $recFilter, $batchInfo,
//    $grouped, $hasilList, $paramList,
//    $materialStr, $metodeStr, $jumlahSampel,
//    $tglTerima, $tglAnalisa,
//    $analisList, $certNum, $lulus, $tLulus
//
//  FIX K-3: semua variabel sudah di-pass dari export_pdf.php
//  FIX R-1: semua tanggal pakai fmtTgl() dari config/db.php
//  FIX R-4: certNum deterministik, bukan acak
// ============================================================

// Pastikan $recFilter selalu terdefinisi (FIX K-3)
$recFilter   = $recFilter   ?? '';
$batchInfo   = $batchInfo   ?? null;
$klienFilter = $klienFilter ?? '';
?>

<!-- KOP -->
<div class="kop">
    <div class="kop-logo">&#9879;<br>LAB<br>MINERAL</div>
    <div class="kop-info">
        <h1>AISPEKTRA LABORATORY</h1>
        <p>
            Laboratorium Uji Mineral &amp; Logam<br>
            Jl. Tamalanrea Raya, Makassar 90245, Sulawesi Selatan<br>
            Telp/Fax. +62 411 000-0000 &nbsp;|&nbsp; Email: lab@labmineral.co.id
        </p>
    </div>
</div>
<div class="divider"></div>

<!-- JUDUL -->
<div class="report-title">
    <h2>REPORT OF ANALYSIS</h2>
    <p>(Laporan Analisis)</p>
</div>
<div class="cert-num">
    Certificate Number / Nomor Sertifikat : <strong><?= bersihkan($certNum) ?></strong>
</div>

<!-- Badge batch -->
<?php if (!empty($recFilter)): ?>
<div style="text-align:center;margin-bottom:12px">
    <span class="batch-badge">&#128230; No. Penerimaan: <?= bersihkan($recFilter) ?></span>
</div>
<?php endif; ?>

<!-- INFO BOX -->
<div class="info-box">
    <div class="info-grid">
        <div>
            <div class="info-row">
                <span class="ilabel">Customer / <em>Pelanggan</em></span>
                <span class="icolon">:</span>
                <span><strong><?= bersihkan($klienFilter) ?></strong></span>
            </div>
            <div class="info-row">
                <span class="ilabel">Subject / <em>Hal</em></span>
                <span class="icolon">:</span>
                <span>Mineral Analysis</span>
            </div>
            <div class="info-row">
                <span class="ilabel">Description / <em>Keterangan Sampel</em></span>
                <span class="icolon">:</span>
                <span><?= bersihkan($materialStr) ?></span>
            </div>
            <div class="info-row">
                <span class="ilabel">Number of Sample(s) / <em>Jumlah Sampel</em></span>
                <span class="icolon">:</span>
                <span><?= $jumlahSampel ?> (<?= terbilang2($jumlahSampel) ?>)</span>
            </div>
            <div class="info-row">
                <span class="ilabel">Form of Sample / <em>Bentuk Sampel</em></span>
                <span class="icolon">:</span>
                <span>Pulp</span>
            </div>
        </div>
        <div>
            <div class="info-row">
                <span class="ilabel">Test Required / <em>Analisa Uji</em></span>
                <span class="icolon">:</span>
                <span>Elemental (<?= bersihkan(implode(', ', $paramList)) ?>)</span>
            </div>
            <div class="info-row">
                <span class="ilabel">Date Received / <em>Tanggal Terima</em></span>
                <span class="icolon">:</span>
                <span><?= $tglTerima ?></span>
            </div>
            <div class="info-row">
                <span class="ilabel">Date of Analysis / <em>Tanggal Analisa</em></span>
                <span class="icolon">:</span>
                <span><?= $tglAnalisa ?></span>
            </div>
            <div class="info-row">
                <span class="ilabel">Method of Analysis / <em>Metode Analisa</em></span>
                <span class="icolon">:</span>
                <span><?= bersihkan($metodeStr) ?></span>
            </div>
            <div class="info-row">
                <span class="ilabel">Reference / <em>Referensi</em></span>
                <span class="icolon">:</span>
                <span>&mdash;</span>
            </div>
            <?php if (!empty($recFilter)): ?>
            <div class="info-row">
                <span class="ilabel">No. Penerimaan / <em>Batch</em></span>
                <span class="icolon">:</span>
                <span><strong><?= bersihkan($recFilter) ?></strong></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- STATISTIK -->
<div class="stats-row">
    <div class="scard">
        <div class="num"><?= $jumlahSampel ?></div>
        <div class="lbl">Total Sampel</div>
    </div>
    <div class="scard">
        <div class="num"><?= count($hasilList) ?></div>
        <div class="lbl">Total Parameter Uji</div>
    </div>
    <div class="scard">
        <div class="num"><?= $lulus ?></div>
        <div class="lbl">&#10003; Sampel Lulus</div>
    </div>
    <div class="scard">
        <div class="num red"><?= $tLulus ?></div>
        <div class="lbl">&#10007; Tidak Lulus</div>
    </div>
    <div class="scard">
        <div class="num gold">
            <?= $jumlahSampel > 0 ? round($lulus / $jumlahSampel * 100) : 0 ?>%
        </div>
        <div class="lbl">Tingkat Kelulusan</div>
    </div>
</div>

<!-- RESULT -->
<div class="result-title">RESULT</div>

<table class="hasil">
    <thead>
        <tr>
            <th rowspan="2" style="width:80px">KODE<br>SAMPEL</th>
            <th rowspan="2" style="width:90px">MATERIAL</th>
            <th colspan="<?= count($paramList) ?>">Hasil Analisa</th>
            <th rowspan="2" style="width:78px">Kesimpulan</th>
            <th rowspan="2" style="width:85px">Remarks</th>
        </tr>
        <tr class="sub">
            <?php foreach ($paramList as $p):
                $sat = '';
                foreach ($hasilList as $hh) {
                    if ($hh['parameter'] === $p) { $sat = $hh['satuan']; break; }
                }
            ?>
                <th><?= bersihkan($p) ?><?= $sat ? '<br>('.bersihkan($sat).')' : '' ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php
    $hasNR = false;
    foreach ($grouped as $kode => $grp):
        $paramMap = []; $kesAll = [];
        foreach ($grp['results'] as $r) {
            $paramMap[$r['parameter']] = $r['nilai'];
            $kesAll[] = $r['kesimpulan'];
        }
        if      (in_array('tidak_lulus', $kesAll)) { $kLbl = 'TIDAK LULUS'; $kCls = 'ktl'; }
        elseif  (in_array('pending',     $kesAll)) { $kLbl = 'PENDING';     $kCls = 'kp';  }
        else                                       { $kLbl = 'LULUS';       $kCls = 'kl';  }
        $missing = array_diff($paramList, array_keys($paramMap));
        if ($missing) $hasNR = true;
    ?>
        <tr>
            <td style="font-weight:700"><?= bersihkan($kode) ?></td>
            <td style="text-align:left;padding-left:6px">
                <?= bersihkan($grp['info']['jenis_material']) ?>
            </td>
            <?php foreach ($paramList as $p):
                $val = $paramMap[$p] ?? null;
            ?>
                <td>
                    <?= $val !== null
                        ? number_format((float)$val, 4)
                        : '<span style="color:#aaa">NR</span>' ?>
                </td>
            <?php endforeach; ?>
            <td class="<?= $kCls ?>"><?= $kLbl ?></td>
            <td style="font-size:7.5pt;color:#666">
                <?= $missing ? 'NR=Not Reported' : '&mdash;' ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($hasNR): ?>
    <tfoot>
        <tr>
            <td colspan="<?= count($paramList) + 4 ?>">
                NR = Not Reported (Parameter tidak diuji untuk sampel tersebut)
            </td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>

<!-- DISCLAIMER -->
<div class="disclaimer">
    HASIL ANALISA TERSEBUT DIATAS HANYA MERUJUK PADA SAMPEL YANG DISERAHKAN
    DIMANA PENGAMBILAN SAMPEL TERSEBUT TIDAK DILAKUKAN OLEH AISPEKTRA LABORATORY.
    LAPORAN INI TIDAK BOLEH DIPERBANYAK TANPA IZIN TERTULIS DARI LABORATORIUM.
</div>

<!-- TTD -->
<div class="ttd-area">
    <?php foreach ($analisList as $nama): ?>
    <div class="ttd-box">
        <div class="ttd-lbl">ANALIS</div>
        <div class="ttd-name"><?= bersihkan($nama) ?></div>
        <div class="ttd-pos">Analis Laboratorium</div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($analisList)): ?>
    <div class="ttd-box">
        <div class="ttd-lbl">ANALIS</div>
        <div class="ttd-name" style="min-width:160px;color:#ccc">___________________</div>
        <div class="ttd-pos">Analis Laboratorium</div>
    </div>
    <?php endif; ?>
    <div class="ttd-box" style="margin-left:auto">
        <div class="ttd-lbl">MENGETAHUI,<br>Kepala Laboratorium</div>
        <div class="ttd-name" style="min-width:160px;color:#ccc">___________________</div>
        <div class="ttd-pos">Kepala Laboratorium</div>
    </div>
</div>

<!-- FOOTER -->
<div class="pfooter">
    <span>
        Aispektra Laboratory<?= APP_VERSION ?> &bull;
        Digenerate: <?= fmtTglPanjang(date('Y-m-d')) ?>, <?= date('H:i') ?> &bull;
        Operator: <?= bersihkan($_SESSION['nama'] ?? '') ?>
    </span>
    <strong><?= bersihkan($certNum) ?></strong>
</div>
