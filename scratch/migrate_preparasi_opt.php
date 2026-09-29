<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo->exec("ALTER TABLE penerimaan_sampel ADD COLUMN butuh_preparasi TINYINT(1) DEFAULT 1 AFTER metode_uji");
    echo "Column 'butuh_preparasi' added to 'penerimaan_sampel'.\n";
} catch (Exception $e) {
    echo "Error on penerimaan_sampel: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE work_order ADD COLUMN butuh_preparasi TINYINT(1) DEFAULT 1 AFTER status");
    echo "Column 'butuh_preparasi' added to 'work_order'.\n";
} catch (Exception $e) {
    echo "Error on work_order: " . $e->getMessage() . "\n";
}
?>
