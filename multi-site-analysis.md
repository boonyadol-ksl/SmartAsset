# Multi-Site Support Analysis for Asset Import

## Current State

### Database Structure

#### sites table
- `id` (PK)
- `site_code` (VARCHAR 10) - ตัวระบุไซต์/โรงงาน เช่น "P001", "P002"
- `site_name` - ชื่อไซต์/โรงงาน
- `is_active` - สถานะการใช้งาน

#### users table
- `site_id` (FK to sites.id) - ระบุว่าพนักงานนี้อยู่ไซต์ไหน
- `plant_code` (VARCHAR 20) - อาจใช้สำหรับ plant/department
- `role` - admin, webadmin, inventory, viewer

#### assets table (CURRENT)
- `plant_code` - ระบุโรงงานปลายทาง
- `department_code` - ระบุแผนกที่ดูแล
- ❌ **NO `site_code` column** - ไม่มี!

### Import Flow (Current)

```
asset-import.php (Frontend)
  ↓
- Admin เลือก plant_code จาก dropdown
- Webadmin โลกเฉพาะ plant_code ของเขา
  ↓
api/import.php (Backend)
  ↓
- plant_code ส่งมาจากฟอร์ม
- อ่านไฟล์ CSV/XLSX
- Insert/Update assets ด้วย plant_code จากไฟล์
```

---

## Recommendation: Multi-Site Architecture

### ✅ Option: Add `site_code` Column to Assets Table

**YES, ต้องเพิ่ม `site_code`** - For these reasons:

1. **ความชัดเจน** - Separate the concern between:
   - `site_code` = ไซต์/บริษัทที่เป็นเจ้าของทรัพย์สิน (ระบบใหญ่)
   - `plant_code` = โรงงานปลายทางในการส่งมอบ (ในไซต์เดียวกัน)
   - `department_code` = แผนกที่ดูแล (ในโรงงาน)

2. **ข้อมูลที่อัพโหลดไม่เปลี่ยน** - The CSV/XLSX file stays the same:
   - ไฟล์ยังมี 15 คอลัมน์เดิม (ไม่มี site_code)
   - ระบบจะ **auto-add site_code** จากพนักงานอัพโหลด

3. **Security & Data Isolation**:
   - Webadmin ของไซต์ A ไม่สามารถเห็นข้อมูลไซต์ B
   - ทรัพย์สินแต่ละชุดต้องระบุเจ้าของไซต์อย่างชัดเจน

4. **Query Performance**:
   - Faster filtering by site
   - Can create composite index: `(site_code, plant_code, asset_no)`

---

## Implementation Plan

### Step 1: Database Migration

Add new column to `assets` table:

```sql
ALTER TABLE assets 
ADD COLUMN `site_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `plant_code`,
ADD INDEX `idx_site_code` (`site_code`),
ADD INDEX `idx_site_plant_asset` (`site_code`, `plant_code`, `asset_no`);
```

### Step 2: Get User's Site Code

In `api/import.php`, extract site_code from logged-in user:

```php
// Get user's site info
$user = currentUser();  // Get current logged-in user
$userSiteId = $user['site_id'];  // Get user's site_id

// Query to get site_code from user's site_id
$userSite = $db->fetchOne(
    "SELECT site_code FROM sites WHERE id = ? AND is_active = 1", 
    [$userSiteId]
);
$siteCode = $userSite['site_code'] ?? '';

// Alternative: If admin selects plant_code, map it to site_code
if ($userRole === 'admin' && isset($_POST['plant_code'])) {
    $site = $db->fetchOne(
        "SELECT site_code FROM sites WHERE site_code = ? AND is_active = 1", 
        [$_POST['plant_code']]
    );
    $siteCode = $site['site_code'] ?? '';
}
```

### Step 3: Update Import Logic

In `api/import.php` within the data building section:

```php
// When building $data array for each row:
$data = array(
    'site_code'         => $siteCode,  // ✨ AUTO-ADD from user's site
    'plant_code'        => $row['plant_code'],
    'asset_no'          => $assetNo,
    'asset_description' => isset($row['asset_description']) ? $row['asset_description'] : '',
    // ... other fields remain same
    'created_by'        => $user['id']
);
```

### Step 4: Update Composite Key Logic

Adjust duplicate detection to include site_code:

```php
// Pre-fetch existing assets with site_code consideration:
$res = $db->fetchAll(
    "SELECT id, asset_no, plant_code, site_code, cap_date FROM assets 
     WHERE site_code = ? AND asset_no IN ($ph)", 
    array_merge([$siteCode], $allAssetNos)
);

