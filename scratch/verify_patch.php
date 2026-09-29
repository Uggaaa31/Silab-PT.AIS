<?php
require_once 'config/db.php';

echo "--- Verifikasi Database ---\n";

// 1. Cek tabel
$tables = ['submission_sampel', 'submission_sampel_detail', 'client_access'];
foreach ($tables as $t) {
    $exists = tableExists($pdo, $t);
    echo "Table $t: " . ($exists ? "EXISTS" : "MISSING") . "\n";
}

// 2. Cek Role 'client'
try {
    $stmt = $pdo->query("DESCRIBE pengguna role");
    $row = $stmt->fetch();
    echo "Pengguna Role Definition: " . $row['Type'] . "\n";
} catch (Exception $e) {
    echo "Error checking role: " . $e->getMessage() . "\n";
}

// 3. Cek apakah ada data klien yang dimigrasi
$count = $pdo->query("SELECT COUNT(*) FROM pengguna WHERE role = 'client'")->fetchColumn();
echo "Total users with 'client' role: $count\n";
