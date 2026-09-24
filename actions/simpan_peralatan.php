<?php
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$action = $_POST['action'] ?? 'tambah';

if ($action === 'update_status') {
    $pdo->prepare("UPDATE peralatan SET status = ? WHERE id = ?")
        ->execute([$_POST['status'], (int)$_POST['id']]);
    $_SESSION['msg'] = 'Status peralatan berhasil diperbarui.';
} else {
    $pdo->prepare(
        "INSERT INTO peralatan
         (kode_alat, nama, lokasi, status, tanggal_kalibrasi, masa_berlaku_kalibrasi, jadwal_maintenance, pic, catatan)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        trim($_POST['kode_alat']),
        trim($_POST['nama']),
        trim($_POST['lokasi'] ?? ''),
        $_POST['status'],
        $_POST['tanggal_kalibrasi']       ?: null,
        $_POST['masa_berlaku_kalibrasi']  ?: null,
        $_POST['jadwal_maintenance']      ?: null,
        trim($_POST['pic'] ?? ''),
        trim($_POST['catatan'] ?? ''),
    ]);
    $_SESSION['msg'] = 'Peralatan baru berhasil ditambahkan.';
}

header('Location: ' . BASE_URL . '/pages/peralatan.php');
exit;
