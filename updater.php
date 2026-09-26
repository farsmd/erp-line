<?php
/**
 * updater.php — سیستم بررسی و دانلود خودکار آپدیت‌ها از GitHub
 * مسئولیت: بررسی نسخه، دانلود تغییرات و به‌روزرسانی فایل‌ها
 */

$config = [
    'repo_owner'    => 'farsmd',
    'repo_name'     => 'erp-line',
    'repo_branch'   => 'main',
    'current_version' => file_get_contents('.version') ?: '0.0.1',
    'github_api'    => 'https://api.github.com',
    'backup_dir'    => '.backups',
];

session_start();

// ─────────────────────────────────────────
// تابع کنترل دسترسی
// ─────────────────────────────────────────

function requireAdmin() {
    if (!isset($_SESSION['updater_admin'])) {
        http_response_code(401);
        die(json_encode(['error' => 'دسترسی رد شد']));
    }
}

// ─────────────────────────────────────────
// ورود مدیر
// ─────────────────────────────────────────

if ($_GET['action'] === 'login' && $_POST) {
    $password = $_POST['password'] ?? '';
    $saved = file_get_contents('.admin_pass') ?: 'far1230010';
    
    if ($password === trim($saved)) {
        $_SESSION['updater_admin'] = true;
        header('Location: updater.php?action=status');
        exit;
    } else {
        $error = 'رمز عبور اشتباه است';
    }
}

if ($_GET['action'] === 'logout') {
    session_destroy();
    header('Location: updater.php');
    exit;
}

// ─────────────────────────────────────────
// بررسی وضعیت
// ─────────────────────────────────────────

