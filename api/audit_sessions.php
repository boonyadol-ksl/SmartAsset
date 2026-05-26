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

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

$db = Database::getInstance();

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
}

if ($action === 'create_and_assign') {
    $plant          = trim($input['plant_code'] ?? '');
    $sessionYear    = (int)($input['session_year'] ?? 0);
    $deadlineDate   = trim($input['deadline_date'] ?? ''); // YYYY-MM-DD
    $percent        = (int)($input['percent'] ?? 0);
    $selectedCC     = $input['selectedCC']    ?? []; // Array ของ Cost Center
    $selectedUsers  = $input['selectedUsers'] ?? []; // Array ของ User ID (string)
    $excludeChecked = (bool)($input['excludeChecked'] ?? true);
    $user_id        = (int)($_SESSION['user_id'] ?? 0);

    if (
        empty($plant) ||
        $sessionYear <= 0 ||
        empty($deadlineDate) ||
        $percent <= 0 ||
        empty($selectedCC) ||
        empty($selectedUsers)
    ) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน กรุณาตรวจสอบอีกครั้ง']);
        exit;
    }

    try {
        $db->beginTransaction();

        // ---- 1) สร้าง audit_session header ----
        $sessionName = "ตรวจนับ {$plant} ปี {$sessionYear} ({$percent}%) - " . date('d/m/Y H:i');
        $db->execute(
            "INSERT INTO audit_sessions (session_name, plant_code, target_percent, created_by, session_year, deadline_date)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$sessionName, $plant, $percent, $user_id, $sessionYear, $deadlineDate]
        );
        $sessionId = (int)$db->lastInsertId();

        // ---- 2) ดึง Asset ที่ต้องตรวจ ----
        $ccPlaceholders = implode(',', array_fill(0, count($selectedCC), '?'));

        $excludeClause = '';
        $params = array_merge([$plant], $selectedCC);
        if ($excludeChecked) {
            // ใช้ fiscal year จาก audit_sessions.session_year (ไม่ใช้ YEAR(checked_at))
            $excludeClause = " AND id NOT IN (
                SELECT aa.asset_id
                FROM audit_assignments aa
                JOIN audit_sessions s ON s.id = aa.session_id
                WHERE s.plant_code = ?
                  AND s.session_year = ?
                  AND aa.status = 'completed'
            )";
            $params = array_merge($params, [$plant, $sessionYear]);
        }

        $countSql = "SELECT COUNT(*) as total FROM assets
                     WHERE plant_code = ? AND status = 'active'
                     AND cost_center IN ({$ccPlaceholders})
                     {$excludeClause}";
        $totalCount = (int)($db->fetchOne($countSql, $params)['total'] ?? 0);

        if ($totalCount === 0) {
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

        // ---- 3) กระจายงานแบบ Sequential Split ----
        $perPerson = (int)ceil(count($assets) / count($selectedUsers));
        $chunks    = array_chunk($assets, $perPerson);

        $userStats = [];
        foreach ($selectedUsers as $uid) {
            $uid = (int)$uid;
            $existing = (int)($db->fetchOne(
                "SELECT COUNT(*) as cnt FROM audit_assignments WHERE session_id = ? AND user_id = ?",
                [$sessionId, $uid]
            )['cnt'] ?? 0);
            $userStats[$uid] = ['existing' => $existing, 'added' => 0, 'skipped' => 0];
        }

        foreach ($chunks as $index => $assetChunk) {
            if (!isset($selectedUsers[$index])) continue;
            $userId = (int)$selectedUsers[$index];

            foreach ($assetChunk as $asset) {
                $assetId = (int)$asset['id'];

                // กันซ้ำ "เฉพาะปีที่เลือก" (กันซ้ำเฉพาะ pending เพื่อเปิดทางให้ยกเลิกแล้วมอบหมายใหม่ได้)
                $dupCnt = (int)($db->fetchOne(
                    "SELECT COUNT(*) as cnt
                     FROM audit_assignments aa
                     JOIN audit_sessions s ON s.id = aa.session_id
                     WHERE s.plant_code = ?
                       AND s.session_year = ?
                       AND aa.asset_id = ?
                       AND aa.status = 'pending'",
                    [$plant, $sessionYear, $assetId]
                )['cnt'] ?? 0);
                if ($dupCnt > 0) {
                    $userStats[$userId]['skipped']++;
                    continue;
                }

                $db->execute(
                    "INSERT INTO audit_assignments (session_id, asset_id, user_id, status)
                     VALUES (?, ?, ?, 'pending')",
                    [$sessionId, $assetId, $userId]
                );
                $userStats[$userId]['added']++;
            }
        }

        $db->commit();

        // ---- 4) สรุปผลต่อ user ----
        $userIds = array_map('intval', $selectedUsers);
        $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
        $userRows = $db->fetchAll(
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
                'existing'  => $stat['existing'],
                'added'     => $stat['added'],
                'skipped'   => $stat['skipped'],
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
