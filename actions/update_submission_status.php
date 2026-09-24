<?php
// ============================================================
//  actions/update_submission_status.php
//  Update status submission (AJAX)
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Cek apakah user login dan admin
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
    exit;
}

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Admin only.']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

// Validasi status yang diizinkan
$allowedStatus = ['pending', 'diterima', 'ditolak'];
if (!$id || !in_array($status, $allowedStatus)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

try {
    $credentialPayload = null;

    if ($status === 'diterima') {
        $subStmt = $pdo->prepare("SELECT * FROM submission_sampel WHERE id = ? LIMIT 1");
        $subStmt->execute([$id]);
        $submission = $subStmt->fetch();
        if (!$submission) {
            echo json_encode(['success' => false, 'message' => 'Submission tidak ditemukan.']);
            exit;
        }

        $clientAccount = createClientAccountForAccess($pdo, [
            'kode_akses' => $submission['nomor_submission'] ?? '',
            'submission_id' => $id,
            'klien' => $submission['klien'] ?? '',
            'email' => $submission['email'] ?? '',
        ]);

        if (!($clientAccount['created'] ?? false)) {
            $msg = $clientAccount['message'] ?? 'Akun client gagal dibuat.';
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        $credentialPayload = [
            'username' => $clientAccount['username'] ?? '',
            'password' => $clientAccount['password'] ?? '',
        ];
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE submission_sampel SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Status updated successfully.',
            'credentials' => $credentialPayload
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No changes made or submission not found.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
