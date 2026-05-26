<?php

/**
 * asset-print.php
 * 1 asset = 1 A4 page
 * แสดงข้อมูลการตรวจนับล่าสุด (latest audit_assignments per asset)
 * รูปภาพแสดง grid สัดส่วนพอดี A4
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helper/auth.php';
requireLogin();

$rawIds = $_GET['id'] ?? '0';
$ids    = array_filter(array_map('intval', explode(',', $rawIds)));
if (empty($ids)) {
    die("ไม่พบข้อมูลทรัพย์สิน");
}

$db           = Database::getInstance();
$placeholders = implode(',', array_fill(0, count($ids), '?'));

/* ── Query: ดึง asset + audit ล่าสุด (MAX id per asset) ── */
$assets = $db->fetchAll(
    "SELECT
        assets.*,
        TIMESTAMPDIFF(YEAR,  cap_date, CURDATE())  AS u_years,
        TIMESTAMPDIFF(MONTH,
            DATE_ADD(cap_date, INTERVAL TIMESTAMPDIFF(YEAR, cap_date, CURDATE()) YEAR),
            CURDATE())                              AS u_months,
        DATEDIFF(CURDATE(),
            DATE_ADD(
                DATE_ADD(cap_date, INTERVAL TIMESTAMPDIFF(YEAR, cap_date, CURDATE()) YEAR),
                INTERVAL TIMESTAMPDIFF(MONTH,
                    DATE_ADD(cap_date, INTERVAL TIMESTAMPDIFF(YEAR, cap_date, CURDATE()) YEAR),
                    CURDATE()) MONTH
            ))                                      AS u_days,
        aa.id          AS audit_id,
        aa.status      AS audit_status,
        aa.remark      AS audit_remark,
        aa.checked_at AS audit_date
    FROM assets
    LEFT JOIN (
    -- เลือกเฉพาะ ID ล่าสุดของแต่ละ asset_id
    SELECT MAX(id) as max_id, asset_id
    FROM audit_assignments
    GROUP BY asset_id
) AS latest_audit ON latest_audit.asset_id = assets.id
LEFT JOIN audit_assignments AS aa ON aa.id = latest_audit.max_id
WHERE assets.id IN ($placeholders)
ORDER BY FIELD(assets.id, $placeholders);",
    array_merge($ids, $ids)
);

if (!$assets) {
    die("ไม่พบข้อมูลทรัพย์สิน");
}

/* ── โหลดรูปทรัพย์สิน (asset_images table) ── */
$images = $db->fetchAll(
    "SELECT asset_id, filename, is_primary
     FROM asset_images
     WHERE asset_id IN ($placeholders)
     ORDER BY is_primary DESC, id ASC",
    $ids
);

$imageMap = [];
foreach ($images as $img) {
    $aid = $img['asset_id'];
    if (!isset($imageMap[$aid])) {
        $imageMap[$aid] = ['qr' => null, 'photos' => [], 'audit_photos' => []];
    }
    if ($img['is_primary'] == 2) {
        if (!$imageMap[$aid]['qr']) $imageMap[$aid]['qr'] = $img['filename'];
        continue;
    }
    if (count($imageMap[$aid]['photos']) < 4) {
        $imageMap[$aid]['photos'][] = $img['filename'];
    }
}

/* ── โหลดรูปจากการตรวจนับ (uploads/audit/{asset_id}/) ── */
$auditUploadBase = __DIR__ . '/../uploads/audit/';
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// debug json


$assets_last = $assets[0] ?? null;
$audit_ids = [$assets_last['audit_id'] ?? 0];

foreach ($audit_ids as $aid) {

    if (!isset($imageMap[$aid])) {
        $imageMap[$aid] = ['qr' => null, 'photos' => [], 'audit_photos' => []];
    }
    $auditDir = $auditUploadBase . $aid . '/';

    if (!is_dir($auditDir)) continue;
    $files = scandir($auditDir);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) continue;
        $imageMap[$aid]['audit_photos'][] = $file;
    }
    //dd($imageMap[$aid]);

}

