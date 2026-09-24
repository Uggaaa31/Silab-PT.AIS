<?php
// ============================================================
//  actions/get_hasil_uji.php
//  Mengambil data hasil uji untuk keperluan edit
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT h.*, s.kode_sampel, s.jenis_material
    FROM hasil_uji h
    JOIN sampel s ON h.sampel_id = s.id
    WHERE h.id = ?
");
$stmt->execute([$id]);
$data = $stmt->fetch();

if ($data) {
    echo json_encode([
        'success' => true,
        'id' => $data['id'],
        'kode_uji' => $data['kode_uji'],
        'kode_sampel' => $data['kode_sampel'],
        'jenis_material' => $data['jenis_material'],
        'parameter' => $data['parameter'],
        'nilai' => $data['nilai'],
        'satuan' => $data['satuan'],
        'metode' => $data['metode'],
        'alat_id' => $data['alat_id'],
        'analis_id' => $data['analis_id'],
        'tanggal_uji' => $data['tanggal_uji'],
        'kesimpulan' => $data['kesimpulan'],
        'catatan' => $data['catatan']
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
}
