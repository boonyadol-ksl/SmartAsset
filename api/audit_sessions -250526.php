<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

header('Content-Type: application/json');

// auth.php ใช้ session_name('ASSET_SYS') ก่อน session_start() เสมอ
// ต้องเรียก startSession() จาก auth.php แทนการ session_start() ตรงๆ
startSession();

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session หมดอายุ กรุณา Login ใหม่']);
    exit;
}
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'webadmin'])) {
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

$db = Database::getInstance();

// เพิ่มในไฟล์ audit_sessions.php ภายใน switch-case หรือเงื่อนไข if
if ($action === 'cancel_pending') {
    $userId    = (int)($input['user_id'] ?? 0);
    $sessionId = (int)($input['session_id'] ?? 0);
    $plantCode = trim($input['plant_code'] ?? '');

    if ($userId <= 0 || $sessionId <= 0 || empty($plantCode)) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
        exit;
    }

    // ลบรายการที่ยังเป็น 'pending' เท่านั้น เพื่อป้องกันการลบรายการที่ตรวจไปแล้ว
    $db->execute(
        "DELETE FROM audit_assignments
         WHERE user_id = ?
           AND session_id = ?
           AND status = 'pending'",
        [$userId, $sessionId]
    );

    echo json_encode(['success' => true, 'message' => 'ยกเลิกงานที่ค้างอยู่สำเร็จ']);
    exit;
}elseif ($action === 'create_and_assign') {

    $plant          = trim($input['plant_code'] ?? '');
    $percent        = (int)($input['percent'] ?? 0);
    $selectedCC     = $input['selectedCC']    ?? [];   // Array ของ Cost Center
    $selectedUsers  = $input['selectedUsers'] ?? [];   // Array ของ User ID (string)
    $excludeChecked = (bool)($input['excludeChecked'] ?? true);
    $user_id        = $_SESSION['user_id'];

    // --- Validate input ก่อนเริ่ม transaction ---
    if (empty($plant) || $percent <= 0 || empty($selectedCC) || empty($selectedUsers)) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน กรุณาตรวจสอบอีกครั้ง']);
        exit;
    }

    try {
        $db->beginTransaction(); // [FIX #1] ย้ายเข้ามาใน try/catch

        // ---- 1. สร้าง audit_session header ----
        $sessionName = "ตรวจนับ {$plant} ({$percent}%) - " . date('d/m/Y H:i');
        $db->execute(
            "INSERT INTO audit_sessions (session_name, plant_code, target_percent, created_by) VALUES (?, ?, ?, ?)",
            [$sessionName, $plant, $percent, $user_id]
        );
        $sessionId = $db->lastInsertId();

        // ---- 2. ดึง Asset ที่ต้องตรวจ (Prepared Statement ป้องกัน SQL Injection) ----
        // [FIX #2] เปลี่ยนจาก string concat เป็น prepared statement ด้วย IN (?,?,?)
        $ccPlaceholders = implode(',', array_fill(0, count($selectedCC), '?'));

        $params = array_merge([$plant], $selectedCC);

        $excludeClause = '';
        if ($excludeChecked) {
            $currentYear   = (int)date('Y');
            // [FIX #3] ใช้ audit_assignments.checked_at (ตรงกับ get_cc) แทน last_check_date
            $excludeClause = " AND id NOT IN (
                SELECT asset_id FROM audit_assignments
                WHERE YEAR(checked_at) = {$currentYear} AND status = 'completed'
            )";
        }

        $countSql  = "SELECT COUNT(*) as total FROM assets
                      WHERE plant_code = ? AND status = 'active'
                      AND cost_center IN ({$ccPlaceholders})
                      {$excludeClause}";
        $totalCount = (int)$db->fetchOne($countSql, $params)['total'];

        if ($totalCount === 0) {
            // [FIX #4] ไม่มีรายการ → rollback ทิ้ง session header ที่เพิ่งสร้าง
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'ไม่พบรายการทรัพย์สินที่ต้องตรวจในขอบเขตที่เลือก']);
            exit;
        }

        $limit  = (int)ceil($totalCount * ($percent / 100));
        $assets = $db->fetchAll(
            "SELECT id, cost_center FROM assets
             WHERE plant_code = ? AND status = 'active'
             AND cost_center IN ({$ccPlaceholders})
             {$excludeClause}
             ORDER BY cost_center ASC, RAND()
             LIMIT {$limit}",
            $params
        );

        if (empty($assets)) {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถดึงรายการทรัพย์สินได้']);
            exit;
        }

        // ---- 3. กระจายงานแบบ Sequential Split (แบ่งโซน CC) ----
        $perPerson = (int)ceil(count($assets) / count($selectedUsers));
        $chunks    = array_chunk($assets, $perPerson);

        // [FEATURE] เก็บสถิติต่อ user เพื่อรายงานกลับ
        $userStats = [];
        foreach ($selectedUsers as $uid) {
            $uid = (int)$uid;
            // งานเดิมของ user ใน session นี้ (ควรจะเป็น 0 เสมอ แต่ป้องกัน edge case)
            $existing = (int)$db->fetchOne(
                "SELECT COUNT(*) as cnt FROM audit_assignments WHERE session_id = ? AND user_id = ?",
                [$sessionId, $uid]
            )['cnt'];
            $userStats[$uid] = ['existing' => $existing, 'added' => 0, 'skipped' => 0];
        }

        foreach ($chunks as $index => $assetChunk) {
            if (!isset($selectedUsers[$index])) continue;

            $userId = (int)$selectedUsers[$index];

            foreach ($assetChunk as $asset) {
                $assetId = (int)$asset['id'];

                // [FIX #5] ตรวจสอบ duplicate ข้าม ALL sessions (ไม่ใช่แค่ session ปัจจุบัน)
                // ถ้ามีใน session นี้แล้ว → ข้าม (อาจเกิดจาก RAND ชนกัน)
                $alreadyInSession = (int)$db->fetchOne(
                    "SELECT COUNT(*) as cnt FROM audit_assignments
                     WHERE session_id = ? AND asset_id = ?",
                    [$sessionId, $assetId]
                )['cnt'];

                if ($alreadyInSession > 0) {
                    $userStats[$userId]['skipped']++;
                    continue;
                }

                $db->execute(
                    "INSERT INTO audit_assignments (session_id, asset_id, user_id, status) VALUES (?, ?, ?, 'pending')",
                    [$sessionId, $assetId, $userId]
                );
                $userStats[$userId]['added']++;
            }
        }

        $db->commit();

        // ---- 4. สรุปผลต่อ user (ดึงชื่อมาด้วย) ----
        $userIds         = array_map('intval', $selectedUsers);
        $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
        $userRows        = $db->fetchAll(
            "SELECT id, full_name, username FROM users WHERE id IN ({$userPlaceholders})",
            $userIds
        );
        $userMap = [];
        foreach ($userRows as $u) {
            $userMap[(int)$u['id']] = $u['full_name'] ?: $u['username'];
        }

        $summary = [];
        foreach ($userStats as $uid => $stat) {
            $summary[] = [
                'user_id'   => $uid,
                'name'      => $userMap[$uid] ?? "User #{$uid}",
                'existing'  => $stat['existing'],   // งานที่มีอยู่ก่อนหน้าใน session นี้
                'added'     => $stat['added'],       // งานที่เพิ่มเข้าไปใหม่
                'skipped'   => $stat['skipped'],     // งานที่ข้ามเพราะซ้ำ
                'total'     => $stat['existing'] + $stat['added'],
            ];
        }

        $totalAdded   = array_sum(array_column($summary, 'added'));
        $totalSkipped = array_sum(array_column($summary, 'skipped'));

        echo json_encode([
            'success'       => true,
            'message'       => "สร้างรอบการตรวจนับสำเร็จ เพิ่มงานใหม่ {$totalAdded} รายการ" .
                               ($totalSkipped > 0 ? " (ข้ามซ้ำ {$totalSkipped} รายการ)" : ''),
            'session_id'    => $sessionId,
            'total_assets'  => count($assets),
            'total_added'   => $totalAdded,
            'total_skipped' => $totalSkipped,
            'user_summary'  => $summary,
        ]);

    } catch (Exception $e) {
        try { $db->rollback(); } catch (Exception $re) { /* ignore */ }
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid Action']);