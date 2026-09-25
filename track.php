<?php
/**
 * track.php — سامانه پیگیری خطی سفارشات مشتریان
 */
require_once __DIR__ . '/engine/Engine.php';

$orderData = null;
$errorMsg  = null;

// مراحل خط تولید (کلید: وضعیت DB — مقدار: برچسب نمایشی)
$statuses = [
    'در انتظار بررسی' => 'بررسی اولیه',
    'تایید شده'       => 'صدور فاکتور',
    'در حال ساخت'     => 'ورود به خط تولید',
    'کنترل کیفیت'     => 'تست و QC',
    'آماده ارسال'     => 'بسته‌بندی',
    'تکمیل شده'       => 'تحویل مشتری',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = trim($_POST['order_id'] ?? '');
    $phone   = trim($_POST['phone']    ?? '');

    if ($orderId && $phone) {
        try {
            $db    = new LinerLightEngine();
            $order = $db->getOrder($orderId);

            if ($order && $order['customer_phone'] === $phone) {
                $orderData = $order;
            } else {
                $errorMsg = 'سفارشی با این مشخصات یافت نشد. لطفاً کد سفارش و شماره موبایل را بررسی کنید.';
            }
        } catch (Exception $e) {
            $errorMsg = 'سیستم موقتاً در دسترس نیست.';
        }
    } else {
        $errorMsg = 'لطفاً تمامی فیلدها را پر کنید.';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پیگیری سفارش | لاینر لایت</title>
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <style>
        body { padding: 20px; }

        .track-container {
            max-width: 600px;
            margin: 40px auto;
            background: var(--card);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 30px;
            border-top: 5px solid var(--gold);
        }

        .track-header { text-align: center; margin-bottom: 30px; }
        .track-header h1 { font-size: 22px; color: var(--text); }
        .track-header p  { color: var(--muted); font-size: 14px; margin-top: 5px; }

        /* فرم */
        .form-group           { margin-bottom: 15px; }
        .form-group input     { border-radius: 8px; padding: 12px 15px; }
        .form-group input[dir="ltr"] { text-align: left; }

        /* خلاصه سفارش */
        .order-summary {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .order-summary span        { display: block; margin-bottom: 8px; }
        .order-summary strong      { color: var(--text); }
        .estimated-days-badge {
            margin-top: 10px;
            color: var(--primary);
            background: #eff6ff;
            padding: 8px;
            border-radius: 6px;
            display: block;
        }

        /* Timeline */
        .timeline {
            position: relative;
            margin: 20px 0;
            padding-right: 20px;
            list-style: none;
        }
        .timeline::before {
            content: '';
            position: absolute;
            top: 0; bottom: 0; right: 29px;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline li {
            position: relative;
            margin-bottom: 25px;
            padding-right: 35px;
        }
        .timeline li::before {
            content: '';
            position: absolute;
            right: 5px; top: 2px;
            width: 10px; height: 10px;
            border-radius: 50%;
            background: #e2e8f0;
            border: 4px solid #fff;
            box-shadow: 0 0 0 2px #e2e8f0;
            z-index: 2;
        }

        .timeline li.completed::before   { background: #10b981; box-shadow: 0 0 0 2px #10b981; }
        .timeline li.completed .step-title { color: #10b981; }

        .timeline li.active::before {
            background: var(--primary);
            box-shadow: 0 0 0 4px rgba(37,99,235,.2);
            animation: pulse 2s infinite;
        }
        .timeline li.active .step-title { color: var(--primary); font-weight: bold; }

        .step-title { font-size: 15px; font-weight: bold; color: var(--muted); margin: 0 0 4px; }
        .step-time  { font-size: 12px; color: #94a3b8; }

        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgba(37,99,235,.4); }
            70%  { box-shadow: 0 0 0 10px rgba(37,99,235,0); }
            100% { box-shadow: 0 0 0 0 rgba(37,99,235,0); }
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--muted);
            font-size: 13px;
            text-decoration: none;
        }
        .back-link:hover { color: var(--text); }
    </style>
</head>
<body>

    <div class="track-container">

        <div class="track-header">
            <h1>سامانه پیگیری سفارشات</h1>
            <p>وضعیت لحظه‌ای سفارش خود را در کارگاه لاینر لایت مشاهده کنید</p>
        </div>

        <?php if ($errorMsg): ?>
            <div class="alert-error"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <?php if (!$orderData): ?>

            <!-- فرم جستجو -->
            <form method="POST">
                <div class="form-group">
                    <label>کد پیگیری سفارش (مثال: LN-A1B2C)</label>
                    <input type="text" name="order_id" dir="ltr" placeholder="LN-" required
                           value="<?= htmlspecialchars($_POST['order_id'] ?? 'LN-') ?>">
                </div>
                <div class="form-group">
                    <label>شماره موبایل ثبت شده</label>
                    <input type="tel" name="phone" dir="ltr" placeholder="09123456789"
                           required maxlength="11"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    جستجو و پیگیری
                </button>
            </form>

        <?php else: ?>

            <!-- نتیجه رهگیری -->
            <div class="order-summary">
                <span>کد سفارش: <strong><?= $orderData['order_id'] ?></strong></span>
                <span>تعداد قطعات: <strong><?= (int)($orderData['total_qty'] ?? 0) ?> عدد</strong></span>
                <span>مبلغ فاکتور:
                    <strong style="color:var(--gold);font-size:16px;">
                        <?= number_format($orderData['grand_total_final'] ?? $orderData['grand_total_base'] ?? 0) ?> تومان
                    </strong>
                </span>
                <?php if (!empty($orderData['estimated_days']) && $orderData['status'] !== 'تکمیل شده'): ?>
                    <span class="estimated-days-badge">
                        ⏱ زمان تخمینی تحویل: <strong><?= (int)$orderData['estimated_days'] ?> روز کاری</strong>
                    </span>
                <?php endif; ?>
            </div>

            <?php
            // ساخت نقشه تاریخچه
            $historyMap = [];
            foreach ($orderData['history'] ?? [] as $h) {
                $historyMap[$h['status']] = $h['timestamp'];
            }
            $statusKeys       = array_keys($statuses);
            $currentStatusIdx = array_search($orderData['status'], $statusKeys);
            ?>
            <ul class="timeline">
                <?php foreach ($statuses as $statusKey => $statusLabel):
                    $idx   = array_search($statusKey, $statusKeys);
                    $class = '';
                    $time  = 'در انتظار رسیدن به این مرحله';

                    if ($idx < $currentStatusIdx) {
                        $class = 'completed';
                        $time  = $historyMap[$statusKey] ?? 'تکمیل شده';
                    } elseif ($idx === $currentStatusIdx) {
                        $class = 'active';
                        $time  = $historyMap[$statusKey] ?? 'در حال انجام...';
                    }
                ?>
                    <li class="<?= $class ?>">
                        <p class="step-title"><?= $statusLabel ?></p>
                        <span class="step-time" dir="ltr"><?= $time ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <a href="track.php" class="btn btn-light"
               style="width:100%;text-align:center;text-decoration:none;display:block;">
                پیگیری سفارش دیگر
            </a>

        <?php endif; ?>

        <a href="index.html" class="back-link">➔ بازگشت به سایت لاینر لایت</a>

    </div>

</body>
</html>
