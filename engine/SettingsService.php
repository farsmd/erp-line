<?php
/**
 * SettingsService.php — مدیریت تنظیمات ERP
 * مسئولیت: خواندن، نوشتن، export/import و اعتبارسنجی تنظیمات
 */
require_once __DIR__ . '/Engine.php';

class SettingsService {

    private LinerLightEngine $db;

    // اسکیمای پیش‌فرض — تمام تنظیمات سیستم اینجاست
    private array $schema = [

        // ── برند و ظاهر ──
        'site.brand_name'       => ['type' => 'text',   'default' => 'لاینر لایت',    'label' => 'نام برند'],
        'site.brand_name_en'    => ['type' => 'text',   'default' => 'Liner Light',    'label' => 'نام برند (انگلیسی)'],
        'site.brand_logo'       => ['type' => 'image',  'default' => '/images/logo.png','label' => 'لوگو'],
        'site.contact_phone'    => ['type' => 'text',   'default' => '09366121221',    'label' => 'شماره تماس'],
        'site.footer_text'      => ['type' => 'text',   'default' => 'تمامی حقوق محفوظ است.', 'label' => 'متن فوتر'],
        'site.hero_title'       => ['type' => 'text',   'default' => 'تولیدکننده تخصصی نورهای خطی', 'label' => 'تیتر صفحه اصلی'],
        'site.hero_subtitle'    => ['type' => 'textarea','default' => 'از پروژه‌های بزرگ معماری تا نورپردازی اختصاصی کمد و کابینت.', 'label' => 'زیرتیتر صفحه اصلی'],

        // ── رنگ‌ها ──
        'theme.color_primary'   => ['type' => 'color',  'default' => '#2563eb',  'label' => 'رنگ اصلی'],
        'theme.color_primary_dark' => ['type' => 'color','default' => '#1d4ed8', 'label' => 'رنگ اصلی تیره'],
        'theme.color_gold'      => ['type' => 'color',  'default' => '#c59b4e',  'label' => 'رنگ طلایی'],
        'theme.color_gold_dark' => ['type' => 'color',  'default' => '#b3883c',  'label' => 'رنگ طلایی تیره'],
        'theme.color_bg'        => ['type' => 'color',  'default' => '#f4f7fb',  'label' => 'رنگ پس‌زمینه'],
        'theme.color_text'      => ['type' => 'color',  'default' => '#0f172a',  'label' => 'رنگ متن'],
        'theme.color_success'   => ['type' => 'color',  'default' => '#16a34a',  'label' => 'رنگ موفقیت'],
        'theme.color_danger'    => ['type' => 'color',  'default' => '#dc2626',  'label' => 'رنگ خطر'],
        'theme.admin_sidebar_bg'=> ['type' => 'color',  'default' => '#0f172a',  'label' => 'رنگ سایدبار ادمین'],
        'theme.admin_accent'    => ['type' => 'color',  'default' => '#c59b4e',  'label' => 'رنگ accent ادمین'],

        // ── قیمت‌گذاری ──
        'pricing.wire_free_cm'     => ['type' => 'number', 'default' => 20,   'label' => 'سانتی‌متر سیم رایگان'],
        'pricing.wire_rate_per_cm' => ['type' => 'number', 'default' => 400,  'label' => 'نرخ سیم اضافه (تومان/سانت)'],
        'pricing.min_length_cm'    => ['type' => 'number', 'default' => 50,   'label' => 'حداقل طول محاسباتی (سانت)'],
        'pricing.tiers'            => ['type' => 'json',   'default' => [
            ['min' => 15,  'max' => 29,   'discount' => 3],
            ['min' => 30,  'max' => 59,   'discount' => 6],
            ['min' => 60,  'max' => 119,  'discount' => 9],
            ['min' => 120, 'max' => 9999, 'discount' => 12],
        ], 'label' => 'تیرهای تخفیف'],

        // ── جریان سفارش ──
        'order_flow.statuses'      => ['type' => 'json', 'default' => [
            ['key' => 'در انتظار بررسی', 'label' => 'بررسی اولیه',      'color' => '#f59e0b', 'icon' => '⏳'],
            ['key' => 'تایید شده',       'label' => 'صدور فاکتور',      'color' => '#3b82f6', 'icon' => '✅'],
            ['key' => 'در حال ساخت',     'label' => 'ورود به خط تولید', 'color' => '#8b5cf6', 'icon' => '🔧'],
            ['key' => 'کنترل کیفیت',     'label' => 'تست و QC',         'color' => '#06b6d4', 'icon' => '🔍'],
            ['key' => 'آماده ارسال',     'label' => 'بسته‌بندی',        'color' => '#10b981', 'icon' => '📦'],
            ['key' => 'تکمیل شده',       'label' => 'تحویل مشتری',      'color' => '#16a34a', 'icon' => '🎉'],
        ], 'label' => 'مراحل سفارش'],
        'order_flow.require_phone' => ['type' => 'bool', 'default' => true,  'label' => 'الزام شماره موبایل'],
        'order_flow.require_name'  => ['type' => 'bool', 'default' => false, 'label' => 'الزام نام پروژه'],

        // ── فیلدهای سفارش سفارشی ──
        'order_flow.custom_fields' => ['type' => 'json', 'default' => [], 'label' => 'فیلدهای سفارشی'],

        // ── صف تولید ──
        'queue.daily_capacity' => ['type' => 'number', 'default' => 30, 'label' => 'ظرفیت روزانه (متر)'],
        'queue.buffer_days'    => ['type' => 'number', 'default' => 4,  'label' => 'روزهای بافر'],
        'queue.wastage_percent'=> ['type' => 'number', 'default' => 5,  'label' => 'درصد ضایعات'],

        // ── ادمین ──
        'admin.password'       => ['type' => 'password', 'default' => 'far1230010', 'label' => 'رمز ادمین'],
        'admin.session_hours'  => ['type' => 'number',   'default' => 8,            'label' => 'مدت جلسه (ساعت)'],
    ];

