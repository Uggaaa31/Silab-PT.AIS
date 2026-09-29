<?php
require_once __DIR__ . '/../config/db.php';
$stmt = $pdo->query("SELECT username, role, password FROM pengguna");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $p = $row['role'] . '123';
    if (password_verify($p, $row['password'])) {
        echo $row['username'] . " (" . $row['role'] . ") -> " . $p . "\n";
    } else if (password_verify('admin123', $row['password'])) {
        echo $row['username'] . " (" . $row['role'] . ") -> admin123\n";
    } else if (password_verify('client123', $row['password'])) {
        echo $row['username'] . " (" . $row['role'] . ") -> client123\n";
    } else {
        echo $row['username'] . " (" . $row['role'] . ") -> unknown\n";
    }
}
