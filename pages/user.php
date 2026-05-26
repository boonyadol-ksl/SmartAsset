<?php
$pageTitle = 'จัดการผู้ใช้งาน';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();
requireRole(['admin', 'webadmin']);

$db = Database::getInstance();
$me = currentUser();
$mySiteId = (int)($me['site_id'] ?? 0);

// เดิมดึงจาก assets เพื่อทำ dropdown แผนก (คงไว้ตามของเดิม)
$depts = $db->fetchAll("SELECT DISTINCT department_code, department_name FROM assets ORDER BY department_name");
?>
<?php include __DIR__ . '/../components/head.php'; ?>

<div class="min-h-screen" x-data="userManagement()" x-init="init()">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>

    <main class="main-content flex-1 p-4 sm:p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h1 class="text-xl font-bold text-gray-900">จัดการผู้ใช้งานระบบ</h1>
                <p class="text-sm text-gray-500">
                    สิทธิ์: <span class="font-bold text-blue-600"><?= htmlspecialchars($me['role']) ?></span>
                    <template x-if="myRole==='webadmin'">
                        <span class="ml-2 text-xs text-gray-400">(จัดการได้เฉพาะ Site ของตัวเอง)</span>
                    </template>
                </p>
            </div>
            <button @click="openAddModal()" class="btn btn-primary btn-sm">
                <i class="fas fa-user-plus mr-1"></i> เพิ่มผู้ใช้งาน
            </button>
        </div>

        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>Email</th>
                            <th>Site</th>
                            <th>แผนก</th>
                            <th>สิทธิ์</th>
                            <th>สถานะ</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="user in filteredUsers" :key="user.id">
                            <tr :class="user.id == <?= (int)$me['id'] ?> ? 'bg-blue-50/50' : ''">
                                <td class="font-mono text-sm font-semibold text-blue-700" x-text="user.username"></td>
                                <td class="text-sm font-medium text-gray-900" x-text="user.full_name"></td>
                                <td class="text-xs text-gray-500" x-text="user.email || '-'"></td>
                                <td class="text-xs text-gray-500" x-text="siteLabel(user.site_id)"></td>
                                <td class="text-xs text-gray-500" x-text="user.department_name || user.plant_code || '-'"></td>
                                <td>
                                    <span class="badge" :class="getRoleClass(user.role)" x-text="user.role"></span>
                                </td>
                                <td>
                                    <span :class="user.is_active == 1 ? 'text-green-600' : 'text-red-500'" class="text-xs font-bold" x-text="user.is_active == 1 ? 'Active' : 'Inactive'"></span>
                                </td>
                                <td class="text-center">
                                    <div class="flex justify-center gap-1">
                                        <button @click="openEditModal(user)" class="btn btn-xs btn-info"><i class="fas fa-edit"></i></button>
                                        <template x-if="user.id != <?= (int)$me['id'] ?>">
                                            <button @click="deleteUser(user.id)" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div x-show="showModal" class="modal-backdrop" x-cloak>
        <div class="modal-box p-6 max-w-md">
            <h3 class="font-bold text-gray-900 text-base mb-4" x-text="isEdit ? 'แก้ไข: ' + formData.username : 'เพิ่มผู้ใช้งานใหม่'"></h3>
            <form @submit.prevent="saveUser">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label text-xs">Username *</label>
                            <input type="text" x-model="formData.username" class="form-input text-sm" required :disabled="isEdit">
                        </div>
                        <div>
                            <label class="form-label text-xs">สิทธิ์</label>
                            <select x-model="formData.role" class="form-input text-sm" :disabled="formData.id == <?= (int)$me['id'] ?>" @change="onRoleChange()">
                                <?php if(($me['role'] ?? '') === 'admin'): ?><option value="admin">Admin</option><?php endif; ?>
                                <option value="webadmin">Webadmin</option>
                                <option value="inventory">Inventory</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="form-label text-xs">ชื่อ-นามสกุล *</label>
                        <input type="text" x-model="formData.full_name" class="form-input text-sm" required>
                    </div>

                    <div>
                        <label class="form-label text-xs">Email</label>
                        <input type="email" x-model="formData.email" class="form-input text-sm">
                    </div>

                    <!-- Site selector (ยกเว้น role=admin) -->
                    <div x-show="formData.role !== 'admin'">
                        <label class="form-label text-xs">Site *</label>
                        <select x-model="formData.site_id" class="form-input text-sm" :disabled="myRole==='webadmin'">
                            <option value="">-- เลือก Site --</option>
                            <template x-for="s in sites" :key="s.id">
                                <option :value="String(s.id)" x-text="s.site_code + ' - ' + s.site_name"></option>
                            </template>
                        </select>
                        <template x-if="myRole==='webadmin'">
                            <p class="text-[10px] text-gray-400 mt-1">webadmin เปลี่ยน Site ไม่ได้ (ผูกกับ Site ของตัวเอง)</p>
                        </template>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label text-xs">แผนก (Department)</label>
                            <select x-model="formData.plant_code" class="form-input text-sm">
                                <option value="">-- เลือก --</option>
                                <?php foreach ($depts as $d): ?>
                                    <option value="<?= htmlspecialchars($d['department_code']) ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label text-xs">สถานะ</label>
                            <select x-model="formData.is_active" class="form-input text-sm" :disabled="formData.id == <?= (int)$me['id'] ?>">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="form-label text-xs" x-text="isEdit ? 'รหัสผ่าน (เว้นว่างได้)' : 'รหัสผ่าน *'"></label>
                        <input type="password" x-model="formData.password" class="form-input text-sm" :required="!isEdit">
                    </div>
                </div>

                <div class="flex gap-2 mt-6">
                    <button type="submit" class="btn btn-primary flex-1" :disabled="submitting">บันทึก</button>
                    <button type="button" @click="showModal = false" class="btn btn-secondary">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    const bgColor = type === 'success' ? 'bg-green-600' : 'bg-red-600';
    toast.className = `fixed bottom-5 right-5 ${bgColor} text-white px-6 py-3 rounded-lg shadow-xl z-[9999]`;
    toast.innerText = message;
    document.body.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 3000);
}

