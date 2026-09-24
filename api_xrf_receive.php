<?php
/**
 * REST API Endpoint: XRF Explorer 7000 Connection Test & Ingestion
 * Path: /api/api_xrf_receive.php
 */

header('Content-Type: application/json; charset=utf-8');

// Enable error reporting for debug
error_reporting(E_ALL);
ini_set('display_errors', 0);

// =================================================================
// ⚙️ CONFIGURATION & MODE
// =================================================================
// Set CONNECTION_TEST_ONLY to true: Only checks connection, does NOT save to MySQL DB.
// Set CONNECTION_TEST_ONLY to false: Saves incoming data to MySQL DB.
define('CONNECTION_TEST_ONLY', false);

// Secret API Key (must match the API Key configured in Android App)
define('XRF_SECRET_KEY', getenv('XRF_SECRET_KEY') ?: 'xrf_secret_labmineral_2026');

// File path to store recent connection logs for dashboard display
define('LOG_FILE_PATH', __DIR__ . '/xrf_connection_log.json');

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$current_time = date('Y-m-d H:i:s');

// 0. Handle HTTP GET request (when user opens URL directly in web browser)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    echo json_encode([
        'status'         => 'online',
        'service'        => 'XRF Explorer 7000 API Receiver',
        'mode'           => CONNECTION_TEST_ONLY ? 'CONNECTION_TEST_ONLY' : 'DATABASE_SAVING_ENABLED',
        'message'        => 'API Receiver is ONLINE and listening for HTTP POST JSON requests from XRF Explorer 7000.',
        'dashboard_url'  => (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : '') . '/labmineral/pages/xrf_dashboard.php'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 1. Get raw HTTP POST JSON payload
$raw_input = file_get_contents('php://input');

if (empty($raw_input)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Empty POST payload received from IP: ' . $client_ip . '. Ensure request method is POST with JSON body.'
    ], JSON_PRETTY_PRINT);
    exit;
}

$data = json_decode($raw_input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid JSON payload: ' . json_last_error_msg()
    ], JSON_PRETTY_PRINT);
    exit;
}

// 2. Security API Key Check
$provided_key = isset($data['api_key']) ? $data['api_key'] : '';
if (!empty(XRF_SECRET_KEY) && $provided_key !== XRF_SECRET_KEY) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid API Key from IP: ' . $client_ip
    ], JSON_PRETTY_PRINT);
    exit;
}

// 3. Extract Payload Metadata
$device_id   = !empty($data['device_id']) ? trim($data['device_id']) : 'XRF04';
$db_source   = $data['db_source'] ?? 'connection_test';
$report_id   = intval($data['report_id'] ?? 0);
$sample_name = trim($data['sample_name'] ?? 'PING_TEST');
$work_curve  = trim($data['work_curve_name'] ?? '-');
$operator    = trim($data['operator'] ?? 'operator');
$elements_cnt= isset($data['elements']) && is_array($data['elements']) ? count($data['elements']) : 0;

// 4. Log Connection Event to JSON File (for live UI display)
$log_entry = [
    'timestamp'    => $current_time,
    'client_ip'    => $client_ip,
    'device_id'    => $device_id,
    'db_source'    => $db_source,
    'report_id'    => $report_id,
    'sample_name'  => $sample_name,
    'work_curve'   => $work_curve,
    'operator'     => $operator,
    'elements_cnt' => $elements_cnt,
    'status'       => 'CONNECTED_OK'
];

$existing_logs = [];
if (file_exists(LOG_FILE_PATH)) {
    $raw_logs = file_get_contents(LOG_FILE_PATH);
    $existing_logs = json_decode($raw_logs, true) ?: [];
}
// Keep last 50 log entries
array_unshift($existing_logs, $log_entry);
$existing_logs = array_slice($existing_logs, 0, 50);
@file_put_contents(LOG_FILE_PATH, json_encode($existing_logs, JSON_PRETTY_PRINT));

// =================================================================
// 🚀 REALTIME DEVICE STATUS UPDATE (Always update last_seen_at)
// =================================================================
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'labmineral';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
} elseif (file_exists(__DIR__ . '/config/db.php')) {
    require_once __DIR__ . '/config/db.php';
}
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $db = $pdo;
    } else {
        $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    // Update last_seen_at for this device
    $upd_dev = $db->prepare("UPDATE xrf_devices SET last_seen_at = NOW() WHERE device_id = ?");
    $upd_dev->execute([$device_id]);
    
    // If no rows affected (device not in DB), you might want to insert it, but for now we assume it exists.
    if ($upd_dev->rowCount() == 0) {
        $ins_dev = $db->prepare("INSERT INTO xrf_devices (device_id, device_name, device_type) VALUES (?, ?, ?)");
        $ins_dev->execute([$device_id, 'Alat XRF - ' . $device_id, 'XRF Explorer']);
    }
} catch (Exception $e) {
    // Ignore DB errors for status update so it doesn't break the flow
}

