<?php
session_start();
require_once 'config/db.php';

// Jika sudah login, redirect ke dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT * FROM pengguna WHERE username = ? AND status = 'aktif' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nama']    = $user['nama'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['user_role'] = $user['role'];
            
            // Log aktivitas
            $pdo->prepare("INSERT INTO log_aktivitas (pengguna_id, aksi, modul) VALUES (?, 'Login', 'auth')")
                ->execute([$user['id']]);
            
            $redirect = normalizeRole($user['role']) === 'client'
                ? BASE_URL . '/pages/client_monitoring.php'
                : BASE_URL . '/pages/dashboard.php';
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Username atau password salah.';
        }
    } else {
        $error = 'Mohon isi username dan password.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Login — Aispektra Laboratory</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css"/>
<style>
  body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg); }
  .login-box { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:36px 32px; width:360px; }
  .login-logo { text-align:center; margin-bottom:28px; }
  .login-logo h1 { color:var(--gold); font-size:1.4rem; margin-bottom:4px; }
  .login-logo p  { color:var(--text3); font-size:.8rem; }
  .form-group { margin-bottom:16px; }
  .form-group label { display:block; font-size:.78rem; color:var(--text3); margin-bottom:5px; }
  .form-group input { width:100%; background:var(--bg3); border:1px solid var(--border); color:var(--text);
      padding:10px 14px; border-radius:7px; font-size:.9rem; outline:none; }
  .form-group input:focus { border-color:var(--green3); }
  .btn-login { width:100%; padding:11px; background:var(--gold2); color:#1a0f00; border:none;
      border-radius:7px; font-size:.9rem; font-weight:700; cursor:pointer; margin-top:6px; }
  .btn-login:hover { background:var(--gold); }
  .error-msg { background:#3d1010; color:#e74c3c; padding:9px 12px; border-radius:7px;
      font-size:.8rem; margin-bottom:14px; border-left:3px solid var(--red); }
  .hint { text-align:center; font-size:.72rem; color:var(--text3); margin-top:18px; }
</style>
</head>
<body>
<div class="login-box">
  <div class="login-logo">
    <h1>⚗️ AISPEKTRA LABORATORY</h1>
    <p>Sistem Informasi Laboratorium Uji Mineral & Logam</p>
  </div>
  <?php if ($error): ?>
    <div class="error-msg">⚠ <?= bersihkan($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <div class="form-group">
      <label>Username</label>
      <input type="text" name="username" placeholder="Masukkan username"
             value="<?= bersihkan($_POST['username'] ?? '') ?>" autofocus required/>
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" placeholder="Masukkan password" required/>
    </div>
    <button type="submit" class="btn-login">🔐 Masuk</button>
  </form>
  
  <div class="hint" style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border);">
    <p style="margin-bottom: 8px;">Klien baru atau ingin mengajukan pengujian?</p>
    <a href="<?= BASE_URL ?>/pages/ssf.php" style="display:inline-block; padding:8px 16px; background:var(--bg3); color:var(--gold); border:1px solid var(--gold2); border-radius:7px; text-decoration:none; font-size:.85rem; font-weight:600; transition:0.2s;">
      📝 Isi Sample Submission Form (SSF)
    </a>
  </div>
</div>
</body>
</html>
