<?php
// ============================================================
//  actions/simpan_sampel.php
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$action   = $_POST['action'] ?? 'tambah';
$redirect = $_POST['redirect'] ?? BASE_URL . '/pages/sampel.php?tab=daftar';

// ── Update status satu sampel ────────────────────────────────
if ($action === 'update_status') {
    $pdo->prepare("UPDATE sampel SET status = ? WHERE id = ?")
        ->execute([$_POST['status'], (int)$_POST['id']]);
    $_SESSION['msg'] = 'Status sampel diperbarui.';

// ── Update status massal ─────────────────────────────────────
} elseif ($action === 'mass_status') {
    $ids    = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
    $status = $_POST['status'] ?? '';
    if ($ids && $status) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$status], $ids);
        $pdo->prepare("UPDATE sampel SET status = ? WHERE id IN ($placeholders)")->execute($params);
        $_SESSION['msg'] = count($ids) . ' sampel berhasil diubah statusnya menjadi ' . ucfirst($status) . '.';
    }

// ── Tambah sampel baru ───────────────────────────────────────
} else {
    $last    = $pdo->query("SELECT kode_sampel FROM sampel ORDER BY id DESC LIMIT 1")->fetchColumn();
    $nextNum = $last ? (intval(substr($last, -3)) + 1) : 1;
    $kode    = 'S-' . date('ym') . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

    $penerimaanId = !empty($_POST['penerimaan_id']) ? (int)$_POST['penerimaan_id'] : null;

    $pdo->prepare(
        "INSERT INTO sampel
         (penerimaan_id, kode_sampel, tanggal_masuk, jenis_material, berat_gram, klien, metode_uji, keterangan, dibuat_oleh)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $penerimaanId,
        $kode,
        $_POST['tanggal_masuk'],
        $_POST['jenis_material'],
        !empty($_POST['berat_gram']) ? (float)$_POST['berat_gram'] : null,
        trim($_POST['klien'] ?? ''),
        $_POST['metode_uji'],
        trim($_POST['keterangan'] ?? ''),
        $_SESSION['user_id'],
    ]);

    // Update jumlah_sampel di batch jika ditautkan
    if ($penerimaanId) {
        $pdo->prepare(
            "UPDATE penerimaan_sampel SET jumlah_sampel =
             (SELECT COUNT(*) FROM sampel WHERE penerimaan_id = ?) WHERE id = ?"
        )->execute([$penerimaanId, $penerimaanId]);
    }

    $_SESSION['msg'] = "Sampel $kode berhasil disimpan.";
    $redirect = BASE_URL . '/pages/sampel.php?tab=daftar';
}

header('Location: ' . $redirect);
exit;
