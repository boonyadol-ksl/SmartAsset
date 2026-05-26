<?php
$pageTitle = 'ระบบตรวจนับทรัพย์สิน';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();
$db       = Database::getInstance();
$user     = currentUser();
$userId   = $user['id'];
$userRole = $user['role']; // 'admin', 'webadmin', 'inventory', 'viewer'
// ============================================================
// AJAX ENDPOINTS
// ============================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_GET['action'] === 'get_signature') {
        $pCode = $_GET['plant_code'] ?? '';
        $ccCode = $_GET['cost_center'] ?? '';
        $session_id = $_GET['session_id'] ?? '';
        $row = $db->fetchOne("SELECT * FROM audit_summary WHERE plant_code = ? AND cost_center = ? AND session_id = ?", [$pCode, $ccCode, $session_id]);
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }
    if ($_GET['action'] === 'save_signature') {
        // ดึงข้อมูลผู้ใช้งานปัจจุบันจาก Session
        $currentUserId = $_SESSION['user_id'];
        $input = json_decode(file_get_contents('php://input'), true);
        // 1. ตรวจสอบว่ามีข้อมูลส่งมาหรือไม่
        if (!$input) {
            echo json_encode(['success' => false, 'message' => 'ไม่ได้รับข้อมูล JSON']);
            exit;
        }
        // 2. ดึงค่ามาตรวจสอบ
        $imgData = $input['signature'] ?? null;
        $type    = $input['type'] ?? null;
        $pCode   = $input['pCode'] ?? null;
        $ccCode  = $input['ccCode'] ?? null;
        $signer_id = $input['signer_id'] ?? null;
        $session_id = $input['session_id'] ?? null;
        // กำหนดคอลัมน์ตามประเภท
        $signerName = trim($input['signer_name'] ?? '');
        $colSig  = '';
        $colUid  = null;
        $uidToSave = null;
        $extraCol  = null;
        $extraVal  = null;
        if ($type === 'asset_officer') {
            $colSig    = 'asset_officer_sig';
            $colUid    = 'asset_officer_id';
            $uidToSave = !empty($signer_id) ? $signer_id : $currentUserId;
        } elseif ($type === 'dept') {
            $colSig   = 'dept_sig';
            $extraCol = 'dept_name';
            $extraVal = $signerName;
            if (empty($signerName)) {
                echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อ-สกุลหัวหน้าแผนก']);
                exit;
            }
        } else { // auditor
            $colSig    = 'auditor_sig';
            $colUid    = 'auditor_id';
            $uidToSave = $currentUserId;
        }
        // ตรวจสอบว่ามีแถวนี้อยู่หรือยัง
        $stmt = $db->prepare("SELECT id FROM audit_summary WHERE plant_code = ? AND cost_center = ? AND session_id = ?");
        $stmt->execute([$pCode, $ccCode, $session_id]);
        $row = $stmt->fetch();
        if ($row) {
            if ($extraCol) {
                $sql    = "UPDATE audit_summary SET $colSig = ?, $extraCol = ?, updated_at = NOW() WHERE id = ?";
                $result = $db->prepare($sql)->execute([$imgData, $extraVal, $row['id']]);
            } elseif ($colUid) {
                $sql    = "UPDATE audit_summary SET $colSig = ?, $colUid = ?, updated_at = NOW() WHERE id = ?";
                $result = $db->prepare($sql)->execute([$imgData, $uidToSave, $row['id']]);
            } else {
                $sql    = "UPDATE audit_summary SET $colSig = ?, updated_at = NOW() WHERE id = ?";
                $result = $db->prepare($sql)->execute([$imgData, $row['id']]);
            }
        } else {
            if ($extraCol) {
                $sql    = "INSERT INTO audit_summary (plant_code, cost_center, session_id, $colSig, $extraCol, updated_at) VALUES (?, ?, ?, ?, ?, NOW())";
                $result = $db->prepare($sql)->execute([$pCode, $ccCode, $session_id, $imgData, $extraVal]);
            } elseif ($colUid) {
                $sql    = "INSERT INTO audit_summary (plant_code, cost_center, session_id, $colSig, $colUid, updated_at) VALUES (?, ?, ?, ?, ?, NOW())";
                $result = $db->prepare($sql)->execute([$pCode, $ccCode, $session_id, $imgData, $uidToSave]);
            } else {
                $sql    = "INSERT INTO audit_summary (plant_code, cost_center, session_id, $colSig, updated_at) VALUES (?, ?, ?, ?, NOW())";
                $result = $db->prepare($sql)->execute([$pCode, $ccCode, $session_id, $imgData]);
            }
        }
        echo json_encode(['success' => (bool)$result]);
        exit;
    }
    // ── get_cost_centers ──────────────────────────────────────
    if ($_GET['action'] === 'get_cost_centers') {
        $pCode  = trim($_GET['plant_code'] ?? '');
        $query  = "SELECT DISTINCT a.cost_center
                   FROM audit_assignments aa
                   JOIN assets a ON aa.asset_id = a.id
                   WHERE a.cost_center IS NOT NULL AND a.cost_center != ''";
        $params = [];
        if ($pCode !== '') {
            $query .= " AND a.plant_code = ?";
            $params[] = $pCode;
        }
        if ($userRole === 'webadmin' || $userRole === 'inventory') {
            $query .= " AND aa.user_id = ?";
            $params[] = $userId;
        }
        $query .= " ORDER BY a.cost_center";
        echo json_encode(array_column($db->fetchAll($query, $params), 'cost_center'));
        exit;
    }
    // ── search_by_qr ─────────────────────────────────────────
    if ($_GET['action'] === 'search_by_qr') {
        $qrRaw   = trim($_GET['qr'] ?? '');
        $assetId = 0;
        $prefix  = '';
        if ($qrRaw !== '') {
            $parts = parse_url($qrRaw);
            if (is_array($parts) && !empty($parts['query'])) {
                parse_str($parts['query'], $queryParams);
                $assetId = intval($queryParams['id'] ?? $queryParams['asset_id'] ?? 0);
            }
            if ($assetId <= 0 && preg_match('/(?:^|[?&])(?:id|asset_id)=(\d+)/', $qrRaw, $m)) {
                $assetId = intval($m[1]);
            }
            $prefix = explode('-', $qrRaw)[0]; // e.g. "AEQF007281"
            if (filter_var($prefix, FILTER_VALIDATE_URL)) {
                $prefix = '';
            }
        }
        if ($assetId <= 0 && $prefix === '') {
            echo json_encode(['count' => 0, 'items' => [], 'prefix' => '']);
            exit;
        }
        $qrWhere  = [];
        $qrParams = [];
        if ($assetId > 0) {
            $qrWhere[]  = 'a.id = ?';
            $qrParams[] = $assetId;
        } else {
            $qrWhere[]  = '(a.asset_no LIKE ? OR a.qr_code = ?)';
            $qrParams[] = $prefix . '%';
            $qrParams[] = $qrRaw;
        }
        if ($userRole === 'webadmin' || $userRole === 'inventory') {
            $qrWhere[] = 'aa.user_id = ?';
            $qrParams[] = $userId;
        }
        $qrWhereStr = implode(' AND ', $qrWhere);
        $results = $db->fetchAll(
            "SELECT aa.id as assignment_id, aa.status as assignment_status, aa.checked_at,
                    a.asset_no, a.asset_description, a.serial_no, a.model, a.plant_code, a.cost_center
             FROM audit_assignments aa
             JOIN assets a ON aa.asset_id = a.id
             WHERE $qrWhereStr
             ORDER BY a.asset_no ASC LIMIT 20",
            $qrParams
        );
        echo json_encode(['count' => count($results), 'items' => $results, 'prefix' => $prefix ?: ('ID ' . $assetId)]);
        exit;
    }
    // ── get_audit ─────────────────────────────────────────────
    if ($_GET['action'] === 'get_audit') {
        $assignId = intval($_GET['assignment_id'] ?? 0);
        $row = $db->fetchOne(
            "SELECT aa.*, a.id as asset_id, a.asset_no, a.asset_description, a.serial_no, a.model, a.plant_code, a.cost_center,
                    a.location as asset_location, a.municipality
             FROM audit_assignments aa JOIN assets a ON aa.asset_id = a.id
             WHERE aa.id = ?",
            [$assignId]
        );
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรายการ']);
            exit;
        }
        if ($userRole !== 'admin' && $row['user_id'] != $userId) {
            echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์']);
            exit;
        }
        $remarkData = [];
        if (!empty($row['remark'])) {
            $decoded = json_decode($row['remark'], true);
            if (is_array($decoded)) $remarkData = $decoded;
        }
        // Fetch latest completed audit for this asset to provide "latest" info as default
        $latestAudit = $db->fetchOne(
            "SELECT remark FROM audit_assignments
             WHERE asset_id = ? AND status = 'completed'
             ORDER BY checked_at DESC LIMIT 1",
            [$row['asset_id']]
        );
        $latestRemark = [];
        if ($latestAudit && !empty($latestAudit['remark'])) {
            $latestRemark = json_decode($latestAudit['remark'], true);
        }
        $uploadDir = __DIR__ . '/../uploads/audit/' . $assignId . '/';
        $images    = [];
        $signature = null;
        if (is_dir($uploadDir)) {
            // Load asset & qr images
            foreach (['asset', 'qr_code'] as $prefix_type) {
                $pattern = $uploadDir . $prefix_type . '_*.jpg';
                foreach (glob($pattern) as $f) {
                    $basename = basename($f);
                    $imgBytes = @file_get_contents($f);
                    if ($imgBytes !== false) {
                        $images[] = [
                            'type'     => $prefix_type === 'qr_code' ? 'qr_codes' : 'assets',
                            'base64'   => 'data:image/jpeg;base64,' . base64_encode($imgBytes),
                            'filename' => $basename
                        ];
                    }
                }
            }
            // Load signature
            $sigPath = $uploadDir . 'signature.jpg';
            if (file_exists($sigPath)) {
                $sigBytes = @file_get_contents($sigPath);
                if ($sigBytes !== false) $signature = 'data:image/jpeg;base64,' . base64_encode($sigBytes);
            }
        }
        echo json_encode([
            'success'       => true,
            'status'        => $row['status'],
            'checked_at'    => $row['checked_at'],
            'asset'         => [
                'asset_no'          => $row['asset_no'],
                'asset_description' => $row['asset_description'],
                'serial_no'         => $row['serial_no'],
                'model'             => $row['model'],
                'plant_code'        => $row['plant_code'],
                'cost_center'       => $row['cost_center'],
                'asset_location'    => $row['asset_location'],
                'municipality'      => $row['municipality']
            ],
            'remark_data'   => $remarkData,
            'latest_remark' => $latestRemark,
            'images'        => $images,
            'signature'     => $signature
        ]);
        exit;
    }
    // ── save_audit (POST) ─────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['action'] === 'save_audit') {
        $input      = json_decode(file_get_contents('php://input'), true);
        $assignId   = intval($input['assignment_id'] ?? 0);
        $allowed    = ['active', 'returned', 'not_found', 'inactive', 'repairing'];
        $checkStatus = in_array($input['check_status'] ?? '', $allowed) ? $input['check_status'] : 'active';
        $quantity   = max(0, intval($input['quantity'] ?? 1));
        $location   = trim($input['location'] ?? '');
        $responsible = trim($input['responsible_person'] ?? '');
        $notes      = trim($input['notes'] ?? '');
        $images     = is_array($input['images'] ?? null) ? $input['images'] : [];
        $signature  = $input['signature'] ?? null;
        if (!$assignId) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรายการ']);
            exit;
        }
        $assign = $db->fetchOne("SELECT * FROM audit_assignments WHERE id = ?", [$assignId]);
        if (!$assign) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรายการ']);
            exit;
        }
        if ($userRole !== 'admin' && $assign['user_id'] != $userId) {
            echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์ดำเนินการ']);
            exit;
        }
        // Build structured remark JSON
        $remarkJson = json_encode([
            'check_result'       => $checkStatus,
            'quantity'           => $quantity,
            'location'           => $location,
            'responsible_person' => $responsible,
            'notes'              => $notes,
            'saved_at'           => date('Y-m-d H:i:s'),
            'saved_by'           => $user['full_name'] ?? ($user['username'] ?? 'unknown')
        ], JSON_UNESCAPED_UNICODE);
        $db->execute(
            "UPDATE audit_assignments SET status='completed', checked_at=NOW(), remark=? WHERE id=?",
            [$remarkJson, $assignId]
        );
        // Save images & signature
        $uploadDir = __DIR__ . '/../uploads/audit/' . $assignId . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        // Delete old images (but keep signature until new one arrives)
        foreach (glob($uploadDir . 'asset_*.jpg') as $f)    unlink($f);
        foreach (glob($uploadDir . 'qr_code_*.jpg') as $f) unlink($f);
        $assetIdx = 0;
        $qrIdx = 0;
        foreach ($images as $img) {
            $type    = $img['type'] ?? 'assets';
            $raw     = preg_replace('/^data:image\/\w+;base64,/', '', $img['base64'] ?? '');
            $decoded = base64_decode($raw);
            if (!$decoded) continue;
            if ($type === 'qr_codes') {
                file_put_contents($uploadDir . 'qr_code_' . ($qrIdx++) . '.jpg', $decoded);
            } else {
                file_put_contents($uploadDir . 'asset_' . ($assetIdx++) . '.jpg', $decoded);
            }
        }
        if ($signature) {
            $sigRaw     = preg_replace('/^data:image\/\w+;base64,/', '', $signature);
            $sigDecoded = base64_decode($sigRaw);
            if ($sigDecoded) file_put_contents($uploadDir . 'signature.jpg', $sigDecoded);
        }
        echo json_encode(['success' => true, 'message' => 'บันทึกเรียบร้อยแล้ว']);
        exit;
    }
    echo json_encode(['error' => 'Unknown action']);
    exit;
}
// ============================================================
// PAGE DATA
// ============================================================
// --- รับค่าตัวกรอง ---
$search     = trim($_GET['search'] ?? '');
$plant      = trim($_GET['plant'] ?? '');
$costCenter = trim($_GET['cost_center'] ?? '');
$auditorId  = trim($_GET['auditor_id'] ?? '');
$status     = trim($_GET['status'] ?? '');
$page       = max(1, intval($_GET['page'] ?? 1));
$limit      = in_array($_GET['limit'] ?? 0, [10, 20, 50, 100]) ? intval($_GET['limit']) : ITEMS_PER_PAGE;
$offset     = ($page - 1) * $limit;
// --- Row-Level Security ---
// --- Row-Level Security & Filters ---
$where  = ['1=1'];
$params = [];
// 1. จัดการตรรกะสิทธิ์และการกรองเพื่อไม่ให้เกิด aa.user_id ซ้ำซ้อน
if ($userRole === 'webadmin' || $userRole === 'inventory') {
    // ถ้าเป็น webadmin/inventory บังคับดูเฉพาะของตัวเองเท่านั้น ( ignore ค่าจากฟอร์มกรอง )
    $where[]  = 'aa.user_id = ?';
    $params[] = $userId;
} else {
    // สิทธิ์อื่นๆ (เช่น admin) ถึงจะยอมให้กรองตาม auditorId จากฟอร์มค้นหาได้
    if ($auditorId !== '') {
        $where[]  = 'aa.user_id = ?';
        $params[] = $auditorId;
    }
}
// 2. ค้นหาคำสำคัญ
if ($search !== '') {
    $where[]  = '(a.asset_no LIKE ? OR a.asset_description LIKE ? OR a.serial_no LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
// 3. ตัวกรองอื่นๆ
if ($plant      !== '') {
    $where[] = 'a.plant_code = ?';
    $params[] = $plant;
}
if ($costCenter !== '') {
    $where[] = 'a.cost_center = ?';
    $params[] = $costCenter;
}
if ($status     !== '') {
    $where[] = 'aa.status = ?';
    $params[] = $status;
}
$whereStr = implode(' AND ', $where);
// --- Database Queries ---
// ดึงจำนวนรายการสินทรัพย์ทั้งหมด (ไม่นับซ้ำ)
$countSql = "SELECT COUNT(DISTINCT a.id) as c
             FROM audit_assignments aa
             JOIN assets a ON aa.asset_id = a.id
             WHERE $whereStr";
$total = $db->fetchOne($countSql, $params)['c'];
// ดึงข้อมูลโดยรวบตาม ID ของ สินทรัพย์ (a.id)
$dataSql = "SELECT MAX(aa.id) as assignment_id,
                   (SELECT status FROM audit_assignments WHERE id = MAX(aa.id)) as assignment_status,
                   (SELECT checked_at FROM audit_assignments WHERE id = MAX(aa.id)) as checked_at,
                   (SELECT remark FROM audit_assignments WHERE id = MAX(aa.id)) as assignment_remark,
                   (SELECT user_id FROM audit_assignments WHERE id = MAX(aa.id)) as assigned_user_id,
                   a.*,
                   u.full_name as auditor_name
            FROM audit_assignments aa
            JOIN assets a ON aa.asset_id = a.id
            LEFT JOIN users u ON aa.user_id = u.id
            WHERE $whereStr
            GROUP BY a.id
            ORDER BY a.asset_no ASC
            LIMIT $limit OFFSET $offset";
$assignments = $db->fetchAll($dataSql, $params);
$totalPages = max(1, ceil($total / $limit));
// ดึงรูปสินทรัพย์
foreach ($assignments as &$assignment) {
    $assignment['audit_images'] = [];
    if ($assignment['assignment_status'] !== 'completed') {
        continue;
    }
    $auditDir = __DIR__ . '/../uploads/audit/' . (int)$assignment['assignment_id'] . '/';
    if (!is_dir($auditDir)) {
        continue;
    }
    $imageFiles = [];
    foreach (['asset_*.jpg' => 'รูปทรัพย์', 'qr_code_*.jpg' => 'QR'] as $pattern => $label) {
        foreach (glob($auditDir . $pattern) ?: [] as $imagePath) {
            $imageFiles[] = [
                'url'   => APP_URL . '/uploads/audit/' . (int)$assignment['assignment_id'] . '/' . rawurlencode(basename($imagePath)),
                'label' => $label
            ];
        }
    }
    $assignment['audit_images'] = array_slice($imageFiles, 0, 3);
}
unset($assignment);
// --- Summary Stats ---
$statsWhere  = ['1=1'];
$statsParams = [];
if ($userRole === 'webadmin' || $userRole === 'inventory') {
    $statsWhere[] = 'aa.user_id = ?';
    $statsParams[] = $userId;
}
$statsWhereStr = implode(' AND ', $statsWhere);
$statsSql = "SELECT  latest_aa.user_id, sess.id AS session_id, a.plant_code, a.cost_center, latest_aa.status, COUNT(*) as qty,
                    summary.id as summary_id, summary.asset_officer_sig, summary.auditor_sig, summary.dept_sig, summary.dept_name,
                    u1.full_name as officer_name,
                    u2.full_name as auditor_name_summary,
                    u_assigned.full_name as auditor_name_assigned
             FROM assets a
             JOIN (
                 SELECT asset_id, MAX(id) as max_id
                 FROM audit_assignments aa
                 WHERE $statsWhereStr
                 GROUP BY asset_id
             ) as sub_aa ON a.id = sub_aa.asset_id
             JOIN audit_assignments latest_aa ON sub_aa.max_id = latest_aa.id
             JOIN users u_assigned ON latest_aa.user_id = u_assigned.id
             JOIN audit_sessions sess ON latest_aa.session_id = sess.id -- เชื่อมตาราง Session
             LEFT JOIN audit_summary summary ON a.plant_code = summary.plant_code
                  AND a.cost_center = summary.cost_center
                  AND sess.id = summary.session_id
             LEFT JOIN users u1 ON summary.asset_officer_id = u1.id
             LEFT JOIN users u2 ON summary.auditor_id = u2.id
GROUP BY
    latest_aa.user_id,
    sess.id,
    a.plant_code,
    a.cost_center,
    latest_aa.status,
    summary.id,
    summary.asset_officer_sig,
    summary.auditor_sig,
    u1.full_name,
    u2.full_name;";
$statsRaw = $db->fetchAll($statsSql, $statsParams);
$summary = ['total' => 0, 'completed' => 0, 'pending' => 0, 'breakdown' => []];
foreach ($statsRaw as $row) {
    $pKey = $row['plant_code'] ?: 'ไม่มี Plant';
    $cKey = $row['cost_center'] ?: 'ไม่มี CC';
    $uKey = $row['user_id'] ?:  'ไม่มีผู้ตรวจสอบ';
    if (!isset($summary['breakdown'][$pKey][$cKey][$uKey])) {
        $summary['breakdown'][$pKey][$cKey][$uKey] = [
            'total' => 0,
            'completed' => 0,
            'pending' => 0,
            'sessions_id' => $row['session_id'],
            'summary_id' => $row['summary_id'],
            'asset_officer_signature' => $row['asset_officer_sig'],
            'auditor_signature' => $row['auditor_sig'],
            'dept_signature' => $row['dept_sig'],
            'dept_name' => $row['dept_name'],
            'officer_name' => $row['officer_name'],
            'auditor_name' => $row['auditor_name_summary'] ?: $row['auditor_name_assigned']
        ];
    }
    $qty = (int)$row['qty'];
    $summary['total'] += $qty;
    $summary['breakdown'][$pKey][$cKey][$uKey]['total'] += $qty;
    if ($row['status'] === 'completed') {
        $summary['completed'] += $qty;
        $summary['breakdown'][$pKey][$cKey][$uKey]['completed'] += $qty;
    } else {
        $summary['pending'] += $qty;
        $summary['breakdown'][$pKey][$cKey][$uKey]['pending'] += $qty;
    }
}
$progressPercent = $summary['total'] > 0 ? round(($summary['completed'] / $summary['total']) * 100) : 0;
// --- Dropdown data ---
$plantDropdownSql    = "SELECT DISTINCT a.plant_code, p.plant_name FROM audit_assignments aa JOIN assets a ON aa.asset_id = a.id LEFT JOIN plants p ON a.plant_code = p.plant_code WHERE 1=1";
$plantDropdownParams = [];
if ($userRole === 'webadmin' || $userRole === 'inventory') {
    $plantDropdownSql .= " AND aa.user_id = ?";
    $plantDropdownParams[] = $userId;
}
$plants = $db->fetchAll($plantDropdownSql . " ORDER BY a.plant_code", $plantDropdownParams);
$auditorDropdownSql    = "SELECT DISTINCT u.id, u.full_name FROM audit_assignments aa JOIN users u ON aa.user_id = u.id WHERE 1=1";
$auditorDropdownParams = [];
if ($userRole === 'webadmin' || $userRole === 'inventory') {
    $auditorDropdownSql .= " AND aa.user_id = ?";
    $auditorDropdownParams[] = $userId;
}
$auditors = $db->fetchAll($auditorDropdownSql . " ORDER BY u.full_name", $auditorDropdownParams);
?>
<?php include __DIR__ . '/../components/head.php'; ?>
<!-- Libraries -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
    a,
    .sidebar a,
    .main-content a {
        text-decoration: none !important;
    }
    .btn-check:checked+.btn {
        background-color: var(--bs-btn-active-bg);
        color: #fff;
    }
    .inventory-page {
        width: 100%;
    }
    .inventory-page .main-content {
        width: 100%;
        max-width: none;
    }
    @media (min-width: 1024px) {
        .inventory-page .main-content {
            width: calc(100% - 18rem);
            max-width: calc(100% - 18rem);
        }
    }
    .audit-thumb {
        width: 42px;
        height: 42px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
        background: #f8fafc;
        cursor: pointer;
    }
    .audit-thumb:hover {
        border-color: #2563eb;
    }
    /* QR Scanner */
    #qr-reader {
        width: 100%;
    }
    #qr-reader video {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover;
        border-radius: 8px;
    }
    #qr-reader img,
    #qr-reader__dashboard,
    #qr-reader__header_message,
    #qr-reader__status_span,
    #qr-reader__camera_permission_button {
        display: none !important;
    }
    #qr-reader>div:first-child {
        padding: 0 !important;
        border: none !important;
    }
    /* Signature Pad */
    #signature-canvas {
        border: 2px dashed #adb5bd;
        border-radius: 8px;
        background: #f8f9fa;
        touch-action: none;
        width: 100%;
        height: 130px;
        cursor: crosshair;
    }
    #signature-canvas:active {
        border-color: #0d6efd;
    }
    /* Image preview cards */
    .img-preview-card {
        width: 100px;
        height: 82px;
        cursor: pointer;
        transition: transform .15s;
    }
    .img-preview-card:hover {
        transform: scale(1.05);
    }
    /* Toast */
    #toast-container {
        position: fixed;
        top: 16px;
        right: 16px;
        z-index: 9999;
        min-width: 240px;
    }
    @media (max-width: 767px) {
        .inventory-page {
            padding: 0 !important;
        }
        .inventory-page .main-content {
            padding: 12px !important;
        }
        .inventory-summary {
            border-left: 0;
            border-right: 0;
            border-radius: 0;
            margin-left: -12px;
            margin-right: -12px;
        }
        .inventory-summary-card {
            min-height: 86px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        #toast-container {
            right: 8px;
            left: 8px;
            width: auto;
            min-width: 0;
        }
        .img-preview-card {
            width: 72px;
            height: 72px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .table-responsive table {
            width: 100%;
            min-width: 640px;
        }
        .table-responsive td,
        .table-responsive th {
            white-space: normal;
        }
        #qr-reader {
            min-height: 200px;
        }
        #signature-canvas {
            height: 120px;
        }
    }
