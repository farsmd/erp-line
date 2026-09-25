<?php
/**
 * api.php — API Router
 * مسئولیت: فقط روتینگ و اعتبارسنجی درخواست‌ها
 * هیچ business logic اینجا نیست
 */
session_start();
require_once dirname(__DIR__) . '/engine/Engine.php';
require_once dirname(__DIR__) . '/engine/OrderService.php';

$db      = new LinerLightEngine();
$service = new OrderService($db);

// ─────────────────────────────────────────
// درخواست و action
// ─────────────────────────────────────────

$action = $_GET['action'] ?? '';
$input  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $input = json_decode($raw, true) ?? [];
    }
    if (!empty($input['action'])) {
        $action = $input['action'];
    }
}

$ip = $_SERVER['HTTP_CLIENT_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? 'Unknown';

$ua = parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

// ─────────────────────────────────────────
// چاپ اسناد — بدون JSON header
// ─────────────────────────────────────────

if ($action === 'print') {
    requireAdmin();
    handlePrint($db, $service);
    exit;
}

// ─────────────────────────────────────────
// API JSON
// ─────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');

try {
    $result = match ($action) {
        'login'               => handleLogin($db, $input),
        'logout'              => handleLogout(),
        'get_admin_data'      => handleGetAdminData($db, $service),
        'update_order_status' => handleUpdateOrderStatus($db, $service, $input, $ip, $ua),
        'adjust_wallet'       => handleAdjustWallet($db, $service, $input, $ip, $ua),
        'update_inventory'    => handleUpdateInventory($db, $input, $ip, $ua),
        'save_bom_and_queue'  => handleSaveBomAndQueue($db, $service, $input),
        'save_products'       => handleSaveProducts($service, $input),
        default               => throw new RuntimeException('درخواست نامعتبر'),
    };

    echo json_encode(['status' => 'success'] + $result, JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ═════════════════════════════════════════
// ACTION HANDLERS
// ═════════════════════════════════════════

function handleLogin(LinerLightEngine $db, array $input): array {
    $saved = $db->getSettings('admin_password') ?? 'far1230010';
    if (($input['password'] ?? '') !== $saved) {
        throw new RuntimeException('رمز عبور اشتباه است');
    }
    $_SESSION['admin_logged_in'] = true;
    return [];
}

function handleLogout(): array {
    session_destroy();
    return [];
}

function handleGetAdminData(LinerLightEngine $db, OrderService $service): array {
    requireAdmin();

    $orders = $db->getOrders();
    $rev = $cost = $profit = $totalOrders = 0;

    $activeStatuses = ['تایید شده', 'در حال ساخت', 'کنترل کیفیت', 'آماده ارسال', 'تکمیل شده'];

    foreach ($orders as &$o) {
        $bom         = $service->calculateBOM($o['items']);
        $o['bomData'] = $bom;

        if (in_array($o['status'], $activeStatuses)) {
            $totalOrders++;
            $r      = $o['grand_total_final'] ?? $o['grand_total_base'] ?? 0;
            $rev   += $r;
            $cost  += $bom['cost'];
            $profit += ($r - $bom['cost']);
        }
    }

    return [
        'data' => [
            'orders'                  => $orders,
            'products'                => $db->getProductsConfig(),
            'bom_rules'               => $db->getBomRules(),
            'queue'                   => $db->getSettings('queue') ?? ['daily_capacity' => 30, 'buffer_days' => 4],
            'wastage_percent'         => $db->getSettings('wastage_percent') ?? 5,
            'logs'                    => $db->getLogs(300),
            'inventory'               => $db->getInventory(),
            'procurement_suggestions' => $service->getProcurementSuggestions(),
            'customers'               => $db->getCustomers(),
            'stats'                   => [
                'revenue'       => $rev,
                'cost'          => $cost,
                'profit'        => $profit,
                'total_orders'  => $totalOrders,
            ],
        ],
    ];
}

function handleUpdateOrderStatus(LinerLightEngine $db, OrderService $service, array $input, string $ip, string $ua): array {
    requireAdmin();
    $service->changeOrderStatus($input['order_id'], $input['status_action'], $ip, $ua);
    return [];
}

function handleAdjustWallet(LinerLightEngine $db, OrderService $service, array $input, string $ip, string $ua): array {
    requireAdmin();

    $phone  = $input['phone']  ?? '';
    $type   = $input['type']   ?? 'deduct';
    $amount = (float)($input['amount'] ?? 0);
    $reason = trim($input['reason'] ?? 'عملیات مالی');

    $newBalance = $service->adjustWallet($phone, $type, $amount, $reason);

    $label = ($type === 'deduct') ? 'کسر از کیف پول' : 'شارژ کیف پول';
    $db->addLog('wallet_action', $ip, $ua, 'admin',
        "$label $phone مبلغ " . number_format($amount) . " ت بابت: $reason. مانده: " . number_format($newBalance)
    );

    return ['new_balance' => $newBalance, 'message' => "$label با موفقیت انجام شد."];
}

function handleUpdateInventory(LinerLightEngine $db, array $input, string $ip, string $ua): array {
    requireAdmin();

    $itemId    = $input['item_id'];
    $qtyChange = (float)$input['qty_change'];

    if (isset($input['min_stock']) && isset($input['location'])) {
        $db->updateInventoryMeta($itemId, $qtyChange, (float)$input['min_stock'], $input['location']);
    } else {
        $db->adjustInventoryStock($itemId, $qtyChange);
    }

    $db->addLog('inventory_action', $ip, $ua, 'admin', "بروزرسانی کالا: $itemId");
    return [];
}

function handleSaveBomAndQueue(LinerLightEngine $db, OrderService $service, array $input): array {
    requireAdmin();

    if (isset($input['bom_rules']))       $service->updateBomRules($input['bom_rules']);
    if (isset($input['queue']))           $db->updateSettings('queue', $input['queue']);
    if (isset($input['wastage_percent'])) $db->updateSettings('wastage_percent', $input['wastage_percent']);

    $service->syncInventory();
    return [];
}

function handleSaveProducts(OrderService $service, array $input): array {
    requireAdmin();

    if (isset($input['products'])) {
        $service->updateProducts($input['products']);
    }
    return [];
}

// ═════════════════════════════════════════
// PRINT HANDLER
// ═════════════════════════════════════════

function handlePrint(LinerLightEngine $db, OrderService $service): void {
    header('Content-Type: text/html; charset=utf-8');

    $type = $_GET['print'] ?? 'invoice';

    // پیش‌فاکتور خرید از تأمین‌کننده
    if ($type === 'purchase_order') {
        renderPurchaseOrder($service->getProcurementSuggestions());
        return;
    }

    $id    = $_GET['id'] ?? '';
    $order = $db->getOrder($id);
    if (!$order) die('سفارش یافت نشد.');

    $bom = $service->calculateBOM($order['items']);

    match ($type) {
        'label'    => renderLabel($order),
        'workshop' => renderWorkshopDoc($order, $bom, $type),
        default    => renderDocument($order, $bom, $type),
    };
}

function renderPurchaseOrder(array $items): void {
    $style = "body{font-family:Tahoma,sans-serif;padding:30px;font-size:13px;}
              table{width:100%;border-collapse:collapse;margin-top:20px;}
              th,td{border:1px solid #000;padding:10px;text-align:center;}
              th{background:#eee;}
              @media print{.no-print{display:none;}}";

    echo "<!DOCTYPE html><html dir='rtl'><head><title>سفارش خرید تأمین‌کننده</title>
          <style>$style</style></head><body>";
    echo "<div class='no-print' style='text-align:center;margin-bottom:20px;'>
              <button onclick='window.print()' style='padding:10px 20px;font-weight:bold;cursor:pointer;'>
                  🖨 چاپ پیش‌فاکتور خرید
              </button>
          </div>";
    echo "<h2>سفارش خرید اقلام کسری و نقطه سفارش انبار</h2>";
    echo "<p>تاریخ صدور: " . date('Y/m/d H:i') . "</p>";
    echo "<table><tr>
              <th>ردیف</th><th>نام کالا / متریال</th><th>واحد</th>
              <th>موجودی فعلی</th><th>حداقل آستانه</th><th>مقدار پیشنهادی خرید</th>
          </tr>";

    foreach ($items as $i => $d) {
        echo "<tr>
                  <td>" . ($i + 1) . "</td>
                  <td><strong>{$d['name']}</strong></td>
                  <td>{$d['unit']}</td>
                  <td>{$d['stock']}</td>
                  <td>{$d['min_stock']}</td>
                  <td><strong style='font-size:15px;'>{$d['deficit']}</strong></td>
              </tr>";
    }
    echo "</table></body></html>";
}

function renderLabel(array $order): void {
    echo "<!DOCTYPE html><html dir='rtl'><head>
          <style>
              @page{margin:0;size:50mm auto;}
              body{margin:0;padding:0;font-family:Tahoma,sans-serif;width:50mm;}
              .label-box{width:46mm;padding:2mm;margin:0 auto;border-bottom:2px dashed #000;page-break-after:always;text-align:center;}
              .l-size{font-size:22px;font-weight:900;margin:2mm 0;direction:ltr;}
              .l-text{font-size:11px;margin:1mm 0;font-weight:bold;}
              @media print{.no-print{display:none;}.label-box{border-bottom:none;}}
          </style></head><body>
          <div class='no-print' style='text-align:center;'>
              <button onclick='window.print()'>چاپ</button>
          </div>";

    foreach ($order['items'] as $item) {
        for ($i = 0; $i < (int)$item['qty']; $i++) {
            echo "<div class='label-box'>
                      <div style='font-size:14px;font-weight:bold;'>Liner Light</div>
                      <div class='l-size'>{$item['profile_cm']} cm</div>
                      <div class='l-text'>{$item['model']} | {$item['color']}</div>
                      <div class='l-text'>{$item['light']}</div>
                  </div>";
        }
    }
    echo "</body></html>";
}

function renderWorkshopDoc(array $order, array $bom, string $type): void {
    $title = 'حواله تولید کارگاه';
    renderDocHeader($order, $title);

    echo "<h3>۱. لیست برش قطعات:</h3>
          <table><tr><th>مدل</th><th>رنگ</th><th>نور</th><th>توضیح</th><th>طول</th><th>تعداد</th></tr>";

    foreach ($order['items'] as $it) {
        echo "<tr>
                  <td>{$it['model']}</td><td>{$it['color']}</td><td>{$it['light']}</td>
                  <td>" . ($it['item_desc'] ?? '-') . "</td>
                  <td dir='ltr'>{$it['profile_cm']} cm</td><td>{$it['qty']}</td>
              </tr>";
    }
    echo "</table>";

    if (!empty($bom['recycled_scraps'])) {
        echo "<h4 style='color:#059669;'>♻️ قطعات برش‌خورده از انبار پرتی:</h4><ul>";
        foreach ($bom['recycled_scraps'] as $rc) {
            echo "<li>مدل {$rc['model']}: برش <strong>{$rc['cut_length']} cm</strong> از پرتی {$rc['used_from_scrap']} cm</li>";
        }
        echo "</ul>";
    }

    echo "<h3>۲. متریال نو خروج از انبار:</h3><ul>";
    foreach ($bom['base_profiles'] as $pId => $qty) {
        echo "<li>شاخه خام ۳ متری {$pId}: <strong>{$qty} شاخه</strong></li>";
    }
    foreach ($bom['bom_details'] as $b) {
        echo "<li>{$b['name']}: <strong>{$b['qty']} {$b['unit']}</strong></li>";
    }
    echo "</ul>";

    renderDocFooter();
}

function renderDocument(array $order, array $bom, string $type): void {
    $titleMap = [
        'proforma' => 'پیش‌فاکتور رسمی',
        'invoice'  => 'فاکتور فروش نهایی',
        'qc'       => 'چک‌لیست QC و بسته‌بندی',
        default    => 'رسید تحویل کالا',
    ];
    $title = $titleMap[$type] ?? $titleMap['default'];

    renderDocHeader($order, $title);

    echo "<table><tr><th>محصول</th><th>تعداد</th><th>طول</th><th>مبلغ کل</th></tr>";
    foreach ($order['items'] as $it) {
        echo "<tr>
                  <td>{$it['model']}</td><td>{$it['qty']}</td>
                  <td dir='ltr'>{$it['profile_cm']} cm</td>
                  <td>" . number_format($it['row_total'] ?? 0) . "</td>
              </tr>";
    }
    echo "</table>";

    renderDocFooter();
}

function renderDocHeader(array $order, string $title): void {
    $id    = $order['order_id'];
    $phone = $order['customer_phone'];
    $style = "body{font-family:Tahoma,sans-serif;padding:20px;font-size:13px;}
              .box{border:1px solid #000;padding:30px;margin:auto;max-width:850px;}
              table{width:100%;border-collapse:collapse;margin-bottom:20px;}
              td,th{border:1px solid #000;padding:10px;text-align:center;}
              th{background:#eee;}
              @media print{.no-print{display:none;}.box{border:none;padding:0;}}";

    echo "<!DOCTYPE html><html dir='rtl'><head><title>{$title} - {$id}</title>
          <style>$style</style></head><body>
          <div class='no-print' style='text-align:center;margin-bottom:20px;'>
              <button onclick='window.print()' style='padding:10px 20px;cursor:pointer;'>🖨 چاپ سند</button>
          </div>
          <div class='box'>
          <h2 style='border-bottom:2px solid #000;padding-bottom:10px;display:flex;justify-content:space-between;'>
              <span>Liner Light | {$title}</span>
              <span style='font-size:14px;font-weight:normal;'>کد: {$id} | تلفن: {$phone}</span>
          </h2>";
}

function renderDocFooter(): void {
    echo "</div></body></html>";
}

// ═════════════════════════════════════════
// UTILS
// ═════════════════════════════════════════

function requireAdmin(): void {
    if (!isset($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        echo json_encode(['status' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function parseUserAgent(string $ua): string {
    $os = match (true) {
        stripos($ua, 'windows') !== false                                    => 'Windows',
        stripos($ua, 'mac') !== false || stripos($ua, 'iphone') !== false    => 'Apple (Mac/iOS)',
        stripos($ua, 'android') !== false                                    => 'Android',
        stripos($ua, 'linux') !== false                                      => 'Linux',
        default                                                               => 'ناشناخته',
    };

    $browser = match (true) {
        stripos($ua, 'firefox') !== false                                    => 'Firefox',
        stripos($ua, 'chrome') !== false                                     => 'Chrome',
        stripos($ua, 'safari') !== false                                     => 'Safari',
        default                                                               => 'ناشناخته',
    };

    return "$os | $browser";
}
