<?php
// ============================================================
//  ssf.php — Form Pengiriman Sampel untuk Klien
//  Halaman publik, TIDAK PERLU LOGIN
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';

$pageTitle = 'Form Pengiriman Sampel';
$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$success = $_SESSION['success'] ?? ''; unset($_SESSION['success']);
$submissionNo = $_SESSION['submission_no'] ?? ''; unset($_SESSION['submission_no']);
$clientCredentials = $_SESSION['client_credentials'] ?? null; unset($_SESSION['client_credentials']);

$materialOpts = ['Bijih Emas','Nikel Laterit','Tembaga','Bauksit','Bijih Besi','Timbal/Seng','Mangan','Kromit','Lainnya'];
$metodeOpts   = ['AAS','XRF','ICP-OES','Gravimetri','Fire Assay','Volumetri'];
$parameterOpts = ['Au (Emas)','Ag (Perak)','Cu (Tembaga)','Ni (Nikel)','Fe (Besi)','Al2O3','SiO2','Pb (Timbal)','Zn (Seng)','Lainnya'];

// Generate nomor submission otomatis
$lastSub = $pdo->query("SELECT nomor_submission FROM submission_sampel ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextNum = $lastSub ? (intval(substr($lastSub, -4)) + 1) : 1;
$noAuto = 'SUB-' . date('ymd') . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sample Submission Form (SSF) — Aispektra Laboratory</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-deep:    #070f09;
            --bg-base:    #0d1f10;
            --bg-card:    #112416;
            --bg-card2:   #0f2014;
            --border:     #1e3d22;
            --border-glow:#2d5c33;
            --gold:       #e8b400;
            --gold-light: #f5cc3a;
            --gold-dim:   #a07d00;
            --text-main:  #e6f0e8;
            --text-muted: #7aaa82;
            --text-dim:   #4a6e50;
            --red:        #e05252;
            --green-btn:  #1e6b2e;
            --green-hover:#256135;
            --radius:     10px;
            --shadow:     0 8px 32px rgba(0,0,0,0.55);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg-deep);
            min-height: 100vh;
            padding: 40px 20px 60px;
            background-image:
                radial-gradient(ellipse 60% 40% at 50% 0%, rgba(30,70,35,0.35) 0%, transparent 70%),
                repeating-linear-gradient(0deg, transparent, transparent 39px, rgba(30,60,35,0.07) 39px, rgba(30,60,35,0.07) 40px),
                repeating-linear-gradient(90deg, transparent, transparent 39px, rgba(30,60,35,0.07) 39px, rgba(30,60,35,0.07) 40px);
        }

        /* ── HEADER ── */
        .header {
            text-align: center;
            margin-bottom: 36px;
        }
        .header-logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 6px;
        }
        .header-logo .icon {
            font-size: 32px;
        }
        .header h1 {
            font-family: 'Cinzel', serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--gold);
            letter-spacing: 2px;
            text-shadow: 0 0 20px rgba(232,180,0,0.3);
            line-height: 1.1;
        }
        .header .subtitle {
            font-size: 13px;
            color: var(--text-muted);
            letter-spacing: 0.5px;
            margin-top: 6px;
        }

        /* ── CONTAINER ── */
        .container { max-width: 900px; margin: 0 auto; }

        /* ── ALERT ── */
        .alert {
            padding: 13px 18px;
            border-radius: var(--radius);
            margin-bottom: 22px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: rgba(30,107,46,0.2);
            border: 1px solid #2d6b35;
            color: #7ee89a;
        }
        .alert-error {
            background: rgba(224,82,82,0.12);
            border: 1px solid #6b2d2d;
            color: #f5a0a0;
        }

        /* ── CARD ── */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 28px 30px;
            margin-bottom: 22px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold-dim), transparent);
            opacity: 0.6;
        }
        .card-title {
            font-family: 'Cinzel', serif;
            font-size: 15px;
            font-weight: 600;
            color: var(--gold);
            letter-spacing: 1px;
            padding-bottom: 14px;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .card-title .icon {
            font-size: 18px;
        }

        /* ── FORM ── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 4px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 7px;
        }
        .form-group label .required {
            color: var(--red);
            margin-left: 3px;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-base);
            border: 1px solid var(--border);
            border-radius: 7px;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            color: var(--text-main);
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        .form-control::placeholder { color: var(--text-dim); }
        .form-control:focus {
            border-color: var(--gold-dim);
            box-shadow: 0 0 0 3px rgba(232,180,0,0.1);
        }
        .form-control[readonly], .form-control[disabled] {
            opacity: 0.5;
            cursor: not-allowed;
        }
        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237aaa82' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }
        select.form-control option {
            background: var(--bg-card);
            color: var(--text-main);
        }
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        /* ── BUTTONS ── */
        .btn {
            padding: 12px 26px;
            border: none;
            border-radius: 7px;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            letter-spacing: 0.3px;
        }
        .btn-primary {
            background: var(--gold);
            color: #0a1200;
        }
        .btn-primary:hover {
            background: var(--gold-light);
            box-shadow: 0 0 18px rgba(232,180,0,0.35);
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover {
            border-color: var(--border-glow);
            color: var(--text-main);
        }
        .btn-add {
            background: var(--green-btn);
            color: var(--text-main);
            padding: 9px 18px;
            font-size: 13px;
            border: 1px solid #2a5e35;
        }
        .btn-add:hover {
            background: var(--green-hover);
            box-shadow: 0 0 12px rgba(30,107,46,0.3);
        }
        .btn-remove {
            background: rgba(224,82,82,0.15);
            color: #f5a0a0;
            border: 1px solid rgba(224,82,82,0.3);
            padding: 5px 12px;
            font-size: 12px;
            border-radius: 5px;
        }
        .btn-remove:hover {
            background: rgba(224,82,82,0.25);
        }

        /* ── SAMPLE ROW ── */
        .sample-row {
            background: var(--bg-card2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px 22px;
            margin-bottom: 16px;
            position: relative;
        }
        .sample-row-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .sample-row-header strong {
            font-family: 'Cinzel', serif;
            font-size: 13px;
            color: var(--gold);
            letter-spacing: 1px;
        }
        .sample-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--gold-dim);
            color: #0a1200;
            font-size: 11px;
            font-weight: 700;
            margin-right: 8px;
        }

        /* ── ACTIONS ── */
        .form-actions {
            display: flex;
            gap: 14px;
            justify-content: center;
            margin-top: 10px;
        }

        /* ── SUCCESS CARD ── */
        .success-card {
            text-align: center;
            padding: 40px;
        }
        .success-card .big-check {
            font-size: 52px;
            margin-bottom: 16px;
        }
        .success-card h2 {
            font-family: 'Cinzel', serif;
            color: var(--gold);
            font-size: 20px;
            margin-bottom: 12px;
        }
        .success-card p {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 8px;
        }
        .submission-number {
            display: inline-block;
            margin: 14px 0;
            padding: 10px 24px;
            background: rgba(232,180,0,0.1);
            border: 1px solid var(--gold-dim);
            border-radius: 7px;
            font-family: 'Cinzel', serif;
            font-size: 16px;
            color: var(--gold);
            letter-spacing: 2px;
        }

        /* ── FOOTER ── */
        .footer {
            text-align: center;
            margin-top: 36px;
            color: var(--text-dim);
            font-size: 12px;
            line-height: 1.8;
        }
        .footer .divider {
            display: inline-block;
            margin: 0 8px;
            opacity: 0.4;
        }

        /* ── DIVIDER ── */
        .add-sample-area {
            text-align: center;
            padding-top: 4px;
        }

        @media (max-width: 768px) {
            .header h1 { font-size: 22px; }
            .card { padding: 20px 18px; }
        }

        /* ── PRINT STYLES (SSF TEMPLATE) ── */
        @media print {
            body { background: white !important; padding: 0 !important; color: black !important; }
            .container { max-width: 100% !important; margin: 0 !important; }
            .header, .form-actions, .footer, .btn, .add-sample-area, .alert, .success-card p, .success-card button, .success-card .big-check { display: none !important; }
            .success-card { display: block !important; padding: 0 !important; border: none !important; }
            .success-card h2::before { content: "SAMPLE SUBMISSION FORM (SSF)"; display: block; font-size: 20pt; margin-bottom: 10pt; color: black; font-family: serif; border-bottom: 2px solid black; padding-bottom: 5pt; }
            .success-card h2 { font-size: 0 !important; margin-bottom: 20pt !important; }
            .submission-number { display: block !important; border: 1px solid black !important; padding: 10pt !important; font-size: 14pt !important; margin: 10pt 0 !important; }
            
            .card { border: 1px solid #ccc !important; break-inside: avoid; }
            .card-title { border-bottom: 1px solid #ccc !important; color: black !important; }
        }
    </style>
</head>
<body>
    <div class="container">

        <div class="header">
            <div class="header-logo">
                <span class="icon">📋</span>
                <h1>SAMPLE SUBMISSION<br>FORM (SSF)</h1>
            </div>
            <p class="subtitle">Sistem Informasi Laboratorium Uji Mineral &amp; Logam</p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-error">⚠️ <?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
            <div class="card success-card">
                <div class="big-check">✓</div>
                <h2>SSF Berhasil Terkirim!</h2>
                <p>Terima kasih telah mengirimkan sampel Anda. Formulir Anda telah kami terima.</p>
                <div class="submission-number" id="noSsf"><?= htmlspecialchars($submissionNo ?: $noAuto) ?></div>
                <p>Silakan unduh atau cetak formulir ini sebagai bukti pengiriman fisik.</p>
                
                <?php if ($clientCredentials): ?>
                    <div style="margin:22px auto 0;max-width:460px;text-align:center;background:rgba(232,180,0,0.05);border:1px dashed var(--gold-dim);border-radius:10px;padding:18px 20px">
                        <div style="color:var(--gold);font-weight:700;margin-bottom:10px">Akun Monitoring Sampel</div>
                        <p style="margin-bottom:12px;color:var(--text-muted);font-size:13px">
                            Akun monitoring Anda telah berhasil dibuat. Gunakan kredensial di bawah ini untuk memantau status sampel Anda:
                        </p>
                        <div style="margin-bottom:14px;font-size:14px;color:var(--text-main);line-height:1.8">
                            <strong>Username:</strong> <code style="background:rgba(255,255,255,0.08);padding:3px 8px;border-radius:5px;font-family:monospace;border:1px solid rgba(232,180,0,0.2)"><?= htmlspecialchars($clientCredentials['username']) ?></code><br>
                            <strong>Password:</strong> <code style="background:rgba(255,255,255,0.08);padding:3px 8px;border-radius:5px;font-family:monospace;border:1px solid rgba(232,180,0,0.2)"><?= htmlspecialchars($clientCredentials['password']) ?></code>
                        </div>
                        <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary" style="display:inline-block;padding:8px 16px;font-size:12px;text-decoration:none;margin-top:4px">🔑 Masuk ke Portal Monitoring</a>
                    </div>
                <?php endif; ?>

                <div style="display:flex;gap:12px;justify-content:center;margin:22px 0">
                    <button onclick="window.location.reload()" class="btn btn-secondary">⊕ Kirim Form Baru</button>
                    <button onclick="window.print()" class="btn btn-primary">🖨️ Cetak / Simpan SSF</button>
                </div>

                <!-- Hidden Professional SSF for Printing -->
                <div id="ssfPrintArea" style="display:none">
                    <?php include __DIR__ . '/../includes/ssf_template.php'; ?>
                </div>
            </div>
        <?php else: ?>

        <form method="POST" action="<?= BASE_URL ?>/actions/simpan_submit_sampel.php" id="submissionForm">

            <!-- INFORMASI PENGIRIM -->
            <div class="card">
                <div class="card-title"><span class="icon">📋</span> Informasi Pengirim</div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nama Perusahaan / Klien <span class="required">*</span></label>
                        <input type="text" name="klien" class="form-control" required placeholder="PT. Contoh Mineral">
                    </div>
                    <div class="form-group">
                        <label>Nomor Submission (Otomatis)</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($noAuto) ?>" readonly disabled>
                        <input type="hidden" name="nomor_submission" value="<?= htmlspecialchars($noAuto) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Kontak Person <span class="required">*</span></label>
                        <input type="text" name="kontak_person" class="form-control" required placeholder="Nama penanggung jawab">
                    </div>
                    <div class="form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control" required placeholder="email@perusahaan.com">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>No. Telepon <span class="required">*</span></label>
                        <input type="tel" name="telepon" class="form-control" required placeholder="0812-3456-7890">
                    </div>
                </div>

                <div class="form-group">
                    <label>Alamat Lengkap <span class="required">*</span></label>
                    <textarea name="alamat" class="form-control" rows="2" required placeholder="Jl. ..."></textarea>
                </div>
            </div>

            <!-- DETAIL SAMPEL -->
            <div class="card">
                <div class="card-title"><span class="icon">🧪</span> Detail Sampel</div>
                <p style="margin-bottom:18px; font-size:13px; color:var(--text-dim)">
                    Isi data sampel yang akan dikirimkan. Minimal 1 sampel, maksimal 10 sampel per pengiriman.
                </p>

                <div id="sampleRowsContainer"></div>

                <div class="add-sample-area">
                    <button type="button" class="btn btn-add" onclick="addSampleRow()">➕ Tambah Sampel</button>
                </div>
            </div>

            <!-- INFORMASI TAMBAHAN -->
            <div class="card">
                <div class="card-title"><span class="icon">📝</span> Informasi Tambahan</div>

                <div class="form-group">
                    <label>Instruksi Khusus / Metode Analisis yang Diminta</label>
                    <textarea name="instruksi_khusus" class="form-control" rows="3"
                        placeholder="Jelaskan parameter yang ingin diuji, metode khusus, atau instruksi lainnya..."></textarea>
                </div>

                <div class="form-group" style="margin-bottom:0">
                    <label>Catatan Tambahan</label>
                    <textarea name="catatan" class="form-control" rows="2"
                        placeholder="Informasi lain yang perlu diketahui..."></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="reset" class="btn btn-secondary">↺ Reset Form</button>
                <button type="submit" class="btn btn-primary">📤 Kirim Pengajuan Sampel</button>
            </div>
        </form>

        <?php endif; ?>

        <div class="footer">
            <p><strong style="color:var(--text-muted)">Aispektra Laboratory</strong> — Laboratorium Uji Mineral &amp; Logam</p>
            <p>
                Jl. Tamalanrea Raya, Makassar 90245
                <span class="divider">|</span>
                Telp: +62 411 000-0000
                <span class="divider">|</span>
                lab@aispektra.co.id
            </p>
        </div>
    </div>

    <script>
        let sampleCount = 0;
        const materialOpts  = <?= json_encode($materialOpts) ?>;
        const metodeOpts    = <?= json_encode($metodeOpts) ?>;
        const parameterOpts = <?= json_encode($parameterOpts) ?>;

        function buildOptions(arr) {
            return arr.map(v => `<option value="${v}">${v}</option>`).join('');
        }

        function addSampleRow() {
            if (sampleCount >= 10) {
                alert('Maksimal 10 sampel per pengiriman.');
                return;
            }
            sampleCount++;
            const idx = sampleCount;
            const container = document.getElementById('sampleRowsContainer');
            const div = document.createElement('div');
            div.className = 'sample-row';
            div.id = 'sampleRow_' + idx;
            div.innerHTML = `
                <div class="sample-row-header">
                    <strong><span class="sample-badge">${idx}</span> SAMPEL #${idx}</strong>
                    <button type="button" class="btn-remove" onclick="removeSampleRow(${idx})">✖ Hapus</button>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Jenis Material</label>
                        <select name="sampel[${idx}][jenis_material]" class="form-control">
                            <option value="">— Pilih —</option>
                            ${buildOptions(materialOpts)}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Berat (gram)</label>
                        <input type="number" step="0.001" name="sampel[${idx}][berat_gram]" class="form-control" placeholder="0.000">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Metode Uji yang Diminta</label>
                        <select name="sampel[${idx}][metode_uji]" class="form-control">
                            <option value="">— Pilih —</option>
                            ${buildOptions(metodeOpts)}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Parameter yang Diuji</label>
                        <select name="sampel[${idx}][parameter]" class="form-control">
                            <option value="">— Pilih —</option>
                            ${buildOptions(parameterOpts)}
                        </select>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Keterangan / Kode Sampel <span style="text-transform:none;font-weight:400;opacity:.6">(opsional)</span></label>
                    <input type="text" name="sampel[${idx}][keterangan]" class="form-control" placeholder="Kode sampel atau keterangan tambahan">
                </div>
            `;
            container.appendChild(div);
        }

        function removeSampleRow(id) {
            if (sampleCount <= 1) {
                alert('Minimal 1 sampel harus diisi.');
                return;
            }
            const row = document.getElementById('sampleRow_' + id);
            if (row) row.remove();
            sampleCount--;
        }

        document.addEventListener('DOMContentLoaded', () => addSampleRow());
    </script>
</body>
</html>
