<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

header('Content-Type: application/json; charset=utf-8');
// ตรวจสอบสิทธิ์การเข้าถึง
requireRole(['admin', 'webadmin', 'inventory']);

$db = Database::getInstance();
$user = currentUser();

// รองรับทั้ง JSON Input และ FormData (สำหรับไฟล์ภาพ)
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($_POST['action'] ?? ($input['action'] ?? ($_GET['action'] ?? '')));

/**
 * ACTION: CREATE_SESSION
 * สำหรับ Admin สร้างรอบการตรวจนับใหม่
 */
if ($action === 'create_session') {
    $sessionName = trim($_POST['name'] ?? ($input['name'] ?? ''));
    $startDate   = trim($_POST['start_date'] ?? ($input['start_date'] ?? ''));
    $endDate     = trim($_POST['end_date'] ?? ($input['end_date'] ?? ''));

    if (!$sessionName) {
        jsonResponse(false, null, 'กรุณากรอกชื่อรอบตรวจนับ', 400);
    }

    $existing = $db->fetchOne("SELECT id FROM inventory_sessions WHERE status = 'open' LIMIT 1");
    if ($existing) {
        jsonResponse(false, null, 'มีรอบตรวจนับที่เปิดอยู่แล้ว กรุณาปิดรอบก่อนสร้างใหม่', 409);
    }

    $data = [
        'session_name' => $sessionName,
        'plant_code'   => trim($_POST['plant_code'] ?? ($input['plant_code'] ?? '')),
        'start_date'   => $startDate ?: null,
        'end_date'     => $endDate ?: null,
        'status'       => 'open',
        'created_by'   => $user['id'],
    ];

    $id = $db->insert('inventory_sessions', $data);
    jsonResponse(true, ['id' => $id], 'สร้างรอบตรวจนับสำเร็จ');
}

/**
 * ACTION: GET_HISTORY
 * ดึงประวัติการตรวจนับของทรัพย์สินชิ้นนั้นๆ
 */
if ($action === 'get_history') {
    $assetId = intval($_POST['asset_id'] ?? ($input['asset_id'] ?? ($_GET['asset_id'] ?? 0)));
    if (!$assetId) jsonResponse(false, null, 'ข้อมูลไม่ถูกต้อง', 400);

    $history = $db->fetchAll(
        "(SELECT ir.id, ir.check_status, ir.remarks, ir.photo_0 as photo_path, ir.quantity, ir.checked_at,
                s.session_name, u.full_name as checked_by_name, 'inventory' as source_type
         FROM inventory_results ir
         JOIN inventory_sessions s ON s.id = ir.session_id
         LEFT JOIN users u ON u.id = ir.checked_by
         WHERE ir.asset_id = ?)
         UNION ALL
         (SELECT ar.id, ar.check_status, ar.notes as remarks, ar.photo_path, ar.quantity_checked as quantity, ar.checked_at,
                s.session_name, u.full_name as checked_by_name, 'audit' as source_type
         FROM audit_assignments ar
         JOIN audit_sessions s ON s.id = ar.session_id
         LEFT JOIN users u ON u.id = ar.user_id
         WHERE ar.asset_id = ?)
         ORDER BY checked_at DESC",
        [$assetId, $assetId]
    );
    jsonResponse(true, ['history' => $history]);
}

/**
 * ACTION: CHECK
 * บันทึกผลการตรวจนับ (รองรับการอัปโหลดรูปภาพหลายรูปและลายเซ็น)
 */
