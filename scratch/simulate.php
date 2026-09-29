<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo->beginTransaction();

    echo "Memulai simulasi Work Order...\n";

    // 1. Dapatkan pengguna terkait
    $stmt = $pdo->query("SELECT id FROM pengguna WHERE role='admin' LIMIT 1");
    $adminId = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT id FROM pengguna WHERE role='analis' LIMIT 1");
    $analisId = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT id FROM pengguna WHERE role='supervisor' LIMIT 1");
    $supervisorId = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT id, nama FROM pengguna WHERE role='client' LIMIT 1");
    $klienInfo = $stmt->fetch();
    $klienId = $klienInfo['id'];
    $klienName = $klienInfo['nama'];

    if (!$adminId || !$analisId || !$supervisorId || !$klienId) {
        die("Data pengguna (admin/analis/supervisor/client) tidak lengkap.\n");
    }

    echo "1. Ditemukan Admin (ID: $adminId), Analis (ID: $analisId), Supervisor (ID: $supervisorId), Klien: $klienName\n";

    // 2. Buat Penerimaan Sampel
    $lastRec = $pdo->query("SELECT nomor_penerimaan FROM penerimaan_sampel ORDER BY id DESC LIMIT 1")->fetchColumn();
    $nextNum = $lastRec ? (intval(substr($lastRec, -3)) + 1) : 1;
    $noPenerimaan = 'REC-SIM-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO penerimaan_sampel 
        (nomor_penerimaan, klien, tanggal_terima, jumlah_sampel, jenis_material, metode_uji, status, dibuat_oleh)
        VALUES (?, ?, ?, ?, ?, ?, 'diproses', ?)
    ");
    $stmt->execute([
        $noPenerimaan, $klienName, date('Y-m-d'), 1, 'Bijih Nikel', 'XRF', $adminId
    ]);
    $penerimaanId = $pdo->lastInsertId();
    echo "2. Penerimaan dibuat: $noPenerimaan (ID: $penerimaanId)\n";

    // 3. Buat Sampel
    $lastKode = $pdo->query("SELECT kode_sampel FROM sampel ORDER BY id DESC LIMIT 1")->fetchColumn();
    $nextKodeNum = $lastKode ? (intval(substr($lastKode, -3)) + 1) : 1;
    $kodeSampel = 'S-SIM-' . str_pad($nextKodeNum, 3, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO sampel 
        (penerimaan_id, kode_sampel, tanggal_masuk, jenis_material, berat_gram, klien, metode_uji, status, dibuat_oleh)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'diuji', ?)
    ");
    $stmt->execute([
        $penerimaanId, $kodeSampel, date('Y-m-d'), 'Bijih Nikel', 100.5, $klienName, 'XRF', $adminId
    ]);
    $sampelId = $pdo->lastInsertId();
    echo "3. Sampel dibuat: $kodeSampel (ID: $sampelId)\n";

    // 4. Buat Work Order
    $lastWo = $pdo->query("SELECT nomor_wo FROM work_order ORDER BY id DESC LIMIT 1")->fetchColumn();
    $nextWo = $lastWo ? (intval(substr($lastWo, -3)) + 1) : 1;
    $noWo = 'WO-SIM-' . str_pad($nextWo, 3, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO work_order 
        (nomor_wo, penerimaan_id, analis_id, jadwal_mulai, jadwal_selesai, prioritas, status)
        VALUES (?, ?, ?, ?, ?, 'normal', 'aktif')
    ");
    $stmt->execute([
        $noWo, $penerimaanId, $analisId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+2 days'))
    ]);
    $woId = $pdo->lastInsertId();
    echo "4. Work Order dibuat: $noWo (ID: $woId)\n";

    // 5. Hubungkan Sampel dengan WO (Pivot table)
    $stmt = $pdo->prepare("INSERT INTO work_order_sampel (wo_id, sampel_id) VALUES (?, ?)");
    $stmt->execute([$woId, $sampelId]);
    echo "5. Sampel dihubungkan ke Work Order\n";

    // 6. Preparasi
    $stmt = $pdo->prepare("
        INSERT INTO preparasi_sampel 
        (work_order_id, sampel_id, analis_id, metode_preparasi, tanggal_preparasi, faktor_pengenceran, standar_disiapkan)
        VALUES (?, ?, ?, 'destruksi_asam', ?, 1.0, 1)
    ");
    $stmt->execute([
        $woId, $sampelId, $analisId, date('Y-m-d')
    ]);
    $preparasiId = $pdo->lastInsertId();
    echo "6. Preparasi sampel selesai (ID: $preparasiId)\n";

    // 7. Pengujian (Hasil Uji)
    $lastUji = $pdo->query("SELECT kode_uji FROM hasil_uji ORDER BY id DESC LIMIT 1")->fetchColumn();
    $nextUji = $lastUji ? (intval(substr($lastUji, 2)) + 1) : 1;
    $kodeUji = 'U-' . str_pad($nextUji, 3, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO hasil_uji 
        (kode_uji, sampel_id, parameter, nilai, satuan, analis_id, tanggal_uji, kesimpulan)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'lulus')
    ");
    $stmt->execute([
        $kodeUji, $sampelId, 'Ni', '1.85', '%', $analisId, date('Y-m-d')
    ]);
    $hasilUjiId = $pdo->lastInsertId();
    echo "7. Pengujian selesai, Hasil: 1.85% Ni (ID: $hasilUjiId)\n";

    // 8. Manajemen Sampel QC (Standar CRM)
    $stmt = $pdo->prepare("INSERT INTO sampel (kode_sampel, tanggal_masuk, jenis_material, status, dibuat_oleh) VALUES (?, ?, ?, 'antrian', ?)");
    $stmt->execute(['STD-SIM-001', date('Y-m-d'), 'CRM Nikel', $adminId]);
    $stdSampelId = $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO qc_sampel 
        (sampel_id, tipe_qc, parameter, nilai_expected, batas_min_pct, batas_maks_pct, flag, status_qc, tanggal_uji)
        VALUES (?, 'standar', 'Ni', 1.82, 85, 115, 'pass', 'pending', ?)
    ");
    $stmt->execute([$stdSampelId, date('Y-m-d')]);
    $stdQcId = $pdo->lastInsertId();
    echo "8. Sampel Standar (CRM) dibuat via Manajemen QC: STD-SIM-001 (ID: $stdQcId)\n";

    // 9. Input Data QC untuk Standar tersebut
    $stmt = $pdo->prepare("
        UPDATE qc_sampel 
        SET nilai_qc = 1.83, flag = 'pass' 
        WHERE id = ?
    ");
    $stmt->execute([$stdQcId]);
    echo "9. Data nilai terukur QC Standar diinput: 1.83 (Pass)\n";

    // 10. Input Data QC (Duplikat) untuk S-SIM-112
    $stmt = $pdo->prepare("
        INSERT INTO qc_sampel 
        (sampel_id, preparasi_id, tipe_qc, parameter, nilai_qc, nilai_expected, flag, status_qc, tanggal_uji)
        VALUES (?, ?, 'duplikat', 'Ni', 1.84, 1.85, 'pass', 'pending', ?)
    ");
    $stmt->execute([$sampelId, $preparasiId, date('Y-m-d')]);
    $dupQcId = $pdo->lastInsertId();
    echo "10. Data QC Duplikat ditambahkan untuk S-SIM-112\n";

    // 11. Supervisor Review QC
    $stmt = $pdo->prepare("
        UPDATE qc_sampel 
        SET status_qc = 'disetujui', reviewer_id = ?, catatan_review = 'Simulasi review OK'
        WHERE id IN (?, ?)
    ");
    $stmt->execute([$supervisorId, $stdQcId, $dupQcId]);
    echo "11. Supervisor menyetujui hasil QC (Standar & Duplikat)\n";

    // 12. Tandai WO Selesai
    $stmt = $pdo->prepare("UPDATE work_order SET status = 'selesai' WHERE id = ?");
    $stmt->execute([$woId]);
    echo "10. Work Order ditandai selesai\n";

    // Update status penerimaan dan sampel
    $pdo->prepare("UPDATE sampel SET status = 'selesai' WHERE id = ?")->execute([$sampelId]);
    $pdo->prepare("UPDATE penerimaan_sampel SET status = 'selesai' WHERE id = ?")->execute([$penerimaanId]);

    $pdo->commit();
    echo "\n>>> SIMULASI WORK ORDER SELESAI DENGAN SUKSES! <<<\n";
    echo "Buka aplikasi dan lihat data untuk WO: $noWo, Klien: $klienName\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "TERJADI KESALAHAN: " . $e->getMessage() . "\n";
}
