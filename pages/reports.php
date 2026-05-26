<?php
$pageTitle = 'รายงาน';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();

$db = Database::getInstance();

// Summary data
$totalAssets   = $db->fetchOne("SELECT COUNT(*) as c FROM assets")['c'];
$activeAssets  = $db->fetchOne("SELECT COUNT(*) as c FROM assets WHERE status='active'")['c'];
$cancelled     = $db->fetchOne("SELECT COUNT(*) as c FROM assets WHERE status='cancelled'")['c'];
$totalValue    = $db->fetchOne("SELECT IFNULL(SUM(acquis_val),0) as v FROM assets WHERE status='active'")['v'];

// By Department
$deptPage   = max(1, intval($_GET['dpage'] ?? 1));
$deptLimit  = 10;
$deptOffset = ($deptPage - 1) * $deptLimit;
$deptTotal  = $db->fetchOne("SELECT COUNT(DISTINCT department_code) as c FROM assets WHERE status='active' AND department_name IS NOT NULL")['c'];
$deptPages  = max(1, ceil($deptTotal / $deptLimit));
$byDept = $db->fetchAll("SELECT department_code, department_name, COUNT(*) as cnt, SUM(acquis_val) as val FROM assets WHERE status='active' GROUP BY department_code, department_name ORDER BY cnt DESC LIMIT $deptLimit OFFSET $deptOffset");

// By Class
$classPage   = max(1, intval($_GET['cpage'] ?? 1));
$classLimit  = 10;
$classOffset = ($classPage - 1) * $classLimit;
$classTotal  = $db->fetchOne("SELECT COUNT(DISTINCT a.class_code) as c FROM assets a WHERE a.status='active'")['c'];
$classPages  = max(1, ceil($classTotal / $classLimit));
$byClass = $db->fetchAll("SELECT c.class_code, c.class_name, COUNT(a.id) as cnt, IFNULL(SUM(a.acquis_val),0) as val FROM assets a LEFT JOIN asset_classes c ON a.class_code=c.class_code WHERE a.status='active' GROUP BY c.class_code, c.class_name ORDER BY cnt DESC LIMIT $classLimit OFFSET $classOffset");

// By Plant
$byPlant = $db->fetchAll("SELECT a.plant_code, p.plant_name, COUNT(a.id) as cnt, IFNULL(SUM(a.acquis_val),0) as val FROM assets a LEFT JOIN plants p ON a.plant_code=p.plant_code GROUP BY a.plant_code, p.plant_name ORDER BY cnt DESC");

// Monthly acquisition (last 12 months)
$monthly = $db->fetchAll("
    SELECT DATE_FORMAT(cap_date,'%Y-%m') as month, COUNT(*) as cnt, SUM(acquis_val) as val
    FROM assets WHERE cap_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH) AND cap_date IS NOT NULL
    GROUP BY month ORDER BY month ASC
");
?>
<?php include __DIR__ . '/../components/head.php'; ?>

<div class="min-h-screen">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>

    <main class="main-content flex-1 p-4 sm:p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h1 class="text-xl font-bold text-gray-900">รายงาน & Export</h1>
                <p class="text-sm text-gray-500 mt-0.5">สรุปข้อมูลทรัพย์สินและการตรวจนับ</p>
            </div>
            <div class="flex gap-2">
                <a href="<?= APP_URL ?>/api/export.php?type=assets&format=csv" class="btn btn-secondary btn-sm">
                    <i class="fas fa-file-csv text-green-600"></i> Export CSV
                </a>
                <button onclick="printPage()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-print text-blue-600"></i> พิมพ์
                </button>
            </div>
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
            <h3 class="font-bold text-gray-900 text-sm mb-4">ทรัพย์สินที่ซื้อรายเดือน (12 เดือนล่าสุด)</h3>
            <div id="chartMonthly" class="min-h-[220px]"></div>
        </div>

        <!-- Table by Department -->
        <div class="card mb-5">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-900 text-sm">รายงานตามแผนก</h3>
            </div>
            <div class="table-wrap">
                <table class="data-table" id="deptTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>แผนก</th>
                            <th class="text-right">จำนวน (รายการ)</th>
                            <th class="text-right">มูลค่ารวม (บาท)</th>
                            <th class="text-right">สัดส่วน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byDept as $i => $d): ?>
                        <tr>
                            <td class="text-gray-400 text-xs"><?= $i+1 ?></td>
                            <td class="font-medium text-gray-900"><?= htmlspecialchars($d['department_name'] ?? '-') ?></td>
                            <td class="text-right font-medium"><?= number_format($d['cnt']) ?></td>
                            <td class="text-right"><?= number_format($d['val'], 2) ?></td>
                            <td class="text-right">
                                <?php $pct = $activeAssets > 0 ? round($d['cnt']/$activeAssets*100, 1) : 0; ?>
                                <div class="flex items-center justify-end gap-2">
                                    <div class="w-16 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-1.5 bg-blue-500 rounded-full" style="width:<?= $pct ?>%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500 w-8 text-right"><?= $pct ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray-50 font-bold">
                            <td colspan="2" class="px-4 py-3 text-sm">รวมทั้งหมด</td>
                            <td class="px-4 py-3 text-right text-sm"><?= number_format($activeAssets) ?></td>
                            <td class="px-4 py-3 text-right text-sm"><?= number_format($totalValue, 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php if ($deptPages > 1): ?>
            <div class="flex items-center justify-between px-5 py-3 border-t border-gray-100">
                <p class="text-xs text-gray-500"><?= $deptOffset + 1 ?>–<?= min($deptOffset + $deptLimit, $deptTotal) ?> / <?= $deptTotal ?></p>
                <div class="pagination">
                    <?php if ($deptPage > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['dpage' => $deptPage - 1])) ?>" class="page-btn">‹</a>
                    <?php endif; ?>
                    <?php for ($p = max(1, $deptPage - 2); $p <= min($deptPages, $deptPage + 2); $p++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['dpage' => $p])) ?>" class="page-btn <?= $p === $deptPage ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($deptPage < $deptPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['dpage' => $deptPage + 1])) ?>" class="page-btn">›</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
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
</script>

</body>
</html>