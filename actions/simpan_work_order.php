<?php
// ============================================================
//  actions/simpan_work_order.php
//  UPDATE: WO batch via penerimaan_id + pivot work_order_sampel
//          Backward-compat: mode single (sampel_ids[])
//          Fix: sampel_id tidak disertakan di INSERT (nullable)
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();
ensureWorkOrderSchema($pdo);

$action = $_POST['action'] ?? '';

try {

switch ($action) {

    // ─────────────────────────────────────────────────────────
    // BUAT WO BARU
    // ─────────────────────────────────────────────────────────
    case 'buat':
        $mode          = $_POST['mode_wo']       ?? 'batch';
        $nomorWo       = trim($_POST['nomor_wo']  ?? '');
        $prioritas     = $_POST['prioritas']      ?? 'normal';
        $analisId      = intval($_POST['analis_id']    ?? 0) ?: null;
        $peralatanId   = intval($_POST['peralatan_id'] ?? 0) ?: null;
        $metode        = trim($_POST['metode']    ?? '');
        $parameter     = trim($_POST['parameter'] ?? '');
        $jadwalMulai   = $_POST['jadwal_mulai']   ?? null;
        $jadwalSelesai = $_POST['jadwal_selesai'] ?? null;
        $catatan       = trim($_POST['catatan']   ?? '');
        $statusAwal    = $_POST['status_awal']    ?? 'draft';
        $butuhPrep     = isset($_POST['butuh_preparasi']) ? 1 : 0;

        // ── Validasi dasar ───────────────────────────────────
        if (!$nomorWo) {
            $_SESSION['msg'] = 'ERROR: Nomor WO tidak boleh kosong.';
            header('Location: ' . BASE_URL . '/pages/work_order.php?tab=buat');
            exit;
        }

        // ── Cek duplikat nomor WO ────────────────────────────
        $cekDup = $pdo->prepare("SELECT COUNT(*) FROM work_order WHERE nomor_wo = ?");
        $cekDup->execute([$nomorWo]);
        if ($cekDup->fetchColumn() > 0) {
            $_SESSION['msg'] = 'ERROR: Nomor WO ' . htmlspecialchars($nomorWo) . ' sudah digunakan.';
            header('Location: ' . BASE_URL . '/pages/work_order.php?tab=buat');
            exit;
        }

        // ── Normalize jadwal ─────────────────────────────────
        $jadwalMulai   = ($jadwalMulai   !== '') ? $jadwalMulai   : null;
        $jadwalSelesai = ($jadwalSelesai !== '') ? $jadwalSelesai : null;

        // ────────────────────────────────────────────────────
        // MODE BATCH — referensi ke nomor penerimaan
        // ────────────────────────────────────────────────────
        if ($mode === 'batch') {
            $penerimaanId = intval($_POST['penerimaan_id'] ?? 0);
            if (!$penerimaanId) {
                $_SESSION['msg'] = 'ERROR: Pilih nomor penerimaan untuk mode batch.';
                header('Location: ' . BASE_URL . '/pages/work_order.php?tab=buat');
                exit;
            }

            // Pastikan penerimaan valid
            $cekRec = $pdo->prepare("SELECT COUNT(*) FROM penerimaan_sampel WHERE id = ?");
            $cekRec->execute([$penerimaanId]);
            if (!$cekRec->fetchColumn()) {
                $_SESSION['msg'] = 'ERROR: Nomor penerimaan tidak ditemukan.';
                header('Location: ' . BASE_URL . '/pages/work_order.php?tab=buat');
                exit;
            }

            // Ambil sampel dalam batch yang belum ada di WO aktif/draft
            $stSampel = $pdo->prepare("
                SELECT s.id FROM sampel s
                WHERE  s.penerimaan_id = ?
                  AND  s.status IN ('antrian','diuji')
                  AND  s.id NOT IN (
                      SELECT wos.sampel_id
                      FROM   work_order_sampel wos
                      JOIN   work_order wo ON wos.wo_id = wo.id
                      WHERE  wo.status IN ('draft','aktif')
                  )
            ");
            $stSampel->execute([$penerimaanId]);
            $sampelIds = $stSampel->fetchAll(PDO::FETCH_COLUMN);

            if (empty($sampelIds)) {
                $_SESSION['msg'] = 'ERROR: Tidak ada sampel tersedia dalam batch ini (semua sudah punya WO aktif).';
                header('Location: ' . BASE_URL . '/pages/work_order.php?tab=buat');
                exit;
            }

            $pdo->beginTransaction();

            // INSERT work_order — sampel_id TIDAK disertakan (sudah NULL-able)
            $stWo = $pdo->prepare("
                INSERT INTO work_order
                    (nomor_wo, penerimaan_id, lingkup_batch,
                     analis_id, peralatan_id,
                     metode, parameter, prioritas,
                     jadwal_mulai, jadwal_selesai, catatan, status, butuh_preparasi)
                VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stWo->execute([
                $nomorWo, $penerimaanId,
                $analisId, $peralatanId,
                $metode ?: null, $parameter ?: null, $prioritas,
                $jadwalMulai, $jadwalSelesai,
                $catatan ?: null, $statusAwal, $butuhPrep
            ]);
            $woId = $pdo->lastInsertId();

            // Isi pivot + update status sampel
            _insertPivotDanUpdateStatus($pdo, $woId, $sampelIds, $statusAwal);

            $pdo->commit();

            $jumlah = count($sampelIds);
            $_SESSION['msg'] = "Work Order {$nomorWo} berhasil dibuat ({$jumlah} sampel dari batch).";

        // ────────────────────────────────────────────────────
        // MODE SINGLE — pilih sampel spesifik via checkbox
        // ────────────────────────────────────────────────────
        } else {
            $rawIds    = $_POST['sampel_ids'] ?? [];
            $sampelIds = array_values(array_filter(array_map('intval', $rawIds)));

            if (empty($sampelIds)) {
                $_SESSION['msg'] = 'ERROR: Pilih minimal satu sampel.';
                header('Location: ' . BASE_URL . '/pages/work_order.php?tab=buat');
                exit;
            }

            // Ambil penerimaan_id dari sampel pertama sebagai referensi (opsional)
            $stRec = $pdo->prepare("
                SELECT penerimaan_id FROM sampel WHERE id = ? LIMIT 1
            ");
            $stRec->execute([$sampelIds[0]]);
            $refPenerimaanId = $stRec->fetchColumn() ?: null;

            $pdo->beginTransaction();

            // INSERT work_order — sampel_id NULL
            $stWo = $pdo->prepare("
                INSERT INTO work_order
                    (nomor_wo, penerimaan_id, lingkup_batch,
                     analis_id, peralatan_id,
                     metode, parameter, prioritas,
                     jadwal_mulai, jadwal_selesai, catatan, status, butuh_preparasi)
                VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stWo->execute([
                $nomorWo, $refPenerimaanId,
                $analisId, $peralatanId,
                $metode ?: null, $parameter ?: null, $prioritas,
                $jadwalMulai, $jadwalSelesai,
                $catatan ?: null, $statusAwal, $butuhPrep
            ]);
            $woId = $pdo->lastInsertId();

            // Isi pivot + update status sampel
            _insertPivotDanUpdateStatus($pdo, $woId, $sampelIds, $statusAwal);

            $pdo->commit();

            $jumlah = count($sampelIds);
            $_SESSION['msg'] = "Work Order {$nomorWo} berhasil dibuat ({$jumlah} sampel).";
        }

        header('Location: ' . BASE_URL . '/pages/work_order.php?tab=daftar');
        exit;

    // ─────────────────────────────────────────────────────────
    // AKTIVASI WO (draft → aktif)
    // ─────────────────────────────────────────────────────────
    case 'aktivasi':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            $_SESSION['msg'] = 'ERROR: ID WO tidak valid.';
            header('Location: ' . BASE_URL . '/pages/work_order.php');
            exit;
        }

        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE work_order SET status = 'aktif'
            WHERE id = ? AND status = 'draft'
        ")->execute([$id]);

        // Update semua sampel dalam WO ini ke 'diuji'
        $sampelIds = _getSampelIds($pdo, $id);
        $stUpd = $pdo->prepare("UPDATE sampel SET status = 'diuji' WHERE id = ?");
        foreach ($sampelIds as $sid) {
            $stUpd->execute([$sid]);
        }

        $pdo->commit();

        $_SESSION['msg'] = 'Work Order berhasil diaktifkan.';
        header('Location: ' . BASE_URL . '/pages/work_order.php?tab=daftar');
        exit;

    // ─────────────────────────────────────────────────────────
    // SELESAIKAN WO (aktif → selesai)
    // ─────────────────────────────────────────────────────────
    case 'selesaikan':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            $_SESSION['msg'] = 'ERROR: ID WO tidak valid.';
            header('Location: ' . BASE_URL . '/pages/work_order.php');
            exit;
        }

        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE work_order
            SET    status     = 'selesai',
                   selesai_at = NOW()
            WHERE  id = ? AND status = 'aktif'
        ")->execute([$id]);

        // Update status sampel → selesai
        $sampelIds = _getSampelIds($pdo, $id);
        $stUpd = $pdo->prepare("UPDATE sampel SET status = 'selesai' WHERE id = ?");
        foreach ($sampelIds as $sid) {
            $stUpd->execute([$sid]);
        }

        $pdo->commit();

        $_SESSION['msg'] = 'Work Order berhasil ditandai selesai.';
        header('Location: ' . BASE_URL . '/pages/work_order.php?tab=daftar');
        exit;

    // ─────────────────────────────────────────────────────────
    // BATALKAN WO
    // ─────────────────────────────────────────────────────────
    case 'batalkan':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            $_SESSION['msg'] = 'ERROR: ID WO tidak valid.';
            header('Location: ' . BASE_URL . '/pages/work_order.php');
            exit;
        }

        $pdo->beginTransaction();

        // Ambil sampel SEBELUM batalkan (untuk rollback status)
        $sampelIds = _getSampelIds($pdo, $id);

        $pdo->prepare("
            UPDATE work_order SET status = 'dibatalkan'
            WHERE  id = ? AND status != 'selesai'
        ")->execute([$id]);

        // Rollback status sampel → antrian
        $stUpd = $pdo->prepare("UPDATE sampel SET status = 'antrian' WHERE id = ?");
        foreach ($sampelIds as $sid) {
            $stUpd->execute([$sid]);
        }

        $pdo->commit();

        $_SESSION['msg'] = 'Work Order dibatalkan. Status sampel dikembalikan ke antrian.';
        header('Location: ' . BASE_URL . '/pages/work_order.php?tab=daftar');
        exit;

    default:
        header('Location: ' . BASE_URL . '/pages/work_order.php');
        exit;
}

} catch (PDOException $e) {
    // Rollback jika ada transaksi yang berjalan
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errCode = $e->getCode();
    $errMsg  = $e->getMessage();

    // Terjemahkan kode error MySQL ke pesan yang informatif
    if ($errCode === '23000') {
        if (strpos($errMsg, 'Duplicate') !== false) {
            $_SESSION['msg'] = 'ERROR: Nomor WO sudah digunakan, gunakan nomor lain.';
        } elseif (strpos($errMsg, 'sampel_id') !== false) {
            // Error spesifik FK sampel_id — panduan untuk fix DB
            $_SESSION['msg'] = 'ERROR: Kolom sampel_id masih NOT NULL di database. '
                             . 'Jalankan file fix_sampel_id_nullable.sql di phpMyAdmin terlebih dahulu.';
        } elseif (strpos($errMsg, 'penerimaan_id') !== false) {
            $_SESSION['msg'] = 'ERROR: Nomor penerimaan tidak valid.';
        } else {
            $_SESSION['msg'] = 'ERROR: Pelanggaran constraint database. Periksa data yang diinput. [23000]';
        }
    } elseif ($errCode === '42S02') {
        $_SESSION['msg'] = 'ERROR: Tabel tidak ditemukan. '
                         . 'Pastikan fix_work_order_batch.sql sudah dijalankan di phpMyAdmin.';
    } else {
        error_log('[simpan_work_order] PDOException ' . $errCode . ': ' . $errMsg);
        $_SESSION['msg'] = 'ERROR: Kesalahan database: ' . htmlspecialchars($errMsg) . ' [Kode: ' . $errCode . ']';
    }

    header('Location: ' . BASE_URL . '/pages/work_order.php?tab=buat');
    exit;
}

// =============================================================
// HELPER FUNCTIONS
// =============================================================

/**
 * Insert baris ke pivot work_order_sampel
 * dan update status semua sampel terkait secara atomik.
 */
function _insertPivotDanUpdateStatus(
    PDO    $pdo,
    int    $woId,
    array  $sampelIds,
    string $statusWo
): void {
    $stPivot = $pdo->prepare("
        INSERT IGNORE INTO work_order_sampel (wo_id, sampel_id)
        VALUES (?, ?)
    ");

    // WO aktif langsung → sampel 'diuji'; WO draft → sampel tetap 'antrian'
    $statusSampel = ($statusWo === 'aktif') ? 'diuji' : 'antrian';
    $stUpd = $pdo->prepare("UPDATE sampel SET status = ? WHERE id = ?");

    foreach ($sampelIds as $sid) {
        $sid = intval($sid);
        if ($sid <= 0) continue;
        $stPivot->execute([$woId, $sid]);
        $stUpd->execute([$statusSampel, $sid]);
    }
}

/**
 * Ambil semua sampel_id yang terkait WO via pivot.
 */
function _getSampelIds(PDO $pdo, int $woId): array
{
    $st = $pdo->prepare("
        SELECT sampel_id FROM work_order_sampel WHERE wo_id = ?
    ");
    $st->execute([$woId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
