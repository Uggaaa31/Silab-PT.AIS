<?php
require_once __DIR__ . '/../config/db.php';

$devices = [
    ['id' => 'XRF01', 'name' => 'Spektrometer XRF 01', 'type' => 'XRF Explorer', 'location' => 'Lab Utama'],
    ['id' => 'XRF02', 'name' => 'Spektrometer XRF 02', 'type' => 'XRF Explorer', 'location' => 'Lab Mineral'],
    ['id' => 'XRF03', 'name' => 'Spektrometer XRF 03', 'type' => 'XRF Explorer', 'location' => 'Lab Preparasi'],
    ['id' => 'XRF04', 'name' => 'Spektrometer XRF 04', 'type' => 'XRF Explorer', 'location' => 'Lab Mobile'],
];

foreach ($devices as $d) {
    $stmt = $pdo->prepare("
        INSERT INTO xrf_devices (device_id, device_name, device_type, location, is_active, last_seen_at)
        VALUES (?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE 
            device_name = VALUES(device_name),
            device_type = VALUES(device_type),
            location = VALUES(location),
            is_active = 1
    ");
    $stmt->execute([$d['id'], $d['name'], $d['type'], $d['location']]);
}

echo "=== DAFTAR PERANGKAT XRF TERDAFTAR ===\n";
$rows = $pdo->query("SELECT id, device_id, device_name, device_type, location, is_active, last_seen_at FROM xrf_devices")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
