<?php
// ============================================================
//  actions/simpan_hasil_uji_batch.php
//  FIX K-2: kode uji digenerate SATU KALI di awal transaksi,
//           bukan di dalam loop (menghilangkan race condition)
//  FIX S-6: simpan no_referensi ke kolom hasil_uji
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$rows       = $_POST['rows']             ?? [];
$analisId   = !empty($_POST['analis_id_all'])   ? (int)$_POST['analis_id_all']   : null;
$tglUji     = $_POST['tanggal_uji_all']  ?? date('Y-m-d');

if (empty($rows)) {
    $_SESSION['msg'] = 'ERROR: Tidak ada data yang disimpan.';
    header('Location: '.BASE_URL.'/pages/pengujian.php?tab=batch'); exit;
}

// Hitung berapa baris valid terlebih dahulu
$validRows = array_filter($rows, fn($r) =>
    !empty($r['sampel_id']) && trim($r['parameter'] ?? '') !== '' && isset($r['nilai']) && $r['nilai'] !== ''
);

if (empty($validRows)) {
    $_SESSION['msg'] = 'ERROR: Tidak ada baris valid (sampel, parameter, dan nilai wajib diisi).';
    header('Location: '.BASE_URL.'/pages/pengujian.php?tab=batch'); exit;
}

try {
    $pdo->beginTransaction();

    // ── FIX K-2: Ambil nomor terakhir SATU KALI di luar loop ─
    $lastKodeUji = $pdo->query(
        "SELECT kode_uji FROM hasil_uji ORDER BY id DESC LIMIT 1 FOR UPDATE"
    )->fetchColumn();
    $nextUjiNum  = $lastKodeUji ? (intval(substr($lastKodeUji, 2)) + 1) : 1;

    $stmtInsert = $pdo->prepare(
        "INSERT INTO hasil_uji
         (kode_uji, sampel_id, no_referensi, parameter, nilai, satuan,
          metode, analis_id, kesimpulan, tanggal_uji)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmtNoRef = $pdo->prepare(
        "SELECT rec.nomor_penerimaan
         FROM sampel s
         LEFT JOIN penerimaan_sampel rec ON s.penerimaan_id = rec.id
         WHERE s.id = ?"
    );

    $stmtPenerimaan = $pdo->prepare("SELECT penerimaan_id FROM sampel WHERE id = ?");

    $saved = 0;
    $penerimaanIds = [];
    foreach ($validRows as $r) {
        $sampelId = (int)$r['sampel_id'];
        $nilai    = (float)$r['nilai'];

        // Ambil nilai kesimpulan dari dropdown di baris hasil uji (default: pending)
        $kes = $r['kesimpulan'] ?? 'pending';

        // Kode uji berurutan (FIX K-2: increment dari nilai awal, bukan query ulang)
        $kode = 'U-' . str_pad($nextUjiNum, 3, '0', STR_PAD_LEFT);
        $nextUjiNum++;

        // Ambil no_referensi dari relasi sampel → penerimaan (FIX S-6)
        $stmtNoRef->execute([$sampelId]);
        $noRef = $stmtNoRef->fetchColumn() ?: null;
        // Override dengan nilai dari form jika ada
        if (!empty($r['no_referensi'])) $noRef = $r['no_referensi'];

        $stmtInsert->execute([
            $kode, $sampelId, $noRef,
            trim($r['parameter']),
            $nilai,
            $r['satuan']  ?? 'g/t',
            $r['metode']  ?? 'AAS',
            $analisId,
            $kes,
            $tglUji,
        ]);

        $saved++;
        $stmtPenerimaan->execute([$sampelId]);
        $penerimaanId = (int)$stmtPenerimaan->fetchColumn();
        if ($penerimaanId) $penerimaanIds[$penerimaanId] = true;
    }

    $pdo->commit();

    foreach (array_keys($penerimaanIds) as $penerimaanId) {
        syncPenerimaanCompletion($pdo, (int)$penerimaanId);
        cleanupCompletedClientAccounts($pdo, (int)$penerimaanId);
    }

    $_SESSION['msg'] = "$saved hasil uji berhasil disimpan.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['msg'] = 'ERROR: '.$e->getMessage();
}

header('Location: '.BASE_URL.'/pages/pengujian.php?tab=hasil'); exit;
