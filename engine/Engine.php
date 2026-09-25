<?php
/**
 * Engine.php — لایه دیتابیس (Database Layer)
 * مسئولیت: فقط CRUD روی SQLite
 * هیچ business logic اینجا نیست
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

    // ─────────────────────────────────────────
    // INIT
    // ─────────────────────────────────────────

    private function initDB(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key   TEXT PRIMARY KEY,
                setting_value TEXT
            );
            CREATE TABLE IF NOT EXISTS products (
                id               TEXT PRIMARY KEY,
                title            TEXT,
                price            INTEGER,
                cost             INTEGER,
                description      TEXT,
                image            TEXT,
                needs_cap        INTEGER,
                allowed_colors   TEXT,
                allowed_lights   TEXT
            );
            CREATE TABLE IF NOT EXISTS bom_rules (
                id                  TEXT PRIMARY KEY,
                name                TEXT,
                unit                TEXT,
                price               REAL,
                base_type           TEXT,
                rate                REAL,
                divider             REAL,
                applicable_products TEXT
            );
            CREATE TABLE IF NOT EXISTS system_logs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                log_type    TEXT,
                ip_address  TEXT,
                user_agent  TEXT,
                page_url    TEXT,
                details     TEXT,
                created_at  TEXT
            );
            CREATE TABLE IF NOT EXISTS orders (
                order_id             TEXT PRIMARY KEY,
                project_name         TEXT,
                customer_phone       TEXT,
                total_rows           INTEGER,
                total_qty            INTEGER,
                total_meters         REAL,
                discount_rate_percent REAL,
                grand_total_base     REAL,
                grand_total_final    REAL,
                status               TEXT,
                date_created         TEXT,
                items_json           TEXT,
                history_json         TEXT,
                estimated_days       INTEGER DEFAULT 0,
                is_invoiced          INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS customers (
                phone                    TEXT PRIMARY KEY,
                name                     TEXT,
                category                 TEXT    DEFAULT 'عادی',
                wallet_balance           REAL    DEFAULT 0,
                special_discount         REAL    DEFAULT 0,
                office_address           TEXT,
                workshop_address         TEXT,
                delivery_addresses_json  TEXT,
                total_purchases          REAL    DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS inventory (
                item_id   TEXT PRIMARY KEY,
                name      TEXT,
                unit      TEXT,
                stock     REAL DEFAULT 0,
                min_stock REAL DEFAULT 5,
                location  TEXT DEFAULT 'قفسه اصلی'
            );
            CREATE TABLE IF NOT EXISTS scraps_inventory (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id TEXT,
                length_cm REAL,
                qty      INTEGER DEFAULT 1,
                order_id TEXT,
                status   TEXT DEFAULT 'available'
            );
        ");

        // مایگریشن ستون‌های جدید (idempotent)
        $migrations = [
            "ALTER TABLE inventory  ADD COLUMN min_stock REAL DEFAULT 5",
            "ALTER TABLE inventory  ADD COLUMN location  TEXT DEFAULT 'قفسه اصلی'",
            "ALTER TABLE customers  ADD COLUMN wallet_balance REAL DEFAULT 0",
        ];
        foreach ($migrations as $sql) {
            try { $this->pdo->exec($sql); } catch (PDOException) {}
        }
    }

    // ─────────────────────────────────────────
    // SETTINGS
    // ─────────────────────────────────────────

    public function getSettings(string $key): mixed {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? json_decode($val, true) : null;
    }

    public function updateSettings(string $key, mixed $value): void {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare("
            INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
        ")->execute([$key, $json]);
    }

    // ─────────────────────────────────────────
    // PRODUCTS
    // ─────────────────────────────────────────

    public function getProductsConfig(): array {
        return $this->pdo->query("SELECT * FROM products")->fetchAll();
    }

    public function replaceProducts(array $products): void {
        $this->pdo->exec("DELETE FROM products");
        $stmt = $this->pdo->prepare("
            INSERT INTO products
                (id, title, price, cost, description, image, needs_cap, allowed_colors, allowed_lights)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($products as $p) {
            $stmt->execute([
                $p['id'], $p['title'], $p['price'],
                $p['cost'] ?? 0, $p['desc'] ?? '',
                $p['image'] ?? '', !empty($p['needs_cap']) ? 1 : 0,
                $p['allowed_colors'] ?? 'مشکی، سفید',
                $p['allowed_lights'] ?? 'آفتابی',
            ]);
        }
    }

    // ─────────────────────────────────────────
    // BOM RULES
    // ─────────────────────────────────────────

    public function getBomRules(): array {
        $rules = $this->pdo->query("SELECT * FROM bom_rules")->fetchAll();
        foreach ($rules as &$r) {
            $r['products'] = json_decode($r['applicable_products'], true);
            $r['base']     = $r['base_type']; // backward compat
        }
        return $rules;
    }

    public function replaceBomRules(array $rules): void {
        $this->pdo->exec("DELETE FROM bom_rules");
        $stmt = $this->pdo->prepare("
            INSERT INTO bom_rules (id, name, unit, price, base_type, rate, divider, applicable_products)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($rules as $r) {
            $stmt->execute([
                $r['id'], $r['name'], $r['unit'], $r['price'],
                $r['base'] ?? $r['base_type'],
                $r['rate'], $r['divider'],
                json_encode($r['products'] ?? ['ALL'], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    // ─────────────────────────────────────────
    // ORDERS
    // ─────────────────────────────────────────

    public function getOrders(): array {
        $orders = $this->pdo->query("SELECT * FROM orders ORDER BY date_created DESC")->fetchAll();
        return array_map([$this, 'decodeOrder'], $orders);
    }

    public function getOrder(string $orderId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $o = $stmt->fetch();
        return $o ? $this->decodeOrder($o) : null;
    }

    public function insertOrder(array $data): void {
        $this->pdo->prepare("
            INSERT INTO orders
                (order_id, project_name, customer_phone, total_rows, total_qty,
                 total_meters, discount_rate_percent, grand_total_base, grand_total_final,
                 status, date_created, items_json, history_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $data['order_id'],
            $data['project_name']        ?? '',
            $data['customer_phone'],
            $data['total_rows']          ?? 0,
            $data['total_qty']           ?? 0,
            $data['total_meters']        ?? 0,
            $data['discount_rate_percent'] ?? 0,
            $data['grand_total_base']    ?? 0,
            $data['grand_total_final']   ?? $data['grand_total_base'] ?? 0,
            $data['status'],
            $data['date_created'],
            json_encode($data['items']   ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($data['history'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function updateOrderStatus(string $orderId, string $newStatus, array $history, int $estimatedDays = 0): void {
        $this->pdo->prepare("
            UPDATE orders SET status = ?, history_json = ?, estimated_days = ? WHERE order_id = ?
        ")->execute([
            $newStatus,
            json_encode($history, JSON_UNESCAPED_UNICODE),
            $estimatedDays,
            $orderId,
        ]);
    }

    public function markOrderInvoiced(string $orderId): void {
        $this->pdo->prepare("UPDATE orders SET is_invoiced = 1 WHERE order_id = ?")->execute([$orderId]);
    }

    private function decodeOrder(array $o): array {
        $o['items']   = json_decode($o['items_json'],   true) ?? [];
        $o['history'] = json_decode($o['history_json'], true) ?? [];
        return $o;
    }

    // ─────────────────────────────────────────
    // CUSTOMERS
    // ─────────────────────────────────────────

    public function getCustomers(): array {
        return $this->pdo->query("SELECT * FROM customers ORDER BY total_purchases DESC")->fetchAll();
    }

    public function upsertCustomer(string $phone, string $name = 'مشتری'): void {
        $this->pdo->prepare("INSERT OR IGNORE INTO customers (phone, name, wallet_balance) VALUES (?, ?, 0)")
                  ->execute([$phone, $name]);
    }

    public function addCustomerPurchase(string $phone, float $amount): void {
        $this->pdo->prepare("UPDATE customers SET total_purchases = total_purchases + ? WHERE phone = ?")
                  ->execute([$amount, $phone]);
    }

    public function getCustomerWallet(string $phone): float {
        $stmt = $this->pdo->prepare("SELECT wallet_balance FROM customers WHERE phone = ?");
        $stmt->execute([$phone]);
        $val = $stmt->fetchColumn();
        if ($val === false) throw new RuntimeException("مشتری با این شماره یافت نشد.");
        return (float)$val;
    }

    public function setCustomerWallet(string $phone, float $newBalance): void {
        $this->pdo->prepare("UPDATE customers SET wallet_balance = ? WHERE phone = ?")
                  ->execute([$newBalance, $phone]);
    }

    // ─────────────────────────────────────────
    // INVENTORY
    // ─────────────────────────────────────────

    public function getInventory(): array {
        return $this->pdo->query("SELECT * FROM inventory ORDER BY name ASC")->fetchAll();
    }

    public function upsertInventoryItem(string $itemId, string $name, string $unit, string $defaultLocation = 'انبار اکسسوری'): void {
        $this->pdo->prepare("
            INSERT OR IGNORE INTO inventory (item_id, name, unit, stock, min_stock, location)
            VALUES (?, ?, ?, 0, 5, ?)
        ")->execute([$itemId, $name, $unit, $defaultLocation]);
    }

    public function adjustInventoryStock(string $itemId, float $qtyChange): void {
        $this->pdo->prepare("UPDATE inventory SET stock = stock + ? WHERE item_id = ?")
                  ->execute([$qtyChange, $itemId]);
    }

    public function updateInventoryMeta(string $itemId, float $qtyChange, float $minStock, string $location): void {
        $this->pdo->prepare("
            UPDATE inventory SET stock = stock + ?, min_stock = ?, location = ? WHERE item_id = ?
        ")->execute([$qtyChange, $minStock, $location, $itemId]);
    }

    public function getLowStockItems(): array {
        return $this->pdo->query("
            SELECT * FROM inventory WHERE stock <= min_stock ORDER BY (stock - min_stock) ASC
        ")->fetchAll();
    }

    // ─────────────────────────────────────────
    // SCRAPS
    // ─────────────────────────────────────────

    public function getAvailableScraps(): array {
        return $this->pdo->query("
            SELECT * FROM scraps_inventory
            WHERE status = 'available' AND length_cm >= 10
            ORDER BY length_cm DESC
        ")->fetchAll();
    }

    public function insertScrap(string $modelId, float $lengthCm, int $qty, string $orderId): void {
        $this->pdo->prepare("
            INSERT INTO scraps_inventory (model_id, length_cm, qty, order_id, status)
            VALUES (?, ?, ?, ?, 'available')
        ")->execute([$modelId, $lengthCm, $qty, $orderId]);
    }

    // ─────────────────────────────────────────
    // LOGS
    // ─────────────────────────────────────────

    public function addLog(string $type, string $ip, string $userAgent, string $url, string $details = ''): void {
        $now = (new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('Y/m/d H:i:s');
        $this->pdo->prepare("
            INSERT INTO system_logs (log_type, ip_address, user_agent, page_url, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$type, $ip, $userAgent, $url, $details, $now]);
    }

    public function getLogs(int $limit = 300): array {
        $stmt = $this->pdo->prepare("SELECT * FROM system_logs ORDER BY id DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
}
