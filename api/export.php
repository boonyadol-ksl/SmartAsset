<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

requireLogin();

// Check if this is a valid export request
$type = trim($_POST['type'] ?? $_GET['type'] ?? '');
$export_all = trim($_POST['export_all'] ?? '');

// Allow export either via explicit type=assets OR via export_all flag from asset-list
if ($type !== 'assets' && $export_all !== '1') {
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
if ($municipality !== '') { $where[] = 'municipality LIKE ?'; $params[] = "%$municipality%"; }

$whereStr = implode(' AND ', $where);

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