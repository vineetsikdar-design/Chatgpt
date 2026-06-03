<?php
// --- 1. FIX SESSION BUG ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'config.php';

// --- AUTO-PATCH DATABASE FOR NEW FEATURES ---
try { $pdo->exec("ALTER TABLE zentrax_keys ADD COLUMN key_type VARCHAR(10) DEFAULT 'paid'"); } catch(Exception $e) {}

// ==========================================
// 1. LOGOUT LOGIC
// ==========================================
if (isset($_GET['logout'])) { session_destroy(); header("Location: admin.php"); exit; }

// ==========================================
// 2. LOGIN LOGIC
// ==========================================
$login_err = '';
if (!isset($_SESSION['admin_logged'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $user = trim($_POST['username']);
        $pass = $_POST['password'];

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'admin'");
        $stmt->execute([$user]); $admin = $stmt->fetch();

        if ($admin && password_verify($pass, $admin['password'])) {
            $_SESSION['admin_logged'] = true; 
            $_SESSION['admin_user'] = $admin['username'];
            header("Location: admin.php"); exit;
        } else { $login_err = "Invalid Credentials."; }
    }
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>ZENTRAX - Admin Login</title><script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script><link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"><style>body{background:#050505;color:#fff;font-family:'Inter', sans-serif;} .glass-card { background: #0a0a0a; border: 1px solid #1a1a1a; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }</style></head>
    <body class="flex items-center justify-center min-h-screen flex-col px-4">
        <div class="text-center mb-8">
            <div class="w-16 h-16 rounded-full border border-gray-800 flex items-center justify-center mx-auto mb-4 bg-[#111]"><i data-lucide="shield-alert" class="w-8 h-8 text-white"></i></div>
            <h1 class="text-3xl font-extrabold tracking-widest font-['Outfit'] uppercase">ZENTRAX VIP</h1>
            <p class="text-gray-500 text-xs mt-2 uppercase tracking-widest">Master Control Panel</p>
        </div>
        <div class="glass-card p-8 rounded-2xl w-full max-w-sm">
            <?php if($login_err) echo "<div class='text-red-500 text-xs mb-4 text-center font-bold'>$login_err</div>"; ?>
            <form method="POST" class="space-y-5">
                <div><label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block">USERNAME</label><input type="text" name="username" required class="w-full bg-[#050505] border border-gray-800 p-4 text-white rounded-lg outline-none focus:border-gray-500 transition text-sm"></div>
                <div><label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block">KEY (PASSWORD)</label><input type="password" name="password" required class="w-full bg-[#050505] border border-gray-800 p-4 text-white rounded-lg outline-none focus:border-gray-500 transition text-sm"></div>
                <button type="submit" name="login" class="w-full bg-white hover:bg-gray-200 text-black p-4 rounded-lg font-bold text-sm transition uppercase tracking-widest mt-2">ACCESS SYSTEM</button>
            </form>
        </div>
        <script>lucide.createIcons();</script>
    </body></html>
    <?php exit;
}

// ==========================================
// 3. POST ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $page_redirect = $_GET['page'] ?? 'dashboard';

    // 1. GENERATE KEYS
    if (isset($_POST['generate'])) {
        $key_type = $_POST['key_type'] ?? 'paid';
        $max_ips = ($_POST['max_ips']==='custom') ? (int)$_POST['custom_ip'] : (int)$_POST['max_ips'];
        $dur_val = (int)$_POST['duration_val'];
        $dur_unit = $_POST['duration_unit'];
        $feature = $_POST['feature'];
        $generated = [];

        $stmt = $pdo->prepare("INSERT INTO zentrax_keys (license_key, feature_name, duration_val, duration_unit, max_ips, key_type, created_by) VALUES (?, ?, ?, ?, ?, ?, 'admin')");

        if (isset($_POST['is_custom']) && !empty(trim($_POST['custom_key']))) {
            $key = strtoupper(trim($_POST['custom_key']));
            $chk = $pdo->prepare("SELECT id FROM zentrax_keys WHERE license_key=?"); $chk->execute([$key]);
            if ($chk->fetch()) { $_SESSION['err'] = "Custom Key '$key' already exists!"; header("Location: admin.php?page=generator"); exit; }
            $stmt->execute([$key, $feature, $dur_val, $dur_unit, $max_ips, $key_type]);
            $generated[] = $key;
            $qty = 1;
        } else {
            $qty = (int)$_POST['quantity'];
            for($i=0; $i<$qty; $i++) {
                $key = strtoupper(bin2hex(random_bytes(6))); 
                $stmt->execute([$key, $feature, $dur_val, $dur_unit, $max_ips, $key_type]);
                $generated[] = $key;
            }
        }
        
        $_SESSION['last_gen'] = ['qty' => $qty, 'dur' => "$dur_val $dur_unit", 'max_ips' => $max_ips, 'type' => strtoupper($key_type), 'keys' => implode("\n", $generated)];
        $_SESSION['download_keys'] = implode("\n", $generated);
        $page_redirect = 'generator';
    }
    
    // 2. EDIT KEY (Includes Expiry Adjust)
    if (isset($_POST['edit_key_submit'])) {
        $kid = (int)$_POST['k_id'];
        $pdo->prepare("UPDATE zentrax_keys SET duration_val=?, duration_unit=?, max_ips=? WHERE id=?")->execute([(int)$_POST['e_dur'], $_POST['e_unit'], (int)$_POST['e_max'], $kid]);
        
        // Update Expiry Date in binds if provided
        if (!empty($_POST['e_expiry'])) {
            $k_name = $pdo->query("SELECT license_key FROM zentrax_keys WHERE id=$kid")->fetchColumn();
            $pdo->prepare("UPDATE zentrax_binds SET expired_date=? WHERE license_key=?")->execute([$_POST['e_expiry'], $k_name]);
        }
        $_SESSION['msg'] = "Key Configuration Updated!";
        $page_redirect = 'keys';
    }

    // 3. BLACKLIST IP
    if (isset($_POST['add_blacklist'])) {
        $ip = trim($_POST['banned_ip']);
        $pdo->prepare("INSERT IGNORE INTO banned_ips (ip_address) VALUES (?)")->execute([$ip]);
        $pdo->prepare("DELETE FROM zentrax_binds WHERE ip_address=?")->execute([$ip]);
        $pdo->prepare("DELETE FROM zentrax_sessions WHERE ip_address=?")->execute([$ip]);
        $_SESSION['msg'] = "IP Blacklisted & Kick-banned successfully!";
        $page_redirect = 'blacklist';
    }

    // 4. MAINTENANCE MODE
    if (isset($_POST['toggle_maintenance'])) {
        if (file_exists('maintenance.flag')) { unlink('maintenance.flag'); $_SESSION['msg']="Maintenance Mode OFF"; }
        else { file_put_contents('maintenance.flag', '1'); $_SESSION['msg']="Maintenance Mode ON"; }
        $page_redirect = 'dashboard';
    }

    // 5. CREATE RESELLER
    if (isset($_POST['create_reseller'])) {
        $u = trim($_POST['r_user']); $p = password_hash($_POST['r_pass'], PASSWORD_DEFAULT); $b = (int)$_POST['r_bal'];
        try {
            $pdo->prepare("INSERT INTO users (username, password, role, balance) VALUES (?, ?, 'reseller', ?)")->execute([$u, $p, $b]);
            $_SESSION['msg'] = "Reseller $u Created Successfully!";
        } catch(Exception $e) { $_SESSION['err'] = "Username already exists!"; }
        $page_redirect = 'reseller';
    }

    // 6. MANAGE RESELLER
    if (isset($_POST['manage_reseller'])) {
        $id = (int)$_POST['r_id']; $act = $_POST['action'];
        if($act == 'add_bal') { $pdo->query("UPDATE users SET balance = balance + ".(int)$_POST['amt']." WHERE id=$id"); $_SESSION['msg']="Balance Added!"; }
        if($act == 'deduct_bal') { $pdo->query("UPDATE users SET balance = balance - ".(int)$_POST['amt']." WHERE id=$id"); $_SESSION['msg']="Balance Deducted!"; }
        if($act == 'del') { $pdo->query("DELETE FROM users WHERE id=$id"); $_SESSION['msg']="Reseller Deleted!"; }
        if($act == 'edit') {
            $nu = trim($_POST['new_u']); $np = trim($_POST['new_p']);
            if($nu) { try { $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$nu, $id]); } catch(Exception $e){} }
            if($np) { $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($np, PASSWORD_DEFAULT), $id]); }
            $_SESSION['msg']="Reseller Updated!";
        }
        $page_redirect = 'reseller';
    }

    // 7. DEPOSIT APPROVAL
    if (isset($_POST['handle_deposit'])) {
        $did = (int)$_POST['d_id']; $uid = (int)$_POST['u_id']; $amt = (int)$_POST['amt'];
        if($_POST['action'] == 'approve') {
            $pdo->query("UPDATE deposits SET status='approved' WHERE id=$did");
            $pdo->query("UPDATE users SET balance = balance + $amt WHERE id=$uid");
            $_SESSION['msg'] = "Deposit Approved & Balance Added!";
        } else {
            $pdo->query("UPDATE deposits SET status='declined' WHERE id=$did"); $_SESSION['msg'] = "Deposit Declined!";
        }
        $page_redirect = 'reseller';
    }

    // 8. PRICING
    if (isset($_POST['update_pricing'])) {
        $pdo->query("UPDATE pricing SET price=".(int)$_POST['p_1']." WHERE duration_days=1");
        $pdo->query("UPDATE pricing SET price=".(int)$_POST['p_7']." WHERE duration_days=7");
        $pdo->query("UPDATE pricing SET price=".(int)$_POST['p_15']." WHERE duration_days=15");
        $pdo->query("UPDATE pricing SET price=".(int)$_POST['p_30']." WHERE duration_days=30");
        $_SESSION['msg'] = "Pricing Updated!";
        $page_redirect = 'reseller';
    }

    header("Location: admin.php?page=" . $page_redirect); exit;
}

