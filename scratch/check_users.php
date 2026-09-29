<?php
require_once __DIR__ . '/../config/db.php';
$stmt = $pdo->query("SELECT id, username, nama, role, status, password FROM pengguna");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($users, JSON_PRETTY_PRINT);
?>
