<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo->exec("DROP TABLE IF EXISTS sampel_qc");
    echo "Table sampel_qc dropped successfully.";
} catch (Exception $e) {
    echo "Error dropping table: " . $e->getMessage();
}