    public function __construct(LinerLightEngine $db) {
        $this->db = $db;
    }

    // ─────────────────────────────────────────
    // GET — با پشتیبانی از مقدار پیش‌فرض
    // ─────────────────────────────────────────

    public function get(string $key): mixed {
        $stored = $this->db->getSettings($key);
        if ($stored !== null) return $stored;
        return $this->schema[$key]['default'] ?? null;
    }

    public function getAll(): array {
        $result = [];
        foreach ($this->schema as $key => $meta) {
            $result[$key] = [
                'value'   => $this->get($key),
                'type'    => $meta['type'],
                'label'   => $meta['label'],
                'default' => $meta['default'],
            ];
        }
        return $result;
    }

    // گروه‌بندی‌شده — برای UI ادمین
    public function getAllGrouped(): array {
        $grouped = [];
        foreach ($this->schema as $key => $meta) {
            [$group] = explode('.', $key, 2);
            $grouped[$group][$key] = [
                'value'   => $this->get($key),
                'type'    => $meta['type'],
                'label'   => $meta['label'],
                'default' => $meta['default'],
            ];
        }
        return $grouped;
    }

    // ─────────────────────────────────────────
    // SET
    // ─────────────────────────────────────────

    public function set(string $key, mixed $value): void {
        if (!isset($this->schema[$key])) {
            throw new RuntimeException("کلید تنظیمات نامعتبر: $key");
        }
        $value = $this->cast($key, $value);
        $this->db->updateSettings($key, $value);
    }

