<?php
// ============================================================
//  actions/simpan_penerimaan.php
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

if (!isAdmin()) {
    $_SESSION['msg'] = 'ERROR: Hanya Administrator yang dapat mengubah penerimaan sampel.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$action = $_POST['action'] ?? 'tambah';

// ── Update status batch ──────────────────────────────────────
if ($action === 'update_status') {
    $pdo->prepare("UPDATE penerimaan_sampel SET status = ? WHERE id = ?")
        ->execute([$_POST['status'], (int)$_POST['id']]);
    
    $deleted = cleanupCompletedClientAccounts($pdo, (int)$_POST['id']);
    $_SESSION['msg'] = 'Status penerimaan diperbarui.';
    if ($deleted > 0) {
        $_SESSION['msg'] .= " $deleted akun client selesai dan otomatis dihapus.";
    }
    
    header('Location: ' . BASE_URL . '/pages/penerimaan.php');
    exit;
}

// ── Hapus batch penerimaan + relasi ─────────────────────────
if ($action === 'hapus') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        $_SESSION['msg'] = 'ERROR: ID Penerimaan tidak valid.';
        header('Location: ' . BASE_URL . '/pages/penerimaan.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Ambil nomor penerimaan
        $st = $pdo->prepare("SELECT nomor_penerimaan, keterangan FROM penerimaan_sampel WHERE id = ?");
        $st->execute([$id]);
        $recData = $st->fetch();

        if (!$recData) {
            $_SESSION['msg'] = 'ERROR: Data penerimaan tidak ditemukan.';
            $pdo->rollBack();
            header('Location: ' . BASE_URL . '/pages/penerimaan.php');
            exit;
        }

        $noRec = $recData['nomor_penerimaan'];

        // 2. Ambil semua sampel_id dalam batch penerimaan ini
        $stSampel = $pdo->prepare("SELECT id FROM sampel WHERE penerimaan_id = ?");
        $stSampel->execute([$id]);
        $sampelIds = $stSampel->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($sampelIds)) {
            $inSampel = implode(',', array_fill(0, count($sampelIds), '?'));
            
            // Hapus hasil_uji
            $pdo->prepare("DELETE FROM hasil_uji WHERE sampel_id IN ($inSampel)")->execute($sampelIds);
            
            // Hapus qc_sampel jika tabel ada
            if (tableExists($pdo, 'qc_sampel')) {
                $pdo->prepare("DELETE FROM qc_sampel WHERE sampel_id IN ($inSampel)")->execute($sampelIds);
            }

            // Hapus preparasi_sampel
            $pdo->prepare("DELETE FROM preparasi_sampel WHERE sampel_id IN ($inSampel)")->execute($sampelIds);

            // Hapus work_order_sampel
            $pdo->prepare("DELETE FROM work_order_sampel WHERE sampel_id IN ($inSampel)")->execute($sampelIds);
        }

        // 3. Ambil work_order yang terikat ke penerimaan_id ini
        $stWo = $pdo->prepare("SELECT id FROM work_order WHERE penerimaan_id = ?");
        $stWo->execute([$id]);
        $woIds = $stWo->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($woIds)) {
            $inWo = implode(',', array_fill(0, count($woIds), '?'));
            $pdo->prepare("DELETE FROM work_order_sampel WHERE wo_id IN ($inWo)")->execute($woIds);
            $pdo->prepare("DELETE FROM preparasi_sampel WHERE work_order_id IN ($inWo)")->execute($woIds);
            $pdo->prepare("DELETE FROM work_order WHERE id IN ($inWo)")->execute($woIds);
        }

        // 4. Hapus invoice terkait jika ada
        if (tableExists($pdo, 'invoice')) {
            $pdo->prepare("DELETE FROM invoice WHERE penerimaan_id = ?")->execute([$id]);
        }

        // 5. Update client_access agar penerimaan_id dilepas
        if (tableExists($pdo, 'client_access')) {
            $pdo->prepare("UPDATE client_access SET penerimaan_id = NULL WHERE penerimaan_id = ?")->execute([$id]);
        }

        // 6. Hapus semua sampel dalam batch
        $pdo->prepare("DELETE FROM sampel WHERE penerimaan_id = ?")->execute([$id]);

        // 7. Hapus penerimaan_sampel
        $pdo->prepare("DELETE FROM penerimaan_sampel WHERE id = ?")->execute([$id]);

        // 8. Jika ada submission terkait dari keterangan, kembalikan statusnya ke 'diterima'
        if (preg_match('/SUB-[0-9\-]+/', $recData['keterangan'] ?? '', $mSub)) {
            $pdo->prepare("UPDATE submission_sampel SET status = 'diterima' WHERE nomor_submission = ?")->execute([$mSub[0]]);
        }

        $pdo->commit();
        $_SESSION['msg'] = "Batch Penerimaan $noRec beserta seluruh sampel terkait berhasil dihapus.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['msg'] = 'ERROR: Gagal menghapus penerimaan: ' . $e->getMessage();
    }

    header('Location: ' . BASE_URL . '/pages/penerimaan.php');
    exit;
}

