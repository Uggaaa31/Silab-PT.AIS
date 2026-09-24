<?php
header('Content-Type: application/json; charset=utf-8');

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'labmineral';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

if (file_exists(__DIR__ . '/config/db.php')) {
    require_once __DIR__ . '/config/db.php';
} else {
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'DB Connection failed']);
        exit;
    }
}

try {
    $stmt = $pdo->query("SELECT * FROM xrf_devices ORDER BY last_seen_at DESC");
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_measurements = $pdo->query("SELECT COUNT(*) FROM xrf_measurements")->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'devices' => $devices,
        'total_measurements' => $total_measurements,
        'server_time' => time()
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