// ==========================================
// 4. GET ACTIONS
// ==========================================
if (isset($_GET['action'])) {
    $act = $_GET['action']; $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($act == 'ban_key') { $pdo->query("UPDATE zentrax_keys SET status = 0 WHERE id = $id"); $_SESSION['msg']="Key Banned!"; }
    if ($act == 'unban_key') { $pdo->query("UPDATE zentrax_keys SET status = 1 WHERE id = $id"); $_SESSION['msg']="Key Activated!"; }
    if ($act == 'reset_key') { $k = $pdo->query("SELECT license_key FROM zentrax_keys WHERE id = $id")->fetchColumn(); $pdo->query("DELETE FROM zentrax_binds WHERE license_key = '$k'"); $_SESSION['msg']="Hardware IPs Reset!"; }
    if ($act == 'delete_key') { $k = $pdo->query("SELECT license_key FROM zentrax_keys WHERE id = $id")->fetchColumn(); $pdo->query("DELETE FROM zentrax_keys WHERE id = $id"); $pdo->query("DELETE FROM zentrax_binds WHERE license_key = '$k'"); $_SESSION['msg']="Key completely deleted!"; }
    
    // Bulk Actions
    if ($act == 'del_unused') { $pdo->query("DELETE FROM zentrax_keys WHERE license_key NOT IN (SELECT license_key FROM zentrax_binds)"); $_SESSION['msg']="All Unused Keys Wiped!"; }
    if ($act == 'del_expired') { $pdo->query("DELETE FROM zentrax_keys WHERE license_key IN (SELECT license_key FROM zentrax_binds WHERE expired_date < NOW())"); $pdo->query("DELETE FROM zentrax_binds WHERE expired_date < NOW()"); $_SESSION['msg']="Expired Keys Wiped!"; }
    if ($act == 'del_all_free') { $pdo->query("DELETE FROM zentrax_keys WHERE key_type='free'"); $pdo->query("DELETE FROM zentrax_binds WHERE license_key NOT IN (SELECT license_key FROM zentrax_keys)"); $_SESSION['msg']="All FREE Keys Deleted!"; }
    if ($act == 'del_all_paid') { $pdo->query("DELETE FROM zentrax_keys WHERE key_type='paid'"); $pdo->query("DELETE FROM zentrax_binds WHERE license_key NOT IN (SELECT license_key FROM zentrax_keys)"); $_SESSION['msg']="All PAID Keys Deleted!"; }
    if ($act == 'del_all') { $pdo->query("TRUNCATE TABLE zentrax_keys"); $pdo->query("TRUNCATE TABLE zentrax_binds"); $_SESSION['msg']="DB OBLITERATED!"; }
    
    // Blacklist Actions
    if ($act == 'unban_ip') { $pdo->prepare("DELETE FROM banned_ips WHERE ip_address=?")->execute([$_GET['ip']]); $_SESSION['msg']="IP Unbanned!"; header("Location: admin.php?page=blacklist"); exit; }

    header("Location: admin.php?page=keys"); exit;
}

