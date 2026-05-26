<?php
/**
 * api/audit_print.php
 * พิมพ์รายงานสรุปผลการตรวจนับทรัพย์สิน (A4)
 * ต้องเซ็นครบ 3 คนก่อนจึงจะพิมพ์ได้
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();

$db   = Database::getInstance();
$user = currentUser();

// ── Validate Input ────────────────────────────────────────
$summaryId = intval($_GET['summary_id'] ?? 0);
if ($summaryId <= 0) {
    http_response_code(400);
    die('<p style="color:red;font-family:sans-serif;">❌ ไม่พบ summary_id ที่ถูกต้อง</p>');
}

// ── Fetch audit_summary ──────────────────────────────────
$summary = $db->fetchOne(
    "SELECT s.*,
            u1.full_name AS officer_name,
            u2.full_name AS auditor_db_name
     FROM audit_summary s
     LEFT JOIN users u1 ON s.asset_officer_id = u1.id
     LEFT JOIN users u2 ON s.auditor_id       = u2.id
     WHERE s.id = ?",
    [$summaryId]
);

if (!$summary) {
    http_response_code(404);
    die('<p style="color:red;font-family:sans-serif;">❌ ไม่พบข้อมูลสรุป (summary_id=' . $summaryId . ')</p>');
}

// ── Guard: ต้องเซ็นครบทั้งสามคน ──────────────────────────
$missingSign = [];
if (empty($summary['asset_officer_sig'])) $missingSign[] = 'เจ้าหน้าที่ทรัพย์สิน';
if (empty($summary['auditor_sig']))        $missingSign[] = 'เจ้าหน้าที่ตรวจนับ';
if (empty($summary['dept_sig']))           $missingSign[] = 'หัวหน้าแผนก';

if ($missingSign) {
    http_response_code(403);
    die('<p style="color:red;font-family:sans-serif;padding:20px;">❌ ยังเซ็นไม่ครบ กรุณาเซ็นชื่อให้ครบก่อน:<br>• ' . implode('<br>• ', $missingSign) . '</p>');
}

// ── Fetch audit session info ─────────────────────────────
$session = $db->fetchOne(
    "SELECT * FROM audit_sessions WHERE id = ?",
    [$summary['session_id']]
);
$sessionName = $session['session_name'] ?? ('Session #' . $summary['session_id']);
$sessionStart = !empty($session['start_date']) ? date('d/m/Y', strtotime($session['start_date'])) : '-';
$sessionEnd   = !empty($session['end_date'])   ? date('d/m/Y', strtotime($session['end_date']))   : '-';

// ── Fetch plant info ──────────────────────────────────────
$plantInfo = $db->fetchOne(
    "SELECT plant_name FROM plants WHERE plant_code = ?",
    [$summary['plant_code']]
);
$plantName = $plantInfo['plant_name'] ?? '';

// ── Fetch assets with latest audit in this session ───────
$assets = $db->fetchAll(
    "SELECT
         a.asset_no,
         a.asset_description,
         a.serial_no,
         a.model,
         a.plant_code,
         a.cost_center,
         a.location       AS asset_location,
         a.municipality,
         aa.status        AS audit_status,
         aa.checked_at,
         aa.remark        AS audit_remark,
         u.full_name      AS auditor_name
     FROM assets a
     JOIN audit_assignments aa
         ON aa.asset_id   = a.id
        AND aa.session_id = ?
        AND aa.id = (
            SELECT MAX(aa2.id)
            FROM audit_assignments aa2
            WHERE aa2.asset_id   = a.id
              AND aa2.session_id = ?
        )
     LEFT JOIN users u ON aa.user_id = u.id
     WHERE a.plant_code  = ?
       AND a.cost_center = ?
     ORDER BY a.asset_no ASC",
    [
        $summary['session_id'],
        $summary['session_id'],
        $summary['plant_code'],
        $summary['cost_center'],
    ]
);

// ── Stats ─────────────────────────────────────────────────
$totalCount     = count($assets);
$completedCount = 0;
$statusLabels   = [
    'active'    => 'ใช้งาน',
    'returned'  => 'ชำรุด-ส่งคืน',
    'not_found' => 'ไม่พบ',
    'inactive'  => 'ไม่ใช้งาน',
    'repairing' => 'ชำรุด-รอซ่อม',
    'pending'   => 'รอดำเนินการ',
    'completed' => 'ตรวจแล้ว',
];
$statusCount = [];
foreach ($assets as $a) {
    if ($a['audit_status'] === 'completed') $completedCount++;
    $s = $a['audit_status'] ?? 'pending';
    $remarkArr = [];
    if (!empty($a['audit_remark'])) {
        $remarkArr = json_decode($a['audit_remark'], true) ?: [];
    }
    $displayStatus = $remarkArr['check_result'] ?? $s;
    $statusCount[$displayStatus] = ($statusCount[$displayStatus] ?? 0) + 1;
}

$printDate = date('d/m/Y H:i');

// ── Helper ────────────────────────────────────────────────
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function statusLabel(string $s): string {
    $map = [
        'active'    => '<span style="color:#16a34a;font-weight:600;">ใช้งาน</span>',
        'returned'  => '<span style="color:#2563eb;">ชำรุด-ส่งคืน</span>',
        'not_found' => '<span style="color:#dc2626;font-weight:600;">ไม่พบ</span>',
        'inactive'  => '<span style="color:#6b7280;">ไม่ใช้งาน</span>',
        'repairing' => '<span style="color:#d97706;">ชำรุด-รอซ่อม</span>',
    ];
    return $map[$s] ?? h($s);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>รายงานตรวจนับทรัพย์สิน – <?= h($summary['plant_code']) ?> / <?= h($summary['cost_center']) ?></title>
<style>
/* ── Reset & Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Sarabun', 'TH Sarabun New', Arial, sans-serif;
    font-size: 13px;
    color: #111;
    background: #f3f4f6;
}

/* ── A4 Page ── */
.page {
    width: 210mm;
    min-height: 297mm;
    margin: 10mm auto;
    background: #fff;
    padding: 14mm 14mm 18mm;
    box-shadow: 0 2px 12px rgba(0,0,0,.15);
    position: relative;
}

