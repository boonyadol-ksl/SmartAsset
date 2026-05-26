<?php
// =============================================
// Application Configuration
// =============================================

// fix Maximum execution time of 30 seconds exceeded 
set_time_limit(0); // limit to 0 means no time limit

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'kslitc@1234');
define('DB_NAME', 'ksl_asset_db');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Asset Management System');
define('APP_VERSION', '1.0.0');

// Auto-detect host for LAN access
// Set APP_HOST to your server's LAN IP (e.g. '192.168.1.100') or leave 'auto'
define('APP_HOST', 'auto');

$_detected_host = (APP_HOST === 'auto')
    ? (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost')
    : APP_HOST;
$_detected_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

define('APP_URL', $_detected_scheme . '://' . $_detected_host . '/kslassets');

// Session
define('SESSION_LIFETIME', 3600); // 1 hour

// Upload
define('UPLOAD_PATH', __DIR__ . '/assets/uploads/');
define('UPLOAD_URL', APP_URL . '/assets/uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB

// Allowed file types
define('ALLOWED_IMAGES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_EXCEL', ['xlsx', 'xls', 'csv']);

// Pagination
define('ITEMS_PER_PAGE', 20);

// Timezone
date_default_timezone_set('Asia/Bangkok');

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

