<?php
$pageTitle = 'รายละเอียดทรัพย์สิน';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();
$db   = Database::getInstance();
$user = currentUser();
$id   = intval($_GET['id'] ?? 0);
$isNew  = isset($_GET['new']);
$isEdit = isset($_GET['edit']) || $isNew;
$isAdmin = hasRole(['admin', 'webadmin']);
$asset = [];
if (!$isNew && $id > 0) {
    $asset = $db->fetchOne("SELECT * FROM assets WHERE id = ?", [$id]);
    if (!$asset) {
        header('Location: asset-list.php');
        exit;
    }
    $pageTitle = 'ทรัพย์สิน: ' . $asset['asset_no'];
}
// Dropdowns
$plants  = $db->fetchAll("SELECT distinct plant_code,plant_name FROM plants WHERE is_active=1 ORDER BY plant_code");
$depts   = $db->fetchAll("SELECT distinct dept_code,dept_name FROM departments WHERE is_active=1 ORDER BY dept_name");
$classes = $db->fetchAll("SELECT distinct class_code,class_name FROM asset_classes ORDER BY class_name");
// Statuses
$statuses = [
    'active' => 'ใช้งาน',
    'returned' => 'ชำรุด-ส่งคืน',
    'not_found' => 'ไม่พบ',
    'inactive' => 'ไม่ใช้งาน',
    'repairing' => 'ชำรุด-รอซ่อม',
];
// Images & inventory history (only for existing asset)
$assetImages   = [];
$invHistory    = [];
$qrcodeImages  = []; // New: QR code images
if (!$isNew && $id > 0) {
    $assetImages = $db->fetchAll("SELECT * FROM asset_images WHERE asset_id = ? AND is_primary != 2 ORDER BY id DESC", [$id]); // Exclude QR code images and sort by newest first
    $qrcodeImages = $db->fetchAll("SELECT * FROM asset_images WHERE asset_id = ? AND is_primary = 2 ORDER BY id ASC", [$id]); // is_primary = 2 for QR code images
    $invHistory  = $db->fetchAll(
        "(SELECT ir.id as source_id, ir.check_status, ir.remarks, ir.photo_path, ir.quantity, ir.checked_at,
                 s.session_name, u.full_name as checked_by_name, 'inventory' as source_type
          FROM inventory_results ir
          JOIN inventory_sessions s ON s.id = ir.session_id
          LEFT JOIN users u ON u.id = ir.checked_by
          WHERE ir.asset_id = ?
            AND ir.checked_at >= DATE_SUB(CURDATE(), INTERVAL 3 YEAR))
         UNION ALL
         (SELECT aa.id as source_id,
                 'completed' as check_status,
                 aa.remark as remarks,
                 NULL as photo_path,
                 NULL as quantity,
                 aa.checked_at,
                 s.session_name,
                 u.full_name as checked_by_name,
                 'audit' as source_type
          FROM audit_assignments aa
          JOIN audit_sessions s ON s.id = aa.session_id
          LEFT JOIN users u ON u.id = aa.user_id
          WHERE aa.asset_id = ?
            AND aa.status = 'completed'
            AND aa.checked_at >= DATE_SUB(CURDATE(), INTERVAL 3 YEAR))
         ORDER BY checked_at DESC",
        [$id, $id]
    );
    foreach ($invHistory as &$historyRow) {
        if (($historyRow['source_type'] ?? '') !== 'audit' || empty($historyRow['remarks'])) {
            continue;
        }
        $remarkData = json_decode($historyRow['remarks'], true);
        if (!is_array($remarkData)) {
            continue;
        }
        $historyRow['check_status'] = $remarkData['check_result'] ?? $historyRow['check_status'];
        $historyRow['quantity'] = $remarkData['quantity'] ?? $historyRow['quantity'];
        $historyRow['remarks'] = $remarkData['notes'] ?? '';
    }
    unset($historyRow);
    foreach ($invHistory as &$historyRow) {
        $historyRow['audit_images'] = [];
        if (($historyRow['source_type'] ?? '') !== 'audit' || empty($historyRow['source_id'])) {
            continue;
        }
        $auditDir = __DIR__ . '/../uploads/audit/' . (int)$historyRow['source_id'] . '/';
        if (!is_dir($auditDir)) {
            continue;
        }
        $imageFiles = [];
        foreach (['asset_*.jpg' => 'รูปทรัพย์', 'qr_code_*.jpg' => 'QR'] as $pattern => $label) {
            foreach (glob($auditDir . $pattern) ?: [] as $imagePath) {
                $imageFiles[] = [
                    'url' => APP_URL . '/uploads/audit/' . (int)$historyRow['source_id'] . '/' . rawurlencode(basename($imagePath)),
                    'label' => $label
                ];
            }
        }
        $historyRow['audit_images'] = array_slice($imageFiles, 0, 3);
    }
    unset($historyRow);
}
// ── ภาพและข้อมูลล่าสุดจากการตรวจนับ ──────────────────────
$latestAuditFull   = [];
$latestAuditImages = [];
$latestHistoryRow  = null;
if (!$isNew && !empty($invHistory)) {
    $latestHistoryRow  = $invHistory[0]; // sorted DESC checked_at
    $latestAuditImages = $latestHistoryRow['audit_images'] ?? [];
    if (($latestHistoryRow['source_type'] ?? '') === 'audit' && !empty($latestHistoryRow['source_id'])) {
        // ดึง remark JSON ดิบจาก audit_assignments (loop ข้างบนเขียนทับแล้ว)
        $raw = $db->fetchOne("SELECT remark FROM audit_assignments WHERE id = ?", [(int)$latestHistoryRow['source_id']]);
        if ($raw && !empty($raw['remark'])) {
            $latestAuditFull = json_decode($raw['remark'], true) ?: [];
        }
    } else {
        // inventory_results: สร้าง array สำหรับแสดงข้อมูล
        $latestAuditFull = [
            'check_result'       => $latestHistoryRow['check_status'] ?? '',
            'quantity'           => $latestHistoryRow['quantity']     ?? '',
            'notes'              => $latestHistoryRow['remarks']      ?? '',
            'location'           => $asset['location']    ?? '',
            'responsible_person' => $asset['municipality'] ?? '',
        ];
    }
}
// ภาพที่จะแสดง: audit images → asset uploaded images
$displayImages = [];
if (!empty($latestAuditImages)) {
    $displayImages = $latestAuditImages; // [{url, label}]
} elseif (!empty($assetImages)) {
    $displayImages = array_map(function ($img) {
        return [
            'url'   => APP_URL . '/assets/uploads/' . rawurlencode($img['filename']),
            'label' => ($img['is_primary'] == 1) ? '★ รูปหลัก' : 'รูปประกอบ',
            'id'    => $img['id'],
        ];
    }, $assetImages);
}
?>
<?php include __DIR__ . '/../components/head.php'; ?>
<div class="min-h-screen" x-data="assetDetail()">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>
    <main class="main-content flex-1 p-4 sm:p-6">
        <!-- Breadcrumb -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5">
            <nav class="flex items-center gap-2 text-sm text-gray-500">
                <a href="asset-list.php" class="hover:text-blue-600 transition-colors">รายการทรัพย์สิน</a>
                <i class="fas fa-chevron-right text-xs"></i>
                <span class="text-gray-900 font-medium"><?= $isNew ? 'เพิ่มทรัพย์สินใหม่' : htmlspecialchars($asset['asset_no'] ?? '') ?></span>
            </nav>
            <?php if (!$isNew): ?>
                <div class="flex items-center gap-2 no-print">
                    <button onclick="printAsset(<?= $id ?>)"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 border border-transparent rounded-lg text-sm font-medium text-white hover:bg-blue-700 transition-all shadow-sm">
                        <i class="fas fa-print"></i>
                        <span>พิมพ์เอกสาร</span>
                    </button>
                </div>
                <iframe id="printFrame" style="display:none;"></iframe>
            <?php endif; ?>
        </div>
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
            <!-- Main Form -->
            <div class="xl:col-span-2">
                <div class="card">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                        <h2 class="font-bold text-gray-900 text-sm">
                            <?= $isNew ? 'เพิ่มทรัพย์สินใหม่' : ($isEdit ? 'แก้ไขทรัพย์สิน' : 'ข้อมูลทรัพย์สิน') ?>
                        </h2>
                        <?php if (!$isNew && !$isEdit && $isAdmin): ?>
                            <div class="flex gap-2">
                                <!-- <a href="#" class="btn btn-secondary btn-sm no-print" onclick="printPage()">
                                    <i class="fas fa-print"></i> พิมพ์
                                </a> -->
                                <a href="?id=<?= $id ?>&edit=1" class="btn btn-primary btn-sm">
                                    <i class="fas fa-pen"></i> แก้ไข
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <form id="assetForm" class="p-5" @submit.prevent="saveAsset">
                        <input type="hidden" name="action" value="<?= $isNew ? 'create' : 'update' ?>">
                        <input type="hidden" name="id" value="<?= $asset['id'] ?? 0 ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Plant -->
                            <div>
                                <label class="form-label">Plant <span class="text-red-500">*</span></label>
                                <select name="plant_code" class="form-input" <?= (!$isEdit) ? 'disabled' : '' ?> required>
                                    <option value="">-- เลือก Plant --</option>
                                    <?php foreach ($plants as $p): ?>
                                        <option value="<?= $p['plant_code'] ?>" <?= ($asset['plant_code'] ?? '') === $p['plant_code'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['plant_code'] . ' - ' . $p['plant_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Asset No -->
                            <div>
                                <label class="form-label">Asset No. <span class="text-red-500">*</span></label>
                                <input type="text" name="asset_no" value="<?= htmlspecialchars($asset['asset_no'] ?? '') ?>"
                                    placeholder="เช่น AAMF000001" class="form-input font-mono"
                                    <?= (!$isEdit) ? 'readonly' : '' ?> required>
                            </div>
                            <!-- Class -->
                            <div>
                                <label class="form-label">ประเภททรัพย์สิน</label>
                                <select name="class_code" class="form-input" <?= (!$isEdit) ? 'disabled' : '' ?>>
                                    <option value="">-- เลือกประเภท --</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?= $c['class_code'] ?>" <?= ($asset['class_code'] ?? '') === $c['class_code'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['class_code'] . ' - ' . $c['class_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Cap Date -->
                            <div>
                                <label class="form-label">วันที่ซื้อ (Cap Date)</label>
                                <input type="date" name="cap_date" value="<?= $asset['cap_date'] ?? '' ?>"
                                    class="form-input" <?= (!$isEdit) ? 'readonly' : '' ?>>
                            </div>
                            <!-- Description -->
                            <div class="sm:col-span-2">
                                <label class="form-label">รายละเอียดทรัพย์สิน <span class="text-red-500">*</span></label>
                                <textarea name="asset_description" rows="2" placeholder="คำอธิบายทรัพย์สิน"
                                    class="form-input" <?= (!$isEdit) ? 'readonly' : '' ?> required><?= htmlspecialchars($asset['asset_description'] ?? '') ?></textarea>
                            </div>
                            <!-- Brand / Model -->
                            <div>
                                <label class="form-label">ยี่ห้อ</label>
                                <input type="text" name="brand" value="<?= htmlspecialchars($asset['brand'] ?? '') ?>"
                                    placeholder="Brand" class="form-input" <?= (!$isEdit) ? 'readonly' : '' ?>>
                            </div>
                            <div>
                                <label class="form-label">รุ่น (Model)</label>
                                <input type="text" name="model" value="<?= htmlspecialchars($asset['model'] ?? '') ?>"
                                    placeholder="Model" class="form-input" <?= (!$isEdit) ? 'readonly' : '' ?>>
                            </div>
                            <!-- Serial No -->
                            <div>
                                <label class="form-label">Serial No.</label>
                                <input type="text" name="serial_no" value="<?= htmlspecialchars($asset['serial_no'] ?? '') ?>"
                                    placeholder="Serial Number" class="form-input font-mono" <?= (!$isEdit) ? 'readonly' : '' ?>>
                            </div>
                            <!-- Acquis Val -->
                            <div>
                                <label class="form-label">มูลค่าซื้อ (บาท)</label>
                                <input type="number" name="acquis_val" value="<?= $asset['acquis_val'] ?? 0 ?>"
                                    min="0" step="0.01" class="form-input" <?= (!$isEdit) ? 'readonly' : '' ?>>
                            </div>
                            <!-- Cost Center -->
                            <div>
                                <label class="form-label">Cost Center</label>
                                <input type="text" name="cost_center" value="<?= htmlspecialchars($asset['cost_center'] ?? '') ?>"
                                    placeholder="Cost Center" class="form-input" <?= (!$isEdit) ? 'readonly' : '' ?>>
                            </div>
                            <!-- Department -->
                            <div>
                                <label class="form-label">แผนก</label>
                                <select name="department_code" class="form-input" <?= (!$isEdit) ? 'disabled' : '' ?>
                                    @change="updateDeptName">
                                    <option value="">-- เลือกแผนก --</option>
                                    <?php foreach ($depts as $d): ?>
                                        <option value="<?= $d['dept_code'] ?>"
                                            data-name="<?= htmlspecialchars($d['dept_name']) ?>"
                                            <?= ($asset['department_code'] ?? '') === $d['dept_code'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['dept_code'] . ' - ' . $d['dept_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="department_name" id="dept_name_hidden" value="<?= htmlspecialchars($asset['department_name'] ?? '') ?>">
                            </div>
                            <!-- Municipality -->
                            <div>
                                <label class="form-label">ผู้ดูแล (Municipality)</label>
                                <input type="text" name="municipality" value="<?= htmlspecialchars($asset['municipality'] ?? '') ?>"
                                    placeholder="ชื่อผู้ดูแลทรัพย์สิน" class="form-input" <?= (!$isEdit) ? 'readonly' : '' ?>>
                            </div>
                            <!-- Location -->
                            <div class="sm:col-span-2">
                                <label class="form-label">ที่ตั้ง / ตำแหน่ง</label>
                                <input type="text" name="location" value="<?= htmlspecialchars($asset['location'] ?? '') ?>"
                                    placeholder="ระบุที่ตั้งของทรัพย์สิน" class="form-input" <?= (!$isEdit) ? 'readonly' : '' ?>>
                            </div>
                            <!-- Status -->
                            <div>
                                <label class="form-label">สถานะ <span class="text-red-500">*</span></label>
                                <select name="status" class="form-input" <?= (!$isEdit) ? 'disabled' : '' ?> required>
                                    <?php foreach ($statuses as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= ($asset['status'] ?? 'active') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Remark -->
                            <div>
                                <label class="form-label">หมายเหตุ</label>
                                <input type="text" name="remark" value="<?= htmlspecialchars($asset['remark'] ?? '') ?>"
                                    placeholder="หมายเหตุเพิ่มเติม" class="form-input" <?= (!$isEdit) ? 'readonly' : '' ?>>
                            </div>
                            <?php if (!$isNew): ?>
                                <!-- ═══ ภาพประกอบ + ข้อมูลตรวจนับล่าสุด ═══ -->
                                <div class="sm:col-span-2 pt-4 border-t border-gray-100">
                                    <!-- Header row -->
                                    <div class="flex items-center justify-between mb-3">
                                        <h4 class="text-sm font-bold text-gray-700 flex items-center gap-2">
                                            <i class="fas fa-camera-retro text-blue-500"></i>
                                            ภาพประกอบและข้อมูลตรวจนับล่าสุด
                                        </h4>
                                        <?php if ($latestHistoryRow): ?>
                                            <span class="inline-flex items-center gap-1 text-[10px] text-gray-400 bg-gray-50 border border-gray-200 rounded-full px-2.5 py-0.5">
                                                <i class="fas fa-history"></i>
                                                <?= htmlspecialchars($latestHistoryRow['session_name'] ?? '') ?>
                                                <?php if ($latestHistoryRow['checked_at']): ?>
                                                    · <?= date('d/m/Y', strtotime($latestHistoryRow['checked_at'])) ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[10px] text-gray-400">ข้อมูลจากระบบ (ยังไม่มีการตรวจนับ)</span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Latest audit mini-info band -->
                                    <?php if (!empty($latestAuditFull) || !empty($latestHistoryRow)): ?>
                                        <?php
                                        $auditSLabel = ['active' => 'ใช้งาน', 'returned' => 'ชำรุด-ส่งคืน', 'not_found' => 'ไม่พบ', 'inactive' => 'ไม่ใช้งาน', 'repairing' => 'รอซ่อม', 'found' => 'พบ', 'completed' => 'ตรวจแล้ว'];
                                        $auditSColor = ['active' => 'text-green-700 bg-green-50', 'returned' => 'text-blue-700 bg-blue-50', 'not_found' => 'text-red-700 bg-red-50', 'inactive' => 'text-gray-600 bg-gray-50', 'repairing' => 'text-amber-700 bg-amber-50', 'found' => 'text-green-700 bg-green-50', 'completed' => 'text-green-700 bg-green-50'];
                                        $cs = $latestAuditFull['check_result'] ?? $latestHistoryRow['check_status'] ?? '';
                                        $loc = $latestAuditFull['location'] ?? $asset['location'] ?? '';
                                        $resp = $latestAuditFull['responsible_person'] ?? $asset['municipality'] ?? '';
                                        $qty  = $latestAuditFull['quantity'] ?? $latestHistoryRow['quantity'] ?? '';
                                        $auditNotes = $latestAuditFull['notes'] ?? '';
                                        ?>
                                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4 p-3 bg-blue-50/50 rounded-xl border border-blue-100">
                                            <div>
                                                <p class="text-[10px] text-gray-500 mb-0.5">ผลการตรวจ</p>
                                                <?php if ($cs): ?>
                                                    <span class="inline-block text-xs font-semibold px-2 py-0.5 rounded-full <?= $auditSColor[$cs] ?? 'text-gray-700 bg-gray-100' ?>">
                                                        <?= htmlspecialchars($auditSLabel[$cs] ?? $cs) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-400">-</span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p class="text-[10px] text-gray-500 mb-0.5">จำนวนที่นับได้</p>
                                                <p class="text-xs font-semibold text-gray-900"><?= htmlspecialchars((string)($qty ?: '-')) ?></p>
                                            </div>
                                            <div>
                                                <p class="text-[10px] text-gray-500 mb-0.5">สถานที่จริง</p>
                                                <p class="text-xs font-semibold text-gray-900 truncate" title="<?= htmlspecialchars($loc) ?>"><?= htmlspecialchars($loc ?: ($asset['location'] ?? '-')) ?></p>
                                            </div>
                                            <div>
                                                <p class="text-[10px] text-gray-500 mb-0.5">ผู้รับผิดชอบ</p>
                                                <p class="text-xs font-semibold text-gray-900 truncate" title="<?= htmlspecialchars($resp) ?>"><?= htmlspecialchars($resp ?: ($asset['municipality'] ?? '-')) ?></p>
                                            </div>
                                            <?php if (!empty($auditNotes)): ?>
                                                <div class="col-span-2 sm:col-span-4 pt-2 border-t border-blue-100">
                                                    <p class="text-[10px] text-gray-500 mb-0.5">หมายเหตุ (ล่าสุด)</p>
                                                    <p class="text-xs text-gray-700"><?= htmlspecialchars($auditNotes) ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <!-- Image grid: 1 = 1×1, 2+ = 2×2 -->
                                    <?php if (!empty($displayImages)): ?>
                                        <?php
                                        $imgCount  = count($displayImages);
                                        $showImgs  = array_slice($displayImages, 0, 4);
                                        $gridClass = $imgCount === 1
                                            ? 'grid grid-cols-1 max-w-lg mx-auto'
                                            : 'grid grid-cols-2';
                                        ?>
                                        <div class="<?= $gridClass ?> gap-3">
                                            <?php foreach ($showImgs as $dImg): ?>
                                                <div class="aspect-video bg-gray-100 rounded-xl overflow-hidden border border-gray-200 cursor-pointer group relative shadow-sm"
                                                    onclick="openHistoryImage('<?= htmlspecialchars($dImg['url'], ENT_QUOTES) ?>', '<?= htmlspecialchars($dImg['label'] ?? 'ภาพประกอบ', ENT_QUOTES) ?>')">
                                                    <img src="<?= htmlspecialchars($dImg['url']) ?>"
                                                        loading="lazy"
                                                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                                        alt="<?= htmlspecialchars($dImg['label'] ?? '') ?>">
                                                    <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/60 to-transparent px-3 py-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                                        <span class="text-white text-[10px] font-medium"><?= htmlspecialchars($dImg['label'] ?? '') ?></span>
                                                    </div>
                                                    <div class="absolute top-2 right-2 w-7 h-7 rounded-full bg-black/30 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                                        <i class="fas fa-expand-alt text-white text-[10px]"></i>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($imgCount > 4): ?>
                                            <p class="text-xs text-gray-400 text-center mt-2">
                                                <i class="fas fa-images mr-1"></i>+ <?= $imgCount - 4 ?> รูปเพิ่มเติม (ดูใน Gallery)
                                            </p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="flex items-center justify-center h-36 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                                            <div class="text-center text-gray-300">
                                                <i class="fas fa-image text-3xl mb-2 block"></i>
                                                <p class="text-xs">ยังไม่มีภาพประกอบ</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div><!-- /ภาพประกอบ -->
                            <?php endif; ?>
                        </div>
                        <style>
                            @media print {
                                .no-print {
                                    display: none !important;
                                }

                                .main-content {
                                    padding: 0 !important;
                                }

                                .card {
                                    box-shadow: none;
                                    border: none;
                                }
                            }
                        </style>
                        <?php if ($isEdit): ?>
                            <div class="flex gap-2 mt-5 pt-5 border-t border-gray-100">
                                <button type="submit"
                                    class="btn btn-primary"
                                    :disabled="saving"
                                    x-bind:class="saving ? 'opacity-70 cursor-not-allowed' : ''">
                                    <span class="d-flex align-items-center">
                                        <!-- Spinner -->
                                        <span x-show="saving" class="spinner-border spinner-border-sm mr-2" role="status"></span>
                                        <!-- Icon -->
                                        <i x-show="!saving" class="fas fa-save mr-2"></i>
                                        <!-- Label -->
                                        <span x-text="saving ? 'กำลังบันทึก...' : 'บันทึก'"></span>
                                    </span>
                                </button>
                                <a href="<?= $isNew ? 'asset-list.php' : "asset-detail.php?id=$id" ?>" class="btn btn-secondary">
                                    ยกเลิก
                                </a>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <!-- Side Panel -->
            <div class="space-y-4">
                <?php if (!$isNew): ?>
                    <!-- รูปภาพ: upload ย้ายแสดงไปใต้หมายเหตุแล้ว -->
                    <?php if ($isAdmin): ?>
                        <div class="card p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="font-bold text-gray-900 text-sm">จัดการรูปภาพ</h3>
                                <span class="text-xs text-gray-400"><?= count($assetImages) ?>/10</span>
                            </div>
                            <?php if (!empty($assetImages) && isset($assetImages[0])): ?>
                                <div class="mb-3 border rounded overflow-hidden">
                                    <?php
                                    // ตรวจสอบว่าเป็น Array หรือไม่ก่อนแสดงผล
                                    $imageName = is_array($assetImages[0]) ? ($assetImages[0]['filename'] ?? '') : $assetImages[0];
                                    ?>
                                    <div class="mb-4 rounded-xl overflow-hidden bg-gray-50">
                                        <img src="<?= APP_URL ?>/assets/uploads/<?= htmlspecialchars($imageName) ?>"
                                            alt="Asset Preview"
                                            class="w-full h-80 object-contain hover:scale-105 transition-transform duration-500 cursor-pointer">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (count($assetImages) < 3): ?>
                                <label class="btn btn-secondary btn-sm w-full cursor-pointer">
                                    <i class="fas fa-upload"></i> อัปโหลดรูปภาพ
                                    <input type="file" accept="image/*" capture="camera" class="hidden" onchange="uploadImage(this, <?= $id ?>)">
                                </label>
                            <?php else: ?>
                                <p class="text-xs text-center text-gray-400">อัปโหลดครบ 3 รูปแล้ว</p>
                            <?php endif; ?>
                            <?php if (!empty($assetImages)): ?>
                                <button class="btn btn-secondary btn-sm w-full mt-2 no-print" onclick="openGallery()">
                                    <i class="fas fa-images"></i> เปิด Gallery (<?= count($assetImages) ?> รูป)
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <!-- QR Code Upload Section -->
                    <div class="card p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-bold text-gray-900 text-sm">QR Code จริง</h3>
                            <span class="text-xs text-gray-400"><?= count($qrcodeImages) ?>/2</span>
                        </div>
                        <?php if (!empty($qrcodeImages)): ?>
                            <div class="aspect-video bg-gray-50 rounded-xl overflow-hidden border border-gray-100 mb-2">
                                <img id="qrPreview" src="<?= APP_URL . '/assets/uploads/' . htmlspecialchars($qrcodeImages[0]['filename']) ?>" class="w-full h-full object-contain">
                            </div>
                            <div class="grid grid-cols-4 gap-1.5 mb-3">
                                <?php foreach ($qrcodeImages as $qr): ?>
                                    <div class="relative group aspect-square" id="qr-thumb-<?= $qr['id'] ?>">
                                        <img src="<?= APP_URL . '/assets/uploads/' . htmlspecialchars($qr['filename']) ?>"
                                            class="w-full h-full object-cover rounded-lg cursor-pointer border-2 border-blue-500"
                                            onclick="previewQRImage('<?= APP_URL . '/assets/uploads/' . htmlspecialchars($qr['filename']) ?>', <?= $qr['id'] ?>)">
                                        <?php if ($isAdmin): ?>
                                            <div class="absolute inset-0 bg-black/50 rounded-lg opacity-0 group-hover:opacity-100 flex items-center justify-center gap-1 transition-opacity">
                                                <button onclick="deleteQRImage(<?= $qr['id'] ?>, <?= $id ?>)" class="text-white text-xs bg-red-600 rounded px-1 py-0.5" title="ลบ">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <!-- ปรับให้เป็นสี่เหลี่ยมจัตุรัสและลดขนาดลง -->
                            <div class="w-32 h-32 bg-gray-50 rounded-xl flex items-center justify-center border-2 border-dashed border-gray-200 mb-3 mx-auto">
                                <div class="text-center text-gray-300">
                                    <i class="fas fa-qrcode text-2xl mb-1 block"></i>
                                    <p class="text-[10px]">QR Code</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($isAdmin && count($qrcodeImages) < 2): ?>
                            <label class="btn btn-secondary btn-sm w-full cursor-pointer">
                                <i class="fas fa-camera"></i> ถ่าย/อัปโหลด QR
                                <input type="file" accept="image/*" capture="environment" class="hidden" onchange="uploadQRImage(this, <?= $id ?>)">
                            </label>
                        <?php elseif ($isAdmin): ?>
                            <p class="text-xs text-center text-gray-400">อัปโหลดครบ 2 รูปแล้ว</p>
                        <?php endif; ?>
                    </div>
                    <!-- Generated QR Code -->
                    <div class="card p-4">
                        <h3 class="font-bold text-gray-900 text-sm mb-3">QR Code ระบบ</h3>
                        <div class="flex justify-center">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=128x128&data=<?= urlencode($asset['asset_no'] ?? '') ?>" class="rounded-lg w-28 h-28">
                        </div>
                        <p class="text-center text-xs text-gray-400 mt-2 font-mono"><?= htmlspecialchars($asset['asset_no'] ?? '') ?></p>
                        <button onclick="printQR('<?= htmlspecialchars($asset['asset_no'] ?? '') ?>')" class="btn btn-secondary btn-sm w-full mt-2">
                            <i class="fas fa-print"></i> พิมพ์ QR
                        </button>
                    </div>
                    <!-- Meta Info -->
                    <div class="card p-4 text-xs text-gray-500 space-y-2">
                        <div class="flex justify-between">
                            <span>สร้างเมื่อ</span>
                            <span class="text-gray-700"><?= $asset['created_at'] ? date('d/m/Y H:i', strtotime($asset['created_at'])) : '-' ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>แก้ไขล่าสุด</span>
                            <span class="text-gray-700"><?= $asset['updated_at'] ? date('d/m/Y H:i', strtotime($asset['updated_at'])) : '-' ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$isNew): ?>
            <!-- Inventory History -->
            <div class="card mt-5">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-bold text-gray-900 text-sm">ประวัติการตรวจนับย้อนหลัง 3 ปี</h3>
                </div>
                <?php if (!empty($invHistory)): ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>รอบตรวจนับ</th>
                                    <th>ผลการตรวจ</th>
                                    <th>ผู้ตรวจ</th>
                                    <th>จำนวน</th>
                                    <th>วันที่ตรวจ</th>
                                    <th>หมายเหตุ</th>
                                    <th>หลักฐานภาพ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $statusLabel = [
                                    'found' => 'พบ',
                                    'active' => 'ใช้งาน',
                                    'returned' => 'ชำรุด-ส่งคืน',
                                    'not_found' => 'ไม่พบ',
                                    'inactive' => 'ไม่ใช้งาน',
                                    'repairing' => 'ชำรุด-รอซ่อม',
                                    'damaged' => 'ชำรุด',
                                    'completed' => 'ตรวจแล้ว'
                                ];
                                $statusClass = [
                                    'found' => 'badge-active',
                                    'active' => 'badge-active',
                                    'returned' => 'badge-disposed',
                                    'not_found' => 'badge-cancelled',
                                    'inactive' => 'badge-cancelled',
                                    'repairing' => 'badge-disposed',
                                    'damaged' => 'badge-disposed',
                                    'completed' => 'badge-active'
                                ];
                                foreach ($invHistory as $h):
                                ?>
                                    <tr>
                                        <td class="text-sm font-medium text-gray-900"><?= htmlspecialchars($h['session_name']) ?></td>
                                        <td><span class="badge <?= $statusClass[$h['check_status']] ?? '' ?>"><?= $statusLabel[$h['check_status']] ?? $h['check_status'] ?></span></td>
                                        <td class="text-sm text-gray-600"><?= htmlspecialchars($h['checked_by_name'] ?? '-') ?></td>
                                        <td class="text-sm text-gray-600"><?= htmlspecialchars($h['quantity'] ?? '-') ?></td>
                                        <td class="text-xs text-gray-500"><?= $h['checked_at'] ? date('d/m/Y H:i', strtotime($h['checked_at'])) : '-' ?></td>
                                        <td class="text-xs text-gray-500 max-w-[150px] truncate"><?= htmlspecialchars($h['remarks'] ?? '-') ?></td>
                                        <td>
                                            <?php if (!empty($h['audit_images'])): ?>
                                                <div class="flex gap-1.5">
                                                    <?php foreach ($h['audit_images'] as $img): ?>
                                                        <button type="button" onclick='openHistoryImage(<?= json_encode($img['url']) ?>, <?= json_encode($img['label']) ?>)' title="<?= htmlspecialchars($img['label']) ?>">
                                                            <img src="<?= htmlspecialchars($img['url']) ?>" alt="<?= htmlspecialchars($img['label']) ?>" class="w-12 h-12 rounded border border-gray-200 object-cover">
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php elseif ($h['photo_path']): ?>
                                                <?php $photoUrl = APP_URL . '/' . ltrim($h['photo_path'], '/'); ?>
                                                <button type="button" onclick='openHistoryImage(<?= json_encode($photoUrl) ?>, "หลักฐานภาพ")' title="ดูรูป">
                                                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="หลักฐานภาพ" class="w-12 h-12 rounded border border-gray-200 object-cover">
                                                </button>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-300">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="px-5 py-8 text-center text-sm text-gray-400">
                        ไม่พบประวัติการตรวจนับในช่วง 3 ปีที่ผ่านมา
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<div id="toast-container"></div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
    function printPage() {
        window.print();
    }
    const galleryImages = <?= json_encode(array_values($assetImages)) ?>;
    const uploadPath = '<?= APP_URL ?>/assets/uploads/';
    let currentIndex = 0;

    function openGallery(startImgId = null) {
        if (galleryImages.length === 0) return;
        // ถ้ามีการส่ง ID มา ให้เลื่อนไปที่รูปนั้นก่อน
        if (startImgId) {
            currentIndex = galleryImages.findIndex(img => img.id == startImgId);
            if (currentIndex === -1) currentIndex = 0;
        }
        const modal = document.getElementById('galleryModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden'; // กันหน้าจอหลักเลื่อน
        updateModalContent();
    }

    function closeGallery() {
        const modal = document.getElementById('galleryModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = 'auto';
    }

    function openHistoryImage(url, label = 'หลักฐานภาพ') {
        const modal = document.getElementById('historyImageModal');
        document.getElementById('historyModalImg').src = url;
        document.getElementById('historyModalTitle').innerText = label;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }

    function closeHistoryImage() {
        const modal = document.getElementById('historyImageModal');
        document.getElementById('historyModalImg').src = '';
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = 'auto';
    }

    function updateModalContent() {
        const img = galleryImages[currentIndex];
        const mainImg = document.getElementById('modalMainImg');
        // ใส่ Effect จางเข้า
        mainImg.style.opacity = '0';
        setTimeout(() => {
            mainImg.src = uploadPath + img.filename;
            mainImg.style.opacity = '1';
        }, 150);
        document.getElementById('modalImgCounter').innerText = `รูปที่ ${currentIndex + 1} / ${galleryImages.length}`;
        document.getElementById('modalImgInfo').innerText = img.is_primary == 1 ? '⭐ รูปหลักของทรัพย์สิน' : 'รูปประกอบทรัพย์สิน';
        // จัดการ Thumbnail ด้านล่าง
        document.querySelectorAll('.modal-thumb').forEach((thumb, idx) => {
            if (idx === currentIndex) {
                thumb.classList.add('border-blue-500', 'scale-110', 'opacity-100');
                thumb.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center'
                });
            } else {
                thumb.classList.remove('border-blue-500', 'scale-110', 'opacity-100');
            }
        });
    }

    function nextImage() {
        currentIndex = (currentIndex + 1) % galleryImages.length;
        updateModalContent();
    }

    function prevImage() {
        currentIndex = (currentIndex - 1 + galleryImages.length) % galleryImages.length;
        updateModalContent();
    }

    function jumpToImage(index) {
        currentIndex = index;
        updateModalContent();
    }
    // รองรับปุ่ม Keyboard
    document.addEventListener('keydown', (e) => {
        const historyModal = document.getElementById('historyImageModal');
        if (historyModal && !historyModal.classList.contains('hidden')) {
            if (e.key === 'Escape') closeHistoryImage();
            return;
        }
        const modal = document.getElementById('galleryModal');
        if (modal.classList.contains('hidden')) return;
        if (e.key === 'ArrowRight') nextImage();
        if (e.key === 'ArrowLeft') prevImage();
        if (e.key === 'Escape') closeGallery();
    });

    function setAsPrimary(imgId, assetId) {
        if (confirm('ต้องการตั้งรูปนี้เป็นรูปหลักใช่หรือไม่?')) {
            setPrimaryImage(imgId, assetId);
        }
    }

    function assetDetail() {
        return {
            saving: false,
            updateDeptName(e) {
                const opt = e.target.selectedOptions[0];
                document.getElementById('dept_name_hidden').value = opt ? opt.dataset.name || '' : '';
            },
            async saveAsset() {
                this.saving = true;
                const form = document.getElementById('assetForm');
                if (!form.checkValidity()) {
                    form.reportValidity();
                    this.saving = false;
                    return;
                }
                const fd = new FormData(form);
                const data = {};
                fd.forEach((v, k) => data[k] = v);
                form.querySelectorAll('select[disabled], input[readonly]').forEach(el => {
                    if (el.name) data[el.name] = el.value || el.dataset.value || '';
                });
                const r = await apiPost('<?= APP_URL ?>/api/assets.php', data);
                this.saving = false;
                if (r.success) {
                    showToast('บันทึกข้อมูลสำเร็จ', 'success');
                    setTimeout(() => window.location.href = 'asset-detail.php?id=' + (r.data?.id || <?= $id ?>), 800);
                } else {
                    showToast(r.message || 'เกิดข้อผิดพลาด', 'error');
                }
            }
        };
    }

    function previewImage(url, imgId) {
        document.getElementById('primaryImg').src = url;
        document.querySelectorAll('#thumbGrid img').forEach(img => img.classList.remove('border-blue-500'));
        document.querySelector(`#thumb-${imgId} img`)?.classList.add('border-blue-500');
    }

    function previewQRImage(url, imgId) {
        document.getElementById('qrPreview').src = url;
        document.querySelectorAll('.grid.grid-cols-4.gap-1.5.mb-3 img').forEach(img => img.classList.remove('border-blue-500'));
        document.querySelector(`#qr-thumb-${imgId} img`)?.classList.add('border-blue-500');
    }


    // ฟังก์ชันกลางสำหรับการย่อรูปโดยรักษาสัดส่วน
    // ใช้ createImageBitmap() ซึ่งอ่าน EXIF Orientation และหมุนรูปให้อัตโนมัติ
    async function processImage(file, maxWidth = 640, maxHeight = 480) {
        try {
            const bitmap = await createImageBitmap(file);

            let width = bitmap.width;
            let height = bitmap.height;

            // คำนวณ ratio รักษาสัดส่วน ไม่ขยายรูปที่เล็กกว่า max
            const ratio = Math.min(maxWidth / width, maxHeight / height, 1);
            width  = Math.round(width  * ratio);
            height = Math.round(height * ratio);

            const canvas = document.createElement('canvas');
            canvas.width  = width;
            canvas.height = height;
            canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height);
            bitmap.close();

            // แปลงเป็น Blob คุณภาพ 0.8
            return new Promise((resolve, reject) => {
                canvas.toBlob(
                    (blob) => blob
                        ? resolve(new File([blob], file.name, { type: 'image/jpeg' }))
                        : reject(new Error('แปลงรูปไม่สำเร็จ')),
                    'image/jpeg', 0.8
                );
            });
        } catch (err) {
            throw new Error('ประมวลผลรูปภาพไม่ได้: ' + err.message);
        }
    }


    // แก้ไขฟังก์ชันอัปโหลดรูปปกติ
    async function uploadImage(input, assetId) {
        if (!input.files[0]) return;
        let compressedFile;
        try {
            compressedFile = await processImage(input.files[0]);
        } catch (e) {
            showToast('ประมวลผลรูปไม่ได้: ' + e.message, 'error');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'upload_image');
        fd.append('id', assetId);
        fd.append('image', compressedFile); // ส่งไฟล์ที่ย่อแล้ว

        const res = await fetch('<?= APP_URL ?>/api/assets.php', {
            method: 'POST',
            body: fd
        });
        const r = await res.json();
        if (r.success) {
            showToast('อัปโหลดรูปสำเร็จ', 'success');
            setTimeout(() => location.reload(), 800);
        } else showToast(r.message || 'อัปโหลดล้มเหลว', 'error');
    }

    // แก้ไขฟังก์ชันอัปโหลด QR
    async function uploadQRImage(input, assetId) {
        if (!input.files[0]) return;
        let compressedFile;
        try {
            compressedFile = await processImage(input.files[0]);
        } catch (e) {
            showToast('ประมวลผลรูปไม่ได้: ' + e.message, 'error');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'upload_qr_image');
        fd.append('id', assetId);
        fd.append('image', compressedFile); // ส่งไฟล์ที่ย่อแล้ว

        const res = await fetch('<?= APP_URL ?>/api/assets.php', {
            method: 'POST',
            body: fd
        });
        const r = await res.json();
        if (r.success) {
            showToast('อัปโหลด QR สำเร็จ', 'success');
            setTimeout(() => location.reload(), 800);
        } else showToast(r.message || 'อัปโหลดล้มเหลว', 'error');
    }
    async function setPrimaryImage(imgId, assetId) {
        const r = await apiPost('<?= APP_URL ?>/api/assets.php', {
            action: 'set_primary_image',
            img_id: imgId,
            asset_id: assetId
        });
        if (r.success) {
            showToast('ตั้งรูปหลักสำเร็จ', 'success');
            setTimeout(() => location.reload(), 800);
        } else showToast(r.message || 'เกิดข้อผิดพลาด', 'error');
    }
    async function deleteImage(imgId, assetId) {
        confirmAction('ต้องการลบรูปภาพนี้ใช่หรือไม่?', async () => {
            const r = await apiPost('<?= APP_URL ?>/api/assets.php', {
                action: 'delete_image',
                img_id: imgId,
                asset_id: assetId
            });
            if (r.success) {
                showToast('ลบรูปภาพสำเร็จ', 'success');
                setTimeout(() => location.reload(), 800);
            } else showToast(r.message || 'เกิดข้อผิดพลาด', 'error');
        }, 'ลบรูปภาพ');
    }
    async function deleteQRImage(imgId, assetId) {
        confirmAction('ต้องการลบรูป QR นี้ใช่หรือไม่?', async () => {
            const r = await apiPost('<?= APP_URL ?>/api/assets.php', {
                action: 'delete_qr_image',
                img_id: imgId,
                asset_id: assetId
            });
            if (r.success) {
                showToast('ลบรูป QR สำเร็จ', 'success');
                setTimeout(() => location.reload(), 800);
            } else showToast(r.message || 'เกิดข้อผิดพลาด', 'error');
        }, 'ลบรูป QR');
    }

    function printQR(assetNo) {
        const win = window.open('', '_blank', 'width=300,height=350');
        win.document.write(`<html><head><title>QR - ${assetNo}</title></head>
        <body style="text-align:center;padding:20px;font-family:sans-serif;">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(assetNo)}" style="width:200px;height:200px;">
            <p style="font-weight:bold;margin-top:10px;font-size:14px;">${assetNo}</p>
            <script>window.onload=()=>{window.print();window.close();}<\/script>
        </body></html>`);
    }
    //     function printAsset(id) {
    //     const frame = document.getElementById('printFrame');
    //     // โหลดหน้า asset-print.php เข้าไปใน Iframe
    //     frame.src = 'asset-print.php?id=' + id;
    //     // รอให้โหลดหน้าเสร็จแล้วสั่งพิมพ์
    //     frame.onload = function() {
    //         try {
    //             frame.contentWindow.focus();
    //             frame.contentWindow.print();
    //         } catch (e) {
    //             console.error('การพิมพ์ล้มเหลว:', e);
    //             // ถ้าพิมพ์ผ่าน Iframe ไม่ได้ ให้เปิด Tab ใหม่แทนเป็นทางเลือกสำรอง
    //             window.open('asset-print.php?id=' + id, '_blank');
    //         }
    //     };
    // }
    function printAsset(ids) { // ids = '1' หรือ '1,2,3'
        const frame = document.getElementById('printFrame');
        frame.src = 'asset-print.php?id=' + ids + '&per_page=1';
        frame.onload = function() {
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (e) {
                window.open('asset-print.php?id=' + ids, '_blank');
            }
        };
    }
</script>
<div id="historyImageModal" class="fixed inset-0 z-[70] hidden bg-black/90 items-center justify-center p-4 no-print" onclick="closeHistoryImage()">
    <button type="button" onclick="closeHistoryImage()" class="absolute top-5 right-5 text-white text-3xl hover:text-gray-300 z-[80] p-2">
        <i class="fas fa-times"></i>
    </button>
    <div class="max-w-5xl w-full flex flex-col items-center" onclick="event.stopPropagation()">
        <div class="relative w-full h-[78vh] flex items-center justify-center mb-5">
            <img id="historyModalImg" src="" alt="หลักฐานภาพ" class="max-w-full max-h-full object-contain rounded-lg shadow-2xl">
        </div>
        <div class="text-white text-center">
            <p id="historyModalTitle" class="text-base font-medium bg-white/10 px-4 py-2 rounded-full inline-block"></p>
        </div>
    </div>
</div>
<div id="galleryModal" class="fixed inset-0 z-[60] hidden bg-black/95 items-center justify-center p-4 no-print">
    <button onclick="closeGallery()" class="absolute top-5 right-5 text-white text-3xl hover:text-gray-300 z-[70] p-2">
        <i class="fas fa-times"></i>
    </button>
    <button onclick="prevImage()" class="absolute left-4 top-1/2 -translate-y-1/2 text-white/50 hover:text-white text-5xl z-[70] transition-colors p-4">
        <i class="fas fa-chevron-left"></i>
    </button>
    <button onclick="nextImage()" class="absolute right-4 top-1/2 -translate-y-1/2 text-white/50 hover:text-white text-5xl z-[70] transition-colors p-4">
        <i class="fas fa-chevron-right"></i>
    </button>
    <div class="max-w-6xl w-full flex flex-col items-center">
        <div class="relative w-full h-[75vh] flex items-center justify-center mb-6">
            <img id="modalMainImg" src="" class="max-w-full max-h-full object-contain rounded-lg shadow-2xl transition-all duration-300">
        </div>
        <div class="text-white text-center mb-6">
            <p id="modalImgInfo" class="text-lg font-medium mb-1"></p>
            <p id="modalImgCounter" class="text-sm text-gray-400 bg-white/10 px-3 py-1 rounded-full inline-block"></p>
        </div>
        <div class="flex gap-3 overflow-x-auto p-2 max-w-full no-scrollbar">
            <?php foreach ($assetImages as $index => $img): ?>
                <img src="<?= APP_URL . '/assets/uploads/' . htmlspecialchars($img['filename']) ?>"
                    class="modal-thumb w-20 h-20 object-cover rounded-lg cursor-pointer border-2 border-transparent hover:border-blue-400 transition-all opacity-60 hover:opacity-100"
                    onclick="jumpToImage(<?= $index ?>)"
                    data-index="<?= $index ?>">
            <?php endforeach; ?>
        </div>
    </div>
</div>
</body>

</html>