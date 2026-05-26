<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helper/auth.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
} else {
    header('Location: ' . APP_URL . '/login.php');
}
exit;
