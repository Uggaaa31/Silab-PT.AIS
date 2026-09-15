<?php
// ============================================================
//  config/db.php
//  UPDATE: Menambahkan fungsi role helper dan konsistensi session
// ============================================================
define('DB_HOST',     getenv('DB_HOST') ?: 'localhost');
define('DB_USER',     getenv('DB_USER') ?: 'root');
define('DB_PASS',     getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME',     getenv('DB_NAME') ?: 'labmineral');
define('APP_NAME',    getenv('APP_NAME') ?: 'LabMineral Pro');
define('APP_VERSION', '2.0.0');

// Turunkan base URL dari folder project agar asset tetap terbaca meski nama folder berubah.
$projectRoot = realpath(dirname(__DIR__));
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$baseUrl = '';

if ($projectRoot && $documentRoot) {
    $projectRoot = str_replace('\\', '/', $projectRoot);
    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

    if (strpos($projectRoot, $documentRoot) === 0) {
        $relativePath = trim(substr($projectRoot, strlen($documentRoot)), '/');
        $baseUrl = $relativePath === '' ? '' : '/' . $relativePath;
    }
}

// Override BASE_URL jika di-set di environment variable
if (getenv('BASE_URL') !== false && getenv('BASE_URL') !== '') {
    $baseUrl = rtrim(getenv('BASE_URL'), '/');
}

define('BASE_URL', $baseUrl);

// ── Koneksi PDO ──────────────────────────────────────────────
/** @var PDO $pdo */
$pdo = null;
try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("<div style='font-family:sans-serif;padding:30px;background:#3d1010;color:#e74c3c;
         border-radius:8px;margin:20px'><h2>&#9888; Koneksi Database Gagal</h2>
         <p>".htmlspecialchars($e->getMessage())."</p></div>");
}

// ── Cek login ────────────────────────────────────────────────
function cekLogin() {
    global $pdo;
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: '.BASE_URL.'/index.php'); 
        exit;
    }

    if ($pdo instanceof PDO) {
        try {
            $stmt = $pdo->prepare("SELECT id, nama, role, status FROM pengguna WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user || $user['status'] !== 'aktif') {
                session_unset();
                session_destroy();
                header('Location: '.BASE_URL.'/index.php');
                exit;
            }

            $_SESSION['nama'] = $user['nama'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_role'] = $user['role'];
        } catch (Exception $e) {
            // Jika struktur tabel belum lengkap saat setup awal, lanjutkan dengan session yang ada.
        }
    }

    $role = normalizeRole($_SESSION['user_role'] ?? $_SESSION['role'] ?? '');
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    $allowedClientScripts = ['dashboard.php', 'client_monitoring.php', 'cetak_invoice.php', 'logout.php'];

    if ($role === 'client' && !in_array($currentScript, $allowedClientScripts, true)) {
        header('Location: '.BASE_URL.'/pages/client_monitoring.php');
        exit;
    }
}

// ── Sanitasi ─────────────────────────────────────────────────
function bersihkan($s) {
    return htmlspecialchars(trim((string)$s), ENT_QUOTES, 'UTF-8');
}

// ============================================================
// ROLE HELPER FUNCTIONS (terintegrasi dengan session)
// ============================================================

function normalizeRole($role) {
    return $role === 'klien' ? 'client' : $role;
}

function currentUserRole() {
    return normalizeRole($_SESSION['user_role'] ?? $_SESSION['role'] ?? null);
}

/**
 * Cek apakah user memiliki role tertentu.
 * Role lama "klien" diperlakukan sebagai alias dari role baru "client".
 */
function hasRole($role) {
    $userRole = currentUserRole();
    if (!$userRole) return false;

    if (is_array($role)) {
        return in_array($userRole, array_map('normalizeRole', (array)$role), true);
    }
    return $userRole === normalizeRole($role);
}

/**
 * Cek apakah user adalah admin
 */
function isAdmin() {
    return hasRole('admin');
}

/**
 * Cek apakah user adalah analis
 */
function isAnalis() {
    return hasRole('analis');
}

/**
 * Cek apakah user adalah supervisor
 */
function isSupervisor() {
    return hasRole('supervisor');
}

/**
 * Cek apakah user adalah client
 */
function isClient() {
    return hasRole('client');
}

/**
 * Tampilkan badge role dengan warna berbeda
 */
function roleBadge($role) {
    $role = normalizeRole($role);
    $colors = [
        'admin' => '#e74c3c',
        'analis' => '#2ecc71',
        'supervisor' => '#f39c12',
        'client' => '#9b59b6',
        'klien' => '#3498db'
    ];
    $color = $colors[$role] ?? '#95a5a6';
    return "<span style='background:$color;color:white;padding:2px 8px;border-radius:12px;font-size:.65rem;display:inline-block;'>" . strtoupper($role) . "</span>";
}

/**
 * Tampilkan badge read only
 */
function readOnlyBadge() {
    return "<span class='readonly-badge' style='background:#f39c12;color:#1a1a2e;padding:4px 12px;border-radius:20px;font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:6px;'>🔒 Mode Read Only</span>";
}

// ============================================================
// AKSES PER MODUL
// ============================================================

/**
 * Penerimaan Sampel
 * - Admin: full akses (CRUD)
 * - Analis: read only
 * - Supervisor: read only
 */
function canAccessPenerimaan() {
    return isAdmin() || isAnalis() || isSupervisor();
}

function canEditPenerimaan() {
    return isAdmin();
}

function canDeletePenerimaan() {
    return isAdmin();
}

/**
 * Pengujian
 * - Semua role: full akses
 */
function canAccessPengujian() {
    return isAdmin() || isAnalis() || isSupervisor();
}

function canEditPengujian() {
    return isAdmin() || isAnalis() || isSupervisor();
}

/**
 * Preparasi
 * - Admin: read only
 * - Analis: full akses
 * - Supervisor: read only
 */
function canAccessPreparasi() {
    return isAdmin() || isAnalis() || isSupervisor();
}

function canEditPreparasi() {
    return isAnalis();
}

function canViewPreparasi() {
    return isAdmin() || isSupervisor();
}

/**
 * QC
 * - Admin: read only
 * - Analis: full akses
 * - Supervisor: read only
 */
function canAccessQC() {
    return isAdmin() || isAnalis() || isSupervisor();
}

function canEditQC() {
    return isAnalis();
}

function canViewQC() {
    return isAdmin() || isSupervisor();
}

/**
 * Invoice
 * - Admin: full akses
 * - Analis: tidak ada akses
 * - Supervisor: read only
 */
function canAccessInvoice() {
    return isAdmin() || isSupervisor();
}

function canEditInvoice() {
    return isAdmin();
}

function canViewInvoice() {
    return isAdmin() || isSupervisor();
}

/**
 * Bahan (Inventaris)
 * - Semua role: full akses
 */
function canAccessBahan() {
    return isAdmin() || isAnalis() || isSupervisor();
}

function canEditBahan() {
    return isAdmin() || isAnalis() || isSupervisor();
}

/**
 * Peralatan
 * - Semua role: full akses
 */
function canAccessPeralatan() {
    return isAdmin() || isAnalis() || isSupervisor();
}

function canEditPeralatan() {
    return isAdmin() || isAnalis() || isSupervisor();
}

/**
 * Laporan
 * - Admin: full akses
 * - Analis: tidak ada akses
 * - Supervisor: full akses
 */
function canAccessLaporan() {
    return isAdmin() || isSupervisor();
}

/**
 * Work Order
 * - Semua role: full akses
 */
function canAccessWorkOrder() {
    return isAdmin() || isAnalis() || isSupervisor();
}

function canEditWorkOrder() {
    return isAdmin() || isAnalis() || isSupervisor();
}

/**
 * Dashboard - semua role bisa akses
 */
function canAccessDashboard() {
    return true;
}

/**
 * Monitoring client
 */
function canAccessClientMonitoring() {
    return isClient();
}

/**
 * Manajemen Pengguna - hanya admin
 */
function canAccessPengguna() {
    return isAdmin();
}

function canAccessMetodePreparasi() {
    return isAdmin();
}

function canEditMetodePreparasi() {
    return isAdmin();
}

// ============================================================
// END OF ROLE HELPER FUNCTIONS
// ============================================================

// ── STATUS BAHAN — SATU SUMBER KEBENARAN ─────────────────────
function statusBahan($stok, $min) {
    $stok = (float)$stok;
    $min  = (float)$min;
    if ($min <= 0) return 'aman';
    if ($stok <= $min * 0.5) return 'kritis';
    if ($stok <= $min)       return 'rendah';
    return 'aman';
}

// ── HITUNG ALERT — SATU SUMBER KEBENARAN ─────────────────────
function hitungAlert(PDO $pdo): array {
    $bahanKritis = (int)$pdo->query(
        "SELECT COUNT(*) FROM bahan WHERE stok_minimum > 0 AND stok <= stok_minimum * 0.5"
    )->fetchColumn();

    $bahanRendah = (int)$pdo->query(
        "SELECT COUNT(*) FROM bahan WHERE stok_minimum > 0 AND stok <= stok_minimum"
    )->fetchColumn();

    $alatMasalah = (int)$pdo->query(
        "SELECT COUNT(*) FROM peralatan WHERE status IN('maintenance','rusak')"
    )->fetchColumn();

    $kalibrasiAlert = (int)$pdo->query(
        "SELECT COUNT(*) FROM peralatan
         WHERE masa_berlaku_kalibrasi IS NOT NULL
           AND masa_berlaku_kalibrasi <= DATE_ADD(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();

    return [
        'bahan_kritis'   => $bahanKritis,
        'bahan_rendah'   => $bahanRendah,
        'alat_masalah'   => $alatMasalah,
        'kalibrasi'      => $kalibrasiAlert,
        'total'          => $bahanRendah + $alatMasalah + $kalibrasiAlert,
    ];
}

// ── FORMAT TANGGAL ───────────────────────────────────────────
function fmtTgl($dateStr, $format = 'd/m/Y') {
    if (!$dateStr) return '&mdash;';
    return date($format, strtotime($dateStr));
}

function fmtTglPanjang($dateStr) {
    if (!$dateStr) return '&mdash;';
    return date('d F Y', strtotime($dateStr));
}

// ── BADGE STATUS ─────────────────────────────────────────────
function badgeStatus($s) {
    $map = [
        'antrian'     => ['st-blue', 'Antrian'],
        'diuji'       => ['st-ok',   'Diuji'],
        'review'      => ['st-warn', 'Review'],
        'selesai'     => ['st-ok',   'Selesai'],
        'ditolak'     => ['st-err',  'Ditolak'],
        'tersedia'    => ['st-ok',   'Tersedia'],
        'digunakan'   => ['st-blue', 'Digunakan'],
        'maintenance' => ['st-warn', 'Maintenance'],
        'rusak'       => ['st-err',  'Rusak'],
        'aman'        => ['st-ok',   'Aman'],
        'rendah'      => ['st-warn', 'Rendah'],
        'kritis'      => ['st-err',  'Kritis'],
        'lulus'       => ['st-ok',   '&#10003; Lulus'],
        'tidak_lulus' => ['st-err',  '&#10007; Tidak Lulus'],
        'pending'     => ['st-warn', 'Pending'],
        'aktif'       => ['st-ok',   'Aktif'],
        'nonaktif'    => ['st-err',  'Nonaktif'],
        'admin'       => ['st-err',  'Admin'],
        'analis'      => ['st-gold', 'Analis'],
        'supervisor'  => ['st-warn', 'Supervisor'],
        'klien'       => ['st-blue', 'Klien'],
        'client'      => ['st-blue', 'Client'],
        'diterima'    => ['st-blue', 'Diterima'],
        'diproses'    => ['st-warn', 'Diproses'],
        'draft'       => ['st-warn', 'Draft'],
        'diterbitkan' => ['st-blue', 'Diterbitkan'],
        'lunas'       => ['st-ok',   'Lunas'],
        'dibatalkan'  => ['st-err',  'Dibatalkan'],
    ];
    $d = $map[$s] ?? ['st-warn', ucfirst($s)];
    return "<span class='st {$d[0]}'>{$d[1]}</span>";
}

// ── NOMOR SERTIFIKAT DETERMINISTIK ───────────────────────────
function generateCertNum($noRec = null, $klien = '', $tgl = '') {
    if ($noRec) return $noRec;
    $seed = $klien . $tgl;
    return 'LAB-' . date('Ym') . '-' . strtoupper(substr(md5($seed), 0, 4));
}

// ── UTILITY FUNCTIONS UNTUK CLIENT ACCOUNT ──────────────────────
function tableExists($pdo, $table) {
    try {
        $result = $pdo->query("SELECT 1 FROM $table LIMIT 1");
    } catch (Exception $e) {
        return false;
    }
    return $result !== false;
}

function ensureMetodePreparasiTable($pdo) {
    if (tableExists($pdo, 'metode_preparasi')) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS metode_preparasi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            metode VARCHAR(255) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function clientAccessTableReady($pdo) {
    return tableExists($pdo, 'client_access');
}

function attachClientAccessToPenerimaan($pdo, $submissionId, $penerimaanId) {
    if (!$submissionId) return 0;
    $stmt = $pdo->prepare("UPDATE client_access SET penerimaan_id = ?, status = 'aktif' WHERE submission_id = ?");
    $stmt->execute([$penerimaanId, $submissionId]);
    return $stmt->rowCount();
}

/**
 * Tutup akses client untuk penerimaan yang sudah selesai.
 * Aman dipanggil berkali-kali (idempoten).
 * Return: jumlah baris client_access yang diubah.
 */
function cleanupCompletedClientAccounts($pdo, $penerimaanId) {
    $penerimaanId = (int)$penerimaanId;
    if ($penerimaanId <= 0) return 0;
    if (!tableExists($pdo, 'client_access') || !tableExists($pdo, 'sampel')) return 0;

    try {
        // Penerimaan dianggap selesai jika semua sampel berstatus selesai.
        $st = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS done_count
            FROM sampel
            WHERE penerimaan_id = ?
        ");
        $st->execute([$penerimaanId]);
        $row = $st->fetch();
        $total = (int)($row['total'] ?? 0);
        $done = (int)($row['done_count'] ?? 0);

        if ($total <= 0 || $done < $total) {
            return 0;
        }

        $upd = $pdo->prepare("
            UPDATE client_access
            SET status = 'selesai'
            WHERE penerimaan_id = ?
              AND status = 'aktif'
        ");
        $upd->execute([$penerimaanId]);
        return (int)$upd->rowCount();
    } catch (Exception $e) {
        // Jangan ganggu alur utama jika cleanup gagal.
        return 0;
    }
}

function createClientAccountForAccess($pdo, $data) {
    $kodeAkses = $data['kode_akses'] ?? '';
    $klien = $data['klien'] ?? '';
    $email = $data['email'] ?? '';
    $submissionId = $data['submission_id'] ?? null;
    $penerimaanId = $data['penerimaan_id'] ?? null;

    if (!$klien) return ['created' => false, 'message' => 'Nama klien kosong'];
    if (!$kodeAkses) return ['created' => false, 'message' => 'Kode akses kosong'];

    // Normalisasi username berbasis nama klien.
    $baseUsername = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '.', $klien), '.'));
    $username = $baseUsername ?: 'client';
    $stmt = $pdo->prepare("SELECT id FROM pengguna WHERE username = ?");
    $stmt->execute([$username]);
    $existingUser = $stmt->fetch();

    $userId = null;
    $passwordPlain = 'client123'; // Default password

    if ($existingUser) {
        $userId = $existingUser['id'];
        // Update role jika perlu
        $pdo->prepare("UPDATE pengguna SET role = 'client' WHERE id = ?")->execute([$userId]);
    } else {
        // Buat user baru
        $passwordHash = password_hash($passwordPlain, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO pengguna (nama, username, password, email, role, status) VALUES (?, ?, ?, ?, 'client', 'aktif')");
        $stmt->execute([$klien, $username, $passwordHash, $email]);
        $userId = $pdo->lastInsertId();
    }

    // Upsert client_access agar tidak duplikat ketika submission diproses ulang.
    $existingAccess = null;
    $stAccess = $pdo->prepare("SELECT id FROM client_access WHERE kode_akses = ? LIMIT 1");
    $stAccess->execute([$kodeAkses]);
    $existingAccess = $stAccess->fetch();
    if (!$existingAccess && $submissionId) {
        $stAccess = $pdo->prepare("SELECT id FROM client_access WHERE submission_id = ? LIMIT 1");
        $stAccess->execute([$submissionId]);
        $existingAccess = $stAccess->fetch();
    }

    if ($existingAccess) {
        $stmt = $pdo->prepare("
            UPDATE client_access
            SET pengguna_id = ?, submission_id = ?, penerimaan_id = ?, kode_akses = ?, klien = ?, email = ?
            WHERE id = ?
        ");
        $stmt->execute([$userId, $submissionId, $penerimaanId, $kodeAkses, $klien, $email, $existingAccess['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO client_access (pengguna_id, submission_id, penerimaan_id, kode_akses, klien, email) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $submissionId, $penerimaanId, $kodeAkses, $klien, $email]);
    }

    return [
        'created' => true,
        'username' => $username,
        'password' => $passwordPlain,
        'user_id' => $userId
    ];
}

/**
 * Sinkronisasi status penyelesaian penerimaan sampel dan sampel di dalamnya.
 */
function syncPenerimaanCompletion($pdo, $penerimaanId) {
    $penerimaanId = (int)$penerimaanId;
    if ($penerimaanId <= 0) return;
    
    try {
        // Otomatis tandai sampel sebagai 'selesai' jika sudah memiliki hasil uji
        $pdo->prepare("
            UPDATE sampel 
            SET status = 'selesai' 
            WHERE penerimaan_id = ? 
              AND id IN (SELECT sampel_id FROM hasil_uji)
        ")->execute([$penerimaanId]);
        
        // Cek apakah semua sampel di dalam penerimaan ini sudah selesai
        $st = $pdo->prepare("
            SELECT COUNT(*) as total, 
                   SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) as done
            FROM sampel 
            WHERE penerimaan_id = ?
        ");
        $st->execute([$penerimaanId]);
        $row = $st->fetch();
        
        // Jika semuanya selesai, update status penerimaan_sampel
        if ($row && $row['total'] > 0 && $row['total'] == $row['done']) {
            $pdo->prepare("UPDATE penerimaan_sampel SET status = 'selesai' WHERE id = ?")->execute([$penerimaanId]);
        }
    } catch (Exception $e) {
        // Abaikan error sinkronisasi
    }
}
