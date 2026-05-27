<?php
$pageTitle = 'รายงาน';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();

$db = Database::getInstance();

// ═══ COMPANY INFO ═══
$companyInfo = $db->fetchOne("SELECT s.site_code, s.site_name, s.legal_name, s.tax_id FROM sites s WHERE s.is_active = 1 LIMIT 1");
$companyName = $companyInfo['site_name'] ?? 'Asset Management System';

// ═══ AUDIT YEAR FILTER ═══
$auditYears = $db->fetchAll("SELECT DISTINCT session_year FROM audit_sessions WHERE session_year IS NOT NULL ORDER BY session_year DESC");
$selectedYear = intval($_GET['audit_year'] ?? (empty($auditYears) ? date('Y') : $auditYears[0]['session_year']));
$selectedSpan = max(0, min(3, intval($_GET['year_span'] ?? 0)));
$yearStart = $selectedYear - $selectedSpan;
$yearLabel = $selectedSpan > 0 ? sprintf('%d - %d', $yearStart, $selectedYear) : (string)$selectedYear;

// ═══ PLANT FILTER ═══
$selectedPlant = trim($_GET['plant'] ?? '');
$plantStats = $db->fetchAll(
    "SELECT a.plant_code, COALESCE(p.plant_name, a.plant_code) AS plant_name, COUNT(a.id) as cnt, IFNULL(SUM(a.acquis_val),0) as val " .
    "FROM assets a LEFT JOIN plants p ON a.plant_code=p.plant_code " .
    "WHERE a.plant_code IS NOT NULL AND a.plant_code != '' " .
    "GROUP BY a.plant_code, p.plant_name ORDER BY a.plant_code"
);

$plantFilterSql = '';
$plantFilterParams = [];
if ($selectedPlant !== '') {
    $plantFilterSql = " AND plant_code = ?";
    $plantFilterParams[] = $selectedPlant;
}

$yearFilterSql = " AND YEAR(cap_date) BETWEEN ? AND ?";
$yearFilterParams = [$yearStart, $selectedYear];
$summaryFilterSql = $plantFilterSql . $yearFilterSql;
$summaryFilterParams = array_merge($plantFilterParams, $yearFilterParams);

// Summary data
$totalAssets   = $db->fetchOne("SELECT COUNT(*) as c FROM assets WHERE 1=1" . $summaryFilterSql, $summaryFilterParams)['c'];
$activeAssets  = $db->fetchOne("SELECT COUNT(*) as c FROM assets WHERE status='active'" . $summaryFilterSql, $summaryFilterParams)['c'];
$cancelled     = $db->fetchOne("SELECT COUNT(*) as c FROM assets WHERE status='cancelled'" . $summaryFilterSql, $summaryFilterParams)['c'];
$totalValue    = $db->fetchOne("SELECT IFNULL(SUM(acquis_val),0) as v FROM assets WHERE status='active'" . $summaryFilterSql, $summaryFilterParams)['v'];

$openAuditSession = $db->fetchOne("SELECT * FROM audit_sessions ORDER BY id DESC LIMIT 1");
$checkedByDept = [];
if ($openAuditSession) {
    $rows = $db->fetchAll(
        "SELECT a.department_code, COUNT(DISTINCT aa.asset_id) AS checked_count " .
        "FROM audit_assignments aa " .
        "JOIN assets a ON aa.asset_id = a.id " .
        "WHERE aa.session_id = ? AND aa.status = 'completed' GROUP BY a.department_code",
        [$openAuditSession['id']]
    );
    foreach ($rows as $row) {
        $checkedByDept[$row['department_code']] = (int)$row['checked_count'];
    }
}

