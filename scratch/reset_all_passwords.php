<?php
require_once __DIR__ . '/../config/db.php';
$stmt = $pdo->query("SELECT id, username, role FROM pengguna");
echo "Daftar User dan Password:\n\n";
echo str_pad("USERNAME", 25) . " | " . str_pad("ROLE", 12) . " | PASSWORD\n";
echo str_repeat("-", 60) . "\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $role = $row['role'];
    $username = $row['username'];
    $defaultPass = '';
    if ($role === 'admin') {
        $defaultPass = 'admin123';
    } else if ($role === 'client' || $role === 'klien') {
        $defaultPass = 'client123';
    } else if ($role === 'analis') {
        $defaultPass = 'analis123';
    } else if ($role === 'supervisor') {
        $defaultPass = 'supervisor123';
    } else {
        $defaultPass = 'password123';
    }
    
    // Update password
    $hash = password_hash($defaultPass, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE pengguna SET password = ? WHERE id = ?")->execute([$hash, $row['id']]);
    
    echo str_pad($username, 25) . " | " . str_pad($role, 12) . " | " . $defaultPass . "\n";
}
