<?php
$pageTitle = 'รายการทรัพย์สิน';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userSiteCode = $user['site_code'] ?? null;

// Filters
$search  = trim($_GET['search'] ?? '');
$plant   = trim($_GET['plant'] ?? '');
$site    = trim($_GET['site'] ?? ($userSiteCode ?? ''));
$costCenter = trim($_GET['cost_center'] ?? '');
$dept    = trim($_GET['dept'] ?? '');
$status  = trim($_GET['status'] ?? '');
$municipality = trim($_GET['municipality'] ?? ''); // Keep separate for backward compatibility
$page    = max(1, intval($_GET['page'] ?? 1));
$limit   = in_array($_GET['limit'] ?? 0, [10, 20, 50, 100]) ? intval($_GET['limit']) : ITEMS_PER_PAGE;
$offset  = ($page - 1) * $limit;

// Status mapping for Excel imports: ensures imported data maps to system values
$status_map = [
    'ใช้งาน' => 'active',
    'ไม่ใช้งาน' => 'inactive',
    'ชำรุด-ส่งคืน' => 'returned',
    'ชำรุด-รอซ่อม' => 'repairing',
    'ไม่พบ' => 'not_found',
    'จำหน่าย' => 'disposed',
    'ยกเลิก' => 'cancelled',
    'โอนย้าย' => 'transferred'
];

// Build WHERE
$where   = ['1=1'];
$params  = [];

