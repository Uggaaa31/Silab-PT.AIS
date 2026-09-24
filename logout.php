<?php
// ============================================================
//  logout.php
// ============================================================
session_start();
require_once __DIR__ . '/config/db.php';

if (!empty($_SESSION['user_id'])) {
    try {
        $pdo->prepare(
            "INSERT INTO log_aktivitas (pengguna_id, aksi, modul) VALUES (?, 'Logout', 'auth')"
        )->execute([$_SESSION['user_id']]);
    } catch (Exception $e) {
        // Abaikan jika tabel tidak ada
    }
}

session_unset();
session_destroy();
header('Location: ' . BASE_URL . '/index.php');
exit;