// Composite key includes site_code:
$compositeKey = $r['site_code'] . '_' . $r['plant_code'] . '_' . $r['asset_no'] . '_' . $r['cap_date'];
```

### Step 5: Update Queries Throughout App

Files that need updates:

- **pages/asset-list.php** - Filter by user's site_code
- **pages/reports.php** - Show only user's site assets
- **pages/dashboard.php** - Filter by site
- **api/assets.php** - Add site_code filter
- **api/audit_sessions.php** - Ensure only site-relevant audits shown

Example update:

```php
// BEFORE (mixed sites possible):
$assets = $db->fetchAll("SELECT * FROM assets WHERE plant_code = ?", [$plantCode]);

// AFTER (site-specific):
$assets = $db->fetchAll(
    "SELECT * FROM assets WHERE site_code = ? AND plant_code = ?", 
    [$siteCode, $plantCode]
);
```

### Step 6: Update Existing Data

For any existing assets without site_code, run migration script:

```php
// Determine default site_code or prompt user
$defaultSiteCode = 'P001';  // or ask during migration

$db->query(
    "UPDATE assets SET site_code = ? WHERE site_code IS NULL", 
    [$defaultSiteCode]
);

// OR: Map plant_code to site_code if they're the same:
$db->query(
    "UPDATE assets SET site_code = plant_code WHERE site_code IS NULL"
);
```

---

## Upload Flow (After Implementation)

```
User (Webadmin ของ Site A) logs in
  ↓
asset-import.php shows ONLY Site A's name (ล็อกไว้)
  ↓
Select plant_code ใน Site A (e.g., P001-MAIN)
  ↓
Upload CSV file (15 columns as usual)
  ↓
api/import.php:
  - Get user's site_code from users → sites table
  - Read CSV (plant_code, asset_no, description, ...)
  - AUTO-ADD site_code = user's site
  - Save to assets: site_code | plant_code | asset_no | ...
  ↓
Database:
  assets.site_code = 'P001'
  assets.plant_code = (from CSV)
  assets.asset_no = (from CSV)
  ... (other fields unchanged)
```

---

## Security Considerations

### ✅ What's Secure:
- Webadmin can only upload to their assigned site
- Site A's assets won't mix with Site B's
- Queries automatically filter by site_code

### ⚠️ What to Watch:
- Ensure `auth.php` checks site_code in sensitive operations
- Add role-based access control:
  ```php
  // In auth.php
  function requireSite($required_site_code) {
      $user = currentUser();
      $userSite = $db->fetchOne("SELECT site_code FROM sites WHERE id = ?", [$user['site_id']]);
      if ($userSite['site_code'] !== $required_site_code) {
          die('Access Denied: Site Mismatch');
      }
  }
  ```

---

## Summary

| Aspect | Current | After Change |
|--------|---------|---------------|
| **Assets Table** | No site_code | Has site_code column |
| **Upload File** | 15 columns | Still 15 columns (no change) |
| **Site Assignment** | Manual from plant_code | Auto from user's site |
| **Data Isolation** | No separation | Full site-based isolation |
| **API Queries** | Can see all sites | Filtered by user's site |
| **Import Validation** | Checks plant_code | Checks site_code + plant_code |

---

## Files to Modify

1. ✏️ **Database Schema**
   - `asset_db.sql` - Add site_code column

2. ✏️ **Import Handling**
   - `api/import.php` - Add site_code logic
   - `pages/asset-import.php` - Show site info (already does)

3. ✏️ **Data Access Layer**
   - `helper/auth.php` - Add site-check functions
   - `api/assets.php` - Filter by site
   - `api/audit_helper.php` - Filter by site
   - `api/audit_sessions.php` - Filter by site

4. ✏️ **UI/Pages**
   - `pages/asset-list.php` - Show site column option
   - `pages/reports.php` - Already filters by plant, add site filter
   - `pages/dashboard.php` - Show site data

5. ✏️ **Exports**
   - `api/export.php` - Include site_code in exports

---

## Migration Path

**Option A: Gradual**
1. Add column (nullable)
2. Update import logic to populate site_code
3. Run script to populate existing records
4. Update queries one section at a time
5. Make site_code NOT NULL when ready

**Option B: Direct**
1. Add column with default value
2. Update all logic at once
3. Test thoroughly before deployment

Recommend **Option A** for safety.
