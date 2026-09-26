<?php
/**
 * api.php — API Router نسخه ۲
 * پشتیبانی از: تنظیمات کاستم، فیلدهای سفارش، export/import
 */
session_start();
require_once dirname(__DIR__) . '/engine/Engine.php';
require_once dirname(__DIR__) . '/engine/OrderService.php';

$db      = new LinerLightEngine();
$service = new OrderService($db);

$action = $_GET['action'] ?? '';
$input  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) $input = json_decode($raw, true) ?? [];
    if (!empty($input['action'])) $action = $input['action'];
}

$ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$ua = parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

// چاپ اسناد
if ($action === 'print') {
    requireAdmin();
    handlePrint($db, $service);
    exit;
}

// Export JSON دانلود
if ($action === 'export_settings') {
    requireAdmin();
    $export = $db->exportSettings();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="linerlight-settings-' . date('Ymd-His') . '.json"');
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $result = match ($action) {
        'login'                => handleLogin($db, $input),
        'logout'               => handleLogout(),
        'get_admin_data'       => handleGetAdminData($db, $service),
        'save_site_settings'   => handleSaveSiteSettings($db, $input),
        'save_pricing'         => handleSavePricing($db, $input),
        'save_products'        => handleSaveProducts($service, $input),
        'save_bom_and_queue'   => handleSaveBomAndQueue($db, $service, $input),
        'save_order_flow'      => handleSaveOrderFlow($db, $input),
        'save_custom_fields'   => handleSaveCustomFields($db, $input),
        'update_order_status'  => handleUpdateOrderStatus($db, $service, $input, $ip, $ua),
        'adjust_wallet'        => handleAdjustWallet($db, $service, $input, $ip, $ua),
        'update_inventory'     => handleUpdateInventory($db, $input, $ip, $ua),
        'import_settings'      => handleImportSettings($db, $input),
        default                => throw new RuntimeException('درخواست نامعتبر'),
    };
    echo json_encode(['status' => 'success'] + $result, JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ═══════════════════════════════════════════
// HANDLERS
// ═══════════════════════════════════════════

function handleLogin(LinerLightEngine $db, array $input): array {
    $saved = $db->getSetting('admin_password') ?? 'far1230010';
    if (($input['password'] ?? '') !== $saved) throw new RuntimeException('رمز عبور اشتباه است');
    $_SESSION['admin_logged_in'] = true;
    return [];
}

function handleLogout(): array { session_destroy(); return []; }

function handleGetAdminData(LinerLightEngine $db, OrderService $service): array {
    requireAdmin();
    $orders = $db->getOrders();
    $rev = $cost = $profit = $total = 0;
    $activeStatuses = ['تایید شده','در حال ساخت','کنترل کیفیت','آماده ارسال','تکمیل شده'];
    foreach ($orders as &$o) {
        $bom = $service->calculateBOM($o['items']);
        $o['bomData'] = $bom;
        if (in_array($o['status'], $activeStatuses)) {
            $total++; $r = $o['grand_total_final'] ?? $o['grand_total_base'] ?? 0;
            $rev += $r; $cost += $bom['cost']; $profit += ($r - $bom['cost']);
        }
    }
    return ['data' => [
        'orders'            => $orders,
        'products'          => $db->getProductsConfig(),
        'bom_rules'         => $db->getBomRules(),
        'custom_fields'     => $db->getCustomOrderFields(),
        'all_settings'      => $db->getAllSettings(),
        'logs'              => $db->getLogs(300),
        'inventory'         => $db->getInventory(),
        'procurement'       => $service->getProcurementSuggestions(),
        'customers'         => $db->getCustomers(),
        'stats'             => ['revenue'=>$rev,'cost'=>$cost,'profit'=>$profit,'total_orders'=>$total],
    ]];
}

function handleSaveSiteSettings(LinerLightEngine $db, array $input): array {
    requireAdmin();
    $allowed = ['site.brand_name','site.brand_logo','site.contact_phone','site.footer_text','site.colors','admin.theme'];
    foreach ($allowed as $k) {
        if (isset($input[$k])) $db->setSetting($k, $input[$k]);
    }
    // تولید config.js با رنگ‌های جدید
    generateConfigJs($db);
    return [];
}

function handleSavePricing(LinerLightEngine $db, array $input): array {
    requireAdmin();
    $keys = ['pricing.tiers','pricing.wire_free_cm','pricing.wire_rate_per_cm','pricing.min_length_cm'];
    foreach ($keys as $k) {
        if (isset($input[$k])) $db->setSetting($k, $input[$k]);
    }
    generateConfigJs($db);
    return [];
}

function handleSaveProducts(OrderService $service, array $input): array {
    requireAdmin();
    if (isset($input['products'])) $service->updateProducts($input['products']);
    generateConfigJs($service->getDb());
    return [];
}

function handleSaveBomAndQueue(LinerLightEngine $db, OrderService $service, array $input): array {
    requireAdmin();
    if (isset($input['bom_rules']))       $service->updateBomRules($input['bom_rules']);
    if (isset($input['queue']))           { $db->setSetting('queue.daily_capacity',$input['queue']['daily_capacity']??30); $db->setSetting('queue.buffer_days',$input['queue']['buffer_days']??4); }
    if (isset($input['wastage_percent'])) $db->setSetting('wastage_percent',$input['wastage_percent']);
    $service->syncInventory();
    return [];
}

function handleSaveOrderFlow(LinerLightEngine $db, array $input): array {
    requireAdmin();
    if (isset($input['statuses']))      $db->setSetting('order_flow.statuses', $input['statuses']);
    if (isset($input['require_phone'])) $db->setSetting('order_flow.require_phone', $input['require_phone']);
    return [];
}

function handleSaveCustomFields(LinerLightEngine $db, array $input): array {
    requireAdmin();
    if (isset($input['fields'])) $db->replaceCustomOrderFields($input['fields']);
    generateConfigJs($db);
    return [];
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
    $newBal = $service->adjustWallet($phone, $type, $amount, $reason);
    $label  = ($type==='deduct') ? 'کسر کیف پول' : 'شارژ کیف پول';
    $db->addLog('wallet',$ip,$ua,'admin',"$label $phone → ".number_format($newBal));
    return ['new_balance'=>$newBal,'message'=>"$label انجام شد."];
}

function handleUpdateInventory(LinerLightEngine $db, array $input, string $ip, string $ua): array {
    requireAdmin();
    $id = $input['item_id'];
    if (isset($input['min_stock'],$input['location'])) {
        $db->updateInventoryMeta($id,(float)$input['qty_change'],(float)$input['min_stock'],$input['location']);
    } else {
        $db->adjustInventoryStock($id,(float)$input['qty_change']);
    }
    $db->addLog('inventory',$ip,$ua,'admin',"بروزرسانی: $id");
    return [];
}

function handleImportSettings(LinerLightEngine $db, array $input): array {
    requireAdmin();
    if (empty($input['data'])) throw new RuntimeException('داده‌ای برای import ارسال نشده.');
    $db->importSettings($input['data']);
    generateConfigJs($db);
    return ['message' => 'تنظیمات با موفقیت وارد شد.'];
}

// ─── config.js Generator ────────────────────────────────

function generateConfigJs(LinerLightEngine $db): void {
    $tiers    = $db->getSetting('pricing.tiers')          ?? [];
    $products = $db->getProductsConfig();
    $freeCm   = $db->getSetting('pricing.wire_free_cm')   ?? 20;
    $rateCm   = $db->getSetting('pricing.wire_rate_per_cm') ?? 400;
    $minLen   = $db->getSetting('pricing.min_length_cm')  ?? 50;
    $colors   = $db->getSetting('site.colors')            ?? [];
    $fields   = $db->getCustomOrderFields();

    // products map
    $prodsJs  = [];
    foreach ($products as $p) {
        $colors_arr  = array_map('trim', explode('،', $p['allowed_colors']));
        $lights_arr  = array_map('trim', explode('،', $p['allowed_lights']));
        $prodsJs[$p['id']] = [
            'title'         => $p['title'],
            'price'         => (int)$p['price'],
            'image'         => $p['image'],
            'desc'          => $p['description'],
            'allowedColors' => $colors_arr,
            'allowedLights' => $lights_arr,
        ];
    }

    $js  = "// config.js — تولید خودکار توسط ERP. ویرایش دستی توصیه نمی‌شود.\n";
    $js .= "// Generated: " . date('Y/m/d H:i:s') . "\n\n";
    $js .= "const PRICING_RULES = " . json_encode([
        'FREE_WIRE_CM'      => (int)$freeCm,
        'EXTRA_WIRE_PER_CM' => (int)$rateCm,
        'MIN_LENGTH_CM'     => (int)$minLen,
    ], JSON_UNESCAPED_UNICODE) . ";\n\n";
    $js .= "const TIERS = " . json_encode($tiers, JSON_UNESCAPED_UNICODE) . ";\n\n";
    $js .= "const PRODUCTS_DATA = " . json_encode($prodsJs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . ";\n\n";
    $js .= "const SITE_COLORS = " . json_encode($colors, JSON_UNESCAPED_UNICODE) . ";\n\n";
    $js .= "const CUSTOM_ORDER_FIELDS = " . json_encode($fields, JSON_UNESCAPED_UNICODE) . ";\n";

    $path = dirname(__DIR__) . '/assets/js/config.js';
    file_put_contents($path, $js);
}

// ─── Print Handler ──────────────────────────────────────

function handlePrint(LinerLightEngine $db, OrderService $service): void {
    header('Content-Type: text/html; charset=utf-8');
    $type = $_GET['print'] ?? 'invoice';
    if ($type === 'purchase_order') {
        renderPurchaseOrder($service->getProcurementSuggestions()); return;
    }
    $id = $_GET['id'] ?? '';
    $order = $db->getOrder($id);
    if (!$order) die('سفارش یافت نشد.');
    $bom = $service->calculateBOM($order['items']);
    match($type) {
        'label'    => renderLabel($order),
        'workshop' => renderWorkshop($order, $bom),
        default    => renderDoc($order, $bom, $type),
    };
}

function renderPurchaseOrder(array $items): void {
    echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'><title>سفارش خرید</title>
    <style>body{font-family:Tahoma;padding:30px;font-size:13px}table{width:100%;border-collapse:collapse;margin-top:20px}
    th,td{border:1px solid #000;padding:10px;text-align:center}th{background:#eee}@media print{.np{display:none}}</style>
    </head><body><div class='np' style='text-align:center;margin-bottom:20px'>
    <button onclick='window.print()' style='padding:10px 20px;font-weight:bold;cursor:pointer'>🖨 چاپ</button></div>
    <h2>سفارش خرید اقلام کسری</h2><p>تاریخ: " . date('Y/m/d H:i') . "</p>
    <table><tr><th>ردیف</th><th>نام کالا</th><th>واحد</th><th>موجودی</th><th>حداقل</th><th>پیشنهاد خرید</th></tr>";
    foreach ($items as $i => $d)
        echo "<tr><td>".($i+1)."</td><td><b>{$d['name']}</b></td><td>{$d['unit']}</td>
              <td>{$d['stock']}</td><td>{$d['min_stock']}</td><td><b>{$d['deficit']}</b></td></tr>";
    echo "</table></body></html>";
}

function renderLabel(array $order): void {
    echo "<!DOCTYPE html><html dir='rtl'><head><style>
    @page{margin:0;size:50mm auto}body{margin:0;font-family:Tahoma;width:50mm}
    .lb{width:46mm;padding:2mm;margin:0 auto;border-bottom:2px dashed #000;page-break-after:always;text-align:center}
    .ls{font-size:22px;font-weight:900;margin:2mm 0;direction:ltr}.lt{font-size:11px;margin:1mm 0;font-weight:bold}
    @media print{.np{display:none}.lb{border-bottom:none}}</style></head><body>
    <div class='np' style='text-align:center'><button onclick='window.print()'>چاپ</button></div>";
    foreach ($order['items'] as $item)
        for ($i=0; $i<(int)$item['qty']; $i++)
            echo "<div class='lb'><div style='font-size:14px;font-weight:bold'>Liner Light</div>
                  <div class='ls'>{$item['profile_cm']} cm</div>
                  <div class='lt'>{$item['model']} | {$item['color']}</div>
                  <div class='lt'>{$item['light']}</div></div>";
    echo "</body></html>";
}

function renderWorkshop(array $order, array $bom): void {
    $id = $order['order_id'];
    baseDocHead($id, 'حواله تولید کارگاه');
    echo "<h3>برش قطعات:</h3><table><tr><th>مدل</th><th>رنگ</th><th>نور</th><th>توضیح</th><th>طول</th><th>تعداد</th></tr>";
    foreach ($order['items'] as $it)
        echo "<tr><td>{$it['model']}</td><td>{$it['color']}</td><td>{$it['light']}</td>
              <td>".($it['item_desc']??'-')."</td><td dir='ltr'>{$it['profile_cm']} cm</td><td>{$it['qty']}</td></tr>";
    echo "</table>";
    if (!empty($bom['recycled_scraps'])) {
        echo "<h4 style='color:#059669'>♻️ از انبار پرتی:</h4><ul>";
        foreach ($bom['recycled_scraps'] as $rc)
            echo "<li>مدل {$rc['model']}: {$rc['cut_length']} cm از پرتی {$rc['used_from_scrap']} cm</li>";
        echo "</ul>";
    }
    echo "<h3>خروج از انبار:</h3><ul>";
    foreach ($bom['base_profiles'] as $pId => $qty) echo "<li>شاخه {$pId}: <b>{$qty} شاخه</b></li>";
    foreach ($bom['bom_details']   as $b)           echo "<li>{$b['name']}: <b>{$b['qty']} {$b['unit']}</b></li>";
    echo "</ul></div></body></html>";
}

function renderDoc(array $order, array $bom, string $type): void {
    $titles = ['proforma'=>'پیش‌فاکتور','invoice'=>'فاکتور فروش','qc'=>'چک‌لیست QC',];
    $title = $titles[$type] ?? 'رسید';
    baseDocHead($order['order_id'], $title, $order['customer_phone']);
    echo "<table><tr><th>محصول</th><th>تعداد</th><th>طول</th><th>مبلغ</th></tr>";
    foreach ($order['items'] as $it)
        echo "<tr><td>{$it['model']}</td><td>{$it['qty']}</td>
              <td dir='ltr'>{$it['profile_cm']} cm</td><td>".number_format($it['row_total']??0)."</td></tr>";
    echo "</table></div></body></html>";
}

function baseDocHead(string $id, string $title, string $phone=''): void {
    echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'>
    <style>body{font-family:Tahoma;padding:20px;font-size:13px}.box{border:1px solid #000;padding:30px;max-width:850px;margin:auto}
    table{width:100%;border-collapse:collapse;margin-bottom:20px}td,th{border:1px solid #000;padding:10px;text-align:center}
    th{background:#eee}@media print{.np{display:none}.box{border:none;padding:0}}</style></head>
    <body><div class='np' style='text-align:center;margin-bottom:20px'>
    <button onclick='window.print()' style='padding:10px 20px;cursor:pointer'>🖨 چاپ سند</button></div>
    <div class='box'><h2 style='border-bottom:2px solid #000;padding-bottom:10px;display:flex;justify-content:space-between'>
    <span>Liner Light | $title</span><span style='font-size:14px;font-weight:normal'>کد: $id".($phone?" | $phone":"")."</span></h2>";
}

// ─── Utils ─────────────────────────────────────────────

function requireAdmin(): void {
    if (!isset($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        echo json_encode(['status'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function parseUserAgent(string $ua): string {
    $os = match(true) {
        stripos($ua,'windows')!==false => 'Windows',
        stripos($ua,'iphone')!==false || stripos($ua,'mac')!==false => 'Apple',
        stripos($ua,'android')!==false => 'Android',
        stripos($ua,'linux')!==false   => 'Linux',
        default => 'ناشناخته',
    };
    $br = match(true) {
        stripos($ua,'firefox')!==false => 'Firefox',
        stripos($ua,'chrome')!==false  => 'Chrome',
        stripos($ua,'safari')!==false  => 'Safari',
        default => 'ناشناخته',
    };
    return "$os | $br";
}
