<?php
/* ============================================================
   actions/simpan_qc.php — P2
   ============================================================ */
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$action = $_POST['action'] ?? 'input';

// ── Input data QC baru ────────────────────────────────────────
if ($action === 'input' || !$action) {
    $prepId   = null;
    $sampelId = null;
    $selected = trim($_POST['preparasi_id'] ?? '');

    if ($selected !== '') {
        if (str_starts_with($selected, 'man_')) {
            $sampelId = (int) substr($selected, 4);
        } else {
            $prepId = (int) $selected;
            $row = $pdo->prepare("SELECT sampel_id FROM preparasi_sampel WHERE id=?");
            $row->execute([$prepId]); $r = $row->fetch();
            $sampelId = $r ? $r['sampel_id'] : null;
        }
    }

    if (!$sampelId) {
        $_SESSION['msg'] = 'ERROR: Data preparasi / sampel tidak valid.';
        header('Location: '.BASE_URL.'/pages/qc.php'); exit;
    }

    $nilaiQc   = (float)($_POST['nilai_qc']       ?? 0);
    $nilaiExp  = !empty($_POST['nilai_expected']) ? (float)$_POST['nilai_expected'] : null;
    $bMinPct   = (float)($_POST['batas_min_pct']  ?? 85);
    $bMaksPct  = (float)($_POST['batas_maks_pct'] ?? 115);

    // Recovery dihitung oleh trigger
    // Hitung flag di PHP untuk verifikasi / session message
    $recPct = ($nilaiExp && $nilaiExp > 0) ? ($nilaiQc / $nilaiExp) * 100 : null;
    $flag   = 'pass';
    if ($nilaiExp !== null) {
        $diff = abs($nilaiQc - $nilaiExp);
        if (round($diff, 4) <= 0.05) $flag = 'pass';
        else $flag = 'fail';
    }

    $pdo->prepare(
        "INSERT INTO qc_sampel
         (preparasi_id, sampel_id, tipe_qc, parameter, nilai_qc,
          nilai_expected, satuan, batas_min_pct, batas_maks_pct,
          flag, tanggal_uji)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $prepId, $sampelId,
        $_POST['tipe_qc'],
        trim($_POST['parameter']      ?? ''),
        $nilaiQc, $nilaiExp,
        $_POST['satuan']              ?? 'g/t',
        $bMinPct, $bMaksPct,
        $flag,
        $_POST['tanggal_uji'],
    ]);

    $flagMsg = strtoupper($flag);
    $_SESSION['msg'] = "Data QC berhasil disimpan. Flag: $flagMsg"
        . ($recPct !== null ? " | Recovery: ".number_format($recPct,1)."%" : "");
    header('Location: '.BASE_URL.'/pages/qc.php?tab=dashboard'); exit;
}

if ($action === 'manage') {
    $sampleKey = trim($_POST['manajemen_id_sampel'] ?? '');
    $nilaiCert = !empty($_POST['manajemen_nilai_sertifikat']) ? (float) $_POST['manajemen_nilai_sertifikat'] : null;
    $parameter = trim($_POST['manajemen_parameter'] ?? '');

    if ($sampleKey === '' || $parameter === '' || $nilaiCert === null) {
        $_SESSION['msg'] = 'ERROR: ID Sampel, Nilai Sertifikat, dan Parameter harus diisi.';
        header('Location: '.BASE_URL.'/pages/qc.php?tab=manajemen'); exit;
    }

    $stSample = $pdo->prepare("SELECT id FROM sampel WHERE id = ? OR kode_sampel = ? LIMIT 1");
    $stSample->execute([$sampleKey, $sampleKey]);
    $sample = $stSample->fetch();
    if (!$sample) {
        $createdBy = $_SESSION['user_id'] ?? null;
        $pdo->prepare(
            "INSERT INTO sampel (kode_sampel, tanggal_masuk, jenis_material, status, dibuat_oleh)
             VALUES (?, ?, ?, 'antrian', ?)"
        )->execute([$sampleKey, date('Y-m-d'), 'unknown', $createdBy]);
        $sampelId = $pdo->lastInsertId();
    } else {
        $sampelId = $sample['id'];
    }

    $stExist = $pdo->prepare(
        "SELECT id FROM qc_sampel
         WHERE sampel_id = ? AND tipe_qc = 'standar' AND nilai_qc IS NULL
         LIMIT 1"
    );
    $stExist->execute([$sampelId]);
    $existing = $stExist->fetch();

    if ($existing) {
        $pdo->prepare(
            "UPDATE qc_sampel SET parameter = ?, nilai_expected = ?
             WHERE id = ?"
        )->execute([$parameter, $nilaiCert, $existing['id']]);
    } else {
        $pdo->prepare(
            "INSERT INTO qc_sampel
             (preparasi_id, sampel_id, tipe_qc, parameter, nilai_qc,
              nilai_expected, satuan, batas_min_pct, batas_maks_pct,
              flag, status_qc, tanggal_uji)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            null, $sampelId, 'standar', $parameter, null,
            $nilaiCert, null, 85, 115,
            'pass', 'pending', date('Y-m-d'),
        ]);
    }

    $_SESSION['msg'] = 'Data Manajemen Sampel QC berhasil disimpan.';
    header('Location: '.BASE_URL.'/pages/qc.php?tab=manajemen'); exit;
}

// ── Review QC oleh supervisor ────────────────────────────────
if ($action === 'review') {
    $qcId      = (int)$_POST['qc_id'];
    $keputusan = $_POST['keputusan'] ?? 'disetujui'; // disetujui | ditolak
    $reviewer  = !empty($_POST['reviewer_id']) ? (int)$_POST['reviewer_id'] : $_SESSION['user_id'];
    $catatan   = trim($_POST['catatan_review'] ?? '');

    $pdo->prepare(
        "UPDATE qc_sampel SET status_qc=?, reviewer_id=?, catatan_review=? WHERE id=?"
    )->execute([$keputusan, $reviewer, $catatan, $qcId]);

    $_SESSION['msg'] = 'QC berhasil di-review: '.strtoupper($keputusan).'.';
    header('Location: '.BASE_URL.'/pages/qc.php?tab=review'); exit;
}

header('Location: '.BASE_URL.'/pages/qc.php'); exit;
