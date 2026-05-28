<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();

$db = Database::getInstance();

// ═══ COMPANY INFO ═══
$companyInfo = $db->fetchOne("
    SELECT s.site_code, s.site_name, s.legal_name, s.tax_id
    FROM sites s
    WHERE s.is_active = 1
    LIMIT 1
");
$companyName = $companyInfo['site_name'] ?? 'Asset Management System';

// ═══ AUDIT YEAR FILTER ═══
$auditYears = $db->fetchAll("
    SELECT DISTINCT session_year
    FROM audit_sessions
    WHERE session_year IS NOT NULL
    ORDER BY session_year DESC
");
$selectedYear = intval($_GET['audit_year'] ?? (empty($auditYears) ? date('Y') : $auditYears[0]['session_year']));

$dashYearFilterSql = " AND s.session_year = ? ";
$dashYearParams = [$selectedYear];

// ═══ 📊 LOGIC ตรวจสอบยอดต่าง ASSETS กับ AUDIT ASSIGNMENT (เพิ่มเข้ามาใหม่) ═══
// 1. นับจำนวนทรัพย์สินทั้งหมดที่มีอยู่ใน Master Data ปัจจุบัน (ที่มีสถานะ Active)
$masterTotalAssets = $db->fetchOne("SELECT COUNT(*) as c FROM assets WHERE 1=1")['c']; // status = 'active'

// 2. นับจำนวนทรัพย์สินที่ถูกนำเข้าสู่รอบตรวจนับของปีปัจจุบันแล้ว
$assignedInSession = $db->fetchOne("
    SELECT COUNT(DISTINCT aa.asset_id) as c
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    WHERE s.session_year = ?
", [$selectedYear])['c'];

// 3. คำนวณส่วนต่าง (จำนวนทรัพย์สินใหม่ที่ยังไม่ถูกมอบหมายงานตรวจนับ)
$missingAssetCount = max(0, $masterTotalAssets - $assignedInSession);


// ═══ SUMMARY STATS (สถิติตามรอบตรวจนับปีนี้) ═══
$totalAssets  = $db->fetchOne("
    SELECT COUNT(DISTINCT aa.asset_id) as c
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    WHERE 1=1" . $dashYearFilterSql, $dashYearParams)['c'];

$activeAssets = $db->fetchOne("
    SELECT COUNT(DISTINCT aa.asset_id) as c
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    WHERE aa.status='completed' AND IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"active\"%'" . $dashYearFilterSql, $dashYearParams)['c'];

$inactiveAssets = $db->fetchOne("
    SELECT COUNT(DISTINCT aa.asset_id) as c
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    WHERE aa.status='completed' AND (
        IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"returned\"%' OR
        IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"not_found\"%' OR
        IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"inactive\"%' OR
        IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"repairing\"%'
    )" . $dashYearFilterSql, $dashYearParams)['c'];

$totalValue   = $db->fetchOne("
    SELECT SUM(a.acquis_val) as v
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    INNER JOIN assets a ON aa.asset_id = a.id
    WHERE aa.status='completed' AND IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"active\"%'" . $dashYearFilterSql, $dashYearParams)['v'] ?? 0;

// ═══ MONTHLY ACQUISITION DATA ═══
$monthlyData = $db->fetchAll("
    SELECT
        DATE_FORMAT(cap_date, '%Y-%m') as month,
        COUNT(*) as cnt,
        SUM(acquis_val) as val
    FROM assets
    WHERE cap_date IS NOT NULL
    AND YEAR(cap_date) = ?
    GROUP BY MONTH(cap_date), YEAR(cap_date)
    ORDER BY cap_date ASC
", [$selectedYear]);

// Inventory progress
$session = $db->fetchOne("SELECT * FROM audit_sessions ORDER BY id DESC LIMIT 1");
$invTotal    = 0;
$invChecked  = 0;
$invPct      = 0;
if ($session) {
    $invTotal   = $db->fetchOne("SELECT COUNT(DISTINCT asset_id) as c FROM audit_assignments WHERE session_id = ?", [$session['id']])['c'];
    $invChecked = $db->fetchOne("SELECT COUNT(DISTINCT asset_id) as c FROM audit_assignments WHERE session_id = ? AND status = 'completed'", [$session['id']])['c'];
    $invPct     = $invTotal > 0 ? round($invChecked / $invTotal * 100) : 0;
}

// ═══ BY STATUS ═══
$statusCountsRaw = $db->fetchAll("
    SELECT
        CASE
            WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"active\"%' THEN 'active'
            WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"returned\"%' THEN 'returned'
            WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"not_found\"%' THEN 'not_found'
            WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"inactive\"%' THEN 'inactive'
            WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"repairing\"%' THEN 'repairing'
            ELSE 'unknown'
        END as status_key,
        COUNT(DISTINCT aa.asset_id) as cnt
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    WHERE aa.status = 'completed'" . $dashYearFilterSql . "
    GROUP BY status_key
", $dashYearParams);

$statusData = [];
foreach ($statusCountsRaw as $r) {
    if ($r['status_key'] !== 'unknown') {
        $statusData[] = ['status' => $r['status_key'], 'cnt' => $r['cnt']];
    }
}

// ═══ BY DEPARTMENT ═══
$deptData = $db->fetchAll("
    SELECT a.department_name, COUNT(DISTINCT aa.asset_id) as cnt
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    INNER JOIN assets a ON aa.asset_id = a.id
    WHERE 1=1" . $dashYearFilterSql . "
    GROUP BY a.department_name
    ORDER BY cnt DESC LIMIT 6
", $dashYearParams);

// ═══ BY CLASS ═══
$classData = $db->fetchAll("
    SELECT c.class_name, COUNT(DISTINCT aa.asset_id) as cnt
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    INNER JOIN assets a ON aa.asset_id = a.id
    LEFT JOIN asset_classes c ON a.class_code = c.class_code
    WHERE 1=1" . $dashYearFilterSql . "
    GROUP BY a.class_code
    ORDER BY cnt DESC
", $dashYearParams);

// Recent assets
$recentPage  = max(1, intval($_GET['rpage'] ?? 1));
$recentLimit = 8;
$recentOffset = ($recentPage - 1) * $recentLimit;

$recentTotal = $db->fetchOne("
    SELECT COUNT(DISTINCT aa.asset_id) as c
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    WHERE 1=1" . $dashYearFilterSql, $dashYearParams)['c'];

$recentPages = max(1, ceil($recentTotal / $recentLimit));

$recentAssets = $db->fetchAll("
    SELECT a.* FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    INNER JOIN assets a ON aa.asset_id = a.id
    WHERE 1=1" . $dashYearFilterSql . "
    ORDER BY aa.id DESC LIMIT $recentLimit OFFSET $recentOffset
", $dashYearParams);

// ── Plant tab data ──
$plantStats = $db->fetchAll("
    SELECT a.plant_code,
           COALESCE(p.plant_name, a.plant_code) AS plant_name,
           COUNT(DISTINCT aa.asset_id)                                         AS total,
           SUM(CASE WHEN aa.status='completed' AND IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"active\"%' THEN 1 ELSE 0 END) AS active_count,
           SUM(CASE WHEN aa.status='completed' AND IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"inactive\"%' THEN 1 ELSE 0 END) AS cancelled_count,
           SUM(CASE WHEN aa.status='completed' AND IFNULL(aa.remark, '') NOT LIKE '%\"check_result\"%\"active\"%' AND IFNULL(aa.remark, '') NOT LIKE '%\"check_result\"%\"inactive\"%' THEN 1 ELSE 0 END) AS other_count,
           SUM(CASE WHEN aa.status='completed' AND IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"active\"%' THEN COALESCE(a.acquis_val,0) ELSE 0 END) AS total_value
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    INNER JOIN assets a ON aa.asset_id = a.id
    LEFT JOIN plants p ON a.plant_code = p.plant_code
    WHERE a.plant_code IS NOT NULL AND a.plant_code != ''" . $dashYearFilterSql . "
    GROUP BY a.plant_code, p.plant_name
    ORDER BY a.plant_code
", $dashYearParams);

$ccRaw = $db->fetchAll("
    SELECT a.plant_code, a.cost_center,
           COUNT(DISTINCT aa.asset_id) AS total,
           SUM(CASE WHEN aa.status='completed' AND IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"active\"%' THEN 1 ELSE 0 END) AS active_count,
           SUM(CASE WHEN aa.status='completed' AND IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"inactive\"%' THEN 1 ELSE 0 END) AS cancelled_count,
           SUM(CASE WHEN aa.status='completed' AND IFNULL(aa.remark, '') NOT LIKE '%\"check_result\"%\"active\"%' AND IFNULL(aa.remark, '') NOT LIKE '%\"check_result\"%\"inactive\"%' THEN 1 ELSE 0 END) AS other_count,
           SUM(CASE WHEN aa.status='completed' AND IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"active\"%' THEN COALESCE(a.acquis_val,0) ELSE 0 END) AS value_sum
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    INNER JOIN assets a ON aa.asset_id = a.id
    WHERE a.plant_code IS NOT NULL AND a.plant_code != ''" . $dashYearFilterSql . "
    GROUP BY a.plant_code, a.cost_center
    ORDER BY a.plant_code, total DESC
", $dashYearParams);
$ccByPlant = [];
foreach ($ccRaw as $cc) {
    $ccByPlant[$cc['plant_code']][] = $cc;
}

$statusByPlantRaw = $db->fetchAll("
    SELECT a.plant_code,
           CASE
               WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"active\"%' THEN 'active'
               WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"returned\"%' THEN 'returned'
               WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"not_found\"%' THEN 'not_found'
               WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"inactive\"%' THEN 'inactive'
               WHEN IFNULL(aa.remark, '') LIKE '%\"check_result\"%\"repairing\"%' THEN 'repairing'
               ELSE 'unknown'
           END as res_status,
           COUNT(DISTINCT aa.asset_id) AS cnt
    FROM audit_assignments aa
    INNER JOIN audit_sessions s ON aa.session_id = s.id
    INNER JOIN assets a ON aa.asset_id = a.id
    WHERE a.plant_code IS NOT NULL AND a.plant_code != ''" . $dashYearFilterSql . "
    GROUP BY a.plant_code, res_status
", $dashYearParams);
$statusByPlant = [];
foreach ($statusByPlantRaw as $r) {
    if ($r['res_status'] !== 'unknown') {
        $statusByPlant[$r['plant_code']][$r['res_status']] = (int)$r['cnt'];
    }
}
?>
<?php include __DIR__ . '/../components/head.php'; ?>

<div class="min-h-screen">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>

    <main class="main-content flex-1 p-4 sm:p-6">
        <div class="mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">บริษัท</p>
                    <h1 class="text-xl font-bold text-gray-900"><?= htmlspecialchars($companyName) ?></h1>
                    <p class="text-sm text-gray-500 mt-0.5">ภาพรวมทรัพย์สินทั้งหมด</p>
                </div>

                <div class="flex items-end gap-3">
                    <div>
                        <label for="auditYearSelect" class="block text-xs font-semibold text-gray-600 mb-1.5">ปีการตรวจนับ</label>
                        <select id="auditYearSelect" onchange="changeAuditYear(this.value)" class="px-3 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-900 bg-white hover:border-gray-400 cursor-pointer">
                            <?php foreach ($auditYears as $year): ?>
                            <option value="<?= htmlspecialchars($year['session_year']) ?>" <?= $year['session_year'] == $selectedYear ? 'selected' : '' ?>>
                                ปี <?= htmlspecialchars($year['session_year']) ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if (empty($auditYears)): ?>
                            <option value="<?= date('Y') ?>" selected>ปี <?= date('Y') ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-6 p-4 rounded-xl border <?= $missingAssetCount > 0 ? 'bg-amber-50 border-amber-200 text-amber-900' : 'bg-green-50 border-green-200 text-green-900' ?>">
            <div class="flex items-start gap-3">
                <div class="mt-0.5">
                    <?php if ($missingAssetCount > 0): ?>
                        <i class="fas fa-exclamation-triangle text-amber-500 text-lg"></i>
                    <?php else: ?>
                        <i class="fas fa-check-circle text-green-500 text-lg"></i>
                    <?php endif; ?>
                </div>
                <div class="flex-1">
                    <h3 class="font-bold text-sm">💡 ตรวจสอบความสอดคล้องของข้อมูลประจำเดือน</h3>
                    <p class="text-xs mt-1 text-gray-600 leading-relaxed">
                        ปัจจุบันมีทรัพย์สิน (Active) ใน Master Data ทั้งหมด <span class="font-bold text-gray-900"><?= number_format($masterTotalAssets) ?></span> รายการ
                        | ถูกดึงเข้าสู่รอบตรวจนับปี <?= $selectedYear ?> แล้ว <span class="font-bold text-gray-900"><?= number_format($assignedInSession) ?></span> รายการ
                    </p>

                    <?php if ($missingAssetCount > 0): ?>
                        <div class="mt-3 flex items-center justify-between bg-white/60 p-2.5 rounded-lg border border-amber-200/50 flex-wrap gap-2">
                            <span class="text-xs font-medium text-amber-800">
                                <i class="fas fa-sync-alt animate-spin mr-1"></i> พบทรัพย์สินใหม่ตกหล่นอยู่ <span class="font-bold text-red-600 underline text-sm"><?= number_format($missingAssetCount) ?></span> รายการ ที่ยังไม่ถูกส่งไปรับการตรวจนับในปีนี้!
                            </span>
                            <span class="text-[11px] bg-amber-600 text-white px-2.5 py-1 rounded-md font-semibold opacity-90">ต้องการเอาทรัพย์สินใหม่เข้าตรวจนับประจำเดือน</span>
                        </div>
                    <?php else: ?>
                        <p class="text-xs mt-1.5 text-green-700 font-medium">
                            <i class="fas fa-star mr-1"></i> ยอดเยี่ยม! รายการทรัพย์สินในระบบและรอบตรวจนับตรงกันครบถ้วนสมบูรณ์แล้ว
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="mb-5 border-b border-gray-200">
            <nav class="flex overflow-x-auto gap-0.5 -mb-px no-scrollbar">
                <button onclick="switchPlantTab('all')" id="tab-all"
                    class="plant-tab active flex-shrink-0 px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors border-blue-600 text-blue-700 bg-blue-50/50">
                    <i class="fas fa-globe mr-1"></i> ทั้งหมด
                </button>
                <?php foreach ($plantStats as $ps): ?>
                <button onclick="switchPlantTab('<?= htmlspecialchars($ps['plant_code'], ENT_QUOTES) ?>')"
                    id="tab-<?= htmlspecialchars($ps['plant_code'], ENT_QUOTES) ?>"
                    class="plant-tab flex-shrink-0 px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    <?= htmlspecialchars($ps['plant_code']) ?>
                    <span class="ml-1 text-[10px] text-gray-400">(<?= number_format($ps['total']) ?>)</span>
                </button>
                <?php endforeach; ?>
            </nav>
        </div>

        <div id="content-all">

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="stat-card">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">ทรัพย์สินในรอบปีนี้</p>
                    <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center">
                        <i class="fas fa-cubes text-blue-600 text-sm"></i>
                    </div>
                </div>
                <p class="text-2xl font-bold text-gray-900"><?= number_format($totalAssets) ?></p>
                <p class="text-xs text-gray-400 mt-1">รายการ</p>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">ใช้งานอยู่</p>
                    <div class="w-9 h-9 rounded-xl bg-green-50 flex items-center justify-center">
                        <i class="fas fa-check-circle text-green-600 text-sm"></i>
                    </div>
                </div>
                <p class="text-2xl font-bold text-gray-900"><?= number_format($activeAssets) ?></p>
                <p class="text-xs text-green-600 mt-1 font-medium"><?= $totalAssets > 0 ? round($activeAssets/$totalAssets*100) : 0 ?>% ของทั้งหมด</p>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">ไม่ใช้งาน/อื่นๆ</p>
                    <div class="w-9 h-9 rounded-xl bg-amber-50 flex items-center justify-center">
                        <i class="fas fa-exclamation-circle text-amber-500 text-sm"></i>
                    </div>
                </div>
                <p class="text-2xl font-bold text-gray-900"><?= number_format($inactiveAssets) ?></p>
                <p class="text-xs text-amber-600 mt-1">รายการ</p>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">มูลค่ารวม</p>
                    <div class="w-9 h-9 rounded-xl bg-purple-50 flex items-center justify-center">
                        <i class="fas fa-coins text-purple-600 text-sm"></i>
                    </div>
                </div>
                <p class="text-xl font-bold text-gray-900"><?= number_format($totalValue/1000000, 2) ?>M</p>
                <p class="text-xs text-gray-400 mt-1">บาท</p>
            </div>
        </div>

        <?php if ($session): ?>
        <div class="card p-5 mb-6">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h2 class="font-bold text-gray-900 text-sm">ความคืบหน้าการตรวจนับ</h2>
                    <p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($session['session_name']) ?></p>
                </div>
                <a href="inventory.php" class="btn btn-primary btn-sm">ไปตรวจนับ</a>
            </div>
            <div class="flex items-center gap-4">
                <div class="flex-1">
                    <div class="h-3 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-3 rounded-full transition-all duration-700"
                             style="width: <?= $invPct ?>%; background: linear-gradient(90deg,#2563eb,#7c3aed)"></div>
                    </div>
                </div>
                <span class="text-sm font-bold text-gray-900 w-12 text-right"><?= $invPct ?>%</span>
            </div>
            <p class="text-xs text-gray-400 mt-2">ตรวจแล้ว <?= number_format($invChecked) ?> / <?= number_format($invTotal) ?> รายการ</p>
        </div>
        <?php endif; ?>

        <div class="card p-5 mb-6">
            <h2 class="font-bold text-gray-900 text-sm mb-4">ทรัพย์สินที่ซื้อรายเดือน (ปี <?= htmlspecialchars($selectedYear) ?>)</h2>
            <div id="chartMonthly" class="min-h-[220px]"></div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
            <div class="card p-5 lg:col-span-2">
                <h2 class="font-bold text-gray-900 text-sm mb-4">ทรัพย์สินตามแผนก (Top 6)</h2>
                <div id="chartDept" class="min-h-[220px]"></div>
            </div>
            <div class="card p-5">
                <h2 class="font-bold text-gray-900 text-sm mb-4">สถานะทรัพย์สิน</h2>
                <div id="chartStatus" class="min-h-[220px]"></div>
            </div>
        </div>

        <div class="card">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h2 class="font-bold text-gray-900 text-sm">ทรัพย์สินล่าสุดในรอบปีนี้</h2>
                <a href="asset-list.php" class="text-xs text-blue-600 hover:underline font-medium">ดูทั้งหมด →</a>
            </div>
            <div class="table-wrap">
                <table class="data-table" id="recentTable">
                    <thead>
                        <tr>
                            <th>Asset No</th>
                            <th>รายละเอียด</th>
                            <th>แผนก</th>
                            <th>มูลค่า</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAssets as $a): ?>
                        <tr>
                            <td class="font-mono text-xs font-semibold text-blue-700">
                                <a href="asset-detail.php?id=<?= $a['id'] ?>" class="hover:underline"><?= htmlspecialchars($a['asset_no']) ?></a>
                            </td>
                            <td class="max-w-[200px]">
                                <p class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($a['asset_description'] ?? '-') ?></p>
                            </td>
                            <td class="text-xs text-gray-500"><?= htmlspecialchars($a['department_name'] ?? '-') ?></td>
                            <td class="text-sm font-medium text-gray-700"><?= number_format($a['acquis_val'], 2) ?></td>
                            <td>
                                <?php
                                $slabel = ['active'=>'ใช้งาน', 'returned'=>'ชำรุด-ส่งคืน', 'not_found'=>'ไม่พบ', 'inactive'=>'ไม่ใช้งาน', 'repairing'=>'ชำรุด-รอซ่อม'];
                                $scolor = ['active'=>'#16a34a', 'returned'=>'#2563eb', 'not_found'=>'#dc2626', 'inactive'=>'#9ca3af', 'repairing'=>'#f59e0b'];
                                $status = $a['status'] ?? 'active';
                                $color  = $scolor[$status] ?? '#9ca3af';
                                ?>
                                <span class="badge" style="background-color: <?= $color ?>; color: white; padding: 0.375rem 0.75rem; border-radius: 0.375rem; font-size: 0.8125rem; font-weight: 500;">
                                    <?= htmlspecialchars($slabel[$status] ?? $status) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($recentPages > 1): ?>
            <div class="flex items-center justify-between px-5 py-3 border-t border-gray-100">
                <p class="text-xs text-gray-500"><?= $recentOffset + 1 ?>–<?= min($recentOffset + $recentLimit, $recentTotal) ?> จาก <?= number_format($recentTotal) ?></p>
                <div class="pagination">
                    <?php if ($recentPage > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['rpage' => $recentPage - 1])) ?>" class="page-btn">‹</a>
                    <?php endif; ?>
                    <?php for ($p = max(1, $recentPage - 2); $p <= min($recentPages, $recentPage + 2); $p++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['rpage' => $p])) ?>" class="page-btn <?= $p === $recentPage ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($recentPage < $recentPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['rpage' => $recentPage + 1])) ?>" class="page-btn">›</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        </div><?php foreach ($plantStats as $ps):
            $pCode = $ps['plant_code'];
            $pCCs  = $ccByPlant[$pCode] ?? [];
            $pStat = $statusByPlant[$pCode] ?? [];
            $pTotal = (int)$ps['total'];
            $pActive = (int)$ps['active_count'];
            $pPct    = $pTotal > 0 ? round($pActive / $pTotal * 100) : 0;
        ?>
        <div id="content-<?= htmlspecialchars($pCode, ENT_QUOTES) ?>" class="hidden">

            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-base font-bold text-gray-900">
                        <span class="font-mono text-blue-700"><?= htmlspecialchars($pCode) ?></span>
                        <?php if ($ps['plant_name'] !== $pCode): ?>
                        <span class="text-gray-500 font-normal text-sm ml-2">— <?= htmlspecialchars($ps['plant_name']) ?></span>
                        <?php endif; ?>
                    </h2>
                </div>
                <a href="asset-list.php?plant=<?= urlencode($pCode) ?>" class="text-xs text-blue-600 hover:underline font-medium">ดูรายการทั้งหมด →</a>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
                <div class="stat-card">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-gray-500 uppercase">ทรัพย์สินทั้งหมด</p>
                        <div class="w-8 h-8 rounded-xl bg-blue-50 flex items-center justify-center">
                            <i class="fas fa-cubes text-blue-600 text-xs"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format($ps['total']) ?></p>
                    <p class="text-xs text-gray-400 mt-1">รายการ</p>
                </div>
                <div class="stat-card">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-gray-500 uppercase">ใช้งานอยู่</p>
                        <div class="w-8 h-8 rounded-xl bg-green-50 flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 text-xs"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format($ps['active_count']) ?></p>
                    <p class="text-xs text-green-600 mt-1 font-medium"><?= $pPct ?>%</p>
                </div>
                <div class="stat-card">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-gray-500 uppercase">ไม่ใช้งาน/อื่นๆ</p>
                        <div class="w-8 h-8 rounded-xl bg-amber-50 flex items-center justify-center">
                            <i class="fas fa-exclamation-circle text-amber-500 text-xs"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format((int)$ps['other_count']) ?></p>
                    <p class="text-xs text-gray-400 mt-1">รายการ</p>
                </div>
                <div class="stat-card">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-gray-500 uppercase">มูลค่ารวม</p>
                        <div class="w-8 h-8 rounded-xl bg-purple-50 flex items-center justify-center">
                            <i class="fas fa-coins text-purple-600 text-xs"></i>
                        </div>
                    </div>
                    <p class="text-lg font-bold text-gray-900"><?= number_format((float)$ps['total_value']/1000000, 2) ?>M</p>
                    <p class="text-xs text-gray-400 mt-1">บาท</p>
                </div>
            </div>

            <?php if ($pTotal > 0): ?>
            <div class="card p-4 mb-5">
                <h3 class="text-xs font-bold text-gray-700 mb-3 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-gray-400"></i> สัดส่วนสถานะ
                </h3>
                <div class="flex h-5 rounded-full overflow-hidden gap-px mb-3">
                    <?php
                    $statColors = ['active'=>'bg-green-500','cancelled'=>'bg-red-400','returned'=>'bg-blue-400','not_found'=>'bg-red-600','inactive'=>'bg-gray-400','repairing'=>'bg-amber-400'];
                    $statLabels = ['active'=>'ใช้งาน','cancelled'=>'ยกเลิก','returned'=>'ส่งคืน','not_found'=>'ไม่พบ','inactive'=>'ไม่ใช้งาน','repairing'=>'รอซ่อม'];
                    arsort($pStat);
                    foreach ($pStat as $st => $cnt):
                        $pct = round($cnt / $pTotal * 100);
                        if ($pct < 1) continue;
                    ?>
                    <div class="<?= $statColors[$st] ?? 'bg-gray-300' ?> h-full transition-all" style="width:<?= $pct ?>%"
                         title="<?= htmlspecialchars($statLabels[$st] ?? $st) ?>: <?= $cnt ?> (<?= $pct ?>%)"></div>
                    <?php endforeach; ?>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <?php foreach ($pStat as $st => $cnt):
                        $pct = round($cnt / $pTotal * 100);
                    ?>
                    <div class="flex items-center gap-1.5 text-xs text-gray-600">
                        <span class="w-2.5 h-2.5 rounded-full <?= $statColors[$st] ?? 'bg-gray-300' ?>"></span>
                        <?= htmlspecialchars($statLabels[$st] ?? $st) ?>:
                        <span class="font-semibold text-gray-900"><?= number_format($cnt) ?></span>
                        <span class="text-gray-400">(<?= $pct ?>%)</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($pCCs)): ?>
            <div class="card">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-xs font-bold text-gray-700 flex items-center gap-2">
                        <i class="fas fa-table text-gray-400"></i> Cost Center ใน <?= htmlspecialchars($pCode) ?>
                    </h3>
                    <span class="text-xs text-gray-400"><?= count($pCCs) ?> CC</span>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Cost Center</th>
                                <th class="text-center">รวม</th>
                                <th class="text-center">ใช้งาน</th>
                                <th class="text-center">อื่นๆ</th>
                                <th class="text-center">มูลค่า (บาท)</th>
                                <th style="min-width:120px;">สัดส่วน</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pCCs as $cc):
                                $ccPct = $cc['total'] > 0 ? round($cc['active_count'] / $cc['total'] * 100) : 0;
                            ?>
                            <tr>
                                <td class="font-mono text-xs font-semibold text-blue-700">
                                    <a href="asset-list.php?plant=<?= urlencode($pCode) ?>&cost_center=<?= urlencode($cc['cost_center'] ?? '') ?>" class="hover:underline">
                                        <?= htmlspecialchars($cc['cost_center'] ?: '(ไม่ระบุ)') ?>
                                    </a>
                                </td>
                                <td class="text-center font-semibold text-gray-900 text-sm"><?= number_format($cc['total']) ?></td>
                                <td class="text-center text-green-700 font-semibold text-sm"><?= number_format($cc['active_count']) ?></td>
                                <td class="text-center text-amber-600 text-sm"><?= number_format((int)$cc['other_count'] + (int)$cc['cancelled_count']) ?></td>
                                <td class="text-center text-gray-600 text-xs font-mono"><?= number_format((float)$cc['value_sum'], 0) ?></td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                            <div class="h-1.5 bg-green-500 rounded-full" style="width:<?= $ccPct ?>%"></div>
                                        </div>
                                        <span class="text-[10px] text-gray-500 w-7 text-right"><?= $ccPct ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="card px-5 py-8 text-center text-sm text-gray-400">ไม่พบข้อมูล Cost Center</div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    </main>
</div>

<div id="toast-container"></div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
function switchPlantTab(tabId) {
    document.querySelectorAll('[id^="content-"]').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.plant-tab').forEach(btn => {
        btn.classList.remove('border-blue-600', 'text-blue-700', 'bg-blue-50/50');
        btn.classList.add('border-transparent', 'text-gray-500');
    });
    const target = document.getElementById('content-' + tabId);
    if (target) target.classList.remove('hidden');
    const activeBtn = document.getElementById('tab-' + tabId);
    if (activeBtn) {
        activeBtn.classList.remove('border-transparent', 'text-gray-500');
        activeBtn.classList.add('border-blue-600', 'text-blue-700', 'bg-blue-50/50');
    }
}

// Chart: Department
const deptLabels = <?= json_encode(array_column($deptData, 'department_name')) ?>;
const deptCounts = <?= json_encode(array_map('intval', array_column($deptData, 'cnt'))) ?>;

new ApexCharts(document.getElementById('chartDept'), {
    chart: { type: 'bar', height: 220, toolbar: { show: false }, fontFamily: 'IBM Plex Sans Thai, sans-serif' },
    series: [{ name: 'ทรัพย์สิน', data: deptCounts }],
    xaxis: { categories: deptLabels, labels: { style: { fontSize: '11px', colors: '#64748b' } } },
    yaxis: { labels: { style: { fontSize: '11px', colors: '#64748b' } } },
    colors: ['#2563eb'],
    plotOptions: { bar: { borderRadius: 5, columnWidth: '55%' } },
    grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
    dataLabels: { enabled: false },
    tooltip: { y: { formatter: v => v + ' รายการ' } }
}).render();

// Chart: Status
const statusRaw = <?= json_encode($statusData) ?>;
const statusLabels = { active:'ใช้งาน', returned:'ชำรุด-ส่งคืน', not_found:'ไม่พบ', inactive:'ไม่ใช้งาน', repairing:'ชำรุด-รอซ่อม' };
const statusColors = { active:'#16a34a', returned:'#2563eb', not_found:'#dc2626', inactive:'#9ca3af', repairing:'#f59e0b' };

new ApexCharts(document.getElementById('chartStatus'), {
    chart: { type: 'donut', height: 220, fontFamily: 'IBM Plex Sans Thai, sans-serif' },
    series: statusRaw.map(r => parseInt(r.cnt)),
    labels: statusRaw.map(r => statusLabels[r.status] || r.status),
    colors: statusRaw.map(r => statusColors[r.status] || '#94a3b8'),
    legend: { position: 'bottom', fontSize: '11px' },
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'ทั้งหมด', fontSize: '12px' } } } } },
    dataLabels: { enabled: false },
    stroke: { width: 2 }
}).render();

// Chart: Monthly Acquisition
const monthlyRaw = <?= json_encode($monthlyData) ?>;
const monthlyLabels = monthlyRaw.map(m => m.month);
const monthlyCounts = monthlyRaw.map(m => parseInt(m.cnt));
const monthlyValues = monthlyRaw.map(m => parseFloat(m.val) || 0);

new ApexCharts(document.getElementById('chartMonthly'), {
    chart: { type: 'area', height: 220, toolbar: { show: false }, fontFamily: 'IBM Plex Sans Thai, sans-serif' },
    series: [{ name: 'จำนวนทรัพย์สิน', data: monthlyCounts }],
    xaxis: { categories: monthlyLabels, labels: { style: { fontSize: '11px', colors: '#64748b' } } },
    colors: ['#2563eb'],
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
    stroke: { curve: 'smooth', width: 2 },
    grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
    dataLabels: { enabled: false },
    tooltip: {
        y: { formatter: v => v + ' รายการ', title: { formatter: () => '' } },
        custom: ({ series, seriesIndex, dataPointIndex, w }) => {
            const cnt = series[0]?.[dataPointIndex] || 0;
            const val = monthlyValues[dataPointIndex] || 0;
            return `
                <div class="apexcharts-tooltip-custom">
                    <span class="text-xs font-medium">จำนวน: ${cnt} รายการ</span><br>
                    <span class="text-xs font-medium">มูลค่า: ${new Intl.NumberFormat('th-TH').format(val)} บาท</span>
                </div>
            `;
        }
    }
}).render();

function changeAuditYear(year) {
    const url = new URL(window.location);
    url.searchParams.set('audit_year', year);
    window.location = url.toString();
}
</script>
</body>
</html>