// By Department
$byDept = $db->fetchAll(
    "SELECT a.department_code, a.department_name, COUNT(*) as total, COUNT(*) as cnt, " .
    "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"active\"%' THEN 1 ELSE 0 END) AS active_count, " .
    "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"returned\"%' THEN 1 ELSE 0 END) AS returned_count, " .
    "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"repairing\"%' THEN 1 ELSE 0 END) AS repairing_count, " .
    "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"not_found\"%' THEN 1 ELSE 0 END) AS not_found_count, " .
    "SUM(CASE WHEN aa.remark LIKE '%\"check_result\":\"inactive\"%' THEN 1 ELSE 0 END) AS inactive_count, " .
    "IFNULL(SUM(a.acquis_val),0) as val " .
    "FROM assets a " .
    "LEFT JOIN (" .
    "    SELECT aa1.asset_id, aa1.remark " .
    "    FROM audit_assignments aa1 " .
    "    WHERE aa1.status = 'completed' " .
    "      AND aa1.id = (" .
    "          SELECT MAX(aa2.id) " .
    "          FROM audit_assignments aa2 " .
    "          WHERE aa2.asset_id = aa1.asset_id " .
    "            AND aa2.status = 'completed'" .
    "      )" .
    ") aa ON aa.asset_id = a.id " .
    "WHERE 1=1" . $plantFilterSql . $yearFilterSql . " GROUP BY a.department_code, a.department_name ORDER BY total DESC",
    array_merge($plantFilterParams, $yearFilterParams)
);

// By Class
$classPage   = max(1, intval($_GET['cpage'] ?? 1));
$classLimit  = 10;
$classOffset = ($classPage - 1) * $classLimit;
$classTotal  = $db->fetchOne("SELECT COUNT(DISTINCT a.class_code) as c FROM assets a WHERE a.status='active'" . $plantFilterSql . $yearFilterSql, array_merge($plantFilterParams, $yearFilterParams))['c'];
$classPages  = max(1, ceil($classTotal / $classLimit));
$byClass = $db->fetchAll("SELECT c.class_code, c.class_name, COUNT(a.id) as cnt, IFNULL(SUM(a.acquis_val),0) as val FROM assets a LEFT JOIN asset_classes c ON a.class_code=c.class_code WHERE a.status='active'" . $plantFilterSql . $yearFilterSql . " GROUP BY c.class_code, c.class_name ORDER BY cnt DESC LIMIT $classLimit OFFSET $classOffset", array_merge($plantFilterParams, $yearFilterParams));

