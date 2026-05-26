<?php
$pageTitle = 'เปลี่ยนรหัสผ่าน';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();

$db = Database::getInstance();
$me = currentUser();
?>
<?php include __DIR__ . '/../components/head.php'; ?>

<div class="min-h-screen" x-data="passwordManagement()">
    <?php include __DIR__ . '/../components/sidebar.php'; ?>

    <main class="main-content flex-1 p-4 sm:p-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h1 class="text-xl font-bold text-gray-900">เปลี่ยนรหัสผ่าน</h1>
                <p class="text-sm text-gray-500">ผู้ใช้งาน: <span class="font-bold text-blue-600"><?= htmlspecialchars($me['username']) ?></span></p>
            </div>
        </div>

        <div class="max-w-md mx-auto mt-10">
            <div class="card p-6">
                <form @submit.prevent="updatePassword">
                    <div class="space-y-4">
                        <div>
                            <label class="form-label text-sm">รหัสผ่านปัจจุบัน</label>
                            <input type="password" x-model="formData.current_password" class="form-input" required placeholder="ระบุรหัสผ่านเดิม">
                        </div>

                        <hr class="border-gray-100">

                        <div>
                            <label class="form-label text-sm">รหัสผ่านใหม่</label>
                            <input type="password" x-model="formData.new_password" class="form-input" required placeholder="ระบุรหัสผ่านใหม่">
                        </div>

                        <div>
                            <label class="form-label text-sm">ยืนยันรหัสผ่านใหม่</label>
                            <input type="password" x-model="formData.confirm_password" class="form-input" required placeholder="พิมพ์รหัสผ่านใหม่อีกครั้ง">
                        </div>
                    </div>

                    <div class="mt-8">
                        <button type="submit" class="btn btn-primary w-full" :disabled="submitting">
                            <span x-show="!submitting"><i class="fas fa-key mr-2"></i> อัปเดตรหัสผ่าน</span>
                            <span x-show="submitting">กำลังดำเนินการ...</span>
                        </button>
                    </div>
                </form>
            </div>

            <p class="text-center text-xs text-gray-400 mt-4">
                * หลังจากเปลี่ยนรหัสผ่านสำเร็จ คุณอาจต้องเข้าสู่ระบบใหม่อีกครั้ง
            </p>
        </div>
    </main>
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
        return await response.json();
    } catch (e) {
        return { success: false, message: 'การเชื่อมต่อล้มเหลว' };
    }
}

function passwordManagement() {
    return {
        submitting: false,
        formData: {
            current_password: '',
            new_password: '',
            confirm_password: ''
        },

        async updatePassword() {
            if (this.formData.new_password !== this.formData.confirm_password) {
                showToast('รหัสผ่านใหม่ไม่ตรงกัน', 'error');
                return;
            }

            if (this.formData.new_password.length < 6) {
                showToast('รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร', 'error');
                return;
            }

            this.submitting = true;
            const r = await apiPost('<?= APP_URL ?>/api/users.php', {
                action: 'change_password',
                id: '<?= $me['id'] ?>',
                ...this.formData
            });
            this.submitting = false;

            if (r.success) {
                showToast('เปลี่ยนรหัสผ่านสำเร็จแล้ว');
                this.formData = { current_password: '', new_password: '', confirm_password: '' };
            } else {
                showToast(r.message || 'ไม่สามารถเปลี่ยนรหัสผ่านได้', 'error');
            }
        }
    }
}
</script>