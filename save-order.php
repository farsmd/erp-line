<?php
/**
 * save-order.php — ثبت سفارش مشتری
 * ورودی: POST با فیلد order_json
 * خروجی: HTML رسید
 */
header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$orderJson = $_POST['order_json'] ?? '';
if (!$orderJson) exit('داده‌ای ارسال نشده است.');

$data = json_decode($orderJson, true);
if (json_last_error() !== JSON_ERROR_NONE) exit('فرمت داده نامعتبر است.');

require_once __DIR__ . '/engine/Engine.php';
require_once __DIR__ . '/engine/OrderService.php';

$db      = new LinerLightEngine();
$service = new OrderService($db);

try {
    $orderId = $service->createOrder($data);
} catch (Exception $e) {
    http_response_code(500);
    exit('خطا در ثبت سفارش: ' . $e->getMessage());
}

$grandTotal   = $data['grand_total_final'] ?? $data['grand_total_base'] ?? 0;
$phone        = htmlspecialchars($data['customer_phone'] ?? 'نامشخص');
$projectName  = htmlspecialchars($data['project_name']   ?? '');
$totalQty     = (int)($data['total_qty'] ?? 0);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رسید ثبت سفارش | لاینر لایت</title>
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <style>
        body          { background: var(--bg); padding: 40px 20px; }
        .receipt-wrap { max-width: 600px; margin: 0 auto; background: var(--card); border-radius: 16px;
                        box-shadow: var(--shadow); padding: 40px; text-align: center;
                        border-top: 5px solid var(--primary); }
        .receipt-icon { font-size: 60px; margin-bottom: 10px; }
        .receipt-subtitle { color: var(--muted); font-size: 14px; margin-bottom: 25px; }
        .receipt-box  { background: #fcf9f2; border: 1px solid #f4ead6; border-radius: 12px;
                        padding: 20px; text-align: right; margin-bottom: 30px; }
        .receipt-row  { display: flex; justify-content: space-between; align-items: center;
                        padding: 12px 0; border-bottom: 1px dashed #e6dcc8; font-size: 14px; }
        .receipt-row:last-child { border-bottom: none; }
        .receipt-row.total { font-size: 18px; font-weight: bold; color: var(--text);
                             border-top: 2px solid #e6dcc8; border-bottom: none;
                             padding-top: 15px; margin-top: 5px; }
        .tracking-code { background: #fff; padding: 5px 15px; border-radius: 6px; font-weight: bold;
                         letter-spacing: 2px; color: var(--primary); border: 1px solid var(--primary); }
        .btn-group    { display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; }
    </style>
</head>
<body>
    <div class="receipt-wrap">
        <div class="receipt-icon">✅</div>
        <h1>سفارش شما با موفقیت ثبت شد</h1>
        <p class="receipt-subtitle">همکاران ما جهت تأیید نهایی با شما تماس خواهند گرفت.</p>

        <div class="receipt-box">
            <div class="receipt-row">
                <span>کد پیگیری سفارش:</span>
                <span class="tracking-code"><?= $orderId ?></span>
            </div>
            <?php if ($projectName): ?>
            <div class="receipt-row">
                <span>نام پروژه:</span>
                <span style="font-weight:bold; color:#1e3a8a;"><?= $projectName ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-row">
                <span>شماره تماس:</span>
                <span dir="ltr"><?= $phone ?></span>
            </div>
            <div class="receipt-row">
                <span>تعداد قطعات:</span>
                <span><?= $totalQty ?> عدد</span>
            </div>
            <div class="receipt-row total">
                <span>مبلغ نهایی سفارش:</span>
                <span><?= number_format((int)$grandTotal) ?> تومان</span>
            </div>
        </div>

        <div class="btn-group">
            <a href="index.html" class="btn btn-outline">بازگشت به صفحه اصلی</a>
        </div>
    </div>
</body>
</html>
