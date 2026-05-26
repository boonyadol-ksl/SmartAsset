<?php
$pageTitle = 'จัดการ Site';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();
requireRole(['admin', 'webadmin']);
$user = currentUser();
$role = $user['role'] ?? ($_SESSION['user_role'] ?? '');
?>

<?php include __DIR__ . '/../components/head.php'; ?>

<div class="min-h-screen" x-data="siteManager()">
  <?php include __DIR__ . '/../components/sidebar.php'; ?>

  <main class="main-content flex-1 p-4 sm:p-6">
    <div class="mb-6">
      <h1 class="text-xl font-bold text-gray-900">จัดการ Site</h1>
      <p class="text-sm text-gray-500">
        เพิ่ม/แก้ไขข้อมูล Site (ไม่รองรับการลบ — ใช้ปิดการใช้งานแทน)
      </p>
    </div>

    <template x-if="role==='webadmin' && (!userSiteId || userSiteId===0)">
      <div class="p-4 rounded-lg border bg-yellow-50 text-yellow-800 text-sm">
        บัญชี webadmin ยังไม่ได้ผูก Site กรุณาให้ admin กำหนด Site ให้ก่อน
      </div>
    </template>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Left: Sites list -->
      <div class="lg:col-span-2 space-y-4">
        <div class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <h2 class="font-bold text-gray-800 text-sm">รายการ Site</h2>
            <button class="btn btn-primary btn-sm"
              x-show="role==='admin'"
              @click="openCreate()">
              + เพิ่ม Site
            </button>
          </div>

          <div class="overflow-auto">
            <table class="min-w-full text-sm">
              <thead>
                <tr class="text-left text-gray-500 border-b">
                  <th class="py-2 pr-2">Code</th>
                  <th class="py-2 pr-2">ชื่อ Site</th>
                  <th class="py-2 pr-2">สถานะ</th>
                  <th class="py-2 pr-2">จัดการ</th>
                </tr>
              </thead>
              <tbody>
                <template x-for="s in sites" :key="s.id">
                  <tr class="border-b hover:bg-gray-50">
                    <td class="py-2 pr-2 font-mono" x-text="s.site_code"></td>
                    <td class="py-2 pr-2" x-text="s.site_name"></td>
                    <td class="py-2 pr-2">
                      <span class="px-2 py-0.5 rounded-full text-xs border"
                        :class="(parseInt(s.is_active)===1) ? 'bg-green-100 text-green-700 border-green-200' : 'bg-gray-100 text-gray-600 border-gray-200'"
                        x-text="(parseInt(s.is_active)===1) ? 'Active' : 'Inactive'"></span>
                    </td>
                    <td class="py-2 pr-2">
                      <button class="btn btn-xs" @click="selectSite(s)">แก้ไข</button>
                      <button class="btn btn-xs"
                        x-show="role==='admin'"
                        @click="toggleActive(s)">
                        <span x-text="parseInt(s.is_active)===1 ? 'ปิดใช้งาน' : 'เปิดใช้งาน'"></span>
                      </button>
                    </td>
                  </tr>
                </template>
                <template x-if="sites.length===0">
                  <tr><td class="py-4 text-gray-400" colspan="4">ไม่มีข้อมูล</td></tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Plant mapping (optional, admin friendly) -->
        <div class="card p-4" x-show="selectedSite">
          <h2 class="font-bold text-gray-800 text-sm mb-3">
            ผูก Plant เข้ากับ Site: <span class="text-blue-700" x-text="selectedSite?.site_code"></span>
          </h2>
          <div class="text-xs text-gray-500 mb-3">เลือก Plant แล้วกด “บันทึกการผูก”</div>

          <div class="grid grid-cols-2 md:grid-cols-3 gap-2 max-h-64 overflow-auto border rounded p-2 bg-gray-50">
            <template x-for="p in plants" :key="p.plant_code">
              <label class="flex items-center gap-2 text-xs p-1 rounded hover:bg-white cursor-pointer">
                <input type="checkbox"
                  :value="p.plant_code"
                  x-model="selectedPlants">
                <span class="font-mono" x-text="p.plant_code"></span>
                <span class="text-gray-500 truncate" x-text="p.plant_name"></span>
              </label>
            </template>
          </div>

          <div class="mt-3 flex items-center gap-2">
            <button class="btn btn-primary btn-sm" @click="savePlantMapping()" :disabled="savingPlants">
              <span x-show="!savingPlants">บันทึกการผูก</span>
              <span x-show="savingPlants">กำลังบันทึก...</span>
            </button>
            <span class="text-xs text-gray-400">หมายเหตุ: ไม่ได้ลบ plant แค่ผูก site_id ให้ plant</span>
          </div>
        </div>
      </div>

      <!-- Right: Edit form -->
      <div class="space-y-4">
        <div class="card p-4">
          <h2 class="font-bold text-gray-800 text-sm mb-3">แก้ไขข้อมูล Site</h2>

          <template x-if="!selectedSite">
            <div class="text-sm text-gray-400">เลือก Site จากตารางด้านซ้าย</div>
          </template>

          <template x-if="selectedSite">
            <div class="space-y-3">
              <div>
                <label class="form-label text-xs">Site Code</label>
                <input class="form-input" disabled x-model="form.site_code">
              </div>
              <div>
                <label class="form-label text-xs">ชื่อ Site *</label>
                <input class="form-input" x-model="form.site_name">
              </div>
              <div>
                <label class="form-label text-xs">Legal Name</label>
                <input class="form-input" x-model="form.legal_name">
              </div>
              <div>
                <label class="form-label text-xs">Tax ID</label>
                <input class="form-input" x-model="form.tax_id">
              </div>
              <div>
                <label class="form-label text-xs">Address</label>
                <textarea class="form-input" rows="3" x-model="form.address"></textarea>
              </div>
              <button class="btn btn-primary w-full" @click="saveEdit()" :disabled="saving">
                <span x-show="!saving">บันทึก</span>
                <span x-show="saving">กำลังบันทึก...</span>
              </button>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Create modal -->
    <div x-show="showCreate" x-cloak class="fixed inset-0 bg-black/40 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl w-full max-w-lg p-5">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-bold">เพิ่ม Site</h3>
          <button class="btn btn-xs" @click="showCreate=false">ปิด</button>
        </div>
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="form-label text-xs">Site Code *</label>
              <input class="form-input" x-model="create.site_code" placeholder="เช่น BK, PT">
            </div>
            <div>
              <label class="form-label text-xs">ชื่อ Site *</label>
              <input class="form-input" x-model="create.site_name">
            </div>
          </div>
          <div>
            <label class="form-label text-xs">Legal Name</label>
            <input class="form-input" x-model="create.legal_name">
          </div>
          <div>
            <label class="form-label text-xs">Tax ID</label>
            <input class="form-input" x-model="create.tax_id">
          </div>
          <div>
            <label class="form-label text-xs">Address</label>
            <textarea class="form-input" rows="3" x-model="create.address"></textarea>
          </div>
          <button class="btn btn-primary w-full" @click="saveCreate()" :disabled="creating">
            <span x-show="!creating">สร้าง Site</span>
            <span x-show="creating">กำลังสร้าง...</span>
          </button>
        </div>
      </div>
    </div>

  </main>
