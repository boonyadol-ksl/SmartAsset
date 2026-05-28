<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database/db.php';

function startSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('ASSET_SYS');
        session_start();
    }
}


function FnADLogin($user, $passwd)
{
    if (empty($user) || empty($passwd)) return false;

    // 1. ตรวจสอบว่าในตัวแปร $user มีเครื่องหมาย @ หรือยัง
    // ถ้ายังไม่มี (เป็น false) ให้เติม @ZZZ.com ต่อท้ายอัตโนมัติ
    if (strpos($user, '@') === false) {
        $bindUser = $user . "@kslgroup.com";
    } else {
        // ถ้าผู้ใช้พิมพ์ @ เข้ามาเองแล้ว (เช่น พิมพ์เต็ม หรือใช้อีเมลอื่น) ให้ใช้ค่านั้นตรงๆ
        $bindUser = $user;
    }

    // 2. เริ่มกระบวนการเชื่อมต่อ AD (LDAP)
    if ($ds = @ldap_connect("LDAP://10.1.18.2")) {
        // ใช้ตัวแปร $bindUser ที่เราจัดรูปแบบเสร็จแล้วไปตรวจสอบสิทธิ์
        if (@ldap_bind($ds, $bindUser, $passwd)) {
            ldap_close($ds); // ปิดการเชื่อมต่อเมื่อสำเร็จ
            return true;
        }
        ldap_close($ds); // ปิดการเชื่อมต่อเมื่อไม่สำเร็จ
    }
    return false;
}

function isLoggedIn()
{
    startSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function requireRole($roles)
{
    requireLogin();
    if (!is_array($roles)) {
        $roles = [$roles]; // In case a single role string is passed
    }
    if (!in_array($_SESSION['user_role'], $roles)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Permission denied']));
    }
}

function currentUser()
{
    startSession();
    return [
        'id'       => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0,
        'username' => isset($_SESSION['username']) ? $_SESSION['username'] : '',
        'name'     => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '',
        'role'     => isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'viewer',
        'plant'    => isset($_SESSION['user_plant']) ? $_SESSION['user_plant'] : '',
        'site_id'  => isset($_SESSION['user_site_id']) ? $_SESSION['user_site_id'] : null,
    ];
}

function hasRole($role)
{
    startSession();
    $roles = is_array($role) ? $role : [$role];
    return in_array(isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '', $roles);
}

function login($username, $password)
{
    $db = Database::getInstance();
    //$user = $db->fetchOne("SELECT * FROM users WHERE username = ? AND is_active = 1", [$username]);
    // ต่อเครื่องหมาย % เข้าไปท้ายชื่อผู้ใช้ เพื่อทำ Pattern Matching (เช่น จาก "somchai" จะกลายเป็น "somchai%")
    $emailSearch = $username . '%';

    // $user = $db->fetchOne(
    //     "SELECT * FROM users WHERE (username = ? OR email LIKE ?) AND is_active = 1",
    //     [$username, $emailSearch]
    // );

    $user = $db->fetchOne(
    "SELECT u.*, s.site_name ,s.site_code
     FROM users u
     LEFT JOIN sites s ON u.site_id = s.id
     WHERE (u.username = ? OR u.email LIKE ?)
     AND u.is_active = 1",
    [$username, $emailSearch]
);


    // 1. เช็คก่อนว่าพบข้อมูลผู้ใช้ในระบบที่เปิดใช้งาน (is_active = 1) หรือไม่
    if (!$user) {
        return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
    }

    // 2. ตั้งตัวแปรเช็คความถูกต้องของรหัสผ่าน (เริ่มต้นเป็นเท็จ)
    $is_password_valid = false;

    // 3. ทดสอบตรวจสอบรหัสผ่านแบบภายในระบบ (Local Database)
    if (password_verify($password, $user['password'])) {
        $is_password_valid = true;
    }
    // 4. ถ้าแบบแรกไม่ผ่าน ลองนำไปทดสอบกับ AD Login (LDAP) ต่อ
    elseif (FnADLogin($username, $password)) {
        $is_password_valid = true;
    }

    // 5. สรุปผลสุดท้าย: ถ้าทั้งสองช่องทางไม่มีอันไหนถูกเลย ค่อยแจ้ง Error
    if (!$is_password_valid) {
        return ['success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง'];
    }

    startSession();
    session_regenerate_id(true);

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['user_name']  = $user['full_name'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_plant'] = $user['plant_code'];
    $_SESSION['user_site_id'] = $user['site_id'];
    $_SESSION['user_site_code'] = $user['site_code'];
    $_SESSION['user_site_name'] = $user['site_name'];
    $_SESSION['user']       = $user;
    $_SESSION['login_time'] = time();

    $db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);

    return ['success' => true, 'role' => $user['role']];
}

function logout()
{
    startSession();
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

function auditLog($action, $table, $recordId, $oldData = null, $newData = null)
{
    try {
        $db = Database::getInstance();
        $user = currentUser();
        $db->insert('audit_logs', [
            'user_id'    => $user['id'],
            'action'     => $action,
            'table_name' => $table,
            'record_id'  => $recordId,
            'old_data'   => $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null,
            'new_data'   => $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        ]);
    } catch (Exception $e) {
        // silent fail for audit log
    }
}

function jsonResponse($success, $data = null, $message = '', $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize($val)
{
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}
