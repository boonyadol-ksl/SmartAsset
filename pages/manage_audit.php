<?php
$pageTitle = 'สร้างรอบการตรวจนับ';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();
requireRole(['admin', 'webadmin']);
$db = Database::getInstance();
$plants     = $db->fetchAll("SELECT * FROM plants ORDER BY plant_code ASC");
$inspectors = $db->fetchAll("SELECT id, full_name, username FROM users WHERE role IN ('inventory','webadmin') AND is_active=1");
?>
<?php include __DIR__ . '/../components/head.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<div class="min-h-screen" x-data="auditCreator()">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>
    <main class="main-content flex-1 p-4 sm:p-6">
        <div class="mb-6">
            <h1 class="text-xl font-bold text-gray-900">สร้างรอบการตรวจนับใหม่</h1>
            <p class="text-sm text-gray-500">กำหนดเป้าหมาย % และแบ่งงานให้ทีมตรวจสอบแบบแยกโซน</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <!-- Section 1: ขอบเขต -->
                <div class="card p-6">
                    <h2 class="text-sm font-bold text-blue-600 mb-4 flex items-center">
                        <span class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center mr-2 text-xs">1</span>
                        ขอบเขตการตรวจนับ
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="form-label text-xs">เลือก Plant *</label>
                            <select x-model="setup.plant_code" @change="onPlantChange()" class="form-input">
                                <option value="">-- เลือก Plant --</option>
                                <?php foreach ($plants as $p): ?>
                                    <option value="<?= $p['plant_code'] ?>"><?= $p['plant_code'] ?> - <?= $p['plant_name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label text-xs">ปีการตรวจนับ *</label>
                            <input type="number" min="2000" max="3000" step="1"
                                x-model="setup.session_year"
                                @change="onYearChange()"
                                class="form-input"
                                placeholder="เช่น 2026 หรือ 2569">
                            <p class="text-[10px] text-gray-400 mt-1">หมายเหตุ: ให้กำหนดรูปแบบปีให้ตรงกับระบบฝั่ง API/ฐานข้อมูล</p>
                        </div>

                        <div>
                            <label class="form-label text-xs">เป้าหมายการตรวจ (%) *</label>
                            <div class="flex items-center gap-2">
                                <input type="number" x-model="setup.percent" @input="calculateTotal()" min="1" max="100" class="form-input" placeholder="1-100">
                                <span class="text-sm font-bold text-gray-500">%</span>
                            </div>
                        </div>

                        <div>
                            <label class="form-label text-xs">วันสิ้นสุดรอบ (Deadline) *</label>
                            <input type="date" x-model="setup.deadline_date" class="form-input">
                            <p class="text-[10px] text-gray-400 mt-1">ใช้สำหรับแสดงใน Dashboard/Inventory และควบคุมกำหนดส่งงาน</p>
                        </div>
                    </div>

                    <!-- Cost Center Selector + Legend -->
                    <div>
                        <label class="form-label text-xs mb-1 block">เลือก Cost Center</label>

                        <!-- Legend แสดงสถานะ CC -->
                        <div class="flex flex-wrap gap-3 text-[10px] mb-2">
                            <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>มีรายการคงเหลือ</span>
                            <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500 inline-block"></span>ตรวจครบแล้ว (100%)</span>
                            <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-orange-400 inline-block"></span>ถูกมอบหมายไปแล้วบางส่วน</span>
                        </div>

                        <div id="cc-select-wrapper">
                            <div class="mb-2">
                                <button type="button" id="btn-select-all">Select All</button>
                                <button type="button" id="btn-deselect-all">Deselect All</button>
                                <select id="cc-select" style="width:100%;" multiple="multiple"></select>
                            </div>
                            <p class="text-[10px] text-gray-500 mt-1">
                                เลือกแล้ว <span class="font-bold text-blue-600" x-text="setup.selectedCC.length"></span> Cost Center
                                (<span x-text="totalItemsInSelection.toLocaleString()"></span> รายการ Pending)
                                — ระบบจะแสดงเฉพาะที่ยังมีรายการ pending เท่านั้น
                            </p>

                            <!-- แสดง CC ที่หมดแล้ว (ซ่อน/แสดง) -->
                            <template x-if="completedCCs.length > 0">
                                <div class="mt-2">
                                    <button type="button" @click="showCompletedCC=!showCompletedCC"
                                        class="text-[10px] text-gray-400 underline flex items-center gap-1">
                                        <i class="fas" :class="showCompletedCC?'fa-chevron-up':'fa-chevron-down'"></i>
                                        Cost Center ที่ตรวจครบแล้ว (<span x-text="completedCCs.length"></span> รายการ) — ไม่แสดงในการมอบหมาย
                                    </button>
                                    <div x-show="showCompletedCC" x-cloak class="mt-1 flex flex-wrap gap-1">
                                        <template x-for="cc in completedCCs" :key="cc.cost_center">
                                            <span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-[10px] font-medium">
                                                <i class="fas fa-check-circle mr-0.5"></i>
                                                <span x-text="cc.cost_center"></span>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-100">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" x-model="setup.excludeChecked" class="mr-2">
                                <span class="text-xs text-blue-800 font-medium">สุ่มเฉพาะรายการที่ "ยังไม่เคยถูกตรวจ" ในปีที่เลือก</span>
                            </label>
                        </div>
                    </div>

                    <!-- Section 2: มอบหมายทีม -->
                    <div class="card p-6">
                        <h2 class="text-sm font-bold text-blue-600 mb-4 flex items-center">
                            <span class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center mr-2 text-xs">2</span>
                            มอบหมายทีมผู้ตรวจนับ
                        </h2>

                        <!-- Workload ปัจจุบัน -->
                        <template x-if="setup.plant_code && userWorkload.length > 0">
                            <div class="mb-4 p-3 bg-gray-50 rounded-lg border border-gray-200">
                                <p class="text-[11px] font-bold text-gray-600 mb-2">
                                    📋 งานที่มีอยู่แล้วในปีนี้ (Plant: <span x-text="setup.plant_code"></span>, ปี: <span x-text="setup.session_year"></span>)
                                </p>
                                <div class="space-y-1">
                                    <template x-for="uw in userWorkload" :key="uw.user_id">
                                        <div class="flex items-start gap-2 text-xs">
                                            <span class="font-medium text-gray-700 w-28 shrink-0" x-text="uw.full_name"></span>
                                            <div class="flex flex-wrap gap-1">
                                                <template x-for="s in uw.sessions" :key="s.session_id">
                                                    <span class="px-2 py-0.5 rounded-full bg-white border border-gray-300 text-[10px]"
                                                        :class="s.pending>0?'border-orange-300 text-orange-700':'text-gray-500'">
                                                        <span x-text="s.session_name"></span> >
                                                        <span x-text="s.cost_centers"></span> >
                                                        <span class="font-bold" x-text="s.pending"></span> pending /
                                                        <span x-text="s.completed"></span> done
                                                        <span class="ml-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded border"
                                                            :class="deadlineMeta(s.deadline_date).cls"
                                                            :title="s.deadline_date ? ('Deadline: ' + s.deadline_date) : 'ยังไม่ได้กำหนด Deadline'">
                                                            <span x-text="deadlineMeta(s.deadline_date).label"></span>
                                                            <template x-if="deadlineMeta(s.deadline_date).sub">
                                                                <span class="opacity-80" x-text="'(' + deadlineMeta(s.deadline_date).sub + ')'"></span>
                                                            </template>
                                                        </span>
                                                    </span>
                                                </template>
                                                <span class="px-2 py-0.5 rounded-full bg-orange-100 border border-orange-300 text-[10px] font-bold text-orange-800"
                                                    x-show="uw.total_pending>0">
                                                    รวม pending: <span x-text="uw.total_pending"></span>
                                                </span>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">
                            <?php foreach ($inspectors as $user): ?>
                                <label class="flex items-center p-2 border rounded-lg hover:border-blue-300 transition cursor-pointer"
                                    :class="setup.selectedUsers.includes('<?= $user['id'] ?>') ? 'bg-blue-50 border-blue-400' : 'bg-white'">
                                    <input type="checkbox" value="<?= $user['id'] ?>" x-model="setup.selectedUsers" class="mr-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-bold truncate"><?= htmlspecialchars($user['full_name']) ?></div>
                                        <div class="text-[10px] text-gray-500"><?= htmlspecialchars($user['username']) ?></div>
                                        <template x-for="uw in userWorkload.filter(u=>u.user_id==<?= $user['id'] ?>)" :key="uw.user_id">
                                            <div x-show="uw.total_pending>0" class="text-[10px] text-orange-600 font-semibold">
                                                มี <span x-text="uw.total_pending"></span> pending
                                            </div>
                                        </template>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Section 3: ยกเลิก Assignment (Pending) -->
                    <div class="card p-6">
                        <h2 class="text-sm font-bold text-red-500 mb-1 flex items-center">
                            <span class="w-6 h-6 bg-red-100 rounded-full flex items-center justify-center mr-2 text-xs text-red-500">✕</span>
                            ยกเลิกงานที่มอบหมาย (เฉพาะ Pending)
                        </h2>
                        <p class="text-[11px] text-gray-400 mb-4">ยกเลิกได้เฉพาะรายการที่ยังไม่ถูกตรวจ (pending) เท่านั้น รายการที่ตรวจแล้วจะไม่ถูกกระทบ</p>

                        <template x-if="!setup.plant_code">
                            <p class="text-xs text-gray-400 italic">กรุณาเลือก Plant ก่อน</p>
                        </template>
                        <template x-if="setup.plant_code && cancelData.length === 0 && !cancelLoading">
                            <p class="text-xs text-gray-400 italic">ไม่มีรายการ pending ที่สามารถยกเลิกได้</p>
                        </template>
                        <template x-if="cancelLoading">
                            <p class="text-xs text-blue-500 flex items-center gap-2"><span class="spinner border-2 w-3 h-3"></span>กำลังโหลด...</p>
                        </template>

                        <template x-if="cancelData.length > 0">
                            <div class="space-y-3">
                                <template x-for="row in cancelData" :key="row.user_id">
                                    <div class="border rounded-lg p-3 bg-gray-50">
                                        <div class="flex items-center justify-between mb-2">
                                            <div>
                                                <span class="font-bold text-sm" x-text="row.full_name"></span>
                                                <span class="ml-2 text-[10px] text-orange-600 font-semibold bg-orange-50 px-2 py-0.5 rounded-full">
                                                    <span x-text="row.pending_count"></span> pending
                                                </span>
                                            </div>
                                            <button type="button"
                                                @click="cancelUserPending(row.user_id, row.full_name, row.session_id)"
                                                :disabled="cancellingUserId === row.user_id"
                                                class="btn btn-xs bg-red-100 text-red-600 hover:bg-red-200 border border-red-200">
                                                <template x-if="cancellingUserId !== row.user_id">
                                                    <span><i class="fas fa-times mr-1"></i>ยกเลิก Pending ทั้งหมด</span>
                                                </template>
                                                <template x-if="cancellingUserId === row.user_id">
                                                    <span class="spinner border-2 w-3 h-3"></span>
                                                </template>
                                            </button>
                                        </div>
                                        <!-- Cost Centers ที่ยังค้าง -->
                                        <div class="flex flex-wrap gap-1">
                                            <template x-for="cc in row.cost_centers" :key="cc">
                                                <span class="px-2 py-0.5 rounded-full bg-orange-100 text-orange-700 text-[10px]" x-text="cc"></span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <!-- Section 4: ผลลัพธ์หลังสร้าง -->
                    <template x-if="lastResult">
                        <div class="card p-6">
                            <h2 class="text-sm font-bold text-green-600 mb-4 flex items-center">
                                <span class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center mr-2 text-xs">✓</span>
                                ผลการกระจายงาน
                            </h2>
                            <div class="mb-3 text-xs text-gray-600">
                                รวมทั้งหมด <span class="font-bold" x-text="lastResult.total_assets"></span> รายการ |
                                เพิ่มใหม่ <span class="font-bold text-green-600" x-text="lastResult.total_added"></span> รายการ
                                <template x-if="lastResult.total_skipped > 0">
                                    <span> | ข้ามซ้ำ <span class="font-bold text-yellow-600" x-text="lastResult.total_skipped"></span> รายการ</span>
                                </template>
                            </div>
                            <div class="space-y-2">
                                <template x-for="u in lastResult.user_summary" :key="u.user_id">
                                    <div class="flex items-center justify-between p-2 rounded-lg bg-gray-50 border text-xs">
                                        <div>
                                            <span class="font-bold" x-text="u.name"></span>
                                            <template x-if="u.existing > 0">
                                                <span class="ml-2 text-gray-500">(มีเดิม <span x-text="u.existing"></span> รายการ)</span>
                                            </template>
                                        </div>
                                        <div class="flex gap-2">
                                            <span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-bold">+<span x-text="u.added"></span> ใหม่</span>
                                            <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-bold">รวม <span x-text="u.total"></span></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Sidebar: สรุป -->
            <div class="space-y-6">
                <div class="card p-6 sticky top-6">
                    <h2 class="text-sm font-bold text-gray-900 mb-4">สรุปการสร้างงาน</h2>
                    <div class="space-y-3 mb-6 text-sm">
                        <div class="flex justify-between border-b pb-2">
                            <span class="text-gray-500">Plant:</span>
                            <span class="font-bold" x-text="setup.plant_code||'-'"></span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="text-gray-500">ปี:</span>
                            <span class="font-bold" x-text="setup.session_year||'-'"></span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="text-gray-500">Deadline:</span>
                            <span class="font-bold inline-flex items-center gap-1">
                                <span x-text="setup.deadline_date||'-'"></span>
                                <template x-if="setup.deadline_date">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded border text-[10px]"
                                        :class="deadlineMeta(setup.deadline_date).cls">
                                        <span x-text="deadlineMeta(setup.deadline_date).label"></span>
                                        <template x-if="deadlineMeta(setup.deadline_date).sub">
                                            <span class="opacity-80 ml-1" x-text="deadlineMeta(setup.deadline_date).sub"></span>
                                        </template>
                                    </span>
                                </template>
                            </span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="text-gray-500">Cost Center (มี pending):</span>
                            <span class="font-bold" x-text="setup.selectedCC.length"></span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="text-gray-500">สุ่มตรวจ:</span>
                            <span class="font-bold text-blue-600" x-text="setup.percent+'%'"></span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="text-gray-500">จำนวนผู้ตรวจ:</span>
                            <span class="font-bold text-orange-600" x-text="setup.selectedUsers.length+' คน'"></span>
                        </div>
                    </div>

                    <div class="bg-gray-900 text-white p-4 rounded-xl mb-6 text-center">
                        <div class="text-[10px] uppercase tracking-wider text-gray-400 mb-1">ประมาณการรายการต่อคน</div>
                        <div class="text-2xl font-bold" x-text="estimatedPerPerson()"></div>
                        <div class="text-[10px] text-gray-400 mt-1">รายการ/คน (โดยประมาณ)</div>
                    </div>

                    <!-- Warning: assign ซ้ำ -->
                    <template x-if="duplicateWarning">
                        <div class="bg-red-50 border-l-4 border-red-400 p-3 mb-4 rounded">
                            <div class="flex gap-2">
                                <i class="fas fa-ban text-red-500 mt-0.5"></i>
                                <p class="text-xs text-red-700 font-medium" x-text="duplicateWarning"></p>
                            </div>
                        </div>
                    </template>

                    <div x-show="hasWarning()" class="bg-yellow-100 border-l-4 border-yellow-400 p-4 mb-6">
                        <div class="flex">
                            <i class="fas fa-exclamation-triangle text-yellow-400 mr-2 mt-0.5"></i>
                            <p class="text-sm text-yellow-700" x-text="hasWarning()"></p>
                        </div>
                    </div>

                    <button @click="createAuditSession()"
                        :disabled="!isValid() || loading || !!duplicateWarning"
                        class="btn btn-primary w-full py-3 shadow-lg shadow-blue-600/20 flex items-center justify-center gap-2"
                        :class="duplicateWarning ? 'opacity-50 cursor-not-allowed' : ''">
                        <template x-if="loading"><i class="fas fa-spinner fa-spin"></i></template>
                        <span>สร้างรอบการตรวจนับ</span>
                    </button>

                    <p class="text-[10px] text-gray-400 text-center mt-2">
                        ระบบจะไม่มอบหมายรายการซ้ำเด็ดขาด
                    </p>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
    async function apiPost(url, data) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            return { success: false, message: 'การเชื่อมต่อเซิร์ฟเวอร์ล้มเหลว' };
        }
    }

    function showToast(message, type = 'success') {
        alert(message);
        console.log(`${type.toUpperCase()}: ${message}`);
    }

    function auditCreator() {
        return {
            loading: false,
            costCenters: [], // CC ที่ยังมี pending
            completedCCs: [], // CC ที่หมดแล้ว (ตรวจครบ 100%)
            showCompletedCC: false,
            totalItemsInSelection: 0,
            userWorkload: [],
            lastResult: null,
            duplicateWarning: null, // แจ้งเตือนถ้าจะมอบหมายซ้ำ

            // cancel section
            cancelData: [],
            cancelLoading: false,
            cancellingUserId: null,

            setup: {
                plant_code: '',
                session_year: (new Date()).getFullYear(),
                deadline_date: '',
                percent: 10,
                selectedCC: [],
                selectedUsers: [],
                excludeChecked: true
            },

            // ===== Deadline badge helpers =====
            deadlineMeta(deadlineDate) {
                // deadlineDate: 'YYYY-MM-DD' | null | ''
                if (!deadlineDate) {
                    return {
                        label: 'ไม่กำหนด',
                        sub: '',
                        cls: 'bg-gray-100 text-gray-700 border-gray-200'
                    };
                }
                const today = new Date();
                const t0 = new Date(today.getFullYear(), today.getMonth(), today.getDate()); // midnight
                const d0 = new Date(deadlineDate + 'T00:00:00');
                const daysLeft = Math.round((d0 - t0) / (1000 * 60 * 60 * 24));

                if (daysLeft < 0) {
                    return {
                        label: 'เกินกำหนด',
                        sub: `เกิน ${Math.abs(daysLeft)} วัน`,
                        cls: 'bg-red-100 text-red-700 border-red-200'
                    };
                }
                if (daysLeft <= 7) {
                    return {
                        label: 'ใกล้ครบกำหนด',
                        sub: `เหลือ ${daysLeft} วัน`,
                        cls: 'bg-orange-100 text-orange-800 border-orange-200'
                    };
                }
                return {
                    label: 'ตามกำหนด',
                    sub: `เหลือ ${daysLeft} วัน`,
                    cls: 'bg-green-100 text-green-700 border-green-200'
                };
            },

            async onYearChange() {
                // ถ้ายังไม่เลือก plant ให้แค่ reset duplicate / summary
                this.setup.selectedCC = [];
                this.totalItemsInSelection = 0;
                this.lastResult = null;
                this.duplicateWarning = null;

                const $sel = $('#cc-select');
                if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
                $sel.empty();

                if (!this.setup.plant_code || !this.setup.session_year) return;
                await Promise.all([
                    this.fetchCostCenters(),
                    this.fetchUserWorkload(),
                    this.fetchCancelData()
                ]);
            },

            async onPlantChange() {
                this.setup.selectedCC = [];
                this.costCenters = [];
                this.completedCCs = [];
                this.totalItemsInSelection = 0;
                this.userWorkload = [];
                this.lastResult = null;
                this.duplicateWarning = null;
                this.cancelData = [];

                const $sel = $('#cc-select');
                if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
                $sel.empty();

                if (!this.setup.plant_code || !this.setup.session_year) return;
                await Promise.all([
                    this.fetchCostCenters(),
                    this.fetchUserWorkload(),
                    this.fetchCancelData()
                ]);
            },

            async fetchCostCenters() {
                const res = await fetch(`<?= APP_URL ?>/api/audit_helper.php?action=get_cc&plant=${encodeURIComponent(this.setup.plant_code)}&year=${encodeURIComponent(this.setup.session_year)}`)
                    .then(r => r.json()).catch(() => ({ success: false }));
                if (!res.success) return;

                // แยก pending vs completed
                this.costCenters = (res.data || []).filter(i => parseInt(i.pending_count) > 0);
                this.completedCCs = (res.completed || []); // API ส่ง completed แยกมา

                // fallback: ถ้า API เก่าไม่มี completed ให้กรองจาก pending_count === 0
                if (!res.completed && res.data) {
                    this.completedCCs = res.data.filter(i => parseInt(i.pending_count) === 0);
                }

                const $sel = $('#cc-select');
                $('#btn-select-all').on('click', function() {
                    const allValues = $('#cc-select option').map(function() { return this.value; }).get();
                    $('#cc-select').val(allValues).trigger('change');
                });
                $('#btn-deselect-all').on('click', function() {
                    $('#cc-select').val(null).trigger('change');
                });

                // ทำลาย instance เก่าก่อน
                if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
                $sel.empty();
                $sel.select2({
                    placeholder: "เลือก Cost Center...",
                    width: '100%',
                    multiple: true,
                    data: this.costCenters.map(i => ({
                        id: i.cost_center,
                        text: `${i.cost_center} (${i.pending_count} pending)`
                    })),
                    // ปรับแต่งหน้าตาให้มี Checkbox
                    templateResult: function(data, container) {
                        if (!data.id) return data.text;
                        const isSelected = $(data.element).is(':selected');
                        return $(`<span><input type="checkbox" ${isSelected ? 'checked' : ''} style="margin-right:8px;"> ${data.text}</span>`);
                    }
                });

                // ซ่อนรายการที่เลือกแล้ว (ถ้าต้องการพฤติกรรมเดิม)
                $sel.on('select2:open', function (e) {
                    setTimeout(() => {
                        const selectedValues = $sel.val() || [];
                        $('.select2-results__option').each(function() {
                            const $option = $(this);
                            const data = $option.data('data');
                            if (data && selectedValues.includes(data.id.toString())) $option.hide();
                            else $option.show();
                        });
                    }, 0);
                });

                // ดัก Event เพื่ออัปเดตสถานะเมื่อมีการเลือก/ยกเลิก
                $sel.on('select2:select select2:unselect', function(e) {
                    $(this).select2('close');
                    $(this).select2('open');
                });

                const self = this;
                $sel.off('change.audit').on('change.audit', function() {
                    self.setup.selectedCC = $(this).val() || [];
                    self.calculateTotal();
                    self.checkDuplicate();
                });
            },

            async fetchUserWorkload() {
                if (!this.setup.plant_code || !this.setup.session_year) return;
                const res = await fetch(`<?= APP_URL ?>/api/audit_helper.php?action=get_user_workload&plant=${encodeURIComponent(this.setup.plant_code)}&year=${encodeURIComponent(this.setup.session_year)}`)
                    .then(r => r.json()).catch(() => ({ success: false }));
                if (res.success) this.userWorkload = res.data;
            },

            // โหลดรายการ pending ที่มอบหมายไปแล้ว (สำหรับยกเลิก)
            async fetchCancelData() {
                if (!this.setup.plant_code || !this.setup.session_year) return;
                this.cancelLoading = true;
                const res = await fetch(`<?= APP_URL ?>/api/audit_helper.php?action=get_pending_assignments&plant=${encodeURIComponent(this.setup.plant_code)}&year=${encodeURIComponent(this.setup.session_year)}`)
                    .then(r => r.json()).catch(() => ({ success: false }));
                this.cancelLoading = false;
                if (res.success) this.cancelData = res.data || [];
            },

            // ยกเลิก pending ของ user
            async cancelUserPending(userId, name, sessionId) {
                if (!confirm(`ยืนยันการยกเลิก pending ทั้งหมดของ "${name}"?\n(เฉพาะรายการที่ยังไม่ได้ตรวจ)`)) return;
                this.cancellingUserId = userId;
                const res = await apiPost('<?= APP_URL ?>/api/audit_sessions.php', {
                    action: 'cancel_pending',
                    user_id: userId,
                    session_id: sessionId,
                    plant_code: this.setup.plant_code,
                    session_year: Number(this.setup.session_year)
                });
                this.cancellingUserId = null;
                if (res.success) {
                    showToast(res.message || 'ยกเลิกสำเร็จ', 'success');
                    await Promise.all([
                        this.fetchCostCenters(),
                        this.fetchUserWorkload(),
                        this.fetchCancelData()
                    ]);
                    this.setup.selectedCC = [];
                    this.totalItemsInSelection = 0;
                } else {
                    showToast(res.message || 'เกิดข้อผิดพลาด', 'error');
                }
            },

            calculateTotal() {
                this.totalItemsInSelection = this.costCenters
                    .filter(cc => this.setup.selectedCC.includes(cc.cost_center))
                    .reduce((sum, cc) => sum + parseInt(cc.pending_count), 0);
            },

            // ตรวจสอบว่ามี CC ที่ถูก assign ไปแล้วหรือไม่
            checkDuplicate() {
                if (this.setup.selectedCC.length === 0) {
                    this.duplicateWarning = null;
                    return;
                }
                const assignedCCs = this.cancelData.flatMap(u => u.cost_centers || []);
                const overlap = this.setup.selectedCC.filter(cc => assignedCCs.includes(cc));
                if (overlap.length > 0) {
                    this.duplicateWarning = `⚠️ Cost Center: ${overlap.join(', ')} มี Pending ถูกมอบหมายอยู่แล้ว กรุณายกเลิก Pending ก่อนมอบหมายใหม่`;
                } else {
                    this.duplicateWarning = null;
                }
            },

            estimatedPerPerson() {
                if (this.setup.selectedUsers.length === 0 || this.totalItemsInSelection === 0) return 0;
                const total = Math.ceil(this.totalItemsInSelection * (this.setup.percent / 100));
                return Math.ceil(total / this.setup.selectedUsers.length).toLocaleString();
            },

            isValid() {
                return this.setup.plant_code &&
                    this.setup.session_year &&
                    this.setup.deadline_date &&
                    this.setup.selectedCC.length > 0 &&
                    this.setup.selectedUsers.length > 0 &&
                    this.setup.percent > 0;
            },

            async createAuditSession() {
                if (this.duplicateWarning) {
                    alert('ไม่สามารถสร้างได้: มีรายการซ้ำ กรุณายกเลิก Pending ก่อน');
                    return;
                }
                if (!confirm('ยืนยันการสร้างรอบการตรวจนับและกระจายงานให้พนักงาน?\n(ระบบจะไม่มอบหมายรายการซ้ำเด็ดขาด)')) return;
                this.loading = true;
                this.lastResult = null;

                const payload = {
                    action: 'create_and_assign',
                    plant_code: this.setup.plant_code,
                    session_year: Number(this.setup.session_year),
                    deadline_date: this.setup.deadline_date,
                    percent: Number(this.setup.percent),
                    selectedCC: [...this.setup.selectedCC],
                    selectedUsers: [...this.setup.selectedUsers],
                    excludeChecked: this.setup.excludeChecked,
                };

                const res = await apiPost('<?= APP_URL ?>/api/audit_sessions.php', payload);
                this.loading = false;
                if (res.success) {
                    showToast(res.message, 'success');
                    this.lastResult = res;
                    await Promise.all([
                        this.fetchUserWorkload(),
                        this.fetchCostCenters(),
                        this.fetchCancelData()
                    ]);
                    this.setup.selectedCC = [];
                    this.totalItemsInSelection = 0;
                    this.duplicateWarning = null;
                } else {
                    showToast(res.message || 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ', 'error');
                }
            },

            hasWarning() {
                if (!this.setup.excludeChecked) {
                    return "คำเตือน: คุณไม่ได้เลือก 'สุ่มเฉพาะรายการที่ยังไม่เคยตรวจ' อาจทำให้ได้รายการซ้ำ";
                }
                return null;
            }
        };
    }
</script>
</body>
</html>