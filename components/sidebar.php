<?php
require_once __DIR__ . '/../helper/auth.php';
requireLogin();
$user = currentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!-- Sidebar Overlay -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-20 lg:hidden hidden" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<aside id="sidebar" class="fixed top-0 left-0 h-full w-72 bg-gray-900 text-white z-30 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col">
    <!-- Logo -->
    <div class="flex items-center gap-3 px-6 py-5 border-b border-white/10">
        <div class="w-9 h-9 rounded-xl bg-blue-500 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
        </div>
        <div>
            <p class="font-bold text-sm leading-tight">Asset Management</p>
            <p class="text-xs text-gray-400">System v1.0</p>
        </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto py-4 px-3">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 mb-2">เมนูหลัก</p>

        <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'dashboard' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            Dashboard
        </a>

        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 mb-2 mt-4">จัดการทรัพย์สิน</p>

        <?php if (hasRole(['admin','webadmin'])): ?>
        <a href="asset-import.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'asset-import' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            นำเข้าทรัพย์สิน
        </a>
         <a href="asset-list.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= in_array($currentPage, ['asset-list','asset-detail']) ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            รายการทรัพย์สิน
        </a>
        <?php endif; ?>

        <?php if (hasRole(['admin','webadmin','inventory'])): ?>
        <a href="inventory.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'inventory' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            ตรวจนับทรัพย์สิน
        </a>
        <?php endif; ?>

        <?php if (hasRole(['admin','webadmin'])): ?>
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 mb-2 mt-4">รายงาน</p>
        <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'reports' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            รายงาน & Export
        </a>
        <?php endif; ?>

        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider px-3 mb-2 mt-4">ตั้งค่าระบบ</p>

        <?php if (hasRole(['admin','webadmin'])): ?>
        <a href="user.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'user' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
            </svg>
            จัดการผู้ใช้งาน
        </a>

        <a href="manage_audit.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'manage_audit' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            สร้างรอบการตรวจนับ
        </a>

        <!-- NEW: จัดการ Site (Multi-Site) -->
        <a href="sites.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'sites' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M4 10h16M6 10V4h12v6M6 21v-7m4 7v-7m4 7v-7m4 7v-7"/>
            </svg>
            จัดการ Site
        </a>
        <?php endif; ?>

        <a href="password.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'password' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 12v-2m0 0l-4-4m4 4"/>
            </svg>
            เปลี่ยนรหัสผ่าน
        </a>

    </nav>

    <!-- User Profile -->
    <div class="px-4 py-4 border-t border-white/10">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                <?= strtoupper(substr($user['name'], 0, 1)) ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-white truncate"><?= htmlspecialchars($user['name']) ?></p>
                <p class="text-xs text-gray-400 capitalize"><?= $user['role'] ?></p>
            </div>
            <a href="<?= APP_URL ?>/api/logout.php" class="text-gray-400 hover:text-white transition-colors p-1 rounded-lg hover:bg-white/10" title="ออกจากระบบ">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </a>
        </div>
    </div>
</aside>

