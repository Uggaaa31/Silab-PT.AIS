<?php
// ============================================================
//  actions/simpan_login.php
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    $_SESSION['msg'] = 'ERROR: Username dan password harus diisi.';
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM pengguna WHERE username = ? AND status = 'aktif'");
$stmt->execute([$username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    // Simpan data user ke session - GUNAKAN KEDUA FORMAT untuk kompatibilitas
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['nama'] = $user['nama'];
    $_SESSION['user_nama'] = $user['nama'];
    $_SESSION['name'] = $user['nama'];  // Tambahkan ini untuk kompatibilitas
    $_SESSION['user_role'] = $user['role'];  // Format baru dengan underscore
    $_SESSION['role'] = $user['role'];       // Format lama tanpa underscore (untuk kompatibilitas)
    $_SESSION['user_username'] = $user['username'];
    
    // Log aktivitas login
    try {
        $logStmt = $pdo->prepare("INSERT INTO log_aktivitas (pengguna_id, aksi, modul) VALUES (?, ?, ?)");
        $logStmt->execute([$user['id'], 'Login ke sistem', 'auth']);
    } catch (Exception $e) {
        // Abaikan jika tabel tidak ada
    }
    
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
} else {
    $_SESSION['msg'] = 'ERROR: Username atau password salah.';
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
