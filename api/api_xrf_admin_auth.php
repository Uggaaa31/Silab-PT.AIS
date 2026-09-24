<?php
/**
 * REST API Endpoint: XRF Explorer 7000 Admin Authentication
 * Path: /api/api_xrf_admin_auth.php
 *
 * Dedicated security endpoint to verify Admin / Supervisor credentials
 * for unlocking and accessing the XRF Sync application.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Enable error logging, suppress direct display to ensure clean JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$current_time = date('Y-m-d H:i:s');

// 0. GET Request Handler (Browser Documentation / Health Check)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    echo json_encode([
        'status'         => 'online',
        'service'        => 'XRF Admin Authentication API',
        'allowed_roles'  => ['admin', 'supervisor'],
        'message'        => 'Endpoint is ONLINE. Send POST request with { username, password, device_id } to authenticate.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 1. Parse Input Payload (JSON or Form POST)
$raw_input = file_get_contents('php://input');
$data = [];

if (!empty($raw_input)) {
    $data = json_decode($raw_input, true) ?: [];
}
if (empty($data)) {
    $data = $_POST;
}

$username  = trim($data['username'] ?? '');
$password  = trim($data['password'] ?? '');
$device_id = trim($data['device_id'] ?? 'XRF-7000');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Username dan Password wajib diisi!'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Connect Database
$dbConfigPath = dirname(__DIR__) . '/config/db.php';
if (!file_exists($dbConfigPath)) {
    $dbConfigPath = __DIR__ . '/config/db.php';
}

if (!file_exists($dbConfigPath)) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Konfigurasi database server tidak ditemukan.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $dbConfigPath;

/** @var PDO $pdo */
if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Koneksi database MySQL gagal.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Ensure Audit Log Table Exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS xrf_admin_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pengguna_id INT NULL,
            username VARCHAR(100) NOT NULL,
            role VARCHAR(50) NULL,
            device_id VARCHAR(100) NULL,
            ip_address VARCHAR(50) NULL,
            status ENUM('SUCCESS', 'FAILED_PASSWORD', 'FAILED_ROLE', 'FAILED_USER') NOT NULL,
            message VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $ignored) {}

// 3. Query User from `pengguna`
try {
    $stmt = $pdo->prepare("SELECT id, nama, username, password, role, status FROM pengguna WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Log user not found
        logAdminAttempt($pdo, null, $username, null, $device_id, $client_ip, 'FAILED_USER', 'Username tidak terdaftar di sistem');
        http_response_code(401);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Username atau Password salah!'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verify Password
    $isPasswordMatch = password_verify($password, $user['password']);
    
    // Fallback for plain text password if legacy/initial seed
    if (!$isPasswordMatch && $user['password'] === $password) {
        $isPasswordMatch = true;
    }

    if (!$isPasswordMatch) {
        logAdminAttempt($pdo, $user['id'], $username, $user['role'], $device_id, $client_ip, 'FAILED_PASSWORD', 'Password salah');
        http_response_code(401);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Username atau Password salah!'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Check Active Status
    if (isset($user['status']) && strtolower($user['status']) !== 'aktif') {
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Akun ini sedang dinonaktifkan oleh Administrator.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Role Authorization: Only 'admin' and 'supervisor' are allowed
    $role = strtolower($user['role'] ?? '');
    $allowedRoles = ['admin', 'supervisor', 'superadmin'];

    if (!in_array($role, $allowedRoles, true)) {
        logAdminAttempt($pdo, $user['id'], $username, $user['role'], $device_id, $client_ip, 'FAILED_ROLE', 'Akses ditolak: role ' . $role . ' bukan admin');
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Akses Ditolak! Hanya Admin atau Supervisor yang diizinkan membuka aplikasi XRF.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 5. Generate Secure Access Token
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + (24 * 3600); // 24 hours validity

    logAdminAttempt($pdo, $user['id'], $username, $user['role'], $device_id, $client_ip, 'SUCCESS', 'Login Admin Berhasil');

    http_response_code(200);
    echo json_encode([
        'status'     => 'success',
        'message'    => 'Autentikasi Admin Berhasil',
        'token'      => $token,
        'expires_at' => $expiresAt,
        'user'       => [
            'id'       => (int)$user['id'],
            'username' => $user['username'],
            'nama'     => $user['nama'],
            'role'     => $user['role']
        ],
        'server_time' => $current_time
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function logAdminAttempt($pdo, $penggunaId, $username, $role, $deviceId, $ip, $status, $message) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO xrf_admin_logs (pengguna_id, username, role, device_id, ip_address, status, message)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$penggunaId, $username, $role, $deviceId, $ip, $status, $message]);
    } catch (Exception $ignored) {}
}