if ($_GET['action'] === 'status') {
    requireAdmin();
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $latestCommit = fetchLatestCommit($config);
        $currentCommit = file_get_contents('.commit_hash') ?: 'unknown';
        
        $updateAvailable = ($latestCommit['sha'] !== $currentCommit);
        
        echo json_encode([
            'status' => 'success',
            'current_version' => $config['current_version'],
            'current_commit' => substr($currentCommit, 0, 7),
            'latest_commit' => substr($latestCommit['sha'], 0, 7),
            'update_available' => $updateAvailable,
            'latest_message' => $latestCommit['commit']['message'] ?? '',
            'last_check' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────
// دانلود و اعمال آپدیت
// ─────────────────────────────────────────

if ($_POST['action'] === 'update') {
    requireAdmin();
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // ۱. گرفتن اطلاعات جدید
        $latestCommit = fetchLatestCommit($config);
        $files = fetchRepoFiles($config);
        
        // ۲. ایجاد backup
        $backupName = 'backup_' . date('Y-m-d_H-i-s');
        createBackup($backupName);
        
        // ۳. دانلود و نوشتن فایل‌ها
        $updated = [];
        $failed = [];
        
        foreach ($files as $file) {
            try {
                updateFile($file, $config);
                $updated[] = $file['path'];
            } catch (Exception $e) {
                $failed[] = ['file' => $file['path'], 'error' => $e->getMessage()];
            }
        }
        
        // ۴. ثبت نسخه جدید
        file_put_contents('.commit_hash', $latestCommit['sha']);
        file_put_contents('.version', $latestCommit['commit']['message'] ?? date('Y.m.d'));
        file_put_contents('.last_update', date('Y-m-d H:i:s'));
        
        echo json_encode([
            'status' => 'success',
            'message' => 'آپدیت با موفقیت انجام شد',
            'updated_files' => count($updated),
            'failed_files' => count($failed),
            'backup_name' => $backupName,
            'failed' => $failed,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────
// بازگردانی از Backup
// ─────────────────────────────────────────

if ($_POST['action'] === 'restore' && $_POST['backup_name']) {
    requireAdmin();
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $backupName = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['backup_name']);
        $backupPath = $config['backup_dir'] . '/' . $backupName . '.tar.gz';
        
        if (!file_exists($backupPath)) {
            throw new Exception('فایل backup یافت نشد');
        }
        
        // استخراج و بازگردانی
        exec("cd " . escapeshellarg(dirname(__DIR__)) . " && tar -xzf " . escapeshellarg($backupPath), $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception('خطا در بازگردانی backup');
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => 'پروژه از backup بازگردانی شد',
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────
// لیست Backups
// ─────────────────────────────────────────

if ($_GET['action'] === 'backups') {
    requireAdmin();
    
    header('Content-Type: application/json; charset=utf-8');
    
    if (!is_dir($config['backup_dir'])) {
        echo json_encode(['backups' => []]);
        exit;
    }
    
    $backups = [];
    foreach (scandir($config['backup_dir']) as $file) {
        if (preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.tar\.gz$/', $file)) {
            $backups[] = [
                'name' => str_replace('.tar.gz', '', $file),
                'size' => formatBytes(filesize($config['backup_dir'] . '/' . $file)),
                'date' => filemtime($config['backup_dir'] . '/' . $file),
            ];
        }
    }
    
    rsort($backups);
    
    echo json_encode(['backups' => $backups]);
    exit;
}

// ─────────────────────────────────────────
// Helper Functions
// ─────────────────────────────────────────

function fetchLatestCommit($config) {
    $url = "{$config['github_api']}/repos/{$config['repo_owner']}/{$config['repo_name']}/commits/{$config['repo_branch']}";
    $options = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: ERP-Line-Updater/1.0',
            'timeout' => 10,
        ]
    ]);
    
    $response = @file_get_contents($url, false, $options);
    if ($response === false) {
        throw new Exception('خطا در اتصال به GitHub API');
    }
    
    return json_decode($response, true);
}

function fetchRepoFiles($config) {
    $url = "{$config['github_api']}/repos/{$config['repo_owner']}/{$config['repo_name']}/git/trees/{$config['repo_branch']}?recursive=1";
    $options = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: ERP-Line-Updater/1.0',
            'timeout' => 10,
        ]
    ]);
    
    $response = @file_get_contents($url, false, $options);
    if ($response === false) {
        throw new Exception('خطا در دریافت لیست فایل‌ها');
    }
    
    $data = json_decode($response, true);
    
    // فقط بلاب‌ها (فایل‌ها) و نه درخت‌ها
    return array_filter($data['tree'] ?? [], fn($item) => $item['type'] === 'blob');
}

function updateFile($file, $config) {
    $url = "https://raw.githubusercontent.com/{$config['repo_owner']}/{$config['repo_name']}/{$config['repo_branch']}/{$file['path']}";
    $content = @file_get_contents($url);
    
    if ($content === false) {
        throw new Exception("خطا در دانلود: {$file['path']}");
    }
    
    // ایجاد پوشه اگر لزم باشد
    $dir = dirname($file['path']);
    if (!is_dir($dir) && $dir !== '.') {
        mkdir($dir, 0755, true);
    }
    
    if (file_put_contents($file['path'], $content) === false) {
        throw new Exception("خطا در نوشتن: {$file['path']}");
    }
}

function createBackup($name) {
    global $config;
    
    if (!is_dir($config['backup_dir'])) {
        mkdir($config['backup_dir'], 0755, true);
    }
    
    $exclude = [
        '.backups',
        'updater.php',
        '.git',
        'database.sqlite',
        '.admin_pass',
    ];
    
    $excludeStr = implode(' ', array_map(fn($e) => "--exclude='$e'", $exclude));
    $cmd = "tar -czf {$config['backup_dir']}/{$name}.tar.gz $excludeStr .";
    
    exec($cmd, $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception('خطا در ایجاد backup');
    }
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

// ─────────────────────────────────────────
// صفحه HTML
// ─────────────────────────────────────────

if (!isset($_SESSION['updater_admin'])) {
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود سیستم آپدیت</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: Tahoma, sans-serif;
            background: linear-gradient(135deg, #1e3a8a, #2563eb);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
            padding: 40px;
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-box h1 { font-size: 24px; color: #0f172a; margin-bottom: 10px; }
        .login-box p { color: #64748b; font-size: 14px; margin-bottom: 30px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; color: #0f172a; margin-bottom: 6px; font-weight: bold; }
        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            outline: none;
        }
        .form-group input:focus { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,.1); }
        .btn { 
            width: 100%;
            padding: 12px 16px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: background .2s;
        }
        .btn:hover { background: #1d4ed8; }
        .error { color: #dc2626; font-size: 13px; padding: 12px; background: #fef2f2; border-radius: 8px; margin-bottom: 18px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>🔐 سیستم آپدیت</h1>
        <p>لاینر لایت - ERP</p>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>رمز عبور</label>
                <input type="password" name="password" required autofocus>
            </div>
            <button class="btn" type="submit">ورود</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سیستم آپدیت | ERP Line</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: Tahoma, sans-serif;
            background: #f3f6fb;
            color: #0f172a;
            padding: 20px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 0 10px 28px rgba(15,23,42,.08);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 22px; }
        .btn-logout { padding: 10px 16px; background: #fef2f2; color: #dc2626; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; }
        .panel {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 28px rgba(15,23,42,.08);
            padding: 24px;
            margin-bottom: 20px;
        }
        .panel h2 { font-size: 18px; margin-bottom: 18px; border-bottom: 2px solid #e5e7eb; padding-bottom: 12px; }
        .status-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px dashed #e5e7eb; }
        .status-row:last-child { border-bottom: none; }
        .status-label { color: #64748b; font-size: 13px; }
        .status-value { font-weight: bold; font-size: 14px; }
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge.green { background: #ecfdf5; color: #166534; }
        .badge.yellow { background: #fef3c7; color: #a16207; }
        .badge.red { background: #fef2f2; color: #dc2626; }
        .actions { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .btn {
            padding: 12px 18px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: all .2s;
        }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-success { background: #16a34a; color: white; }
        .btn-success:hover { background: #15803d; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .loading { display: none; padding: 20px; text-align: center; color: #2563eb; }
        .success { background: #ecfdf5; border: 1px solid #86efac; color: #166534; padding: 12px; border-radius: 8px; margin-bottom: 12px; }
        .error { background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; padding: 12px; border-radius: 8px; margin-bottom: 12px; }
        .backups-list { margin-top: 20px; }
        .backup-item { background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .backup-info { font-size: 13px; }
        .backup-date { color: #64748b; font-size: 12px; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 سیستم آپدیت خودکار</h1>
            <a href="?action=logout" class="btn-logout">خروج</a>
        </div>

        <div class="panel">
            <h2>وضعیت نسخه</h2>
            <div id="statusContent">
                <div class="loading" style="display:block;">⏳ در حال بررسی...</div>
            </div>
            <div id="messages"></div>
            <div class="actions">
                <button class="btn btn-primary" id="btnCheck" onclick="checkUpdate()">🔍 بررسی به‌روزرسانی‌ها</button>
                <button class="btn btn-success" id="btnUpdate" onclick="applyUpdate()" style="display:none;">✅ دانلود و نصب آپدیت</button>
            </div>
        </div>

        <div class="panel">
            <h2>📦 Backup‌های قدیمی</h2>
            <div id="backupsList">
                <div class="loading">⏳ در حال بارگذاری...</div>
            </div>
        </div>
    </div>

    <script>
        function checkUpdate() {
            const btn = document.getElementById('btnCheck');
            btn.disabled = true;
            fetch('updater.php?action=status')
                .then(r => r.json())
                .then(data => {
                    const html = `
                        <div class="status-row">
                            <span class="status-label">نسخه فعلی</span>
                            <span class="status-value">${data.current_version}</span>
                        </div>
                        <div class="status-row">
                            <span class="status-label">کامیت فعلی</span>
                            <span class="status-value" dir="ltr">${data.current_commit}</span>
                        </div>
                        <div class="status-row">
                            <span class="status-label">آخرین کامیت</span>
                            <span class="status-value" dir="ltr">${data.latest_commit}</span>
                        </div>
                        <div class="status-row">
                            <span class="status-label">آخرین پیام کامیت</span>
                            <span class="status-value">${data.latest_message}</span>
                        </div>
                        <div class="status-row">
                            <span class="status-label">وضعیت</span>
                            <span class="badge ${data.update_available ? 'yellow' : 'green'}">
                                ${data.update_available ? '🔴 آپدیت موجود است' : '✅ نسخه به‌روز است'}
                            </span>
                        </div>
                    `;
                    document.getElementById('statusContent').innerHTML = html;
                    document.getElementById('btnUpdate').style.display = data.update_available ? 'block' : 'none';
                    document.getElementById('btnCheck').disabled = false;
                })
                .catch(e => {
                    document.getElementById('messages').innerHTML = `<div class="error">❌ خطا: ${e}</div>`;
                    btn.disabled = false;
                });
        }

        function applyUpdate() {
            if (!confirm('آیا مطمئن‌اید؟ یک backup قبل از آپدیت ایجاد می‌شود.')) return;
            
            const btn = document.getElementById('btnUpdate');
            btn.disabled = true;
            const msgDiv = document.getElementById('messages');
            msgDiv.innerHTML = '<div class="loading">⏳ در حال دانلود و نصب...</div>';

            fetch('updater.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=update'
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    msgDiv.innerHTML = `
                        <div class="success">
                            ✅ ${data.message}<br>
                            فایل‌های آپدیت شده: ${data.updated_files}<br>
                            Backup: ${data.backup_name}
                        </div>
                    `;
                    setTimeout(() => location.reload(), 2000);
                } else {
                    msgDiv.innerHTML = `<div class="error">❌ خطا: ${data.error}</div>`;
                    btn.disabled = false;
                }
            })
            .catch(e => {
                msgDiv.innerHTML = `<div class="error">❌ خطا: ${e}</div>`;
                btn.disabled = false;
            });
        }

        function loadBackups() {
            fetch('updater.php?action=backups')
                .then(r => r.json())
                .then(data => {
                    if (!data.backups.length) {
                        document.getElementById('backupsList').innerHTML = '<p style="color:#64748b;">هیچ backup ثبت نشده‌ای وجود ندارد</p>';
                        return;
                    }
                    let html = '';
                    data.backups.forEach(b => {
                        html += `
                            <div class="backup-item">
                                <div class="backup-info">
                                    <div>${b.name}</div>
                                    <div class="backup-date">اندازه: ${b.size}</div>
                                </div>
                                <button class="btn btn-danger" onclick="restoreBackup('${b.name}')">بازگردانی</button>
                            </div>
                        `;
                    });
                    document.getElementById('backupsList').innerHTML = html;
                });
        }

        function restoreBackup(name) {
            if (!confirm('آیا مطمئن‌اید؟ تمام تغییرات جدید حذف خواهد شد.')) return;
            
            fetch('updater.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=restore&backup_name=' + encodeURIComponent(name)
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ خطا: ' + data.error);
                }
            });
        }

        // بارگذاری اولیه
        checkUpdate();
        loadBackups();
        setInterval(checkUpdate, 300000); // بررسی هر 5 دقیقه
    </script>
</body>
</html>
