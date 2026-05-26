<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
header('Content-Type: application/json; charset=utf-8');
startSession();
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session หมดอายุ กรุณา Login ใหม่']);
    exit;
}
$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'webadmin'], true)) {
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}
$db = Database::getInstance();
$user = currentUser(); // ควรมี field site_id ใน users (เพิ่มตาม migration)
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($_GET['action'] ?? ($input['action'] ?? ($_POST['action'] ?? '')));
// ตรวจสอบ exist function jsonResponse ก่อนสร้างใหม่
if (!function_exists('jsonResponse')) {
    function jsonResponse($success, $data = null, $message = '', $code = 200)
    {
        http_response_code($code);
        echo json_encode(['success' => $success, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
function requireAdminOnly($role)
{
    if ($role !== 'admin') {
        jsonResponse(false, null, 'เฉพาะ admin เท่านั้น', 403);
    }
}
// webadmin แก้/ดูได้เฉพาะ site ตัวเอง
function assertWebadminSiteScope($role, $userSiteId, $targetSiteId)
{
    if ($role === 'webadmin') {
        if (empty($userSiteId)) {
            jsonResponse(false, null, 'บัญชี webadmin ยังไม่ได้ผูก Site', 400);
        }
        if ((int)$targetSiteId !== (int)$userSiteId) {
            jsonResponse(false, null, 'webadmin เข้าถึงได้เฉพาะ Site ของตัวเอง', 403);
        }
    }
}
try {
    if ($action === 'list') {
        if ($role === 'webadmin') {
            if (empty($user['site_id'])) jsonResponse(true, [], '');
            $rows = $db->fetchAll("SELECT * FROM sites WHERE id = ? ORDER BY site_code", [(int)$user['site_id']]);
        } else {
            $rows = $db->fetchAll("SELECT * FROM sites ORDER BY site_code");
        }
        jsonResponse(true, $rows);
    }
    if ($action === 'create') {
        // แนะนำให้ admin เท่านั้นเป็นคนเพิ่ม site ใหม่ (กันการสร้างมั่ว)
        requireAdminOnly($role);
        $siteCode = strtoupper(trim($input['site_code'] ?? ''));
        $siteName = trim($input['site_name'] ?? '');
        $legalName = trim($input['legal_name'] ?? '');
        $address = trim($input['address'] ?? '');
        $taxId = trim($input['tax_id'] ?? '');
        if ($siteCode === '' || $siteName === '') {
            jsonResponse(false, null, 'กรุณากรอก site_code และ site_name', 400);
        }
        $dup = $db->fetchOne("SELECT id FROM sites WHERE site_code = ? LIMIT 1", [$siteCode]);
        if ($dup) jsonResponse(false, null, 'site_code ซ้ำในระบบ', 409);
        $db->execute(
            "INSERT INTO sites (site_code, site_name, legal_name, address, tax_id, is_active)
             VALUES (?, ?, ?, ?, ?, 1)",
            [$siteCode, $siteName, ($legalName ?: null), ($address ?: null), ($taxId ?: null)]
        );
        jsonResponse(true, ['id' => (int)$db->lastInsertId()], 'เพิ่ม Site สำเร็จ');
    }
    if ($action === 'update') {
        $siteId = (int)($input['id'] ?? 0);
        if ($siteId <= 0) jsonResponse(false, null, 'ข้อมูลไม่ครบถ้วน', 400);
        assertWebadminSiteScope($role, $user['site_id'] ?? null, $siteId);
        $siteName = trim($input['site_name'] ?? '');
        $legalName = trim($input['legal_name'] ?? '');
        $address = trim($input['address'] ?? '');
        $taxId = trim($input['tax_id'] ?? '');
        if ($siteName === '') jsonResponse(false, null, 'กรุณากรอก site_name', 400);
        $db->execute(
            "UPDATE sites SET site_name=?, legal_name=?, address=?, tax_id=? WHERE id=?",
            [$siteName, ($legalName ?: null), ($address ?: null), ($taxId ?: null), $siteId]
        );
        jsonResponse(true, null, 'บันทึกสำเร็จ');
    }
    if ($action === 'toggle_active') {
        // ปิด/เปิดใช้งาน แนะนำให้ admin ทำเท่านั้น
        requireAdminOnly($role);
        $siteId = (int)($input['id'] ?? 0);
        $isActive = (int)($input['is_active'] ?? 1);
        if ($siteId <= 0) jsonResponse(false, null, 'ข้อมูลไม่ครบถ้วน', 400);
        $db->execute("UPDATE sites SET is_active=? WHERE id=?", [$isActive ? 1 : 0, $siteId]);
        jsonResponse(true, null, 'อัปเดตสถานะสำเร็จ');
    }
    if ($action === 'list_plants') {
        $siteId = (int)($_GET['site_id'] ?? 0);
        if ($role === 'webadmin') {
            $siteId = (int)($user['site_id'] ?? 0);
            if ($siteId <= 0) jsonResponse(true, [], '');
        }
        $rows = $db->fetchAll(
            "SELECT plant_code, plant_name, site_id FROM plants ORDER BY plant_code",
            []
        );
        jsonResponse(true, $rows);
    }
    if ($action === 'assign_plants') {
        // assign plant เข้า site (admin ทำได้ทั้งหมด, webadmin ทำได้เฉพาะ site ตัวเอง)
        $siteId = (int)($input['site_id'] ?? 0);
        $plants = $input['plants'] ?? [];
        if ($siteId <= 0 || !is_array($plants)) jsonResponse(false, null, 'ข้อมูลไม่ครบถ้วน', 400);
        assertWebadminSiteScope($role, $user['site_id'] ?? null, $siteId);
        if (count($plants) === 0) jsonResponse(true, null, 'ไม่มี plant ให้ปรับ');
        $placeholders = implode(',', array_fill(0, count($plants), '?'));
        $params = array_merge([$siteId], $plants);
        $db->execute("UPDATE plants SET site_id = ? WHERE plant_code IN ($placeholders)", $params);
        jsonResponse(true, null, 'ผูก Plant เข้ากับ Site สำเร็จ');
    }
    jsonResponse(false, null, 'Invalid action', 400);
} catch (Exception $e) {
    jsonResponse(false, null, $e->getMessage(), 500);
}
