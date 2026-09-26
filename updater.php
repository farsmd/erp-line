<?php
/**
 * updater.php — بررسی و نصب آپدیت GitHub با امکان انتخاب Backup
 */
require_once __DIR__ . '/engine/session-config.php';

$config = [
    'owner' => 'farsmd',
    'repo' => 'erp-line',
    'branch' => 'main',
    'api' => 'https://api.github.com',
    'backup_dir' => __DIR__ . '/.backups',
];

function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireAdmin(): void {
    if (!empty($_SESSION['admin_logged_in']) || !empty($_SESSION['updater_admin'])) {
        $_SESSION['updater_admin'] = true;
        return;
    }
    jsonResponse(['status' => 'error', 'message' => 'لطفاً ابتدا وارد پنل مدیریت شوید.'], 401);
}

function githubGet(string $url): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "User-Agent: ERP-Line-Updater/1.0\r\nAccept: application/vnd.github+json\r\n",
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || trim($raw) === '') throw new RuntimeException('پاسخ خالی از GitHub دریافت شد.');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('پاسخ GitHub معتبر نیست.');
    return $data;
}

function latestCommit(): array {
    global $config;
    return githubGet("{$config['api']}/repos/{$config['owner']}/{$config['repo']}/commits/{$config['branch']}");
}

function repoFiles(): array {
    global $config;
    $data = githubGet("{$config['api']}/repos/{$config['owner']}/{$config['repo']}/git/trees/{$config['branch']}?recursive=1");
    if (!empty($data['truncated'])) throw new RuntimeException('حجم درخت مخزن بیش از حد مجاز است.');
    return array_values(array_filter($data['tree'] ?? [], fn($f) => ($f['type'] ?? '') === 'blob'));
}

function createBackup(): string {
    global $config;
    if (!is_dir($config['backup_dir']) && !mkdir($config['backup_dir'], 0755, true)) {
        throw new RuntimeException('امکان ساخت پوشه Backup وجود ندارد.');
    }
    $name = 'backup_' . date('Y-m-d_H-i-s');
    $path = $config['backup_dir'] . '/' . $name . '.tar.gz';
    $root = escapeshellarg(__DIR__);
    $target = escapeshellarg($path);
    $cmd = "tar -czf $target --exclude=.backups --exclude=database.sqlite --exclude=.admin_pass --exclude=updater.php -C $root .";
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($path)) throw new RuntimeException('ایجاد Backup انجام نشد.');
    return $name;
}

function downloadAndWrite(array $file): void {
    global $config;
    $path = $file['path'];
    $url = "https://raw.githubusercontent.com/{$config['owner']}/{$config['repo']}/{$config['branch']}/" . str_replace('%2F', '/', rawurlencode($path));
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: ERP-Line-Updater/1.0\r\n", 'timeout' => 30]]);
    $content = @file_get_contents($url, false, $ctx);
    if ($content === false) throw new RuntimeException("دانلود فایل ناموفق بود: $path");
    $target = __DIR__ . '/' . $path;
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException("ساخت پوشه ناموفق بود: $dir");
    if (file_put_contents($target, $content) === false) throw new RuntimeException("نوشتن فایل ناموفق بود: $path");
}

function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = $bytes > 0 ? min((int)floor(log($bytes, 1024)), 3) : 0;
    return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'status') {
    requireAdmin();
    try {
        $latest = latestCommit();
        $current = is_file(__DIR__ . '/.commit_hash') ? trim(file_get_contents(__DIR__ . '/.commit_hash')) : '';
        jsonResponse([
            'status' => 'success',
            'current_commit' => substr($current ?: 'unknown', 0, 7),
            'latest_commit' => substr($latest['sha'] ?? '', 0, 7),
            'update_available' => !empty($latest['sha']) && $latest['sha'] !== $current,
            'latest_message' => $latest['commit']['message'] ?? '',
        ]);
    } catch (Throwable $e) { jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500); }
}

if ($action === 'backups') {
    requireAdmin();
    $items = [];
    if (is_dir($config['backup_dir'])) {
        foreach (glob($config['backup_dir'] . '/backup_*.tar.gz') ?: [] as $path) {
            $items[] = ['name' => basename($path, '.tar.gz'), 'size' => formatBytes((int)filesize($path)), 'date' => filemtime($path)];
        }
    }
    usort($items, fn($a, $b) => $b['date'] <=> $a['date']);
    jsonResponse(['status' => 'success', 'backups' => $items]);
}

