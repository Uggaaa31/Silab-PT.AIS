<?php
// ============================================================
//  actions/simpan_submit_sampel.php
//  Menyimpan data submission dari SSF langsung ke Penerimaan Sampel
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';

$nomorSubmission = trim($_POST['nomor_submission'] ?? '');
$klien = trim($_POST['klien'] ?? '');
$kontakPerson = trim($_POST['kontak_person'] ?? '');
$email = trim($_POST['email'] ?? '');
$telepon = trim($_POST['telepon'] ?? '');
$alamat = trim($_POST['alamat'] ?? '');
$instruksiKhusus = trim($_POST['instruksi_khusus'] ?? '');
$catatan = trim($_POST['catatan'] ?? '');
$sampelInput = $_POST['sampel'] ?? [];

// Validasi
if (!$nomorSubmission || !$klien || !$email || !$telepon || !$alamat) {
    $_SESSION['msg'] = 'ERROR: Nomor submission, nama klien, email, telepon, dan alamat wajib diisi.';
    header('Location: ' . BASE_URL . '/pages/ssf.php');
    exit;
}

if (empty($sampelInput)) {
    $_SESSION['msg'] = 'ERROR: Minimal 1 sampel harus diisi.';
    header('Location: ' . BASE_URL . '/pages/ssf.php');
    exit;
}

// Cek duplikat nomor submission
$cek = $pdo->prepare("SELECT id FROM submission_sampel WHERE nomor_submission = ?");
$cek->execute([$nomorSubmission]);
if ($cek->fetch()) {
    $_SESSION['msg'] = "ERROR: Nomor submission $nomorSubmission sudah ada.";
    header('Location: ' . BASE_URL . '/pages/ssf.php');
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Generate nomor penerimaan
    $lastRec = $pdo->query("SELECT nomor_penerimaan FROM penerimaan_sampel ORDER BY id DESC LIMIT 1")->fetchColumn();
    $nextRecNum = $lastRec ? (intval(substr($lastRec, -3)) + 1) : 1;
    $noPenerimaan = 'REC-' . date('ym') . '-' . str_pad($nextRecNum, 3, '0', STR_PAD_LEFT);

    // Kumpulkan material & metode unik
    $materialsArr = [];
    $metodeArr = [];
    foreach ($sampelInput as $s) {
        if (!empty($s['jenis_material'])) $materialsArr[] = trim($s['jenis_material']);
        if (!empty($s['metode_uji']))     $metodeArr[]    = trim($s['metode_uji']);
    }
    $materialStr = implode(', ', array_unique(array_filter($materialsArr)));
    $metodeStr   = implode(', ', array_unique(array_filter($metodeArr)));

    // 2. Keterangan penerimaan
    $keteranganRec = 'Dari SSF: ' . $nomorSubmission;
    if (!empty($instruksiKhusus)) {
        $keteranganRec .= ' | Instruksi: ' . $instruksiKhusus;
    }
    if (!empty($catatan)) {
        $keteranganRec .= ' | Catatan: ' . $catatan;
    }

    // 3. Insert ke penerimaan_sampel
    $stmtRec = $pdo->prepare("
        INSERT INTO penerimaan_sampel 
        (nomor_penerimaan, klien, tanggal_terima, jumlah_sampel, jenis_material, metode_uji, keterangan, status, dibuat_oleh)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'diterima', ?)
    ");
    $stmtRec->execute([
        $noPenerimaan,
        $klien,
        date('Y-m-d'),
        count($sampelInput),
        $materialStr,
        $metodeStr,
        $keteranganRec,
        $_SESSION['user_id'] ?? null
    ]);
    $penerimaanId = $pdo->lastInsertId();

    // 4. Generate kode sampel berikutnya
    $prefix = 'S-' . date('ym') . '-';
    $lastKode = $pdo->prepare("SELECT kode_sampel FROM sampel WHERE kode_sampel LIKE ? ORDER BY kode_sampel DESC LIMIT 1");
    $lastKode->execute([$prefix . '%']);
    $lastKodeStr = $lastKode->fetchColumn();
    $nextKodeNum = $lastKodeStr ? (intval(substr($lastKodeStr, -3)) + 1) : 1;

    // 5. Insert sampel individual
    $stmtSampel = $pdo->prepare("
        INSERT INTO sampel 
        (penerimaan_id, kode_sampel, tanggal_masuk, jenis_material, berat_gram, klien, metode_uji, keterangan, status, dibuat_oleh)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'antrian', ?)
    ");

    foreach ($sampelInput as $s) {
        $kodeSampel = 'S-' . date('ym') . '-' . str_pad($nextKodeNum, 3, '0', STR_PAD_LEFT);
        $ket = trim($s['keterangan'] ?? '');
        if (!empty($s['parameter'])) {
            $ket = ($ket !== '' ? $ket . ' | ' : '') . 'Param: ' . trim($s['parameter']);
        }
        $stmtSampel->execute([
            $penerimaanId,
            $kodeSampel,
            date('Y-m-d'),
            trim($s['jenis_material'] ?? ''),
            !empty($s['berat_gram']) ? (float)$s['berat_gram'] : null,
            $klien,
            trim($s['metode_uji'] ?? ''),
            $ket,
            $_SESSION['user_id'] ?? null
        ]);
        $nextKodeNum++;
    }
    
    // 6. Insert ke tabel submission_sampel (status 'diterima')
    $stmtSub = $pdo->prepare("
        INSERT INTO submission_sampel 
        (nomor_submission, klien, kontak_person, email, telepon, alamat, 
         po_referensi, instruksi_khusus, catatan, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'diterima')
    ");
    $stmtSub->execute([
        $nomorSubmission,
        $klien,
        $kontakPerson ?: null,
        $email,
        $telepon,
        $alamat,
        '',
        $instruksiKhusus,
        $catatan
    ]);
    $submissionId = $pdo->lastInsertId();
    
    // 7. Insert detail submission
    $stmtDetail = $pdo->prepare("
        INSERT INTO submission_sampel_detail 
        (submission_id, jenis_material, berat_gram, metode_uji, parameter, keterangan)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($sampelInput as $s) {
        $stmtDetail->execute([
            $submissionId,
            trim($s['jenis_material'] ?? ''),
            !empty($s['berat_gram']) ? (float)$s['berat_gram'] : null,
            trim($s['metode_uji'] ?? ''),
            trim($s['parameter'] ?? ''),
            trim($s['keterangan'] ?? '')
        ]);
    }
    
    $pdo->commit();

    // 8. Buat/tautkan akun client access untuk monitoring status
    $clientAccount = createClientAccountForAccess($pdo, [
        'kode_akses' => $nomorSubmission,
        'submission_id' => $submissionId,
        'penerimaan_id' => $penerimaanId,
        'klien' => $klien,
        'email' => $email,
    ]);

    $_SESSION['success'] = "Formulir pengajuan sampel berhasil dikirim. Nomor Submission: $nomorSubmission (No. Penerimaan: $noPenerimaan). Data telah langsung masuk ke Daftar Penerimaan Sampel.";
    $_SESSION['submission_no'] = $nomorSubmission;
    if ($clientAccount['created'] ?? false) {
        $_SESSION['client_credentials'] = $clientAccount;
    } elseif (!empty($clientAccount['message'])) {
        $_SESSION['msg'] = 'Pengajuan tersimpan, tetapi akun client belum dibuat: ' . $clientAccount['message'];
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['msg'] = 'ERROR: Gagal menyimpan data. ' . $e->getMessage();
}

header('Location: ' . BASE_URL . '/pages/ssf.php');
exit;