<!-- Top Bar (Multi-Deadline Summary) -->
<header class="lg:ml-72 sticky top-0 z-10 bg-white/90 backdrop-blur border-b border-gray-200">
    <div class="flex items-center justify-between px-4 sm:px-6 h-16 gap-3">

        <!-- Left: Mobile + Title -->
        <div class="flex items-center gap-3 min-w-0">
            <button onclick="toggleSidebar()"
                class="lg:hidden p-2 rounded-xl text-gray-500 hover:bg-gray-100 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <div class="min-w-0">
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-sm sm:text-base font-bold text-gray-900 truncate">
                        <?= htmlspecialchars($pageTitle ?? ucfirst($currentPage)) ?>
                    </h1>
                    <span class="hidden sm:inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border"
                        id="tbSiteChip"
                        style="display:none;"></span>
                </div>
                <div class="hidden sm:flex items-center gap-2 text-[11px] text-gray-500">
                    <span><?= date('d/m/Y H:i') ?> น.</span>
                    <span class="text-gray-300">•</span>
                    <span class="capitalize truncate">ผู้ใช้: <?= htmlspecialchars($user['name'] ?? '-') ?></span>
                </div>
            </div>
        </div>

        <!-- Right: Status chips -->
        <div class="flex items-center gap-2 flex-shrink-0 relative">

            <!-- Pending chip (click to open panel) -->
            <button type="button" id="tbToggle"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border text-xs font-bold transition
                       bg-gray-50 text-gray-700 border-gray-200 hover:bg-gray-100">
                <span class="relative flex h-2 w-2">
                    <span id="tbBlink"
                        class="hidden absolute inline-flex h-full w-full rounded-full opacity-75"></span>
                    <span id="tbDot"
                        class="relative inline-flex rounded-full h-2 w-2 bg-gray-400"></span>
                </span>
                <span>ค้างตรวจ</span>
                <span class="px-2 py-0.5 rounded-full bg-white border border-gray-200" id="tbPending">0</span>
            </button>

            <!-- Deadline summary (mobile-friendly) -->
            <div class="flex items-center gap-2 px-3 py-2 rounded-xl border text-xs font-semibold max-w-[11rem] sm:max-w-none"
                id="tbDeadlineChip">
                <span id="tbDeadlineLabel" class="truncate">Deadline</span>
                <span class="opacity-80 hidden sm:inline" id="tbDeadlineSub"></span>
            </div>

            <!-- Score (placeholder - ต่อไปค่อยผูกสูตรคะแนนจริง) -->
            <div class="hidden md:flex items-center gap-2 px-3 py-2 rounded-xl border text-xs font-semibold bg-emerald-50 text-emerald-700 border-emerald-100">
                Score: <span class="font-bold" id="tbScore">-</span>
            </div>

            <!-- Role -->
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
                <?= ($user['role'] ?? '') === 'admin'
                    ? 'bg-purple-100 text-purple-700'
                    : (($user['role'] ?? '') === 'inventory'
                        ? 'bg-green-100 text-green-700'
                        : 'bg-gray-100 text-gray-600') ?>">
                <?= ucfirst($user['role'] ?? '-') ?>
            </span>

            <!-- Dropdown panel -->
            <div id="tbPanel" class="hidden absolute right-0 top-[3.5rem] w-[calc(100vw-2rem)] max-w-[26rem] bg-white border border-gray-200 rounded-2xl shadow-xl overflow-hidden">
                <div class="p-4 border-b bg-gray-50">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-bold text-gray-900 text-sm">สรุปงานที่ได้รับมอบหมาย</div>
                            <div class="text-xs text-gray-500" id="tbPanelSub">-</div>
                        </div>
                        <button type="button" class="btn btn-xs" id="tbClose">ปิด</button>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1 text-[10px] text-gray-500">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border bg-blue-50 text-blue-700 border-blue-100">น้ำเงิน: ยังทัน</span>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border bg-orange-50 text-orange-800 border-orange-200">ส้ม: ใกล้ครบ</span>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border bg-red-50 text-red-700 border-red-200">แดง: เกินกำหนด</span>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border bg-green-50 text-green-700 border-green-200">เขียว: ไม่มีค้าง</span>
                    </div>
                </div>
                <div class="max-h-80 overflow-auto">
                    <div id="tbSessions" class="p-2"></div>
                </div>
                <div class="p-3 border-t bg-white text-[11px] text-gray-500">
                    เคล็ดลับ: เริ่มจากรอบที่ใกล้ครบกำหนดก่อน เพื่อไม่ให้ค้างงาน
                </div>
            </div>
        </div>
    </div>

    <!-- Alert strip -->
    <div class="px-4 sm:px-6 pb-3 hidden" id="tbAlertWrap">
        <div class="flex items-start gap-2 p-3 rounded-xl border" id="tbAlertBox">
            <div class="mt-0.5" id="tbAlertIcon"></div>
            <div class="text-sm" id="tbAlertText"></div>
        </div>
    </div>
</header>

