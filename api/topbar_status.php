<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

header('Content-Type: application/json; charset=utf-8');

startSession();

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session หมดอายุ กรุณา Login ใหม่']);
    exit;
}

// ให้ inventory ใช้ได้ด้วย (Top Bar สำหรับพนักงานตรวจนับ)
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'webadmin', 'inventory'], true)) {
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}

$db = Database::getInstance();
$user = currentUser();
$action = trim($_GET['action'] ?? '');

// function jsonResponse($success, $data = null, $message = '', $code = 200) {
//     http_response_code($code);
//     echo json_encode(['success' => $success, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
//     exit;
// }

function daysLeftFromDate($deadlineDateYmd) {
    if (!$deadlineDateYmd) return null;
    $d = DateTime::createFromFormat('Y-m-d', $deadlineDateYmd);
    if (!$d) return null;
    $today = new DateTime(date('Y-m-d'));
    $diff = (int)$today->diff($d)->format('%r%a'); // signed days
    return $diff;
}

// กติกา: ใกล้ครบกำหนด <= 7 วัน
$nearDays = 7;

if ($action !== 'mine') {
    jsonResponse(false, null, 'Invalid action', 400);
}

$uid = (int)($user['id'] ?? 0);
if ($uid <= 0) jsonResponse(false, null, 'ไม่พบผู้ใช้งาน', 400);

// สรุปต่อ session ของ user คนนี้
$rows = $db->fetchAll(
    "SELECT
        s.id AS session_id,
        s.session_name,
        s.deadline_date,
        COUNT(*) AS assigned,
        SUM(CASE WHEN aa.status='pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN aa.status='completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN aa.status='missing' THEN 1 ELSE 0 END) AS missing
     FROM audit_assignments aa
     JOIN audit_sessions s ON s.id = aa.session_id
     WHERE aa.user_id = ?
     GROUP BY s.id, s.session_name, s.deadline_date
     ORDER BY (s.deadline_date IS NULL) ASC, s.deadline_date ASC, s.id DESC",
    [$uid]
);

$sessions = [];
$totalPending = 0;
$totalAssigned = 0;
$distinctDeadlines = [];

$hasOverdue = false;
$hasNear = false;
$hasAnyDeadline = false;          // มี deadline ในระบบ (ทุก session)
$hasDeadlineOnPending = false;    // มี deadline ในงานที่ค้าง (pending) อย่างน้อย 1 งาน
$earliestDeadline = null;
$earliestDaysLeft = null;

foreach ($rows as $r) {
    $assigned = (int)$r['assigned'];
    $pending = (int)$r['pending'];
    $completed = (int)$r['completed'];
    $missing = (int)$r['missing'];
    $deadline = $r['deadline_date'];

    $totalPending += $pending;
    $totalAssigned += $assigned;

    $daysLeft = daysLeftFromDate($deadline);
    $deadlineStatus = 'none';
    $deadlineSub = '';
    if ($deadline) {
        $hasAnyDeadline = true;
        $distinctDeadlines[$deadline] = true;
        if ($daysLeft !== null) {
            if ($daysLeft < 0) {
                $deadlineStatus = 'overdue';
                $deadlineSub = 'เกิน ' . abs($daysLeft) . ' วัน';
            } elseif ($daysLeft <= $nearDays) {
                $deadlineStatus = 'near';
                $deadlineSub = 'เหลือ ' . $daysLeft . ' วัน';
            } else {
                $deadlineStatus = 'ok';
                $deadlineSub = 'เหลือ ' . $daysLeft . ' วัน';
            }
        }
    }

    // สถานะรวมควรดูจากงานที่ยังค้าง (pending) เป็นหลัก
    if ($pending > 0 && $deadline) {
        $hasDeadlineOnPending = true;
        if ($daysLeft !== null && $daysLeft < 0) $hasOverdue = true;
        if ($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= $nearDays) $hasNear = true;

        // หา earliest upcoming deadline จาก pending
        if ($daysLeft !== null && $daysLeft >= 0) {
            if ($earliestDaysLeft === null || $daysLeft < $earliestDaysLeft) {
                $earliestDaysLeft = $daysLeft;
                $earliestDeadline = $deadline;
            }
        }
        // ถ้ามี overdue ให้เอา deadline ที่เลยแล้วใกล้ที่สุด (daysLeft ติดลบน้อยที่สุด)
        if ($daysLeft !== null && $daysLeft < 0) {
            if ($earliestDaysLeft === null) {
                $earliestDaysLeft = $daysLeft;
                $earliestDeadline = $deadline;
            } elseif ($earliestDaysLeft < 0 && $daysLeft > $earliestDaysLeft) {
                $earliestDaysLeft = $daysLeft;
                $earliestDeadline = $deadline;
            }
        }
    }

    $sessions[] = [
        'session_id' => (int)$r['session_id'],
        'session_name' => $r['session_name'],
        'deadline_date' => $deadline,
        'deadline_status' => $deadlineStatus,
        'deadline_sub' => $deadlineSub,
        'assigned' => $assigned,
        'pending' => $pending,
        'completed' => $completed,
        'missing' => $missing,
    ];
}