// Monthly acquisition (selected audit year)
$monthlyWhere = "WHERE cap_date IS NOT NULL";
$monthlyParams = [];
if ($selectedPlant !== '') {
    $monthlyWhere .= " AND plant_code = ?";
    $monthlyParams[] = $selectedPlant;
}
$monthlyWhere .= " AND YEAR(cap_date) = ?";
$monthlyParams[] = $selectedYear;
$monthly = $db->fetchAll(
    "SELECT DATE_FORMAT(cap_date,'%Y-%m') as month, COUNT(*) as cnt, SUM(acquis_val) as val FROM assets " . $monthlyWhere . " GROUP BY month ORDER BY month ASC",
    $monthlyParams
);
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
                    <p class="text-sm text-gray-500 mt-0.5">รายงานทรัพย์สินและการตรวจนับ</p>
                </div>
                <div class="flex items-end gap-3 flex-wrap">
                    <a href="<?= APP_URL ?>/api/export.php?type=assets&format=csv<?= $selectedPlant ? '&plant=' . urlencode($selectedPlant) : '' ?><?= '&audit_year=' . urlencode($selectedYear) . '&year_span=' . urlencode($selectedSpan) ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-file-csv text-green-600"></i> Export Assets
                    </a>
                    <a href="<?= APP_URL ?>/api/export.php?type=report&section=dept&format=csv<?= $selectedPlant ? '&plant=' . urlencode($selectedPlant) : '' ?><?= '&audit_year=' . urlencode($selectedYear) . '&year_span=' . urlencode($selectedSpan) ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-file-csv text-green-600"></i> Export Dept
                    </a>
                    <a href="<?= APP_URL ?>/api/export.php?type=report&section=class&format=csv<?= $selectedPlant ? '&plant=' . urlencode($selectedPlant) : '' ?><?= '&audit_year=' . urlencode($selectedYear) . '&year_span=' . urlencode($selectedSpan) ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-file-csv text-green-600"></i> Export Class
                    </a>
                    <a href="<?= APP_URL ?>/api/export.php?type=report&section=monthly&format=csv<?= $selectedPlant ? '&plant=' . urlencode($selectedPlant) : '' ?><?= '&audit_year=' . urlencode($selectedYear) . '&year_span=' . urlencode($selectedSpan) ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-file-csv text-green-600"></i> Export Monthly
                    </a>
                    <a href="<?= APP_URL ?>/api/export.php?type=report&section=all&format=csv<?= $selectedPlant ? '&plant=' . urlencode($selectedPlant) : '' ?><?= '&audit_year=' . urlencode($selectedYear) . '&year_span=' . urlencode($selectedSpan) ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-file-csv text-green-600"></i> Export All Summary
                    </a>
                    <button onclick="printPage()" class="btn btn-secondary btn-sm">
                        <i class="fas fa-print text-blue-600"></i> พิมพ์
                    </button>
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
                    <div>
                        <label for="yearSpanSelect" class="block text-xs font-semibold text-gray-600 mb-1.5">ย้อนหลัง</label>
                        <select id="yearSpanSelect" onchange="changeYearSpan(this.value)" class="px-3 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-900 bg-white hover:border-gray-400 cursor-pointer">
                            <?php for ($span = 0; $span <= 3; $span++): ?>
                            <option value="<?= $span ?>" <?= $span === $selectedSpan ? 'selected' : '' ?>>
                                <?= $span === 0 ? 'ปีปัจจุบัน' : 'ย้อนหลัง ' . $span . ' ปี' ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-5 border-b border-gray-200">
            <nav class="flex overflow-x-auto gap-0.5 -mb-px no-scrollbar">
                <button onclick="changePlantFilter('')" id="tab-all" class="plant-tab flex-shrink-0 px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors <?= $selectedPlant === '' ? 'border-blue-600 text-blue-700 bg-blue-50/50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                    <i class="fas fa-globe mr-1"></i> ทั้งหมด
                </button>
                <?php foreach ($plantStats as $ps): ?>
                <button onclick="changePlantFilter('<?= htmlspecialchars($ps['plant_code'], ENT_QUOTES) ?>')" id="tab-<?= htmlspecialchars($ps['plant_code'], ENT_QUOTES) ?>" class="plant-tab flex-shrink-0 px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors <?= $selectedPlant === $ps['plant_code'] ? 'border-blue-600 text-blue-700 bg-blue-50/50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                    <?= htmlspecialchars($ps['plant_code']) ?>
                    <span class="ml-1 text-[10px] text-gray-400">(<?= number_format($ps['cnt']) ?>)</span>
                </button>
                <?php endforeach; ?>
            </nav>
        </div>

        <!-- Summary Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="stat-card">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">ทรัพย์สินทั้งหมด</p>
                <p class="text-2xl font-bold text-gray-900"><?= number_format($totalAssets) ?></p>
            </div>
            <div class="stat-card">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">ใช้งานอยู่</p>
                <p class="text-2xl font-bold text-green-600"><?= number_format($activeAssets) ?></p>
            </div>
            <div class="stat-card">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">ยกเลิก</p>
                <p class="text-2xl font-bold text-red-600"><?= number_format($cancelled) ?></p>
            </div>
            <div class="stat-card">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">มูลค่ารวม</p>
                <p class="text-2xl font-bold text-purple-600"><?= number_format($totalValue/1000000, 2) ?>M</p>
                <p class="text-xs text-gray-400">บาท</p>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
            <div class="card p-5">
                <h3 class="font-bold text-gray-900 text-sm mb-4">ทรัพย์สินตามแผนก</h3>
                <div id="chartByDept" class="min-h-[250px]"></div>
            </div>
            <div class="card p-5">
                <h3 class="font-bold text-gray-900 text-sm mb-4">ทรัพย์สินตามประเภท</h3>
                <div id="chartByClass" class="min-h-[250px]"></div>
            </div>
        </div>

        <!-- Monthly Chart -->
        <div class="card p-5 mb-5">
            <h3 class="font-bold text-gray-900 text-sm mb-4">ทรัพย์สินที่ซื้อรายเดือน (ช่วงปี <?= htmlspecialchars($yearLabel) ?>)</h3>
            <div id="chartMonthly" class="min-h-[220px]"></div>
        </div>

        <!-- Table by Department -->
        <div class="card mb-5">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <div>
                    <h3 class="font-bold text-gray-900 text-sm">รายงานตามแผนก</h3>
                    <p class="text-xs text-gray-500 mt-1">ช่วงปี <?= htmlspecialchars($yearLabel) ?><?= $selectedPlant ? ' | Plant: ' . htmlspecialchars($selectedPlant) : '' ?></p>
                </div>
                <?php if ($openAuditSession): ?>
                <span class="text-xs text-gray-500">รอบตรวจนับล่าสุด: <?= htmlspecialchars($openAuditSession['session_name']) ?></span>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="data-table" id="deptTable">
                    <thead>
                        <tr>
                            <th rowspan="2">แผนก</th>
                            <th rowspan="2">จำนวนทรัพย์สิน</th>
                            <th colspan="5">ตรวจนับ</th>
                            <th rowspan="2">มูลค่า</th>
                            <th rowspan="2">% การตรวจนับ</th>
                        </tr>
                        <tr>
                            <th>ใช้งาน</th>
                            <th>ส่งคืน</th>
                            <th>รอซ่อม</th>
                            <th>ไม่พบ</th>
                            <th>ไม่ใช้งาน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byDept as $d): ?>
                        <?php $checked = $checkedByDept[$d['department_code'] ?? ''] ?? 0; ?>
                        <?php $pct = $d['total'] > 0 ? round($checked / $d['total'] * 100, 1) : 0; ?>
                        <tr>
                            <td class="font-medium text-gray-900 text-left"><?= htmlspecialchars($d['department_name'] ?? '(ไม่ระบุ)') ?></td>
                            <td class="text-right font-semibold"><?= number_format($d['total']) ?></td>
                            <td class="text-right text-green-700 font-medium"><?= number_format($d['active_count']) ?></td>
                            <td class="text-right text-blue-700 font-medium"><?= number_format($d['returned_count']) ?></td>
                            <td class="text-right text-amber-600 font-medium"><?= number_format($d['repairing_count']) ?></td>
                            <td class="text-right text-red-600 font-medium"><?= number_format($d['not_found_count']) ?></td>
                            <td class="text-right text-gray-500 font-medium"><?= number_format($d['inactive_count']) ?></td>
                            <td class="text-right">
                                <?= number_format($d['val'], 2) ?>
                            </td>
                            <td class="text-right font-semibold"><?= $openAuditSession ? $pct . '%' : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray-50 font-bold">
                            <td class="px-4 py-3 text-sm">รวมทั้งหมด</td>
                            <td class="px-4 py-3 text-right text-sm"><?= number_format(array_sum(array_column($byDept, 'total'))) ?></td>
                            <td class="px-4 py-3 text-right text-sm"><?= number_format(array_sum(array_column($byDept, 'active_count'))) ?></td>
                            <td class="px-4 py-3 text-right text-sm"><?= number_format(array_sum(array_column($byDept, 'returned_count'))) ?></td>
                            <td class="px-4 py-3 text-right text-sm"><?= number_format(array_sum(array_column($byDept, 'repairing_count'))) ?></td>
                            <td class="px-4 py-3 text-right text-sm"><?= number_format(array_sum(array_column($byDept, 'not_found_count'))) ?></td>
                            <td class="px-4 py-3 text-right text-sm"><?= number_format(array_sum(array_column($byDept, 'inactive_count'))) ?></td>
                            <td class="px-4 py-3 text-right text-sm"><?= number_format(array_sum(array_column($byDept, 'val')), 2) ?></td>
                            <td class="px-4 py-3 text-right text-sm"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Table by Class -->
        <div class="card">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-900 text-sm">รายงานตามประเภท</h3>
            </div>
            <div class="table-wrap">
                <table class="data-table" id="classTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ประเภท</th>
                            <th class="text-right">จำนวน</th>
                            <th class="text-right">มูลค่ารวม (บาท)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byClass as $i => $c): ?>
                        <tr>
                            <td class="text-gray-400 text-xs"><?= $i+1 ?></td>
                            <td class="font-medium"><?= htmlspecialchars($c['class_name'] ?? 'ไม่ระบุ') ?></td>
                            <td class="text-right"><?= number_format($c['cnt']) ?></td>
                            <td class="text-right"><?= number_format($c['val'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($classPages > 1): ?>
            <div class="flex items-center justify-between px-5 py-3 border-t border-gray-100">
                <p class="text-xs text-gray-500"><?= $classOffset + 1 ?>–<?= min($classOffset + $classLimit, $classTotal) ?> / <?= $classTotal ?></p>
                <div class="pagination">
                    <?php if ($classPage > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['cpage' => $classPage - 1])) ?>" class="page-btn">‹</a>
                    <?php endif; ?>
                    <?php for ($p = max(1, $classPage - 2); $p <= min($classPages, $classPage + 2); $p++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['cpage' => $p])) ?>" class="page-btn <?= $p === $classPage ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($classPage < $classPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['cpage' => $classPage + 1])) ?>" class="page-btn">›</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<div id="toast-container"></div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
