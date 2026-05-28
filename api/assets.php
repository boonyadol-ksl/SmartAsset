<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    jsonResponse(false, null, 'Unauthorized', 401);
}

$db     = Database::getInstance();
$user   = currentUser();
$method = $_SERVER['REQUEST_METHOD'];
$userSiteCode = isset($user['site_code']) ? $user['site_code'] : null;

// Parse input
$input  = [];
$isJson = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
if ($isJson) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} elseif ($method === 'POST') {
    $input = $_POST;
}

$action = $input['action'] ?? ($_GET['action'] ?? '');

// --- GET by Asset No (สำหรับรองรับการ Scan QR) ---
if ($method === 'GET' && isset($_GET['asset_no'])) {
    $assetNo = trim($_GET['asset_no']);
    // ค้นหาแบบระบุเลขตรงๆ เพื่อความแม่นยำ
    $params = [$assetNo];
    $sql = "SELECT * FROM assets WHERE asset_no = ?";
    // If user has a site_code (webadmin), restrict to that site. Admins may pass site_code param to query across sites.
    if (!empty($userSiteCode)) {
        $sql .= " AND site_code = ?";
        $params[] = $userSiteCode;
    } elseif (!empty($_GET['site_code'])) {
        $sql .= " AND site_code = ?";
        $params[] = trim($_GET['site_code']);
    }
    $asset = $db->fetchAll($sql, $params);

    if (!$asset) {
        jsonResponse(false, null, 'ไม่พบรหัสทรัพย์สินนี้', 404);
    }
    jsonResponse(true, $asset);
    exit; // สำคัญ: ต้อง exit เพื่อไม่ให้ไหลไปทำงานส่วน GET list
}

// ─── Get Cost Centers ───────────────────────────────────────────────────────────
if ($action === 'get_cost_centers') {
    $plantCode = trim($_GET['plant_code'] ?? '');
    $siteCode = trim($_GET['site_code'] ?? $userSiteCode);

    $params = [];
    $where = "WHERE cost_center IS NOT NULL AND cost_center != ''";
    if ($plantCode) {
        $where .= " AND plant_code = ?";
        $params[] = $plantCode;
    }
    if (!empty($siteCode)) {
        $where .= " AND site_code = ?";
        $params[] = $siteCode;
    }
    $costCenters = $db->fetchAll("SELECT DISTINCT cost_center FROM assets $where ORDER BY cost_center", $params);

    jsonResponse(true, $costCenters, 'Success');
}

// ─── Get Departments ────────────────────────────────────────────────────────────
if ($action === 'get_departments') {
    $plantCode = trim($_GET['plant_code'] ?? '');
    $costCenter = trim($_GET['cost_center'] ?? '');
    $siteCode = trim($_GET['site_code'] ?? $userSiteCode);

    $params = [];
    $whereClause = "department_name IS NOT NULL ";

    if ($plantCode && $costCenter) {
        $whereClause .= "AND plant_code = ? AND cost_center = ?";
        $params = [$plantCode, $costCenter];
    } else if ($plantCode) {
        $whereClause .= "AND plant_code = ?";
        $params = [$plantCode];
    }
    if (!empty($siteCode)) {
        $whereClause .= " AND site_code = ?";
        $params[] = $siteCode;
    }

    $departments = $db->fetchAll(
        "SELECT DISTINCT department_code, department_name
         FROM assets
         WHERE $whereClause
         ORDER BY department_name",
        $params
    );

    jsonResponse(true, $departments, 'Success');
}