$page = $_GET['page'] ?? 'dashboard';
$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$err = $_SESSION['err'] ?? ''; unset($_SESSION['err']);
$auto_dl = $_SESSION['download_keys'] ?? ''; unset($_SESSION['download_keys']);
$last_gen = $_SESSION['last_gen'] ?? null; unset($_SESSION['last_gen']);
$is_maintenance = file_exists('maintenance.flag');

// Format Feature Names Function
function formatFeature($raw) {
    $clean = str_replace("MOD: ", "", $raw);
    if ($clean == 'BOADYANTENNA') return 'BOADY + ANTENNA';
    if ($clean == 'NECKANTENNA') return 'NECK + ANTENNA';
    if ($clean == 'DRAGHEADSHOT') return 'DRAG HEADSHOT';
    return $clean;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>ZENTRAX MASTER</title>
    <script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { background:#050505; color:#fff; font-family:'Inter', sans-serif; } 
        .nav-link.active { font-weight: bold; color: #fff; background: rgba(255,255,255,0.05); border-radius: 8px; }
        .glass-panel { background: #0a0a0a; border: 1px solid #1a1a1a; border-radius: 16px; }
        .input-dark { background: #050505; border: 1px solid #1a1a1a; border-radius: 12px; padding: 16px; color: #fff; font-size: 14px; outline: none; transition: 0.3s; }
        .input-dark:focus { border-color: #333; }
        
        /* Smooth Page Transition */
        @keyframes pageFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-page { animation: pageFadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        
        /* Custom Select Styles */
        .custom-select-container { position: relative; width: 100%; user-select: none; }
        .custom-select-trigger { background: #050505; border: 1px solid #1a1a1a; border-radius: 12px; padding: 16px; color: #fff; font-size: 14px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: 0.3s; }
        .custom-select-trigger:hover { border-color: #333; }
        .custom-select-dropdown { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #0a0a0a; border: 1px solid #222; border-radius: 12px; overflow: hidden; z-index: 50; box-shadow: 0 10px 30px rgba(0,0,0,0.8); opacity: 0; transform: translateY(-5px); pointer-events: none; transition: all 0.2s; }
        .custom-select-dropdown.open { opacity: 1; transform: translateY(0); pointer-events: auto; }
        .custom-option { padding: 14px 16px; font-size: 12px; font-weight: bold; color: #a3a3a3; cursor: pointer; transition: 0.2s; border-bottom: 1px solid #111; }
        .custom-option:last-child { border-bottom: none; }
        .custom-option:hover { background: #1a1a1a; color: #fff; }
        .custom-option.selected { color: #3b82f6; }
    </style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">
    
    <div class="md:hidden flex justify-between p-5 bg-[#0a0a0a] border-b border-[#1a1a1a] z-40 sticky top-0">
        <div class="font-bold tracking-widest text-lg font-['Outfit'] uppercase">ZENTRAX</div>
        <button onclick="document.getElementById('mobileNav').classList.toggle('hidden')"><i data-lucide="menu"></i></button>
    </div>
    
    <div id="mobileNav" class="hidden md:block w-full md:w-64 bg-[#050505] md:min-h-screen border-r border-[#1a1a1a] p-6 z-50 fixed md:sticky top-0 h-screen overflow-y-auto">
        <div class="flex justify-between items-center mb-10">
            <h1 class="text-2xl font-extrabold tracking-widest font-['Outfit'] uppercase">ZENTRAX</h1>
            <button class="md:hidden text-gray-500" onclick="document.getElementById('mobileNav').classList.add('hidden')"><i data-lucide="x"></i></button>
        </div>
        <nav class="space-y-2">
            <a href="?page=dashboard" class="flex items-center gap-3 p-3 text-gray-400 hover:text-white nav-link <?= $page=='dashboard'?'active':'' ?>"><i data-lucide="layout-dashboard" class="w-5 h-5"></i> Dashboard</a>
            <a href="?page=generator" class="flex items-center gap-3 p-3 text-gray-400 hover:text-white nav-link <?= $page=='generator'?'active':'' ?>"><i data-lucide="key" class="w-5 h-5"></i> Key Generator</a>
            <a href="?page=keys" class="flex items-center gap-3 p-3 text-gray-400 hover:text-white nav-link <?= $page=='keys'?'active':'' ?>"><i data-lucide="list" class="w-5 h-5"></i> Keys Registry</a>
            <a href="?page=blacklist" class="flex items-center gap-3 p-3 text-red-500 hover:text-red-400 nav-link <?= $page=='blacklist'?'active':'' ?>"><i data-lucide="shield-ban" class="w-5 h-5"></i> IP Blacklist</a>
            <a href="?page=reseller" class="flex items-center gap-3 p-3 text-gray-400 hover:text-white nav-link <?= $page=='reseller'?'active':'' ?>"><i data-lucide="users" class="w-5 h-5"></i> Resellers</a>
            
            <div class="pt-8 mt-8 border-t border-[#1a1a1a]">
                <a href="?logout=true" class="flex items-center gap-3 p-3 text-gray-500 hover:text-white"><i data-lucide="log-out" class="w-5 h-5"></i> Logout</a>
            </div>
        </nav>
    </div>

    <div class="flex-1 p-4 lg:p-10 overflow-y-auto animate-page">
        <?php if($msg) echo "<div class='bg-green-900/20 text-green-400 p-4 border border-green-900/50 rounded-xl mb-6 text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle' class='w-5 h-5'></i> $msg</div>"; ?>
        <?php if($err) echo "<div class='bg-red-900/20 text-red-400 p-4 border border-red-900/50 rounded-xl mb-6 text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5'></i> $err</div>"; ?>
        
        <?php if($page == 'dashboard'): ?>
            <div class="glass-panel p-8 mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h1 class="text-3xl font-extrabold mb-1 font-['Outfit'] uppercase tracking-widest">OVERVIEW</h1>
                    <p class="text-gray-500 text-sm">System Status & Analytics</p>
                </div>
                <form method="POST" class="hidden md:block">
                    <button type="submit" name="toggle_maintenance" class="px-6 py-3 rounded-xl font-bold uppercase tracking-widest text-xs flex items-center gap-2 transition <?= $is_maintenance ? 'bg-red-600 hover:bg-red-500 text-white' : 'bg-[#111] hover:bg-gray-800 text-gray-300 border border-[#222]' ?>">
                        <i data-lucide="power" class="w-4 h-4"></i> <?= $is_maintenance ? 'MAINTENANCE MODE IS ON' : 'TURN ON MAINTENANCE' ?>
                    </button>
                </form>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <div class="glass-panel p-6 flex items-center gap-5">
                    <div class="w-12 h-12 bg-[#111] rounded-xl flex items-center justify-center"><i data-lucide="key" class="w-6 h-6 text-blue-400"></i></div>
                    <div><p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-1">TOTAL KEYS</p><h2 class="text-3xl font-extrabold"><?= $pdo->query("SELECT COUNT(*) FROM zentrax_keys")->fetchColumn() ?></h2></div>
                </div>
                <div class="glass-panel p-6 flex items-center gap-5">
                    <div class="w-12 h-12 bg-[#111] rounded-xl flex items-center justify-center"><i data-lucide="check-circle" class="w-6 h-6 text-green-400"></i></div>
                    <div><p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-1">USED KEYS</p><h2 class="text-3xl font-extrabold"><?= $pdo->query("SELECT COUNT(DISTINCT license_key) FROM zentrax_binds")->fetchColumn() ?></h2></div>
                </div>
                <div class="glass-panel p-6 flex items-center gap-5">
                    <div class="w-12 h-12 bg-[#111] rounded-xl flex items-center justify-center"><i data-lucide="shield-ban" class="w-6 h-6 text-red-400"></i></div>
                    <div><p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-1">BANNED IPs</p><h2 class="text-3xl font-extrabold"><?= $pdo->query("SELECT COUNT(*) FROM banned_ips")->fetchColumn() ?></h2></div>
                </div>
                <div class="glass-panel p-6 flex items-center gap-5">
                    <div class="w-12 h-12 bg-[#111] rounded-xl flex items-center justify-center"><i data-lucide="users" class="w-6 h-6 text-purple-400"></i></div>
                    <div><p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-1">RESELLERS</p><h2 class="text-3xl font-extrabold"><?= $pdo->query("SELECT COUNT(*) FROM users WHERE role='reseller'")->fetchColumn() ?></h2></div>
                </div>
            </div>
            
            <form method="POST" class="md:hidden mt-8">
                <button type="submit" name="toggle_maintenance" class="w-full py-4 rounded-xl font-bold uppercase tracking-widest text-xs flex justify-center items-center gap-2 transition shadow-lg <?= $is_maintenance ? 'bg-red-600 hover:bg-red-500 text-white shadow-red-900/20' : 'bg-[#111] hover:bg-gray-800 text-gray-300 border border-[#222]' ?>">
                    <i data-lucide="power" class="w-4 h-4"></i> <?= $is_maintenance ? 'MAINTENANCE MODE IS ON' : 'TURN ON MAINTENANCE' ?>
                </button>
            </form>

        <?php elseif($page == 'blacklist'): ?>
            <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                <div><h1 class="text-2xl font-extrabold font-['Outfit'] uppercase">IP BLACKLIST</h1><p class="text-gray-500 text-sm">Ban IPs completely from accessing the system.</p></div>
            </div>
            
            <div class="grid md:grid-cols-3 gap-6">
                <div class="glass-panel p-6 md:col-span-1 h-fit">
                    <h2 class="text-xs font-bold text-red-500 uppercase tracking-widest mb-4 flex items-center gap-2"><i data-lucide="ban" class="w-4 h-4"></i> BAN NEW IP</h2>
                    <form method="POST" class="space-y-4">
                        <input type="text" name="banned_ip" placeholder="Enter IP Address (e.g. 192.168.1.1)" required class="input-dark w-full font-mono text-sm">
                        <button type="submit" name="add_blacklist" class="bg-red-600 hover:bg-red-500 text-white w-full py-4 rounded-xl font-bold uppercase tracking-widest text-xs transition">KICK & BAN IP</button>
                    </form>
                </div>
                <div class="glass-panel p-0 md:col-span-2 overflow-hidden">
                    <div class="p-4 bg-[#050505] border-b border-[#1a1a1a] text-xs font-bold text-gray-400 uppercase tracking-widest">BLOCKED DEVICES</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left whitespace-nowrap">
                            <thead class="text-gray-500 text-[10px] uppercase bg-[#050505] border-b border-[#1a1a1a]"><tr><th class="p-4">IP Address</th><th class="p-4">Banned At</th><th class="p-4 text-right">Action</th></tr></thead>
                            <tbody class="divide-y divide-[#1a1a1a]">
                                <?php 
                                $banned = $pdo->query("SELECT * FROM banned_ips ORDER BY banned_at DESC")->fetchAll();
                                if(!$banned) echo "<tr><td colspan='3' class='p-6 text-center text-gray-600'>No IPs blocked.</td></tr>";
                                foreach($banned as $b): ?>
                                    <tr class="hover:bg-[#111]">
                                        <td class="p-4 font-mono text-red-400 font-bold"><?= $b['ip_address'] ?></td>
                                        <td class="p-4 text-gray-500 text-xs"><?= $b['banned_at'] ?></td>
                                        <td class="p-4 text-right">
                                            <a href="?page=blacklist&action=unban_ip&ip=<?= $b['ip_address'] ?>" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-xs font-bold transition">REMOVE BAN</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif($page == 'generator'): ?>
            <div class="mb-6"><h1 class="text-2xl font-extrabold font-['Outfit'] uppercase">KEY GENERATOR</h1><p class="text-gray-500 text-sm">Create and customize access keys.</p></div>

            <?php if($last_gen): ?>
                <div class="glass-panel p-6 mb-6 border-l-4 border-l-emerald-500">
                    <h3 class="flex items-center gap-2 text-emerald-500 font-bold text-sm mb-4"><i data-lucide="check-circle" class="w-5 h-5"></i> Keys Generated Successfully!</h3>
                    <div class="flex flex-col md:flex-row gap-4 mb-3">
                        <textarea id="genKeysText" readonly class="w-full bg-[#050505] border border-[#1a1a1a] p-4 rounded-xl text-emerald-400 font-mono text-sm outline-none resize-none" rows="<?= $last_gen['qty'] > 5 ? 5 : $last_gen['qty'] ?>"><?= htmlspecialchars($last_gen['keys']) ?></textarea>
                        <button onclick="copyKeys()" id="copyBtn" class="bg-emerald-600 hover:bg-emerald-500 text-black px-6 py-4 md:py-0 rounded-xl font-bold uppercase tracking-widest text-xs transition">COPY</button>
                    </div>
                    <div class="text-[10px] text-gray-500 font-bold uppercase flex gap-4"><span class="text-blue-400 border border-blue-400/20 bg-blue-400/10 px-2 py-1 rounded"><?= $last_gen['type'] ?> KEY</span> <span>Dur: <?= $last_gen['dur'] ?></span> <span>Dev: <?= $last_gen['max_ips'] ?></span></div>
                </div>
            <?php endif; ?>

            <form method="POST" class="glass-panel p-8 max-w-2xl space-y-6">
                <input type="hidden" name="generate" value="1">
                
                <div class="flex gap-4 p-1 bg-[#050505] border border-[#1a1a1a] rounded-lg w-fit mb-4">
                    <label class="cursor-pointer">
                        <input type="radio" name="key_type" value="paid" class="peer hidden" checked>
                        <div class="px-6 py-2 rounded-md text-xs font-bold uppercase tracking-widest text-gray-500 peer-checked:bg-blue-600 peer-checked:text-white transition">PAID KEYS</div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="key_type" value="free" class="peer hidden">
                        <div class="px-6 py-2 rounded-md text-xs font-bold uppercase tracking-widest text-gray-500 peer-checked:bg-gray-700 peer-checked:text-white transition">FREE KEYS</div>
                    </label>
                </div>

                <div>
                    <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block">GAME / FEATURE</label>
                    <div class="custom-select-container" id="cs-feature">
                        <input type="hidden" name="feature" id="inp-feature" value="DRAGHEADSHOT">
                        <div class="custom-select-trigger" onclick="toggleSelect('cs-feature')">
                            <span id="txt-feature">DRAG HEADSHOT</span><i data-lucide="chevron-down" class="w-4 h-4 text-gray-500"></i>
                        </div>
                        <div class="custom-select-dropdown">
                            <div class="custom-option selected" onclick="selOpt('cs-feature', 'DRAGHEADSHOT', 'DRAG HEADSHOT')">DRAG HEADSHOT</div>
                            <div class="custom-option" onclick="selOpt('cs-feature', 'BOADYANTENNA', 'BOADY + ANTENNA')">BOADY + ANTENNA</div>
                            <div class="custom-option" onclick="selOpt('cs-feature', 'NECKANTENNA', 'NECK + ANTENNA')">NECK + ANTENNA</div>
                        </div>
                    </div>
                </div>
                
                <div class="flex flex-col md:flex-row gap-4">
                    <div class="w-full md:w-1/2">
                        <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block">DURATION</label>
                        <input type="number" name="duration_val" value="1" min="1" class="input-dark w-full font-bold">
                    </div>
                    <div class="w-full md:w-1/2">
                        <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block">UNIT</label>
                        <div class="custom-select-container" id="cs-unit">
                            <input type="hidden" name="duration_unit" id="inp-unit" value="Hours">
                            <div class="custom-select-trigger" onclick="toggleSelect('cs-unit')">
                                <span id="txt-unit">Hours</span><i data-lucide="chevron-down" class="w-4 h-4 text-gray-500"></i>
                            </div>
                            <div class="custom-select-dropdown">
                                <div class="custom-option selected" onclick="selOpt('cs-unit', 'Hours', 'Hours')">Hours</div>
                                <div class="custom-option" onclick="selOpt('cs-unit', 'Days', 'Days')">Days</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block">DEVICES ALLOWED</label>
                    <div class="custom-select-container" id="cs-dev">
                        <input type="hidden" name="max_ips" id="inp-dev" value="1">
                        <div class="custom-select-trigger" onclick="toggleSelect('cs-dev')">
                            <span id="txt-dev">1 Device</span><i data-lucide="chevron-down" class="w-4 h-4 text-gray-500"></i>
                        </div>
                        <div class="custom-select-dropdown">
                            <div class="custom-option selected" onclick="selOpt('cs-dev', '1', '1 Device')">1 Device</div>
                            <div class="custom-option" onclick="selOpt('cs-dev', '2', '2 Devices')">2 Devices</div>
                            <div class="custom-option" onclick="selOpt('cs-dev', '5', '5 Devices')">5 Devices</div>
                            <div class="custom-option" onclick="selOpt('cs-dev', 'custom', 'Custom Limit...')">Custom Limit...</div>
                        </div>
                    </div>
                    <input type="number" name="custom_ip" id="c_ip" placeholder="Enter custom limit" class="input-dark w-full mt-3 font-bold" style="display:none;">
                </div>
                
                <div class="border-t border-[#1a1a1a] pt-6">
                    <label class="flex items-center gap-3 cursor-pointer mb-4">
                        <input type="checkbox" name="is_custom" id="customKeyToggle" onchange="toggleCustomKey()" class="w-4 h-4 accent-blue-600 bg-[#050505] border-[#333] rounded">
                        <span class="text-xs font-bold uppercase tracking-widest text-gray-300">Create Specific Custom Key Name</span>
                    </label>
                    
                    <div id="bulkGenDiv">
                        <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block">HOW MANY KEYS?</label>
                        <input type="number" name="quantity" value="1" min="1" max="500" class="input-dark w-full font-bold">
                    </div>
                    
                    <div id="customKeyDiv" style="display:none;">
                        <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block text-blue-400">ENTER CUSTOM KEY NAME</label>
                        <input type="text" name="custom_key" placeholder="e.g. ZENTRAX61" class="input-dark w-full font-bold uppercase border-blue-900/50 focus:border-blue-500">
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-white text-black py-4 rounded-xl font-extrabold hover:bg-gray-200 transition uppercase tracking-widest flex items-center justify-center gap-2 mt-4"><i data-lucide="zap" class="w-4 h-4"></i> LAUNCH GENERATOR</button>
            </form>
            
            <script>
                function copyKeys() {
                    const t = document.getElementById("genKeysText"); t.select(); document.execCommand("copy");
                    const btn = document.getElementById("copyBtn"); btn.innerText = 'COPIED!';
                    setTimeout(() => { btn.innerText = 'COPY'; }, 2000);
                }
                function toggleCustomKey() {
                    const isChecked = document.getElementById('customKeyToggle').checked;
                    document.getElementById('bulkGenDiv').style.display = isChecked ? 'none' : 'block';
                    document.getElementById('customKeyDiv').style.display = isChecked ? 'block' : 'none';
                }
            </script>

        <?php elseif($page == 'keys'): ?>
            <div class="mb-6 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div><h1 class="text-2xl font-extrabold font-['Outfit'] uppercase">KEY REGISTRY</h1></div>
                <div class="flex flex-wrap gap-2">
                    <a href="?action=del_expired" onclick="return confirm('Wipe Expired?')" class="bg-[#111] hover:bg-gray-800 border border-[#222] text-gray-300 px-3 py-2 rounded-lg text-[10px] font-bold uppercase transition">Wipe Expired</a>
                    <a href="?action=del_unused" onclick="return confirm('Wipe Unused?')" class="bg-[#111] hover:bg-gray-800 border border-[#222] text-gray-300 px-3 py-2 rounded-lg text-[10px] font-bold uppercase transition">Wipe Unused</a>
                    <a href="?action=del_all_free" onclick="return confirm('Delete ALL Free Keys?')" class="bg-gray-800 hover:bg-gray-700 text-white px-3 py-2 rounded-lg text-[10px] font-bold uppercase transition">Del All Free</a>
                    <a href="?action=del_all_paid" onclick="return confirm('Delete ALL Paid Keys?')" class="bg-blue-900/30 hover:bg-blue-900/50 border border-blue-900/50 text-blue-400 px-3 py-2 rounded-lg text-[10px] font-bold uppercase transition">Del All Paid</a>
                    <button onclick="if(prompt('Type DELETE to wipe ALL DB')==='DELETE') window.location.href='?action=del_all';" class="bg-red-900/30 hover:bg-red-900/50 text-red-500 px-3 py-2 rounded-lg text-[10px] font-bold uppercase border border-red-900/50 transition">NUKE DB</button>
                </div>
            </div>
            
            <div class="glass-panel overflow-hidden flex flex-col h-[70vh]">
                <div class="p-4 border-b border-[#1a1a1a] bg-[#050505] flex flex-col md:flex-row gap-4 items-center justify-between shrink-0">
                    <div class="flex gap-2 bg-[#111] p-1 rounded-lg border border-[#222] w-full md:w-auto overflow-x-auto">
                        <button onclick="filterType('all')" id="tab_all" class="px-4 py-1.5 rounded text-xs font-bold uppercase bg-[#333] text-white transition filter-tab whitespace-nowrap">ALL</button>
                        <button onclick="filterType('paid')" id="tab_paid" class="px-4 py-1.5 rounded text-xs font-bold uppercase text-gray-500 hover:text-white transition filter-tab whitespace-nowrap">PAID</button>
                        <button onclick="filterType('free')" id="tab_free" class="px-4 py-1.5 rounded text-xs font-bold uppercase text-gray-500 hover:text-white transition filter-tab whitespace-nowrap">FREE</button>
                    </div>
                    <div class="relative w-full md:w-64">
                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500"></i>
                        <input type="text" id="rtSearch" onkeyup="realtimeSearch()" placeholder="Search Key/IP..." class="bg-[#111] border border-[#222] pl-9 pr-4 py-2 w-full rounded-lg text-xs outline-none focus:border-gray-500 text-white font-mono placeholder-gray-600 transition">
                    </div>
                </div>

                <div class="overflow-x-auto overflow-y-auto flex-1">
                    <table class="w-full text-sm text-left whitespace-nowrap">
                        <thead class="text-gray-500 text-[10px] uppercase tracking-widest bg-[#050505] border-b border-[#1a1a1a] sticky top-0 z-10"><tr><th class="p-4 font-bold">Key / Status</th><th class="p-4 font-bold">Feature</th><th class="p-4 font-bold">Expiry Date</th><th class="p-4 font-bold text-right">Actions</th></tr></thead>
                        <tbody class="divide-y divide-[#1a1a1a]">
                            <?php 
                            $sql = "SELECT k.*, 
                                   (SELECT COUNT(*) FROM zentrax_binds b WHERE b.license_key = k.license_key) as used,
                                   (SELECT expired_date FROM zentrax_binds b WHERE b.license_key = k.license_key ORDER BY id ASC LIMIT 1) as exp_date
                                   FROM zentrax_keys k ORDER BY k.id DESC";
                            $keys = $pdo->query($sql)->fetchAll();
                            
                            foreach($keys as $k): 
                                $stat_label = ""; $stat_color = "";
                                if ($k['status'] == 0) { $stat_label = "BANNED"; $stat_color = "red-500"; }
                                elseif (!$k['exp_date']) { $stat_label = "NOT REGISTERED"; $stat_color = "blue-400"; }
                                elseif (strtotime($k['exp_date']) < time()) { $stat_label = "EXPIRED"; $stat_color = "red-500"; }
                                else { $stat_label = "ACTIVE"; $stat_color = "green-400"; }
                                
                                $ktype = strtolower($k['key_type'] ?? 'paid');
                                $bg_badge = $ktype == 'paid' ? 'bg-blue-900/30 border-blue-500/30 text-blue-400' : 'bg-gray-800 border-gray-600 text-gray-300';
                            ?>
                            <tr class="hover:bg-[#111] transition-colors reg-row" data-type="<?= $ktype ?>">
                                <td class="p-4">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-mono text-white font-bold <?= $stat_label=='BANNED'?'line-through opacity-50':'' ?>"><?= $k['license_key'] ?></span>
                                        <span class="text-[8px] font-bold px-2 py-0.5 rounded border <?= $bg_badge ?> uppercase"><?= $ktype ?></span>
                                    </div>
                                    <div class="text-[10px] font-bold tracking-widest text-<?= $stat_color ?> uppercase flex items-center gap-1">● <?= $stat_label ?></div>
                                </td>
                                <td class="p-4">
                                    <div class="text-xs text-gray-300 font-bold uppercase"><?= formatFeature($k['feature_name']) ?></div>
                                    <div class="text-[10px] text-gray-600 mt-0.5"><?= $k['duration_val'] ?> <?= $k['duration_unit'] ?> | Dev: <?= $k['used'] ?>/<?= $k['max_ips'] ?></div>
                                </td>
                                <td class="p-4 font-mono text-xs <?= $stat_label=='EXPIRED'?'text-red-400':'text-gray-400' ?>">
                                    <?= $k['exp_date'] ? date('Y-m-d H:i', strtotime($k['exp_date'])) : '<span class="text-gray-600 italic">Waiting...</span>' ?>
                                </td>
                                <td class="p-4 flex gap-2 justify-end">
                                    <button onclick="editKey(<?= $k['id'] ?>, <?= $k['duration_val'] ?>, '<?= $k['duration_unit'] ?>', <?= $k['max_ips'] ?>, '<?= $k['exp_date']?$k['exp_date']:'' ?>')" class="bg-[#111] border border-[#222] p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition"><i data-lucide="settings" class="w-4 h-4"></i></button>
                                    <a href="?page=keys&action=reset_key&id=<?= $k['id'] ?>" class="bg-[#111] border border-[#222] p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-800 transition"><i data-lucide="rotate-ccw" class="w-4 h-4"></i></a>
                                    <a href="?page=keys&action=<?= $k['status']==1?'ban_key':'unban_key' ?>&id=<?= $k['id'] ?>" class="bg-[#111] border border-[#222] p-2 rounded-lg <?= $k['status']==1?'text-orange-400 hover:bg-orange-900/30':'text-green-400 hover:bg-green-900/30' ?> transition"><i data-lucide="<?= $k['status']==1?'ban':'check' ?>" class="w-4 h-4"></i></a>
                                    <a href="?page=keys&action=delete_key&id=<?= $k['id'] ?>" onclick="return confirm('Delete completely?')" class="bg-red-900/20 border border-red-900/50 p-2 rounded-lg text-red-500 hover:bg-red-900/40 transition"><i data-lucide="trash-2" class="w-4 h-4"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="editModal" class="hidden fixed inset-0 bg-black/90 backdrop-blur-sm flex justify-center items-center z-50 p-4">
                <form method="POST" class="glass-panel p-8 w-full max-w-sm space-y-5 animate-[pageFadeIn_0.2s_ease-out]">
                    <h2 class="font-extrabold text-xl flex items-center gap-2"><i data-lucide="settings-2"></i> Modify Key</h2>
                    <input type="hidden" name="edit_key_submit" value="1"><input type="hidden" name="k_id" id="e_kid">
                    
                    <div class="flex gap-4">
                        <div class="w-1/2"><label class="text-[10px] font-bold text-gray-500 block mb-1">DUR</label><input type="number" name="e_dur" id="e_dur" class="input-dark !p-3 w-full font-bold"></div>
                        <div class="w-1/2">
                            <label class="text-[10px] font-bold text-gray-500 block mb-1">UNIT</label>
                            <div class="custom-select-container" id="cs-e-unit">
                                <input type="hidden" name="e_unit" id="inp-e-unit" value="Hours">
                                <div class="custom-select-trigger !p-3" onclick="toggleSelect('cs-e-unit')">
                                    <span id="txt-e-unit">Hours</span><i data-lucide="chevron-down" class="w-4 h-4 text-gray-500"></i>
                                </div>
                                <div class="custom-select-dropdown">
                                    <div class="custom-option" onclick="selOpt('cs-e-unit', 'Hours', 'Hours')">Hours</div>
                                    <div class="custom-option" onclick="selOpt('cs-e-unit', 'Days', 'Days')">Days</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div><label class="text-[10px] font-bold text-gray-500 block mb-1">MAX DEVICES</label><input type="number" name="e_max" id="e_max" class="input-dark !p-3 w-full font-bold"></div>
                    
                    <div id="expiryEditDiv">
                        <label class="text-[10px] font-bold text-blue-400 block mb-1">ADJUST EXPIRY DATE (UTC)</label>
                        <input type="datetime-local" name="e_expiry" id="e_expiry" class="input-dark !p-3 w-full font-bold border-blue-900/50">
                        <p class="text-[9px] text-gray-600 mt-1">Leave blank to keep current expiry.</p>
                    </div>

                    <div class="flex gap-2 pt-2">
                        <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="bg-[#111] text-gray-400 w-1/2 py-3 rounded-lg font-bold text-xs uppercase hover:bg-gray-800 transition">Cancel</button>
                        <button type="submit" class="bg-white hover:bg-gray-200 text-black w-1/2 py-3 rounded-lg font-bold text-xs uppercase transition">Save</button>
                    </div>
                </form>
            </div>

            <script>
                function realtimeSearch() {
                    let input = document.getElementById("rtSearch").value.toUpperCase();
                    let rows = document.querySelectorAll(".reg-row");
                    rows.forEach(row => { row.style.display = row.innerText.toUpperCase().includes(input) ? "" : "none"; });
                }
                function filterType(type) {
                    document.querySelectorAll('.filter-tab').forEach(btn => { btn.classList.remove('bg-[#333]', 'text-white'); btn.classList.add('text-gray-500'); });
                    document.getElementById('tab_' + type).classList.add('bg-[#333]', 'text-white'); document.getElementById('tab_' + type).classList.remove('text-gray-500');
                    let rows = document.querySelectorAll(".reg-row");
                    rows.forEach(row => {
                        if (type === 'all') row.style.display = "";
                        else row.style.display = (row.getAttribute('data-type') === type) ? "" : "none";
                    });
                }
                function editKey(id, dur, unit, max, exp) {
                    document.getElementById('e_kid').value = id; document.getElementById('e_dur').value = dur;
                    selOpt('cs-e-unit', unit, unit); // Use custom select
                    document.getElementById('e_max').value = max;
                    
                    if(exp !== '') { 
                        document.getElementById('expiryEditDiv').style.display = 'block'; 
                        document.getElementById('e_expiry').value = exp.replace(' ', 'T').substring(0, 16);
                    } else { 
                        document.getElementById('expiryEditDiv').style.display = 'none'; 
                        document.getElementById('e_expiry').value = ''; 
                    }
                    document.getElementById('editModal').classList.remove('hidden');
                }
            </script>
            
        <?php elseif($page == 'reseller'): ?>
            <div class="mb-6"><h1 class="text-2xl font-extrabold font-['Outfit'] uppercase">RESELLER MANAGEMENT</h1><p class="text-gray-500 text-sm">Manage partners, pricing, and deposits.</p></div>
            <div class="grid lg:grid-cols-3 gap-6">
                
                <div class="glass-panel p-6">
                    <h2 class="text-xs font-bold mb-5 text-gray-400 uppercase tracking-widest flex items-center gap-2"><i data-lucide="user-plus" class="w-4 h-4"></i> Create Account</h2>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="create_reseller" value="1">
                        <input type="text" name="r_user" placeholder="Username" required class="input-dark w-full">
                        <input type="text" name="r_pass" placeholder="Password" required class="input-dark w-full">
                        <input type="number" name="r_bal" placeholder="Initial Balance (₹)" required class="input-dark w-full">
                        <button class="bg-white w-full py-4 text-black font-extrabold rounded-xl text-xs uppercase tracking-widest mt-2 hover:bg-gray-200 transition">CREATE</button>
                    </form>
                </div>
                
                <div class="glass-panel p-6 lg:col-span-2">
                    <h2 class="text-xs font-bold mb-5 text-gray-400 uppercase tracking-widest flex items-center gap-2"><i data-lucide="indian-rupee" class="w-4 h-4"></i> Global Pricing</h2>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="update_pricing" value="1">
                        <div class="grid grid-cols-2 gap-4">
                            <?php foreach($pdo->query("SELECT * FROM pricing ORDER BY duration_days")->fetchAll() as $p): ?>
                                <div class="flex justify-between items-center bg-[#050505] p-3 border border-[#1a1a1a] rounded-xl">
                                    <label class="text-gray-400 text-xs font-bold"><?= $p['duration_days'] ?> Day(s):</label>
                                    <input type="number" name="p_<?= $p['duration_days'] ?>" value="<?= $p['price'] ?>" class="w-20 bg-transparent text-right font-bold outline-none text-white focus:text-blue-400 transition">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="bg-[#111] hover:bg-gray-800 border border-[#222] text-white w-full py-4 font-extrabold rounded-xl mt-4 text-xs uppercase tracking-widest transition">UPDATE PRICING</button>
                    </form>
                </div>

                <div class="glass-panel p-6 md:col-span-3 border-l-2 border-yellow-500">
                    <h2 class="text-xs font-bold mb-4 text-yellow-500 uppercase tracking-widest">Pending Deposits</h2>
                    <div class="overflow-x-auto h-48 overflow-y-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="text-gray-500 text-[10px] uppercase bg-[#050505] sticky top-0 border-b border-[#1a1a1a]"><tr><th class="p-3 font-bold">User</th><th class="p-3 font-bold">Amount</th><th class="p-3 font-bold">Contact</th><th class="p-3 font-bold">Action</th></tr></thead>
                            <tbody class="divide-y divide-[#1a1a1a]">
                                <?php $deps = $pdo->query("SELECT * FROM deposits WHERE status='pending'")->fetchAll();
                                if(count($deps)==0) echo "<tr><td colspan='4' class='text-center py-6 text-gray-600 italic text-xs'>No pending deposits.</td></tr>";
                                foreach($deps as $d): ?>
                                <tr class="hover:bg-[#111] transition-colors">
                                    <td class="p-3 font-mono text-gray-300">#<?= $d['user_id'] ?></td>
                                    <td class="p-3 text-green-400 font-bold">₹<?= $d['amount'] ?></td>
                                    <td class="p-3 text-xs text-gray-400">TG: <?= $d['telegram_username'] ?: '-' ?><br>WA: <?= $d['whatsapp_number'] ?: '-' ?></td>
                                    <td class="p-3 flex gap-2">
                                        <a href="<?= htmlspecialchars($d['screenshot_path']) ?>" target="_blank" class="bg-[#111] px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-[#222] border border-[#222]">View SS</a>
                                        <form method="POST" class="flex gap-1"><input type="hidden" name="handle_deposit" value="1"><input type="hidden" name="d_id" value="<?= $d['id'] ?>"><input type="hidden" name="u_id" value="<?= $d['user_id'] ?>"><input type="hidden" name="amt" value="<?= $d['amount'] ?>"><button name="action" value="approve" class="bg-green-600/20 text-green-500 hover:bg-green-600 hover:text-white px-3 py-1.5 rounded-lg text-xs font-bold transition">Approve</button><button name="action" value="decline" class="bg-red-600/20 text-red-500 hover:bg-red-600 hover:text-white px-3 py-1.5 rounded-lg text-xs font-bold transition">Decline</button></form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="glass-panel p-6 md:col-span-3">
                    <h2 class="text-xs font-bold mb-4 text-gray-400 uppercase tracking-widest">Partner Database</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="text-gray-500 text-[10px] uppercase bg-[#050505] border-b border-[#1a1a1a]"><tr><th class="p-4 font-bold">User</th><th class="p-4 font-bold">Balance</th><th class="p-4 font-bold">Wallet Actions</th><th class="p-4 font-bold">Edit Config</th></tr></thead>
                            <tbody class="divide-y divide-[#1a1a1a]">
                                <?php 
                                $resellers = $pdo->query("SELECT * FROM users WHERE role='reseller'")->fetchAll();
                                if(!$resellers) echo "<tr><td colspan='4' class='text-center py-6 text-gray-600 text-xs'>No resellers found.</td></tr>";
                                foreach($resellers as $u): ?>
                                <tr class="hover:bg-[#111] transition-colors">
                                    <td class="p-4 font-bold text-white"><?= $u['username'] ?></td>
                                    <td class="p-4 text-green-400 font-bold">₹<?= $u['balance'] ?></td>
                                    <td class="p-4">
                                        <form method="POST" class="flex gap-1 items-center">
                                            <input type="hidden" name="manage_reseller" value="1"><input type="hidden" name="r_id" value="<?= $u['id'] ?>">
                                            <input type="number" name="amt" placeholder="Amt" class="w-16 bg-[#050505] border border-[#1a1a1a] text-xs px-2 py-2 rounded-lg outline-none focus:border-gray-500 text-white">
                                            <button name="action" value="add_bal" class="bg-[#111] hover:bg-[#222] border border-[#222] px-3 py-2 rounded-lg text-xs font-bold text-white transition">+</button>
                                            <button name="action" value="deduct_bal" class="bg-[#111] hover:bg-[#222] border border-[#222] px-3 py-2 rounded-lg text-xs font-bold text-white transition">-</button>
                                            <button name="action" value="del" onclick="return confirm('Delete this reseller?')" class="bg-red-900/20 hover:bg-red-900/50 text-red-500 px-3 py-2 rounded-lg text-xs font-bold ml-2 transition"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                        </form>
                                    </td>
                                    <td class="p-4">
                                        <form method="POST" class="flex gap-2 items-center">
                                            <input type="hidden" name="manage_reseller" value="1"><input type="hidden" name="r_id" value="<?= $u['id'] ?>"><input type="hidden" name="action" value="edit">
                                            <input type="text" name="new_u" placeholder="New User" class="w-24 bg-[#050505] border border-[#1a1a1a] text-xs px-2 py-2 rounded-lg outline-none focus:border-gray-500 text-white">
                                            <input type="text" name="new_p" placeholder="New Pass" class="w-24 bg-[#050505] border border-[#1a1a1a] text-xs px-2 py-2 rounded-lg outline-none focus:border-gray-500 text-white">
                                            <button class="bg-white hover:bg-gray-200 text-black px-4 py-2 rounded-lg text-xs font-bold transition">Save</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        lucide.createIcons();
        
        // Custom App-Like Select Logic
        function toggleSelect(id) {
            document.querySelectorAll('.custom-select-dropdown').forEach(el => {
                if(el.parentElement.id !== id) el.classList.remove('open');
            });
            document.querySelector('#' + id + ' .custom-select-dropdown').classList.toggle('open');
        }
        
        function selOpt(containerId, val, text) {
            document.getElementById('inp-' + containerId.replace('cs-','')).value = val;
            document.getElementById('txt-' + containerId.replace('cs-','')).innerText = text;
            
            // Handle active state
            document.querySelectorAll('#' + containerId + ' .custom-option').forEach(el => el.classList.remove('selected'));
            event.target.classList.add('selected');
            
            document.querySelector('#' + containerId + ' .custom-select-dropdown').classList.remove('open');
            
            // Trigger specific logic
            if(containerId === 'cs-dev') {
                document.getElementById('c_ip').style.display = (val === 'custom') ? 'block' : 'none';
            }
        }
        
        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.custom-select-container')) {
                document.querySelectorAll('.custom-select-dropdown').forEach(el => el.classList.remove('open'));
            }
        });

    </script>
</body></html>
