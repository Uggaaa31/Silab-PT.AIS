<?php
require_once __DIR__ . '/../config/db.php';

echo "=== MEMULAI SINKRONISASI DATABASE DARI SERVER (192.168.0.230:8080) KE LOKAL ===\n";

$cookieFile = __DIR__ . '/cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

// 1. Login to 192.168.0.230:8080
$ch = curl_init('http://192.168.0.230:8080/actions/simpan_login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'username' => 'admin',
    'password' => 'password'
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$loginResp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Login ke server: HTTP $code\n";

// 2. Fetch all XRF measurements from server
$ch = curl_init('http://192.168.0.230:8080/actions/get_xrf_measurements.php?limit=200');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$dataResp = curl_exec($ch);
curl_close($ch);

if (file_exists($cookieFile)) unlink($cookieFile);

$res = json_decode($dataResp, true);
if (empty($res) || empty($res['data'])) {
    echo "ERROR: Gagal mengambil data dari server. Response:\n" . substr($dataResp, 0, 500) . "\n";
    exit;
}

$serverData = $res['data'];
$totalServer = count($serverData);
echo "Berhasil mengambil $totalServer data scan dari server!\n\n";

$inserted = 0;
$updated = 0;

$pdo->beginTransaction();

try {
    foreach ($serverData as $item) {
        $deviceId    = !empty($item['device_id']) ? $item['device_id'] : 'XRF04';
        $dbSource    = $item['db_source'] ?? 'alloy.db';
        $reportId    = (int)($item['report_id'] ?? 0);
        $sampleName  = $item['sample_name'] ?? 'Tanpa Nama';
        $sampleSupp  = $item['sample_supplier'] ?? '';
        $testDate    = $item['test_date'] ?? date('Y-m-d H:i:s');
        $timestampMs = floatval($item['timestamp_ms'] ?? 0);
        $workCurve   = $item['work_curve_name'] ?? 'Default';
        $grade       = $item['grade'] ?? '';
        $operator    = $item['operator'] ?? 'administr';
        $elements    = $item['elements'] ?? [];

        // Check if measurement exists
        $checkStmt = $pdo->prepare("SELECT id FROM xrf_measurements WHERE (device_id = ? AND db_source = ? AND report_id = ?) OR (timestamp_ms = ? AND sample_name = ?)");
        $checkStmt->execute([$deviceId, $dbSource, $reportId, $timestampMs, $sampleName]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $mId = $existing['id'];
            // Update device_id and metadata
            $upd = $pdo->prepare("UPDATE xrf_measurements SET device_id = ?, sample_name = ?, test_date = ?, timestamp_ms = ?, work_curve_name = ?, grade = ?, operator = ? WHERE id = ?");
            $upd->execute([$deviceId, $sampleName, $testDate, $timestampMs, $workCurve, $grade, $operator, $mId]);
            $updated++;
        } else {
            // Insert new measurement
            $ins = $pdo->prepare("INSERT INTO xrf_measurements (
                device_id, db_source, report_id, sample_name, sample_supplier, test_date, timestamp_ms,
                test_time, tub_voltage, tub_current, work_curve_name, grade, operator, cps, counts, temperature, received_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 30, 8.1, 161.8, ?, ?, ?, 60000, 1800000, -34.7, ?)");
            $ins->execute([
                $deviceId, $dbSource, $reportId, $sampleName, $sampleSupp, $testDate, $timestampMs,
                $workCurve, $grade, $operator, $testDate
            ]);
            $mId = $pdo->lastInsertId();

            // Insert elements
            if (!empty($elements)) {
                $insEl = $pdo->prepare("INSERT INTO xrf_measurement_elements (measurement_id, element_name, concentration, element_error, unit) VALUES (?, ?, ?, ?, ?)");
                foreach ($elements as $el) {
                    $insEl->execute([
                        $mId,
                        $el['element_name'] ?? $el['name'] ?? '',
                        floatval($el['concentration'] ?? 0),
                        floatval($el['error'] ?? $el['element_error'] ?? 0),
                        $el['unit'] ?? '%'
                    ]);
                }
            }
            $inserted++;
        }
    }

    $pdo->commit();
    echo "=== SINKRONISASI BERHASIL! ===\n";
    echo "Data baru dimasukkan (Inserted): $inserted\n";
    echo "Data diperbarui (Updated): $updated\n";
    
    $totalNow = (int)$pdo->query("SELECT COUNT(*) FROM xrf_measurements")->fetchColumn();
    echo "Total data di database lokal sekarang: $totalNow data\n";

    echo "\n=== 5 DATA TERBARU DI DATABASE LOKAL ===\n";
    $latest = $pdo->query("SELECT id, device_id, db_source, sample_name, test_date, work_curve_name FROM xrf_measurements ORDER BY timestamp_ms DESC, id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($latest);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
