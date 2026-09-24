<?php
// ============================================================
//  actions/simpan_pengguna.php
//  HANYA ADMIN yang dapat mengakses
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';

// Hanya admin yang boleh akses
if (!isAdmin()) {
    $_SESSION['msg'] = 'ERROR: Anda tidak memiliki izin.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

// Validasi password
if ($_POST['password'] !== $_POST['password2']) {
    $_SESSION['msg'] = 'ERROR: Password dan konfirmasi tidak cocok.';
    header('Location: ' . BASE_URL . '/pages/pengguna.php');
    exit;
}

// Cek username duplikat
$cek = $pdo->prepare("SELECT id FROM pengguna WHERE username = ?");
$cek->execute([trim($_POST['username'])]);
if ($cek->fetch()) {
    $_SESSION['msg'] = 'ERROR: Username sudah digunakan.';
    header('Location: ' . BASE_URL . '/pages/pengguna.php');
    exit;
}

$hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
$status = $_POST['status'] ?? 'aktif';

$pdo->prepare(
    "INSERT INTO pengguna (nama, username, password, email, role, status) VALUES (?, ?, ?, ?, ?, ?)"
)->execute([
    trim($_POST['nama']),
    trim($_POST['username']),
    $hash,
    trim($_POST['email'] ?? ''),
    $_POST['role'],
    $status,
]);

$_SESSION['msg'] = 'Pengguna baru berhasil ditambahkan.';
header('Location: ' . BASE_URL . '/pages/pengguna.php');
exit;
