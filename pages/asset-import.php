<?php
$pageTitle = 'นำเข้าทรัพย์สิน';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();
requireRole(['admin']);

$db = Database::getInstance();
$logPage   = max(1, intval($_GET['lpage'] ?? 1));
$logLimit  = 10;
$logOffset = ($logPage - 1) * $logLimit;
$logTotal  = $db->fetchOne("SELECT COUNT(*) as c FROM import_logs")['c'];
$logPages  = max(1, ceil($logTotal / $logLimit));
$importLogs = $db->fetchAll("SELECT il.*, u.full_name FROM import_logs il LEFT JOIN users u ON il.imported_by=u.id ORDER BY il.id DESC LIMIT $logLimit OFFSET $logOffset");
?>
<?php include __DIR__ . '/../components/head.php'; ?>

<div class="min-h-screen" x-data="importPage()">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>

    <main class="main-content flex-1 p-4 sm:p-6">
        <div class="mb-5">
            <h1 class="text-xl font-bold text-gray-900">นำเข้าทรัพย์สิน</h1>
            <p class="text-sm text-gray-500 mt-0.5">อัปโหลดไฟล์ Excel หรือ CSV เพื่อนำเข้าทรัพย์สินจำนวนมาก</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            <!-- Upload Card -->
            <div class="lg:col-span-2 space-y-4">
                <!-- Template Download -->
                <div class="card p-5">
                    <h3 class="font-bold text-gray-900 text-sm mb-3">ขั้นตอนการนำเข้า</h3>
                    <div class="space-y-2 text-sm text-gray-600">
                        <div class="flex items-start gap-3">
                            <span class="w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">1</span>
                            <p>ดาวน์โหลด Template และกรอกข้อมูลทรัพย์สิน</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">2</span>
                            <p>บันทึกไฟล์เป็นรูปแบบ <strong>.xlsx</strong> หรือ <strong>.csv</strong></p>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">3</span>
                            <p>เลือกไฟล์และกดปุ่ม "นำเข้าข้อมูล"</p>
                        </div>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <a href="<?= APP_URL ?>/api/download-template.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-download text-green-600"></i> ดาวน์โหลด Template CSV
                        </a>
                    </div>
                </div>

                <!-- Upload Zone -->
                <div class="card p-5">
                    <h3 class="font-bold text-gray-900 text-sm mb-4">อัปโหลดไฟล์</h3>

                    <div
                        class="border-2 border-dashed rounded-xl p-8 text-center transition-colors cursor-pointer"
                        :class="dragOver ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300 bg-gray-50'"
                        @dragover.prevent="dragOver = true"
                        @dragleave.prevent="dragOver = false"
                        @drop.prevent="handleDrop($event)"
                        @click="$refs.fileInput.click()">
                        <i class="fas fa-file-excel text-4xl text-green-500 mb-3 block"></i>
                        <p class="font-semibold text-gray-700 text-sm">ลากไฟล์มาวาง หรือ คลิกเพื่อเลือกไฟล์</p>
                        <p class="text-xs text-gray-400 mt-1">รองรับ .xlsx, .xls, .csv (ขนาดสูงสุด 10MB)</p>
                        <input type="file" x-ref="fileInput" accept=".xlsx,.xls,.csv" class="hidden" @change="handleFile($event)">
                    </div>

                    <!-- Selected File -->
                    <div x-show="selectedFile" class="mt-4 flex items-center gap-3 p-3 bg-blue-50 rounded-xl border border-blue-100">
                        <i class="fas fa-file-excel text-green-600 text-xl"></i>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate" x-text="selectedFile?.name"></p>
                            <p class="text-xs text-gray-500" x-text="formatFileSize(selectedFile?.size)"></p>
                        </div>
                        <button @click="selectedFile = null" class="text-gray-400 hover:text-red-500 transition-colors">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <!-- Progress -->
                    <div x-show="uploading" class="mt-4">
                        <div class="flex justify-between text-xs text-gray-500 mb-1">
                            <span>กำลังนำเข้า...</span>
                            <span x-text="progress + '%'"></span>
                        </div>
                        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-2 bg-blue-600 rounded-full transition-all duration-300"
                                 :style="'width:' + progress + '%'"></div>
                        </div>
                    </div>

                    <!-- Result -->
                    <div x-show="result" class="mt-4 p-4 rounded-xl"
                         :class="result?.success ? 'bg-green-50 border border-green-100' : 'bg-red-50 border border-red-100'">
                        <div class="flex items-start gap-2">
                            <i :class="result?.success ? 'fas fa-check-circle text-green-600' : 'fas fa-exclamation-circle text-red-600'" class="mt-0.5"></i>
                            <div class="text-sm">
                                <p class="font-semibold" :class="result?.success ? 'text-green-800' : 'text-red-800'" x-text="result?.message"></p>
                                <template x-if="result?.data">
                                    <p class="text-xs mt-1 text-gray-600">
                                        ✅ สำเร็จ <strong x-text="result.data.success_rows"></strong> แถว
                                        <template x-if="result.data.skipped_rows > 0">
                                            <span> &nbsp;⏩ ข้าม <strong x-text="result.data.skipped_rows"></strong> แถว</span>
                                        </template>
                                        <template x-if="result.data.error_rows > 0">
                                            <span> &nbsp;❌ ผิดพลาด <strong x-text="result.data.error_rows"></strong> แถว</span>
                                        </template>
                                    </p>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Duplicate Mode -->
                    <div class="mt-4 p-4 bg-amber-50 border border-amber-100 rounded-xl">
                        <label class="block text-xs font-semibold text-amber-800 mb-2">
                            <i class="fas fa-copy mr-1"></i> เมื่อพบ Asset No. ซ้ำในระบบ
                        </label>
                        <select x-model="duplicateMode" class="form-input text-sm">
                            <option value="update">♻️ อัปเดตข้อมูลทับ (Overwrite)</option>
                            <option value="skip">⏩ ข้ามรายการซ้ำ (Skip)</option>
                            <option value="new_only">✨ นำเข้าเฉพาะรายการใหม่เท่านั้น</option>
                        </select>
                        <p class="text-xs text-amber-700 mt-1.5">
                            <template x-if="duplicateMode === 'update'"><span>รายการที่มีอยู่แล้วจะถูก<strong>อัปเดตข้อมูลทับ</strong>ด้วยข้อมูลจากไฟล์</span></template>
                            <template x-if="duplicateMode === 'skip'"><span>รายการที่มีอยู่แล้วจะ<strong>ถูกข้าม</strong> ไม่มีการเปลี่ยนแปลงข้อมูลเดิม</span></template>
                            <template x-if="duplicateMode === 'new_only'"><span>นำเข้า<strong>เฉพาะรายการใหม่</strong>ที่ยังไม่มีในระบบเท่านั้น</span></template>
                        </p>
                    </div>

                    <button @click="uploadFile" x-show="selectedFile && !uploading"
                            class="btn btn-primary w-full mt-4" :disabled="!selectedFile">
                        <i class="fas fa-file-import"></i> นำเข้าข้อมูล
                    </button>
                </div>
            </div>

            <!-- Import History -->
            <div>
                <div class="card">
                    <div class="px-4 py-3 border-b border-gray-100">
                        <h3 class="font-bold text-gray-900 text-sm">ประวัตินำเข้า</h3>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <?php if (empty($importLogs)): ?>
                        <div class="p-6 text-center text-gray-400 text-sm">ยังไม่มีประวัตินำเข้า</div>
                        <?php else: ?>
                        <?php foreach ($importLogs as $log): ?>
                        <div class="p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-xs font-medium text-gray-900 truncate"><?= htmlspecialchars($log['filename']) ?></p>
                                    <p class="text-xs text-gray-400 mt-0.5">
                                        <?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>
                                        · <?= htmlspecialchars($log['full_name'] ?? 'Unknown') ?>
                                    </p>
                                    <p class="text-xs mt-0.5">
                                        <span class="text-green-600">+<?= $log['success_rows'] ?></span>
                                        <?php if ($log['error_rows'] > 0): ?>
                                        / <span class="text-red-500"><?= $log['error_rows'] ?> error</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php
                                $lmap = ['completed'=>'badge-active','failed'=>'badge-cancelled','processing'=>'badge-transfer'];
                                $llabel = ['completed'=>'สำเร็จ','failed'=>'ล้มเหลว','processing'=>'กำลังดำเนินการ'];
                                ?>
                                <span class="badge <?= $lmap[$log['status']] ?? '' ?> text-xs flex-shrink-0"><?= $llabel[$log['status']] ?? $log['status'] ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($logPages > 1): ?>
                    <div class="flex items-center justify-between px-3 py-2 border-t border-gray-100">
                        <p class="text-xs text-gray-400"><?= $logOffset + 1 ?>–<?= min($logOffset + $logLimit, $logTotal) ?> / <?= $logTotal ?></p>
                        <div class="pagination">
                            <?php if ($logPage > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['lpage' => $logPage - 1])) ?>" class="page-btn">‹</a>
                            <?php endif; ?>
                            <?php for ($p = max(1, $logPage - 2); $p <= min($logPages, $logPage + 2); $p++): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['lpage' => $p])) ?>" class="page-btn <?= $p === $logPage ? 'active' : '' ?>"><?= $p ?></a>
                            <?php endfor; ?>
                            <?php if ($logPage < $logPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['lpage' => $logPage + 1])) ?>" class="page-btn">›</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Field Mapping Guide -->
                <div class="card mt-4 p-4">
                    <h3 class="font-bold text-gray-900 text-sm mb-3">คอลัมน์ที่รองรับ</h3>
                    <div class="space-y-1">
                        <?php
                        $fields = [
                            'plant_code' => 'Plant *',
                            'asset_no' => 'Asset No *',
                            'class_code' => 'Class Code',
                            'asset_description' => 'Description *',
                            'cap_date' => 'Cap Date (YYYY-MM-DD)',
                            'acquis_val' => 'Acquis Value',
                            'cost_center' => 'Cost Center',
                            'department_code' => 'Dept Code',
                            'department_name' => 'Dept Name',
                            'municipality' => 'Municipality',
                            'serial_no' => 'Serial No',
                            'brand' => 'Brand',
                            'model' => 'Model',
                            'status' => 'Status',
                            'remark' => 'Remark',
                        ];
                        foreach ($fields as $k => $v):
                        ?>
                        <div class="flex items-center justify-between py-0.5">
                            <span class="text-xs font-mono text-blue-700"><?= $k ?></span>
                            <span class="text-xs text-gray-500"><?= $v ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
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
        dragOver: false,
        selectedFile: null,
        uploading: false,
        progress: 0,
        result: null,
        duplicateMode: 'update',

        handleFile(e) {
            const f = e.target.files[0];
            if (f) { this.selectedFile = f; this.result = null; }
        },
        handleDrop(e) {
            this.dragOver = false;
            const f = e.dataTransfer.files[0];
            if (f) { this.selectedFile = f; this.result = null; }
        },
        formatFileSize(bytes) {
            if (!bytes) return '';
            const units = ['B','KB','MB','GB'];
            let i = 0;
            while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
            return bytes.toFixed(1) + ' ' + units[i];
        },
        async uploadFile() {
            if (!this.selectedFile) return;
            this.uploading = true;
            this.progress = 0;
            this.result = null;

            const fd = new FormData();
            fd.append('file', this.selectedFile);
            fd.append('duplicate_mode', this.duplicateMode);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '<?= APP_URL ?>/api/import.php');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) this.progress = Math.round(e.loaded / e.total * 80);
            };
            xhr.onload = () => {
                this.progress = 100;
                this.uploading = false;
                try {
                    const r = JSON.parse(xhr.responseText);
                    this.result = r;
                    if (r.success) {
                        const msg = `นำเข้าสำเร็จ ${r.data?.success_rows || 0} รายการ` + 
                                    (r.data?.skipped_rows > 0 ? ` (ข้าม ${r.data.skipped_rows} รายการ)` : '');
                        showToast(msg, 'success');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showToast(r.message || 'นำเข้าล้มเหลว', 'error');
                    }
                } catch {
                    this.result = { success: false, message: 'เกิดข้อผิดพลาดในการอ่านผลลัพธ์' };
                }
            };
            xhr.onerror = () => {
                this.uploading = false;
                this.result = { success: false, message: 'เกิดข้อผิดพลาดในการเชื่อมต่อ' };
            };
            xhr.send(fd);
        }
    };
}
</script>

</body>
</html>