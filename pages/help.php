<?php
$pageTitle = 'คู่มือระบบ (Help Guide)';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();

$helpSections = [
    'overview' => [
        'title' => 'ภาพรวมระบบ',
        'icon' => 'fas fa-star',
        'pages' => [
            'dashboard' => [
                'name' => 'Dashboard',
                'icon' => 'fas fa-chart-pie',
                'desc' => 'หน้าหลักแสดงภาพรวมสถิติทรัพย์สินทั้งหมดขององค์กร',
                'features' => [
                    'แสดงข้อมูลบริษัท/ไซต์หลักที่กำลังใช้งาน',
                    'กรองและสลับดูภาพรวมตามปีรอบการตรวจนับทรัพย์สิน',
                    'คำนวณสถิติจำนวนทรัพย์สิน (ทั้งหมด, ใช้งานอยู่, ชำรุด/ไม่ใช้งาน) และมูลค่ารวม',
                    'กราฟสรุปข้อมูลแบ่งตาม Plant, แผนก, ประเภททรัพย์สิน และสถานะจริง',
                    'แท็บจำแนกตาม Plant เพื่อการเจาะลึกข้อมูลเฉพาะพื้นที่',
                    'แถบรายงานความก้าวหน้า (%) การตรวจนับเรียลไทม์จากงานที่มอบหมาย'
                ]
            ]
        ]
    ],
    'asset_manage' => [
        'title' => 'การจัดการทรัพย์สิน',
        'icon' => 'fas fa-boxes',
        'pages' => [
            'asset-list' => [
                'name' => 'รายการทรัพย์สินทั้งหมด',
                'icon' => 'fas fa-list-ul',
                'desc' => 'ศูนย์กลางข้อมูลและค้นหารายชื่อทรัพย์สินคงคลัง',
                'features' => [
                    'ระบบกรองขั้นสูง: คำค้นหา (Asset No, รายละเอียด, Serial No, ผู้ดูแล), Plant, Cost Center, แผนก, และสถานะ',
                    'ระบบแบ่งหน้า (Pagination) และเลือกจำนวนรายการที่ต้องการแสดงผลได้',
                    'ปุ่มเพิ่มทรัพย์สินใหม่ และปุ่มนำเข้าข้อมูลจาก Excel (เฉพาะสิทธิ์ Admin)',
                    'ส่งออกข้อมูลไฟล์ CSV ตามเงื่อนไขและฟิลเตอร์ที่กำลังเลือกกรองอยู่',
                    'แสดง Badge สีระบุสถานะอย่างชัดเจน พร้อมปุ่มทางเลือก: ดูรายละเอียด, แก้ไขข้อมูล, ลบรายการ (Admin)'
                ]
            ],
            'asset-detail' => [
                'name' => 'รายละเอียดและแก้ไขทรัพย์สิน',
                'icon' => 'fas fa-info-circle',
                'desc' => 'หน้าจัดการข้อมูลเชิงลึกของทรัพย์สินรายตัว',
                'features' => [
                    'รองรับ 3 โหมดการทำงานในหน้าเดียว: ดูข้อมูล, แก้ไขข้อมูลดั้งเดิม, และเปิดเพิ่มทรัพย์สินใหม่',
                    'ฟอร์มบันทึกข้อมูลครบถ้วน: Plant, Asset No, ประเภท, วันที่จัดซื้อ, รายละเอียด, ยี่ห้อ/รุ่น, Serial No, มูลค่าจัดซื้อ, คอสเซ็นเตอร์, แผนก และผู้รับผิดชอบ',
                    'แสดงรูปภาพประกอบหน้างานจริง (อัปโหลดได้สูงสุด 10 รูป) และรูป QR Code',
                    'แสดงแท็บประวัติการตรวจสอบย้อนหลัง (Audit & Inventory History) ของทรัพย์สินตัวนั้นๆ',
                    'ปุ่มพิมพ์แท็ก/พิมพ์ข้อมูลทรัพย์สินในรูปแบบเอกสารมาตรฐาน'
                ]
            ],
            'asset-import' => [
                'name' => 'นำเข้าข้อมูลทรัพย์สิน (Excel/CSV)',
                'icon' => 'fas fa-file-import',
                'desc' => 'ระบบอัปโหลดและขึ้นระบบข้อมูลทรัพย์สินชุดใหญ่ทางไฟล์ (เฉพาะ Admin)',
                'features' => [
                    'รองรับไฟล์นามสกุลมาตรฐาน .xlsx, .xls และ .csv',
                    'ระบบเลือกการชนกันของข้อมูลกรณี Asset No ซ้ำซ้อน: อัปเดตทับ (Update), ข้ามรายการซ้ำ (Skip), หรือนำเข้าเฉพาะรายการใหม่ (New Only)',
                    'แถบแสดงขั้นตอนการประมวลผล (Process Bar) พร้อมสรุปจำนวนความสำเร็จ/ข้อผิดพลาด',
                    'ตารางประวัติบันทึกการนำเข้าข้อมูลย้อนหลัง (Import Logs)',
                    'ลิงก์ดาวน์โหลดไฟล์ Template ต้นแบบสำหรับการจัดคอลัมน์ให้ตรงกับฐานข้อมูล'
                ]
            ],
            'asset-print' => [
                'name' => 'พิมพ์แท็กทรัพย์สิน (A4)',
                'icon' => 'fas fa-print',
                'desc' => 'หน้าจัดรูปแบบเอกสารสำหรับสั่งพิมพ์ทางเครื่องพิมพ์',
                'features' => [
                    'รองรับการส่ง ID เข้ามาประมวลผลทีละหลายรายการเพื่อจัดหน้าสั่งพิมพ์พร้อมกัน',
                    'แสดงรายละเอียดทรัพย์สิน, รูปภาพหลัก, QR Code, และผลตรวจล่าสุด',
                    'คำนวณอายุการใช้งานของทรัพย์สินอัตโนมัติจากวันที่ตรวจรับจนถึงปัจจุบัน',
                    'จัด Layout จัดหน้ากระดาษแบบ Clean สวยงาม เหมาะสำหรับการบันทึกเป็น PDF หรือพิมพ์ลงกระดาษ A4'
                ]
            ]
        ]
    ],
    'audit_count' => [
        'title' => 'งานตรวจนับและมอบหมาย',
        'icon' => 'fas fa-clipboard-check',
        'pages' => [
            'manage_audit' => [
                'name' => 'การบริหารรอบการตรวจนับ',
                'icon' => 'fas fa-tasks',
                'desc' => 'หน้าวางแผน มอบหมายงาน และตั้งค่ารอบสัญญางานตรวจนับประจำปี (Admin / Webadmin)',
                'features' => [
                    'สร้างรอบตรวจสอบประจำปี กำหนดเป้าหมายความสำเร็จ (%) และวันสิ้นสุดกำหนดส่ง (Deadline)',
                    'เลือกขอบเขตงานคัดกรองตามพื้นที่ Plant และคอสเซ็นเตอร์ (Cost Center)',
                    'ระบบตรวจจับสถานะ Cost Center อัจฉริยะ: ยังมีงานค้าง, ตรวจนับครบแล้ว, มอบหมายงานไปแล้วบางส่วน',
                    'กระจายงานและมอบหมายผู้ตรวจนับ (Inspectors) พร้อมกราฟเช็คจำนวนภาระงาน (Workload) ของแต่ละคน',
                    'ฟังก์ชันดึงงานกลับ หรือยกเลิกงานที่ค้างอยู่ (Pending) เพื่อเปลี่ยนผู้ตรวจสอบใหม่'
                ]
            ],
            'inventory' => [
                'name' => 'บันทึกผลการตรวจนับ',
                'icon' => 'fas fa-mobile-alt',
                'desc' => 'หน้างานหลักของเจ้าหน้าที่ตรวจสอบ (Inventory) ในการลงพื้นที่คีย์ผลตรวจ',
                'features' => [
                    'ค้นหางานด่วนผ่านการสแกน QR Code หรือคีย์เลขอ้างอิง Asset No',
                    'คัดกรองแสดงเฉพาะงานตามสิทธิ์ที่ตัวเองได้รับมอบหมายในรอบปีปัจจุบัน',
                    'บันทึกสถานะหน้างานจริง: ใช้งานอยู่, ชำรุดส่งคืน, ไม่พบ, ไม่ใช้งาน, รอซ่อม',
                    'ฟังก์ชันถ่ายรูปหลักฐานประกอบหน้างาน และระบบเซ็นชื่อยืนยันแบบดิจิทัล (Digital Signature)'
                ]
            ]
        ]
    ],
    'reports_section' => [
        'title' => 'รายงานและการตั้งค่า',
        'icon' => 'fas fa-file-invoice-dollar',
        'pages' => [
            'reports' => [
                'name' => 'ศูนย์รายงานสถิติ',
                'icon' => 'fas fa-file-alt',
                'desc' => 'หน้ารวมข้อมูลสรุปสารสนเทศส่งผู้บริหาร',
                'features' => [
                    'เลือกดูข้อมูลรายงานตามปีรอบตรวจนับ หรือเปรียบเทียบย้อนหลังหลายปี (Year Span)',
                    'รายงานแยกมิติ: รายงานตามแผนก, รายงานแยกตามคลาสประเภททรัพย์สิน, ยอดสะสมรายเดือน',
                    'ปุ่มดาวน์โหลดไฟล์รายงาน CSV แยกประเภทแบบละเอียดในคลิกเดียว',
                    'ปุ่มจัดหน้าเพื่อเตรียมพิมพ์สรุปข้อมูลสถิติหน้าจอ'
                ]
            ],
            'report-print' => [
                'name' => 'พิมพ์รายงานสรุปผล',
                'icon' => 'fas fa-paste',
                'desc' => 'หน้าเอกสารทางการสำหรับการเซ็นอนุมัติผลนับ',
                'features' => [
                    'เลือกรูปแบบฟอร์มเอกสารได้ 2 แบบ: Summary (สรุปยอด) หรือ Checklist (ใบตรวจนับหน้างาน)',
                    'กรองแบ่งข้อมูลให้เรียบร้อยตามสิทธิ์ราย Plant หรือแผนก',
                    'เพิ่มพื้นที่สำหรับหัวหน้างาน เจ้าหน้าที่ และคณะกรรมการลงลายมือชื่อท้ายเอกสาร'
                ]
            ],
            'user' => [
                'name' => 'จัดการผู้ใช้งานระบบ',
                'icon' => 'fas fa-users-cog',
                'desc' => 'หน้าระบบความปลอดภัย สิทธิ์ และรายชื่อผู้ใช้งาน (Admin / Webadmin)',
                'features' => [
                    'สร้าง แก้ไข และระงับการใช้งานผู้ใช้ (Active / Inactive)',
                    'กำหนดบทบาทสิทธิ์เด็ดขาด: Admin, Webadmin, Inventory, Viewer',
                    'ผูกบัญชีผู้ใช้เข้ากับพื้นที่ Site เพื่อจำกัดขอบเขตการมองเห็นข้อมูล (สำหรับกลุ่มผู้ใช้นอกเหนือจาก Admin)'
                ]
            ]
        ]
    ]
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <?php include __DIR__ . '/../components/head.php'; ?>
    <style>
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        html { scroll-behavior: smooth; }
    </style>
</head>
<body class="bg-gray-50 font-sans text-gray-800 antialiased">
    <div class="flex h-screen overflow-hidden">


        <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">

            <div class="w-full bg-white border-b border-gray-200 px-4 py-4 md:px-6 md:py-5 shadow-sm">
                <div class="max-w-7xl mx-auto flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2 md:p-2.5 bg-blue-50 text-blue-600 rounded-xl">
                            <i class="fas fa-question-circle text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-lg md:text-xl font-bold text-gray-900">คู่มือแนะนำการใช้งานระบบ</h1>
                            <p class="text-[11px] md:text-xs text-gray-500 mt-0.5">สรุปความสามารถและคำอธิบายฟังก์ชันของแต่ละหน้างานหลักใน SmartAsset</p>
                        </div>
                    </div>
                    <div class="flex items-center">
                        <a href="<?= APP_URL ?>/pages/dashboard.php"
                           class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 py-2 bg-gray-900 text-white hover:bg-gray-800 rounded-xl text-xs font-semibold shadow-sm transition-all duration-200 group border border-gray-800">
                            <i class="fas fa-home text-blue-400"></i>
                            <span>กลับสู่หน้าหลัก Dashboard</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="md:hidden bg-white border-b border-gray-200 px-4 py-2.5 sticky top-0 z-20 flex gap-2 overflow-x-auto no-scrollbar">
                <?php foreach ($helpSections as $secKey => $sec): ?>
                    <?php foreach ($sec['pages'] as $pageKey => $p): ?>
                        <a href="#page-<?= $pageKey ?>" class="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 hover:bg-blue-50 hover:text-blue-600 rounded-lg text-[11px] font-medium text-gray-600 transition-colors">
                            <i class="<?= $p['icon'] ?> text-[10px]"></i>
                            <span><?= $p['name'] ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>

            <div class="p-4 md:p-6 max-w-7xl w-full mx-auto grid grid-cols-1 md:grid-cols-4 gap-6 items-start">

                <div class="hidden md:block md:col-span-1 bg-white border border-gray-200 rounded-2xl p-4 sticky top-6 shadow-sm max-h-[80vh] overflow-y-auto no-scrollbar">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-gray-400 mb-3 px-2">สารบัญหน้าระบบ</p>
                    <nav class="space-y-4">
                        <?php foreach ($helpSections as $secKey => $sec): ?>
                            <div>
                                <span class="text-xs font-bold text-gray-800 flex items-center gap-1.5 px-2 mb-1.5 opacity-90">
                                    <i class="<?= $sec['icon'] ?> text-blue-500 w-4 text-center"></i> <?= $sec['title'] ?>
                                </span>
                                <div class="space-y-0.5 pl-2 border-l-2 border-gray-100 ml-3">
                                    <?php foreach ($sec['pages'] as $pageKey => $p): ?>
                                        <a href="#page-<?= $pageKey ?>" class="group flex items-center gap-2 px-2 py-1.5 rounded-lg text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-blue-600 transition-colors">
                                            <i class="<?= $p['icon'] ?> text-gray-400 group-hover:text-blue-500 w-4 text-center text-[10px]"></i>
                                            <span class="truncate"><?= $p['name'] ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </nav>
                </div>

                <div class="md:col-span-3 space-y-6 md:space-y-8">

                    <div class="bg-gradient-to-br from-slate-800 to-slate-900 text-white rounded-2xl p-5 md:p-6 shadow-md border border-slate-700">
                        <h2 class="text-sm md:text-base font-bold flex items-center gap-2 mb-4">
                            <i class="fas fa-user-shield text-blue-400"></i> ระดับสิทธิ์และการเข้าถึงข้อมูล (User Roles)
                        </h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <div class="bg-white/5 border border-white/10 rounded-xl p-3">
                                <span class="font-bold text-red-400 flex items-center gap-1.5 mb-1"><i class="fas fa-crown text-[10px]"></i> Admin</span>
                                <p class="text-gray-300 leading-relaxed">สิทธิ์สูงสุด จัดการผู้ใช้ทั้งหมด, ตั้งค่า Site/Plant, นำเข้าข้อมูล Excel, ลบรายการทรัพย์สิน และเปิดรอบงานได้</p>
                            </div>
                            <div class="bg-white/5 border border-white/10 rounded-xl p-3">
                                <span class="font-bold text-amber-400 flex items-center gap-1.5 mb-1"><i class="fas fa-user-edit text-[10px]"></i> Webadmin</span>
                                <p class="text-gray-300 leading-relaxed">ผู้ดูแลประจำไซต์ มอบหมายสัญญางานตรวจนับให้พนักงาน และเรียกดูรายงานสรุปยอดสถิติต่างๆ ภายในไซต์ตัวเอง</p>
                            </div>
                            <div class="bg-white/5 border border-white/10 rounded-xl p-3">
                                <span class="font-bold text-green-400 flex items-center gap-1.5 mb-1"><i class="fas fa-running text-[10px]"></i> Inventory</span>
                                <p class="text-gray-300 leading-relaxed">เจ้าหน้าที่ลงตรวจนับ ค้นหางานด่วนผ่าน QR Code บันทึกสถานะหน้างานจริง ถ่ายภาพหลักฐาน และเซ็นชื่อดิจิทัล</p>
                            </div>
                            <div class="bg-white/5 border border-white/10 rounded-xl p-3">
                                <span class="font-bold text-blue-400 flex items-center gap-1.5 mb-1"><i class="fas fa-eye text-[10px]"></i> Viewer</span>
                                <p class="text-gray-300 leading-relaxed">สิทธิ์เปิดดูข้อมูลและประวัติการตรวจนับอย่างเดียว เน้นดูสถิติกราฟรายงานภาพรวม ไม่สามารถแก้ไขฟิลด์ข้อมูลได้</p>
                            </div>
                        </div>
                    </div>

                    <?php foreach ($helpSections as $secKey => $sec): ?>
                        <div class="space-y-4">
                            <div class="flex items-center gap-2 border-b border-gray-200 pb-2">
                                <div class="w-1.5 h-4 bg-blue-600 rounded-full"></div>
                                <h2 class="text-sm md:text-base font-bold text-gray-900"><?= $sec['title'] ?></h2>
                            </div>

                            <?php foreach ($sec['pages'] as $pageKey => $p): ?>
                                <div id="page-<?= $pageKey ?>" class="bg-white border border-gray-200 rounded-2xl p-4 md:p-5 shadow-sm hover:border-gray-300 transition-all scroll-mt-16 md:scroll-mt-6">
                                    <div class="flex items-center gap-3">
                                        <div class="p-2 bg-slate-50 text-slate-600 rounded-lg">
                                            <i class="<?= $p['icon'] ?> text-sm md:text-base"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-xs md:text-sm font-bold text-gray-900"><?= $p['name'] ?></h3>
                                            <p class="text-[10px] text-gray-400 mt-0.5">ไฟล์หน้างาน: <code class="bg-slate-100 text-slate-600 px-1 py-0.5 rounded text-[9px]">pages/<?= $pageKey ?>.php</code></p>
                                        </div>
                                    </div>

                                    <div class="text-xs text-gray-600 mt-3 bg-blue-50/40 border border-blue-100/50 rounded-xl p-3 leading-relaxed">
                                        <strong class="text-blue-700">หน้าที่ของระบบ:</strong> <?= $p['desc'] ?>
                                    </div>

                                    <div class="mt-4">
                                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">ความสามารถหลักของหน้านี้</p>
                                        <ul class="space-y-2">
                                            <?php foreach ($p['features'] as $feat): ?>
                                                <li class="flex items-start gap-2 text-xs text-gray-600 leading-relaxed">
                                                    <i class="fas fa-check-circle text-green-500 mt-0.5 text-[10px] flex-shrink-0"></i>
                                                    <span><?= htmlspecialchars($feat) ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>

        </div>
    </div>
</body>
</html>