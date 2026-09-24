<?php
// ============================================================
//  actions/simpan_invoice.php
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

if (!isAdmin()) {
    $_SESSION['msg'] = 'ERROR: Hanya Administrator yang dapat mengubah invoice.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$action = $_POST['action'] ?? 'buat';

// ── Buat invoice baru ────────────────────────────────────────
if ($action === 'buat') {
    $statusAwal = $_POST['status_awal'] ?? 'draft';
    $items      = $_POST['items']       ?? [];

    if (empty(array_filter($items, fn($it) => !empty($it['deskripsi'])))) {
        $_SESSION['msg'] = 'ERROR: Minimal satu item tagihan harus diisi.';
        header('Location: '.BASE_URL.'/pages/invoice.php?tab=buat'); exit;
    }

    try {
        $pdo->beginTransaction();

        // Insert header invoice
        $pdo->prepare(
            "INSERT INTO invoice
             (nomor_invoice, penerimaan_id, klien, alamat_klien,
              tanggal_invoice, tanggal_jatuh_tempo,
              diskon_pct, ppn_pct, status,
              subtotal, diskon_nominal, ppn_nominal, total,
              catatan, dibuat_oleh)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            trim($_POST['nomor_invoice']),
            !empty($_POST['penerimaan_id']) ? (int)$_POST['penerimaan_id'] : null,
            trim($_POST['klien']),
            trim($_POST['alamat_klien'] ?? ''),
            $_POST['tanggal_invoice'],
            !empty($_POST['tanggal_jatuh_tempo']) ? $_POST['tanggal_jatuh_tempo'] : null,
            (float)($_POST['diskon_pct']  ?? 0),
            (float)($_POST['ppn_pct']     ?? 11),
            $statusAwal,
            (int)($_POST['subtotal']       ?? 0),
            (int)($_POST['diskon_nominal'] ?? 0),
            (int)($_POST['ppn_nominal']    ?? 0),
            (int)($_POST['total']          ?? 0),
            trim($_POST['catatan'] ?? ''),
            $_SESSION['user_id'],
        ]);
        $invoiceId = $pdo->lastInsertId();

        // Insert item-item
        $stItem = $pdo->prepare(
            "INSERT INTO invoice_item
             (invoice_id, deskripsi, tarif_id, qty, harga_satuan, subtotal)
             VALUES (?,?,?,?,?,?)"
        );
        foreach ($items as $it) {
            $desc  = trim($it['deskripsi'] ?? '');
            $qty   = (int)($it['qty']          ?? 1);
            $harga = (int)($it['harga_satuan'] ?? 0);
            if (!$desc) continue;
            $stItem->execute([
                $invoiceId, $desc,
                !empty($it['tarif_id']) ? (int)$it['tarif_id'] : null,
                $qty, $harga, $qty * $harga,
            ]);
        }

        $pdo->commit();
        $no = trim($_POST['nomor_invoice']);
        $_SESSION['msg'] = "Invoice $no berhasil dibuat (status: $statusAwal).";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['msg'] = 'ERROR: '.$e->getMessage();
    }
    header('Location: '.BASE_URL.'/pages/invoice.php?tab=daftar'); exit;
}

// ── Terbitkan invoice ────────────────────────────────────────
if ($action === 'terbitkan') {
    $pdo->prepare("UPDATE invoice SET status='diterbitkan' WHERE id=?")
        ->execute([(int)$_POST['id']]);
    $_SESSION['msg'] = 'Invoice diterbitkan.';
    header('Location: '.BASE_URL.'/pages/invoice.php'); exit;
}

// ── Tandai lunas ──────────────────────────────────────────────
if ($action === 'lunas') {
    $invoiceId = (int)$_POST['id'];
    $pdo->prepare("UPDATE invoice SET status='lunas' WHERE id=?")
        ->execute([$invoiceId]);

    $stmtInv = $pdo->prepare("SELECT penerimaan_id FROM invoice WHERE id = ?");
    $stmtInv->execute([$invoiceId]);
    $penerimaanId = (int)$stmtInv->fetchColumn();

    if ($penerimaanId) syncPenerimaanCompletion($pdo, $penerimaanId);

    $deleted = $penerimaanId ? cleanupCompletedClientAccounts($pdo, $penerimaanId) : 0;
    $_SESSION['msg'] = 'Invoice ditandai lunas.';
    if ($deleted > 0) {
        $_SESSION['msg'] .= " $deleted akun client selesai dan otomatis dihapus.";
    }
    header('Location: '.BASE_URL.'/pages/invoice.php'); exit;
}

// ── Batalkan ─────────────────────────────────────────────────
if ($action === 'batalkan') {
    $pdo->prepare("UPDATE invoice SET status='dibatalkan' WHERE id=?")
        ->execute([(int)$_POST['id']]);
    $_SESSION['msg'] = 'Invoice dibatalkan.';
    header('Location: '.BASE_URL.'/pages/invoice.php'); exit;
}

// ── Tambah / update tarif ────────────────────────────────────
if ($action === 'tarif') {
    $pdo->prepare(
        "INSERT INTO tarif_pengujian (nama, metode, parameter, harga, satuan)
         VALUES (?,?,?,?,?)"
    )->execute([
        trim($_POST['nama']),
        trim($_POST['metode']    ?? '') ?: null,
        trim($_POST['parameter'] ?? '') ?: null,
        (int)$_POST['harga'],
        $_POST['satuan'] ?? 'per parameter',
    ]);
    $_SESSION['msg'] = 'Tarif berhasil ditambahkan.';
    header('Location: '.BASE_URL.'/pages/invoice.php?tab=tarif'); exit;
}

header('Location: '.BASE_URL.'/pages/invoice.php'); exit;