</style>
<div class="inventory-page min-h-screen w-full p-0 sm:p-6">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>
    <main class="main-content w-full p-3 sm:p-6 lg:ml-72 transition-all duration-300">
        <!-- Page Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5 border-b border-gray-200 pb-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    <i class="fa-solid fa-boxes-stacked text-blue-600 mr-2"></i>เมนูตรวจนับทรัพย์สิน
                </h1>
                <p class="text-sm text-gray-500 mt-0.5">ผู้ใช้งาน: <span class="font-semibold text-gray-700"><?= htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'User') ?></span></p>
            </div>
            <div class="flex items-center gap-3 ml-auto sm:ml-0">
                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-purple-100 text-purple-800 uppercase shadow-sm">
                    <i class="fa-solid fa-user-shield mr-1"></i><?= htmlspecialchars($userRole) ?>
                </span>
                <button class="bg-blue-600 hover:bg-blue-700 text-white font-medium text-xs px-3.5 py-2 rounded shadow flex items-center gap-1.5 transition"
                    onclick="openQRScanner()">
                    <i class="fa-solid fa-qrcode text-sm"></i> สแกน QR Code
                </button>
            </div>
        </div>
        <!-- Summary Dashboard -->
        <div class="inventory-summary bg-white border border-blue-100 rounded-lg p-4 mb-5 shadow-sm">
            <h5 class="text-sm font-bold text-blue-950 flex items-center gap-2 mb-3">
                <i class="fa-solid fa-circle-info text-blue-600"></i> ยอดสรุปภารกิจตรวจสอบทรัพย์สิน
            </h5>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-3">
                <div class="inventory-summary-card bg-blue-50/70 border border-blue-200 rounded p-3 text-center">
                    <div class="text-xs text-blue-700 font-medium">รายการทั้งหมด</div>
                    <div class="text-2xl font-extrabold text-blue-900 mt-1"><?= number_format($summary['total']) ?></div>
                </div>
                <div class="inventory-summary-card bg-green-50/70 border border-green-200 rounded p-3 text-center">
                    <div class="text-xs text-green-700 font-medium">ตรวจเสร็จสิ้น</div>
                    <div class="text-2xl font-extrabold text-green-900 mt-1"><?= number_format($summary['completed']) ?></div>
                </div>
                <div class="inventory-summary-card bg-yellow-50/70 border border-yellow-200 rounded p-3 text-center">
                    <div class="text-xs text-yellow-700 font-medium">คงเหลือ (Pending)</div>
                    <div class="text-2xl font-extrabold text-yellow-900 mt-1"><?= number_format($summary['pending']) ?></div>
                </div>
                <div class="inventory-summary-card bg-gray-50 border border-gray-200 rounded p-3">
                    <div class="text-xs text-gray-600 font-medium mb-1">ความคืบหน้า: <?= $progressPercent ?>%</div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5 mt-2">
                        <div class="bg-green-600 h-2.5 rounded-full transition-all duration-500" style="width: <?= $progressPercent ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="mt-2">
                <button class="text-xs text-blue-600 hover:text-blue-800 flex items-center gap-1 font-semibold focus:outline-none" type="button"
                    onclick="document.getElementById('breakdownSection').classList.toggle('hidden')">
                    <i class="fa-solid fa-list-check"></i> [คลิก] ดูข้อมูลจำแนกราย Plant และ Cost Center
                </button>
                <div id="breakdownSection" class="hidden mt-3 overflow-x-auto border border-gray-100 rounded">
                    <table class="min-w-full divide-y divide-gray-200 text-xs text-left text-gray-500">
                        <thead class="bg-gray-50 text-gray-700 font-semibold">
                            <tr>
                                <th class="p-2.5">Plant Code</th>
                                <th class="p-2.5">Cost Center</th>
                                <th class="p-2.5">จนท.ตรวจนับ</th>
                                <th class="p-2.5 text-center">รวม</th>
                                <th class="p-2.5 text-center text-green-700">ตรวจแล้ว</th>
                                <th class="p-2.5 text-center text-yellow-700">คงเหลือ</th>
                                <th>จนท.ทรัพย์สิน</th>
                                <th>จนท.ตรวจนับ</th>
                                <th>หัวหน้าแผนก</th>
                                <th>พิมพ์</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white font-mono">
                            <?php if (empty($summary['breakdown'])): ?>
                                <tr>
                                    <td colspan="9" class="p-3 text-center text-gray-400 font-sans">ไม่พบรายการ</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($summary['breakdown'] as $pCode => $ccData): ?>
                                    <?php foreach ($ccData as $ccCode => $val): ?>
                                        <?php foreach ($val as $auditor => $auditorData): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="p-2.5 font-bold text-gray-700"><?= htmlspecialchars($pCode) ?></td>
                                                <td class="p-2.5 text-gray-600"><?= htmlspecialchars($ccCode) ?></td>
                                                <td class="p-2.5 text-gray-600"><?= htmlspecialchars($auditor) ?></td>
                                                <td class="p-2.5 text-center font-semibold text-gray-900"><?= number_format($auditorData['total']) ?></td>
                                                <td class="p-2.5 text-center text-green-600 font-semibold"><?= number_format($auditorData['completed']) ?></td>
                                                <td class="p-2.5 text-center text-yellow-600 font-semibold"><?= number_format($auditorData['pending']) ?></td>
                                                <td class="p-2.5">
                                                    <?php if ($auditorData['pending'] == 0): ?>
                                                        <?php if (!empty($auditorData['asset_officer_signature'])): ?>
                                                            <div class="text-center">
                                                                <img src="<?= $auditorData['asset_officer_signature'] ?>" width="80" class="border mb-1 mx-auto d-block"
                                                                    style="cursor:pointer" onclick="openSignModal('asset_officer', '<?= $pCode ?>', '<?= $ccCode ?>', '<?= $auditorData['sessions_id'] ?>')">
                                                                <small class="text-muted d-block"><?= htmlspecialchars($auditorData['officer_name'] ?? '') ?></small>
                                                            </div>
                                                        <?php else: ?>
                                                            <button class="btn btn-sm btn-outline-primary" onclick="openSignModal('asset_officer', '<?= $pCode ?>', '<?= $ccCode ?>', '<?= $auditorData['sessions_id'] ?>')">
                                                                <i class="fa-solid fa-signature"></i> เซ็นชื่อ
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <small class="text-gray-400">ยังไม่ครบ</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-2.5">
                                                    <?php if ($auditorData['pending'] == 0): ?>
                                                        <?php if (!empty($auditorData['auditor_signature'])): ?>
                                                            <div class="text-center">
                                                                <img src="<?= $auditorData['auditor_signature'] ?>" width="80" class="border mb-1 mx-auto d-block"
                                                                    style="cursor:pointer" onclick="openSignModal('auditor', '<?= $pCode ?>', '<?= $ccCode ?>', '<?= $auditorData['sessions_id'] ?>')">
                                                                <small class="text-muted d-block"><?= htmlspecialchars($auditorData['auditor_name'] ?? '') ?></small>
                                                            </div>
                                                        <?php else: ?>
                                                            <button class="btn btn-sm btn-outline-success" onclick="openSignModal('auditor', '<?= $pCode ?>', '<?= $ccCode ?>', '<?= $auditorData['sessions_id'] ?>')">
                                                                <i class="fa-solid fa-signature"></i> เซ็นชื่อ
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <small class="text-gray-400">ยังไม่ครบ</small>
                                                    <?php endif; ?>
                                                </td>
                                                <!-- หัวหน้าแผนก -->
                                                <td class="p-2.5">
                                                    <?php if ($auditorData['pending'] == 0): ?>
                                                        <?php if (!empty($auditorData['dept_signature'])): ?>
                                                            <div class="text-center">
                                                                <img src="<?= htmlspecialchars($auditorData['dept_signature']) ?>" width="80" class="border mb-1 mx-auto d-block"
                                                                    style="cursor:pointer" onclick="openSignModal('dept', '<?= htmlspecialchars($pCode, ENT_QUOTES) ?>', '<?= htmlspecialchars($ccCode, ENT_QUOTES) ?>', '<?= $auditorData['sessions_id'] ?>')">
                                                                <small class="text-muted d-block"><?= htmlspecialchars($auditorData['dept_name'] ?? '') ?></small>
                                                            </div>
                                                        <?php else: ?>
                                                            <button class="btn btn-sm btn-outline-warning" onclick="openSignModal('dept', '<?= htmlspecialchars($pCode, ENT_QUOTES) ?>', '<?= htmlspecialchars($ccCode, ENT_QUOTES) ?>', '<?= $auditorData['sessions_id'] ?>')">
                                                                <i class="fa-solid fa-signature"></i> เซ็นชื่อ
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <small class="text-gray-400">ยังไม่ครบ</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-2.5 text-center">
                                                    <?php
                                                    $allSigned = !empty($auditorData['asset_officer_signature'])
                                                               && !empty($auditorData['auditor_signature'])
                                                               && !empty($auditorData['dept_signature']);
                                                    ?>
                                                    <?php if ($allSigned): ?>
                                                        <button class="btn btn-sm btn-primary" onclick="printRow('<?= (int)$auditorData['summary_id'] ?>')">
                                                            <i class="fa-solid fa-print"></i> พิมพ์
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-secondary" disabled title="ต้องเซ็นครบทั้งสามคนก่อนพิมพ์">
                                                            <i class="fa-solid fa-print"></i> พิมพ์
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal fade" id="signModal" tabindex="-1" aria-labelledby="signModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content shadow">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title" id="signModalLabel">
                            <i class="fa-solid fa-signature me-2"></i> ลงลายเซ็นออนไลน์
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3" id="signer-selection-wrapper">
                            <label class="form-label fw-bold">เลือกชื่อผู้เซ็น (จนท.ทรัพย์สิน):</label>
                            <select id="signer-name" class="form-select">
                                <option value="">-- กรุณาเลือกชื่อ --</option>
                                <?php
                                $officers = $db->query("SELECT id, full_name FROM users WHERE role IN ('webadmin', 'inventory') ")->fetchAll();
                                foreach ($officers as $u) {
                                    echo '<option value="' . htmlspecialchars($u['id']) . '">' . htmlspecialchars($u['full_name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3" id="signer-name-wrapper" style="display:none;">
                            <label class="form-label fw-bold">ชื่อ-สกุล หัวหน้าแผนก <span class="text-danger">*</span></label>
                            <input type="text" id="signer-fullname" class="form-control" placeholder="กรอกชื่อ-นามสกุลหัวหน้าแผนก">
                        </div>
                        <div class="text-center bg-light border rounded p-2 mb-2">
                            <canvas id="signature-pad" class="w-100" style="height: 200px; touch-action: none;"></canvas>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="clearSignature()">
                                <i class="fa-solid fa-eraser me-1"></i> ล้างลายเซ็น
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer bg-light p-2">
                        <button type="button" class="btn btn-sm btn-secondary px-3" onclick="closeSignModal()">ปิดหน้าต่าง</button>
                        <button type="button" class="btn btn-sm btn-success px-4" id="save-btn" onclick="saveSignature()">
                            <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกลายเซ็น
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- Filter Form -->
        <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm mb-4">
            <form method="GET" action="inventory.php" id="searchFilterForm">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">คำค้นหา</label>
                        <input type="text" name="search" id="filter-search" value="<?= htmlspecialchars($search) ?>"
                            placeholder="รหัสทรัพย์สิน, คำอธิบาย..."
                            class="w-full text-xs border border-gray-300 rounded p-2 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Plant</label>
                        <select name="plant" id="filter-plant" onchange="fetchCostCentersAjax(this.value)"
                            class="w-full text-xs border border-gray-300 rounded p-2 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            <option value="">-- เลือกทั้งหมด --</option>
                            <?php foreach ($plants as $p): ?>
                                <option value="<?= htmlspecialchars($p['plant_code']) ?>" <?= $plant === $p['plant_code'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['plant_code'] . ' ' . ($p['plant_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Cost Center</label>
                        <select name="cost_center" id="filter-cost-center"
                            class="w-full text-xs border border-gray-300 rounded p-2 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            <option value="">-- เลือกทั้งหมด --</option>
                            <?php if ($costCenter !== ''): ?>
                                <option value="<?= htmlspecialchars($costCenter) ?>" selected><?= htmlspecialchars($costCenter) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">ผู้ตรวจนับ</label>
                        <select name="auditor_id" id="filter-auditor"
                            class="w-full text-xs border border-gray-300 rounded p-2 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            <option value="">-- เลือกทั้งหมด --</option>
                            <?php foreach ($auditors as $aud): ?>
                                <option value="<?= $aud['id'] ?>" <?= $auditorId === (string)$aud['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($aud['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">สถานะ</label>
                        <select name="status" id="filter-status"
                            class="w-full text-xs border border-gray-300 rounded p-2 focus:outline-none focus:ring-1 focus:ring-blue-500">
                            <option value="">-- ทั้งหมด --</option>
                            <option value="pending" <?= $status === 'pending'   ? 'selected' : '' ?>>รอดำเนินการ</option>
                            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>ตรวจเสร็จสิ้น</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2 mt-3 pt-2 border-t border-gray-100">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium text-xs py-2 px-4 rounded transition shadow-sm">
                        <i class="fas fa-search mr-1"></i> กรองข้อมูล
                    </button>
                    <a href="inventory.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium text-xs py-2 px-4 rounded transition text-center no-underline">
                        <i class="fas fa-times mr-1"></i> ล้างตัวกรอง
                    </a>
                    <div class="ml-auto flex items-center gap-2">
                        <span class="text-xs text-gray-500">แสดง:</span>
                        <select name="limit" onchange="this.form.submit()" class="text-xs border border-gray-300 rounded p-1">
                            <option value="10" <?= $limit === 10 ? 'selected' : '' ?>>10 แถว</option>
                            <option value="20" <?= $limit === 20 ? 'selected' : '' ?>>20 แถว</option>
                            <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50 แถว</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <!-- Data Table -->
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
            <div class="overflow-x-auto table-responsive">
                <table class="min-w-full divide-y divide-gray-200 text-sm text-left text-gray-700">
                    <thead class="bg-gray-50 text-xs font-semibold text-gray-600 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-center" style="width:55px;">ลำดับ</th>
                            <th class="px-4 py-3">Plant / Cost Center</th>
                            <th class="px-4 py-3">รหัสทรัพย์สิน</th>
                            <th class="px-4 py-3">รายละเอียด</th>
                            <th class="px-4 py-3">ผู้รับมอบหมาย</th>
                            <th class="px-4 py-3 text-center">สถานะ</th>
                            <th class="px-4 py-3 text-center" style="width:150px;">รูปภาพ</th>
                            <th class="px-4 py-3 text-center" style="width:110px;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-xs">
                        <?php if (empty($assignments)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-12 text-gray-400 font-sans">
                                    <i class="fas fa-inbox text-3xl mb-2 block opacity-30"></i>
                                    ไม่พบรายการตรวจนับตามเงื่อนไขที่ระบุ
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assignments as $idx => $a): ?>
                                <?php $objResult = json_decode($a['assignment_remark'] ?? '{}', true); ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3 text-center text-gray-400 font-mono"><?= $offset + $idx + 1 ?></td>
                                    <td class="px-4 py-3">
                                        <span class="font-mono bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded font-bold text-[10px]"><?= htmlspecialchars($a['plant_code'] ?? '-') ?></span>
                                        <div class="text-[10px] text-gray-400 font-mono mt-1"><?= htmlspecialchars($a['cost_center'] ?? '-') ?></div>
                                    </td>
                                    <td class="px-4 py-3 font-mono font-bold text-blue-600"><?= htmlspecialchars($a['asset_no']) ?></td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900 max-w-[280px] truncate" title="<?= htmlspecialchars($a['asset_description']) ?>">
                                            <?= htmlspecialchars($a['asset_description']) ?>
                                        </div>
                                        <div class="text-[10px] text-gray-400 mt-0.5">
                                            <!-- S/N: <?= htmlspecialchars($a['serial_no'] ?: '-') ?> | Model: <?= htmlspecialchars($a['model'] ?: '-') ?> -->
                                             ผู้รับผิดชอบ: <?= htmlspecialchars($objResult['responsible_person'] ?? $a['municipality'] ?? '-') ?> | Location: <?= htmlspecialchars($objResult['location'] ?? '-') ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-gray-600">
                                        <i class="fa-solid fa-user-check text-gray-400 mr-1"></i><?= htmlspecialchars($a['auditor_name'] ?? 'ยังไม่ระบุ') ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($a['assignment_status'] === 'completed'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-800">
                                                <i class="fa-solid fa-circle-check mr-1"></i> ตรวจแล้ว
                                            </span>
                                            <?php if ($a['checked_at']): ?>
                                                <div class="text-[9px] text-gray-400 mt-0.5"><?= htmlspecialchars(date('d/m/y H:i', strtotime($a['checked_at']))) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-yellow-100 text-yellow-800">
                                                <i class="fa-solid fa-clock mr-1"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($a['assignment_status'] === 'completed' && !empty($a['audit_images'])): ?>
                                            <div class="flex items-center justify-center gap-1.5">
                                                <?php foreach ($a['audit_images'] as $img): ?>
                                                    <button type="button" class="p-0 border-0 bg-transparent position-relative"
                                                        onclick="previewFullImage('<?= htmlspecialchars($img['url'], ENT_QUOTES) ?>')"
                                                        title="<?= htmlspecialchars($img['label']) ?>">
                                                        <img src="<?= htmlspecialchars($img['url']) ?>" class="audit-thumb" alt="<?= htmlspecialchars($img['label']) ?>">
                                                        <span class="position-absolute bottom-0 start-50 translate-middle-x bg-dark text-white px-1 rounded-top"
                                                            style="font-size:8px;line-height:1.2;opacity:.82;"><?= htmlspecialchars($img['label']) ?></span>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($a['assignment_status'] === 'completed'): ?>
                                            <div class="text-center text-[10px] text-gray-400">
                                                <i class="fa-regular fa-image d-block mb-1"></i>ไม่มีรูป
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center text-gray-300">-</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php $canModify = ($userRole === 'admin') || ($a['assigned_user_id'] == $userId); ?>
                                        <?php if ($canModify): ?>
                                            <?php
                                            $assetDetailsForModal = [
                                                'asset_no'          => $a['asset_no'] ?? '',
                                                'asset_description' => $a['asset_description'] ?? '',
                                                'plant_code'        => $a['plant_code'] ?? '',
                                                'cost_center'       => $a['cost_center'] ?? '',
                                                'serial_no'         => $a['serial_no'] ?? '',
                                                'model'             => $a['model'] ?? '',
                                                'asset_location'    => $a['location'] ?? '',
                                                'municipality'      => $a['municipality'] ?? '',
                                                'auditor_name'      => $a['auditor_name'] ?? '',
                                                'audit_images'      => $a['audit_images'] ?? []
                                            ];
                                            ?>
                                            <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-[11px] font-medium inline-flex items-center gap-1 shadow-sm transition"
                                                onclick='openUpdateModal(<?= (int)$a['assignment_id'] ?>, <?= json_encode($a['asset_no'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($a['asset_description'] ?? '', JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($assetDetailsForModal, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                <i class="fa-solid fa-clipboard-check"></i> ตรวจนับ
                                            </button>
                                        <?php else: ?>
                                            <button class="bg-gray-100 text-gray-400 px-3 py-1 rounded text-[11px] cursor-not-allowed" disabled>
                                                <i class="fa-solid fa-lock"></i> ล็อค
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <?php
                $paginationPages = [1];
                $startPage = max(2, $page - 2);
                $endPage   = min($totalPages - 1, $page + 2);
                if ($page <= 3) {
                    $endPage = min($totalPages - 1, 5);
                }
                if ($page >= $totalPages - 2) {
                    $startPage = max(2, $totalPages - 4);
                }
                for ($i = $startPage; $i <= $endPage; $i++) {
                    $paginationPages[] = $i;
                }
                if ($totalPages > 1) {
                    $paginationPages[] = $totalPages;
                }
                $paginationPages = array_values(array_unique($paginationPages));
                sort($paginationPages);
                $lastRenderedPage = 0;
                ?>
                <div class="bg-gray-50 border-t border-gray-100 px-4 py-3 text-xs text-gray-500">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="whitespace-nowrap text-center sm:text-left">
                            แสดงแถวที่ <?= $offset + 1 ?> – <?= min($offset + $limit, $total) ?> จาก <?= number_format($total) ?> รายการ
                        </div>
                        <div class="flex gap-1 flex-wrap justify-center sm:justify-end">
                            <?php if ($page > 1): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="px-2.5 h-8 inline-flex items-center justify-center bg-white border border-gray-300 rounded hover:bg-gray-100 no-underline">ก่อนหน้า</a>
                            <?php endif; ?>
                            <?php foreach ($paginationPages as $i): ?>
                                <?php if ($lastRenderedPage && $i > $lastRenderedPage + 1): ?>
                                    <span class="w-8 h-8 inline-flex items-center justify-center text-gray-400">...</span>
                                <?php endif; ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                                    class="w-8 h-8 inline-flex items-center justify-center rounded border no-underline <?= $i === $page ? 'bg-blue-600 text-white font-bold border-blue-600' : 'bg-white border-gray-300 hover:bg-gray-100' ?>"><?= $i ?></a>
                                <?php $lastRenderedPage = $i; ?>
                            <?php endforeach; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="px-2.5 h-8 inline-flex items-center justify-center bg-white border border-gray-300 rounded hover:bg-gray-100 no-underline">ถัดไป</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<!-- ═══════════════════════════════════════════════════════════
     TOAST CONTAINER
═══════════════════════════════════════════════════════════ -->
<div id="toast-container"></div>
<!-- ═══════════════════════════════════════════════════════════
     MODAL: QR SCANNER
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="qrScannerModal" tabindex="-1" aria-hidden="true" style="z-index:1070;">
    <div class="modal-dialog modal-fullscreen-sm-down modal-dialog-centered" style="max-width:400px;">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white p-3">
                <h5 class="modal-title fs-6"><i class="fa-solid fa-qrcode me-2"></i> สแกน QR Code ทรัพย์สิน</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <p class="text-muted text-center" style="font-size:12px;">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    ระบบจะค้นหาด้วยรหัสก่อน <code>-</code> เช่น <strong>AEQF007281</strong>-0000-0000
                </p>
                <div id="qr-reader" class="rounded overflow-hidden bg-black" style="min-height:260px;"></div>
                <div id="qr-reader-status" class="text-center mt-2 text-muted" style="font-size:11px;">
                    <span class="spinner-border spinner-border-sm me-1"></span> กำลังเปิดกล้อง...
                </div>
                <div class="mt-3 border-top pt-3">
                    <label class="form-label text-xs fw-bold text-muted">ถ่าย/เลือกรูป QR Code แทนการเปิดกล้อง</label>
                    <input type="file" id="scan-qr-image-input" class="form-control form-control-sm" accept="image/*" capture="environment">
                    <div class="text-muted mt-1" style="font-size:10px;">ใช้ได้ในกรณีเบราว์เซอร์บล็อกกล้องจาก HTTP</div>
                </div>
                <!-- Manual input fallback -->
                <div class="mt-3 border-top pt-3">
                    <label class="form-label text-xs fw-bold text-muted">หรือพิมพ์รหัส QR ด้วยตนเอง</label>
                    <div class="input-group input-group-sm">
                        <input type="text" id="manual-qr-input" class="form-control" placeholder="เช่น AEQF007281-0000-0000">
                        <button class="btn btn-primary" onclick="manualQRSearch()">ค้นหา</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- ═══════════════════════════════════════════════════════════
     MODAL: QR RESULTS LIST (multiple found)
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="qrResultsModal" tabindex="-1" aria-hidden="true" style="z-index:1080;">
    <div class="modal-dialog modal-fullscreen-sm-down modal-dialog-centered modal-dialog-scrollable" style="max-width:440px;">
        <div class="modal-content">
            <div class="modal-header bg-info text-white p-3">
                <h5 class="modal-title fs-6">
                    <i class="fa-solid fa-list-check me-2"></i>
                    พบหลายรายการสำหรับรหัส: <span id="qr-results-prefix" class="fw-bold font-monospace"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <p class="text-muted px-2" style="font-size:11px;">
                    <i class="fa-solid fa-hand-pointer me-1"></i> แตะรายการที่ต้องการตรวจนับ
                </p>
                <div class="list-group list-group-flush" id="qr-results-list"></div>
            </div>
        </div>
    </div>
</div>
<!-- ═══════════════════════════════════════════════════════════
     MODAL: IMAGE FULL PREVIEW
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true" style="z-index:1090;">
    <div class="modal-dialog modal-fullscreen-sm-down modal-dialog-centered modal-lg">
        <div class="modal-content bg-black">
            <div class="modal-header border-0 p-2">
                <span class="text-white text-xs ms-auto">ตัวอย่างรูปภาพ (640×480 บีบอัด)</span>
                <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 text-center">
                <img id="preview-modal-img" src="" alt="preview" style="max-width:100%; max-height:70vh; object-fit:contain;">
            </div>
        </div>
    </div>
</div>
<!-- ═══════════════════════════════════════════════════════════
     MODAL: AUDIT FORM
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="updateAuditModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true" style="z-index:1060;">
    <div class="modal-dialog modal-fullscreen-sm-down modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white p-3">
                <h5 class="modal-title fs-6"><i class="fa-solid fa-file-pen me-2"></i> บันทึกผลการตรวจนับจากหน้างาน</h5>
                <button type="button" class="btn-close btn-close-white" onclick="closeAuditModal()"></button>
            </div>
            <div class="modal-body text-sm" style="max-height:75vh;overflow-y:auto;">
                <!-- Asset Info -->
                <div class="alert alert-secondary py-2 mb-3 rounded border-0">
                    <div class="row text-xs g-2">
                        <div class="col-md-5">
                            <strong>รหัสทรัพย์สิน:</strong>
                            <span id="modal-asset-no" class="font-monospace text-primary fw-bold ms-1">-</span>
                        </div>
                        <div class="col-md-7">
                            <strong>รายละเอียด:</strong>
                            <span id="modal-asset-desc" class="ms-1">-</span>
                        </div>
                        <div class="col-6 col-md-3">
                            <strong>Plant:</strong>
                            <span id="modal-asset-plant" class="font-monospace ms-1">-</span>
                        </div>
                        <div class="col-6 col-md-3">
                            <strong>Cost Center:</strong>
                            <span id="modal-asset-cost-center" class="font-monospace ms-1">-</span>
                        </div>
                        <div class="col-6 col-md-3">
                            <strong>S/N:</strong>
                            <span id="modal-asset-serial" class="font-monospace ms-1">-</span>
                        </div>
                        <div class="col-6 col-md-3">
                            <strong>Model:</strong>
                            <span id="modal-asset-model" class="font-monospace ms-1">-</span>
                        </div>
                        <div class="col-md-6">
                            <strong>Location เดิม:</strong>
                            <span id="modal-asset-location" class="ms-1">-</span>
                        </div>
                        <div class="col-md-6">
                            <strong>ผู้รับผิดชอบ:</strong>
                            <span id="modal-asset-owner" class="ms-1">-</span>
                        </div>
                    </div>
                </div>
                <form id="auditForm" onsubmit="event.preventDefault();">
                    <div class="row g-3">
                        <!-- Status Buttons -->
                        <div class="col-12">
                            <label class="form-label fw-bold text-danger" style="font-size:11px;">
                                ผลการตรวจสอบทรัพย์สิน <span class="text-danger">*</span>
                            </label>
                            <div class="row row-cols-2 row-cols-md-5 g-2">
                                <div class="col">
                                    <input type="radio" class="btn-check" name="check_status" id="status-active" value="active" checked>
                                    <label class="btn btn-outline-success w-100 py-2 text-center" style="font-size:11px;" for="status-active">
                                        <i class="fa-solid fa-circle-check d-block mb-1 fs-6"></i>1. ใช้งาน
                                    </label>
                                </div>
                                <div class="col">
                                    <input type="radio" class="btn-check" name="check_status" id="status-returned" value="returned">
                                    <label class="btn btn-outline-info w-100 py-2 text-center" style="font-size:11px;" for="status-returned">
                                        <i class="fa-solid fa-rotate-left d-block mb-1 fs-6"></i>2. ชำรุด-ส่งคืน
                                    </label>
                                </div>
                                <div class="col">
                                    <input type="radio" class="btn-check" name="check_status" id="status-notfound" value="not_found">
                                    <label class="btn btn-outline-danger w-100 py-2 text-center" style="font-size:11px;" for="status-notfound">
                                        <i class="fa-solid fa-circle-xmark d-block mb-1 fs-6"></i>3. ไม่พบ
                                    </label>
                                </div>
                                <div class="col">
                                    <input type="radio" class="btn-check" name="check_status" id="status-inactive" value="inactive">
                                    <label class="btn btn-outline-secondary w-100 py-2 text-center" style="font-size:11px;" for="status-inactive">
                                        <i class="fa-solid fa-ban d-block mb-1 fs-6"></i>4. ไม่ใช้งาน
                                    </label>
                                </div>
                                <div class="col">
                                    <input type="radio" class="btn-check" name="check_status" id="status-repairing" value="repairing">
                                    <label class="btn btn-outline-warning w-100 py-2 text-center" style="font-size:11px;" for="status-repairing">
                                        <i class="fa-solid fa-screwdriver-wrench d-block mb-1 fs-6"></i>5. ชำรุด-รอซ่อม
                                    </label>
                                </div>
                            </div>
                        </div>
                        <!-- Quantity & Location -->
                        <div class="col-md-3">
                            <label for="quantity" class="form-label fw-bold" style="font-size:11px;">จำนวนที่นับเจอจริง</label>
                            <input type="number" class="form-control form-control-sm" id="quantity" value="1" min="0">
                        </div>
                        <div class="col-md-5">
                            <label for="location" class="form-label fw-bold" style="font-size:11px;">ห้อง / พิกัดสถานที่จริง</label>
                            <input type="text" class="form-control form-control-sm" id="location" placeholder="เช่น อาคาร บี ชั้น 3 ห้องฝ่ายวางแผน">
                        </div>
                        <div class="col-md-4">
                            <label for="responsible_person" class="form-label fw-bold" style="font-size:11px;">ผู้รับผิดชอบปัจจุบัน</label>
                            <input type="text" class="form-control form-control-sm" id="responsible_person" placeholder="ระบุชื่อผู้รับผิดชอบ">
                        </div>
                        <!-- Upload Buttons -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold" style="font-size:11px;">
                                <i class="fa-solid fa-camera me-1 text-primary"></i>
                                1. ถ่ายภาพทรัพย์สิน <span class="text-muted">(≤ 2 รูป, บีบอัด 640×480)</span>
                            </label>
                            <input type="file" class="form-control form-control-sm" id="upload-assets" accept="image/*" capture="environment" multiple>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold" style="font-size:11px;">
                                <i class="fa-solid fa-qrcode me-1 text-purple-600"></i>
                                2. ถ่ายสติกเกอร์ QR Code <span class="text-muted">(≤ 1 รูป)</span>
                            </label>
                            <input type="file" class="form-control form-control-sm" id="upload-qrcodes" accept="image/*" capture="environment">
                        </div>
                        <!-- Image Preview Zone -->
                        <div class="col-12">
                            <label class="form-label fw-bold mb-1" style="font-size:11px;">
                                คลังรูปภาพ (Preview)
                                <span class="text-danger ms-1" id="image-count-txt">(0/3 รูป)</span>
                                <span class="text-muted fw-normal"> — คลิกรูปเพื่อดูขยาย · กด × เพื่อลบ</span>
                            </label>
                            <div class="d-flex flex-wrap gap-2 p-2 border rounded bg-light" id="images-preview-zone" style="min-height:88px;">
                                <div class="text-muted m-auto" id="preview-placeholder" style="font-size:11px;">
                                    <i class="fa-solid fa-images opacity-30 fa-2x d-block text-center mb-1"></i>
                                    ยังไม่มีรูปภาพ
                                </div>
                            </div>
                        </div>
                        <!-- Signature Pad -->
                        <div class="col-12">
                            <label class="form-label fw-bold" style="font-size:11px;">
                                <i class="fa-solid fa-signature me-1 text-danger"></i>
                                ลายเซ็นผู้ตรวจนับ
                                <span class="text-muted fw-normal">(เซ็นบนพื้นที่ด้านล่าง)</span>
                            </label>
                            <div class="position-relative">
                                <canvas id="signature-canvas"></canvas>
                                <button type="button" onclick="clearSignature()"
                                    class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 mt-1 me-1"
                                    style="font-size:10px; padding:2px 8px;">
                                    <i class="fa-solid fa-eraser me-1"></i>ล้าง
                                </button>
                            </div>
                            <div class="text-muted mt-1" style="font-size:10px;">
                                <i class="fa-solid fa-info-circle me-1"></i>
                                ใช้นิ้วหรือ stylus เซ็นลายมือในกรอบสีเทาด้านบน
                            </div>
                        </div>
                        <!-- Remarks -->
                        <div class="col-12">
                            <label for="remarks" class="form-label fw-bold" style="font-size:11px;">หมายเหตุ</label>
                            <textarea class="form-control form-control-sm" id="remarks" rows="2"
                                placeholder="กรอกเพิ่มเติมถ้าทรัพย์สินชำรุด / สูญหาย / โอนย้าย ..."></textarea>
                        </div>
                    </div><!-- /.row -->
                </form>
            </div>
            <div class="modal-footer bg-light p-2">
                <button type="button" class="btn btn-sm btn-secondary px-3" onclick="closeAuditModal()">ปิดหน้าต่าง</button>
                <button type="button" class="btn btn-sm btn-success px-4" id="save-btn" onclick="saveAuditData()">
                    <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกรายการ
                </button>
            </div>
        </div>
    </div>
</div>
<!-- ═══════════════════════════════════════════════════════════
     SCRIPTS
═══════════════════════════════════════════════════════════ -->
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
<script src="<?= APP_URL ?>/assets/js/html5-qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script>
    // ══════════════════════════════════════════════════════
    //  GLOBALS
    // ══════════════════════════════════════════════════════
    let auditBsModal = null;
    let qrBsModal = null;
    let signaturePad = null;
    let html5QrCode = null;
    let qrScannerRunning = false;
    let qrScanHandled = false;
    let currentAssetId = null;
    let assetsImagesList = []; // [{base64, saved}]
    let qrCodesImagesList = []; // [{base64, saved}]
    // ══════════════════════════════════════════════════════
    //  INIT
    // ══════════════════════════════════════════════════════
    document.addEventListener('DOMContentLoaded', function() {
        // Bootstrap modals
        auditBsModal = new bootstrap.Modal(document.getElementById('updateAuditModal'));
        qrBsModal = new bootstrap.Modal(document.getElementById('qrScannerModal'));
        // Upload listeners
        document.getElementById('upload-assets').addEventListener('change', (e) => handleImageProcessing(e, 'assets', 2));
        document.getElementById('upload-qrcodes').addEventListener('change', (e) => handleImageProcessing(e, 'qr_codes', 1));
        document.getElementById('scan-qr-image-input').addEventListener('change', handleQRImageScan);
        // QR modal lifecycle
        document.getElementById('qrScannerModal').addEventListener('shown.bs.modal', () => setTimeout(startQRScanner, 300));
        document.getElementById('qrScannerModal').addEventListener('hidden.bs.modal', stopQRScanner);
        // Audit modal: init signature when shown
        document.getElementById('updateAuditModal').addEventListener('shown.bs.modal', function() {
            initSignaturePad();
        });
        document.getElementById('signModal').addEventListener('shown.bs.modal', function() {
            initSignaturePad('signature-pad');
            if (typeof pendingSigData !== 'undefined' && pendingSigData && signaturePad) {
                signaturePad.fromDataURL(pendingSigData);
                pendingSigData = null;
            }
        });
        document.getElementById('signModal').addEventListener('hidden.bs.modal', function() {
            // เคลียร์ทุกอย่างเมื่อปิด Modal
            document.getElementById('signer-name').value = '';
            const fullnameInput = document.getElementById('signer-fullname');
            if (fullnameInput) fullnameInput.value = '';
            if (typeof signaturePad !== 'undefined') {
                signaturePad.clear();
            }
            // รีเซ็ต currentData ป้องกันการนำค่าเก่าไปใช้
            currentData = {
                type: '',
                pCode: '',
                ccCode: ''
            };
        });
        // Cost center cascade
        const initialPlant = document.getElementById('filter-plant').value;
        fetchCostCentersAjax(initialPlant, '<?= htmlspecialchars($costCenter) ?>');
    });
    // ══════════════════════════════════════════════════════
    //  COST CENTER CASCADE
    // ══════════════════════════════════════════════════════
    function fetchCostCentersAjax(plantCode, selectedCc = '') {
        const ccSelect = document.getElementById('filter-cost-center');
        ccSelect.innerHTML = '<option value="">-- กำลังโหลด... --</option>';
        fetch(`inventory.php?action=get_cost_centers&plant_code=${encodeURIComponent(plantCode)}`)
            .then(r => r.json())
            .then(data => {
                ccSelect.innerHTML = '<option value="">-- เลือกทั้งหมด --</option>';
                data.forEach(cc => {
                    const opt = document.createElement('option');
                    opt.value = cc;
                    opt.textContent = cc;
                    if (cc === selectedCc) opt.selected = true;
                    ccSelect.appendChild(opt);
                });
            })
            .catch(() => {
                ccSelect.innerHTML = '<option value="">-- โหลดไม่สำเร็จ --</option>';
            });
    }
    // ══════════════════════════════════════════════════════
    //  QR SCANNER (แก้ไขใหม่ทั้งหมด)
    // ══════════════════════════════════════════════════════
    // ตัวแปรสำหรับเก็บ instance ของ modal
    let qrResultsModal = null;
    function openQRScanner() {
        qrScanHandled = false;
        // รีเซ็ตค่าใน modal
        const manualInput = document.getElementById('manual-qr-input');
        if (manualInput) manualInput.value = '';
        const qrReader = document.getElementById('qr-reader');
        if (qrReader) qrReader.innerHTML = '';
        const statusDiv = document.getElementById('qr-reader-status');
        if (statusDiv) {
            statusDiv.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> กำลังเปิดกล้อง...';
        }
        // แสดง modal
        if (qrBsModal) qrBsModal.show();
    }
    function startQRScanner() {
        const qrReader = document.getElementById('qr-reader');
        if (!qrReader) {
            console.error('ไม่พบ element qr-reader');
            return;
        }
        const statusDiv = document.getElementById('qr-reader-status');
        const doStart = () => {
            if (typeof Html5Qrcode === 'undefined') {
                if (statusDiv) {
                    statusDiv.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-xmark me-1"></i>ไม่พบไลบรารีอ่าน QR Code</span>';
                }
                return;
            }
            qrReader.innerHTML = '';
            html5QrCode = new Html5Qrcode('qr-reader');
            qrScanHandled = false;
            const config = {
                fps: 10,
                qrbox: {
                    width: 200,
                    height: 200
                },
                aspectRatio: 1.0,
                verbose: false
            };
            const onSuccess = (decodedText) => {
                if (qrScanHandled) return;
                qrScanHandled = true;
                // สแกนสำเร็จ
                console.log('สแกนสำเร็จ:', decodedText);
                stopQRScanner();
                if (qrBsModal) qrBsModal.hide();
                const normalized = normalizeQRCodeText(decodedText);
                // แสดง toast แทน alert
                showToast('✅ สแกนสำเร็จ: ' + normalized.display.substring(0, 30) + (normalized.display.length > 30 ? '...' : ''), 'success');
                // ประมวลผล QR code
                processQRCode(decodedText.trim());
            };
            html5QrCode.start({
                    facingMode: 'environment'
                },
                config,
                onSuccess,
                () => {
                    // ไม่พบ QR code ในเฟรม - ไม่ต้องทำอะไร (เงียบไว้)
                }
            ).then(() => {
                qrScannerRunning = true;
                if (statusDiv) {
                    statusDiv.innerHTML = '<i class="fa-solid fa-camera text-success me-1"></i>กล้องพร้อมแล้ว — เล็งที่ QR Code';
                }
            }).catch(err => {
                qrScannerRunning = false;
                const statusDiv = document.getElementById('qr-reader-status');
                if (statusDiv) {
                    statusDiv.innerHTML = '<span class="text-danger"><i class="fa-solid fa-camera-slash me-1"></i>ไม่สามารถเปิดกล้อง: ' + escHtml(String(err)) + '</span>';
                }
                console.error('Scanner error:', err);
            });
        };
        if (html5QrCode) {
            html5QrCode.stop()
                .catch(() => {})
                .finally(() => {
                    try {
                        html5QrCode.clear();
                    } catch (err) {}
                    html5QrCode = null;
                    qrScannerRunning = false;
                    doStart();
                });
        } else {
            doStart();
        }
    }
    function stopQRScanner() {
        if (html5QrCode && qrScannerRunning) {
            const scanner = html5QrCode;
            qrScannerRunning = false;
            html5QrCode = null;
            scanner.stop()
                .then(() => {
                    console.log('Scanner stopped successfully');
                    scanner.clear();
                })
                .catch(err => {
                    console.warn('Error stopping scanner:', err);
                });
        } else if (html5QrCode) {
            try {
                html5QrCode.clear();
            } catch (err) {}
            html5QrCode = null;
        }
    }
    function manualQRSearch() {
        const val = document.getElementById('manual-qr-input')?.value.trim();
        if (!val) {
            showToast('⚠️ กรุณาพิมพ์รหัส QR ก่อนค้นหา', 'warning');
            return;
        }
        // ปิด modal scanner
        if (qrBsModal) qrBsModal.hide();
        // แสดง loading
        showToast('🔍 กำลังค้นหารหัส: ' + escHtml(val.substring(0, 30)) + '...', 'info');
        // ประมวลผล
        processQRCode(val);
    }
    function handleQRImageScan(event) {
        const input = event.target;
        const file = input.files && input.files[0];
        if (!file) return;
        const statusDiv = document.getElementById('qr-reader-status');
        if (typeof Html5Qrcode === 'undefined') {
            showToast('❌ ไม่พบไลบรารีอ่าน QR Code', 'danger');
            input.value = '';
            return;
        }
        if (statusDiv) {
            statusDiv.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังอ่าน QR จากรูป...';
        }
        stopQRScanner();
        const imageScanner = new Html5Qrcode('qr-reader');
        imageScanner.scanFile(file, true)
            .then(decodedText => {
                if (qrBsModal) qrBsModal.hide();
                showToast('✅ อ่าน QR จากรูปสำเร็จ', 'success');
                processQRCode(decodedText.trim());
            })
            .catch(err => {
                console.error('QR image scan error:', err);
                if (statusDiv) {
                    statusDiv.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-xmark me-1"></i>อ่าน QR จากรูปไม่ได้ กรุณาถ่ายให้ชัดและให้ QR อยู่เต็มกรอบ</span>';
                }
                showToast('❌ อ่าน QR จากรูปไม่ได้', 'warning');
            })
            .finally(() => {
                try {
                    imageScanner.clear();
                } catch (err) {}
                input.value = '';
            });
    }
    function normalizeQRCodeText(qrText) {
        const raw = String(qrText || '').trim();
        let prefix = raw.includes('-') ? raw.split('-')[0].trim() : raw;
        let display = prefix || raw;
        try {
            const url = new URL(raw);
            const assetId = url.searchParams.get('id') || url.searchParams.get('asset_id');
            if (assetId) {
                prefix = 'ID ' + assetId;
                display = prefix;
            }
        } catch (err) {
            const idMatch = raw.match(/(?:^|[?&])(?:id|asset_id)=(\d+)/);
            if (idMatch) {
                prefix = 'ID ' + idMatch[1];
                display = prefix;
            }
        }
        return {
            raw,
            prefix,
            display
        };
    }
    function processQRCode(qrText) {
        // ตรวจสอบว่ามีข้อความหรือไม่
        if (!qrText || qrText.trim() === '') {
            showToast('❌ รหัส QR ว่างเปล่า', 'warning');
            return;
        }
        const normalized = normalizeQRCodeText(qrText);
        let prefix = normalized.prefix;
        if (!prefix || prefix.trim() === '') {
            showToast('❌ รหัส QR ไม่ถูกต้อง: ' + escHtml(qrText.substring(0, 50)), 'danger');
            return;
        }
        showToast('🔍 ค้นหารหัส: ' + escHtml(prefix) + '...', 'info');
        // ส่ง request ไปค้นหา
        fetch(`inventory.php?action=search_by_qr&qr=${encodeURIComponent(qrText)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (!data) {
                    showToast('❌ ไม่พบข้อมูลจากเซิร์ฟเวอร์', 'danger');
                    return;
                }
                if (data.count === 0 || !data.items || data.items.length === 0) {
                    showToast('❌ ไม่พบรายการสำหรับรหัส: ' + escHtml(prefix), 'warning');
                    return;
                }
                if (data.count === 1) {
                    // เจอรายการเดียว → เปิด modal ตรวจนับเลย
                    const item = data.items[0];
                    openUpdateModal(item.assignment_id, item.asset_no, item.asset_description, item);
                } else {
                    // เจอหลายรายการ → แสดงรายการให้เลือก
                    showQRResultsList(data.items, prefix);
                }
            })
            .catch(err => {
                console.error('Search error:', err);
                showToast('❌ เกิดข้อผิดพลาดในการค้นหา: ' + escHtml(err.message), 'danger');
            });
    }
    function showQRResultsList(items, prefix) {
        // ตรวจสอบว่ามี element หรือไม่
        const prefixSpan = document.getElementById('qr-results-prefix');
        const listContainer = document.getElementById('qr-results-list');
        const modalElement = document.getElementById('qrResultsModal');
        if (!prefixSpan || !listContainer || !modalElement) {
            console.error('ไม่พบ element ที่จำเป็นสำหรับแสดงผลลัพธ์');
            showToast('❌ เกิดข้อผิดพลาดในการแสดงผล', 'danger');
            return;
        }
        // ตั้งค่า prefix
        prefixSpan.textContent = prefix;
        // เคลียร์รายการเก่า
        listContainer.innerHTML = '';
        // สร้างรายการใหม่
        items.forEach((item, index) => {
            const statusBadge = item.assignment_status === 'completed' ?
                '<span class="badge bg-success ms-1" style="font-size:9px;">✓ ตรวจแล้ว</span>' :
                '<span class="badge bg-warning text-dark ms-1" style="font-size:9px;">⏳ รอดำเนินการ</span>';
            const div = document.createElement('div');
            div.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3';
            div.style.cursor = 'pointer';
            div.style.transition = 'background-color 0.2s';
            div.innerHTML = `
            <div class="overflow-hidden">
                <div class="fw-bold font-monospace text-primary" style="font-size:13px;">
                    ${escHtml(item.asset_no)}
                </div>
                <div class="text-muted text-truncate" style="font-size:11px; max-width:260px;" title="${escHtml(item.asset_description)}">
                    ${escHtml(item.asset_description || '-')}
                </div>
                <div class="text-muted" style="font-size:10px;">
                    <i class="fa-solid fa-building"></i> ${escHtml(item.plant_code || '-')}
                    <i class="fa-solid fa-diagram-project ms-2"></i> ${escHtml(item.cost_center || '-')}
                </div>
            </div>
            <div class="ms-2 flex-shrink-0">${statusBadge}</div>
        `;
            // เพิ่ม event click
            div.onclick = () => {
                // ปิด modal ผลลัพธ์
                if (qrResultsModal) {
                    qrResultsModal.hide();
                } else {
                    // Fallback: หา instance หรือสร้างใหม่
                    const modal = bootstrap.Modal.getInstance(modalElement);
                    if (modal) modal.hide();
                }
                // เปิด modal ตรวจนับ
                openUpdateModal(item.assignment_id, item.asset_no, item.asset_description, item);
            };
            // เพิ่ม hover effect
            div.onmouseenter = () => {
                div.style.backgroundColor = '#f8f9fa';
            };
            div.onmouseleave = () => {
                div.style.backgroundColor = '';
            };
            listContainer.appendChild(div);
        });
        // สร้างหรือ reuse instance ของ modal
        if (!qrResultsModal) {
            qrResultsModal = new bootstrap.Modal(modalElement, {
                backdrop: 'static',
                keyboard: true
            });
        }
        // แสดง modal
        qrResultsModal.show();
    }
    // ฟังก์ชันเพิ่มเติมสำหรับจัดการ modal เมื่อปิด
    function initQRResultsModal() {
        const modalElement = document.getElementById('qrResultsModal');
        if (modalElement && !qrResultsModal) {
            qrResultsModal = new bootstrap.Modal(modalElement, {
                backdrop: 'static',
                keyboard: true
            });
            // เมื่อปิด modal ให้เคลียร์รายการ
            modalElement.addEventListener('hidden.bs.modal', function() {
                const listContainer = document.getElementById('qr-results-list');
                if (listContainer) {
                    listContainer.innerHTML = '';
                }
            });
        }
    }
    // เรียกใช้ฟังก์ชัน init เมื่อ DOM พร้อม
    // เพิ่มบรรทัดนี้ใน DOMContentLoaded หรือเรียกแยก
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initQRResultsModal);
    } else {
        initQRResultsModal();
    }
    // ══════════════════════════════════════════════════════
    //  AUDIT MODAL
    // ══════════════════════════════════════════════════════
    function setModalAssetDetails(details = {}) {
        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value || '-';
        };
        setText('modal-asset-no', details.asset_no);
        setText('modal-asset-desc', details.asset_description);
        setText('modal-asset-plant', details.plant_code);
        setText('modal-asset-cost-center', details.cost_center);
        setText('modal-asset-serial', details.serial_no);
        setText('modal-asset-model', details.model);
        setText('modal-asset-location', details.asset_location || details.location);
        setText('modal-asset-owner', details.municipality || details.auditor_name);
    }
    function openUpdateModal(assignmentId, assetNo, assetDesc, assetDetails = {}) {
        currentAssetId = assignmentId;
        setModalAssetDetails({
            ...assetDetails,
            asset_no: assetDetails.asset_no || assetNo,
            asset_description: assetDetails.asset_description || assetDesc
        });
        // Reset form
        document.getElementById('auditForm').reset();
        document.getElementById('quantity').value = 1;
        document.getElementById('status-active').checked = true;
        assetsImagesList = [];
        qrCodesImagesList = [];
        if (Array.isArray(assetDetails.audit_images) && assetDetails.audit_images.length > 0) {
            assetDetails.audit_images.forEach(img => {
                const entry = {
                    base64: img.url,
                    saved: true,
                    filename: img.label || null
                };
                if (img.label === 'QR') qrCodesImagesList.push(entry);
                else assetsImagesList.push(entry);
            });
        }
        renderPreviews();
        if (signaturePad) signaturePad.clear();
        auditBsModal.show();
        // Load existing data after a tick (modal might still be animating)
        setTimeout(() => loadExistingAuditData(assignmentId), 300);
    }
    function closeAuditModal() {
        auditBsModal.hide();
    }
    function loadExistingAuditData(assignmentId) {
        fetch(`inventory.php?action=get_audit&assignment_id=${assignmentId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const rd = data.remark_data || {};
                const lr = data.latest_remark || {};
                if (data.asset) {
                    setModalAssetDetails(data.asset);
                }
                if (rd.check_result) {
                    const radio = document.querySelector(`input[name="check_status"][value="${rd.check_result}"]`);
                    if (radio) radio.checked = true;
                }
                // Fill form with current assignment data OR fallback to latest completed audit for this asset
                document.getElementById('quantity').value = (rd.quantity !== undefined) ? rd.quantity : (lr.quantity !== undefined ? lr.quantity : 1);
                document.getElementById('location').value = rd.location || lr.location || '';
                document.getElementById('responsible_person').value = rd.responsible_person || lr.responsible_person || data.asset.municipality || '';
                document.getElementById('remarks').value = rd.notes || '';
                // Load saved images
                assetsImagesList = [];
                qrCodesImagesList = [];
                (data.images || []).forEach(img => {
                    const entry = {
                        base64: img.base64,
                        saved: true,
                        filename: img.filename
                    };
                    if (img.type === 'qr_codes') qrCodesImagesList.push(entry);
                    else assetsImagesList.push(entry);
                });
                renderPreviews();
                // Load saved signature
                if (data.signature && signaturePad) {
                    const img = new Image();
                    img.onload = () => {
                        const canvas = document.getElementById('signature-canvas');
                        const ctx = canvas.getContext('2d');
                        const ratio = window.devicePixelRatio || 1;
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        ctx.drawImage(img, 0, 0, canvas.width / ratio, canvas.height / ratio);
                    };
                    img.src = data.signature;
                }
            })
            .catch(err => {
                console.error('loadExistingAuditData error:', err);
                showToast('โหลดข้อมูลเดิม/รูปภาพไม่สำเร็จ: ' + escHtml(err.message || err), 'warning');
            });
    }
    function saveSignature() {
        const signerId = document.getElementById('signer-name').value;
        const signerFullname = (document.getElementById('signer-fullname')?.value || '').trim();
        // แก้ไข: ถ้าเป็น officer ต้องเลือกชื่อ
        if (currentData.type === 'asset_officer' && !signerId) {
            showToast('กรุณาเลือกชื่อผู้เซ็น', 'warning');
            return;
        }
        // ถ้าเป็นหัวหน้าแผนก ต้องกรอกชื่อ-สกุล
        if (currentData.type === 'dept' && !signerFullname) {
            showToast('กรุณากรอกชื่อ-สกุลหัวหน้าแผนก', 'warning');
            return;
        }
        if (signaturePad.isEmpty()) {
            showToast('กรุณาวาดลายเซ็นก่อนบันทึก', 'warning');
            return;
        }
        const payload = {
            signature: signaturePad.toDataURL(),
            signer_id: signerId,
            signer_name: signerFullname,
            type: currentData.type,
            pCode: currentData.pCode,
            ccCode: currentData.ccCode,
            session_id: currentData.sessionId
        };
        fetch('inventory.php?action=save_signature', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json' // สำคัญมาก: บอก PHP ว่าส่ง JSON มานะ
                },
                body: JSON.stringify(payload) // เปลี่ยน Object ให้เป็น JSON String
            })
            .then(res => res.json())
            .then(data => {
                // console.log(data);
                if (data.success) {
                    location.reload();
                } else {
                    showToast('บันทึกไม่สำเร็จ: ' + (data.message || ''), 'warning');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'danger');
            });
    }
    // เมื่อ Modal เปิด
    let currentData = {
        type: '',
        pCode: '',
        ccCode: '',
        sessionId: ''
    };
    let pendingSigData = null;
    function openSignModal(userType, pCode, ccCode, session_id) {
        // 1. อัปเดตตัวแปร currentData
        currentData = {
            type: userType,
            pCode: pCode,
            ccCode: ccCode,
            sessionId: session_id
        };
        pendingSigData = null;
        const modalEl = document.getElementById('signModal');
        const wrapper = document.getElementById('signer-selection-wrapper');
        // 2. ตั้งค่า Data attribute
        modalEl.dataset.userType = userType;
        modalEl.dataset.pCode = pCode;
        modalEl.dataset.ccCode = ccCode;
        modalEl.dataset.sessionId = session_id;
        // 3. จัดการ UI
        wrapper.style.display = (userType === 'asset_officer') ? 'block' : 'none';
        const nameWrapper = document.getElementById('signer-name-wrapper');
        if (nameWrapper) nameWrapper.style.display = (userType === 'dept') ? 'block' : 'none';
        const fullnameInput = document.getElementById('signer-fullname');
        if (fullnameInput) fullnameInput.value = '';
        // 4. เปิด Modal
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
        // 5. เคลียร์ Canvas (ตรวจสอบก่อนว่ามีตัวแปร signaturePad หรือยัง)
        if (typeof signaturePad !== 'undefined' && signaturePad) {
            signaturePad.clear();
        }
        // 6. โหลดข้อมูลเดิม (ถ้ามี)
        fetch(`inventory.php?action=get_signature&plant_code=${pCode}&cost_center=${ccCode}&session_id=${session_id}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data) {
                    const row = data.data;
                    let targetSig = null;
                    if (userType === 'asset_officer') {
                        document.getElementById('signer-name').value = row.asset_officer_id || '';
                        targetSig = row.asset_officer_sig;
                    } else if (userType === 'dept') {
                        const fi = document.getElementById('signer-fullname');
                        if (fi && row.dept_name) fi.value = row.dept_name;
                        targetSig = row.dept_sig;
                    } else {
                        targetSig = row.auditor_sig;
                    }
                    if (targetSig) {
                        if (signaturePad && modalEl.classList.contains('show')) {
                            signaturePad.fromDataURL(targetSig);
                        } else {
                            pendingSigData = targetSig;
                        }
                    }
                }
            });
    }
    function closeSignModal() {
        // 1. เคลียร์ค่าตัวแปร currentData
        currentData = {
            type: '',
            pCode: '',
            ccCode: '',
            sessionId: ''
        };
        // 2. ปิด Modal
        const myModalEl = document.getElementById('signModal');
        const modal = bootstrap.Modal.getInstance(myModalEl);
        if (modal) {
            modal.hide();
        }
        // 3. เคลียร์ Dropdown, text input และ Canvas
        document.getElementById('signer-name').value = '';
        const fullnameInput = document.getElementById('signer-fullname');
        if (fullnameInput) fullnameInput.value = '';
        if (typeof signaturePad !== 'undefined') signaturePad.clear();
    }
    // ══════════════════════════════════════════════════════
    //  IMAGE PROCESSING (compress 640×480, cover-fit)
    // ══════════════════════════════════════════════════════
    function handleImageProcessing(event, folderTarget, maxLimit) {
        const files = Array.from(event.target.files);
        const currentList = folderTarget === 'assets' ? assetsImagesList : qrCodesImagesList;
        files.forEach(file => {
            if (currentList.length >= maxLimit) {
                showToast(`ใส่ได้ไม่เกิน ${maxLimit} รูปสำหรับประเภทนี้`, 'warning');
                return;
            }
            const reader = new FileReader();
            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    // Cover-fit crop to 640×480
                    const TW = 640,
                        TH = 480;
                    const imgAR = img.width / img.height;
                    const tgAR = TW / TH;
                    let sx = 0,
                        sy = 0,
                        sw = img.width,
                        sh = img.height;
                    if (imgAR > tgAR) {
                        sw = img.height * tgAR;
                        sx = (img.width - sw) / 2;
                    } else {
                        sh = img.width / tgAR;
                        sy = (img.height - sh) / 2;
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = TW;
                    canvas.height = TH;
                    canvas.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, TW, TH);
                    const compressed = canvas.toDataURL('image/jpeg', 0.82);
                    if (currentList.length < maxLimit) {
                        currentList.push({
                            base64: compressed,
                            saved: false,
                            filename: null
                        });
                        renderPreviews();
                    }
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        });
        event.target.value = ''; // reset input
    }
    function renderPreviews() {
        const zone = document.getElementById('images-preview-zone');
        zone.innerHTML = '';
        const total = assetsImagesList.length + qrCodesImagesList.length;
        if (total === 0) {
            zone.innerHTML = `
            <div class="text-muted m-auto text-center" id="preview-placeholder" style="font-size:11px;">
                <i class="fa-solid fa-images opacity-30 fa-2x d-block mb-1"></i>ยังไม่มีรูปภาพ
            </div>`;
        } else {
            assetsImagesList.forEach((img, idx) => zone.appendChild(createPreviewCard(img, 'assets', idx)));
            qrCodesImagesList.forEach((img, idx) => zone.appendChild(createPreviewCard(img, 'qr_codes', idx)));
        }
        document.getElementById('image-count-txt').textContent = `(${total}/3 รูป)`;
    }
    function createPreviewCard(imgObj, type, idx) {
        const isQR = type === 'qr_codes';
        const labelTxt = isQR ? 'QR' : 'รูปทรัพย์';
        const labelColor = isQR ? '#6f42c1' : '#0d6efd';
        const wrap = document.createElement('div');
        wrap.className = 'position-relative border rounded overflow-hidden bg-light img-preview-card';
        wrap.style.cssText = 'width:100px;height:82px;flex-shrink:0;';
        wrap.innerHTML = `
        <img src="${imgObj.base64}"
             style="width:100%;height:100%;object-fit:cover;"
             onclick="previewFullImage('${imgObj.base64.substring(0, 50)}...')"
             data-fullsrc="${escHtml(imgObj.base64)}"
             title="คลิกดูขนาดเต็ม">
        <button type="button"
                onclick="deleteImage('${type}',${idx})"
                class="btn btn-danger p-0 position-absolute top-0 end-0"
                style="width:20px;height:20px;font-size:12px;line-height:1;border-radius:50%;margin:2px;"
                title="ลบรูปนี้">×</button>
        <div class="position-absolute bottom-0 start-0 w-100 text-white text-center"
             style="font-size:9px;background:${labelColor};padding:1px 0;opacity:.92;">${labelTxt}</div>
    `;
        // Fix full preview
        wrap.querySelector('img').onclick = function() {
            previewFullImage(this.dataset.fullsrc);
        };
        return wrap;
    }
    function deleteImage(type, idx) {
        if (type === 'assets') assetsImagesList.splice(idx, 1);
        else qrCodesImagesList.splice(idx, 1);
        renderPreviews();
    }
    function previewFullImage(base64) {
        document.getElementById('preview-modal-img').src = base64;
        new bootstrap.Modal(document.getElementById('imagePreviewModal')).show();
    }
    // ══════════════════════════════════════════════════════
    //  SIGNATURE PAD
    // ══════════════════════════════════════════════════════
    function initSignaturePad(canvasId = 'signature-canvas') {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        // Destroy previous instance
        if (signaturePad) {
            signaturePad.off();
            signaturePad = null;
        }
        // High-DPI fix
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        signaturePad = new SignaturePad(canvas, {
            backgroundColor: 'rgba(248,249,250,0)',
            penColor: 'rgb(0,0,100)',
            minWidth: 1.0,
            maxWidth: 3.0,
            velocityFilterWeight: 0.7
        });
    }
    function clearSignature() {
        if (signaturePad) signaturePad.clear();
        showToast('ล้างลายเซ็นแล้ว', 'info');
    }
    // ══════════════════════════════════════════════════════
    //  SAVE AUDIT
    // ══════════════════════════════════════════════════════
    function saveAuditData() {
        const checkStatus = document.querySelector('input[name="check_status"]:checked')?.value;
        if (!checkStatus) {
            showToast('⚠️ กรุณาเลือกผลการตรวจสอบก่อนบันทึก', 'warning');
            return;
        }
        const quantity = parseInt(document.getElementById('quantity').value) || 0;
        const location = document.getElementById('location').value.trim();
        const responsible = document.getElementById('responsible_person').value.trim();
        const notes = document.getElementById('remarks').value.trim();
        // Collect all images
        const allImages = [
            ...assetsImagesList.map(img => ({
                type: 'assets',
                base64: img.base64
            })),
            ...qrCodesImagesList.map(img => ({
                type: 'qr_codes',
                base64: img.base64
            }))
        ];
        // Get signature data
        let signatureData = null;
        if (signaturePad && !signaturePad.isEmpty()) {
            signatureData = signaturePad.toDataURL('image/jpeg', 0.92);
        }
        const payload = {
            assignment_id: currentAssetId,
            check_status: checkStatus,
            quantity,
            location,
            responsible_person: responsible,
            notes,
            images: allImages,
            signature: signatureData
        };
        // Disable save button
        const btn = document.getElementById('save-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> กำลังบันทึก...';
        fetch('inventory.php?action=save_audit', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast('✅ ' + (data.message || 'บันทึกเรียบร้อยแล้ว'), 'success');
                    auditBsModal.hide();
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    showToast('❌ ' + (data.message || 'บันทึกไม่สำเร็จ'), 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> บันทึกรายการ';
                }
            })
            .catch(err => {
                showToast('Network error: ' + escHtml(String(err)), 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> บันทึกรายการ';
            });
    }
    // ══════════════════════════════════════════════════════
    //  UTILITIES
    // ══════════════════════════════════════════════════════
    function showToast(message, type = 'info') {
        const colors = {
            success: 'bg-success',
            danger: 'bg-danger',
            warning: 'bg-warning text-dark',
            info: 'bg-info text-dark'
        };
        const div = document.createElement('div');
        div.className = `toast align-items-center text-white ${colors[type] || 'bg-secondary'} border-0 mb-2`;
        div.setAttribute('role', 'alert');
        div.setAttribute('aria-live', 'assertive');
        div.innerHTML = `
        <div class="d-flex">
            <div class="toast-body" style="font-size:13px;">${message}</div>
            <button type="button" class="btn-close ${type === 'warning' || type === 'info' ? '' : 'btn-close-white'} me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>`;
        document.getElementById('toast-container').appendChild(div);
        const toast = new bootstrap.Toast(div, {
            delay: 4000
        });
        toast.show();
        div.addEventListener('hidden.bs.toast', () => div.remove());
    }
    // ══════════════════════════════════════════════════════
    //  PRINT REPORT
    // ══════════════════════════════════════════════════════
    function printRow(summaryId) {
        if (!summaryId || summaryId === '0') {
            showToast('⚠️ ไม่พบข้อมูล Summary สำหรับพิมพ์', 'warning');
            return;
        }
        const url = '<?= APP_URL ?>/api/audit_print.php?summary_id=' + encodeURIComponent(summaryId);
        const win = window.open(url, '_blank', 'width=900,height=700,scrollbars=yes');
        if (!win) {
            showToast('⚠️ Pop-up ถูกบล็อก กรุณาอนุญาต Pop-up สำหรับเว็บนี้', 'warning');
        }
    }
    function escHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
</script>
</body>
</html>