const byDeptData = <?= json_encode($byDept) ?>;
const byClassData = <?= json_encode($byClass) ?>;
const monthlyData = <?= json_encode($monthly) ?>;

// Chart: By Department
new ApexCharts(document.getElementById('chartByDept'), {
    chart: { type: 'bar', height: 250, toolbar: { show: false }, fontFamily: 'IBM Plex Sans Thai, sans-serif' },
    series: [{ name: 'ทรัพย์สิน', data: byDeptData.map(d => parseInt(d.cnt)) }],
    xaxis: { categories: byDeptData.map(d => d.department_name || 'ไม่ระบุ'), labels: { style: { fontSize: '11px', colors: '#64748b' } } },
    colors: ['#2563eb'],
    plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
    grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
    dataLabels: { enabled: false },
}).render();

// Chart: By Class (Pie)
new ApexCharts(document.getElementById('chartByClass'), {
    chart: { type: 'pie', height: 250, fontFamily: 'IBM Plex Sans Thai, sans-serif' },
    series: byClassData.map(d => parseInt(d.cnt)),
    labels: byClassData.map(d => d.class_name || 'ไม่ระบุ'),
    legend: { position: 'bottom', fontSize: '11px' },
    dataLabels: { formatter: (val) => val.toFixed(1) + '%' },
    stroke: { width: 2 },
    colors: ['#2563eb','#7c3aed','#16a34a','#d97706','#dc2626','#0891b2','#be185d'],
}).render();

// Chart: Monthly
new ApexCharts(document.getElementById('chartMonthly'), {
    chart: { type: 'area', height: 220, toolbar: { show: false }, fontFamily: 'IBM Plex Sans Thai, sans-serif' },
    series: [{ name: 'จำนวนทรัพย์สิน', data: monthlyData.map(m => parseInt(m.cnt)) }],
    xaxis: { categories: monthlyData.map(m => m.month), labels: { style: { fontSize: '11px', colors: '#64748b' } } },
    colors: ['#2563eb'],
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
    stroke: { curve: 'smooth', width: 2 },
    grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
    dataLabels: { enabled: false },
    tooltip: { y: { formatter: v => v + ' รายการ' } },
}).render();

function changeAuditYear(year) {
    const url = new URL(window.location);
    url.searchParams.set('audit_year', year);
    window.location = url.toString();
}

function changeYearSpan(span) {
    const url = new URL(window.location);
    url.searchParams.set('year_span', span);
    window.location = url.toString();
}

function changePlantFilter(plantCode) {
    const url = new URL(window.location);
    if (plantCode === '') {
        url.searchParams.delete('plant');
    } else {
        url.searchParams.set('plant', plantCode);
    }
    window.location = url.toString();
}
</script>

</body>
</html>