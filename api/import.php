<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

// ปรับค่าเพื่อให้รองรับไฟล์ขนาดใหญ่ 10,000+ แถว
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');
if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

header('Content-Type: application/json; charset=utf-8');
requireRole(array('admin'));

$db   = Database::getInstance();
$user = currentUser();

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(false, null, 'กรุณาเลือกไฟล์ที่ต้องการนำเข้า', 400);
}

$file = $_FILES['file'];
if ($file['size'] > MAX_UPLOAD_SIZE) {
    jsonResponse(false, null, 'ขนาดไฟล์เกินขีดจำกัดที่ตั้งไว้', 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, array('csv', 'xlsx', 'xls'))) {
    jsonResponse(false, null, 'รองรับเฉพาะไฟล์ .csv หรือ .xlsx เท่านั้น', 400);
}

// --- Functions สำหรับการอ่านไฟล์ (รองรับ PHP 7+) ---

function parseCsvFile($path)
{
    $rows = array();
    if (($fh = fopen($path, 'r')) === false) return $rows;
    while (($row = fgetcsv($fh)) !== false) {
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function parseDate($val)
{
    $val = trim((string)$val);
    if ($val === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
    if (preg_match('/^(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{4})$/', $val, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (is_numeric($val)) {
        $ts = ($val - 25569) * 86400;
        return date('Y-m-d', (int)$ts);
    }
    $ts = strtotime($val);
    return $ts ? date('Y-m-d', $ts) : null;
}

function normalizeStatus($val)
{
    $map = array(
        'ใช้งาน' => 'active',
        'ชำรุด-ส่งคืน' => 'returned',
        'ไม่พบ' => 'not_found',
        'ไม่ใช้งาน' => 'inactive',
        'ชำรุด-รอซ่อม' => 'repairing',
        'active' => 'active'
    );
    $v = trim((string)$val);
    return isset($map[$v]) ? $map[$v] : 'active';
}

function excelColumnIndex($column)
{
    $column = strtoupper((string)$column);
    $index = 0;
    for ($i = 0; $i < strlen($column); $i++) {
        $index = $index * 26 + (ord($column[$i]) - 64);
    }
    return $index - 1;
}

function parseXlsxFile($path)
{
    $rows = array();
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $rows;

    $sharedStrings = array();
    $ssData = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssData) {
        $sxml = simplexml_load_string($ssData);
        foreach ($sxml->si as $si) {
            $sharedStrings[] = isset($si->t) ? (string)$si->t : (string)$si->r->t;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return $rows;

    $sheet = simplexml_load_string($sheetXml);
    foreach ($sheet->sheetData->row as $row) {
        $cells = array();
        foreach ($row->c as $c) {
            $col = preg_replace('/\d+$/', '', (string)$c['r']);
            $index = excelColumnIndex($col);
            $val = (string)$c->v;
            if ((string)$c['t'] === 's') $val = isset($sharedStrings[(int)$val]) ? $sharedStrings[(int)$val] : '';
            $cells[$index] = $val;
        }
        if (!empty($cells)) $rows[] = $cells;
    }
    return $rows;
}

// --- ฟังก์ชัน Bulk Insert โดยใช้ PDO มาตรฐาน (รองรับ PHP 7+) ---

function bulkInsertAssets($db, $pendingInserts, $batchSize = 500)
{
    if (empty($pendingInserts)) return array('inserted' => 0, 'errors' => array());

    $columns = array(
        'plant_code',
        'class_code',
        'asset_no',
        'asset_description',
        'cap_date',
        'acquis_val',
        'book_val',
        'cost_center',
        'department_code',
        'department_name',
        'municipality',
        'location',
        'serial_no',
        'brand',
        'model',
        'status',
        'qr_code',
        'asset_image',
        'remark',
        'created_by'
    );

    $inserted = 0;
    $errors   = array();
    $pdo      = $db->getConnection(); // ดึง PDO Instance มาใช้โดยตรง

    foreach (array_chunk($pendingInserts, $batchSize) as $batch) {
        $placeholders = array();
        $values = array();
        foreach ($batch as $item) {
            $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            foreach ($columns as $col) {
                $values[] = isset($item['data'][$col]) ? $item['data'][$col] : null;
            }
        }

        $sql = "INSERT INTO assets (" . implode(',', $columns) . ") VALUES " . implode(',', $placeholders);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            $inserted += count($batch);
        } catch (Exception $e) {
            // หาก Bulk พัง ให้ลอง Insert ทีละแถวเพื่อเก็บ Error รายบรรทัด
            foreach ($batch as $item) {
                try {
                    $db->insert('assets', $item['data']);
                    $inserted++;
                } catch (Exception $ex) {
                    $errors[] = $item['rowLabel'] . ': ' . $ex->getMessage();
                }
            }
        }
    }
    return array('inserted' => $inserted, 'errors' => $errors);
}

// --- เริ่มกระบวนการ Import ---

$rows = ($ext === 'csv') ? parseCsvFile($file['tmp_name']) : parseXlsxFile($file['tmp_name']);
if (count($rows) < 2) jsonResponse(false, null, 'ไฟล์ไม่มีข้อมูล', 400);

// เตรียม Header
$headerRow = array();
foreach ($rows[0] as $v) {
    $headerRow[] = trim(strtolower((string)$v));
}

$mode = in_array(isset($_POST['duplicate_mode']) ? $_POST['duplicate_mode'] : '', array('update', 'skip', 'new_only'))
    ? $_POST['duplicate_mode'] : 'update';

$dataRows = array_slice($rows, 1);

// Pre-fetch เพื่อเช็ค Asset No ที่มีอยู่แล้ว
$existingMap = array();
$allAssetNos = array();
$assetNoIdx = array_search('asset_no', $headerRow);

if ($assetNoIdx !== false) {
    foreach ($dataRows as $r) {
        if (!empty($r[$assetNoIdx])) $allAssetNos[] = trim((string)$r[$assetNoIdx]);
    }
}

if (!empty($allAssetNos)) {
    $ph = implode(',', array_fill(0, count($allAssetNos), '?'));
    // ดึง plant_code มาด้วยเพื่อความแม่นยำ
    $res = $db->fetchAll("SELECT id, asset_no, plant_code,cap_date FROM assets WHERE asset_no IN ($ph)", $allAssetNos);
    if ($res) {
        foreach ($res as $r) {
            // สร้าง Key คู่ระหว่าง Plant + Asset No
            $compositeKey = $r['plant_code'] . '_' . $r['asset_no'] . '_' . $r['cap_date'];
            $existingMap[$compositeKey] = $r['id'];
        }
    }
}

$successRows = 0;
$skippedRows = 0;
$errorRows = 0;
$errors = array();
$pendingInserts = array();
$countBefore = (int)$db->fetchOne("SELECT COUNT(*) as c FROM assets")['c'];

// ใช้ Transaction คลุมการทำงานทั้งหมดเพื่อความเร็วและความปลอดภัยของข้อมูล
$pdo = $db->getConnection();
$pdo->beginTransaction();

try {
    foreach ($dataRows as $rowIndex => $rowData) {
        $row = array();
        foreach ($headerRow as $ci => $cn) {
            if ($cn !== '') $row[$cn] = isset($rowData[$ci]) ? trim((string)$rowData[$ci]) : '';
        }

        // ข้ามแถวว่าง
        if (empty(array_filter($row))) continue;

        if (empty($row['plant_code']) || empty($row['asset_no'])) {
            $errorRows++;
            $errors[] = "แถว " . ($rowIndex + 2) . ": ขาดข้อมูลหลัก";
            continue;
        }

        $assetNo = $row['asset_no'];
        $plantCode = $row['plant_code'];
        $capDate = parseDate(isset($row['cap_date']) ? $row['cap_date'] : '');
        $acquis_val = (float)str_replace(',', '', isset($row['acquis_val']) ? $row['acquis_val'] : 0);
        $currentKey = $plantCode . '_' . $assetNo . '_' . $capDate.'_'.$acquis_val;

        $existsId = isset($existingMap[$currentKey]) ? $existingMap[$currentKey] : null;

        if ($existsId && ($mode === 'skip' || $mode === 'new_only')) {
            $skippedRows++;
            continue;
        }

        $data = array(
            'plant_code'        => $row['plant_code'],
            'class_code'        => isset($row['class_code']) ? $row['class_code'] : '',
            'asset_no'          => $assetNo,
            'asset_description' => isset($row['asset_description']) ? $row['asset_description'] : '',
            'cap_date'          => parseDate(isset($row['cap_date']) ? $row['cap_date'] : ''),
            'acquis_val'        => (float)str_replace(',', '', isset($row['acquis_val']) ? $row['acquis_val'] : 0),
            'book_val'          => (float)str_replace(',', '', isset($row['book_val']) ? $row['book_val'] : 0),
            'cost_center'       => isset($row['cost_center']) ? $row['cost_center'] : '',
            'department_code'   => isset($row['department_code']) ? $row['department_code'] : '',
            'department_name'   => isset($row['department_name']) ? $row['department_name'] : '',
            'municipality'      => isset($row['municipality']) ? $row['municipality'] : '',
            'location'          => isset($row['location']) ? $row['location'] : '',
            'serial_no'         => isset($row['serial_no']) ? $row['serial_no'] : '',
            'brand'             => isset($row['brand']) ? $row['brand'] : '',
            'model'             => isset($row['model']) ? $row['model'] : '',
            'status'            => normalizeStatus(isset($row['status']) ? $row['status'] : ''),
            'qr_code'           => isset($row['qr_code']) ? $row['qr_code'] : '',
            'asset_image'       => isset($row['asset_image']) ? $row['asset_image'] : '',
            'remark'            => isset($row['remark']) ? $row['remark'] : '',
            'updated_at'        => date('Y-m-d H:i:s')
        );

        if ($existsId) {
            $db->update('assets', $data, 'id = :id', array('id' => $existsId));
            $successRows++;
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['created_by'] = $user['id'];
            $pendingInserts[] = array('data' => $data, 'rowLabel' => 'แถว ' . ($rowIndex + 2));
        }
    }

    // ทำ Bulk Insert สำหรับข้อมูลใหม่
    if (!empty($pendingInserts)) {
        $bulk = bulkInsertAssets($db, $pendingInserts, 500);
        $successRows += $bulk['inserted'];
        $errorRows   += count($bulk['errors']);
        $errors       = array_merge($errors, $bulk['errors']);
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false, null, 'เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
}

$countAfter = (int)$db->fetchOne("SELECT COUNT(*) as c FROM assets")['c'];
$actualNewRecords = $countAfter - $countBefore;

// บันทึก Log การนำเข้า
$db->insert('import_logs', array(
    'filename'           => sanitize($file['name']),
    'total_rows'         => count($dataRows),
    'success_rows'       => $successRows,
    'error_rows'         => $errorRows,
    'skipped_rows'       => $skippedRows,
    'actual_new_records' => $actualNewRecords,
    'status'             => 'completed',
    'imported_by'        => $user['id'],
    'error_detail'       => json_encode($errors, JSON_UNESCAPED_UNICODE)
));

// ส่ง Response กลับ (ตามโครงสร้างเดิมที่คุณต้องการ)
jsonResponse(true, array(
    'success_rows'        => $successRows,
    'error_rows'          => $errorRows,
    'skipped_rows'        => $skippedRows,
    'count_before_import' => $countBefore,
    'count_after_import'  => $countAfter,
    'actual_new_records'  => $actualNewRecords,
    'mode'                => $mode
), 'นำเข้าข้อมูลเสร็จสิ้น');
