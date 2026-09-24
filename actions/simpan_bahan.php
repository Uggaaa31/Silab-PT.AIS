<?php
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$kode = trim($_POST['kode_bahan']);

// Cek apakah kode sudah ada → update stok, jika tidak → insert baru
$stmt = $pdo->prepare("SELECT id, stok FROM bahan WHERE kode_bahan = ?");
$stmt->execute([$kode]);
$existing = $stmt->fetch();

if ($existing) {
    $newStok = $existing['stok'] + (float)$_POST['stok'];
    $pdo->prepare(
        "UPDATE bahan SET stok = ?, supplier = ?, tanggal_kadaluarsa = ? WHERE kode_bahan = ?"
    )->execute([
        $newStok,
        trim($_POST['supplier'] ?? ''),
        $_POST['tanggal_kadaluarsa'] ?: null,
        $kode,
    ]);
    // Catat log
    $pdo->prepare(
        "INSERT INTO log_bahan (bahan_id, jenis, jumlah, keterangan, pengguna_id) VALUES (?, 'masuk', ?, 'Penambahan stok', ?)"
    )->execute([$existing['id'], (float)$_POST['stok'], $_SESSION['user_id']]);

    $_SESSION['msg'] = "Stok $kode berhasil diperbarui (total: $newStok).";
} else {
    $pdo->prepare(
        "INSERT INTO bahan (kode_bahan, nama, stok, satuan, stok_minimum, supplier, tanggal_kadaluarsa)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $kode,
        trim($_POST['nama']),
        (float)$_POST['stok'],
        $_POST['satuan'],
        (float)($_POST['stok_minimum'] ?? 0),
        trim($_POST['supplier'] ?? ''),
        $_POST['tanggal_kadaluarsa'] ?: null,
    ]);
    $_SESSION['msg'] = "Bahan $kode berhasil ditambahkan.";
}

header('Location: ' . BASE_URL . '/pages/bahan.php');
exit;
