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
        <!-- สแกนตรวจนับทรัพย์สิน  inventory_scan.php-->
         <!-- <a href="inventory_scan.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'inventory_scan' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
            สแกนตรวจนับทรัพย์สิน
        </a> -->
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
            จัดการผู้ตรวจสอบนับ
        </a>
        <!-- สร้างรอบการตรวจนับ  pages/manage_audit.php-->
         <a href="manage_audit.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl mb-1 text-sm font-medium transition-colors <?= $currentPage === 'manage_audit' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-white/10' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            สร้างรอบการตรวจนับ
        </a>

        <?php endif; ?>


        <!-- เปลี่ยนรหัสผ่าน  pages/password.php -->
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

<!-- Top Bar -->
<header class="lg:ml-72 bg-white border-b border-gray-200 sticky top-0 z-10">
    <div class="flex items-center justify-between px-4 sm:px-6 h-16">
        <!-- Mobile menu button -->
        <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl text-gray-500 hover:bg-gray-100 transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <!-- Page Title -->
        <div class="flex items-center gap-2">
            <nav class="text-sm text-gray-500 hidden sm:flex items-center gap-1">
                <span class="text-gray-900 font-semibold" id="pageTitle">Dashboard</span>
            </nav>
        </div>

        <!-- Right Actions -->
        <div class="flex items-center gap-2">
            <div class="text-xs text-gray-400 hidden md:block">
                <?= date('d/m/Y H:i') ?> น.
            </div>
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?= $user['role'] === 'admin' ? 'bg-purple-100 text-purple-700' : ($user['role'] === 'inventory' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600') ?>">
                <?= ucfirst($user['role']) ?>
            </span>
        </div>
    </div>
</header>