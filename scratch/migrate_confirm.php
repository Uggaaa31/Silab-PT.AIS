<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo->exec("ALTER TABLE penerimaan_sampel ADD COLUMN is_confirmed TINYINT(1) DEFAULT 0 AFTER status");
    echo "Column 'is_confirmed' added to 'penerimaan_sampel'.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
