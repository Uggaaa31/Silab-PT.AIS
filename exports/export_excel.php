<?php
// ============================================================
//  exports/export_pdf.php
//  Requirement: composer require tecnickcom/tcpdf
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();
require_once __DIR__ . '/../vendor/autoload.php';

// ── Ambil data hasil uji ─────────────────────────────────────
$hasilList = $pdo->query("
    SELECT h.kode_uji, s.kode_sampel, s.jenis_material,
           h.parameter, h.nilai, h.satuan,
           h.batas_min, h.batas_maks, h.metode,
           p.nama AS analis, h.tanggal_uji, h.kesimpulan
    FROM hasil_uji h
    JOIN sampel s ON h.sampel_id = s.id
    LEFT JOIN pengguna p ON h.analis_id = p.id
    ORDER BY h.created_at DESC
")->fetchAll();

$total  = count($hasilList);
$lulus  = count(array_filter($hasilList, fn($r) => $r['kesimpulan'] === 'lulus'));
$tLulus = count(array_filter($hasilList, fn($r) => $r['kesimpulan'] === 'tidak_lulus'));
$pct    = $total > 0 ? round($lulus / $total * 100) : 0;

// ── Buat objek TCPDF ─────────────────────────────────────────
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('LabMineral Pro');
$pdf->SetAuthor('LabMineral Pro');
$pdf->SetTitle('Laporan Hasil Uji Mineral & Logam');
$pdf->SetSubject('Hasil Uji Laboratorium');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 15, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->SetFont('helvetica', '', 9);
$pdf->AddPage();

// ── HEADER ────────────────────────────────────────────────────
$pdf->SetFillColor(30, 64, 40);
$pdf->SetTextColor(240, 192, 64);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 10, 'LAPORAN HASIL UJI MINERAL & LOGAM', 0, 1, 'C', true);

$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(168, 213, 181);
$pdf->Cell(0, 6,
    'LabMineral Pro  |  Tanggal Cetak: ' . date('d F Y, H:i') .
    '  |  Operator: ' . ($_SESSION['nama'] ?? ''),
    0, 1, 'C', true);

// ── STATISTIK ─────────────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetTextColor(40, 40, 40);
$pdf->SetFont('helvetica', 'B', 9);
$cols = [
    ['Total Uji',        $total],
    ['Lulus',            $lulus],
    ['Tidak Lulus',      $tLulus],
    ['Tingkat Kelulusan', "$pct%"],
];
// Baris label
foreach ($cols as $c) {
    $pdf->SetFillColor(240, 249, 243);
    $pdf->Cell(68, 7, $c[0], 1, 0, 'C', true);
}
$pdf->Ln();
// Baris nilai
$pdf->SetFont('helvetica', 'B', 12);
foreach ($cols as $c) {
    $pdf->SetFillColor(224, 242, 230);
    $pdf->Cell(68, 9, $c[1], 1, 0, 'C', true);
}
$pdf->Ln(12);

// ── HEADER TABEL ──────────────────────────────────────────────
$pdf->SetFillColor(30, 64, 40);
$pdf->SetTextColor(240, 192, 64);
$pdf->SetFont('helvetica', 'B', 7.5);

$colW = [16, 22, 28, 22, 14, 12, 14, 14, 20, 26, 22, 22];
$colH = [
    'ID Uji', 'Sampel', 'Material', 'Parameter',
    'Hasil', 'Satuan', 'Min', 'Maks',
    'Metode', 'Analis', 'Tanggal', 'Kesimpulan'
];
foreach ($colH as $i => $h) {
    $pdf->Cell($colW[$i], 7, $h, 1, 0, 'C', true);
}
$pdf->Ln();

// ── BARIS DATA ────────────────────────────────────────────────
$pdf->SetFont('helvetica', '', 7.5);
$fill = false;

foreach ($hasilList as $row) {
    // Warna baris zebra
    $pdf->SetFillColor(
        $fill ? 240 : 255,
        $fill ? 249 : 255,
        $fill ? 243 : 255
    );
    $pdf->SetTextColor(30, 30, 30);

    // Label kesimpulan
    if ($row['kesimpulan'] === 'lulus')         $kLabel = 'LULUS';
    elseif ($row['kesimpulan'] === 'tidak_lulus') $kLabel = 'TIDAK LULUS';
    else                                         $kLabel = 'PENDING';

    $vals = [
        $row['kode_uji'],
        $row['kode_sampel'],
        $row['jenis_material'],
        $row['parameter'],
        $row['nilai'],
        $row['satuan'],
        $row['batas_min']  ?? '-',
        $row['batas_maks'] ?? '-',
        $row['metode'],
        $row['analis']     ?? '-',
        $row['tanggal_uji']
            ? date('d/m/Y', strtotime($row['tanggal_uji']))
            : '-',
        $kLabel,
    ];

    foreach ($vals as $i => $v) {
        // Warna teks kolom Kesimpulan
        if ($i === 11) {
            if ($row['kesimpulan'] === 'lulus')
                $pdf->SetTextColor(0, 150, 0);
            else
                $pdf->SetTextColor(180, 0, 0);
        }
        $pdf->Cell($colW[$i], 6, $v, 1, 0, 'C', $fill);
        if ($i === 11) $pdf->SetTextColor(30, 30, 30);
    }
    $pdf->Ln();
    $fill = !$fill;
}

// ── FOOTER ────────────────────────────────────────────────────
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->SetTextColor(130, 130, 130);
$pdf->Cell(0, 5,
    'Dokumen ini dibuat otomatis oleh LabMineral Pro v2.0.0. ' .
    'Hanya berlaku sebagai dokumen informasi internal.',
    0, 1, 'C');

// ── OUTPUT FILE ───────────────────────────────────────────────
$filename = 'HasilUji_LabMineral_' . date('Ymd') . '.pdf';
$pdf->Output($filename, 'D'); // D = paksa download
exit;