// =================================================================
// 🔄 MODE 1: CONNECTION TEST ONLY (DB SAVING DISABLED)
// =================================================================
if (CONNECTION_TEST_ONLY) {
    http_response_code(200);
    echo json_encode([
        'status'       => 'success',
        'mode'         => 'connection_test_only',
        'message'      => 'Koneksi Wi-Fi dari XRF Explorer 7000 BERHASIL (Data TIDAK disimpan ke DB)',
        'connection'   => [
            'client_ip'     => $client_ip,
            'device_id'     => $device_id,
            'received_at'   => $current_time,
            'sample_name'   => $sample_name,
            'db_source'     => $db_source,
            'report_id'     => $report_id,
            'elements_cnt'  => $elements_cnt
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// =================================================================
// 💾 MODE 2: DATABASE SAVING ENABLED (MySQL)
// =================================================================
$db_host = 'localhost';
$db_name = 'labmineral';
$db_user = 'root';
$db_pass = '';

if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
} elseif (file_exists(__DIR__ . '/config/db.php')) {
    require_once __DIR__ . '/config/db.php';
}

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $db = $pdo;
    } else {
        $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    $db->beginTransaction();

    $test_date    = !empty($data['test_date']) ? $data['test_date'] : date('Y-m-d H:i:s');
    $timestamp_ms = floatval($data['timestamp_ms'] ?? 0);

    // FIX: Include timestamp_ms in duplicate check to prevent ID reuse collisions!
    // If the XRF deletes a mode, it reuses the report_id. We must treat it as a new scan if the timestamp is different.
    $check_stmt = $db->prepare("SELECT id FROM xrf_measurements WHERE device_id = ? AND db_source = ? AND report_id = ? AND timestamp_ms = ?");
    $check_stmt->execute([$device_id, $db_source, $report_id, $timestamp_ms]);
    $existing = $check_stmt->fetch();
    $test_time    = intval($data['test_time'] ?? 0);
    $tub_voltage  = floatval($data['tub_voltage'] ?? 0);
    $tub_current  = floatval($data['tub_current'] ?? 0);
    $grade        = trim($data['grade'] ?? '');
    $gps          = trim($data['gps'] ?? '(0.0,0.0)');
    $longitude    = floatval($data['longitude'] ?? 0);
    $latitude     = floatval($data['latitude'] ?? 0);
    $altitude     = floatval($data['altitude'] ?? 0);
    $cps          = intval($data['cps'] ?? 0);
    $counts       = intval($data['counts'] ?? 0);
    $temperature  = floatval($data['temperature'] ?? 0);
    $elements     = isset($data['elements']) && is_array($data['elements']) ? $data['elements'] : [];

    if ($existing) {
        $measurement_id = $existing['id'];
        $update_stmt = $db->prepare("UPDATE xrf_measurements SET 
            sample_name = ?, test_date = ?, timestamp_ms = ?, test_time = ?,
            tub_voltage = ?, tub_current = ?, work_curve_name = ?, grade = ?, operator = ?,
            gps = ?, longitude = ?, latitude = ?, altitude = ?, cps = ?, counts = ?, temperature = ?
            WHERE id = ?");
        $update_stmt->execute([
            $sample_name, $test_date, $timestamp_ms, $test_time,
            $tub_voltage, $tub_current, $work_curve, $grade, $operator,
            $gps, $longitude, $latitude, $altitude, $cps, $counts, $temperature,
            $measurement_id
        ]);
        $del_elm = $db->prepare("DELETE FROM xrf_measurement_elements WHERE measurement_id = ?");
        $del_elm->execute([$measurement_id]);
        $action = 'updated';
    } else {
        $ins_stmt = $db->prepare("INSERT INTO xrf_measurements (
            device_id, db_source, report_id, sample_name, test_date, timestamp_ms, test_time,
            tub_voltage, tub_current, work_curve_name, grade, operator, gps, longitude, latitude, altitude,
            cps, counts, temperature
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins_stmt->execute([
            $device_id, $db_source, $report_id, $sample_name, $test_date, $timestamp_ms, $test_time,
            $tub_voltage, $tub_current, $work_curve, $grade, $operator, $gps, $longitude, $latitude, $altitude,
            $cps, $counts, $temperature
        ]);
        $measurement_id = $db->lastInsertId();
        $action = 'inserted';
    }

    if (!empty($elements)) {
        $elm_stmt = $db->prepare("INSERT INTO xrf_measurement_elements (
            measurement_id, element_name, concentration, element_error, unit
        ) VALUES (?, ?, ?, ?, ?)");
        foreach ($elements as $elm) {
            $elm_name = trim($elm['name'] ?? '');
            $elm_con  = floatval($elm['concentration'] ?? 0);
            $elm_err  = floatval($elm['error'] ?? 0);
            $elm_unit = trim($elm['unit'] ?? '%');
            if (!empty($elm_name)) {
                $elm_stmt->execute([$measurement_id, $elm_name, $elm_con, $elm_err, $elm_unit]);
            }
        }
    }

    $db->commit();

    echo json_encode([
        'status'         => 'success',
        'mode'           => 'database_saving_enabled',
        'action'         => $action,
        'measurement_id' => intval($measurement_id),
        'message'        => "XRF measurement successfully saved to database."
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()], JSON_PRETTY_PRINT);
}
