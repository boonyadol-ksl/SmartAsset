<?php
// 1. นำเข้าไฟล์ที่จำเป็นก่อน
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

// 2. ใช้ฟังก์ชัน startSession() จากระบบเพื่อให้ชื่อ Session ตรงกัน
startSession();

header('Content-Type: application/json');

// 3. ตรวจสอบ Login ผ่านฟังก์ชันของระบบ (isLoggedIn จาก auth.php)
if (!isLoggedIn()) {
    echo json_encode([
        'is_authorized' => false,
        'message' => 'Session expired. Please login again.'
    ]);
    exit;
}

$asset_no = $_GET['asset_no'] ?? '';
$user_id = $_SESSION['user_id'];

try {
    $db = Database::getInstance();

    // 4. ค้นหาข้อมูลโดยใช้ LIKE และตรวจสอบสิทธิ์
    // $sql = "SELECT a.*, assets.asset_no, assets.cost_center
    //         FROM audit_assignments a
    //         JOIN assets ON a.asset_id = assets.id
    //         WHERE assets.asset_no LIKE ?
    //         AND a.user_id = ?
    //         AND a.status = 'pending'";

    // $assignments = $db->fetchAll($sql, [$asset_no . '%', $user_id]);

    // for testing
    $sql = "SELECT a.*
        FROM assets a
            WHERE a.asset_no LIKE 'AEQH009863'  ";

    $assignments = $db->fetchAll($sql,[]);

    if (count($assignments) > 0) {
        echo json_encode([
            'is_authorized' => true,
            'data' => $assignments
        ]);
    } else {
        echo json_encode([
            'is_authorized' => false,
            'message' => 'ไม่พบรายการที่ได้รับมอบหมาย'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'is_authorized' => false,
        'message' => 'Database Error: ' . $e->getMessage()
    ]);
}