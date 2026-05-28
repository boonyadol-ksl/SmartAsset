---
name: smartasset
description: Help me make safe, focused changes in the SmartAsset PHP/MySQL asset management repository.
argument-hint: Describe the SmartAsset code change, bug fix, or feature update you want.
---
Use this prompt when you want to update the SmartAsset application with a small, targeted code change.

Include:
- the file(s) or page affected (for example, `pages/asset-list.php`, `api/assets.php`, `assets/js/app.js`)
- the behavior you want to fix or improve
- whether it is a PHP, SQL, HTML/CSS, or JavaScript change
- any validation or expected outcome

Examples:
- "Update `pages/asset-list.php` to filter assets by site code and keep pagination working."
- "Fix the SQL query in `api/assets.php` so disabled assets are excluded from results."
- "Add a validation error on `pages/password.php` when passwords do not match."
