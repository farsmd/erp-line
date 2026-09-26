<?php
/**
 * session-config.php — پیکربندی مشترک نشست برای پنل ادمین و آپدیت
 */

// پیکربندی نشست برای دوام طولانی‌تر و مشترک بودن
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_lifetime', 86400 * 7); // 7 روز
    ini_set('session.gc_maxlifetime', 86400 * 7);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0); // روی HTTPS تغییر دهید
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.name', 'ERPLINE_ADMIN');
    session_start();
}
