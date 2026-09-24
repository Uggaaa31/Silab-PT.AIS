<?php
// ============================================================
//  actions/simpan_metode_preparasi.php
//  CRUD Metode Preparasi untuk Admin
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';

/** @var PDO $pdo */

if (!isAdmin()) {
    $_SESSION['msg'] = 'ERROR: Anda tidak memiliki izin.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

ensureMetodePreparasiTable($pdo);

$action = $_POST['action'] ?? 'tambah';
$id = (int)($_POST['id'] ?? 0);
$metode = trim($_POST['metode'] ?? '');

if ($action === 'tambah' || $action === 'update') {
    if ($metode === '') {
        $_SESSION['msg'] = 'ERROR: Kolom Metode Preparasi tidak boleh kosong.';
        header('Location: ' . BASE_URL . '/pages/metode_preparasi.php' . ($action === 'update' && $id ? '?edit_id=' . $id : ''));
        exit;
    }

    $cek = $pdo->prepare('SELECT id FROM metode_preparasi WHERE LOWER(metode) = LOWER(?)' . ($action === 'update' ? ' AND id <> ?' : ''));
    $params = [$metode];
    if ($action === 'update') {
        $params[] = $id;
    }
    $cek->execute($params);
    if ($cek->fetch()) {
        $_SESSION['msg'] = 'ERROR: Metode preparasi sudah terdaftar.';
        header('Location: ' . BASE_URL . '/pages/metode_preparasi.php' . ($action === 'update' && $id ? '?edit_id=' . $id : '')); 
        exit;
    }

    if ($action === 'tambah') {
        $stmt = $pdo->prepare('INSERT INTO metode_preparasi (metode) VALUES (?)');
        $stmt->execute([$metode]);
        $_SESSION['msg'] = 'Metode preparasi berhasil ditambahkan.';
    } else {
        $stmt = $pdo->prepare('UPDATE metode_preparasi SET metode = ? WHERE id = ?');
        $stmt->execute([$metode, $id]);
        $_SESSION['msg'] = 'Metode preparasi berhasil diperbarui.';
    }
}

if ($action === 'hapus' && $id > 0) {
    $stmt = $pdo->prepare('DELETE FROM metode_preparasi WHERE id = ?');
    $stmt->execute([$id]);
    $_SESSION['msg'] = 'Metode preparasi berhasil dihapus.';
}

header('Location: ' . BASE_URL . '/pages/metode_preparasi.php');
exit;
