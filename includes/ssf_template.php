<!-- ============================================================
     includes/ssf_template.php
     ============================================================ -->
<div class="ssf-printable">
    <style>
        .ssf-printable { display: none; background: white; color: black; font-family: 'Times New Roman', serif; padding: 20px; }
        @media print {
            .ssf-printable { display: block !important; }
            .no-print { display: none !important; }
        }
        .ssf-kop { display: flex; align-items: center; gap: 20px; border-bottom: 2px solid black; padding-bottom: 10px; margin-bottom: 20px; }
        .ssf-logo { font-size: 28pt; color: #1e4028; font-weight: 800; text-align: center; border-right: 1px solid #ccc; padding-right: 20px; line-height: 1; }
        .ssf-kop h1 { margin: 0; font-size: 18pt; color: black; }
        .ssf-kop p { margin: 0; font-size: 9pt; color: #444; }
        .ssf-title { text-align: center; margin-bottom: 20px; }
        .ssf-title h2 { margin: 0; font-size: 14pt; text-decoration: underline; }
        .ssf-section { margin-bottom: 15px; }
        .ssf-section-title { background: #f0f0f0; padding: 4px 8px; font-weight: 700; font-size: 10pt; border-left: 4px solid black; margin-bottom: 10px; }
        .ssf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 9pt; }
        .ssf-field { margin-bottom: 6px; }
        .ssf-field label { display: block; font-weight: 700; margin-bottom: 2px; }
        .ssf-field span { display: block; border-bottom: 1px dotted #888; min-height: 1.2em; }
        .ssf-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 8.5pt; }
        .ssf-table th, .ssf-table td { border: 1px solid black; padding: 6px; text-align: left; }
        .ssf-table th { background: #f9f9f9; text-align: center; }
        .ssf-footer { margin-top: 30px; display: flex; justify-content: space-between; font-size: 9pt; }
        .ssf-sign-box { width: 200px; text-align: center; }
        .ssf-sign-line { margin-top: 60px; border-top: 1px solid black; padding-top: 5px; }
    </style>

    <div class="ssf-kop">
        <div class="ssf-logo">🧪<br>LAB</div>
        <div>
            <h1>AISPEKTRA LABORATORY</h1>
            <p>Laboratorium Uji Mineral &amp; Logam<br>
            Jl. Tamalanrea Raya, Makassar 90245, Sulawesi Selatan<br>
            Telp/Fax. +62 411 000-0000 | Email: lab@labmineral.co.id</p>
        </div>
    </div>

    <div class="ssf-title">
        <h2>SAMPLE SUBMISSION FORM (SSF)</h2>
        <p style="margin:0; font-size:9pt">(Formulir Pengiriman Sampel)</p>
    </div>

    <div style="display:flex; justify-content:space-between; font-size:9pt; margin-bottom:15px">
        <span>No. Form: <strong><?= $noAuto ?? 'SSF-AUTO' ?></strong></span>
        <span>Tanggal: <strong><?= date('d/m/Y') ?></strong></span>
    </div>

    <div class="ssf-section">
        <div class="ssf-section-title">A. INFORMASI KLIEN / CUSTOMER INFORMATION</div>
        <div class="ssf-grid">
            <div class="ssf-field"><label>Nama Perusahaan</label><span><?= htmlspecialchars($_POST['klien'] ?? '-') ?></span></div>
            <div class="ssf-field"><label>Kontak Person</label><span><?= htmlspecialchars($_POST['kontak_person'] ?? '-') ?></span></div>
            <div class="ssf-field"><label>Email</label><span><?= htmlspecialchars($_POST['email'] ?? '-') ?></span></div>
            <div class="ssf-field"><label>No. Telepon</label><span><?= htmlspecialchars($_POST['telepon'] ?? '-') ?></span></div>
        </div>
    </div>

    <div class="ssf-section">
        <div class="ssf-section-title">B. DETAIL PENGIRIMAN / SUBMISSION DETAILS</div>
        <div class="ssf-grid">
            <div class="ssf-field"><label>Tanggal Kirim</label><span><?= date('d/m/Y') ?></span></div>
            <div class="ssf-field"><label>No. PO / Referensi</label><span><?= htmlspecialchars($_POST['po_referensi'] ?? '-') ?></span></div>
        </div>
    </div>

    <div class="ssf-section">
        <div class="ssf-section-title">C. DAFTAR SAMPEL / LIST OF SAMPLES</div>
        <table class="ssf-table">
            <thead>
                <tr>
                    <th style="width:30px">No</th>
                    <th>Jenis Material</th>
                    <th style="width:80px">Berat (g)</th>
                    <th>Metode Uji</th>
                    <th>Parameter</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($_POST['sampel'])): ?>
                    <?php foreach ($_POST['sampel'] as $i => $s): ?>
                        <tr>
                            <td style="text-align:center"><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($s['jenis_material'] ?? '-') ?></td>
                            <td style="text-align:right"><?= htmlspecialchars($s['berat_gram'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($s['metode_uji'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($s['parameter'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align:center">Tidak ada data sampel</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="ssf-footer">
        <div class="ssf-sign-box">
            <p>Diserahkan oleh,</p>
            <div class="ssf-sign-line">( Klien )</div>
        </div>
        <div class="ssf-sign-box">
            <p>Diterima oleh,</p>
            <div class="ssf-sign-line">( Petugas Lab )</div>
        </div>
    </div>

    <div style="margin-top:20px; font-size:8pt; border-top:1px solid #ccc; padding-top:10px; color:#666">
        Dokumen ini digenerate secara otomatis oleh sistem LabMineral Pro pada <?= date('d/m/Y H:i') ?>.
    </div>
</div>
