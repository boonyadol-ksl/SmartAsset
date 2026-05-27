<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

requireLogin();

// Check if this is a valid export request
$type = trim($_POST['type'] ?? $_GET['type'] ?? '');
$export_all = trim($_POST['export_all'] ?? '');

$section = trim($_POST['section'] ?? $_GET['section'] ?? '');

// Allow export either via explicit type=assets/report OR via export_all flag from asset-list
if ($type !== 'assets' && $type !== 'report' && $export_all !== '1') {
    http_response_code(400);
    echo 'Invalid export request';
    exit;
}

$db = Database::getInstance();

// Build WHERE clause based on filters passed from asset-list page
$where = ['1=1'];
$params = [];

// Get filters from POST or GET
$search = trim($_POST['search'] ?? $_GET['search'] ?? '');
$plant = trim($_POST['plant'] ?? $_GET['plant'] ?? '');
$costCenter = trim($_POST['cost_center'] ?? $_GET['cost_center'] ?? '');
$dept = trim($_POST['dept'] ?? $_GET['dept'] ?? '');
$status = trim($_POST['status'] ?? $_GET['status'] ?? '');
$municipality = trim($_POST['municipality'] ?? $_GET['municipality'] ?? '');
$auditYear = intval($_POST['audit_year'] ?? $_GET['audit_year'] ?? 0);
$yearSpan = max(0, min(10, intval($_POST['year_span'] ?? $_GET['year_span'] ?? 0)));

