<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();

$db = Database::getInstance();

$type  = $_GET['type'] ?? 'summary';
$plant = $_GET['plant'] ?? '';
$dept  = $_GET['dept'] ?? '';
$session_id = $_GET['session_id'] ?? ''; // ระบุรอบการตรวจนับ

// 1. กรณีรายงานสรุป (Summary) ตามรูป image_234c08.png
if ($type === 'summary') {
    $title = "รายงานสรุปการตรวจนับทรัพย์สิน";
    $query = "
        SELECT
            a.department_code,
            a.department_name,
            COUNT(a.id) as total_assets,
            SUM(CASE WHEN ir.check_status = 'found' THEN 1 ELSE 0 END) as count_found,
            SUM(CASE WHEN ir.check_status = 'damaged' THEN 1 ELSE 0 END) as count_damaged,
            SUM(CASE WHEN ir.check_status = 'not_found' THEN 1 ELSE 0 END) as count_notfound,
            SUM(CASE WHEN ir.id IS NULL THEN 1 ELSE 0 END) as count_pending
        FROM assets a
        LEFT JOIN inventory_results ir ON a.id = ir.asset_id AND (ir.session_id = ? OR ? = '')
        WHERE (a.plant_code = ? OR ? = '')
        GROUP BY a.department_code, a.department_name
    ";
    $data = $db->fetchAll($query, [$session_id, $session_id, $plant, $plant]);
}
// 2. กรณีใบตรวจนับ/รายการละเอียด ตามรูป image_234c46.png และ image_234c26.png
else {
    $title = ($type === 'checklist') ? "ใบรายงานการตรวจนับทรัพย์สิน" : "รายงานรายละเอียดทรัพย์สิน";
    $query = "
        SELECT a.*, ir.check_status, ir.remarks as audit_remark, ir.checked_at
        FROM assets a
        LEFT JOIN inventory_results ir ON a.id = ir.asset_id AND (ir.session_id = ? OR ? = '')
        WHERE (a.plant_code = ? OR ? = '') AND (a.department_code = ? OR ? = '')
        ORDER BY a.department_code, a.asset_no
    ";
    $data = $db->fetchAll($query, [$session_id, $session_id, $plant, $plant, $dept, $dept]);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
    <style>
        @font-face { font-family: 'Sarabun'; src: url('https://fonts.gstatic.com/s/sarabun/v12/dt8m692S_M4n97v_pY_m.ttf'); }
        body { font-family: 'Sarabun', sans-serif; font-size: 11px; line-height: 1.4; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid black; padding: 4px; }
        th { background: #f2f2f2; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .no-print { margin-bottom: 20px; }
        .sig-table { width: 100%; margin-top: 30px; border: none; }
        .sig-table td { border: none; text-align: center; width: 50%; padding-top: 40px; }
        @media print { .no-print { display: none; } @page { size: landscape; margin: 1cm; } }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">พิมพ์รายงาน</button>
    <button onclick="window.close()">ปิด</button>
</div>

<div class="text-center">
    <h2 style="margin:0;"><?= $title ?></h2>
    <p>โรงงาน: <?= $plant ?: 'ทั้งหมด' ?> | แผนก: <?= $dept ?: 'ทั้งหมด' ?></p>
</div>

<table>
    <?php if ($type === 'summary'): ?>
        <thead>
            <tr>
                <th>รหัสแผนก</th>
                <th>ชื่อแผนก</th>
                <th>จำนวนทั้งหมด</th>
                <th>พบ/ปกติ</th>
                <th>ชำรุด</th>
                <th>ไม่พบ</th>
                <th>รอตรวจ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $r): ?>
            <tr>
                <td class="text-center"><?= $r['department_code'] ?></td>
                <td><?= $r['department_name'] ?></td>
                <td class="text-right"><?= number_format($r['total_assets']) ?></td>
                <td class="text-right"><?= number_format($r['count_found']) ?></td>
                <td class="text-right"><?= number_format($r['count_damaged']) ?></td>
                <td class="text-right"><?= number_format($r['count_notfound']) ?></td>
                <td class="text-right"><?= number_format($r['count_pending']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    <?php else: ?>
        <thead>
            <tr>
                <th>ลำดับ</th>
                <th>Asset No.</th>
                <th>Description</th>
                <th>Cap.Date</th>
                <th class="text-right">Acquis.Val</th>
                <th>Cost Center</th>
                <th>ผลการตรวจ</th>
                <th>หมายเหตุ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $i => $r): ?>
            <tr>
                <td class="text-center"><?= $i+1 ?></td>
                <td><?= $r['asset_no'] ?></td>
                <td><?= $r['asset_description'] ?></td>
                <td class="text-center"><?= $r['cap_date'] ?></td>
                <td class="text-right"><?= number_format($r['acquis_val'], 2) ?></td>
                <td class="text-center"><?= $r['cost_center'] ?></td>
                <td class="text-center">
                    <?php
                        if($r['check_status'] == 'found') echo 'พบ';
                        elseif($r['check_status'] == 'damaged') echo 'ชำรุด';
                        elseif($r['check_status'] == 'not_found') echo 'ไม่พบ';
                        else echo '-';
                    ?>
                </td>
                <td><?= $r['audit_remark'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    <?php endif; ?>
</table>

<table class="sig-table">
    <tr>
        <td>
            ผู้ร่วมตรวจนับ..........................................<br>
            (....................................................)<br>
            บัญชีทรัพย์สิน
        </td>
        <td>
            ผู้ร่วมตรวจนับ..........................................<br>
            (....................................................)<br>
            หัวหน้ากะ/หัวหน้าแผนก
        </td>
    </tr>
</table>

</body>
</html>