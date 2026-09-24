<?php
// ============================================================
//  actions/simpan_hasil_uji.php
//  FIX S-5: hapus update status manual — kini dihandle trigger
//  FIX S-6: simpan no_referensi ke kolom hasil_uji
//  UPDATE: Tambah fungsi edit
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$action = $_POST['action'] ?? 'insert';

// ============================================================
// HAPUS HASIL UJI
// ============================================================
if ($action === 'hapus') {
    $id = (int)($_POST['id'] ?? 0);
    $redirect = $_POST['redirect'] ?? BASE_URL . '/pages/pengujian.php?tab=hasil';
    if ($id) {
        try {
            $pdo->beginTransaction();
            // Cari sampel_id dan penerimaan_id dari hasil uji yang akan dihapus
            $stSampel = $pdo->prepare("SELECT h.sampel_id, s.penerimaan_id FROM hasil_uji h JOIN sampel s ON h.sampel_id = s.id WHERE h.id = ?");
            $stSampel->execute([$id]);
            $info = $stSampel->fetch();
            
            $pdo->prepare("DELETE FROM hasil_uji WHERE id = ?")->execute([$id]);
            
            if ($info) {
                // Cek apakah sampel ini masih punya hasil uji lain
                $stCek = $pdo->prepare("SELECT COUNT(*) FROM hasil_uji WHERE sampel_id = ?");
                $stCek->execute([$info['sampel_id']]);
                if ($stCek->fetchColumn() == 0) {
                    // Kembalikan status sampel ke 'diuji'
                    $pdo->prepare("UPDATE sampel SET status = 'diuji' WHERE id = ?")->execute([$info['sampel_id']]);
                }
                
                if ($info['penerimaan_id']) {
                    // Karena ada hasil uji yang dihapus, penerimaan ini pasti belum selesai 100%
                    $pdo->prepare("UPDATE penerimaan_sampel SET status = 'diproses' WHERE id = ?")->execute([$info['penerimaan_id']]);
                    // Cabut akses client karena belum selesai
                    $pdo->prepare("UPDATE client_access SET status = 'aktif' WHERE penerimaan_id = ?")->execute([$info['penerimaan_id']]);
                }
            }
            $pdo->commit();
            $_SESSION['msg'] = 'Hasil uji berhasil dihapus.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['msg'] = 'ERROR: Gagal menghapus hasil uji.';
        }
    }
    header('Location: ' . $redirect);
    exit;
}

// ============================================================
// EDIT HASIL UJI
// ============================================================
if ($action === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $parameter = trim($_POST['parameter'] ?? '');
    $nilai = (float)($_POST['nilai'] ?? 0);
    $satuan = $_POST['satuan'] ?? '';
    $metode = $_POST['metode'] ?? '';
    $alatId = !empty($_POST['alat_id']) ? (int)$_POST['alat_id'] : null;
    $analisId = !empty($_POST['analis_id']) ? (int)$_POST['analis_id'] : null;
    $tanggalUji = $_POST['tanggal_uji'] ?? date('Y-m-d');
    $kesimpulan = $_POST['kesimpulan'] ?? 'lulus';
    $catatan = trim($_POST['catatan'] ?? '');
    $redirect = $_POST['redirect'] ?? BASE_URL . '/pages/pengujian.php?tab=hasil';
    
    if (!$id) {
        $_SESSION['msg'] = 'ERROR: ID tidak valid.';
        header('Location: ' . $redirect);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE hasil_uji 
            SET parameter = ?, nilai = ?, satuan = ?, metode = ?,
                alat_id = ?, analis_id = ?, tanggal_uji = ?,
                kesimpulan = ?, catatan = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $parameter, $nilai, $satuan, $metode,
            $alatId, $analisId, $tanggalUji,
            $kesimpulan, $catatan, $id
        ]);

        $stPid = $pdo->prepare("
            SELECT s.penerimaan_id
            FROM hasil_uji h
            JOIN sampel s ON s.id = h.sampel_id
            WHERE h.id = ?
        ");
        $stPid->execute([$id]);
        $penerimaanId = (int)$stPid->fetchColumn();
        if ($penerimaanId) {
            syncPenerimaanCompletion($pdo, $penerimaanId);
            cleanupCompletedClientAccounts($pdo, $penerimaanId);
        }
        
        $_SESSION['msg'] = "Hasil uji berhasil diperbarui.";
        
    } catch (PDOException $e) {
        $_SESSION['msg'] = 'ERROR: ' . $e->getMessage();
    }
    
    header('Location: ' . $redirect);
    exit;
}

// ============================================================
// INSERT HASIL UJI (yang sudah ada sebelumnya)
// ============================================================
$nilai    = (float)($_POST['nilai']      ?? 0);
$redirect = $_POST['redirect'] ?? BASE_URL.'/pages/pengujian.php?tab=hasil';
$sampelId = (int)($_POST['sampel_id'] ?? 0);

if (!$sampelId) {
    $_SESSION['msg'] = 'ERROR: Sampel tidak valid.';
    header('Location: '.$redirect); exit;
}

// Kesimpulan selalu 'lulus' — batas min/maks dihapus dari form
$kes = 'lulus';

// Auto-generate kode uji
$last    = $pdo->query("SELECT kode_uji FROM hasil_uji ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextNum = $last ? (intval(substr($last, 2)) + 1) : 1;
$kode    = 'U-'.str_pad($nextNum, 3, '0', STR_PAD_LEFT);

// ── FIX S-6: ambil no_referensi dari relasi sampel ──────────
$noRef = null;
if (!empty($_POST['no_referensi'])) {
    $noRef = trim($_POST['no_referensi']);
} else {
    $stRef = $pdo->prepare(
        "SELECT rec.nomor_penerimaan
         FROM sampel s
         LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
         WHERE s.id = ?"
    );
    $stRef->execute([$sampelId]);
    $noRef = $stRef->fetchColumn() ?: null;
}

$pdo->prepare(
    "INSERT INTO hasil_uji
     (kode_uji, sampel_id, no_referensi, parameter, nilai, satuan,
      metode, alat_id, analis_id, kesimpulan, catatan, tanggal_uji)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
)->execute([
    $kode, $sampelId, $noRef,
    trim($_POST['parameter']),
    $nilai,
    $_POST['satuan'],
    $_POST['metode'],
    !empty($_POST['alat_id'])   ? (int)$_POST['alat_id']   : null,
    !empty($_POST['analis_id']) ? (int)$_POST['analis_id'] : null,
    $kes,
    trim($_POST['catatan'] ?? ''),
    $_POST['tanggal_uji'],
]);

$stPid = $pdo->prepare("SELECT penerimaan_id FROM sampel WHERE id = ?");
$stPid->execute([$sampelId]);
$penerimaanId = (int)$stPid->fetchColumn();
if ($penerimaanId) {
    syncPenerimaanCompletion($pdo, $penerimaanId);
    cleanupCompletedClientAccounts($pdo, $penerimaanId);
}

$_SESSION['msg'] = "Hasil uji $kode disimpan. Kesimpulan: ".strtoupper($kes);
header('Location: '.$redirect); exit;