<script>
(() => {
  const panel = document.getElementById('tbPanel');
  const toggle = document.getElementById('tbToggle');
  const closeBtn = document.getElementById('tbClose');
  const pendingEl = document.getElementById('tbPending');
  const dot = document.getElementById('tbDot');
  const blink = document.getElementById('tbBlink');
  const deadlineChip = document.getElementById('tbDeadlineChip');
  const deadlineLabel = document.getElementById('tbDeadlineLabel');
  const deadlineSub = document.getElementById('tbDeadlineSub');
  const sessionsWrap = document.getElementById('tbSessions');
  const panelSub = document.getElementById('tbPanelSub');
  const alertWrap = document.getElementById('tbAlertWrap');
  const alertBox = document.getElementById('tbAlertBox');
  const alertText = document.getElementById('tbAlertText');
  const alertIcon = document.getElementById('tbAlertIcon');

  // Guard: กัน JS พังในกรณี DOM ไม่ครบ/บางหน้ามี layout ต่างกัน
  if (!panel || !toggle || !pendingEl || !dot || !blink || !deadlineChip || !deadlineLabel || !sessionsWrap) {
    return;
  }

  function openPanel() { panel.classList.remove('hidden'); }
  function closePanel() { panel.classList.add('hidden'); }

  // เลี่ยง optional chaining เพื่อรองรับ browser เก่ากว่า
  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    panel.classList.contains('hidden') ? openPanel() : closePanel();
  });
  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  document.addEventListener('click', (e) => {
    if (panel.classList.contains('hidden')) return;
    if (!panel.contains(e.target) && e.target !== toggle) closePanel();
  });

  function setChip(status) {
    // status: overdue | near | ok | none | no_pending
    // reset
    toggle.className = 'inline-flex items-center gap-2 px-3 py-2 rounded-xl border text-xs font-bold transition';
    blink.className = 'hidden absolute inline-flex h-full w-full rounded-full opacity-75';
    dot.className = 'relative inline-flex rounded-full h-2 w-2';

    const base = ' bg-gray-50 text-gray-700 border-gray-200 hover:bg-gray-100';
    let cls = base;
    let dotCls = ' bg-gray-400';
    let blinkCls = '';

    if (status === 'overdue') {
      cls = ' bg-red-50 text-red-700 border-red-200 hover:bg-red-100';
      dotCls = ' bg-red-500';
      blinkCls = ' animate-ping bg-red-400';
    } else if (status === 'near') {
      cls = ' bg-orange-50 text-orange-800 border-orange-200 hover:bg-orange-100';
      dotCls = ' bg-orange-500';
      blinkCls = ' animate-ping bg-orange-400';
    } else if (status === 'ok') {
      // ยังมีงานค้าง แต่ยังอยู่ในกรอบเวลา => ใช้ "น้ำเงิน" จะสื่อว่าให้ทำต่อ (ไม่ใช่เสร็จแล้ว)
      cls = ' bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100';
      dotCls = ' bg-blue-500';
    } else if (status === 'no_pending') {
      // ไม่มีงานค้าง => ใช้ "เขียว" สื่อว่าจบ/ปกติ
      cls = ' bg-green-50 text-green-700 border-green-200 hover:bg-green-100';
      dotCls = ' bg-green-500';
    }

    toggle.className += cls;
    dot.className += dotCls;
    if (blinkCls) {
      blink.className = 'absolute inline-flex h-full w-full rounded-full opacity-75' + blinkCls;
    }
  }

  function setDeadlineChip(status, label, sub) {
    deadlineChip.className = 'flex items-center gap-2 px-3 py-2 rounded-xl border text-xs font-semibold max-w-[11rem] sm:max-w-none';
    if (status === 'overdue') deadlineChip.className += ' bg-red-50 text-red-700 border-red-200';
    else if (status === 'near') deadlineChip.className += ' bg-orange-50 text-orange-800 border-orange-200';
    else if (status === 'ok') deadlineChip.className += ' bg-blue-50 text-blue-700 border-blue-200';
    else deadlineChip.className += ' bg-gray-50 text-gray-600 border-gray-200';

    deadlineLabel.textContent = label || 'Deadline';
    deadlineSub.textContent = sub || '';
  }

  function setAlert(status, text) {
    if (!text) {
      alertWrap.classList.add('hidden');
      return;
    }
    alertWrap.classList.remove('hidden');
    alertBox.className = 'flex items-start gap-2 p-3 rounded-xl border';

    if (status === 'overdue') {
      alertBox.className += ' bg-red-50 border-red-200 text-red-900';
      alertIcon.innerHTML = '<svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86l-8.02 14A2 2 0 004.02 21h15.96a2 2 0 001.73-3.14l-8.02-14a2 2 0 00-3.4 0z"/></svg>';
    } else {
      alertBox.className += ' bg-yellow-50 border-yellow-200 text-yellow-900';
      alertIcon.innerHTML = '<svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86l-8.02 14A2 2 0 004.02 21h15.96a2 2 0 001.73-3.14l-8.02-14a2 2 0 00-3.4 0z"/></svg>';
    }
    alertText.textContent = text;
  }

  function renderSessions(sessions) {
    sessionsWrap.innerHTML = '';
    if (!sessions || sessions.length === 0) {
      sessionsWrap.innerHTML = '<div class="p-4 text-sm text-gray-400">ไม่มีงานที่ได้รับมอบหมาย</div>';
      return;
    }

    sessions.forEach(s => {
      const status = s.deadline_status || 'none';
      const badgeCls =
        status === 'overdue' ? 'bg-red-100 text-red-700 border-red-200' :
        status === 'near'    ? 'bg-orange-100 text-orange-800 border-orange-200' :
        status === 'ok'      ? 'bg-blue-100 text-blue-700 border-blue-200' :
                               'bg-gray-100 text-gray-600 border-gray-200';

      const barPct = s.assigned > 0 ? Math.round((s.completed / s.assigned) * 100) : 0;
      const deadlineText = s.deadline_date ? `Deadline: ${s.deadline_date}` : 'Deadline: ไม่กำหนด';
      const sub = s.deadline_sub ? ` • ${s.deadline_sub}` : '';

      const row = document.createElement('div');
      row.className = 'p-3 m-2 rounded-xl border hover:bg-gray-50 transition cursor-pointer';
      row.innerHTML = `
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="text-sm font-bold text-gray-900 truncate">${escapeHtml(s.session_name || ('Session #' + s.session_id))}</div>
            <div class="text-[11px] text-gray-500">${deadlineText}${sub}</div>
          </div>
          <div class="flex flex-col items-end gap-1">
            <span class="px-2 py-0.5 rounded-full border text-[11px] font-semibold ${badgeCls}">
              ${labelForStatus(status)}
            </span>
            <div class="text-[11px] text-gray-600 font-semibold">
              ค้าง <span class="text-gray-900">${s.pending}</span> / ทั้งหมด ${s.assigned}
            </div>
          </div>
        </div>
        <div class="mt-2">
          <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-2 bg-blue-600 rounded-full" style="width:${barPct}%"></div>
          </div>
          <div class="mt-1 text-[10px] text-gray-400">ความคืบหน้า ${barPct}% (เสร็จ ${s.completed} รายการ)</div>
        </div>
      `;
      row.addEventListener('click', () => {
        // ถ้าในอนาคต inventory.php รองรับ filter ด้วย session_id จะใช้งานได้ทันที
        window.location.href = `inventory.php?session_id=${encodeURIComponent(s.session_id)}&status=pending`;
      });
      sessionsWrap.appendChild(row);
    });
  }

  function labelForStatus(status) {
    if (status === 'overdue') return 'เกินกำหนด';
    if (status === 'near') return 'ใกล้ครบกำหนด';
    if (status === 'ok') return 'ตามกำหนด';
    return 'ไม่กำหนด';
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  async function loadTopbar() {
    try {
      const res = await fetch('<?= APP_URL ?>/api/topbar_status.php?action=mine', { credentials: 'include' })
        .then(r => r.json());
      if (!res || !res.success) return;

      const d = res.data || {};
      pendingEl.textContent = (d.total_pending ?? 0).toLocaleString();
      panelSub.textContent = d.panel_sub || '';
      setChip(d.overall_status || 'none');
      setDeadlineChip(d.overall_status || 'none', d.deadline_label || 'Deadline', d.deadline_sub || '');
      setAlert(d.overall_status || 'none', d.alert_text || '');
      renderSessions(d.sessions || []);
    } catch (e) {
      // เงียบไว้ ไม่ให้รบกวนการใช้งาน
    }
  }

  // initial + refresh every 60s
  loadTopbar();
  setInterval(loadTopbar, 60000);
})();
</script>
