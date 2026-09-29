<?php
require 'config/db.php';
$stmt = $pdo->query('SELECT role, username, nama FROM pengguna');
echo "ROLE | USERNAME | NAMA\n";
echo "-----------------------\n";
while($row = $stmt->fetch()) {
    echo $row['role'] . " | " . $row['username'] . " | " . $row['nama'] . "\n";
}
