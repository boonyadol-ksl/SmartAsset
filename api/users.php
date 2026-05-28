<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
header('Content-Type: application/json');

// ตรวจสอบสิทธิ์ (รองรับทั้ง admin และ webadmin)
if (!hasRole(['admin', 'webadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? $input['action'] ?? '';

switch ($action) {
    case 'list':
        // ดึงข้อมูลผู้ใช้งานพร้อมชื่อแผนก
        $data = $db->fetchAll("SELECT u.*, a.department_name
                               FROM users u
                               LEFT JOIN (SELECT DISTINCT department_code, department_name FROM assets) a
                               ON u.plant_code = a.department_code
                               ORDER BY u.username ASC");
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'create':
        // ตรวจสอบ Username ซ้ำ
        $exists = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$input['username']]);
        if ($exists) {
            echo json_encode(['success' => false, 'message' => 'Username นี้มีในระบบแล้ว']);
            exit;
        }

        $hashedPassword = password_hash($input['password'], PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (username, full_name, plant_code, email, password, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)";

        // ใช้ ->query() แทน ->execute() เพื่อแก้ Fatal Error
        $res = $db->query($sql, [
            $input['username'],
            $input['full_name'],
            $input['plant_code'],
            $input['email'],
            $hashedPassword,
            $input['role'],
            $input['is_active']
        ]);
        echo json_encode(['success' => $res]);
        break;

    case 'update':
        $me = currentUser();
        $params = [$input['full_name'], $input['plant_code'], $input['email'], $input['site_id']];
        $roleAndStatusSql = "";

        // ป้องกันการเปลี่ยนสิทธิ์หรือสถานะตัวเอง
        if ($input['id'] != $me['id']) {
            $roleAndStatusSql = ", role = ?, is_active = ?";
            $params[] = $input['role'];
            $params[] = $input['is_active'];
        }

        $passwordSql = "";
        if (!empty($input['password'])) {
            $passwordSql = ", password = ?";
            $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
        }



        $params[] = $input['id'];
        $sql = "UPDATE users SET full_name = ?, plant_code = ?, email = ?, site_id = ? $roleAndStatusSql $passwordSql WHERE id = ?";

        $res = $db->query($sql, $params);
        echo json_encode(['success' => $res]);
        break;

    case 'delete':
        $me = currentUser();
        if ($input['id'] == $me['id']) {
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถลบบัญชีตัวเองได้']);
            exit;
        }
        $res = $db->query("DELETE FROM users WHERE id = ?", [$input['id']]);
        echo json_encode(['success' => $res]);
        break;
}