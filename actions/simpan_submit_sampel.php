<?php
// ============================================================
//  actions/simpan_submit_sampel.php
//  Menyimpan data submission dari klien ke database
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
if (!$nomorSubmission || !$klien || !$kontakPerson || !$email || !$telepon || !$alamat) {
    $_SESSION['msg'] = 'ERROR: Nomor submission, nama klien, kontak person, email, telepon, dan alamat wajib diisi.';
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
    
    // Insert ke tabel submission_sampel dengan status 'pending' (menunggu verifikasi fisik admin)
    $stmt = $pdo->prepare("
        INSERT INTO submission_sampel 
        (nomor_submission, klien, kontak_person, email, telepon, alamat, 
         po_referensi, instruksi_khusus, catatan, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $nomorSubmission,
        $klien,
        $kontakPerson,
        $email,
        $telepon,
        $alamat,
        '',
        $instruksiKhusus,
        $catatan
    ]);
    $submissionId = $pdo->lastInsertId();
    
    // Insert detail sampel
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

    // Buat akun client access untuk monitoring status (penerimaan_id = null sebelum diterima admin)
    $clientAccount = createClientAccountForAccess($pdo, [
        'kode_akses' => $nomorSubmission,
        'submission_id' => $submissionId,
        'penerimaan_id' => null,
        'klien' => $klien,
        'email' => $email,
    ]);

    $_SESSION['success'] = "Formulir pengajuan sampel berhasil dikirim. Nomor submission: $nomorSubmission. Menunggu sampel fisik tiba di laboratorium untuk diverifikasi oleh petugas.";
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
