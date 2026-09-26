<?php
/**
 * Engine.php — لایه دیتابیس (Database Layer)
 * نسخه ۲: پشتیبانی از تنظیمات کاستم، فیلدهای سفارش سفارشی
 */
class LinerLightEngine {
   
    private PDO $pdo;
    private string $dbFile;

    public function __construct(string $dbFile = null) {
        $this->dbFile = $dbFile ?? dirname(__DIR__) . '/database.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->initDB();
    }

    private function initDB(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key   TEXT PRIMARY KEY,
                setting_value TEXT,
                updated_at    TEXT DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS products (
                id               TEXT PRIMARY KEY,
                title            TEXT,
                price            INTEGER,
                cost             INTEGER DEFAULT 0,
                description      TEXT,
                image            TEXT,
                needs_cap        INTEGER DEFAULT 0,
                allowed_colors   TEXT DEFAULT 'مشکی،سفید',
                allowed_lights   TEXT DEFAULT 'آفتابی (3000K)',
                sort_order       INTEGER DEFAULT 0,
                active           INTEGER DEFAULT 1
            );
            CREATE TABLE IF NOT EXISTS bom_rules (
                id                  TEXT PRIMARY KEY,
                name                TEXT,
                unit                TEXT,
                price               REAL DEFAULT 0,
                base_type           TEXT,
                rate                REAL DEFAULT 1,
                divider             REAL DEFAULT 1,
                applicable_products TEXT DEFAULT '[\"ALL\"]',
                sort_order          INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS custom_order_fields (
                id           TEXT PRIMARY KEY,
                label        TEXT NOT NULL,
                field_type   TEXT DEFAULT 'text',
                placeholder  TEXT,
                options_json TEXT,
                required     INTEGER DEFAULT 0,
                show_on_label INTEGER DEFAULT 0,
                sort_order   INTEGER DEFAULT 0,
                active       INTEGER DEFAULT 1
            );
            CREATE TABLE IF NOT EXISTS system_logs (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                log_type   TEXT,
                ip_address TEXT,
                user_agent TEXT,
                page_url   TEXT,
                details    TEXT,
                created_at TEXT DEFAULT (datetime('now','localtime'))
            );
            CREATE TABLE IF NOT EXISTS orders (
                order_id              TEXT PRIMARY KEY,
                project_name          TEXT,
                customer_phone        TEXT,
                total_rows            INTEGER DEFAULT 0,
                total_qty             INTEGER DEFAULT 0,
                total_meters          REAL    DEFAULT 0,
                discount_rate_percent REAL    DEFAULT 0,
                grand_total_base      REAL    DEFAULT 0,
                grand_total_final     REAL    DEFAULT 0,
                status                TEXT    DEFAULT 'در انتظار بررسی',
                date_created          TEXT,
                items_json            TEXT    DEFAULT '[]',
                history_json          TEXT    DEFAULT '[]',
                custom_fields_json    TEXT    DEFAULT '{}',
                estimated_days        INTEGER DEFAULT 0,
                is_invoiced           INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS customers (
                phone                   TEXT PRIMARY KEY,
                name                    TEXT DEFAULT 'مشتری',
                category                TEXT DEFAULT 'عادی',
                wallet_balance          REAL DEFAULT 0,
                special_discount        REAL DEFAULT 0,
                office_address          TEXT,
                delivery_addresses_json TEXT DEFAULT '[]',
                total_purchases         REAL DEFAULT 0,
                created_at              TEXT DEFAULT (datetime('now','localtime'))
            );
            CREATE TABLE IF NOT EXISTS inventory (
                item_id   TEXT PRIMARY KEY,
                name      TEXT,
                unit      TEXT,
                stock     REAL DEFAULT 0,
                min_stock REAL DEFAULT 5,
                location  TEXT DEFAULT 'انبار اصلی'
            );
            CREATE TABLE IF NOT EXISTS scraps_inventory (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id  TEXT,
                length_cm REAL,
                qty       INTEGER DEFAULT 1,
                order_id  TEXT,
                status    TEXT DEFAULT 'available',
                created_at TEXT DEFAULT (datetime('now','localtime'))
            );
        ");

        // مایگریشن‌ها (idempotent)
        foreach ([
            "ALTER TABLE orders    ADD COLUMN custom_fields_json TEXT DEFAULT '{}'",
            "ALTER TABLE inventory ADD COLUMN min_stock REAL DEFAULT 5",
            "ALTER TABLE inventory ADD COLUMN location  TEXT DEFAULT 'انبار اصلی'",
            "ALTER TABLE customers ADD COLUMN wallet_balance REAL DEFAULT 0",
            "ALTER TABLE settings  ADD COLUMN updated_at TEXT",
            "ALTER TABLE products  ADD COLUMN active INTEGER DEFAULT 1",
            "ALTER TABLE products  ADD COLUMN sort_order INTEGER DEFAULT 0",
        ] as $sql) {
            try { $this->pdo->exec($sql); } catch (PDOException) {}
        }

        $this->seedDefaults();
    }

    private function seedDefaults(): void {
        $defaults = [
            'site.brand_name'   => 'لاینر لایت',
            'site.brand_logo'   => '',
            'site.contact_phone'=> '09366121221',
            'site.footer_text'  => '© لاینر لایت. تمامی حقوق محفوظ است.',
            'site.colors'       => json_encode([
                'primary' => '#2563eb',
                'gold'    => '#c59b4e',
                'bg'      => '#f4f7fb',
                'text'    => '#0f172a',
                'success' => '#16a34a',
                'danger'  => '#dc2626',
            ]),
            'pricing.tiers'     => json_encode([
                ['min'=>15,  'max'=>29,   'discount'=>3],
                ['min'=>30,  'max'=>59,   'discount'=>6],
                ['min'=>60,  'max'=>119,  'discount'=>9],
                ['min'=>120, 'max'=>9999, 'discount'=>12],
            ]),
            'pricing.wire_free_cm'     => '20',
            'pricing.wire_rate_per_cm' => '400',
            'pricing.min_length_cm'    => '50',
            'order_flow.statuses'      => json_encode([
                'در انتظار بررسی','تایید شده','در حال ساخت',
                'کنترل کیفیت','آماده ارسال','تکمیل شده',
            ]),
            'order_flow.require_phone' => '1',
            'queue.daily_capacity'     => '30',
            'queue.buffer_days'        => '4',
            'admin.theme'              => json_encode(['sidebar_color'=>'#0f172a','accent'=>'#c59b4e']),
            'wastage_percent'          => '5',
        ];

        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)"
        );
        foreach ($defaults as $k => $v) $stmt->execute([$k, $v]);
    }

