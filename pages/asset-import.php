<?php
$pageTitle = 'นำเข้าทรัพย์สิน';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();
requireRole(['admin','webadmin']);
$db = Database::getInstance();
$logPage   = max(1, intval($_GET['lpage'] ?? 1));
$logLimit  = 10;
$logOffset = ($logPage - 1) * $logLimit;
$logTotal  = $db->fetchOne("SELECT COUNT(*) as c FROM import_logs")['c'];
$logPages  = max(1, ceil($logTotal / $logLimit));
// ดึงประวัติการอัปโหลด พร้อมเชื่อมตาราง sites ผ่านรหัสโรงงานเพื่อแสดงชื่อที่ชัดเจน
$importLogs = $db->fetchAll("
    SELECT il.*, u.full_name, s.site_name
    FROM import_logs il
    LEFT JOIN users u ON il.imported_by = u.id
    LEFT JOIN sites s ON il.plant_code = s.site_code
    ORDER BY il.id DESC
    LIMIT $logLimit OFFSET $logOffset
");


$userRole = $_SESSION['user_role'] ?? '';
// ดึงค่าการผูกโรงงานของผู้ใช้จากตาราง users
$userPlantCode = $_SESSION['user_plant_code'] ?? ($_SESSION['plant_code'] ?? '');
$userSiteId    = $_SESSION['user_site_id']    ?? ($_SESSION['site_id']    ?? '');
$availablePlants = [];
if ($userRole === 'admin') {
    // Admin: ดึงรายชื่อไซต์/โรงงานทั้งหมดจากตาราง sites ที่มีอยู่จริงในระบบ
    $availablePlants = $db->fetchAll("SELECT site_code as plant_code, site_name as plant_name FROM sites WHERE is_active = 1 ORDER BY site_code");
} else {
    // Webadmin: ดึงเฉพาะไซต์/โรงงานที่รหัสตรงกับสิทธิ์ตัวเอง
    if (!empty($userPlantCode)) {
        $availablePlants = $db->fetchAll("SELECT site_code as plant_code, site_name as plant_name FROM sites WHERE site_code = ? AND is_active = 1 LIMIT 1", [$userPlantCode]);
    } else if (!empty($userSiteId)) {
        $availablePlants = $db->fetchAll("SELECT site_code as plant_code, site_name as plant_name FROM sites WHERE id = ? AND is_active = 1 LIMIT 1", [$userSiteId]);
    }
    // ถ้าในตาราง sites ยังว่างอยู่ ให้ดึงค่าจากเซสชันแสดงผลความปลอดภัยไว้ก่อน
    if (empty($availablePlants)) {
        $availablePlants = [[
            'plant_code' => 'No Site',
            'plant_name' => 'No Site Assigned'
        ]];
    }
}
// กำหนดข้อความแสดงชื่อโรงงานปัจจุบันของ Webadmin ให้เด่นชัดเจน
$webadminPlantDisplay = "";
if (!empty($availablePlants) && $userRole !== 'admin') {
    $webadminPlantDisplay = "[" . $availablePlants[0]['plant_code'] . "] " . $availablePlants[0]['plant_name'];
}
?>
<?php include __DIR__ . '/../components/head.php'; ?>
<div class="min-h-screen bg-slate-50/50" x-data="importPage()">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>
    <main class="main-content flex-1 p-4 sm:p-6">
        <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-gray-200 pb-4 bg-white p-4 rounded-2xl shadow-sm">
            <div>
                <h1 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-file-import text-blue-600"></i> นำเข้าข้อมูลทรัพย์สินคงคลัง
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">ระบบตรวจสอบสิทธิ์แยกรายไซต์โรงงาน พร้อมโครงสร้างไฟล์นำเข้าแบบละเอียดครบทุกฟิลด์ข้อมูล</p>
            </div>
            <div>
                <a href="<?= APP_URL ?>/api/download-template.php"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 rounded-xl text-xs font-semibold shadow-sm transition">
                    <i class="fas fa-file-download text-green-600"></i>
                    <span>ดาวน์โหลดฟอร์ม Template (.CSV)</span>
                </a>
            </div>
        </div>
        <div class="mb-6 bg-white border border-blue-100 rounded-2xl p-5 shadow-sm">
            <h3 class="text-xs font-bold text-blue-700 flex items-center gap-1.5 mb-3">
                <i class="fas fa-table"></i> ข้อกำหนดโครงสร้างคอลัมน์ในไฟล์ Template (รองรับข้อมูลละเอียดครอบคลุม 15 คอลัมน์)
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-2 text-[11px]">
                <div class="p-2 bg-blue-50/40 border border-blue-200 rounded-lg"><strong>1. plant_code</strong> <span class="text-red-500">*</span><br><span class="text-gray-400">รหัสโรงงานปลายทาง</span></div>
                <div class="p-2 bg-blue-50/40 border border-blue-200 rounded-lg"><strong>2. asset_no</strong> <span class="text-red-500">*</span><br><span class="text-gray-400">เลขที่ทรัพย์สินหลัก</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>3. class_code</strong><br><span class="text-gray-400">รหัสหมวดหมู่ / คลาส</span></div>
                <div class="p-2 bg-blue-50/40 border border-blue-200 rounded-lg"><strong>4. asset_description</strong> <span class="text-red-500">*</span><br><span class="text-gray-400">ชื่อและคำอธิบายทรัพย์สิน</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>5. cap_date</strong><br><span class="text-gray-400">วันที่ได้มา (YYYY-MM-DD)</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>6. acquis_val</strong><br><span class="text-gray-400">มูลค่าราคาทุนทรัพย์สิน</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>7. cost_center</strong><br><span class="text-gray-400">ศูนย์ต้นทุนบัญชี</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>8. department_code</strong><br><span class="text-gray-400">รหัสแผนกที่ดูแล</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>9. department_name</strong><br><span class="text-gray-400">ชื่อแผนกงาน</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>10. municipality</strong><br><span class="text-gray-400">พื้นที่/เขตเทศบาล</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>11. serial_no</strong><br><span class="text-gray-400">เลขเครื่องเครื่องจักร (S/N)</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>12. brand</strong><br><span class="text-gray-400">ยี่ห้อของผลิตภัณฑ์</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>13. model</strong><br><span class="text-gray-400">รุ่น / โมเดลสินค้า</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>14. status</strong><br><span class="text-gray-400">สถานะเปิดระบบเริ่มต้น</span></div>
                <div class="p-2 bg-slate-50 border border-gray-200 rounded-lg"><strong>15. remark</strong><br><span class="text-gray-400">หมายเหตุบันทึกย่อ</span></div>
            </div>
            <p class="text-[10px] text-gray-400 mt-2 flex items-center gap-1">
                <span class="inline-block w-1.5 h-1.5 rounded-full bg-red-500"></span>
                <span>ช่องที่มีสัญลักษณ์ <span class="text-red-500 font-bold">*</span> คือข้อมูลบังคับกรอก ห้ามปล่อยว่างเด็ดขาดเพื่อความเที่ยงตรงของระบบ</span>
            </p>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <div class="lg:col-span-1 space-y-5">
                <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
                    <h2 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4 flex items-center gap-1.5">
                        <i class="fas fa-industry text-gray-400"></i> โรงงานปลายทางที่รองรับการอัปโหลด
                    </h2>
                    <div class="mb-4">
                        <?php if ($userRole === 'admin'): ?>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">ระบุโรงงานปลายทางที่รับข้อมูล</label>
                            <div class="relative">
                                <select x-model="selectedPlant" class="w-full bg-white border border-gray-200 rounded-xl pl-3 py-2.5 text-xs font-medium focus:outline-none focus:border-blue-500 appearance-none cursor-pointer text-gray-800">
                                    <option value="">-- กรุณาเลือกโรงงานปลายทาง --</option>
                                    <?php foreach ($availablePlants as $p): ?>
                                        <option value="<?= htmlspecialchars($p['plant_code']) ?>">
                                            [<?= htmlspecialchars($p['plant_code']) ?>] <?= htmlspecialchars($p['plant_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400 text-[10px]">
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                            </div>
                        <?php else: ?>
                            <label class="block text-xs font-semibold text-gray-400 mb-1.5">โรงงานที่คุณมีสิทธิ์รับผิดชอบ (ล็อกเฉพาะไซต์)</label>
                            <div class="bg-blue-50/80 border border-blue-100 rounded-xl px-4 py-3 text-xs text-blue-900 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-lock text-blue-500 text-[10px]"></i>
                                    <span class="font-bold"><?= htmlspecialchars($webadminPlantDisplay) ?></span>
                                </div>
                                <span class="bg-blue-600 text-white font-bold text-[9px] px-1.5 py-0.5 rounded">ไซต์ประจำตัว</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-5">
                        <label class="block text-xs font-semibold text-gray-700 mb-2">เมื่อตรวจพบรหัส Asset No. ซ้ำกันในระบบ</label>
                        <div class="space-y-2">
                            <label class="flex items-start gap-2 p-2 rounded-xl border cursor-pointer text-xs transition" :class="duplicateMode === 'update' ? 'bg-blue-50/20 border-blue-200' : 'border-gray-100 hover:bg-gray-50'">
                                <input type="radio" name="dup_mode" value="update" x-model="duplicateMode" class="mt-0.5 text-blue-600">
                                <div>
                                    <p class="font-semibold text-gray-800">อัปเดตข้อมูลทับรายการเดิม (Update)</p>
                                    <p class="text-[10px] text-gray-400">เขียนทับช่องข้อมูลด้วยข้อมูลชุดล่าสุดทันที</p>
                                </div>
                            </label>
                            <label class="flex items-start gap-2 p-2 rounded-xl border cursor-pointer text-xs transition" :class="duplicateMode === 'skip' ? 'bg-blue-50/20 border-blue-200' : 'border-gray-100 hover:bg-gray-50'">
                                <input type="radio" name="dup_mode" value="skip" x-model="duplicateMode" class="mt-0.5 text-blue-600">
                                <div>
                                    <p class="font-semibold text-gray-800">ข้ามแถวข้อมูลที่ซ้ำพบล่าสุด (Skip)</p>
                                    <p class="text-[10px] text-gray-400">คงมูลค่าและข้อมูลเดิมไว้เพื่อความปลอดภัย</p>
                                </div>
                            </label>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">เลือกไฟล์จากคอมพิวเตอร์ของคุณ</label>
                        <div @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="handleDrop($event)"
                             :class="dragOver ? 'border-blue-500 bg-blue-50/30' : 'border-gray-200 bg-slate-50 hover:bg-gray-100/60'"
                             class="border-2 border-dashed rounded-xl p-5 text-center cursor-pointer transition relative">
                            <input type="file" @change="handleFileSelect($event)" accept=".xlsx, .xls, .csv" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                            <div x-show="!file" class="space-y-1">
                                <i class="fas fa-cloud-upload-alt text-xl text-gray-400"></i>
                                <p class="text-xs font-medium text-gray-600">ลากไฟล์มาวาง หรือคลิกเปิดกล่องค้นหา</p>
                            </div>
                            <div x-show="file" x-cloak class="flex items-center justify-between bg-white border p-2 rounded-lg text-left">
                                <div class="flex items-center gap-2 min-w-0">
                                    <i class="fas fa-file-csv text-green-600 text-sm"></i>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-gray-800 truncate" x-text="file ? file.name : ''"></p>
                                        <p class="text-[10px] text-gray-400" x-text="file ? (file.size/1024).toFixed(1)+' KB' : ''"></p>
                                    </div>
                                </div>
                                <button @click.prevent="clearFile()" class="text-gray-400 hover:text-red-500 p-1"><i class="fas fa-times text-xs"></i></button>
                            </div>
                        </div>
                    </div>
                    <button @click="uploadFile()" :disabled="!file || uploading"
                            :class="(!file || uploading) ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-blue-600 text-white hover:bg-blue-500 shadow-sm'"
                            class="w-full py-2.5 rounded-xl font-bold text-xs flex items-center justify-center gap-2 transition">
                        <span x-show="uploading" class="w-3.5 h-3.5 border-2 border-gray-400 border-t-transparent rounded-full animate-spin"></span>
                        <i x-show="!uploading" class="fas fa-check-circle"></i>
                        <span x-text="uploading ? 'กำลังประมวลผลความปลอดภัยและแถวข้อมูล...' : 'เริ่มอัปโหลดไฟล์เข้าระบบ'"></span>
                    </button>
                </div>
                <div class="bg-white border rounded-2xl p-4 shadow-sm text-xs" x-show="result" x-cloak>
                    <div :class="result.success ? 'text-green-800' : 'text-red-800'">
                        <p class="font-bold flex items-center gap-1.5">
                            <i :class="result.success ? 'fas fa-check-circle text-green-500' : 'fas fa-times-circle text-red-500'"></i>
                            <span x-text="result.message"></span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="lg:col-span-2">
                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 bg-slate-50/40 flex items-center justify-between">
                        <h2 class="text-xs font-bold text-gray-900 flex items-center gap-1.5">
                            <i class="fas fa-history text-gray-500"></i> ประวัติความปลอดภัยและการอัปโหลดแยกตามไซต์
                        </h2>
                        <span class="text-[10px] font-bold bg-white px-2.5 py-1 border border-gray-200 rounded-full text-gray-500 shadow-sm">
                            รวมประวัติ <?= number_format($logTotal) ?> รอบ
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-50/50 border-b border-gray-100 text-gray-400 font-bold uppercase tracking-wider">
                                    <th class="px-4 py-3">วันเวลา / ผู้อัปโหลด</th>
                                    <th class="px-4 py-3">เป้าหมายไซต์โรงงาน</th>
                                    <th class="px-4 py-3">ดาวน์โหลดหลักฐาน</th>
                                    <th class="px-4 py-3 text-center">สำเร็จ/ข้าม/พลาด</th>
                                    <th class="px-4 py-3 text-right">การจัดการย้อนกลับ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-600">
                                <?php if (empty($importLogs)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-8 text-gray-400 italic">ไม่พบข้อมูลบันทึกประวัติล็อกการอัปโหลดในคลังข้อมูล</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($importLogs as $log):
                                        $fileKey = !empty($log['filename']) ? $log['filename'] : ($log['file_name'] ?? '');
                                        $isRollbacked = ($log['is_rolled_back'] ?? 0) == 1;
                                    ?>
                                        <tr class="hover:bg-slate-50/30 transition <?= $isRollbacked ? 'bg-gray-50/60 opacity-60' : '' ?>">
                                            <td class="px-4 py-3">
                                                <div class="font-semibold text-gray-900"><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?> น.</div>
                                                <div class="text-[10px] text-gray-400 mt-0.5"><i class="fas fa-user text-[9px]"></i> <?= htmlspecialchars($log['full_name'] ?? 'ไม่ทราบผู้ใช้งาน') ?></div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php if (!empty($log['plant_code'])): ?>
                                                    <div class="font-bold text-blue-600">[<?= htmlspecialchars($log['plant_code']) ?>]</div>
                                                    <div class="text-[10px] text-gray-500 truncate max-w-[150px]" title="<?= htmlspecialchars($log['site_name']) ?>">
                                                        <?= htmlspecialchars($log['site_name'] ?? 'ไม่พบรหัสชื่อไซต์ในระบบ') ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-400 italic text-[11px]">ไม่ระบุไซต์ (ประวัติระบบเก่า)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php if (!empty($fileKey)): ?>
                                                    <a href="<?= APP_URL ?>/assets/imports/<?= htmlspecialchars($fileKey) ?>" download
                                                       class="inline-flex items-center gap-1 text-blue-600 hover:underline font-medium truncate max-w-[140px]" title="ดาวน์โหลดไฟล์สำรองเก็บเป็นหลักฐาน">
                                                        <i class="fas fa-download text-[10px]"></i> <?= htmlspecialchars($fileKey) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-gray-400 italic">ไม่มีไฟล์สำรอง</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-center font-semibold whitespace-nowrap text-[11px]">
                                                <span class="text-green-600" title="นำเข้าสำเร็จ"><?= $log['success_rows'] ?></span> /
                                                <span class="text-amber-500" title="ข้ามรายการซ้ำ"><?= $log['skipped_rows'] ?? 0 ?></span> /
                                                <span class="text-red-500" title="พบข้อผิดพลาด"><?= $log['error_rows'] ?></span>
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                <?php if ($isRollbacked): ?>
                                                    <span class="inline-block text-[10px] bg-gray-100 border text-gray-500 px-2 py-0.5 rounded-md font-bold">กู้คืนเวอร์ชันเรียบร้อย</span>
                                                <?php else: ?>
                                                    <button @click="rollbackBatch(<?= $log['id'] ?>)" class="px-2 py-1 bg-red-50 hover:bg-red-600 border border-red-100 text-red-600 hover:text-white rounded-lg font-bold text-[10px] transition">
                                                        <i class="fas fa-undo-alt"></i> กู้คืนรอบนี้
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($logPages > 1): ?>
                        <div class="px-4 py-3 border-t bg-slate-50/40 flex items-center justify-between text-xs">
                            <span class="text-gray-400">หน้า <?= $logPage ?> จากทั้งหมด <?= $logPages ?> หน้า</span>
                            <div class="flex items-center gap-0.5">
                                <a href="?lpage=<?= max(1, $logPage - 1) ?>" class="px-2 py-1 border bg-white rounded-md text-gray-500 <?= $logPage <= 1 ? 'pointer-events-none opacity-40' : '' ?>">‹</a>
                                <?php for ($i = 1; $i <= $logPages; $i++): ?>
                                    <a href="?lpage=<?= $i ?>" class="px-2.5 py-1 border rounded-md font-medium text-center <?= $i === $logPage ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white hover:bg-gray-50 text-gray-500' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <a href="?lpage=<?= min($logPages, $logPage + 1) ?>" class="px-2 py-1 border bg-white rounded-md text-gray-500 <?= $logPage >= $logPages ? 'pointer-events-none opacity-40' : '' ?>">›</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
<div id="toast-container"></div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
function importPage() {
    return {
        file: null,
        dragOver: false,
        duplicateMode: 'update',
        // ตั้งต้นผูกค่าสิทธิ์โรงงานตามระดับบัญชีผู้ใช้จริง
        selectedPlant: '<?= $userRole === "admin" ? "" : ($availablePlants[0]['plant_code'] ?? "") ?>',
        uploading: false,
        progress: 0,
        result: null,
        handleFileSelect(e) {
            const files = e.target.files;
            if (files.length > 0) { this.file = files[0]; this.result = null; }
        },
        handleDrop(e) {
            this.dragOver = false;
            const files = e.dataTransfer.files;
            if (files.length > 0) { this.file = files[0]; this.result = null; }
        },
        clearFile() {
            this.file = null;
            this.result = null;
            this.progress = 0;
        },
        uploadFile() {
            if (!this.file) return;
            if (!this.selectedPlant) {
                showToast('กรุณาระบุไซต์และรหัสโรงงานปลายทางที่รับผิดชอบอัปโหลดข้อมูล', 'error');
                return;
            }
            this.uploading = true;
            this.progress = 15;
            this.result = null;
            const formData = new FormData();
            formData.append('file', this.file);
            formData.append('duplicate_mode', this.duplicateMode);
            formData.append('plant_code', this.selectedPlant); // ส่งรหัสตรวจสอบยืนยันไปที่ API หลังบ้าน
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '<?= APP_URL ?>/api/import.php');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) this.progress = Math.round((e.loaded / e.total) * 85);
            };
            xhr.onload = () => {
                this.progress = 100;
                this.uploading = false;
                try {
                    const r = JSON.parse(xhr.responseText);
                    this.result = r;
                    if (r.success) {
                        showToast('นำเข้าข้อมูลความปลอดภัยแยกไซต์และบันทึกประวัติเรียบร้อย', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showToast(r.message || 'โครงสร้างไฟล์ขัดข้อง', 'error');
                    }
                } catch {
                    showToast('การประมวลผลปลายทางขัดข้อง', 'error');
                }
            };
            xhr.send(formData);
        },
        rollbackBatch(logId) {
            if (!confirm('ยืนยันประสงค์กู้คืนและถอยโครงสร้างเวอร์ชันของรอบการอัปโหลดนี้ใช่หรือไม่?\nข้อมูลทรัพย์สินที่ถูกเขียนทับในรอบนี้จะดึงประวัติ Snapshot กลับมาโดยไม่ทำลาย Auto ID ตารางอื่น')) return;
            const formData = new FormData();
            formData.append('action', 'rollback');
            formData.append('log_id', logId);
            fetch('<?= APP_URL ?>/api/import.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With', 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(r => {
                if (r.success) {
                    showToast('กู้คืนชุดเวอร์ชันเดิมเรียบร้อยแล้ว', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(r.message || 'ไม่สามารถทำรายการกู้คืนได้', 'error');
                }
            })
            .catch(() => showToast('เครือข่ายสัญญาณปลายทางขัดข้อง', 'error'));
        }
    }
}
</script>