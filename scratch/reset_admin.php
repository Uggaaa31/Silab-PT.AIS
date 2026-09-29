<?php
require_once __DIR__ . '/../config/db.php';
$pass = password_hash('admin123', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE pengguna SET password = ?, status = 'aktif' WHERE username = 'admin'")
    ->execute([$pass]);
echo "Password for 'admin' has been reset to 'admin123'.\n";
?>