if ($search !== '') {
    $where[] = '(asset_no LIKE ? OR asset_description LIKE ? OR serial_no LIKE ? OR municipality LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($plant !== '') { $where[] = 'plant_code = ?'; $params[] = $plant; }
if ($costCenter !== '') { $where[] = 'cost_center = ?'; $params[] = $costCenter; }
if ($dept !== '') { $where[] = 'department_code = ?'; $params[] = $dept; }

// Handle status mapping for export
// DB stores English values (active, inactive, repairing, not_found, returned)
// Filters from asset-list.php send English values; support Thai input as well (e.g. from direct URL)
if ($status !== '') {
    $thai_to_db = [
        'ใช้งาน'      => 'active',
        'ไม่ใช้งาน'   => 'inactive',
        'ชำรุด-ส่งคืน' => 'returned',
        'ชำรุด-รอซ่อม' => 'repairing',
        'ไม่พบ'        => 'not_found',
    ];
    // If it's a Thai label, convert to DB value; otherwise use as-is (already English)
    $db_status = $thai_to_db[$status] ?? $status;
    $where[] = 'status = ?';
    $params[] = $db_status;
}
if ($auditYear > 0) {
    $yearStart = $auditYear - $yearSpan;
    $yearEnd = $auditYear;
    $where[] = 'YEAR(cap_date) BETWEEN ? AND ?';
    $params[] = $yearStart;
    $params[] = $yearEnd;
}
if ($municipality !== '') { $where[] = 'municipality LIKE ?'; $params[] = "%$municipality%"; }

$whereStr = implode(' AND ', $where);

// If exporting report summaries, build CSV from distilled report data
if ($type === 'report') {
    $validSections = ['dept', 'class', 'monthly', 'all'];
    if (!in_array($section, $validSections, true)) {
        http_response_code(400);
        echo 'Invalid report export section';
        exit;
    }

    $filename = 'report_export_' . $section . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    $yearCondition = '';
    $yearParams = [];
    if ($auditYear > 0) {
        $yearStart = $auditYear - $yearSpan;
        $yearEnd = $auditYear;
        $yearCondition = ' AND YEAR(cap_date) BETWEEN ? AND ?';
        $yearParams = [$yearStart, $yearEnd];
    }
    $plantCondition = $plant !== '' ? ' AND plant_code = ?' : '';
    $plantParams = $plant !== '' ? [$plant] : [];

    if ($section === 'dept' || $section === 'all') {
        fputcsv($out, ['Department Summary']);
        fputcsv($out, ['department_code', 'department_name', 'total', 'active_count', 'returned_count', 'repairing_count', 'not_found_count', 'inactive_count', 'value_sum']);
        $deptRows = $db->fetchAll(
            "SELECT a.department_code, a.department_name, COUNT(*) as total, " .
            "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"active\"%' THEN 1 ELSE 0 END) AS active_count, " .
            "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"returned\"%' THEN 1 ELSE 0 END) AS returned_count, " .
            "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"repairing\"%' THEN 1 ELSE 0 END) AS repairing_count, " .
            "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"not_found\"%' THEN 1 ELSE 0 END) AS not_found_count, " .
            "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"inactive\"%' THEN 1 ELSE 0 END) AS inactive_count, " .
            "IFNULL(SUM(a.acquis_val),0) as value_sum " .
            "FROM assets a " .
            "LEFT JOIN (" .
            "    SELECT aa1.asset_id, aa1.remark " .
            "    FROM audit_assignments aa1 " .
            "    WHERE aa1.status = 'completed' " .
            "      AND aa1.id = (" .
            "          SELECT MAX(aa2.id) " .
            "          FROM audit_assignments aa2 " .
            "          WHERE aa2.asset_id = aa1.asset_id " .
            "            AND aa2.status = 'completed'" .
            "      )" .
            ") aa ON aa.asset_id = a.id " .
            "WHERE 1=1" . $plantCondition . $yearCondition .
            " GROUP BY a.department_code, a.department_name ORDER BY total DESC",
            array_merge($plantParams, $yearParams)
        );
        foreach ($deptRows as $row) {
            fputcsv($out, [$row['department_code'], $row['department_name'], $row['total'], $row['active_count'], $row['returned_count'], $row['repairing_count'], $row['not_found_count'], $row['inactive_count'], $row['value_sum']]);
        }
        if ($section !== 'all') { fclose($out); exit; }
        fputcsv($out, []);
    }

    if ($section === 'class' || $section === 'all') {
        fputcsv($out, ['Class Summary']);
        fputcsv($out, ['class_code', 'class_name', 'count', 'value_sum']);
        $classRows = $db->fetchAll(
            "SELECT c.class_code, c.class_name, COUNT(a.id) as count, IFNULL(SUM(a.acquis_val),0) as value_sum " .
            "FROM assets a LEFT JOIN asset_classes c ON a.class_code=c.class_code " .
            "WHERE a.status='active'" . $plantCondition . $yearCondition .
            " GROUP BY c.class_code, c.class_name ORDER BY count DESC",
            array_merge($plantParams, $yearParams)
        );
        foreach ($classRows as $row) {
            fputcsv($out, [$row['class_code'], $row['class_name'], $row['count'], $row['value_sum']]);
        }
        if ($section !== 'all') { fclose($out); exit; }
        fputcsv($out, []);
    }

    if ($section === 'monthly' || $section === 'all') {
        fputcsv($out, ['Monthly Acquisition Summary']);
        fputcsv($out, ['month', 'count', 'value_sum']);
        $monthlyRows = $db->fetchAll(
            "SELECT DATE_FORMAT(cap_date,'%Y-%m') as month, COUNT(*) as count, SUM(acquis_val) as value_sum " .
            "FROM assets WHERE cap_date IS NOT NULL" . $plantCondition . $yearCondition .
            " GROUP BY month ORDER BY month ASC",
            array_merge($plantParams, $yearParams)
        );
        foreach ($monthlyRows as $row) {
            fputcsv($out, [$row['month'], $row['count'], $row['value_sum']]);
        }
        fclose($out);
        exit;
    }
}

// Fetch assets based on filters
$assets = $db->fetchAll("SELECT * FROM assets WHERE $whereStr ORDER BY id ASC", $params);

$filename = 'assets_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$columns = [
    'plant_code', 'class_code', 'asset_no', 'asset_description', 'cap_date',
    'acquis_val', 'book_val', 'cost_center', 'department_code', 'department_name',
    'municipality', 'location', 'serial_no', 'brand', 'model', 'status',
    'qr_code', 'asset_image', 'remark', 'created_by', 'updated_by', 'created_at', 'updated_at'
];

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");
fputcsv($out, $columns);
foreach ($assets as $asset) {
    $row = [];
    foreach ($columns as $col) {
        if($col == 'status') {
            $status_map = [
                'active' => 'ใช้งาน',
                'inactive' => 'ไม่ใช้งาน',
                'repairing' => 'ชำรุด-รอซ่อม',
                'not_found' => 'ไม่พบ',
                'returned' => 'ชำรุด-ส่งคืน',
            ];
            $row[] = $status_map[$asset[$col]] ?? $asset[$col] ?? '';
        } else {
            $row[] = $asset[$col] ?? '';
        }
    }
    fputcsv($out, $row);
}
fclose($out);
exit;