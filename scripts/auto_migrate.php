<?php
// scripts/auto_migrate.php - Otomatis sinkronisasi skema & impor data XRF saat container start

echo "[Auto Migrate] Memeriksa koneksi database...\n";

$host   = getenv('DB_HOST') ?: 'db';
$port   = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'labmineral';
$user   = getenv('DB_USER') ?: 'labuser';
$pass   = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'labmineral_secure_pass2026';

$maxTries = 30;
$pdo = null;

for ($i = 0; $i < $maxTries; $i++) {
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        break;
    } catch (PDOException $e) {
        echo "[Auto Migrate] Menunggu database ({$host}:{$port})... (" . ($i + 1) . "/{$maxTries}): " . $e->getMessage() . "\n";
        sleep(2);
    }
}

if (!$pdo) {
    echo "[Auto Migrate] Gagal terhubung ke database setelah {$maxTries} percobaan. Melanjutkan startup web...\n";
    exit(0);
}

echo "[Auto Migrate] Database berhasil terhubung!\n";

// 1. Skema fix untuk work_order & preparasi_sampel
try {
    $col = $pdo->query("SHOW COLUMNS FROM work_order LIKE 'butuh_preparasi'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE work_order ADD COLUMN butuh_preparasi TINYINT(1) DEFAULT 0 AFTER catatan");
        echo "[Auto Migrate] Berhasil menambahkan kolom butuh_preparasi ke tabel work_order.\n";
    }
} catch (Exception $e) {
    echo "[Auto Migrate] Info work_order: " . $e->getMessage() . "\n";
}

try {
    $col = $pdo->query("SHOW COLUMNS FROM preparasi_sampel LIKE 'metode_preparasi'")->fetch();
    if ($col && strpos(strtolower($col['Type']), 'enum') !== false) {
        $pdo->exec("ALTER TABLE preparasi_sampel MODIFY COLUMN metode_preparasi VARCHAR(100) NOT NULL DEFAULT 'destruksi_asam'");
        echo "[Auto Migrate] Berhasil mengubah metode_preparasi menjadi VARCHAR(100).\n";
    }
} catch (Exception $e) {
    echo "[Auto Migrate] Info preparasi_sampel: " . $e->getMessage() . "\n";
}

// 2. Periksa apakah tabel xrf_measurements sudah berisi data
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'xrf_measurements'")->fetch();
    $xrfCount = 0;
    if ($checkTable) {
        $xrfCount = (int)$pdo->query("SELECT COUNT(*) FROM xrf_measurements")->fetchColumn();
    }

    $dumpFile = __DIR__ . '/sql/xrf_data_dump.sql';
    if ($xrfCount === 0 && file_exists($dumpFile)) {
        echo "[Auto Migrate] Tabel xrf_measurements kosong. Mengimpor xrf_data_dump.sql (2081 measurements)...\n";
        
        $cmd = sprintf(
            'mysql -h %s -P %s -u %s %s %s < %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            $pass !== '' ? '-p' . escapeshellarg($pass) : '',
            escapeshellarg($dbname),
            escapeshellarg($dumpFile)
        );
        exec($cmd, $output, $ret);
        
        if ($ret === 0) {
            echo "[Auto Migrate] Berhasil mengimpor data XRF via MySQL client!\n";
        } else {
            echo "[Auto Migrate] MySQL CLI error: " . implode(" ", $output) . ". Mencoba fallback via PDO multi-query...\n";
            $sqlContent = file_get_contents($dumpFile);
            $pdo->exec($sqlContent);
            echo "[Auto Migrate] Berhasil mengimpor data XRF via PDO!\n";
        }
    } else {
        echo "[Auto Migrate] Data XRF sudah ada ({$xrfCount} measurements). Tidak perlu impor ulang.\n";
    }
} catch (Exception $e) {
    echo "[Auto Migrate] Info data XRF: " . $e->getMessage() . "\n";
}

echo "[Auto Migrate] Proses migrasi otomatis selesai.\n";
