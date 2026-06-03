<?php
// --- 1. FIX SESSION BUG ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'config.php';

// ==========================================
// 🚨 MAINTENANCE MODE CHECK 🚨
// ==========================================
if (file_exists('maintenance.flag')) {
    die('<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>ZENTRAX - Maintenance</title>
        <script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@700;800&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    </head>
    <body class="bg-[#050505] text-white flex items-center justify-center min-h-screen p-4 font-[\'Inter\']">
        <div class="bg-[#0a0a0a] border border-[#1a1a1a] rounded-3xl p-10 max-w-md w-full text-center shadow-2xl relative overflow-hidden">
            <div class="absolute top-0 left-1/2 -translate-x-1/2 w-32 h-32 bg-yellow-500/10 rounded-full blur-3xl"></div>
            <div class="w-20 h-20 bg-yellow-500/10 border border-yellow-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6 relative z-10"><i data-lucide="settings" class="w-10 h-10 text-yellow-500 animate-[spin_4s_linear_infinite]"></i></div>
            <h1 class="text-3xl font-extrabold font-[\'Outfit\'] tracking-widest uppercase mb-2 relative z-10">SYSTEM UPGRADE</h1>
            <p class="text-gray-400 text-sm mb-6 relative z-10">Zentrax VIP is currently undergoing a scheduled security and protocol upgrade. Please check back shortly.</p>
            <div class="bg-[#111] border border-[#222] p-4 rounded-xl text-xs font-mono text-gray-500 relative z-10">ESTIMATED DOWNTIME: <span class="text-yellow-500 font-bold">FEW MINUTES</span></div>
        </div>
        <script>lucide.createIcons();</script>
    </body></html>');
}

// --- AUTO-PATCH DATABASE FOR DEVICE TOKEN ---
try { $pdo->exec("ALTER TABLE zentrax_binds ADD COLUMN device_token VARCHAR(100) DEFAULT NULL"); } catch(Exception $e) {}

// --- RELIABLE IP FETCHING ---
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ipList[0]);
    }
    return $_SERVER['REMOTE_ADDR'];
}
$real_ip = getUserIP();

