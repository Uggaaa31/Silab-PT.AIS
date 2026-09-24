<?php
// ============================================================
//  xrf_data.php — Data Lengkap Pengukuran XRF Explorer 7000 (Admin)
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
cekLogin();

if (!isAdmin()) {
    $_SESSION['msg'] = 'ERROR: Halaman ini hanya dapat diakses oleh Administrator.';
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$pageTitle = 'Data XRF Explorer';
$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);

// ── Parameter Filter & Pagination ────────────────────────────
$fStart  = trim($_GET['fstart'] ?? '');
$fEnd    = trim($_GET['fend'] ?? '');
$fMode   = trim($_GET['fmode'] ?? '');
$fSearch = trim($_GET['fsearch'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 20;
$offset  = ($page - 1) * $limit;

$totalCount = 0;
$todayCount = 0;
$dbSources  = [];
$workCurves = [];
$totalRows  = 0;
$totalPages = 1;
$xrfList    = [];

try {
    $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM xrf_measurements")->fetchColumn();
    $todayCount = (int)$pdo->query("SELECT COUNT(*) FROM xrf_measurements WHERE DATE(test_date) = CURDATE()")->fetchColumn();
    $dbSources  = $pdo->query("SELECT DISTINCT db_source FROM xrf_measurements WHERE db_source IS NOT NULL AND db_source != '' ORDER BY db_source")->fetchAll(PDO::FETCH_COLUMN);
    $workCurves = $pdo->query("SELECT DISTINCT work_curve_name FROM xrf_measurements WHERE work_curve_name IS NOT NULL AND work_curve_name != '' AND work_curve_name != '-' ORDER BY work_curve_name")->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT * FROM xrf_measurements WHERE 1=1";
    $prm = [];

    if ($fStart !== '') {
        $sql .= " AND DATE(test_date) >= ?";
        $prm[] = $fStart;
    }
    if ($fEnd !== '') {
        $sql .= " AND DATE(test_date) <= ?";
        $prm[] = $fEnd;
    }
    if ($fMode !== '' && $fMode !== 'all') {
        if (str_ends_with($fMode, '.db')) {
            $sql .= " AND db_source = ?";
            $prm[] = $fMode;
        } else {
            $sql .= " AND work_curve_name = ?";
            $prm[] = $fMode;
        }
    }
    if ($fSearch !== '') {
        $sql .= " AND (sample_name LIKE ? OR report_id LIKE ? OR operator LIKE ? OR grade LIKE ? OR work_curve_name LIKE ?)";
        $wc = "%$fSearch%";
        $prm[] = $wc; $prm[] = $wc; $prm[] = $wc; $prm[] = $wc; $prm[] = $wc;
    }

    $cntSql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
    $stCnt = $pdo->prepare($cntSql);
    $stCnt->execute($prm);
    $totalRows  = (int)$stCnt->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $limit));

    $sql .= " ORDER BY timestamp_ms DESC, id DESC LIMIT $limit OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($prm);
    $xrfList = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($xrfList)) {
        $mIds = array_column($xrfList, 'id');
        $inP = implode(',', array_fill(0, count($mIds), '?'));
        $elSt = $pdo->prepare("
            SELECT measurement_id, element_name, concentration, element_error, unit
            FROM xrf_measurement_elements
            WHERE measurement_id IN ($inP)
            ORDER BY concentration DESC
        ");
        $elSt->execute($mIds);
        $allEls = $elSt->fetchAll(PDO::FETCH_ASSOC);
        $elsMap = [];
        foreach ($allEls as $e) {
            $elsMap[$e['measurement_id']][] = $e;
        }
        foreach ($xrfList as &$xa) {
            $xa['elements'] = $elsMap[$xa['id']] ?? [];
        }
    }
} catch (Exception $e) {
    $msg = 'ERROR: ' . $e->getMessage();
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.xrf-badge-mode {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 4px;
    font-size: .68rem;
    font-weight: 600;
}
.xrf-mode-mineral { background: #064e3b; color: #6ee7b7; border: 1px solid #047857; }
.xrf-mode-alloy   { background: #1e3a8a; color: #93c5fd; border: 1px solid #3b82f6; }
.xrf-mode-metal   { background: #451a03; color: #fde047; border: 1px solid #d97706; }

.xrf-element-pill {
    display: inline-block;
    background: #1e293b;
    color: #e2e8f0;
    border: 1px solid #334155;
    border-radius: 3px;
    padding: 1px 6px;
    font-size: .68rem;
    margin-right: 4px;
    margin-bottom: 2px;
}

/* Modal XRF Detail */
.modal-xrf {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(4px);
    z-index: 1050;
    justify-content: center;
    align-items: center;
}
.modal-xrf-content {
    background: var(--bg2);
    border-radius: 12px;
    width: 820px;
    max-width: 95vw;
    max-height: 88vh;
    padding: 22px 24px;
    border: 1px solid var(--gold);
    box-shadow: 0 8px 32px rgba(0,0,0,0.6);
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
}
.modal-xrf-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.modal-edit-close {
    background: none;
    border: none;
    color: var(--text3);
    font-size: 1.5rem;
    cursor: pointer;
    transition: .15s;
}
.modal-edit-close:hover { color: var(--red); }
</style>

<div style="margin-bottom:14px">
    <div class="sec-title" style="margin-bottom:2px">⚡ Data Pengukuran XRF Explorer 7000</div>
    <p style="font-size:.78rem;color:var(--text3);margin:0">Database lengkap hasil scan spektrometri genggam XRF yang tersimpan di MySQL server.</p>
</div>

<?php if ($msg): ?>
    <div class="alert-box <?= str_starts_with($msg,'ERROR')?'alert-red':'alert-green' ?>" style="margin-bottom:14px">
        <?= str_starts_with($msg,'ERROR')?'&#9888;':'&#10003;' ?> <?= bersihkan($msg) ?>
    </div>
<?php endif; ?>

<!-- KPI Summary Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">
    <div class="card" style="padding:14px;background:rgba(30,41,59,0.7);border:1px solid rgba(56,189,248,0.3)">
        <div style="font-size:.7rem;color:var(--text3);text-transform:uppercase;font-weight:600">Total Scan XRF</div>
        <div style="font-size:1.6rem;font-weight:700;color:#38bdf8;margin-top:2px"><?= number_format($totalCount) ?></div>
        <div style="font-size:.68rem;color:var(--text3)">Semua riwayat pengukuran tersimpan</div>
    </div>
    <div class="card" style="padding:14px;background:rgba(30,41,59,0.7);border:1px solid rgba(52,211,153,0.3)">
        <div style="font-size:.7rem;color:var(--text3);text-transform:uppercase;font-weight:600">Scan Hari Ini</div>
        <div style="font-size:1.6rem;font-weight:700;color:#34d399;margin-top:2px"><?= number_format($todayCount) ?></div>
        <div style="font-size:.68rem;color:var(--text3)"><?= date('d F Y') ?></div>
    </div>
    <div class="card" style="padding:14px;background:rgba(30,41,59,0.7);border:1px solid rgba(245,158,11,0.3)">
        <div style="font-size:.7rem;color:var(--text3);text-transform:uppercase;font-weight:600">Database Instrumen</div>
        <div style="font-size:1.6rem;font-weight:700;color:#f59e0b;margin-top:2px"><?= count($dbSources) ?> <small style="font-size:.75rem;color:var(--text3)">Mode</small></div>
        <div style="font-size:.68rem;color:var(--text3)"><?= !empty($dbSources) ? implode(', ', $dbSources) : 'mineral.db, alloy.db' ?></div>
    </div>
    <div class="card" style="padding:14px;background:rgba(30,41,59,0.7);border:1px solid rgba(168,85,247,0.3)">
        <div style="font-size:.7rem;color:var(--text3);text-transform:uppercase;font-weight:600">Kurva Kalibrasi</div>
        <div style="font-size:1.6rem;font-weight:700;color:#c084fc;margin-top:2px"><?= count($workCurves) ?> <small style="font-size:.75rem;color:var(--text3)">Kurva</small></div>
        <div style="font-size:.68rem;color:var(--text3)">Work curves terdaftar</div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px 12px">
    <div style="display:flex;align-items:center;gap:4px">
        <span style="font-size:.75rem;color:var(--text3)">📅 Tanggal:</span>
        <input type="date" name="fstart" value="<?= bersihkan($fStart) ?>" style="background:var(--bg2);border:1px solid var(--border);color:var(--text);padding:6px 8px;border-radius:4px;font-size:.75rem;outline:none"/>
        <span style="font-size:.75rem;color:var(--text3)">s/d</span>
        <input type="date" name="fend" value="<?= bersihkan($fEnd) ?>" style="background:var(--bg2);border:1px solid var(--border);color:var(--text);padding:6px 8px;border-radius:4px;font-size:.75rem;outline:none"/>
    </div>

    <select name="fmode" style="background:var(--bg2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:4px;font-size:.75rem;outline:none">
        <option value="">Semua Mode / DB</option>
        <option value="mineral.db" <?= $fMode==='mineral.db'?'selected':'' ?>>mineral.db (Mineral &amp; Batuan)</option>
        <option value="alloy.db" <?= $fMode==='alloy.db'?'selected':'' ?>>alloy.db (Logam / Alloy)</option>
        <option value="metal.db" <?= $fMode==='metal.db'?'selected':'' ?>>metal.db (Precious Metals)</option>
        <?php if (!empty($workCurves)): ?>
            <optgroup label="Kurva Kerja">
                <?php foreach ($workCurves as $wc): ?>
                    <option value="<?= bersihkan($wc) ?>" <?= $fMode===$wc?'selected':'' ?>><?= bersihkan($wc) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
    </select>

    <input name="fsearch" value="<?= bersihkan($fSearch) ?>" placeholder="🔍 Cari sampel, operator, grade, curve..."
           style="flex:1;min-width:180px;background:var(--bg2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:4px;font-size:.75rem;outline:none"/>

    <button type="submit" class="btn btn-green btn-sm" style="padding:6px 14px">Terapkan Filter</button>
    <a href="<?= BASE_URL ?>/pages/xrf_data.php" class="btn btn-sm" style="background:var(--bg2);color:var(--text2);border:1px solid var(--border);padding:6px 10px">Reset</a>
</form>

<!-- Data Table Card -->
<div class="card" style="margin-bottom:16px">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
        <div>⚡ Daftar Pengukuran XRF Explorer <span style="font-size:.75rem;color:var(--text3);font-weight:normal">(<?= number_format($totalRows) ?> total data)</span></div>
        <div style="font-size:.75rem;color:var(--text3)">Halaman <?= $page ?> dari <?= $totalPages ?></div>
    </div>

    <div style="overflow-x:auto">
        <table class="data-table" style="font-size:.78rem">
            <thead>
                <tr>
                    <th style="width:130px">Waktu Scan</th>
                    <th style="width:140px">Nama Sampel</th>
                    <th style="width:90px">Mode / DB</th>
                    <th style="width:140px">Kurva Kerja</th>
                    <th style="width:100px">Operator</th>
                    <th>Kandungan Unsur (Elemental Analysis)</th>
                    <th style="width:120px">Kondisi Tabung</th>
                    <th style="width:90px;text-align:center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($xrfList as $xrf): 
                    $dbBadge = 'xrf-mode-mineral';
                    if ($xrf['db_source'] === 'alloy.db') $dbBadge = 'xrf-mode-alloy';
                    elseif ($xrf['db_source'] === 'metal.db') $dbBadge = 'xrf-mode-metal';
                ?>
                <tr>
                    <td style="color:var(--text3);font-size:.73rem;white-space:nowrap">
                        <?= $xrf['test_date'] ? date('d/m/Y H:i', strtotime($xrf['test_date'])) : '—' ?>
                    </td>
                    <td>
                        <strong style="color:var(--gold);font-size:.82rem"><?= bersihkan($xrf['sample_name'] ?: 'Tanpa Nama') ?></strong>
                        <div style="font-size:.68rem;color:var(--text3)">ID #<?= (int)$xrf['report_id'] ?: (int)$xrf['id'] ?><?= $xrf['grade'] ? ' · ' . bersihkan($xrf['grade']) : '' ?></div>
                    </td>
                    <td><span class="xrf-badge-mode <?= $dbBadge ?>"><?= bersihkan($xrf['db_source'] ?: '—') ?></span></td>
                    <td>
                        <div><?= bersihkan($xrf['work_curve_name'] ?: 'Default') ?></div>
                        <div style="font-size:.68rem;color:var(--text3)"><?= $xrf['device_id'] ? bersihkan($xrf['device_id']) : 'XRF-7000' ?></div>
                    </td>
                    <td style="font-size:.75rem"><?= bersihkan($xrf['operator'] ?: '—') ?></td>
                    <td>
                        <?php if (!empty($xrf['elements'])): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:4px">
                                <?php foreach (array_slice($xrf['elements'], 0, 6) as $el): ?>
                                    <span class="xrf-element-pill">
                                        <strong><?= bersihkan($el['element_name']) ?></strong>: <?= round((float)$el['concentration'], 3) ?><?= bersihkan($el['unit'] ?: '%') ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (count($xrf['elements']) > 6): ?>
                                    <span style="font-size:.65rem;color:var(--text3);align-self:center">+<?= count($xrf['elements']) - 6 ?> lainnya</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:var(--text3);font-size:.72rem">— Tidak ada unsur —</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.7rem;color:var(--text3)">
                        <div><?= round((float)$xrf['tub_voltage'], 1) ?> kV · <?= round((float)$xrf['tub_current'], 1) ?> µA</div>
                        <div><?= number_format((int)$xrf['cps']) ?> cps · <?= (int)$xrf['test_time'] ?>s</div>
                    </td>
                    <td style="text-align:center;white-space:nowrap">
                        <button type="button" class="btn btn-gold btn-sm" style="font-size:.68rem;padding:3px 8px" onclick="showXrfDetailModal(<?= htmlspecialchars(json_encode($xrf), ENT_QUOTES, 'UTF-8') ?>)">
                            🔍 Detail
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($xrfList)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:32px">Tidak ada data XRF yang sesuai filter.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap">
        <?php 
            $queryParams = $_GET;
            unset($queryParams['page']);
            $baseQuery = '?' . http_build_query($queryParams) . '&page=';
        ?>
        <?php if ($page > 1): ?>
            <a href="<?= $baseQuery . ($page - 1) ?>" class="btn btn-sm" style="background:var(--bg3);border:1px solid var(--border);color:var(--text)">&laquo; Prev</a>
        <?php endif; ?>

        <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
            <a href="<?= $baseQuery . $p ?>" class="btn btn-sm <?= $p===$page?'btn-gold':'' ?>" style="<?= $p!==$page?'background:var(--bg3);border:1px solid var(--border);color:var(--text)':'' ?>">
                <?= $p ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="<?= $baseQuery . ($page + 1) ?>" class="btn btn-sm" style="background:var(--bg3);border:1px solid var(--border);color:var(--text)">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL DETAIL XRF -->
<div id="xrfDetailModal" class="modal-xrf">
    <div class="modal-xrf-content">
        <div class="modal-xrf-header">
            <div style="display:flex;align-items:center;gap:10px">
                <span style="font-size:1.4rem">🔬</span>
                <div>
                    <h3 id="detSampleName" style="margin:0;color:var(--gold);font-size:1.1rem;font-weight:700">Detail Pengukuran XRF</h3>
                    <p id="detDate" style="margin:0;font-size:.74rem;color:var(--text3)"></p>
                </div>
            </div>
            <button type="button" class="modal-edit-close" onclick="closeXrfDetailModal()">&times;</button>
        </div>

        <div style="overflow-y:auto;flex:1;padding-right:4px;">
            <!-- Grid Metadata & Sensor -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:14px;font-size:.75rem">
                <div><span style="color:var(--text3)">Sumber DB:</span> <strong id="detDb" style="color:var(--gold)"></strong></div>
                <div><span style="color:var(--text3)">ID Report:</span> <strong id="detReportId"></strong></div>
                <div><span style="color:var(--text3)">Kurva Kerja:</span> <strong id="detCurve"></strong></div>
                <div><span style="color:var(--text3)">Operator:</span> <strong id="detOperator"></strong></div>
                <div><span style="color:var(--text3)">Tegangan Tabung:</span> <span id="detVoltage"></span></div>
                <div><span style="color:var(--text3)">Arus Tabung:</span> <span id="detCurrent"></span></div>
                <div><span style="color:var(--text3)">Waktu Uji:</span> <span id="detTestTime"></span></div>
                <div><span style="color:var(--text3)">Total CPS:</span> <span id="detCps"></span></div>
                <div><span style="color:var(--text3)">Sensor Suhu:</span> <span id="detTemp"></span></div>
                <div><span style="color:var(--text3)">Sensor Tekanan:</span> <span id="detPressure"></span></div>
                <div><span style="color:var(--text3)">Kelembaban:</span> <span id="detHumidity"></span></div>
                <div><span style="color:var(--text3)">Koordinat GPS:</span> <span id="detGps"></span></div>
            </div>

            <!-- Tabel Unsur Lengkap -->
            <div style="font-weight:600;font-size:.8rem;color:var(--gold);margin-bottom:6px">🧪 Komposisi Unsur Terdeteksi:</div>
            <div style="max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;background:var(--bg3)">
                <table class="data-table" style="font-size:.76rem" id="detElementsTable">
                    <thead>
                        <tr>
                            <th style="width:40px;text-align:center">No</th>
                            <th>Nama Unsur</th>
                            <th>Konsentrasi / Kadar</th>
                            <th>Error (±)</th>
                            <th>Satuan</th>
                        </tr>
                    </thead>
                    <tbody id="detElementsBody"></tbody>
                </table>
            </div>
        </div>

        <div style="margin-top:14px;display:flex;justify-content:flex-end;flex-shrink:0;">
            <button type="button" class="btn btn-sm" onclick="closeXrfDetailModal()" style="background:var(--bg3);color:var(--text2);border:1px solid var(--border)">Tutup</button>
        </div>
    </div>
</div>

<script>
function showXrfDetailModal(data) {
    if (!data) return;
    document.getElementById('detSampleName').textContent = data.sample_name || 'Tanpa Nama';
    document.getElementById('detDate').textContent = (data.test_date ? data.test_date : '') + (data.device_id ? ` · Instrumen: ${data.device_id}` : '');
    document.getElementById('detDb').textContent = data.db_source || '—';
    document.getElementById('detReportId').textContent = '#' + (data.report_id || data.id || '—');
    document.getElementById('detCurve').textContent = data.work_curve_name || '—';
    document.getElementById('detOperator').textContent = data.operator || '—';
    
    document.getElementById('detVoltage').textContent = (data.tub_voltage ? parseFloat(data.tub_voltage).toFixed(1) + ' kV' : '—');
    document.getElementById('detCurrent').textContent = (data.tub_current ? parseFloat(data.tub_current).toFixed(1) + ' µA' : '—');
    document.getElementById('detTestTime').textContent = (data.test_time ? data.test_time + ' s' : '—');
    document.getElementById('detCps').textContent = (data.cps ? Number(data.cps).toLocaleString() + ' cps' : '—');
    
    document.getElementById('detTemp').textContent = (data.temperature !== null && data.temperature !== undefined && data.temperature !== '' ? data.temperature + ' °C' : '—');
    document.getElementById('detPressure').textContent = (data.pressure ? data.pressure + ' hPa' : '—');
    document.getElementById('detHumidity').textContent = (data.humidity ? data.humidity + ' %' : '—');
    
    let gpsText = '—';
    if (data.gps && data.gps !== '(0.0,0.0)' && data.gps !== '(0.0, 0.0)') {
        gpsText = data.gps;
    } else if (data.latitude && data.longitude && (parseFloat(data.latitude) !== 0 || parseFloat(data.longitude) !== 0)) {
        gpsText = `${data.latitude}, ${data.longitude}`;
    }
    document.getElementById('detGps').textContent = gpsText;

    const tbody = document.getElementById('detElementsBody');
    tbody.innerHTML = '';
    if (data.elements && data.elements.length > 0) {
        data.elements.forEach((el, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="text-align:center;color:var(--text3)">${idx + 1}</td>
                <td><strong style="color:var(--gold)">${escapeHtml(el.element_name)}</strong></td>
                <td><strong>${parseFloat(el.concentration).toFixed(4)}</strong></td>
                <td style="color:var(--text3)">${el.element_error ? '± ' + parseFloat(el.element_error).toFixed(4) : '—'}</td>
                <td>${escapeHtml(el.unit || '%')}</td>
            `;
            tbody.appendChild(tr);
        });
    } else {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:var(--text3);padding:18px">Tidak ada unsur yang tersimpan untuk pengukuran ini.</td></tr>`;
    }

    document.getElementById('xrfDetailModal').style.display = 'flex';
}

function closeXrfDetailModal() {
    const m = document.getElementById('xrfDetailModal');
    if (m) m.style.display = 'none';
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('xrfDetailModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeXrfDetailModal();
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