/* ── Label maps ── */
$statusLabel = [
    'active'    => 'ใช้งาน',
    'inactive'  => 'ไม่ได้ใช้งาน',
    'returned'  => 'ชำรุด-ส่งคืน',
    'repairing' => 'ชำรุด-รอซ่อม',
    'not_found' => 'ไม่พบ',
];
$auditStatusLabel = [
    'found'         => 'พบ',
    'not_found'     => 'ไม่พบ',
    'damaged'       => 'ชำรุด',
    'transferred'   => 'โอนย้าย',
    'active'        => 'พบ / ใช้งาน',
];
$statusColor = [
    'active'    => '#16a34a',
    'inactive'  => '#6b7280',
    'returned'  => '#dc2626',
    'repairing' => '#d97706',
    'not_found' => '#7c3aed',
];
$auditColor = [
    'found'       => '#16a34a',
    'active'      => '#16a34a',
    'not_found'   => '#dc2626',
    'damaged'     => '#d97706',
    'transferred' => '#2563eb',
];

$today = date('d/m/') . (date('Y') + 543) . ' ' . date('H:i') . ' น.';

/* ── Photo grid class ── */
function photoGridCols(int $n): string
{
    if ($n <= 1) return 'cols-1';
    if ($n == 2) return 'cols-2';
    return 'cols-2'; // 3-4 รูป → 2×2
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>พิมพ์ทรัพย์สิน</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════
   RESET & BASE
═══════════════════════════════════════ */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            font-size: 13px;
            line-height: 1.45;
            background: #e5e7eb;
            color: #1a1a1a;
        }

        /* ═══════════════════════════════════════
   NO-PRINT TOOLBAR
═══════════════════════════════════════ */
        .toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999;
            background: #1e3a5f;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .25);
            font-size: 13px;
        }

        .toolbar span {
            opacity: .7;
        }

        .toolbar select,
        .toolbar button {
            padding: 5px 12px;
            border-radius: 5px;
            border: none;
            font-family: inherit;
            font-size: 13px;
            cursor: pointer;
        }

        .toolbar select {
            background: rgba(255, 255, 255, .15);
            color: #fff;
        }

        .toolbar button {
            background: #f59e0b;
            color: #1a1a1a;
            font-weight: 700;
        }

        .toolbar button:hover {
            background: #d97706;
        }

        /* ═══════════════════════════════════════
   A4 PAGE
═══════════════════════════════════════ */
        .print-page {
            width: 210mm;
            min-height: 297mm;
            max-height: 297mm;
            margin: 56px auto 16px;
            background: #fff;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0, 0, 0, .18);
            page-break-after: always;
        }

        /* ═══════════════════════════════════════
   CARD LAYOUT
═══════════════════════════════════════ */
        .asset-card {
            display: flex;
            flex-direction: column;
            height: 297mm;
            padding: 0;
        }

        /* ── HEADER ── */
        .card-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            color: #fff;
            padding: 8px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-shrink: 0;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .org-logo {
            height: 36px;
            width: auto;
            background: rgba(255, 255, 255, .12);
            border-radius: 4px;
            padding: 2px;
        }

        .org-text .org {
            font-size: 10px;
            opacity: .8;
            letter-spacing: .04em;
        }

        .org-text .htitle {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .02em;
        }

        .header-badge {
            background: rgba(255, 255, 255, .18);
            border: 1.5px solid rgba(255, 255, 255, .35);
            border-radius: 6px;
            padding: 4px 12px;
            font-family: 'Courier New', monospace;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .05em;
            white-space: nowrap;
        }

        /* ── BODY ── */
        .card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 10px 14px 8px;
            gap: 8px;
            overflow: hidden;
            min-height: 0;
        }

        /* TOP: info + QR side by side */
        .top-row {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
        }

        /* Info table */
        .info-block {
            flex: 1;
            min-width: 0;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .info-table td {
            padding: 3.5px 7px;
            vertical-align: top;
            border-bottom: 1px solid #f0f0f0;
        }

        .info-table tr:last-child td {
            border-bottom: none;
        }

        .info-table .lbl {
            width: 110px;
            font-weight: 600;
            color: #374151;
            background: #f8fafc;
            white-space: nowrap;
            border-right: 1px solid #e5e7eb;
            font-size: 11.5px;
        }

        .info-table .val {
            color: #111827;
        }

        .info-table .mono {
            font-family: 'Courier New', monospace;
            font-size: 11.5px;
        }

        /* Status pill */
        .status-pill {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
            color: #fff;
        }

        /* QR section */
        .qr-block {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }

        .qr-box {
            width: 90px;
            height: 90px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #f8fafc;
        }

        .qr-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .qr-label {
            font-size: 9px;
            font-family: monospace;
            color: #6b7280;
            text-align: center;
            max-width: 90px;
            word-break: break-all;
        }

        /* ── DIVIDER ── */
        .section-divider {
            border: none;
            border-top: 1.5px solid #e5e7eb;
            margin: 0;
            flex-shrink: 0;
        }

        /* ── SECTION TITLE ── */
        .section-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            color: #1e3a5f;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 5px;
            flex-shrink: 0;
        }

        .section-title::before {
            content: '';
            display: block;
            width: 3px;
            height: 12px;
            background: #2563eb;
            border-radius: 2px;
            flex-shrink: 0;
        }

        /* ── AUDIT SECTION ── */
        .audit-section {
            flex-shrink: 0;
        }

        .audit-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 6px;
        }

        .audit-card {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 6px 9px;
            background: #f9fafb;
        }

        .audit-card .ac-label {
            font-size: 10px;
            color: #6b7280;
            margin-bottom: 2px;
        }

        .audit-card .ac-value {
            font-size: 12px;
            font-weight: 600;
            color: #111827;
        }

        .audit-card .ac-value.no-audit {
            color: #9ca3af;
            font-weight: 400;
            font-style: italic;
            font-size: 11px;
        }

        /* Remark box */
        .remark-card {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 7px 10px;
            background: #fefce8;
            min-height: 36px;
            flex-shrink: 0;
        }

        .remark-card .remark-label {
            font-size: 10px;
            font-weight: 600;
            color: #92400e;
            margin-bottom: 3px;
        }

        .remark-card .remark-content {
            font-size: 12px;
            color: #422006;
            line-height: 1.5;
        }

        .remark-empty {
            color: #a3a3a3;
            font-style: italic;
        }

        /* ── PHOTO SECTION ── */
        .photo-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .photo-grid {
            display: grid;
            gap: 6px;
            flex: 1;
            min-height: 0;
        }

        .photo-grid.cols-1 {
            grid-template-columns: 1fr;
        }

        .photo-grid.cols-2 {
            grid-template-columns: 1fr 1fr;
        }

        .photo-item {
            position: relative;
            border: 1px solid #e5e7eb;
            border-radius: 5px;
            overflow: hidden;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 0;
        }

        .photo-item img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .photo-index {
            position: absolute;
            top: 4px;
            left: 4px;
            background: rgba(30, 58, 95, .7);
            color: #fff;
            font-size: 9px;
            font-weight: 700;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .no-photo {
            border: 1.5px dashed #d1d5db;
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            color: #9ca3af;
            font-size: 12px;
            min-height: 60px;
            flex: 1;
        }

        .no-photo-icon {
            font-size: 22px;
            opacity: .5;
        }

        /* Audit photo highlight */
        .audit-photo-item {
            border-color: #86efac;
            background: #f0fdf4;
        }

        .audit-photo-item::after {
            content: '✓ ตรวจนับ';
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(22, 163, 74, .8);
            color: #fff;
            font-size: 8px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
        }

        /* ── FOOTER ── */
        .card-footer {
            border-top: 1.5px solid #e5e7eb;
            background: #f8fafc;
            padding: 7px 14px 6px;
            flex-shrink: 0;
        }

        .footer-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .sig-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .sig-box {
            border-top: 1px solid #9ca3af;
            padding-top: 4px;
            text-align: center;
            font-size: 10.5px;
            color: #374151;
        }

        .sig-line-space {
            height: 24px;
        }

        /* ═══════════════════════════════════════
   PRINT
═══════════════════════════════════════ */
        @media print {
            @page {
                size: A4;
                margin: 0;
            }

            body {
                background: #fff;
            }

            .toolbar {
                display: none !important;
            }

            .print-page {
                width: 210mm;
                min-height: 297mm;
                max-height: 297mm;
                margin: 0;
                box-shadow: none;
                page-break-after: always;
            }

            .asset-card {
                height: 297mm;
            }
        }
    </style>
</head>

<body>

    <!-- Toolbar -->
    <div class="toolbar no-print">
        <span>📄 พิมพ์ทรัพย์สิน (<?= count($assets) ?> รายการ)</span>
        <button onclick="window.print()">🖨 พิมพ์</button>
        <button onclick="window.close()" style="background:rgba(255,255,255,.15);color:#fff;margin-left:auto;">✕ ปิด</button>
    </div>

    <?php foreach ($assets as $asset):
        $aid     = $asset['id'];
        $audit_id = $asset['audit_id'] ?? 0;
        $qr           = $imageMap[$aid]['qr']          ?? null;
        $photos       = $imageMap[$aid]['photos']       ?? [];
        $auditPhotos  = $imageMap[$audit_id]['audit_photos'] ?? [];
        $photoCount      = count($photos);
        $auditPhotoCount = count($auditPhotos);


        $gridCols        = photoGridCols($photoCount);
        $auditGridCols   = photoGridCols($auditPhotoCount);

        /* Audit info */
        $hasAudit       = !empty($asset['audit_status']);
        $auditStatusTxt = $auditStatusLabel[$asset['audit_status']] ?? ($asset['audit_status'] ?? '-');
        $auditBg        = $auditColor[$asset['audit_status']]       ?? '#6b7280';

        /* Asset status */
        $assetStatusTxt = $statusLabel[$asset['status']] ?? $asset['status'] ?? '-';
        $assetStatusBg  = $statusColor[$asset['status']] ?? '#6b7280';

        /* Age */
        $age = '';
        if ($asset['u_years'] !== null) {
            $age = "{$asset['u_years']} ปี {$asset['u_months']} เดือน {$asset['u_days']} วัน";
        }

        /* Remark: ดู audit_remark ก่อน ถ้าไม่มีใช้ asset remark */
        $remark = trim($asset['audit_remark'] ?? '') ?: trim($asset['remark'] ?? '');

        $audit_last = jsonDecode($remark);

        $asset['municipality'] = $audit_last['responsible_person'] ?? $asset['municipality'] ?? '-';
        $asset['location'] = $audit_last['location'] ?? $asset['location'] ?? '-';

    ?>
        <div class="print-page">
            <div class="asset-card">

                <!-- ═══ HEADER ═══ -->
                <div class="card-header">
                    <div class="header-brand">
                        <img src="<?= APP_URL ?>/assets/images/logo.png" class="org-logo" alt="Logo">
                        <div class="org-text">
                            <div class="org">ทะเบียนครุภัณฑ์</div>
                            <div class="htitle">บัตรประจำทรัพย์สิน</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php if ($hasAudit): ?>
                            <span style="background:<?= $auditBg ?>;color:#fff;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;">
                                ✓ ตรวจนับแล้ว: <?= htmlspecialchars($auditStatusTxt) ?>
                            </span>
                        <?php endif; ?>
                        <div class="header-badge"><?= htmlspecialchars($asset['asset_no']) ?></div>
                    </div>
                </div>

                <!-- ═══ BODY ═══ -->
                <div class="card-body">

                    <!-- TOP ROW: Info + QR -->
                    <div class="top-row">
                        <!-- Info table -->
                        <div class="info-block">
                            <table class="info-table">
                                <tr>
                                    <td class="lbl">รหัสทรัพย์สิน</td>
                                    <td class="val mono"><?= htmlspecialchars($asset['asset_no']) ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">รายละเอียด</td>
                                    <td class="val"><?= htmlspecialchars($asset['asset_description'] ?? '-') ?></td>
                                </tr>
                                <?php if (!empty($asset['brand']) || !empty($asset['model'])): ?>
                                    <tr>
                                        <td class="lbl">ยี่ห้อ / รุ่น</td>
                                        <td class="val"><?= htmlspecialchars(trim(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? ''))) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (!empty($asset['serial_no'])): ?>
                                    <tr>
                                        <td class="lbl">Serial No.</td>
                                        <td class="val mono"><?= htmlspecialchars($asset['serial_no']) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="lbl">วันที่ได้มา</td>
                                    <td class="val"><?= htmlspecialchars($asset['cap_date'] ?? '-') ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">อายุการใช้งาน</td>
                                    <td class="val"><?= $age ?: '-' ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">Cost Center</td>
                                    <td class="val mono"><?= htmlspecialchars($asset['cost_center'] ?? '-') ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">แผนก</td>
                                    <td class="val"><?= htmlspecialchars($asset['department_name'] ?? '-') ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">สถานที่</td>
                                    <td class="val"><?= htmlspecialchars($asset['location'] ?? '-') ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">ผู้รับผิดชอบ</td>
                                    <td class="val"><?= htmlspecialchars($asset['municipality'] ?? '-') ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">สถานะ</td>
                                    <td class="val">
                                        <span class="status-pill" style="background:<?= $assetStatusBg ?>;">
                                            <?= htmlspecialchars($assetStatusTxt) ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- QR -->
                        <div class="qr-block">
                            <div class="qr-box" id="qrbox-<?= $aid ?>">
                                <?php if ($qr): ?>
                                    <img src="<?= APP_URL ?>/assets/uploads/<?= htmlspecialchars($qr) ?>" alt="QR">
                                <?php endif; ?>
                            </div>
                            <div class="qr-label"><?= htmlspecialchars($asset['asset_no']) ?></div>
                        </div>
                    </div>

                    <hr class="section-divider">

                    <!-- ═══ AUDIT SECTION ═══ -->
                    <div class="audit-section">
                        <div class="section-title">ผลการตรวจนับล่าสุด</div>
                        <div class="audit-grid">
                            <div class="audit-card">
                                <div class="ac-label">สถานะการตรวจนับ</div>
                                <?php if ($hasAudit): ?>
                                    <div class="ac-value">
