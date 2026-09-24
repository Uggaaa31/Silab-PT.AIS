<?php
/* ============================================================
   actions/simpan_preparasi.php — P1 Preparasi Sampel
   UPDATE: handle mode batch (semua sampel WO) dan single
   ============================================================ */
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$modeInput = $_POST['mode_input'] ?? 'single';
$woId      = !empty($_POST['work_order_id']) ? (int)$_POST['work_order_id'] : null;
$faktor    = !empty($_POST['faktor_pengenceran']) ? (float)$_POST['faktor_pengenceran'] : 1.0;
$reagenArr = $_POST['reagen'] ?? [];

// ── Tentukan daftar sampel yang akan dipreparasi ─────────────
$sampelIds = [];

if ($modeInput === 'wo' && $woId) {
    // Mode WO: bisa semua sampel dalam WO, atau satu sampel spesifik
    $batchJson   = trim($_POST['sampel_ids_batch'] ?? '');
    $sampelSingle = (int)($_POST['sampel_id'] ?? 0);

    if ($sampelSingle > 0) {
        // User memilih sampel spesifik dari dropdown WO
        $sampelIds = [$sampelSingle];
    } elseif ($batchJson && $batchJson !== '[]') {
        // User memilih "Semua sampel dalam WO" — ambil dari JSON hidden field
        $decoded = json_decode($batchJson, true);
        $sampelIds = array_values(array_filter(array_map('intval', $decoded ?: [])));
    } else {
        // Fallback: query langsung dari pivot jika JS tidak mengisi hidden field
        $stFallback = $pdo->prepare("
            SELECT sampel_id FROM work_order_sampel WHERE wo_id = ?
        ");
        $stFallback->execute([$woId]);
        $sampelIds = $stFallback->fetchAll(PDO::FETCH_COLUMN);
    }
} else {
    // Mode sampel tunggal (dari mode_input=sampel atau fallback lama)
    $sid = (int)($_POST['sampel_id_single'] ?? $_POST['sampel_id'] ?? 0);
    if ($sid > 0) $sampelIds = [$sid];
}

// Validasi: harus ada minimal 1 sampel
if (empty($sampelIds)) {
    $_SESSION['msg'] = 'ERROR: Tidak ada sampel yang dipilih. '
                     . 'Pilih Work Order atau sampel terlebih dahulu.';
    header('Location: ' . BASE_URL . '/pages/preparasi.php?tab=input');
    exit;
}

// ── Siapkan parameter preparasi yang sama untuk semua sampel ─
$metodePrep   = $_POST['metode_preparasi']                    ?? 'destruksi_asam';
$prosedur     = trim($_POST['prosedur']                       ?? '');
$volAwal      = !empty($_POST['volume_awal_ml'])  ? (float)$_POST['volume_awal_ml']  : null;
$volAkhir     = !empty($_POST['volume_akhir_ml']) ? (float)$_POST['volume_akhir_ml'] : null;
$blanko       = isset($_POST['blanko_disiapkan'])   ? 1 : 0;
$standar      = isset($_POST['standar_disiapkan'])  ? 1 : 0;
$spike        = isset($_POST['spike_disiapkan'])    ? 1 : 0;
$duplikat     = isset($_POST['duplikat_disiapkan']) ? 1 : 0;
$suhu         = !empty($_POST['suhu_ruang'])  ? (float)$_POST['suhu_ruang']  : null;
$kelembaban   = !empty($_POST['kelembaban'])  ? (float)$_POST['kelembaban']  : null;
$catatan      = trim($_POST['catatan']        ?? '');
$analisId     = !empty($_POST['analis_id'])   ? (int)$_POST['analis_id']     : null;
$tglPreparasi = $_POST['tanggal_preparasi']   ?? date('Y-m-d');

// ── Proses reagen sekali — stok dikurangi per total pemakaian ─
// Untuk batch: stok dikurangi sesuai jumlah × jumlah_sampel
$jumlahSampel = count($sampelIds);
$reagenData   = [];

try {
    $pdo->beginTransaction();

    // ── STEP 1: Kurangi stok reagen (sekali, proporsional batch) ─
    foreach ($reagenArr as $r) {
        $bahanId     = (int)($r['bahan_id'] ?? 0);
        $jumlahSatuan = (float)($r['jumlah'] ?? 0);
        if (!$bahanId || $jumlahSatuan <= 0) continue;

        // Total pemakaian = per-sampel × jumlah sampel
        $totalPakai = $jumlahSatuan * $jumlahSampel;

        // Kurangi stok — hanya jika stok mencukupi
        $stUpd = $pdo->prepare("
            UPDATE bahan SET stok = stok - ?
            WHERE id = ? AND stok >= ?
        ");
        $stUpd->execute([$totalPakai, $bahanId, $totalPakai]);

        if ($stUpd->rowCount() === 0) {
            // Stok tidak mencukupi — coba kurangi seberapapun yang ada
            $pdo->prepare("UPDATE bahan SET stok = 0 WHERE id = ? AND stok > 0")
                ->execute([$bahanId]);
        }

        // Log keluar (total batch)
        $pdo->prepare("
            INSERT INTO log_bahan (bahan_id, jenis, jumlah, keterangan, pengguna_id)
            VALUES (?, 'keluar', ?, ?, ?)
        ")->execute([
            $bahanId,
            $totalPakai,
            'Preparasi batch ' . $jumlahSampel . ' sampel'
                . ($woId ? ' (WO #' . $woId . ')' : ''),
            $_SESSION['user_id'],
        ]);

        // Ambil nama bahan untuk JSON reagen_detail
        $nb = $pdo->prepare("SELECT nama, kode_bahan FROM bahan WHERE id = ?");
        $nb->execute([$bahanId]);
        $bahan = $nb->fetch();

        $reagenData[] = [
            'bahan_id' => $bahanId,
            'nama'     => $bahan['nama']      ?? '',
            'kode'     => $bahan['kode_bahan'] ?? '',
            'jumlah'   => $jumlahSatuan,        // per sampel
            'satuan'   => $r['satuan'] ?? '',
            'lot'      => $r['lot']    ?? '',
        ];
    }

    $reagenJson = $reagenData
        ? json_encode($reagenData, JSON_UNESCAPED_UNICODE)
        : null;

    // ── STEP 2: Insert preparasi_sampel untuk setiap sampel ──────
    $stPrep = $pdo->prepare("
        INSERT INTO preparasi_sampel
            (work_order_id, sampel_id, metode_preparasi, prosedur,
             faktor_pengenceran, volume_awal_ml, volume_akhir_ml,
             reagen_detail,
             blanko_disiapkan, standar_disiapkan, spike_disiapkan, duplikat_disiapkan,
             suhu_ruang, kelembaban, catatan, analis_id, tanggal_preparasi)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $prepIds = [];
    foreach ($sampelIds as $sid) {
        $sid = (int)$sid;
        if ($sid <= 0) continue;

        $stPrep->execute([
            $woId, $sid,
            $metodePrep, $prosedur,
            $faktor, $volAwal, $volAkhir,
            $reagenJson,
            $blanko, $standar, $spike, $duplikat,
            $suhu, $kelembaban, $catatan,
            $analisId, $tglPreparasi,
        ]);
        $prepIds[] = $pdo->lastInsertId();
    }

    // ── STEP 3: Update faktor pengenceran di hasil_uji (P4) ──────
    if ($faktor != 1.0) {
        $stFaktor = $pdo->prepare("
            UPDATE hasil_uji
            SET    faktor_pengenceran = ?,
                   nilai_terkoreksi   = nilai * ? * COALESCE(faktor_konversi, 1)
            WHERE  sampel_id = ?
        ");
        foreach ($sampelIds as $sid) {
            $stFaktor->execute([$faktor, $faktor, (int)$sid]);
        }
    }

    $pdo->commit();

    // ── Pesan sukses ─────────────────────────────────────────────
    if ($jumlahSampel === 1) {
        $_SESSION['msg'] = 'Catatan preparasi berhasil disimpan.'
            . ($faktor != 1.0 ? " Faktor pengenceran ×{$faktor} diterapkan." : '');
    } else {
        $_SESSION['msg'] = "Catatan preparasi berhasil disimpan untuk {$jumlahSampel} sampel"
            . ($woId ? " dalam WO." : ".")
            . ($faktor != 1.0 ? " Faktor pengenceran ×{$faktor} diterapkan ke semua sampel." : '');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['msg'] = 'ERROR: ' . $e->getMessage();
}

header('Location: ' . BASE_URL . '/pages/preparasi.php?tab=daftar');
exit;