    // ─── SETTINGS ───────────────────────────────────────

    public function getSetting(string $key): mixed {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val === false) return null;
        $decoded = json_decode($val, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $val;
    }

    public function getSettings(string $key): mixed { return $this->getSetting($key); }

    public function setSetting(string $key, mixed $value): void {
        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        $now = (new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('Y/m/d H:i:s');
        $this->pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?,?,?)
             ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value, updated_at=excluded.updated_at"
        )->execute([$key, $encoded, $now]);
    }

    public function updateSettings(string $key, mixed $value): void { $this->setSetting($key, $value); }

    public function getAllSettings(): array {
        $rows = $this->pdo->query("SELECT setting_key, setting_value, updated_at FROM settings")->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $decoded = json_decode($r['setting_value'], true);
            $result[$r['setting_key']] = [
                'value'      => (json_last_error()===JSON_ERROR_NONE) ? $decoded : $r['setting_value'],
                'updated_at' => $r['updated_at'],
            ];
        }
        return $result;
    }

    public function setManySettings(array $map): void {
        foreach ($map as $k => $v) $this->setSetting($k, $v);
    }

    // ─── PRODUCTS ───────────────────────────────────────

    public function getProductsConfig(): array {
        return $this->pdo->query(
            "SELECT * FROM products WHERE active=1 ORDER BY sort_order ASC, id ASC"
        )->fetchAll();
    }

    public function replaceProducts(array $products): void {
        $this->pdo->exec("DELETE FROM products");
        $stmt = $this->pdo->prepare(
            "INSERT INTO products (id,title,price,cost,description,image,needs_cap,allowed_colors,allowed_lights,sort_order,active)
             VALUES (?,?,?,?,?,?,?,?,?,?,1)"
        );
        foreach ($products as $i => $p) {
            $stmt->execute([
                $p['id'], $p['title'], (int)$p['price'], (int)($p['cost']??0),
                $p['desc']??$p['description']??'',
                $p['image']??'', !empty($p['needs_cap'])?1:0,
                is_array($p['allowed_colors']) ? implode('،',$p['allowed_colors']) : ($p['allowed_colors']??'مشکی،سفید'),
                is_array($p['allowed_lights']) ? implode('،',$p['allowed_lights']) : ($p['allowed_lights']??'آفتابی'),
                $i,
            ]);
        }
    }

    // ─── BOM RULES ──────────────────────────────────────

    public function getBomRules(): array {
        $rules = $this->pdo->query(
            "SELECT * FROM bom_rules ORDER BY sort_order ASC, id ASC"
        )->fetchAll();
        foreach ($rules as &$r) {
            $r['products'] = json_decode($r['applicable_products'], true) ?? ['ALL'];
            $r['base']     = $r['base_type'];
        }
        return $rules;
    }

    public function replaceBomRules(array $rules): void {
        $this->pdo->exec("DELETE FROM bom_rules");
        $stmt = $this->pdo->prepare(
            "INSERT INTO bom_rules (id,name,unit,price,base_type,rate,divider,applicable_products,sort_order)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        foreach ($rules as $i => $r) {
            $prods = $r['products'] ?? ['ALL'];
            $stmt->execute([
                $r['id'], $r['name'], $r['unit'], (float)$r['price'],
                $r['base']??$r['base_type'],
                (float)$r['rate'], (float)$r['divider'],
                json_encode($prods, JSON_UNESCAPED_UNICODE), $i,
            ]);
        }
    }

    // ─── CUSTOM ORDER FIELDS ─────────────────────────────

    public function getCustomOrderFields(): array {
        return $this->pdo->query(
            "SELECT * FROM custom_order_fields WHERE active=1 ORDER BY sort_order ASC"
        )->fetchAll();
    }

    public function replaceCustomOrderFields(array $fields): void {
        $this->pdo->exec("DELETE FROM custom_order_fields");
        $stmt = $this->pdo->prepare(
            "INSERT INTO custom_order_fields
                (id,label,field_type,placeholder,options_json,required,show_on_label,sort_order,active)
             VALUES (?,?,?,?,?,?,?,?,1)"
        );
        foreach ($fields as $i => $f) {
            $id = $f['id'] ?? 'field_' . ($i+1);
            $opts = is_array($f['options']??null)
                ? json_encode($f['options'], JSON_UNESCAPED_UNICODE)
                : ($f['options_json'] ?? null);
            $stmt->execute([
                $id, $f['label'], $f['field_type']??'text',
                $f['placeholder']??'', $opts,
                !empty($f['required'])?1:0,
                !empty($f['show_on_label'])?1:0,
                $i,
            ]);
        }
    }

    // ─── ORDERS ──────────────────────────────────────────

    public function getOrders(): array {
        return array_map([$this,'decodeOrder'],
            $this->pdo->query("SELECT * FROM orders ORDER BY date_created DESC")->fetchAll()
        );
    }

    public function getOrder(string $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE order_id=?");
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        return $o ? $this->decodeOrder($o) : null;
    }

    public function insertOrder(array $data): void {
        $this->pdo->prepare(
            "INSERT INTO orders
                (order_id,project_name,customer_phone,total_rows,total_qty,total_meters,
                 discount_rate_percent,grand_total_base,grand_total_final,status,date_created,
                 items_json,history_json,custom_fields_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $data['order_id'],
            $data['project_name']??'',
            $data['customer_phone'],
            $data['total_rows']??0,
            $data['total_qty']??0,
            $data['total_meters']??0,
            $data['discount_rate_percent']??0,
            $data['grand_total_base']??0,
            $data['grand_total_final']??$data['grand_total_base']??0,
            $data['status'],
            $data['date_created'],
            json_encode($data['items']??[], JSON_UNESCAPED_UNICODE),
            json_encode($data['history']??[], JSON_UNESCAPED_UNICODE),
            json_encode($data['custom_fields']??[], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function updateOrderStatus(string $id, string $status, array $history, int $days=0): void {
        $this->pdo->prepare(
            "UPDATE orders SET status=?, history_json=?, estimated_days=? WHERE order_id=?"
        )->execute([$status, json_encode($history,JSON_UNESCAPED_UNICODE), $days, $id]);
    }

    public function markOrderInvoiced(string $id): void {
        $this->pdo->prepare("UPDATE orders SET is_invoiced=1 WHERE order_id=?")->execute([$id]);
    }

    private function decodeOrder(array $o): array {
        $o['items']         = json_decode($o['items_json'],        true) ?? [];
        $o['history']       = json_decode($o['history_json'],      true) ?? [];
        $o['custom_fields'] = json_decode($o['custom_fields_json']??'{}', true) ?? [];
        return $o;
    }

    // ─── CUSTOMERS ───────────────────────────────────────

    public function getCustomers(): array {
        return $this->pdo->query("SELECT * FROM customers ORDER BY total_purchases DESC")->fetchAll();
    }

    public function upsertCustomer(string $phone, string $name='مشتری'): void {
        $this->pdo->prepare(
            "INSERT OR IGNORE INTO customers (phone,name,wallet_balance) VALUES (?,?,0)"
        )->execute([$phone, $name]);
    }

    public function addCustomerPurchase(string $phone, float $amount): void {
        $this->pdo->prepare(
            "UPDATE customers SET total_purchases=total_purchases+? WHERE phone=?"
        )->execute([$amount, $phone]);
    }

    public function getCustomerWallet(string $phone): float {
        $stmt = $this->pdo->prepare("SELECT wallet_balance FROM customers WHERE phone=?");
        $stmt->execute([$phone]);
        $val = $stmt->fetchColumn();
        if ($val === false) throw new RuntimeException("مشتری یافت نشد.");
        return (float)$val;
    }

    public function setCustomerWallet(string $phone, float $bal): void {
        $this->pdo->prepare("UPDATE customers SET wallet_balance=? WHERE phone=?")->execute([$bal,$phone]);
    }

    // ─── INVENTORY ───────────────────────────────────────

    public function getInventory(): array {
        return $this->pdo->query("SELECT * FROM inventory ORDER BY name ASC")->fetchAll();
    }

    public function upsertInventoryItem(string $id, string $name, string $unit, string $loc='انبار اصلی'): void {
        $this->pdo->prepare(
            "INSERT OR IGNORE INTO inventory (item_id,name,unit,stock,min_stock,location) VALUES (?,?,?,0,5,?)"
        )->execute([$id,$name,$unit,$loc]);
    }

    public function adjustInventoryStock(string $id, float $delta): void {
        $this->pdo->prepare(
            "UPDATE inventory SET stock=stock+? WHERE item_id=?"
        )->execute([$delta,$id]);
    }

    public function updateInventoryMeta(string $id, float $delta, float $min, string $loc): void {
        $this->pdo->prepare(
            "UPDATE inventory SET stock=stock+?, min_stock=?, location=? WHERE item_id=?"
        )->execute([$delta,$min,$loc,$id]);
    }

    public function getLowStockItems(): array {
        return $this->pdo->query(
            "SELECT * FROM inventory WHERE stock<=min_stock ORDER BY (stock-min_stock) ASC"
        )->fetchAll();
    }

    // ─── SCRAPS ──────────────────────────────────────────

    public function getAvailableScraps(): array {
        return $this->pdo->query(
            "SELECT * FROM scraps_inventory WHERE status='available' AND length_cm>=10 ORDER BY length_cm DESC"
        )->fetchAll();
    }

    public function insertScrap(string $modelId, float $len, int $qty, string $orderId): void {
        $this->pdo->prepare(
            "INSERT INTO scraps_inventory (model_id,length_cm,qty,order_id,status) VALUES (?,?,?,?,'available')"
        )->execute([$modelId,$len,$qty,$orderId]);
    }

    // ─── LOGS ────────────────────────────────────────────

    public function addLog(string $type, string $ip, string $ua, string $url, string $details=''): void {
        $now = (new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('Y/m/d H:i:s');
        $this->pdo->prepare(
            "INSERT INTO system_logs (log_type,ip_address,user_agent,page_url,details,created_at)
             VALUES (?,?,?,?,?,?)"
        )->execute([$type,$ip,$ua,$url,$details,$now]);
    }

    public function getLogs(int $limit=300): array {
        $stmt = $this->pdo->prepare("SELECT * FROM system_logs ORDER BY id DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    // ─── EXPORT / IMPORT ─────────────────────────────────

    public function exportSettings(): array {
        return [
            'version'       => '2.0',
            'exported_at'   => date('Y/m/d H:i'),
            'settings'      => $this->getAllSettings(),
            'products'      => $this->getProductsConfig(),
            'bom_rules'     => $this->getBomRules(),
            'custom_fields' => $this->getCustomOrderFields(),
        ];
    }

    public function importSettings(array $data): void {
        if (!empty($data['settings'])) {
            foreach ($data['settings'] as $k => $v) {
                $val = is_array($v) ? ($v['value'] ?? $v) : $v;
                $this->setSetting($k, $val);
            }
        }
        if (!empty($data['products']))      $this->replaceProducts($data['products']);
        if (!empty($data['bom_rules']))     $this->replaceBomRules($data['bom_rules']);
        if (!empty($data['custom_fields'])) $this->replaceCustomOrderFields($data['custom_fields']);
    }
}