if ($search !== '') {
    $where[] = '(a.asset_no LIKE ? OR a.asset_description LIKE ? OR a.serial_no LIKE ? OR a.municipality LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($plant !== '') { $where[] = 'a.plant_code = ?'; $params[] = $plant; }
if ($site !== '')  { $where[] = 'a.site_code = ?';  $params[] = $site; }
if ($costCenter !== '') { $where[] = 'a.cost_center = ?'; $params[] = $costCenter; }
if ($dept  !== '') { $where[] = 'a.department_code = ?'; $params[] = $dept; }
if ($status !== '') { $where[] = 'a.status = ?'; $params[] = $status; }
if ($municipality !== '') { $where[] = 'a.municipality LIKE ?'; $params[] = "%$municipality%"; } // Also keep separate municipality filter if needed

$whereStr = implode(' AND ', $where);

$total = $db->fetchOne("SELECT COUNT(*) as c FROM assets a WHERE $whereStr", $params)['c'];
$assets = $db->fetchAll("SELECT a.* FROM assets a WHERE $whereStr ORDER BY a.id DESC LIMIT $limit OFFSET $offset", $params);
$totalPages = max(1, ceil($total / $limit));

// Dropdowns - Load based on cascading filters
$plants = $db->fetchAll("SELECT * FROM plants WHERE is_active=1" . (!empty($site) ? " AND site_id = (SELECT id FROM sites WHERE site_code = '" . addslashes($site) . "' LIMIT 1)" : "") . " ORDER BY plant_code");

// Get cost centers based on selected plant
$costCenters = [];
// Cost centers filtered by plant and site
$ccParams = [];
$ccWhere = "WHERE cost_center IS NOT NULL AND cost_center != ''";
if ($plant !== '') { $ccWhere .= " AND plant_code = ?"; $ccParams[] = $plant; }
if ($site !== '')  { $ccWhere .= " AND site_code = ?"; $ccParams[] = $site; }
$costCenters = $db->fetchAll("SELECT DISTINCT cost_center FROM assets $ccWhere ORDER BY cost_center", $ccParams);

// Get departments based on selected plant and cost center
$depts = [];
// Departments filtered by plant, cost center and site
$dParams = [];
$dWhere = "WHERE department_name IS NOT NULL";
if ($plant !== '' && $costCenter !== '') { $dWhere .= " AND plant_code = ? AND cost_center = ?"; $dParams = [$plant, $costCenter]; }
elseif ($plant !== '') { $dWhere .= " AND plant_code = ?"; $dParams = [$plant]; }
if ($site !== '') { $dWhere .= " AND site_code = ?"; $dParams[] = $site; }
$depts = $db->fetchAll("SELECT DISTINCT department_code, department_name FROM assets $dWhere ORDER BY department_name", $dParams);
?>
<?php include __DIR__ . '/../components/head.php'; ?>

<div class="min-h-screen">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>

    <main class="main-content flex-1 p-4 sm:p-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
            <div>
                <h1 class="text-xl font-bold text-gray-900">รายการทรัพย์สิน</h1>
                <p class="text-sm text-gray-500 mt-0.5">พบ <?= number_format($total) ?> รายการ</p>
            </div>
            <?php if (hasRole(['admin'])): ?>
            <div class="flex gap-2">
                <a href="asset-import.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-file-import"></i> นำเข้า Excel
                </a>
                <a href="asset-detail.php?new=1" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> เพิ่มทรัพย์สิน
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Search & Filter -->
        <div class="card p-4 mb-4">
            <form method="GET" action="asset-list.php" id="filterForm">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                    <div class="lg:col-span-2">
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                            placeholder="ค้นหา Asset No, คำอธิบาย, Serial No, ผู้ดูแล..."
                            class="form-input">
                    </div>
                    <select name="plant" id="plantSelect" class="form-input">
                        <option value="">-- ทุก Plant --</option>
                        <?php foreach ($plants as $p): ?>
                        <option value="<?= $p['plant_code'] ?>" <?= $plant === $p['plant_code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['plant_code'] . ' - ' . $p['plant_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="cost_center" id="costCenterSelect" class="form-input">
                        <option value="">-- ทุก Cost Center --</option>
                        <?php foreach ($costCenters as $cc): ?>
                        <option value="<?= $cc['cost_center'] ?>" <?= $costCenter === $cc['cost_center'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cc['cost_center']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="dept" id="deptSelect" class="form-input">
                        <option value="">-- ทุกแผนก --</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['department_code'] ?>" <?= $dept === $d['department_code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['department_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="form-input">
                        <option value="">-- ทุกสถานะ --</option>
                        <option value="active"      <?= $status === 'active'      ? 'selected' : '' ?>>ใช้งาน</option>
                        <option value="returned"    <?= $status === 'returned'    ? 'selected' : '' ?>>ชำรุด-ส่งคืน</option>
                        <option value="not_found"   <?= $status === 'not_found'   ? 'selected' : '' ?>>ไม่พบ</option>
                        <option value="inactive"    <?= $status === 'inactive'    ? 'selected' : '' ?>>ไม่ใช้งาน</option>
                        <option value="repairing"   <?= $status === 'repairing'   ? 'selected' : '' ?>>ชำรุด-รอซ่อม</option>
                    </select>
                </div>
                <!-- Action Row: Search controls + Export -->
                <div class="flex flex-wrap items-center gap-2 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-search"></i> ค้นหา
                    </button>
                    <a href="asset-list.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-times"></i> ล้างตัวกรอง
                    </a>
                    <div class="flex items-center gap-1 ml-1">
                        <label class="text-xs text-gray-500 whitespace-nowrap">แสดง</label>
                        <select name="limit" onchange="this.form.submit()" class="form-input w-auto text-sm py-1">
                            <option value="10"  <?= $limit === 10  ? 'selected' : '' ?>>10</option>
                            <option value="20"  <?= $limit === 20  ? 'selected' : '' ?>>20</option>
                            <option value="50"  <?= $limit === 50  ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                        </select>
                        <label class="text-xs text-gray-500 whitespace-nowrap">รายการ/หน้า</label>
                    </div>
                    <button type="button" onclick="exportAllData()" class="btn btn-sm ml-auto"
                        style="background:#16a34a;color:#fff;border:none;"
                        onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
                        <i class="fas fa-file-csv"></i> Export CSV
                        <?php if ($search || $plant || $costCenter || $dept || $status || $municipality): ?>
                        <span class="ml-1 text-xs opacity-75">(ที่กรองแล้ว)</span>
                        <?php endif; ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="card">
            <div class="table-wrap">
                <table class="data-table" id="assetTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Plant</th>
                            <th>Asset No</th>
                            <th>รายละเอียด</th>
                            <th>แผนก</th>
                            <th>ผู้ดูแล</th>
                            <th>มูลค่าซื้อ</th>
                            <th>สถานะ</th>
                            <th class="no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assets)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-12 text-gray-400">
                                <i class="fas fa-inbox text-3xl mb-3 block opacity-30"></i>
                                ไม่พบข้อมูลทรัพย์สิน
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($assets as $i => $a): ?>
                        <tr>
                            <td class="text-gray-400 text-xs"><?= $offset + $i + 1 ?></td>
                            <td><span class="text-xs font-mono bg-gray-100 px-2 py-0.5 rounded"><?= htmlspecialchars($a['plant_code'] ?? '-') ?></span></td>
                            <td>
                                <a href="asset-detail.php?id=<?= $a['id'] ?>" class="font-mono text-xs font-semibold text-blue-700 hover:underline">
                                    <?= htmlspecialchars($a['asset_no']) ?>
                                </a>
                            </td>
                            <td>
                                <p class="text-sm font-medium text-gray-900 max-w-[200px] truncate" title="<?= htmlspecialchars($a['asset_description'] ?? '') ?>">
                                    <?= htmlspecialchars($a['asset_description'] ?? '-') ?>
                                </p>
                                <?php if ($a['brand']): ?>
                                <p class="text-xs text-gray-400"><?= htmlspecialchars($a['brand']) ?><?= $a['model'] ? ' · ' . htmlspecialchars($a['model']) : '' ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs text-gray-500"><?= htmlspecialchars($a['department_name'] ?? '-') ?></td>
                            <td class="text-xs text-gray-500"><?= htmlspecialchars($a['municipality'] ?? '-') ?></td>
                    <td class="text-sm font-medium text-gray-700 text-right"><?= number_format($a['acquis_val'], 2) ?></td>
                            <td>
                                <?php
                                $smap   = ['active'=>'badge-active','cancelled'=>'badge-cancelled','disposed'=>'badge-disposed','transferred'=>'badge-transfer'];
                                $slabel = ['active'=>'ใช้งาน','cancelled'=>'ยกเลิก','disposed'=>'จำหน่าย','transferred'=>'โอนย้าย'];
                                ?>
                                <?php
                                $smap   = [
                                    'active'=>'badge-active',
                                    'returned'=>'badge-cancelled',
                                    'not_found'=>'badge-disposed',
                                    'inactive'=>'badge-cancelled',
                                    'repairing'=>'badge-warning',

                                ];
                                $slabel = [
                                    'active'=>'ใช้งาน',
                                    'returned'=>'ชำรุด-ส่งคืน',
                                    'not_found'=>'ไม่พบ',
                                    'inactive'=>'ไม่ใช้งาน',
                                    'repairing'=>'ชำรุด-รอซ่อม',

                                ];
                                ?>
                                <span class="badge <?= $smap[$a['status']] ?? 'badge-active' ?>"><?= $slabel[$a['status']] ?? $a['status'] ?></span>
                            </td>
                            <td class="no-print">
                                <div class="flex items-center gap-1">
                                    <a href="asset-detail.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-secondary" title="ดูรายละเอียด">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (hasRole(['admin'])): ?>
                                    <a href="asset-detail.php?id=<?= $a['id'] ?>&edit=1" class="btn btn-xs btn-primary" title="แก้ไข">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <button onclick="deleteAsset(<?= $a['id'] ?>, '<?= htmlspecialchars($a['asset_no']) ?>')" class="btn btn-xs btn-danger" title="ลบ">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination: always show info row, hide page buttons when only 1 page -->
            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-5 py-4 border-t border-gray-100">
                <p class="text-xs text-gray-500">
                    <?php if ($total === 0): ?>
                        ไม่พบรายการ
                    <?php else: ?>
                        แสดง <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $total)) ?> จาก <?= number_format($total) ?> รายการ
                    <?php endif; ?>
                </p>
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-btn">‹</a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end   = min($totalPages, $page + 2);
                    for ($p = $start; $p <= $end; $p++):
                    ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-btn">›</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<div id="toast-container"></div>

<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
// Cascading filter functionality
document.addEventListener('DOMContentLoaded', function() {
    const plantSelect = document.getElementById('plantSelect');
    const costCenterSelect = document.getElementById('costCenterSelect');
    const deptSelect = document.getElementById('deptSelect');
    const form = document.getElementById('filterForm');

    // Function to update cost centers based on selected plant
    function updateCostCenters() {
        const selectedPlant = plantSelect.value;

        // Reset cost center and department selects when plant changes
        if (selectedPlant !== plantSelect.getAttribute('data-prev-value')) {
            costCenterSelect.innerHTML = '<option value="">-- กำลังโหลด --</option>';
            deptSelect.innerHTML = '<option value="">-- ทุกแผนก --</option>';

            if (selectedPlant) {
                // Fetch cost centers for the selected plant
                fetch(`<?= APP_URL ?>/api/assets.php?action=get_cost_centers&plant_code=${selectedPlant}`)
                    .then(response => response.json())
                    .then(data => {
                        costCenterSelect.innerHTML = '<option value="">-- ทุก Cost Center --</option>';
                        if (data.success && data.data) {
                            data.data.forEach(cc => {
                                const option = document.createElement('option');
                                option.value = cc.cost_center;
                                option.textContent = cc.cost_center;
                                costCenterSelect.appendChild(option);
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching cost centers:', error);
                        costCenterSelect.innerHTML = '<option value="">-- ทุก Cost Center --</option>';
                    });
            } else {
                // If no plant selected, load all cost centers
                fetch(`<?= APP_URL ?>/api/assets.php?action=get_cost_centers`)
                    .then(response => response.json())
                    .then(data => {
                        costCenterSelect.innerHTML = '<option value="">-- ทุก Cost Center --</option>';
                        if (data.success && data.data) {
                            data.data.forEach(cc => {
                                const option = document.createElement('option');
                                option.value = cc.cost_center;
                                option.textContent = cc.cost_center;
                                costCenterSelect.appendChild(option);
                            });
                        }
                    });
            }
        }

        plantSelect.setAttribute('data-prev-value', selectedPlant);
    }

    // Function to update departments based on selected plant and cost center
    function updateDepartments() {
        const selectedPlant = plantSelect.value;
        const selectedCostCenter = costCenterSelect.value;

        // Reset department select when cost center changes
        deptSelect.innerHTML = '<option value="">-- กำลังโหลด --</option>';

        let url = `<?= APP_URL ?>/api/assets.php?action=get_departments`;
        if (selectedPlant && selectedCostCenter) {
            url += `&plant_code=${selectedPlant}&cost_center=${selectedCostCenter}`;
        } else if (selectedPlant) {
            url += `&plant_code=${selectedPlant}`;
        }

        fetch(url)
            .then(response => response.json())
            .then(data => {
                deptSelect.innerHTML = '<option value="">-- ทุกแผนก --</option>';
                if (data.success && data.data) {
                    data.data.forEach(dept => {
                        const option = document.createElement('option');
                        option.value = dept.department_code;
                        option.textContent = dept.department_name;
                        deptSelect.appendChild(option);
                    });
                }
            })
            .catch(error => {
                console.error('Error fetching departments:', error);
                deptSelect.innerHTML = '<option value="">-- ทุกแผนก --</option>';
            });
    }

    // Event listeners for cascading filters
    plantSelect.addEventListener('change', function() {
        updateCostCenters();

        // Reset department selection when plant changes
        deptSelect.innerHTML = '<option value="">-- ทุกแผนก --</option>';
    });

    costCenterSelect.addEventListener('change', function() {
        updateDepartments();
    });

    // Initialize the previous value for plant
    plantSelect.setAttribute('data-prev-value', plantSelect.value);
});
</script>

<script>
// Export all data function
function exportAllData() {
    // Create a form to submit the full data request
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= APP_URL ?>/api/export.php';
    form.style.display = 'none';

    // Add filters as hidden inputs
    const params = new URLSearchParams(window.location.search);
    for(const [key, value] of params.entries()){
        if(key !== 'page' && key !== 'limit'){ // Exclude pagination params
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        }
    }

    // Add export flag
    const exportInput = document.createElement('input');
    exportInput.type = 'hidden';
    exportInput.name = 'export_all';
    exportInput.value = '1';
    form.appendChild(exportInput);

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
</script>
</body>
</html>