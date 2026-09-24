<?php
/**
 * AJAX Endpoint: Get XRF Measurements with Dynamic Filtering
 * Path: /actions/get_xrf_measurements.php
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir, silakan login kembali.']);
    exit;
}

try {
    $startDate = trim($_GET['start_date'] ?? '');
    $endDate   = trim($_GET['end_date'] ?? '');
    $search    = trim($_GET['search'] ?? '');
    $mode      = trim($_GET['mode'] ?? '');
    $limit     = min(200, max(10, (int)($_GET['limit'] ?? 100)));

    $sql = "
        SELECT id, device_id, db_source, report_id, sample_name, sample_supplier,
               test_date, timestamp_ms, work_curve_name, grade, operator
        FROM xrf_measurements
        WHERE 1=1
    ";
    $params = [];

    // 1. Date Range Filter
    if ($startDate !== '') {
        $sql .= " AND DATE(test_date) >= ?";
        $params[] = $startDate;
    }
    if ($endDate !== '') {
        $sql .= " AND DATE(test_date) <= ?";
        $params[] = $endDate;
    }

    // 2. Search Filter (Sample Name, Report ID, Operator, Grade, Work Curve)
    if ($search !== '') {
        $sql .= " AND (sample_name LIKE ? OR report_id LIKE ? OR operator LIKE ? OR grade LIKE ? OR work_curve_name LIKE ?)";
        $wildcard = "%$search%";
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
    }

    // 3. Mode / Database Source / Work Curve Filter
    if ($mode !== '' && $mode !== 'all') {
        if (str_ends_with($mode, '.db')) {
            $sql .= " AND db_source = ?";
            $params[] = $mode;
        } else {
            $sql .= " AND work_curve_name = ?";
            $params[] = $mode;
        }
    }

    $sql .= " ORDER BY timestamp_ms DESC, id DESC LIMIT $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch elements for each measurement
    $data = [];
    if (!empty($rows)) {
        $measurementIds = array_column($rows, 'id');
        $inPlaceholders = implode(',', array_fill(0, count($measurementIds), '?'));
        
        $elStmt = $pdo->prepare("
            SELECT measurement_id, element_name, concentration, element_error, unit
            FROM xrf_measurement_elements
            WHERE measurement_id IN ($inPlaceholders)
            ORDER BY concentration DESC
        ");
        $elStmt->execute($measurementIds);
        $allElements = $elStmt->fetchAll(PDO::FETCH_ASSOC);

        $elementsByMeasurement = [];
        foreach ($allElements as $el) {
            $elementsByMeasurement[$el['measurement_id']][] = [
                'element_name'  => $el['element_name'],
                'concentration' => (float)$el['concentration'],
                'error'         => (float)$el['element_error'],
                'unit'          => $el['unit'] ?: '%'
            ];
        }

        foreach ($rows as $r) {
            $mId = $r['id'];
            $els = $elementsByMeasurement[$mId] ?? [];

            // Summary string for quick glance
            $summaryArr = [];
            foreach (array_slice($els, 0, 4) as $e) {
                $summaryArr[] = $e['element_name'] . ': ' . round($e['concentration'], 2) . $e['unit'];
            }
            $summaryStr = !empty($summaryArr) ? implode(', ', $summaryArr) : '—';
            if (count($els) > 4) {
                $summaryStr .= ' (+' . (count($els) - 4) . ' lainnya)';
            }

            $data[] = [
                'id'               => (int)$r['id'],
                'device_id'        => $r['device_id'],
                'db_source'        => $r['db_source'],
                'report_id'        => (int)$r['report_id'],
                'sample_name'      => $r['sample_name'] ?: 'Tanpa Nama',
                'sample_supplier'  => $r['sample_supplier'],
                'test_date'        => $r['test_date'],
                'formatted_date'   => $r['test_date'] ? date('d/m/Y H:i', strtotime($r['test_date'])) : '—',
                'timestamp_ms'     => $r['timestamp_ms'],
                'work_curve_name'  => $r['work_curve_name'] ?: 'Default',
                'grade'            => $r['grade'] ?: '—',
                'operator'         => $r['operator'] ?: '—',
                'elements'         => $els,
                'elements_summary' => $summaryStr,
                'elements_count'   => count($els)
            ];
        }
    }

    // Get available modes / curves for dynamic dropdown options
    $availableDbSources = $pdo->query("SELECT DISTINCT db_source FROM xrf_measurements WHERE db_source IS NOT NULL AND db_source != '' ORDER BY db_source")->fetchAll(PDO::FETCH_COLUMN);
    $availableCurves = $pdo->query("SELECT DISTINCT work_curve_name FROM xrf_measurements WHERE work_curve_name IS NOT NULL AND work_curve_name != '' AND work_curve_name != '-' ORDER BY work_curve_name")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success'      => true,
        'count'        => count($data),
        'db_sources'   => $availableDbSources,
        'work_curves'  => $availableCurves,
        'data'         => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal mengambil data XRF: ' . $e->getMessage()
    ]);
}