// --- PERMANENT IP BAN CHECK ---
try {
    $ban_stmt = $pdo->prepare("SELECT * FROM banned_ips WHERE ip_address = ?");
    $ban_stmt->execute([$real_ip]);
    if ($ban_stmt->fetch()) {
        die("<div style='background:#050505; color:red; text-align:center; padding:50px; font-family:monospace; font-size:20px; border:2px solid red; margin:20px; border-radius: 12px;'>
            <h1>🚨 ACCESS DENIED 🚨</h1><p>Your IP ($real_ip) is PERMANENTLY BANNED.</p></div>");
    }
} catch (Exception $e) {}

// ==========================================
// 🔄 AUTO IP SHIFTER (DEVICE COOKIE MAGIC) 🔄
// ==========================================
$device_token = $_COOKIE['predator_device'] ?? null;

if ($device_token) {
    // Find active bind for this specific device
    $stmt = $pdo->prepare("SELECT * FROM zentrax_binds WHERE device_token = ? AND expired_date > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$device_token]);
    $bind_data = $stmt->fetch();
    
    // If device exists but IP has changed (e.g. WiFi to Mobile Data)
    if ($bind_data && $bind_data['ip_address'] !== $real_ip) {
        $old_ip = $bind_data['ip_address'];
        
        // Transfer the bind to the new IP
        $pdo->prepare("UPDATE zentrax_binds SET ip_address = ? WHERE id = ?")->execute([$real_ip, $bind_data['id']]);
        
        // Transfer the active session folder to keep game running
        $folder = $pdo->query("SELECT active_folder FROM zentrax_sessions WHERE ip_address='$old_ip'")->fetchColumn();
        $pdo->prepare("DELETE FROM zentrax_sessions WHERE ip_address=?")->execute([$old_ip]);
        if($folder) {
            $pdo->prepare("INSERT INTO zentrax_sessions (ip_address, active_folder) VALUES (?, ?) ON DUPLICATE KEY UPDATE active_folder=?")->execute([$real_ip, $folder, $folder]);
        }
        
        $_SESSION['action_status'] = "info"; 
        $_SESSION['action_msg'] = "NETWORK CHANGE DETECTED.<br><b>Your IP was automatically updated.</b>";
    }
} else {
    // Generate token if not exists
    $device_token = bin2hex(random_bytes(16));
    setcookie('predator_device', $device_token, time() + (86400 * 30), "/"); // Expires in 30 days
}

// ==========================================
// FORM SUBMISSION (POST) -> Process & Redirect
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_type = $_POST['action_type']; 
    
    // 1. AUTHORIZE ACTION
    if ($action_type === 'authorize') {
        $input_key = trim($_POST['license_key']);
        $user_ip = isset($_POST['user_ip']) && !empty(trim($_POST['user_ip'])) ? trim($_POST['user_ip']) : $real_ip;

        $_SESSION['last_key'] = $input_key;
        $_SESSION['last_ip'] = $user_ip;

        $stmt = $pdo->prepare("SELECT * FROM zentrax_keys WHERE license_key = ?");
        $stmt->execute([$input_key]); $kdata = $stmt->fetch();
        
        if (!$kdata) { 
            $_SESSION['action_status'] = "error"; 
            $_SESSION['action_msg'] = "INVALID LICENSE KEY!"; 
        } elseif ($kdata['status'] == 0) { 
            $_SESSION['action_status'] = "error"; 
            $_SESSION['action_msg'] = "THIS KEY IS PERMANENTLY BANNED!"; 
        } else {
            // GLOBAL TIMER LOGIC
            $first_bind = $pdo->prepare("SELECT expired_date FROM zentrax_binds WHERE license_key = ? ORDER BY id ASC LIMIT 1");
            $first_bind->execute([$input_key]);
            $global_exp = $first_bind->fetchColumn();

            // Check if globally expired
            if ($global_exp && strtotime($global_exp) < time()) {
                $_SESSION['action_status'] = "error"; 
                $_SESSION['action_msg'] = "THIS KEY HAS GLOBALLY EXPIRED FOR ALL USERS!"; 
                header("Location: index.php"); exit;
            }

            if (!$global_exp) {
                $global_exp = date('Y-m-d H:i:s', strtotime("+{$kdata['duration_val']} {$kdata['duration_unit']}"));
            }

            // DOUBLE CHECK (Using Device Token as Primary Identity)
            $chk = $pdo->prepare("SELECT * FROM zentrax_binds WHERE license_key = ? AND (device_token = ? OR ip_address = ?) AND expired_date > NOW()");
            $chk->execute([$input_key, $device_token, $user_ip]);
            $chk_data = $chk->fetch();
            
            if ($chk_data) {
                // Ensure device token is bound
                $pdo->prepare("UPDATE zentrax_binds SET device_token = ?, ip_address = ? WHERE id = ?")->execute([$device_token, $user_ip, $chk_data['id']]);
                $_SESSION['action_status'] = "success"; 
                $_SESSION['action_msg'] = "YOUR DEVICE IS ALREADY REGISTERED.<br><b>SYSTEM IS READY TO USE.</b>";
                $pdo->query("INSERT IGNORE INTO zentrax_sessions (ip_address, active_folder) VALUES ('$user_ip', '{$kdata['feature_name']}')");
            } else {
                $c = $pdo->prepare("SELECT COUNT(DISTINCT device_token) FROM zentrax_binds WHERE license_key = ? AND device_token IS NOT NULL");
                $c->execute([$input_key]);
                
                if ($c->fetchColumn() >= $kdata['max_ips']) {
                    $_SESSION['action_status'] = "warning"; 
                    $_SESSION['action_msg'] = "DEVICE LIMIT REACHED FOR THIS KEY!";
                } else {
                    // NEW BINDING WITH DEVICE TOKEN
                    $pdo->prepare("INSERT INTO zentrax_binds (license_key, ip_address, expired_date, device_token) VALUES (?, ?, ?, ?)")->execute([$input_key, $user_ip, $global_exp, $device_token]);
                    $pdo->prepare("INSERT INTO zentrax_sessions (ip_address, active_folder) VALUES (?, ?) ON DUPLICATE KEY UPDATE active_folder = ?")->execute([$user_ip, $kdata['feature_name'], $kdata['feature_name']]);
                    
                    $feat = str_replace("MOD: ", "", $kdata['feature_name']);
                    
                    $_SESSION['action_status'] = "success"; 
                    $_SESSION['action_msg'] = "YOUR DEVICE IS SUCCESSFULLY REGISTERED!<br><br><span style='color:#38bdf8;'>VALID UNTIL: <span class='local-time' data-utc='{$global_exp}'></span></span><br>Feature: $feat";
                }
            }
        }
    } 
    // 2. CHECK KEY ACTION
    elseif ($action_type === 'check') {
        $input_key = trim($_POST['license_key']);
        $_SESSION['last_key'] = $input_key;

        $stmt = $pdo->prepare("SELECT k.*, (SELECT COUNT(DISTINCT device_token) FROM zentrax_binds b WHERE b.license_key = k.license_key AND b.device_token IS NOT NULL) as used_ips FROM zentrax_keys k WHERE k.license_key = ?");
        $stmt->execute([$input_key]); $kdata = $stmt->fetch();

        if (!$kdata) {
            $_SESSION['action_status'] = "error";
            $_SESSION['action_msg'] = "INVALID LICENSE KEY OR KEY DOES NOT EXIST.";
        } else {
            $_SESSION['key_details'] = $kdata;
            $_SESSION['action_status'] = "info";
            $_SESSION['action_msg'] = "KEY DETAILS FETCHED SUCCESSFULLY!";
        }
    }
    // 3. CHECK MY IP HISTORY
    elseif ($action_type === 'myinfo') {
        $user_ip = isset($_POST['user_ip']) && !empty(trim($_POST['user_ip'])) ? trim($_POST['user_ip']) : $real_ip;
        $_SESSION['last_ip'] = $user_ip;

        $stmt = $pdo->prepare("SELECT b.*, k.feature_name, k.duration_val, k.duration_unit, k.status as key_status FROM zentrax_binds b JOIN zentrax_keys k ON b.license_key = k.license_key WHERE b.device_token = ? OR b.ip_address = ? ORDER BY b.id DESC LIMIT 1");
        $stmt->execute([$device_token, $user_ip]);
        $ip_data = $stmt->fetch();

        if (!$ip_data) {
            $_SESSION['action_status'] = "error";
            $_SESSION['action_msg'] = "THIS DEVICE IS NOT REGISTERED IN OUR DATABASE.";
        } else {
            $is_expired = (strtotime($ip_data['expired_date']) < time());
            $stat = $is_expired ? "<span class='text-red-500'>EXPIRED</span>" : "<span class='text-green-500'>ACTIVE</span>";
            if ($ip_data['key_status'] == 0) $stat = "<span class='text-red-500'>KEY BANNED</span>";
            
            $feat = str_replace("MOD: ", "", $ip_data['feature_name']);
            
            $_SESSION['action_status'] = $is_expired ? "warning" : "success";
            $_SESSION['action_msg'] = "<div class='text-center border-b border-[#1a1a1a] pb-3 mb-4 text-white tracking-widest'><b>DEVICE HISTORY FOUND</b></div>
                                      <div class='text-left text-xs space-y-3 font-mono'>
                                      <div class='flex justify-between'><span class='text-gray-500'>Linked Key:</span> <span class='text-blue-400 font-bold'>".substr($ip_data['license_key'], 0, 15)."...</span></div>
                                      <div class='flex justify-between'><span class='text-gray-500'>Feature:</span> <span class='text-white font-bold'>$feat</span></div>
                                      <div class='flex justify-between'><span class='text-gray-500'>Duration:</span> <span class='text-white font-bold'>{$ip_data['duration_val']} {$ip_data['duration_unit']}</span></div>
                                      <div class='flex justify-between'><span class='text-gray-500'>Expired On:</span> <span class='text-white font-bold local-time' data-utc='{$ip_data['expired_date']}'></span></div>
                                      <div class='flex justify-between mt-3 pt-3 border-t border-[#1a1a1a]'><span class='text-gray-500'>Status:</span> <b>$stat</b></div>
                                      </div>";
        }
        $_SESSION['force_tab'] = 'auth'; // Stay on form tab for history check
    }
    // 4. SET ACTIVE GAME
    elseif ($action_type === 'set_game') {
        $folder = $_POST['selected_folder'];
        $pdo->prepare("INSERT INTO zentrax_sessions (ip_address, active_folder) VALUES (?, ?) ON DUPLICATE KEY UPDATE active_folder = ?")->execute([$real_ip, $folder, $folder]);
        $_SESSION['action_status'] = "success"; 
        $_SESSION['action_msg'] = "FEATURE APPLIED FOR BYPASS: " . str_replace("MOD: ", "", $folder);
        $_SESSION['force_tab'] = 'dash';
    }
    // 5. REVOKE KEY
    elseif ($action_type === 'revoke') {
        $bind_id = (int)$_POST['bind_id'];
        $pdo->query("DELETE FROM zentrax_binds WHERE id = $bind_id AND device_token = '$device_token'");
        $_SESSION['action_status'] = "warning"; 
        $_SESSION['action_msg'] = "ACCESS REVOKED FOR THIS DEVICE.";
        $_SESSION['force_tab'] = 'dash';
    }

    header("Location: index.php");
    exit;
}

// ==========================================
// DATA FETCHING & SESSION READ
// ==========================================
$binds = $pdo->query("SELECT b.*, k.feature_name FROM zentrax_binds b JOIN zentrax_keys k ON b.license_key = k.license_key WHERE b.device_token = '$device_token' AND b.expired_date > NOW() ORDER BY b.id DESC")->fetchAll();
$curr_session = $pdo->query("SELECT active_folder FROM zentrax_sessions WHERE ip_address = '$real_ip'")->fetchColumn();

if (count($binds) > 0 && empty($curr_session)) {
    $most_recent_feature = $binds[0]['feature_name'];
    $pdo->prepare("INSERT INTO zentrax_sessions (ip_address, active_folder) VALUES (?, ?)")->execute([$real_ip, $most_recent_feature]);
    $curr_session = $most_recent_feature;
}

$action_status = $_SESSION['action_status'] ?? "";
$action_msg = $_SESSION['action_msg'] ?? "";
$key_details = $_SESSION['key_details'] ?? null;
$force_tab = $_SESSION['force_tab'] ?? "auth";

$input_key_val = $_SESSION['last_key'] ?? "";
$user_ip_val = $_SESSION['last_ip'] ?? $real_ip;

unset($_SESSION['action_status'], $_SESSION['action_msg'], $_SESSION['key_details'], $_SESSION['force_tab']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZENTRAX VIP - Client Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;700;800&display=swap" rel="stylesheet">
    <style>
        body { background: #050505; color: #fff; font-family: 'Inter', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px 10px; overflow-x: hidden;}
        .container { width: 100%; max-width: 420px; z-index: 10; padding-bottom: 80px;} /* Extra padding for FAB */
        .glass-card { background: #0a0a0a; border: 1px solid #1a1a1a; border-radius: 16px; padding: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); margin-bottom: 20px; }
        .input-box { width: 100%; padding: 16px; background: #050505; border: 1px solid #1a1a1a; border-radius: 12px; color: #fff; font-size: 14px; outline: none; transition: 0.3s; text-align: center; }
        .input-box:focus { border-color: #555; }
        .btn-main { width: 100%; padding: 16px; border-radius: 12px; background: #ffffff; color: #000000; font-weight: 800; cursor: pointer; transition: 0.3s; display: flex; justify-content: center; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 1px; }
        .btn-main:hover { background: #e5e5e5; }
        .action-tabs { display: flex; gap: 6px; margin-bottom: 20px; background: #050505; padding: 6px; border-radius: 12px; border: 1px solid #1a1a1a; }
        .action-btn { flex: 1; padding: 12px 2px; background: transparent; border: none; color: #666; font-size: 11px; font-weight: 700; border-radius: 8px; cursor: pointer; transition: 0.3s; text-transform: uppercase; }
        .action-btn.active { background: #1a1a1a; color: #fff; }
        .alert { margin-top: 20px; padding: 16px; border-radius: 12px; font-size: 13px; font-weight: 600; text-align: center; border: 1px solid transparent; line-height: 1.6; }
        .alert.success { background: rgba(16,185,129,0.1); border-color: rgba(16,185,129,0.3); color: #10b981; }
        .alert.error { background: rgba(239,68,68,0.1); border-color: rgba(239,68,68,0.3); color: #ef4444; }
        .alert.warning { background: rgba(245,158,11,0.1); border-color: rgba(245,158,11,0.3); color: #f59e0b; }
        .alert.info { background: rgba(56,189,248,0.1); border-color: rgba(56,189,248,0.3); color: #38bdf8; }
        
        #welcomeModal { transition: opacity 0.3s ease-out; }
        .modal-content { transform: scale(0.9); transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        #welcomeModal.show { opacity: 1; pointer-events: auto; }
        #welcomeModal.show .modal-content { transform: scale(1); }
        
        .view-section { display: none; animation: fadeIn 0.3s ease-in-out; }
        .view-section.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
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
<body>

    <button onclick="toggleSupport()" class="fixed bottom-6 right-6 bg-[#111] border border-[#333] text-white px-4 py-3 rounded-2xl shadow-[0_0_20px_rgba(0,0,0,0.8)] flex items-center gap-2 hover:bg-[#222] transition-colors z-40">
        <i data-lucide="life-buoy" class="w-4 h-4 text-blue-400"></i> <span class="text-[10px] font-bold tracking-widest uppercase">SUPPORT</span>
    </button>

    <div id="supportModal" class="hidden fixed bottom-20 right-6 w-[280px] bg-[#0a0a0a] border border-[#1a1a1a] rounded-2xl shadow-[0_10px_40px_rgba(0,0,0,0.9)] z-50 p-5 animate-[fadeIn_0.2s_ease-out]">
        <div class="flex justify-between items-center mb-4 border-b border-[#1a1a1a] pb-3">
            <h3 class="text-xs font-bold uppercase tracking-widest text-blue-400 flex items-center gap-2"><i data-lucide="message-square" class="w-4 h-4"></i> Get Help</h3>
            <button onclick="toggleSupport()" class="text-gray-500 hover:text-white transition"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>
        <textarea id="supportText" placeholder="Enter your problem or query here..." class="w-full bg-[#050505] border border-[#222] rounded-xl p-3 text-xs text-white outline-none focus:border-blue-500 resize-none h-24 mb-3 font-mono"></textarea>
        <button onclick="submitSupport()" class="w-full bg-white hover:bg-gray-200 text-black font-extrabold text-[10px] uppercase tracking-widest py-3 rounded-xl transition-colors flex items-center justify-center gap-2">
            <i data-lucide="send" class="w-3 h-3"></i> Send to Admin
        </button>
    </div>

    <div id="welcomeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 backdrop-blur-sm opacity-0 pointer-events-none">
        <div class="modal-content bg-[#0a0a0a] border border-[#1a1a1a] rounded-2xl p-8 max-w-[340px] w-[90%] shadow-2xl text-center relative">
            <div class="w-16 h-16 mx-auto bg-[#111] rounded-2xl flex items-center justify-center mb-5 border border-[#333]">
                <i data-lucide="shield-check" class="text-white w-8 h-8"></i>
            </div>
            <h2 class="text-xl font-extrabold text-white mb-2 uppercase font-['Outfit'] tracking-widest">ELBASANLLIU VIP<br>Official Site</h2>
            <p class="text-gray-500 text-xs mb-8 leading-relaxed">Join our official Telegram network for the latest updates, secure proxy services, and premium support.</p>
            <div class="flex flex-col gap-3 mb-5">
                <a href="https://t.me/ELBASANLLIU1010" target="_blank" class="w-full bg-[#111] hover:bg-[#222] border border-[#333] text-white py-3.5 rounded-xl font-bold flex items-center justify-center gap-2 transition-colors text-sm uppercase tracking-widest">
                    <i data-lucide="message-circle" class="w-4 h-4"></i> Contact Admin
                </a>
                <a href="https://t.me/ELBASANLLIU1010" target="_blank" class="w-full bg-[#111] hover:bg-[#222] border border-[#333] text-white py-3.5 rounded-xl font-bold flex items-center justify-center gap-2 transition-colors text-sm uppercase tracking-widest">
                    <i data-lucide="bell" class="w-4 h-4"></i> Join Channel
                </a>
            </div>
            <button onclick="closeWelcomeModal()" class="text-gray-600 hover:text-white text-xs underline uppercase tracking-widest font-bold transition-colors">Continue to Portal</button>
        </div>
    </div>

    <div class="container">
        <div class="flex flex-col items-center justify-center mb-8">
            <div class="w-14 h-14 bg-[#111] border border-[#222] rounded-full flex items-center justify-center shadow-lg mb-4">
                <i data-lucide="rocket" class="text-white w-7 h-7"></i>
            </div>
            <h1 class="font-['Outfit'] text-2xl font-extrabold uppercase tracking-widest">ELBASANLLIU PROXY</h1>
            <p class="text-[10px] text-gray-500 uppercase tracking-widest font-bold mt-1">Client Verification Portal</p>
        </div>

        <div class="flex gap-2 mb-4 bg-[#0a0a0a] p-2 rounded-xl border border-[#1a1a1a]">
            <button onclick="switchView('authView')" id="tabAuth" class="flex-1 py-3 text-xs font-bold uppercase tracking-widest rounded-lg transition-all <?= $force_tab=='auth'?'bg-[#1a1a1a] text-white':'text-gray-500 hover:text-white' ?>">Register Key</button>
            <button onclick="switchView('dashView')" id="tabDash" class="flex-1 py-3 text-xs font-bold uppercase tracking-widest rounded-lg transition-all <?= $force_tab=='dash'?'bg-[#1a1a1a] text-white':'text-gray-500 hover:text-white' ?>">
                My Info <?php if(count($binds)>0) echo "<span class='bg-green-500 text-black px-1.5 py-0.5 rounded-full text-[8px] ml-1'>".count($binds)."</span>"; ?>
            </button>
        </div>

        <?php if($action_msg): ?>
            <div class="alert <?= $action_status ?>"><?= $action_msg ?></div>
        <?php endif; ?>

        <div id="authView" class="view-section <?= $force_tab=='auth'?'active':'' ?>">
            <div class="glass-card">
                <form method="POST" action="index.php" id="authForm">
                    <input type="hidden" name="action_type" id="actionType" value="authorize">

                    <div id="keyGroupContainer" class="mb-5">
                        <label class="flex items-center gap-2 text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2"><i data-lucide="lock" class="w-3 h-3"></i> LICENSE KEY</label>
                        <div class="relative">
                            <input type="text" name="license_key" id="licenseKeyInput" class="input-box uppercase font-bold pr-20 text-left pl-5" placeholder="ENTER YOUR KEY" value="<?= htmlspecialchars($input_key_val) ?>" required autocomplete="off">
                            <button type="button" onclick="pasteKey()" class="absolute right-2 top-1/2 -translate-y-1/2 bg-[#111] hover:bg-[#222] border border-[#333] px-3 py-2 rounded-lg flex items-center gap-1.5 transition-colors group z-10">
                                <i data-lucide="clipboard" class="w-3 h-3 text-gray-400 group-hover:text-white"></i>
                                <span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider group-hover:text-white">Paste</span>
                            </button>
                        </div>
                    </div>

                    <div id="ipSectionContainer" class="mb-6 relative">
                        <label class="flex items-center gap-2 text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2"><i data-lucide="globe" class="w-3 h-3"></i> IP ADDRESS</label>
                        <input type="text" name="user_ip" id="ipInput" class="input-box font-bold text-gray-400" value="<?= htmlspecialchars($user_ip_val) ?>" readonly>
                        <div class="flex gap-2 mt-3">
                            <button type="button" id="btnAuto" onclick="setIpMode('auto')" class="flex-1 bg-[#1a1a1a] text-white py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest border border-[#333] transition">Auto Fetch</button>
                            <button type="button" id="btnManual" onclick="setIpMode('manual')" class="flex-1 bg-transparent text-gray-500 hover:bg-[#111] hover:text-white py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest border border-[#1a1a1a] transition">Manual Edit</button>
                        </div>
                    </div>

                    <div class="action-tabs">
                        <button type="button" class="action-btn active" id="act-auth" onclick="setAction('authorize')">Authorize</button>
                        <button type="button" class="action-btn" id="act-check" onclick="setAction('check')">Check Status</button>
                        <button type="button" class="action-btn" id="act-myinfo" onclick="setAction('myinfo')">IP History</button>
                    </div>

                    <button type="submit" class="btn-main" id="submitBtn">
                        <i data-lucide="shield-check" class="w-4 h-4"></i> <span>AUTHORIZE DEVICE</span>
                    </button>
                </form>

                <?php if($key_details): 
                    $status_lbl = ($key_details['status'] == 1) ? "active" : "banned";
                ?>
                    <div class="mt-6 border border-[#1a1a1a] bg-[#050505] p-5 rounded-xl text-xs space-y-3 font-mono">
                        <div class="flex justify-between border-b border-[#1a1a1a] pb-2"><span class="text-gray-500">Status:</span> <span class="font-bold uppercase" style="color: <?= $status_lbl=='active'?'#10b981':'#ef4444' ?>;"><?= $status_lbl ?></span></div>
                        <div class="flex justify-between border-b border-[#1a1a1a] pb-2"><span class="text-gray-500">Feature:</span> <span class="text-white font-bold"><?= str_replace("MOD: ", "", $key_details['feature_name']) ?></span></div>
                        <div class="flex justify-between border-b border-[#1a1a1a] pb-2"><span class="text-gray-500">Duration:</span> <span class="text-white font-bold"><?= $key_details['duration_val'] ?> <?= $key_details['duration_unit'] ?></span></div>
                        <div class="flex justify-between pt-1"><span class="text-gray-500">Devices Linked:</span> <b><span class="text-yellow-500"><?= $key_details['used_ips'] ?></span> / <?= $key_details['max_ips'] ?></b></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="dashView" class="view-section <?= $force_tab=='dash'?'active':'' ?>">
            <?php if(count($binds) == 0): ?>
                <div class="glass-card text-center py-10">
                    <i data-lucide="ghost" class="w-12 h-12 text-gray-600 mx-auto mb-4"></i>
                    <h2 class="text-lg font-bold text-gray-300 uppercase tracking-widest mb-2">No Active Keys</h2>
                    <p class="text-xs text-gray-500 mb-6">Your device does not have any active subscriptions. Please go back to register a new key.</p>
                    <button onclick="switchView('authView')" class="bg-white text-black px-6 py-3 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-gray-200 transition">Go to Registration</button>
                </div>
            <?php else: ?>
                <div class="glass-card border-l-4 border-l-blue-500">
                    <div class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-4 flex items-center gap-2"><i data-lucide="target" class="w-3 h-3 text-blue-500"></i> SET ACTIVE FEATURE</div>
                    <form method="POST" class="flex flex-col gap-3">
                        <input type="hidden" name="action_type" value="set_game">
                        
                        <div class="custom-select-container" id="cs-game">
                            <input type="hidden" name="selected_folder" id="inp-game" value="<?= htmlspecialchars($curr_session ?: $binds[0]['feature_name']) ?>">
                            <div class="custom-select-trigger" onclick="toggleSelect('cs-game')">
                                <span id="txt-game" class="truncate pr-4">🎮 <?= str_replace("MOD: ", "", ($curr_session ?: $binds[0]['feature_name'])) ?></span>
                                <i data-lucide="chevron-down" class="w-4 h-4 text-gray-500 shrink-0"></i>
                            </div>
                            <div class="custom-select-dropdown">
                                <?php foreach($binds as $b): 
                                    $clean_feat = str_replace("MOD: ", "", $b['feature_name']);
                                    $isSelected = ($curr_session == $b['feature_name']) ? 'selected' : '';
                                ?>
                                    <div class="custom-option <?= $isSelected ?>" onclick="selOpt('cs-game', '<?= $b['feature_name'] ?>', '🎮 <?= $clean_feat ?>')"><?= $clean_feat ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white w-full py-4 rounded-xl font-bold text-xs uppercase tracking-widest transition shadow-[0_0_15px_rgba(37,99,235,0.4)] mt-2">APPLY BYPASS</button>
                    </form>
                    <div class="mt-4 bg-[#050505] p-4 rounded-xl flex items-center justify-between border border-[#1a1a1a]">
                        <span class="text-[10px] text-gray-500 font-bold uppercase tracking-widest">Active Bypass:</span>
                        <span class="text-xs font-extrabold text-green-400"><?= $curr_session ? str_replace("MOD: ", "", $curr_session) : 'NONE' ?></span>
                    </div>
                </div>

                <div class="glass-card">
                    <div class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-4 flex items-center gap-2"><i data-lucide="layers" class="w-3 h-3"></i> YOUR SUBSCRIPTIONS</div>
                    <div class="space-y-4">
                        <?php foreach($binds as $b): ?>
                            <div class="bg-[#050505] border border-[#1a1a1a] p-5 rounded-xl flex flex-col gap-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <div class="text-xs font-bold text-white mb-1 tracking-wide"><?= str_replace("MOD: ", "", $b['feature_name']) ?></div>
                                        <div class="text-[10px] text-gray-500 font-mono">Key: <?= substr($b['license_key'], 0, 12) ?>...</div>
                                    </div>
                                    <div class="text-[9px] bg-green-900/20 text-green-500 px-2 py-1 rounded-full border border-green-900/50 font-bold uppercase tracking-widest">ACTIVE</div>
                                </div>
                                <div class="flex justify-between items-center pt-4 border-t border-[#1a1a1a]">
                                    <div class="text-[10px] text-gray-500 font-mono">Exp: <span class="local-time" data-utc="<?= $b['expired_date'] ?>"></span></div>
                                    <form method="POST" onsubmit="return confirm('Revoke this key from your device? You will need to enter it again to use it.');">
                                        <input type="hidden" name="action_type" value="revoke">
                                        <input type="hidden" name="bind_id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="bg-[#111] hover:bg-red-600 text-red-500 hover:text-white border border-[#333] hover:border-red-600 px-4 py-2 rounded-lg text-[10px] font-bold transition uppercase tracking-widest">Revoke</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-8 text-center space-y-4">
            <p class="text-gray-600 text-[10px] font-mono uppercase tracking-widest">Current IP: <?= $real_ip ?></p>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // 📋 Clipboard Paste Function
        async function pasteKey() {
            try {
                const text = await navigator.clipboard.readText();
                if(text) {
                    document.getElementById('licenseKeyInput').value = text.trim().toUpperCase();
                }
            } catch (err) {
                alert('Browser blocked clipboard access. Please paste manually.');
            }
        }

        // 💬 Support FAB Functions
        function toggleSupport() {
            const modal = document.getElementById('supportModal');
            modal.classList.toggle('hidden');
        }
        function submitSupport() {
            let txt = document.getElementById('supportText').value;
            if(!txt.trim()) return;
            window.open('https://t.me/predatorxit?text=' + encodeURIComponent(txt), '_blank');
            toggleSupport();
            document.getElementById('supportText').value = '';
        }

        // Modal Logic
        document.addEventListener("DOMContentLoaded", () => {
            if (!sessionStorage.getItem('welcomeShown')) {
                setTimeout(() => { document.getElementById('welcomeModal').classList.add('show'); }, 300);
            }
            
            // Auto convert UTC to Client's Local Time
            document.querySelectorAll('.local-time').forEach(el => {
                let timeStr = el.getAttribute('data-utc');
                if(timeStr) {
                    let dateObj = new Date(timeStr.replace(' ', 'T') + 'Z');
                    let options = { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true };
                    el.innerText = dateObj.toLocaleString('en-US', options);
                }
            });
        });

        function closeWelcomeModal() {
            document.getElementById('welcomeModal').classList.remove('show');
            sessionStorage.setItem('welcomeShown', 'true');
        }

        function switchView(viewId) {
            document.querySelectorAll('.view-section').forEach(el => el.classList.remove('active'));
            document.getElementById(viewId).classList.add('active');
            
            document.getElementById('tabAuth').className = "flex-1 py-3 text-xs font-bold uppercase tracking-widest rounded-lg transition-all text-gray-500 hover:text-white";
            document.getElementById('tabDash').className = "flex-1 py-3 text-xs font-bold uppercase tracking-widest rounded-lg transition-all text-gray-500 hover:text-white";
            
            if(viewId === 'authView') {
                document.getElementById('tabAuth').classList.add('bg-[#1a1a1a]', 'text-white');
                document.getElementById('tabAuth').classList.remove('text-gray-500');
            } else {
                document.getElementById('tabDash').classList.add('bg-[#1a1a1a]', 'text-white');
                document.getElementById('tabDash').classList.remove('text-gray-500');
            }
        }

        const realIp = "<?= $real_ip ?>";
        const ipInput = document.getElementById('ipInput');

        function setIpMode(mode) {
            if(!ipInput) return;
            if(mode === 'auto') {
                document.getElementById('btnAuto').className = "flex-1 bg-[#1a1a1a] text-white py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest border border-[#333] transition";
                document.getElementById('btnManual').className = "flex-1 bg-transparent text-gray-500 hover:bg-[#111] hover:text-white py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest border border-[#1a1a1a] transition";
                ipInput.readOnly = true; ipInput.value = realIp; ipInput.classList.add('text-gray-400'); ipInput.classList.remove('text-white');
            } else {
                document.getElementById('btnManual').className = "flex-1 bg-[#1a1a1a] text-white py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest border border-[#333] transition";
                document.getElementById('btnAuto').className = "flex-1 bg-transparent text-gray-500 hover:bg-[#111] hover:text-white py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest border border-[#1a1a1a] transition";
                ipInput.readOnly = false; ipInput.focus(); ipInput.classList.add('text-white'); ipInput.classList.remove('text-gray-400');
            }
        }

        function setAction(action) {
            document.getElementById('actionType').value = action;
            document.getElementById('act-auth').classList.remove('active');
            document.getElementById('act-check').classList.remove('active');
            document.getElementById('act-myinfo').classList.remove('active');
            
            let btn = document.getElementById('submitBtn');
            let keyGroup = document.getElementById('keyGroupContainer');
            
            if(action === 'authorize') {
                document.getElementById('act-auth').classList.add('active');
                btn.innerHTML = '<i data-lucide="shield-check" class="w-4 h-4"></i> <span>AUTHORIZE DEVICE</span>';
                keyGroup.style.display = 'block';
                document.getElementById('licenseKeyInput').required = true;
            } else if(action === 'check') {
                document.getElementById('act-check').classList.add('active');
                btn.innerHTML = '<i data-lucide="search" class="w-4 h-4"></i> <span>CHECK KEY STATUS</span>';
                keyGroup.style.display = 'block';
                document.getElementById('licenseKeyInput').required = true;
            } else if(action === 'myinfo') {
                document.getElementById('act-myinfo').classList.add('active');
                btn.innerHTML = '<i data-lucide="history" class="w-4 h-4"></i> <span>CHECK IP HISTORY</span>';
                keyGroup.style.display = 'none';
                document.getElementById('licenseKeyInput').required = false;
            }
            lucide.createIcons();
        }

        let lastAction = "<?= $_SESSION['last_action'] ?? 'authorize' ?>";
        if(document.getElementById('actionType')) setAction(lastAction);
        
        let lastIp = "<?= $user_ip_val ?>";
        if(lastIp !== realIp && document.getElementById('ipInput')) {
            setIpMode('manual'); ipInput.value = lastIp;
        }

        // Custom Dropdown Logic
        function toggleSelect(id) {
            document.querySelectorAll('.custom-select-dropdown').forEach(el => {
                if(el.parentElement.id !== id) el.classList.remove('open');
            });
            document.querySelector('#' + id + ' .custom-select-dropdown').classList.toggle('open');
        }
        
        function selOpt(containerId, val, text) {
            document.getElementById('inp-' + containerId.replace('cs-','')).value = val;
            document.getElementById('txt-' + containerId.replace('cs-','')).innerText = text;
            
            document.querySelectorAll('#' + containerId + ' .custom-option').forEach(el => el.classList.remove('selected'));
            event.target.classList.add('selected');
            document.querySelector('#' + containerId + ' .custom-select-dropdown').classList.remove('open');
        }
        
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.custom-select-container')) {
                document.querySelectorAll('.custom-select-dropdown').forEach(el => el.classList.remove('open'));
            }
        });
    </script>
</body>
</html>