// ─── Upload Image ─────────────────────────────────────────────────────────────
if ($action === 'upload_image') {
    requireRole(['admin','webadmin']);
    $id = intval($input['id'] ?? 0);
    if (!$id || !isset($_FILES['image'])) {
        jsonResponse(false, null, 'Missing data');
    }

    $file = $_FILES['image'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_IMAGES)) jsonResponse(false, null, 'ไฟล์รูปภาพไม่ถูกต้อง');
    if ($file['size'] > MAX_UPLOAD_SIZE)  jsonResponse(false, null, 'ไฟล์ขนาดเกิน ' . (MAX_UPLOAD_SIZE/1024/1024) . 'MB');

    // Max 10 images per asset (excluding QR code images)
    $count = $db->fetchOne('SELECT COUNT(*) as c FROM asset_images WHERE asset_id = ? AND is_primary != 2', [$id])['c'];
    if ($count >= 10) jsonResponse(false, null, 'อัปโหลดได้สูงสุด 10 รูปต่อทรัพย์สิน');

    $dir = UPLOAD_PATH . 'assets/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'asset_' . $id . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        jsonResponse(false, null, 'อัปโหลดล้มเหลว');
    }

    $isPrimary = ($count === 0) ? 1 : 0; // Set first image as primary
    $imgId = $db->insert('asset_images', [
        'asset_id'    => $id,
        'filename'    => 'assets/' . $filename,
        'is_primary'  => $isPrimary,
        'uploaded_by' => $user['id'],
    ]);

    // Keep asset_image in sync with primary
    if ($isPrimary) {
        $db->update('assets', ['asset_image' => 'assets/' . $filename], 'id=:id', ['id' => $id]);
    }

    jsonResponse(true, ['id' => $imgId, 'filename' => 'assets/' . $filename, 'is_primary' => $isPrimary], 'อัปโหลดสำเร็จ');
}

// ─── Upload QR Code Image ─────────────────────────────────────────────────────
if ($action === 'upload_qr_image') {
    requireRole(['admin','webadmin']);
    $id = intval($input['id'] ?? 0);
    if (!$id || !isset($_FILES['image'])) {
        jsonResponse(false, null, 'Missing data');
    }

    $file = $_FILES['image'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_IMAGES)) jsonResponse(false, null, 'ไฟล์รูปภาพไม่ถูกต้อง');
    if ($file['size'] > MAX_UPLOAD_SIZE)  jsonResponse(false, null, 'ไฟล์ขนาดเกิน ' . (MAX_UPLOAD_SIZE/1024/1024) . 'MB');

    // Max 5 QR code images per asset
    $count = $db->fetchOne('SELECT COUNT(*) as c FROM asset_images WHERE asset_id = ? AND is_primary = 2', [$id])['c'];
    if ($count >= 5) jsonResponse(false, null, 'อัปโหลด QR Code ได้สูงสุด 5 รูปต่อทรัพย์สิน');

    $dir = UPLOAD_PATH . 'qr_codes/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'qr_' . $id . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        jsonResponse(false, null, 'อัปโหลดล้มเหลว');
    }

    $imgId = $db->insert('asset_images', [
        'asset_id'    => $id,
        'filename'    => 'qr_codes/' . $filename,
        'is_primary'  => 2, // Special value for QR code images
        'uploaded_by' => $user['id'],
    ]);

    jsonResponse(true, ['id' => $imgId, 'filename' => 'qr_codes/' . $filename, 'is_primary' => 2], 'อัปโหลด QR สำเร็จ');
}

