<?php
// ============================================================
//  includes/header.php
// ============================================================
if (!isset($pageTitle)) $pageTitle = 'LabMineral Pro';

// Hitung alert dengan fungsi terpusat
global $pdo;
$alertData  = ($pdo && !isClient()) ? hitungAlert($pdo) : ['total' => 0];
$alertCount = $alertData['total'] ?? 0;

// Ambil role dari session (dukung kedua format)
$userRole = currentUserRole() ?? 'guest';
$userNama = $_SESSION['nama'] ?? $_SESSION['user_nama'] ?? $_SESSION['name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?= bersihkan($pageTitle) ?> &mdash; LabMineral Pro</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css"/>
    <style>
        .user-role-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 8px;
        }
        .user-role-badge.admin { background: #e74c3c; color: white; }
        .user-role-badge.analis { background: #2ecc71; color: white; }
        .user-role-badge.supervisor { background: #f39c12; color: white; }
        .user-role-badge.klien { background: #3498db; color: white; }
        .user-role-badge.client { background: #3498db; color: white; }
        .user-role-badge.guest { background: #95a5a6; color: white; }
        .topbar-logout {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 20px;
            border: 1px solid var(--border);
            background: var(--bg3);
            color: var(--red);
            text-decoration: none;
            font-size: .78rem;
            font-weight: 600;
        }
        .topbar-logout:hover {
            filter: brightness(1.08);
        }
        .client-topbar-left {
            min-width: 240px;
            display: inline-flex;
            align-items: center;
        }
        .client-topbar-logo {
            color: var(--gold);
            font-weight: 700;
            font-size: .9rem;
            letter-spacing: .4px;
            white-space: nowrap;
        }
        .client-title-center {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            margin: 0;
            pointer-events: none;
            text-align: center;
        }
    </style>
</head>
<body>

<?php if (!isClient()): ?>
<?php require_once __DIR__ . '/sidebar.php'; ?>
<?php endif; ?>

<div id="main">
    <div id="topbar">
        <?php if (isClient()): ?>
            <div class="client-topbar-left">
                <span class="client-topbar-logo">&#9879; AISPEKTRA LABORATORY</span>
            </div>
            <h1 class="client-title-center"><?= bersihkan($pageTitle) ?></h1>
        <?php else: ?>
            <h1><?= bersihkan($pageTitle) ?></h1>
        <?php endif; ?>
        <div class="topbar-right">
            <?php if ($alertCount > 0): ?>
                <span class="badge-alert" title="<?= $alertData['bahan_rendah'] ?? 0 ?> bahan rendah · <?= $alertData['alat_masalah'] ?? 0 ?> alat bermasalah · <?= $alertData['kalibrasi'] ?? 0 ?> kalibrasi">
                    &#9888; <?= $alertCount ?> Alert
                </span>
            <?php endif; ?>
            <span class="user-chip">
                &#128100; <?= bersihkan($userNama) ?>
                <span class="user-role-badge <?= $userRole ?>"><?= strtoupper($userRole) ?></span>
            </span>
            <?php if (isClient()): ?>
                <a class="topbar-logout" href="<?= BASE_URL ?>/logout.php">&#128682; Logout</a>
            <?php endif; ?>
        </div>
    </div>
    <div id="content">