วันเวลาที่ตรวจนับ: <?= htmlspecialchars(date('d/m/Y H:i', strtotime($asset['audit_date']))) ?> น.<br>
                                    <span class="status-pill" style="background:<?= $auditBg ?>;">
                                        <?= htmlspecialchars($auditStatusTxt) ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="ac-value no-audit">ยังไม่ได้ตรวจนับ</div>
                                <?php endif; ?>
                            </div>
                            <div class="audit-card" style="grid-column: span 2;">
                                <div class="ac-label">หมายเหตุจากการตรวจนับ</div>
                                <div class="ac-value <?= $hasAudit ? '' : 'no-audit' ?>">
                                    <?php if ($hasAudit && !empty($asset['audit_remark'])): ?>
                                        <?= nl2br(htmlspecialchars($audit_last['note'] ?? '-')) ?>
                                    <?php elseif ($hasAudit): ?>
                                        <span style="color:#9ca3af;font-style:italic;font-size:11px;">ไม่มีหมายเหตุ</span>
                                    <?php else: ?>
                                        ยังไม่มีข้อมูล
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ REMARK ═══ -->
                    <?php if (!empty($asset['remark'])): ?>
                        <div class="remark-card">
                            <div class="remark-label">📝 หมายเหตุทั่วไป (ข้อมูลทรัพย์สิน)</div>
                            <div class="remark-content">
                                <?= nl2br(htmlspecialchars($asset['remark'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <hr class="section-divider">

                    <!-- ═══ PHOTOS ═══ -->
                    <div class="photo-section">

                        <?php if ($auditPhotoCount > 0): ?>
                            <!-- รูปจากการตรวจนับ -->
                            <div class="section-title" style="color:#16a34a;">
                                <span style="background:#16a34a;"></span>
                                รูปจากการตรวจนับ (<?= $auditPhotoCount ?> รูป)
                            </div>
                            <div class="photo-grid <?= $auditGridCols ?>" style="flex:<?= ($photoCount > 0) ? '0 0 auto; max-height:46%' : '1' ?>;">
                                <?php foreach ($auditPhotos as $i => $photo): ?>
                                    <div class="photo-item audit-photo-item">
                                        <div class="photo-index" style="background:rgba(22,163,74,.8);"><?= $i + 1 ?></div>
                                        <img src="<?= APP_URL ?>/uploads/audit/<?= $audit_id ?>/<?= htmlspecialchars($photo) ?>"
                                            alt="รูปการตรวจนับที่ <?= $i + 1 ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($photoCount > 0): ?>
                            <!-- รูปทรัพย์สิน -->
                            <?php if ($auditPhotoCount > 0): ?>
                                <div class="section-title" style="margin-top:6px;">รูปทรัพย์สิน (<?= $photoCount ?> รูป)</div>
                            <?php else: ?>
                                <div class="section-title">รูปทรัพย์สิน</div>
                            <?php endif; ?>
                            <div class="photo-grid <?= $gridCols ?>" style="flex:1;">
                                <?php foreach ($photos as $i => $photo): ?>
                                    <div class="photo-item">
                                        <div class="photo-index"><?= $i + 1 ?></div>
                                        <img src="<?= APP_URL ?>/assets/uploads/<?= htmlspecialchars($photo) ?>"
                                            alt="รูปที่ <?= $i + 1 ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($auditPhotoCount == 0): ?>
                            <div class="no-photo">
                                <span class="no-photo-icon">🖼</span>
                                ไม่มีรูปภาพ
                            </div>
                        <?php endif; ?>

                    </div>

                </div><!-- /card-body -->

                <!-- ═══ FOOTER ═══ -->
                <div class="card-footer">
                    <div class="footer-top">
                        <span>พิมพ์โดย: <strong><?= htmlspecialchars($_SESSION['user_name'] ?? '-') ?></strong></span>
                        <span>วันที่พิมพ์: <?= $today ?></span>
                    </div>
                    <div class="sig-row">
                        <div class="sig-box">
                            <div class="sig-line-space"></div>
                            ลงชื่อ ................................................ ผู้ตรวจนับ
                        </div>
                        <div class="sig-box">
                            <div class="sig-line-space"></div>
                            ลงชื่อ ................................................ ผู้รับผิดชอบทรัพย์สิน
                        </div>
                    </div>
                </div>

            </div><!-- /asset-card -->
        </div><!-- /print-page -->
    <?php endforeach; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        // Generate QR for assets without stored QR image
        var qrTargets = <?= json_encode(array_values(array_filter(array_map(function ($a) use ($imageMap) {
                            if (!empty($imageMap[$a['id']]['qr'])) return null;
                            return ['id' => $a['id'], 'url' => APP_URL . '/asset-detail.php?id=' . $a['id']];
                        }, $assets)))) ?>;

        qrTargets.forEach(function(t) {
            var box = document.getElementById('qrbox-' + t.id);
            if (!box) return;
            new QRCode(box, {
                text: t.url,
                width: 88,
                height: 88,
                correctLevel: QRCode.CorrectLevel.M
            });
        });
    </script>
</body>

</html>