    public function setBulk(array $data): array {
        $updated = [];
        $errors  = [];

        foreach ($data as $key => $value) {
            try {
                $this->set($key, $value);
                $updated[] = $key;
            } catch (Exception $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    // ─────────────────────────────────────────
    // CUSTOM FIELDS — فیلدهای سفارشی
    // ─────────────────────────────────────────

    public function getCustomFields(): array {
        return $this->get('order_flow.custom_fields') ?? [];
    }

    public function addCustomField(array $field): void {
        $fields = $this->getCustomFields();

        // اعتبارسنجی
        if (empty($field['id']))    throw new RuntimeException('شناسه فیلد الزامی است.');
        if (empty($field['label'])) throw new RuntimeException('برچسب فیلد الزامی است.');
        if (empty($field['type']))  throw new RuntimeException('نوع فیلد الزامی است.');

        $allowedTypes = ['text', 'number', 'select', 'textarea', 'checkbox', 'color_picker'];
        if (!in_array($field['type'], $allowedTypes)) {
            throw new RuntimeException('نوع فیلد نامعتبر است.');
        }

        // بررسی تکراری نبودن id
        foreach ($fields as $f) {
            if ($f['id'] === $field['id']) {
                throw new RuntimeException("فیلدی با شناسه '{$field['id']}' قبلاً وجود دارد.");
            }
        }

        $fields[] = [
            'id'          => $field['id'],
            'label'       => $field['label'],
            'type'        => $field['type'],
            'required'    => !empty($field['required']),
            'placeholder' => $field['placeholder'] ?? '',
            'options'     => $field['options'] ?? [],     // برای نوع select
            'default'     => $field['default'] ?? '',
            'hint'        => $field['hint'] ?? '',
            'print_on_label' => !empty($field['print_on_label']),
            'show_in_workshop'=> !empty($field['show_in_workshop']),
        ];

        $this->db->updateSettings('order_flow.custom_fields', $fields);
    }

    public function updateCustomField(string $fieldId, array $updates): void {
        $fields = $this->getCustomFields();
        $found  = false;

        foreach ($fields as &$f) {
            if ($f['id'] === $fieldId) {
                $f = array_merge($f, $updates);
                $f['id'] = $fieldId; // id تغییر نمی‌کند
                $found = true;
                break;
            }
        }

        if (!$found) throw new RuntimeException("فیلد '$fieldId' یافت نشد.");
        $this->db->updateSettings('order_flow.custom_fields', $fields);
    }

    public function deleteCustomField(string $fieldId): void {
        $fields  = $this->getCustomFields();
        $filtered = array_values(array_filter($fields, fn($f) => $f['id'] !== $fieldId));

        if (count($filtered) === count($fields)) {
            throw new RuntimeException("فیلد '$fieldId' یافت نشد.");
        }

        $this->db->updateSettings('order_flow.custom_fields', $filtered);
    }

    public function reorderCustomFields(array $orderedIds): void {
        $fields  = $this->getCustomFields();
        $indexed = [];
        foreach ($fields as $f) $indexed[$f['id']] = $f;

        $reordered = [];
        foreach ($orderedIds as $id) {
            if (isset($indexed[$id])) $reordered[] = $indexed[$id];
        }

        $this->db->updateSettings('order_flow.custom_fields', $reordered);
    }

    // ─────────────────────────────────────────
    // CSS GENERATOR — تولید CSS داینامیک از تنظیمات
    // ─────────────────────────────────────────

    public function generateCSS(): string {
        $p  = fn(string $k) => $this->get($k);

        return ":root {
    --primary:          {$p('theme.color_primary')};
    --primary-dark:     {$p('theme.color_primary_dark')};
    --gold:             {$p('theme.color_gold')};
    --gold-dark:        {$p('theme.color_gold_dark')};
    --gold-bg:          {$this->hexWithOpacity($p('theme.color_gold'), 0.08)};
    --gold-border:      {$this->hexWithOpacity($p('theme.color_gold'), 0.25)};
    --bg:               {$p('theme.color_bg')};
    --text:             {$p('theme.color_text')};
    --success:          {$p('theme.color_success')};
    --danger:           {$p('theme.color_danger')};
    --card:             #ffffff;
    --line:             #e5e7eb;
    --muted:            #64748b;
    --shadow:           0 10px 30px rgba(15,23,42,.08);
    --radius:           18px;
}";
    }

    // ─────────────────────────────────────────
    // CONFIG.JS GENERATOR — برای frontend
    // ─────────────────────────────────────────

    public function generateConfigJS(array $products): string {
        $tiers        = $this->get('pricing.tiers');
        $pricingRules = [
            'FREE_WIRE_CM'      => $this->get('pricing.wire_free_cm'),
            'EXTRA_WIRE_PER_CM' => $this->get('pricing.wire_rate_per_cm'),
            'MIN_LENGTH_CM'     => $this->get('pricing.min_length_cm'),
        ];

        // ساخت PRODUCTS_DATA از محصولات DB
        $productsData = [];
        foreach ($products as $p) {
            $colors = is_array($p['allowed_colors'])
                ? $p['allowed_colors']
                : array_map('trim', explode('،', $p['allowed_colors']));
            $lights = is_array($p['allowed_lights'])
                ? $p['allowed_lights']
                : array_map('trim', explode('،', $p['allowed_lights']));

            $productsData[$p['id']] = [
                'title'         => $p['title'],
                'price'         => (int)$p['price'],
                'image'         => $p['image'],
                'desc'          => $p['description'],
                'allowedColors' => $colors,
                'allowedLights' => $lights,
            ];
        }

        $customFields = $this->getCustomFields();
        $siteName     = $this->get('site.brand_name');
        $siteNameEn   = $this->get('site.brand_name_en');
        $phone        = $this->get('site.contact_phone');

        $tiersJson        = json_encode($tiers,        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $pricingJson      = json_encode($pricingRules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $productsJson     = json_encode($productsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $customFieldsJson = json_encode($customFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return "/**
 * config.js — تولیدشده خودکار توسط ERP
 * آخرین به‌روزرسانی: " . date('Y/m/d H:i') . "
 * این فایل را دستی ویرایش نکنید — از پنل ادمین تغییر دهید
 */

const SITE = {
    name:   '{$siteName}',
    nameEn: '{$siteNameEn}',
    phone:  '{$phone}',
};

const PRICING_RULES = {$pricingJson};

const TIERS = {$tiersJson};

const PRODUCTS_DATA = {$productsJson};

const CUSTOM_FIELDS = {$customFieldsJson};
";
    }

    // ─────────────────────────────────────────
    // EXPORT / IMPORT — بک‌آپ و ریستور
    // ─────────────────────────────────────────

    public function export(): array {
        return [
            'version'    => '2.0',
            'exported_at'=> date('Y/m/d H:i:s'),
            'settings'   => $this->getAllGrouped(),
            'products'   => $this->db->getProductsConfig(),
            'bom_rules'  => $this->db->getBomRules(),
        ];
    }

    public function import(array $data): array {
        if (($data['version'] ?? '') !== '2.0') {
            throw new RuntimeException('نسخه فایل پشتیبان پشتیبانی نمی‌شود.');
        }

        $result = ['settings' => [], 'errors' => []];

        // ریستور تنظیمات
        if (!empty($data['settings'])) {
            foreach ($data['settings'] as $group => $items) {
                foreach ($items as $key => $item) {
                    try {
                        if ($key === 'admin.password') continue; // رمز ادمین را بازگردانی نمی‌کنیم
                        $this->set($key, $item['value']);
                        $result['settings'][] = $key;
                    } catch (Exception $e) {
                        $result['errors'][$key] = $e->getMessage();
                    }
                }
            }
        }

        // ریستور محصولات
        if (!empty($data['products'])) {
            $this->db->replaceProducts($data['products']);
        }

        // ریستور BOM
        if (!empty($data['bom_rules'])) {
            $this->db->replaceBomRules($data['bom_rules']);
        }

        return $result;
    }

    // ─────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────

    private function cast(string $key, mixed $value): mixed {
        $type = $this->schema[$key]['type'];
        return match ($type) {
            'number'   => (float)$value,
            'bool'     => (bool)$value,
            'json'     => is_string($value) ? json_decode($value, true) : $value,
            'password' => !empty($value) ? $value : $this->get($key),
            default    => (string)$value,
        };
    }

    private function hexWithOpacity(string $hex, float $opacity): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgba($r,$g,$b,$opacity)";
    }
}