if ($action === 'check') {
    $assetId      = intval($_POST['asset_id'] ?? 0);
    $sessionId    = intval($_POST['session_id'] ?? 0);
    $status       = trim($_POST['check_status'] ?? '');
    $quantity     = intval($_POST['quantity'] ?? 0);
    $remarks      = trim($_POST['remarks'] ?? '');
    $location     = trim($_POST['location'] ?? '');
    $signature    = trim($_POST['signature'] ?? '');
    $sourceType   = trim($_POST['source_type'] ?? 'inventory'); // 'inventory' หรือ 'audit'
    $assignmentId = intval($_POST['assignment_id'] ?? 0);

    $allowed = ['found', 'not_found', 'damaged', 'active', 'returned', 'inactive', 'repairing'];

    if (!$assetId || !in_array($status, $allowed, true)) {
        jsonResponse(false, null, 'ข้อมูลไม่ถูกต้องหรือสถานะไม่ได้รับอนุญาต', 400);
    }

    $asset = $db->fetchOne('SELECT asset_no FROM assets WHERE id = ?', [$assetId]);
    if (!$asset) jsonResponse(false, null, 'ไม่พบทรัพย์สินในระบบ', 404);

    // --- ส่วนจัดการรูปภาพ (รองรับสูงสุด 5 รูป) ---
    $photoPaths = [];
    $uploadBaseDir = __DIR__ . '/../assets/uploads/inventory/' . date('Ym') . '/';
    if (!is_dir($uploadBaseDir)) mkdir($uploadBaseDir, 0755, true);

    for ($i = 0; $i < 5; $i++) {
        if (isset($_FILES["photo_$i"]) && $_FILES["photo_$i"]['size'] > 0) {
            $ext = pathinfo($_FILES["photo_$i"]['name'], PATHINFO_EXTENSION);
            $filename = "asset_{$assetId}_rev{$i}_" . time() . "." . $ext;
            if (move_uploaded_file($_FILES["photo_$i"]['tmp_name'], $uploadBaseDir . $filename)) {
                $photoPaths["photo_$i"] = 'assets/uploads/inventory/' . date('Ym') . '/' . $filename;
            }
        }
    }

    if ($sourceType === 'inventory') {
        if (!$sessionId) jsonResponse(false, null, 'ต้องระบุ session_id', 400);

        $session = $db->fetchOne('SELECT id FROM inventory_sessions WHERE id = ? AND status = "open"', [$sessionId]);
        if (!$session) jsonResponse(false, null, 'รอบตรวจนับนี้ถูกปิดไปแล้ว', 403);

        $result = $db->fetchOne('SELECT id FROM inventory_results WHERE session_id = ? AND asset_id = ?', [$sessionId, $assetId]);

        $data = [
            'session_id'   => $sessionId,
            'asset_id'     => $assetId,
            'asset_no'     => $asset['asset_no'],
            'check_status' => $status,
            'location'     => $location,
            'quantity'     => $quantity,
            'remarks'      => $remarks,
            'signature'    => $signature ?: null,
            'checked_by'   => $user['id'],
            'checked_at'   => date('Y-m-d H:i:s'),
        ];

        // รวมพาธรูปภาพเข้าไปใน Data
        $data = array_merge($data, $photoPaths);

        if ($result) {
            $db->update('inventory_results', $data, ['id' => $result['id']]);
        } else {
            $db->insert('inventory_results', $data);
        }

        auditLog('inventory_check', "ตรวจนับ {$asset['asset_no']} เป็น $status โดย {$user['full_name']}", $user['id']);

    } elseif ($sourceType === 'audit') {
        if (!$assignmentId) jsonResponse(false, null, 'ต้องระบุ assignment_id', 400);

        $assignment = $db->fetchOne('SELECT id FROM audit_assignments WHERE id = ? AND user_id = ?', [$assignmentId, $user['id']]);
        if (!$assignment) jsonResponse(false, null, 'ไม่พบงานตรวจสอบที่ได้รับมอบหมาย', 404);

        $updateData = [
            'status'           => 'completed',
            'check_status'     => $status,
            'notes'            => $remarks,
            'photo_path'       => $photoPaths['photo_0'] ?? null, // ระบบ audit เดิมมักใช้รูปเดียว
            'quantity_checked' => $quantity,
            'checked_at'       => date('Y-m-d H:i:s')
        ];

        $db->update('audit_assignments', $updateData, ['id' => $assignmentId]);
        auditLog('audit_check', "Audit {$asset['asset_no']} สำเร็จ โดย {$user['full_name']}", $user['id']);
    }

    jsonResponse(true, null, 'บันทึกข้อมูลการตรวจนับเรียบร้อยแล้ว');
}

// กรณีไม่มี Action ตรงเงื่อนไข
jsonResponse(false, null, 'ไม่พบ Action ที่ระบุ', 400);