// ─── Get Images ───────────────────────────────────────────────────────────────
if ($action === 'get_images') {
    $id = intval($input['id'] ?? $_GET['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Missing id');
    $images = $db->fetchAll('SELECT * FROM asset_images WHERE asset_id = ? ORDER BY is_primary DESC, id ASC', [$id]);
    jsonResponse(true, ['images' => $images]);
}

// ─── Set Primary Image ────────────────────────────────────────────────────────
if ($action === 'set_primary_image') {
    requireRole(['admin','webadmin']);
    $imgId   = intval($input['img_id'] ?? 0);
    $assetId = intval($input['asset_id'] ?? 0);
    if (!$imgId || !$assetId) jsonResponse(false, null, 'Missing data');

    $img = $db->fetchOne('SELECT * FROM asset_images WHERE id = ? AND asset_id = ? AND is_primary != 2', [$imgId, $assetId]);
    if (!$img) jsonResponse(false, null, 'ไม่พบรูปภาพ');

    $db->query('UPDATE asset_images SET is_primary = 0 WHERE asset_id = ? AND is_primary != 2', [$assetId]);
    $db->query('UPDATE asset_images SET is_primary = 1 WHERE id = ?', [$imgId]);
    $db->update('assets', ['asset_image' => $img['filename']], 'id=:id', ['id' => $assetId]);
    jsonResponse(true, null, 'ตั้งเป็นรูปหลักสำเร็จ');
}

// ─── Delete Image ─────────────────────────────────────────────────────────────
if ($action === 'delete_image') {
    requireRole(['admin','webadmin']);
    $imgId   = intval($input['img_id'] ?? 0);
    $assetId = intval($input['asset_id'] ?? 0);
    if (!$imgId || !$assetId) jsonResponse(false, null, 'Missing data');

    $img = $db->fetchOne('SELECT * FROM asset_images WHERE id = ? AND asset_id = ? AND is_primary != 2', [$imgId, $assetId]);
    if (!$img) jsonResponse(false, null, 'ไม่พบรูปภาพ');

    @unlink(UPLOAD_PATH . str_replace('assets/', '', $img['filename']));
    $db->query('DELETE FROM asset_images WHERE id = ?', [$imgId]);

    // If deleted primary, promote next image
    if ($img['is_primary']) {
        $next = $db->fetchOne('SELECT * FROM asset_images WHERE asset_id = ? AND is_primary != 2 ORDER BY id ASC LIMIT 1', [$assetId]);
        if ($next) {
            $db->query('UPDATE asset_images SET is_primary = 1 WHERE id = ?', [$next['id']]);
            $db->update('assets', ['asset_image' => $next['filename']], 'id=:id', ['id' => $assetId]);
        } else {
            $db->update('assets', ['asset_image' => null], 'id=:id', ['id' => $assetId]);
        }
    }
    jsonResponse(true, null, 'ลบรูปภาพสำเร็จ');
}

// ─── Delete QR Code Image ─────────────────────────────────────────────────────
if ($action === 'delete_qr_image') {
    requireRole(['admin','webadmin']);
    $imgId   = intval($input['img_id'] ?? 0);
    $assetId = intval($input['asset_id'] ?? 0);
    if (!$imgId || !$assetId) jsonResponse(false, null, 'Missing data');

    $img = $db->fetchOne('SELECT * FROM asset_images WHERE id = ? AND asset_id = ? AND is_primary = 2', [$imgId, $assetId]);
    if (!$img) jsonResponse(false, null, 'ไม่พบรูป QR Code');

    @unlink(UPLOAD_PATH . str_replace('qr_codes/', '', $img['filename']));
    $db->query('DELETE FROM asset_images WHERE id = ?', [$imgId]);
    jsonResponse(true, null, 'ลบรูป QR สำเร็จ');
}

// ─── Create ───────────────────────────────────────────────────────────────────
if ($action === 'create') {
    requireRole(['admin','webadmin']);

    $assetNo = trim($input['asset_no'] ?? '');
    if (!$assetNo) jsonResponse(false, null, 'กรุณากรอก Asset No.');

    $exists = $db->fetchOne("SELECT id FROM assets WHERE asset_no = ?", [$assetNo]);
    if ($exists) jsonResponse(false, null, "Asset No. '$assetNo' มีอยู่แล้วในระบบ");

    $data = [
        'plant_code'       => sanitize($input['plant_code'] ?? ''),
        'class_code'       => sanitize($input['class_code'] ?? ''),
        'asset_no'         => $assetNo,
        'asset_description'=> sanitize($input['asset_description'] ?? ''),
        'cap_date'         => $input['cap_date'] ?: null,
        'acquis_val'       => floatval($input['acquis_val'] ?? 0),
        'cost_center'      => sanitize($input['cost_center'] ?? ''),
        'department_code'  => sanitize($input['department_code'] ?? ''),
        'department_name'  => sanitize($input['department_name'] ?? ''),
        'municipality'     => sanitize($input['municipality'] ?? ''),
        'location'         => sanitize($input['location'] ?? ''),
        'serial_no'        => sanitize($input['serial_no'] ?? ''),
        'brand'            => sanitize($input['brand'] ?? ''),
        'model'            => sanitize($input['model'] ?? ''),
        'status'           => in_array($input['status'] ?? '', ['active','cancelled','disposed','transferred']) ? $input['status'] : 'active',
        'remark'           => sanitize($input['remark'] ?? ''),
        'created_by'       => $user['id'],
    ];

    $id = $db->insert('assets', $data);
    auditLog('create', 'assets', $id, null, $data);
    jsonResponse(true, ['id' => $id], 'เพิ่มทรัพย์สินสำเร็จ');
}

// ─── Update ───────────────────────────────────────────────────────────────────
if ($action === 'update') {
    requireRole(['admin','webadmin']);

    $id = intval($input['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID');

    $old = $db->fetchOne("SELECT * FROM assets WHERE id=?", [$id]);
    if (!$old) jsonResponse(false, null, 'ไม่พบทรัพย์สิน');

    $data = [
        'plant_code'       => sanitize($input['plant_code'] ?? $old['plant_code']),
        'class_code'       => sanitize($input['class_code'] ?? $old['class_code']),
        'asset_description'=> sanitize($input['asset_description'] ?? $old['asset_description']),
        'cap_date'         => $input['cap_date'] ?: $old['cap_date'],
        'acquis_val'       => floatval($input['acquis_val'] ?? $old['acquis_val']),
        'cost_center'      => sanitize($input['cost_center'] ?? $old['cost_center']),
        'department_code'  => sanitize($input['department_code'] ?? $old['department_code']),
        'department_name'  => sanitize($input['department_name'] ?? $old['department_name']),
        'municipality'     => sanitize($input['municipality'] ?? $old['municipality']),
        'location'         => sanitize($input['location'] ?? $old['location']),
        'serial_no'        => sanitize($input['serial_no'] ?? $old['serial_no']),
        'brand'            => sanitize($input['brand'] ?? $old['brand']),
        'model'            => sanitize($input['model'] ?? $old['model']),
        'status'           => in_array($input['status'] ?? '', ['active','cancelled','disposed','transferred']) ? $input['status'] : $old['status'],
        'remark'           => sanitize($input['remark'] ?? $old['remark']),
        'updated_by'       => $user['id'],
    ];

    $db->update('assets', $data, 'id=:id', ['id' => $id]);
    auditLog('update', 'assets', $id, $old, $data);
    jsonResponse(true, ['id' => $id], 'บันทึกสำเร็จ');
}

// ─── Delete ───────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    requireRole(['admin','webadmin']);

    $id = intval($input['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID');

    $old = $db->fetchOne("SELECT * FROM assets WHERE id=?", [$id]);
    if (!$old) jsonResponse(false, null, 'ไม่พบทรัพย์สิน');

    $db->query("DELETE FROM assets WHERE id=?", [$id]);
    auditLog('delete', 'assets', $id, $old, null);
    jsonResponse(true, null, 'ลบทรัพย์สินสำเร็จ');
}

// ─── GET single ───────────────────────────────────────────────────────────────
if ($method === 'GET' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $asset = $db->fetchOne("SELECT * FROM assets WHERE id=?", [$id]);
    if (!$asset) jsonResponse(false, null, 'ไม่พบทรัพย์สิน', 404);
    jsonResponse(true, $asset);
}

// ─── GET list ─────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $search = trim($_GET['search'] ?? '');
    $plant  = trim($_GET['plant'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = min(100, intval($_GET['limit'] ?? ITEMS_PER_PAGE));
    $offset = ($page - 1) * $limit;

    $where  = ['1=1'];
    $params = [];
    if ($search) { $where[] = '(asset_no LIKE ? OR asset_description LIKE ?)'; $params = array_merge($params, ["%$search%", "%$search%"]); }
    if ($plant)  { $where[] = 'plant_code = ?'; $params[] = $plant; }
    if ($status) { $where[] = 'status = ?'; $params[] = $status; }

    $whereStr = implode(' AND ', $where);
    $total    = $db->fetchOne("SELECT COUNT(*) as c FROM assets WHERE $whereStr", $params)['c'];
    $assets   = $db->fetchAll("SELECT * FROM assets WHERE $whereStr ORDER BY id DESC LIMIT $limit OFFSET $offset", $params);

    jsonResponse(true, ['assets' => $assets, 'total' => $total, 'page' => $page, 'limit' => $limit]);
}

jsonResponse(false, null, 'Invalid request');