// ── Konfirmasi admin ─────────────────────────────────────────
if ($action === 'konfirmasi') {
    $pdo->prepare("UPDATE penerimaan_sampel SET is_confirmed = 1 WHERE id = ?")
        ->execute([(int)$_POST['id']]);
    $_SESSION['msg'] = 'Batch penerimaan telah dikonfirmasi oleh Admin.';
    header('Location: ' . BASE_URL . '/pages/penerimaan.php');
    exit;
}

// ── Simpan penerimaan baru + semua sampel ────────────────────
$noPenerimaan = trim($_POST['nomor_penerimaan'] ?? '');
$klien        = trim($_POST['klien'] ?? '');
$tglTerima    = $_POST['tanggal_terima'] ?? date('Y-m-d');
$keterangan   = trim($_POST['keterangan'] ?? '');
$sampelInput  = $_POST['sampel'] ?? [];

// Validasi minimal
if (!$noPenerimaan || !$klien) {
    $_SESSION['msg'] = 'ERROR: Nomor penerimaan dan klien wajib diisi.';
    header('Location: ' . BASE_URL . '/pages/penerimaan.php');
    exit;
}

if (empty($sampelInput)) {
    $_SESSION['msg'] = 'ERROR: Minimal 1 sampel harus ditambahkan.';
    header('Location: ' . BASE_URL . '/pages/penerimaan.php');
    exit;
}

// Cek duplikat nomor penerimaan
$cek = $pdo->prepare("SELECT id FROM penerimaan_sampel WHERE nomor_penerimaan = ?");
$cek->execute([$noPenerimaan]);
if ($cek->fetch()) {
    $_SESSION['msg'] = "ERROR: Nomor penerimaan $noPenerimaan sudah ada.";
    header('Location: ' . BASE_URL . '/pages/penerimaan.php');
    exit;
}

// Kumpulkan info material & metode untuk ringkasan batch
$materialsArr = [];
$metodeArr    = [];
foreach ($sampelInput as $s) {
    if (!empty($s['jenis_material'])) $materialsArr[] = $s['jenis_material'];
    if (!empty($s['metode_uji']))     $metodeArr[]    = $s['metode_uji'];
}
$materialStr = implode(', ', array_unique($materialsArr));
$metodeStr   = implode(', ', array_unique($metodeArr));

