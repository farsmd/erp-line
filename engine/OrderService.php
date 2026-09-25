<?php
/**
 * OrderService.php — لایه بیزینس لاجیک
 * مسئولیت: محاسبات، BOM، صف تولید، انبار
 * هیچ SQL مستقیمی اینجا نیست — همه چیز از طریق Engine
 */
require_once __DIR__ . '/Engine.php';

class OrderService {

    private LinerLightEngine $db;

    public function __construct(LinerLightEngine $db) {
        $this->db = $db;
    }

    // ─────────────────────────────────────────
    // INVENTORY SYNC
    // بعد از هر تغییر در products یا bom_rules صدا زده می‌شه
    // ─────────────────────────────────────────

    public function syncInventory(): void {
        foreach ($this->db->getProductsConfig() as $p) {
            $this->db->upsertInventoryItem(
                'profile_' . $p['id'],
                'شاخه خام ' . $p['title'],
                'شاخه',
                'انبار پروفیل'
            );
        }
        foreach ($this->db->getBomRules() as $r) {
            $this->db->upsertInventoryItem(
                'bom_' . $r['id'],
                $r['name'],
                $r['unit'],
                'انبار اکسسوری'
            );
        }
    }

    // ─────────────────────────────────────────
    // PRODUCTS
    // ─────────────────────────────────────────

    public function updateProducts(array $products): void {
        $this->db->replaceProducts($products);
        $this->syncInventory();
    }

    public function updateBomRules(array $rules): void {
        $this->db->replaceBomRules($rules);
        $this->syncInventory();
    }

    // ─────────────────────────────────────────
    // ORDER CREATION
    // ─────────────────────────────────────────