</div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
function siteManager() {
  return {
    role: <?= json_encode($role) ?>,
    userSiteId: <?= json_encode((int)($user['site_id'] ?? 0)) ?>,
    sites: [],
    plants: [],
    selectedSite: null,
    selectedPlants: [],
    showCreate: false,
    saving: false,
    creating: false,
    savingPlants: false,
    form: { id: null, site_code: '', site_name: '', legal_name: '', address: '', tax_id: '' },
    create: { site_code: '', site_name: '', legal_name: '', address: '', tax_id: '' },

    async init() {
      await this.reload();
    },

    async reload() {
      const res = await fetch('<?= APP_URL ?>/api/sites.php?action=list').then(r=>r.json());
      this.sites = res.success ? (res.data || []) : [];
    },

    openCreate() {
      this.create = { site_code: '', site_name: '', legal_name: '', address: '', tax_id: '' };
      this.showCreate = true;
    },

    selectSite(s) {
      this.selectedSite = s;
      this.form = {
        id: s.id,
        site_code: s.site_code,
        site_name: s.site_name || '',
        legal_name: s.legal_name || '',
        address: s.address || '',
        tax_id: s.tax_id || ''
      };
      this.loadPlants();
    },

    async saveEdit() {
      if (!this.form.site_name) { alert('กรุณากรอกชื่อ Site'); return; }
      this.saving = true;
      const res = await fetch('<?= APP_URL ?>/api/sites.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action:'update', ...this.form })
      }).then(r=>r.json()).catch(()=>({success:false,message:'เชื่อมต่อไม่ได้'}));
      this.saving = false;
      if (!res.success) return alert(res.message || 'บันทึกไม่สำเร็จ');
      alert('บันทึกสำเร็จ');
      await this.reload();
      // re-select updated row
      const newSel = this.sites.find(x=>String(x.id)===String(this.form.id));
      if (newSel) this.selectedSite = newSel;
    },

    async saveCreate() {
      if (!this.create.site_code || !this.create.site_name) { alert('กรุณากรอก Site Code และชื่อ Site'); return; }
      this.creating = true;
      const res = await fetch('<?= APP_URL ?>/api/sites.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action:'create', ...this.create })
      }).then(r=>r.json()).catch(()=>({success:false,message:'เชื่อมต่อไม่ได้'}));
      this.creating = false;
      if (!res.success) return alert(res.message || 'สร้างไม่สำเร็จ');
      this.showCreate = false;
      await this.reload();
    },

    async toggleActive(s) {
      const next = parseInt(s.is_active)===1 ? 0 : 1;
      if (!confirm(next ? 'ยืนยันเปิดใช้งาน Site นี้?' : 'ยืนยันปิดใช้งาน Site นี้?')) return;
      const res = await fetch('<?= APP_URL ?>/api/sites.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action:'toggle_active', id:s.id, is_active: next })
      }).then(r=>r.json());
      if (!res.success) return alert(res.message || 'ทำรายการไม่สำเร็จ');
      await this.reload();
      if (this.selectedSite && String(this.selectedSite.id)===String(s.id)) {
        this.selectedSite = this.sites.find(x=>String(x.id)===String(s.id)) || null;
      }
    },

    async loadPlants() {
      if (!this.selectedSite) return;
      const res = await fetch('<?= APP_URL ?>/api/sites.php?action=list_plants').then(r=>r.json());
      this.plants = res.success ? (res.data || []) : [];
      // pre-check plants ที่ผูกอยู่กับ site นี้
      this.selectedPlants = this.plants
        .filter(p => String(p.site_id) === String(this.selectedSite.id))
        .map(p => p.plant_code);
    },

    async savePlantMapping() {
      if (!this.selectedSite) return;
      this.savingPlants = true;
      const res = await fetch('<?= APP_URL ?>/api/sites.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action:'assign_plants', site_id: this.selectedSite.id, plants: this.selectedPlants })
      }).then(r=>r.json()).catch(()=>({success:false,message:'เชื่อมต่อไม่ได้'}));
      this.savingPlants = false;
      if (!res.success) return alert(res.message || 'บันทึกไม่สำเร็จ');
      alert('บันทึกสำเร็จ');
      await this.loadPlants();
    }
  }
}
</script>