// Mulai transaksi
try {
    $pdo->beginTransaction();

    // 1. Insert batch penerimaan
    $stmtRec = $pdo->prepare(
        "INSERT INTO penerimaan_sampel
         (nomor_penerimaan, klien, tanggal_terima, jumlah_sampel, jenis_material, metode_uji, keterangan, status, dibuat_oleh)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'diterima', ?)"
    );
    $stmtRec->execute([
        $noPenerimaan,
        $klien,
        $tglTerima,
        count($sampelInput),
        $materialStr,
        $metodeStr,
        $keterangan,
        $_SESSION['user_id'],
    ]);
    $penerimaanId = $pdo->lastInsertId();

    // 2. Auto-generate kode sampel berikutnya
    $prefix = 'S-' . date('ym') . '-';
    $lastKode = $pdo->prepare("SELECT kode_sampel FROM sampel WHERE kode_sampel LIKE ? ORDER BY kode_sampel DESC LIMIT 1");
    $lastKode->execute([$prefix . '%']);
    $lastKodeStr = $lastKode->fetchColumn();
    $nextNum  = $lastKodeStr ? (intval(substr($lastKodeStr, -3)) + 1) : 1;

    // 3. Insert setiap sampel
    $stmtSampel = $pdo->prepare(
        "INSERT INTO sampel
         (penerimaan_id, kode_sampel, tanggal_masuk, jenis_material, berat_gram, klien, metode_uji, keterangan, dibuat_oleh)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $kodeList = [];
    foreach ($sampelInput as $s) {
        $kodeSampel = 'S-' . date('ym') . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        $stmtSampel->execute([
            $penerimaanId,
            $kodeSampel,
            $tglTerima,
            trim($s['jenis_material'] ?? ''),
            !empty($s['berat_gram']) ? (float)$s['berat_gram'] : null,
            $klien,
            trim($s['metode_uji'] ?? ''),
            trim($s['keterangan'] ?? ''),
            $_SESSION['user_id'],
        ]);
        $kodeList[] = $kodeSampel;
        $nextNum++;
    }

    // 4. Backward Sync ke tabel submission klien (jika dari form SSF)
    $fromSubmission = (int)($_POST['from_submission'] ?? 0);
    if ($fromSubmission > 0) {
        // Update status
        $pdo->prepare("UPDATE submission_sampel SET status = 'diproses' WHERE id = ?")->execute([$fromSubmission]);
        
        // Hapus detail lama, ganti dengan data sampel aktual yang baru diinput admin lab
        $pdo->prepare("DELETE FROM submission_sampel_detail WHERE submission_id = ?")->execute([$fromSubmission]);
        $stmtSubDetail = $pdo->prepare("INSERT INTO submission_sampel_detail (submission_id, jenis_material, berat_gram, metode_uji, keterangan) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($sampelInput as $s) {
            $stmtSubDetail->execute([
                $fromSubmission,
                trim($s['jenis_material'] ?? ''),
                !empty($s['berat_gram']) ? (float)$s['berat_gram'] : null,
                trim($s['metode_uji'] ?? ''),
                trim($s['keterangan'] ?? '')
            ]);
        }
    }

    $pdo->commit();

    $jumlah = count($kodeList);
    $kodeStr = implode(', ', $kodeList);
    $_SESSION['msg'] = "Penerimaan $noPenerimaan berhasil disimpan. $jumlah sampel dibuat: $kodeStr.";

    $clientAccount = createClientAccountForAccess($pdo, [
        'kode_akses' => $noPenerimaan,
        'submission_id' => $fromSubmission > 0 ? $fromSubmission : null,
        'penerimaan_id' => $penerimaanId,
        'klien' => $klien,
    ]);

    if ($clientAccount['created'] ?? false) {
        $_SESSION['msg'] .= " Akun client: username {$clientAccount['username']}, password {$clientAccount['password']}.";
    } elseif (!empty($clientAccount['message'])) {
        $_SESSION['msg'] .= " Akun client belum dibuat: {$clientAccount['message']}";
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['msg'] = 'ERROR: Gagal menyimpan — ' . $e->getMessage();
}

header('Location: ' . BASE_URL . '/pages/penerimaan.php');
exit;
