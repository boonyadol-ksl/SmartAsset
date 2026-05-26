<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

requireLogin();

$filename = 'asset_import_template.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$columns = [
    'plant_code',
    'asset_no',
    'class_code',
    'asset_description',
    'cap_date',
    'acquis_val',
    'cost_center',
    'department_code',
    'department_name',
    'municipality',
    'serial_no',
    'brand',
    'model',
    'status',
    'remark',
];

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, $columns);
fputcsv($output, ['1003', 'AAMF000100', 'IT', 'Laptop HP ProBook', '2024-01-15', '25000', 'CC-IT-001', 'IT', 'IT Department', 'นายวิชัย มั่นคง', 'SN123456', 'HP', 'ProBook 450', 'active', '']);
fflush($output);
fclose($output);
exit;