    public function createOrder(array $data): string {
        $orderId = 'LN-' . strtoupper(substr(uniqid(), -5));
        $now     = (new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('Y/m/d H:i:s');

        $data['order_id']    = $orderId;
        $data['status']      = 'در انتظار بررسی';
        $data['date_created'] = date('Y/m/d H:i');
        $data['history']     = [[
            'status'    => 'در انتظار بررسی',
            'timestamp' => $now,
            'note'      => 'سفارش در سیستم ثبت شد.',
        ]];

        $this->db->insertOrder($data);

        $phone = $data['customer_phone'] ?? '';
        $name  = $data['project_name']  ?? 'مشتری';
        $this->db->upsertCustomer($phone, $name);
        $this->db->addCustomerPurchase($phone, $data['grand_total_final'] ?? $data['grand_total_base'] ?? 0);

        return $orderId;
    }

    // ─────────────────────────────────────────
    // ORDER STATUS
    // ─────────────────────────────────────────

    public function changeOrderStatus(string $orderId, string $action, string $ip, string $ua): void {
        $order = $this->db->getOrder($orderId);
        if (!$order) throw new RuntimeException("سفارش یافت نشد.");

        $statusMap = [
            'approve'          => 'تایید شده',
            'reject'           => 'رد شده',
            'production'       => 'در حال ساخت',
            'qc'               => 'کنترل کیفیت',
            'ready'            => 'آماده ارسال',
            'complete'         => 'تکمیل شده',
            'revert_pending'   => 'در انتظار بررسی',
            'revert_approved'  => 'تایید شده',
            'revert_production'=> 'در حال ساخت',
            'revert_qc'        => 'کنترل کیفیت',
            'revert_ready'     => 'آماده ارسال',
        ];

        $newStatus = $statusMap[$action] ?? throw new RuntimeException("اکشن نامعتبر: $action");

        $history   = $order['history'] ?? [];
        $history[] = [
            'status'    => $newStatus,
            'timestamp' => (new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('Y/m/d H:i:s'),
        ];

        $estDays = ($newStatus === 'در حال ساخت')
            ? $this->calculateEstimatedDays($this->db->getOrders(), $order['total_meters'])
            : ($order['estimated_days'] ?? 0);

        if ($action === 'approve') {
            $this->deductOrderMaterials($orderId);
        }

        $this->db->updateOrderStatus($orderId, $newStatus, $history, $estDays);
        $this->db->addLog('admin_action', $ip, $ua, 'admin', "تغییر وضعیت سفارش $orderId به $newStatus");
    }

    // ─────────────────────────────────────────
    // WALLET
    // ─────────────────────────────────────────

    public function adjustWallet(string $phone, string $type, float $amount, string $reason): float {
        if ($amount <= 0) throw new RuntimeException("مبلغ باید بزرگتر از صفر باشد.");

        $change     = ($type === 'deduct') ? -$amount : $amount;
        $current    = $this->db->getCustomerWallet($phone);
        $newBalance = $current + $change;

        if ($newBalance < 0) {
            throw new RuntimeException(
                "موجودی کیف پول کافی نیست. مانده فعلی: " . number_format($current) . " تومان"
            );
        }

        $this->db->setCustomerWallet($phone, $newBalance);
        return $newBalance;
    }

    // ─────────────────────────────────────────
    // PROCUREMENT
    // ─────────────────────────────────────────

    public function getProcurementSuggestions(): array {
        $items = $this->db->getLowStockItems();
        foreach ($items as &$it) {
            // میزان سفارش پیشنهادی = کسری + ۵۰٪ حاشیه ایمنی
            $it['deficit'] = max(0, $it['min_stock'] - $it['stock']) + ceil($it['min_stock'] * 0.5);
        }
        return $items;
    }

    // ─────────────────────────────────────────
    // BOM — الگوریتم Bin Packing + بازیافت پرتی
    // ─────────────────────────────────────────

    public function calculateBOM(array $orderItems): array {
        $products       = $this->db->getProductsConfig();
        $bomRules       = $this->db->getBomRules();
        $wastagePercent = $this->db->getSettings('wastage_percent') ?? 5;
        $wasteMultiplier = $wastagePercent / 100;

        // ساخت map محصولات
        $productMap = [];
        foreach ($products as $p) {
            $productMap[$p['id']] = $p;
        }

        // گروه‌بندی قطعات به تفکیک مدل
        $modelStats  = [];
        $modelPieces = [];

        foreach ($orderItems as $item) {
            $modelId = $this->resolveModelId($item, $products);
            if (!$modelId) continue;

            $modelStats[$modelId]  ??= ['meters' => 0, 'pieces' => 0, 'branches' => 0];
            $modelPieces[$modelId] ??= [];

            $meters = ($item['profile_cm'] * $item['qty']) / 100;
            $modelStats[$modelId]['meters'] += $meters;
            $modelStats[$modelId]['pieces'] += $item['qty'];

            for ($i = 0; $i < $item['qty']; $i++) {
                $modelPieces[$modelId][] = (float)$item['profile_cm'];
            }
        }

        // بازیافت پرتی‌های موجود در دیتابیس
        $availableScraps = $this->db->getAvailableScraps();
        $recycledScraps  = [];
        $baseProfiles    = [];
        $scrapReport     = [];
        $totalProfileCost = 0;

        foreach ($modelPieces as $modelId => $pieces) {
            if (!isset($productMap[$modelId])) continue;

            rsort($pieces); // بزرگ‌ترین اول (First Fit Decreasing)

            // مرحله ۱: جایگزینی از پرتی‌های موجود
            $remaining = [];
            foreach ($pieces as $piece) {
                $needed = $piece + 0.5; // لقی اره
                $foundKey = null;

                foreach ($availableScraps as $k => $sc) {
                    if ($sc['model_id'] === $modelId && $sc['length_cm'] >= $needed) {
                        $foundKey = $k;
                        break;
                    }
                }

                if ($foundKey !== null) {
                    $recycledScraps[] = [
                        'model'           => $modelId,
                        'used_from_scrap' => $availableScraps[$foundKey]['length_cm'],
                        'cut_length'      => $piece,
                    ];
                    $availableScraps[$foundKey]['length_cm'] -= $needed;
                    if ($availableScraps[$foundKey]['length_cm'] < 10) {
                        unset($availableScraps[$foundKey]);
                    }
                } else {
                    $remaining[] = $piece;
                }
            }

            // مرحله ۲: Bin Packing روی شاخه‌های ۳ متری نو
            $branches = [];
            foreach ($remaining as $piece) {
                $needed = $piece + 0.5;
                $placed = false;

                foreach ($branches as $idx => $rem) {
                    if ($rem >= $needed) {
                        $branches[$idx] -= $needed;
                        $placed = true;
                        break;
                    }
                }
                if (!$placed) {
                    $branches[] = 300 - $needed;
                }
            }

            $numBranches = count($branches);
            $baseProfiles[$modelId] = $numBranches;
            $modelStats[$modelId]['branches'] = $numBranches;

            // جمع‌آوری پرتی‌های جدید قابل بازیافت
            $scraps = [];
            foreach ($branches as $rem) {
                if ($rem >= 5) {
                    $scraps[] = round($rem, 1);
                }
            }
            rsort($scraps);
            $scrapReport[$modelId] = $scraps;

            $totalProfileCost += $numBranches * ($productMap[$modelId]['cost'] ?? 0);
        }

        // محاسبه BOM اکسسوری‌ها
        $bomDetails       = [];
        $totalDynamicCost = 0;

        foreach ($bomRules as $rule) {
            [$baseVal, $appliesToOrder] = $this->calcRuleBaseValue($rule, $modelStats);

            if ($baseVal <= 0) continue;

            $divider   = max(0.0001, $rule['divider']);
            $pureQty   = ($baseVal * $rule['rate']) / $divider;
            $baseType  = $rule['base_type'] ?? $rule['base'];
            $wasteQty  = in_array($baseType, ['meter', 'branch']) ? ($pureQty * $wasteMultiplier) : 0;
            $exactQty  = $pureQty + $wasteQty;
            $itemCost  = $exactQty * $rule['price'];

            $totalDynamicCost += $itemCost;
            $bomDetails[] = [
                'id'   => $rule['id'],
                'name' => $rule['name'],
                'qty'  => round($exactQty, 3),
                'unit' => $rule['unit'],
                'cost' => $itemCost,
            ];
        }

        // تبدیل scrapReport به فرمت یکنواخت
        $flatScraps = [];
        foreach ($scrapReport as $modelId => $lengths) {
            foreach ($lengths as $len) {
                $flatScraps[] = ['model' => $modelId, 'length_cm' => $len, 'qty' => 1];
            }
        }

        return [
            'base_profiles'   => $baseProfiles,
            'bom_details'     => $bomDetails,
            'scraps'          => $flatScraps,
            'recycled_scraps' => $recycledScraps,
            'cost'            => $totalProfileCost + $totalDynamicCost,
        ];
    }

    // ─────────────────────────────────────────
    // DEDUCT MATERIALS (هنگام تایید سفارش)
    // ─────────────────────────────────────────

    public function deductOrderMaterials(string $orderId): void {
        $order = $this->db->getOrder($orderId);
        if (!$order || ($order['is_invoiced'] ?? 0) == 1) return;

        $bom = $this->calculateBOM($order['items']);

        foreach ($bom['base_profiles'] as $modelId => $qty) {
            $this->db->adjustInventoryStock('profile_' . $modelId, -$qty);
        }
        foreach ($bom['bom_details'] as $item) {
            if (!empty($item['id'])) {
                $this->db->adjustInventoryStock('bom_' . $item['id'], -$item['qty']);
            }
        }

        // ثبت پرتی‌های جدید در انبار پرتی دائمی
        foreach ($bom['scraps'] as $sc) {
            $this->db->insertScrap($sc['model'], $sc['length_cm'], $sc['qty'] ?? 1, $orderId);
        }

        $this->db->markOrderInvoiced($orderId);
    }

    // ─────────────────────────────────────────
    // QUEUE / زمان تخمینی تحویل
    // ─────────────────────────────────────────

    public function calculateEstimatedDays(array $ordersList, float $newOrderMeters): int {
        $queue    = $this->db->getSettings('queue') ?? [];
        $capacity = $queue['daily_capacity'] ?? 30;
        $buffer   = $queue['buffer_days']    ?? 4;

        $queueBranches = 0;
        foreach ($ordersList as $o) {
            if (in_array($o['status'] ?? '', ['در حال ساخت', 'کنترل کیفیت'])) {
                $queueBranches += ceil(($o['total_meters'] ?? 0) / 3);
            }
        }

        $waitDays  = floor($queueBranches / max(1, $capacity));
        $buildDays = max(1, ceil(ceil($newOrderMeters / 3) / max(1, $capacity)));

        return (int)($waitDays + $buildDays + $buffer);
    }

    // ─────────────────────────────────────────
    // HELPERS (private)
    // ─────────────────────────────────────────

    private function resolveModelId(array $item, array $products): string {
        if (!empty($item['model_id'])) return $item['model_id'];

        foreach ($products as $p) {
            if ($p['title'] === $item['model']) return $p['id'];
        }
        return '';
    }

    private function calcRuleBaseValue(array $rule, array $modelStats): array {
        $baseVal        = 0;
        $appliesToOrder = false;
        $baseType       = $rule['base_type'] ?? $rule['base'];

        foreach ($modelStats as $modelId => $stats) {
            $applies = in_array('ALL', $rule['products']) || in_array($modelId, $rule['products']);
            if (!$applies) continue;

            $appliesToOrder = true;
            $baseVal += match ($baseType) {
                'meter'  => $stats['meters'],
                'piece'  => $stats['pieces'],
                'branch' => $stats['branches'],
                default  => 0,
            };
        }

        if ($baseType === 'order' && $appliesToOrder) {
            $baseVal = 1;
        }

        return [$baseVal, $appliesToOrder];
    }
}
