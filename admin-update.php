<?php
/**
 * admin-update.php — دسترسی سیستم آپدیت از نشست پنل مدیریت
 *
 * این فایل رمز جداگانه‌ای ندارد و فقط وقتی قابل استفاده است که مدیر
 * قبلاً در admin.html وارد شده باشد.
 */
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    exit('دسترسی غیرمجاز است. ابتدا وارد پنل مدیریت شوید.');
}

// updater.php از این پرچم برای اجازه دسترسی استفاده می‌کند.
$_SESSION['updater_admin'] = true;
require __DIR__ . '/updater.php';