// สถานะรวม
$overallStatus = 'none';
if ($totalPending <= 0) {
    $overallStatus = 'no_pending';
} elseif ($hasOverdue) {
    $overallStatus = 'overdue';
} elseif ($hasNear) {
    $overallStatus = 'near';
} elseif ($hasDeadlineOnPending) {
    $overallStatus = 'ok';
} else {
    $overallStatus = 'none';
}

$deadlineLabel = 'Deadline';
$deadlineSub = '';
$alertText = '';

if ($overallStatus === 'no_pending') {
    $deadlineLabel = 'ไม่มีงานค้าง';
    $deadlineSub = '';
} elseif ($overallStatus === 'overdue') {
    $deadlineLabel = 'เกินกำหนด';
    $deadlineSub = $earliestDaysLeft !== null ? ('เกิน ' . abs($earliestDaysLeft) . ' วัน') : '';
    $alertText = 'มีงานที่เกินกำหนด แนะนำให้เริ่มจากรอบที่ขึ้น “เกินกำหนด” ก่อน';
} elseif ($overallStatus === 'near') {
    $deadlineLabel = 'ใกล้ครบกำหนด';
    $deadlineSub = $earliestDaysLeft !== null ? ('เหลือ ' . $earliestDaysLeft . ' วัน') : '';
    $alertText = 'ใกล้ครบกำหนด แนะนำให้เร่งตรวจนับงานค้างให้เสร็จก่อนถึงกำหนด';
} elseif ($overallStatus === 'ok') {
    $deadlineLabel = 'ตามกำหนด';
    $deadlineSub = $earliestDaysLeft !== null ? ('ใกล้สุด เหลือ ' . $earliestDaysLeft . ' วัน') : '';
} else {
    $deadlineLabel = 'ไม่กำหนด';
    $deadlineSub = $totalPending > 0 ? 'งานค้างไม่มี deadline' : 'รอบเก่าไม่จำกัด';
}

$deadlineCount = count($distinctDeadlines);
$panelSub = 'ค้างตรวจ ' . number_format($totalPending) . ' รายการ • '
          . 'มี ' . count($sessions) . ' รอบ'
          . ($deadlineCount > 1 ? (' • ' . $deadlineCount . ' deadline') : '');

jsonResponse(true, [
    'total_pending' => $totalPending,
    'total_assigned' => $totalAssigned,
    'overall_status' => $overallStatus,
    'deadline_label' => $deadlineLabel,
    'deadline_sub' => $deadlineSub,
    'alert_text' => $alertText,
    'panel_sub' => $panelSub,
    'sessions' => $sessions,
]);
