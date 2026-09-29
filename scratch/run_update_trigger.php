<?php
require_once __DIR__ . '/../config/db.php';

$sql = "
DROP TRIGGER IF EXISTS trg_qc_recovery_insert;
DROP TRIGGER IF EXISTS trg_qc_recovery_update;
";

$pdo->exec($sql);

$sqlInsert = "
CREATE TRIGGER trg_qc_recovery_insert
BEFORE INSERT ON qc_sampel FOR EACH ROW
BEGIN
    IF NEW.nilai_expected IS NOT NULL AND NEW.nilai_expected > 0 THEN
        SET NEW.persen_recovery = (NEW.nilai_qc / NEW.nilai_expected) * 100;
        IF ABS(NEW.nilai_qc - NEW.nilai_expected) <= 0.05 THEN
            SET NEW.flag = 'pass';
        ELSE
            SET NEW.flag = 'fail';
        END IF;
    END IF;
END;
";

$pdo->exec($sqlInsert);

$sqlUpdate = "
CREATE TRIGGER trg_qc_recovery_update
BEFORE UPDATE ON qc_sampel FOR EACH ROW
BEGIN
    IF NEW.nilai_expected IS NOT NULL AND NEW.nilai_expected > 0 THEN
        SET NEW.persen_recovery = (NEW.nilai_qc / NEW.nilai_expected) * 100;
        IF ABS(NEW.nilai_qc - NEW.nilai_expected) <= 0.05 THEN
            SET NEW.flag = 'pass';
        ELSE
            SET NEW.flag = 'fail';
        END IF;
    END IF;
END;
";

$pdo->exec($sqlUpdate);

echo "Triggers updated successfully!\n";