/* ── Header ── */
.report-header {
    border-bottom: 2.5px solid #1e3a5f;
    padding-bottom: 8px;
    margin-bottom: 10px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
}
.report-header .logo-area {
    flex-shrink: 0;
    width: 52px;
    height: 52px;
    border: 1px solid #ddd;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: #9ca3af;
    text-align: center;
    overflow: hidden;
}
.report-header .title-area { flex: 1; }
.report-header h1 {
    font-size: 17px;
    font-weight: 700;
    color: #1e3a5f;
    line-height: 1.3;
}
.report-header .subtitle {
    font-size: 11.5px;
    color: #4b5563;
    margin-top: 3px;
}
.report-header .print-meta {
    font-size: 10.5px;
    color: #6b7280;
    text-align: right;
    flex-shrink: 0;
    line-height: 1.7;
}

/* ── Info Grid ── */
.info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px 12px;
    background: #f0f4ff;
    border: 1px solid #c7d2fe;
    border-radius: 6px;
    padding: 8px 12px;
    margin-bottom: 10px;
}
.info-grid .info-item { font-size: 11.5px; }
.info-grid .info-item .label { color: #6b7280; font-size: 10px; display: block; }
.info-grid .info-item .value { font-weight: 600; color: #1e3a5f; }

/* ── Stats Row ── */
.stats-row {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}
.stat-card {
    flex: 1;
    min-width: 90px;
    border-radius: 5px;
    padding: 5px 8px;
    text-align: center;
    border: 1px solid;
}
.stat-card .num { font-size: 20px; font-weight: 700; line-height: 1.2; }
.stat-card .lbl { font-size: 9.5px; margin-top: 2px; }
.stat-total    { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
.stat-done     { background:#f0fdf4; border-color:#bbf7d0; color:#15803d; }
.stat-pending  { background:#fefce8; border-color:#fde68a; color:#92400e; }
.stat-notfound { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }

/* ── Asset Table ── */
.asset-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    margin-bottom: 14px;
}
.asset-table thead th {
    background: #1e3a5f;
    color: #fff;
    padding: 5px 4px;
    text-align: center;
    font-weight: 600;
    border: 1px solid #1e3a5f;
    white-space: nowrap;
}
.asset-table tbody td {
    padding: 4px 4px;
    border: 1px solid #d1d5db;
    vertical-align: top;
}
.asset-table tbody tr:nth-child(even) td { background: #f9fafb; }
.asset-table tbody tr:hover td { background: #eff6ff; }
.asset-table .no-col   { width: 28px; text-align: center; color: #6b7280; }
.asset-table .assetno  { font-family: monospace; color: #1d4ed8; font-weight: 700; white-space: nowrap; }
.asset-table .desc-col { max-width: 160px; }
.asset-table .center   { text-align: center; }
.asset-table .muted    { color: #6b7280; font-size: 10px; }

/* ── Signature Section ── */
.sig-section {
    margin-top: 18px;
    border-top: 2px solid #1e3a5f;
    padding-top: 12px;
    page-break-inside: avoid;
}
.sig-section h3 {
    font-size: 13px;
    font-weight: 700;
    color: #1e3a5f;
    margin-bottom: 10px;
}
.sig-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
.sig-box {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 10px;
    text-align: center;
    background: #fafafa;
}
.sig-box .role-title {
    font-size: 11px;
    font-weight: 700;
    color: #374151;
    margin-bottom: 6px;
    padding-bottom: 4px;
    border-bottom: 1px dashed #d1d5db;
}
.sig-box img {
    max-width: 140px;
    max-height: 70px;
    display: block;
    margin: 6px auto;
    object-fit: contain;
}
.sig-box .sig-name {
    font-size: 11.5px;
    font-weight: 600;
    color: #111;
    border-top: 1px solid #374151;
    padding-top: 4px;
    margin-top: 4px;
}
.sig-box .sig-date {
    font-size: 9.5px;
    color: #6b7280;
    margin-top: 2px;
}

/* ── Footer ── */
.page-footer {
    position: absolute;
    bottom: 8mm;
    left: 14mm;
    right: 14mm;
    font-size: 9.5px;
    color: #9ca3af;
    display: flex;
    justify-content: space-between;
    border-top: 1px solid #e5e7eb;
    padding-top: 4px;
}

/* ── Print Media ── */
@media print {
    body { background: none; }
    .page {
        margin: 0;
        box-shadow: none;
        padding: 10mm 12mm 16mm;
    }
    .no-print { display: none !important; }
    @page {
        size: A4 portrait;
        margin: 10mm 12mm;
    }
}

/* ── Toolbar (screen only) ── */
.toolbar {
    width: 210mm;
    margin: 6mm auto 4mm;
    display: flex;
    gap: 8px;
    align-items: center;
}
.btn-print {
    background: #1d4ed8;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 8px 20px;
    font-size: 13px;
    cursor: pointer;
    font-family: inherit;
}
.btn-print:hover { background: #1e40af; }
.btn-close-win {
    background: #6b7280;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 8px 14px;
    font-size: 13px;
    cursor: pointer;
    font-family: inherit;
}
</style>
</head>
<body>

<!-- Toolbar (screen only) -->
<div class="toolbar no-print">
    <button class="btn-print" onclick="window.print()">🖨️ พิมพ์รายงาน (A4)</button>
    <button class="btn-close-win" onclick="window.close()">✕ ปิด</button>
    <span style="font-size:11px;color:#6b7280;margin-left:4px;">
        ตรวจสอบ Preview ก่อนพิมพ์ · แนะนำตั้งค่า "Background graphics" เพื่อให้สีพื้นหลังแสดง
    </span>
</div>

<div class="page">

    <!-- ── Header ── -->
    <div class="report-header">
        <div class="title-area">
            <h1>รายงานสรุปผลการตรวจนับทรัพย์สิน</h1>
            <div class="subtitle">
                Audit Inventory Report &nbsp;|&nbsp;
                <?= h($summary['plant_code']) ?>
                <?php if ($plantName): ?> – <?= h($plantName) ?><?php endif; ?>
                &nbsp;/&nbsp; <?= h($summary['cost_center']) ?>
            </div>
        </div>
        <div class="print-meta">
            พิมพ์เมื่อ: <?= $printDate ?><br>
            โดย: <?= h($user['full_name'] ?? $user['username'] ?? 'ไม่ระบุ') ?>
        </div>
    </div>

    <!-- ── Info Grid ── -->
    <div class="info-grid">
        <div class="info-item">
            <span class="label">Plant Code</span>
            <span class="value"><?= h($summary['plant_code']) ?><?= $plantName ? ' – ' . h($plantName) : '' ?></span>
        </div>
        <div class="info-item">
            <span class="label">Cost Center</span>
            <span class="value"><?= h($summary['cost_center']) ?></span>
        </div>
        <div class="info-item">
            <span class="label">รอบการตรวจนับ</span>
            <span class="value"><?= h($sessionName) ?></span>
        </div>
        <div class="info-item">
            <span class="label">ช่วงเวลา</span>
            <span class="value"><?= $sessionStart ?> – <?= $sessionEnd ?></span>
        </div>
        <div class="info-item">
            <span class="label">วันที่อัปเดตสรุป</span>
            <span class="value"><?= $summary['updated_at'] ? date('d/m/Y H:i', strtotime($summary['updated_at'])) : '-' ?></span>
        </div>
        <div class="info-item">
            <span class="label">สถานะ</span>
            <span class="value" style="color:#15803d;">✓ เซ็นครบทั้ง 3 ฝ่าย</span>
        </div>
    </div>

    <!-- ── Stats ── -->
    <div class="stats-row">
        <div class="stat-card stat-total">
            <div class="num"><?= $totalCount ?></div>
            <div class="lbl">รายการทั้งหมด</div>
        </div>
        <div class="stat-card stat-done">
            <div class="num"><?= $completedCount ?></div>
            <div class="lbl">ตรวจเสร็จสิ้น</div>
        </div>
        <div class="stat-card stat-pending">
            <div class="num"><?= $totalCount - $completedCount ?></div>
            <div class="lbl">ยังไม่ตรวจ</div>
        </div>
        <?php $notFound = $statusCount['not_found'] ?? 0; if ($notFound): ?>
        <div class="stat-card stat-notfound">
            <div class="num"><?= $notFound ?></div>
            <div class="lbl">ไม่พบทรัพย์สิน</div>
        </div>
        <?php endif; ?>
        <?php if (!empty($statusCount)): ?>
            <?php foreach ($statusCount as $sc => $cnt):
                if ($sc === 'not_found') continue; ?>
            <div class="stat-card" style="background:#f9fafb;border-color:#d1d5db;color:#374151;">
                <div class="num" style="font-size:16px;"><?= $cnt ?></div>
                <div class="lbl"><?= h($sc === 'active' ? 'ใช้งาน' : ($sc === 'returned' ? 'ส่งคืน' : ($sc === 'inactive' ? 'ไม่ใช้' : ($sc === 'repairing' ? 'รอซ่อม' : $sc)))) ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── Asset Table ── -->
    <table class="asset-table">
        <thead>
            <tr>
                <th class="no-col">#</th>
                <th style="width:100px;">รหัสทรัพย์สิน</th>
                <th>รายละเอียด</th>
                <th style="width:80px;">S/N</th>
                <th style="width:65px;">ผลตรวจ</th>
                <th style="width:80px;">สถานที่</th>
                <th style="width:72px;">ผู้รับผิดชอบ</th>
                <th style="width:65px;">ผู้ตรวจ</th>
                <th style="width:60px;">วันที่ตรวจ</th>
                <th style="width:70px;">หมายเหตุ</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($assets)): ?>
            <tr>
                <td colspan="10" style="text-align:center;color:#9ca3af;padding:16px;">ไม่พบรายการทรัพย์สิน</td>
            </tr>
        <?php else: ?>
            <?php foreach ($assets as $idx => $a):
                $remarkArr = [];
                if (!empty($a['audit_remark'])) {
                    $remarkArr = json_decode($a['audit_remark'], true) ?: [];
                }
                $checkResult   = $remarkArr['check_result']       ?? $a['audit_status'] ?? '-';
                $locationReal  = $remarkArr['location']           ?? $a['asset_location'] ?? '-';
                $responsible   = $remarkArr['responsible_person'] ?? $a['municipality'] ?? '-';
                $notes         = $remarkArr['notes']              ?? '';
                $checkedAtStr  = $a['checked_at'] ? date('d/m/y', strtotime($a['checked_at'])) : '-';
                $auditorName   = $a['auditor_name'] ?? '-';
            ?>
            <tr>
                <td class="no-col"><?= $idx + 1 ?></td>
                <td class="assetno"><?= h($a['asset_no']) ?></td>
                <td class="desc-col">
                    <?= h($a['asset_description']) ?>
                    <?php if ($a['model']): ?>
                        <div class="muted">Model: <?= h($a['model']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="muted center"><?= h($a['serial_no'] ?: '-') ?></td>
                <td class="center"><?= statusLabel($checkResult) ?></td>
                <td><?= h($locationReal) ?></td>
                <td><?= h($responsible) ?></td>
                <td class="muted"><?= h($auditorName) ?></td>
                <td class="center muted"><?= h($checkedAtStr) ?></td>
                <td class="muted"><?= h($notes) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- ── Signature Section ── -->
    <div class="sig-section">
        <h3>ลายเซ็นผู้รับรองผลการตรวจนับ</h3>
        <div class="sig-grid">

            <!-- เจ้าหน้าที่ทรัพย์สิน -->
            <div class="sig-box">
                <div class="role-title">เจ้าหน้าที่ทรัพย์สิน</div>
                <img src="<?= h($summary['asset_officer_sig']) ?>" alt="ลายเซ็น จนท.ทรัพย์สิน">
                <div class="sig-name">
                    <?= h($summary['officer_name'] ?? '– ไม่ระบุชื่อ –') ?>
                </div>
                <div class="sig-date">ฝ่ายบัญชีทรัพย์สิน</div>
            </div>

            <!-- เจ้าหน้าที่ตรวจนับ -->
            <div class="sig-box">
                <div class="role-title">เจ้าหน้าที่ตรวจนับ</div>
                <img src="<?= h($summary['auditor_sig']) ?>" alt="ลายเซ็น จนท.ตรวจนับ">
                <div class="sig-name">
                    <?= h($summary['auditor_db_name'] ?? '– ไม่ระบุชื่อ –') ?>
                </div>
                <div class="sig-date">ผู้ดำเนินการตรวจนับ</div>
            </div>

            <!-- หัวหน้าแผนก -->
            <div class="sig-box">
                <div class="role-title">หัวหน้าแผนก</div>
                <img src="<?= h($summary['dept_sig']) ?>" alt="ลายเซ็น หัวหน้าแผนก">
                <div class="sig-name">
                    <?= h($summary['dept_name'] ?? '– ไม่ระบุชื่อ –') ?>
                </div>
                <div class="sig-date">ผู้รับรองผลการตรวจนับ</div>
            </div>

        </div>
    </div>

    <!-- ── Page Footer ── -->
    <div class="page-footer">
        <span>ระบบตรวจนับทรัพย์สิน KSL Group | สร้างอัตโนมัติโดยระบบ</span>
        <span>Plant: <?= h($summary['plant_code']) ?> / CC: <?= h($summary['cost_center']) ?> | พิมพ์: <?= $printDate ?></span>
    </div>

</div><!-- /.page -->

<script>
    // Auto-open print dialog เมื่อโหลดเสร็จ
    window.addEventListener('load', function () {
        // หน่วงเล็กน้อยให้ font/image โหลดก่อน
        setTimeout(function () {
            window.print();
        }, 600);
    });
</script>
</body>
</html>