if ($action === 'update') {
    requireAdmin();
    try {
        $latest = latestCommit();
        if (empty($latest['sha'])) throw new RuntimeException('نسخه جدید پیدا نشد.');
        $backupRequested = filter_var($_POST['backup'] ?? '0', FILTER_VALIDATE_BOOLEAN);
        $backupName = null;
        if ($backupRequested) $backupName = createBackup();
        $updated = 0;
        foreach (repoFiles() as $file) { downloadAndWrite($file); $updated++; }
        file_put_contents(__DIR__ . '/.commit_hash', $latest['sha']);
        file_put_contents(__DIR__ . '/.version', $latest['commit']['message'] ?? date('Y-m-d H:i:s'));
        jsonResponse(['status' => 'success', 'message' => 'آپدیت با موفقیت انجام شد.', 'updated_files' => $updated, 'backup_name' => $backupName, 'backup_created' => $backupRequested]);
    } catch (Throwable $e) { jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500); }
}

if ($action === 'logout') { session_destroy(); header('Location: updater.php'); exit; }

// صفحه باید از داخل پنل مدیر باز شود؛ برای سازگاری، ورود مستقل حذف نشده است.
if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['updater_admin'])) {
    http_response_code(401);
    exit('ابتدا وارد پنل مدیریت شوید.');
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>آپدیت سیستم</title>
<style>
body{font-family:Tahoma,sans-serif;background:#f3f6fb;padding:24px;color:#0f172a}.box{max-width:760px;margin:auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 10px 28px #0f172a14}.row{display:flex;justify-content:space-between;border-bottom:1px dashed #e5e7eb;padding:12px 0}.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:22px}.btn{border:0;border-radius:10px;padding:12px 18px;cursor:pointer;font-weight:bold}.primary{background:#2563eb;color:#fff}.success{background:#16a34a;color:#fff}.muted{color:#64748b}.error{background:#fef2f2;color:#b91c1c;padding:12px;border-radius:8px;margin-top:16px}.ok{background:#ecfdf5;color:#166534;padding:12px;border-radius:8px;margin-top:16px}label{display:flex;gap:8px;align-items:center;font-size:13px;color:#475569}button:disabled{opacity:.55;cursor:not-allowed}
</style></head>
<body><main class="box"><h1>🔄 آپدیت سیستم</h1><p class="muted">آپدیت فقط از داخل نشست احراز‌شده پنل مدیریت انجام می‌شود.</p>
<section id="status"><p class="muted">در حال بررسی...</p></section>
<div class="actions"><label><input type="checkbox" id="makeBackup" checked> قبل از آپدیت Backup بگیر</label><button class="btn primary" id="check">🔍 بررسی مجدد</button><button class="btn success" id="update" hidden>✅ دانلود و نصب آپدیت</button></div><div id="message"></div></main>
<script>
const $=id=>document.getElementById(id), esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function getJson(url,options={}){const r=await fetch(url,options);const text=await r.text();let d;try{d=text?JSON.parse(text):null}catch{throw Error('پاسخ سرور JSON معتبر نیست: '+text.slice(0,160))}if(!r.ok)throw Error(d?.message||d?.error||'درخواست ناموفق بود');return d}
async function check(){ $('check').disabled=true;$('status').innerHTML='<p class="muted">در حال بررسی...</p>';try{const d=await getJson('updater.php?action=status');$('status').innerHTML=`<div class="row"><span>کامیت فعلی</span><b dir="ltr">${esc(d.current_commit)}</b></div><div class="row"><span>آخرین کامیت</span><b dir="ltr">${esc(d.latest_commit)}</b></div><div class="row"><span>آخرین تغییر</span><b>${esc(d.latest_message)}</b></div><div class="row"><span>وضعیت</span><b>${d.update_available?'🔴 آپدیت موجود است':'✅ سیستم به‌روز است'}</b></div>`;$('update').hidden=!d.update_available;$('message').innerHTML=''}catch(e){$('message').innerHTML='<div class="error">❌ '+esc(e.message)+'</div>'}$('check').disabled=false}
async function install(){if(!confirm($('makeBackup').checked?'قبل از آپدیت Backup گرفته می‌شود. ادامه؟':'بدون Backup آپدیت شود؟'))return;$('update').disabled=true;try{const body=new URLSearchParams({action:'update',backup:$('makeBackup').checked?'1':'0'});const d=await getJson('updater.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});$('message').innerHTML='<div class="ok">✅ '+esc(d.message)+(d.backup_created?' Backup: '+esc(d.backup_name):' آپدیت بدون Backup انجام شد.')+'</div>';$('update').hidden=true}catch(e){$('message').innerHTML='<div class="error">❌ '+esc(e.message)+'</div>'}$('update').disabled=false}
$('check').onclick=check;$('update').onclick=install;check();setInterval(check,300000);
</script></body></html>
