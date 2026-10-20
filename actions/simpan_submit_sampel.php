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

    // 1. Insert ke tabel submission_sampel (status 'diterima' oleh sistem)
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
    
    // 2. Insert detail submission
    $stmtDetail = $pdo->prepare("
        INSERT INTO submission_sampel_detail 
        (submission_id, jenis_material, berat_gram, metode_uji, parameter, keterangan)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($sampelInput as $s) {
        $paramArr = [];
        if (!empty($s['parameter'])) {
            if (is_array($s['parameter'])) {
                $paramArr = $s['parameter'];
            } else {
                $paramArr = array_map('trim', explode(',', $s['parameter']));
            }
        }
        $paramLainnya = trim($s['parameter_lainnya'] ?? '');
        if ($paramLainnya !== '') {
            $keyLainnya = array_search('Lainnya', $paramArr);
            if ($keyLainnya !== false) {
                $paramArr[$keyLainnya] = $paramLainnya;
            } elseif (!in_array($paramLainnya, $paramArr)) {
                $paramArr[] = $paramLainnya;
            }
        }
        $paramStr = implode(', ', array_filter(array_map('trim', $paramArr)));

        $stmtDetail->execute([
            $submissionId,
            trim($s['jenis_material'] ?? ''),
            !empty($s['berat_gram']) ? (float)$s['berat_gram'] : null,
            trim($s['metode_uji'] ?? ''),
            $paramStr,
            trim($s['keterangan'] ?? '')
        ]);
    }
    
    $pdo->commit();

    // 3. Buat/tautkan akun client access untuk monitoring status (penerimaan_id diisi setelah admin menginput penerimaan)
    $clientAccount = createClientAccountForAccess($pdo, [
        'kode_akses' => $nomorSubmission,
        'submission_id' => $submissionId,
        'penerimaan_id' => null,
        'klien' => $klien,
        'email' => $email,
    ]);

    $_SESSION['success'] = "Formulir permohonan pengujian sampel berhasil dikirim. Nomor Submission: $nomorSubmission. Status pengajuan: Diterima Sistem. Admin laboratorium akan segera menghubungi Anda via WhatsApp untuk konfirmasi pengiriman sampel fisik.";
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
