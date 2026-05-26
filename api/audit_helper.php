<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';

header('Content-Type: application/json');

startSession();

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session หมดอายุ กรุณา Login ใหม่']);
    exit;
}
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'webadmin'])) {
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}

$db     = Database::getInstance();
$input  = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    switch ($action) {
        case 'get_cc': {
            $plant = trim($_GET['plant'] ?? '');
            if (empty($plant)) {
                echo json_encode(['success' => false, 'message' => 'กรุณาระบุ plant']);
                exit;
            }
            $year = (int)($_GET['year'] ?? date('Y'));

            /**
             * นิยาม pending ต่อ cost center:
             * - นับ assets active ที่ "ยังไม่เคย completed" ในปีที่เลือก (อิง audit_sessions.session_year)
             */
            $sql = "SELECT
                        a.cost_center,
                        COUNT(a.id) AS total_count,
                        SUM(CASE WHEN c.asset_id IS NULL THEN 1 ELSE 0 END) AS pending_count
                    FROM assets a
                    LEFT JOIN (
                        SELECT DISTINCT aa.asset_id
                        FROM audit_assignments aa
                        JOIN audit_sessions s ON s.id = aa.session_id
                        WHERE s.plant_code = ?
                          AND s.session_year = ?
                          AND aa.status = 'completed'
                    ) c ON c.asset_id = a.id
                    WHERE a.plant_code = ?
                      AND a.status = 'active'
                    GROUP BY a.cost_center
                    ORDER BY a.cost_center ASC";

            $rows = $db->fetchAll($sql, [$plant, $year, $plant]);

            $pending = [];
            $completed = [];
            foreach ($rows as $r) {
                $r['pending_count'] = (int)$r['pending_count'];
                $r['total_count'] = (int)$r['total_count'];
                if ($r['total_count'] <= 0) continue;
                if ($r['pending_count'] > 0) $pending[] = $r;
                else $completed[] = $r;
            }

            echo json_encode(['success' => true, 'data' => $pending, 'completed' => $completed]);
            break;
        }

        case 'get_user_workload': {
            $plant = trim($_GET['plant'] ?? '');
            if (empty($plant)) {
                echo json_encode(['success' => false, 'message' => 'กรุณาระบุ plant']);
                exit;
            }
            $year = (int)($_GET['year'] ?? date('Y'));

            $sql = "SELECT
                        u.id          AS user_id,
                        u.full_name,
                        u.username,
                        s.id          AS session_id,
                        s.session_name,
                        s.created_at  AS session_created_at,
                        s.deadline_date AS deadline_date,
                        COUNT(aa.id)  AS assigned_count,
                        SUM(CASE WHEN aa.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                        SUM(CASE WHEN aa.status = 'pending'   THEN 1 ELSE 0 END) AS pending_count,
                        (
                            SELECT GROUP_CONCAT(DISTINCT cost_center)
                            FROM assets
                            WHERE id IN (
                                SELECT asset_id FROM audit_assignments
                                WHERE session_id = s.id AND user_id = u.id
                            )
                            AND plant_code = s.plant_code
                        ) AS cost_centers
                    FROM users u
                    JOIN audit_assignments aa ON aa.user_id = u.id
                    JOIN audit_sessions    s  ON s.id = aa.session_id
                    WHERE s.plant_code = ?
                      AND s.session_year = ?
                    GROUP BY u.id, s.id
                    ORDER BY u.full_name ASC, s.created_at DESC";

            $rows = $db->fetchAll($sql, [$plant, $year]);

            // จัดกลุ่มตาม user
            $result = [];
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if (!isset($result[$uid])) {
                    $result[$uid] = [
                        'user_id'   => $uid,
                        'full_name' => $row['full_name'],
                        'username'  => $row['username'],
                        'sessions'  => [],
                        'total_pending'   => 0,
                        'total_completed' => 0,
                    ];
                }
                $result[$uid]['sessions'][] = [
                    'session_id'    => (int)$row['session_id'],
                    'session_name'  => $row['session_name'],
                    'created_at'    => $row['session_created_at'],
                    'deadline_date' => $row['deadline_date'],
                    'assigned'      => (int)$row['assigned_count'],
                    'completed'     => (int)$row['completed_count'],
                    'pending'       => (int)$row['pending_count'],
                    'cost_centers'  => $row['cost_centers']
                ];
                $result[$uid]['total_pending']   += (int)$row['pending_count'];
                $result[$uid]['total_completed'] += (int)$row['completed_count'];
            }

            echo json_encode(['success' => true, 'data' => array_values($result)]);
            break;
        }

        case 'get_pending_assignments': {
            $plant = trim($_GET['plant'] ?? '');
            if (empty($plant)) {
                echo json_encode(['success' => false, 'message' => 'กรุณาระบุ plant']);
                exit;
            }
            $year = (int)($_GET['year'] ?? date('Y'));

            // ดึงเฉพาะรายการที่ยัง pending อยู่ของแต่ละคน (แยกตามปี)
            $sql = "SELECT
                        aa.user_id,
                        u.full_name,
                        aa.session_id,
                        COUNT(aa.id) as pending_count,
                        GROUP_CONCAT(DISTINCT a.cost_center) as cost_centers
                    FROM audit_assignments aa
                    JOIN audit_sessions s ON s.id = aa.session_id
                    JOIN assets a ON aa.asset_id = a.id
                    JOIN users u ON aa.user_id = u.id
                    WHERE s.plant_code = ?
                      AND s.session_year = ?
                      AND aa.status = 'pending'
                    GROUP BY aa.user_id, aa.session_id";

            $data = $db->fetchAll($sql, [$plant, $year]);
            echo json_encode(['success' => true, 'data' => $data]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