async function apiPost(url, data) {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Server Response:', text);
            return { success: false, message: 'Server Error' };
        }
    } catch (e) {
        return { success: false, message: 'Connection Failed' };
    }
}

function userManagement() {
    return {
        users: [],
        sites: [],
        myRole: <?= json_encode($me['role'] ?? '') ?>,
        mySiteId: <?= json_encode($mySiteId) ?>,
        showModal: false,
        isEdit: false,
        submitting: false,
        formData: { id: '', username: '', full_name: '', plant_code: '', email: '', password: '', role: 'inventory', is_active: 1, site_id: '' },

        async init() {
            await this.fetchSites();
            await this.fetchUsers();
        },

        get filteredUsers() {
            // admin เห็นทั้งหมด
            if (this.myRole === 'admin') return this.users;

            // webadmin: เห็นเฉพาะ site ตัวเอง + ไม่เห็น admin
            const hasSiteField = this.users.length ? (this.users[0].site_id !== undefined) : false;
            if (!hasSiteField) {
                // fallback (ถ้า API ยังไม่ส่ง site_id มา)
                return this.users.filter(u => u.role !== 'admin');
            }
            return this.users.filter(u =>
                u.role !== 'admin' &&
                String(u.site_id || 0) === String(this.mySiteId || 0)
            );
        },

        siteLabel(siteId) {
            const id = String(siteId || '');
            if (!id) return '-';
            const s = this.sites.find(x => String(x.id) === id);
            return s ? `${s.site_code}` : `#${id}`;
        },

        async fetchSites() {
            const r = await fetch('<?= APP_URL ?>/api/sites.php?action=list').then(res => res.json()).catch(()=>({success:false}));
            this.sites = r.success ? (r.data || []) : [];
        },

        async fetchUsers() {
            const r = await fetch('<?= APP_URL ?>/api/users.php?action=list').then(res => res.json());
            if (r.success) this.users = r.data || [];
        },

        getRoleClass(role) {
            if (role === 'admin') return 'badge-active';
            if (role === 'webadmin') return 'badge-info';
            if (role === 'inventory') return 'bg-purple-100 text-purple-700';
            return 'bg-gray-100 text-gray-600';
        },

        openAddModal() {
            this.isEdit = false;
            this.formData = {
                id: '',
                username: '',
                full_name: '',
                plant_code: '',
                email: '',
                password: '',
                role: 'inventory',
                is_active: 1,
                site_id: (this.myRole === 'webadmin') ? String(this.mySiteId || '') : ''
            };
            this.showModal = true;
        },

        openEditModal(user) {
            this.isEdit = true;
            this.formData = { ...user, password: '', site_id: String(user.site_id || '') };
            // webadmin บังคับ site ตัวเอง
            if (this.myRole === 'webadmin') this.formData.site_id = String(this.mySiteId || '');
            this.showModal = true;
        },

        onRoleChange() {
            // ถ้าเลือก admin ให้ล้าง site_id
            if (this.formData.role === 'admin') this.formData.site_id = '';
            // webadmin บังคับ site ตัวเอง
            if (this.myRole === 'webadmin') this.formData.site_id = String(this.mySiteId || '');
        },

        async saveUser() {
            // validate site_id สำหรับ role ที่ไม่ใช่ admin
            if (this.formData.role !== 'admin' && !this.formData.site_id) {
                showToast('กรุณาเลือก Site', 'error');
                return;
            }

            // webadmin บังคับ site ตัวเอง (กันแก้ค่าใน DOM)
            if (this.myRole === 'webadmin') {
                this.formData.site_id = String(this.mySiteId || '');
            }

            this.submitting = true;
            const r = await apiPost('<?= APP_URL ?>/api/users.php', {
                action: this.isEdit ? 'update' : 'create',
                ...this.formData
            });
            this.submitting = false;
            if (r.success) {
                showToast('บันทึกสำเร็จ');
                this.showModal = false;
                this.fetchUsers();
            } else {
                showToast(r.message, 'error');
            }
        },

        async deleteUser(id) {
            if (!confirm('ยืนยันการลบ?')) return;
            const r = await apiPost('<?= APP_URL ?>/api/users.php', { action: 'delete', id });
            if (r.success) {
                showToast('ลบสำเร็จ');
                this.fetchUsers();
            } else {
                showToast(r.message, 'error');
            }
        }
    }
}
</script>
