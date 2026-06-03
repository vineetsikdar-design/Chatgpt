<?php
// --- 1. FIX SESSION BUG ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'config.php';

// --- AUTO-PATCH DATABASE FOR DAILY RESETS ---
try { 
    $pdo->exec("ALTER TABLE zentrax_keys ADD COLUMN daily_resets INT DEFAULT 0, ADD COLUMN last_reset_date DATE DEFAULT NULL"); 
} catch(Exception $e) {}

// ==========================================
// 1. LOGOUT LOGIC
// ==========================================
if (isset($_GET['logout'])) { session_destroy(); header("Location: resellerpanel.php"); exit; }

// ==========================================
// 2. RESELLER LOGIN LOGIC
// ==========================================
$login_err = '';
if (!isset($_SESSION['reseller_logged'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $user = trim($_POST['username']);
        $pass = $_POST['password'];

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'reseller'");
        $stmt->execute([$user]); $r_data = $stmt->fetch();

        if ($r_data && password_verify($pass, $r_data['password'])) {
            $_SESSION['reseller_logged'] = true; 
            $_SESSION['reseller_user'] = $r_data['username'];
            $_SESSION['reseller_id'] = $r_data['id'];
            header("Location: resellerpanel.php"); exit;
        } else { $login_err = "Invalid Credentials or Not a Reseller."; }
    }
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>ZENTRAX - Partner Login</title><script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script><link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"><style>body{background:#050505;color:#fff;font-family:'Inter', sans-serif;} .glass-card { background: rgba(13, 17, 29, 0.8); border: 1px solid rgba(59, 130, 246, 0.2); backdrop-filter: blur(10px); box-shadow: 0 10px 40px rgba(0,0,0,0.5); }</style></head>
    <body class="flex items-center justify-center min-h-screen flex-col px-4 relative overflow-hidden">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-blue-600/20 rounded-full blur-[120px] pointer-events-none"></div>
        <div class="glass-card p-8 rounded-3xl w-full max-w-sm text-center relative overflow-hidden z-10">
            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-600 to-indigo-600"></div>
            <div class="w-16 h-16 bg-blue-900/30 rounded-2xl flex items-center justify-center mx-auto mb-5 border border-blue-500/30 shadow-[0_0_20px_rgba(59,130,246,0.3)]"><i data-lucide="briefcase" class="w-8 h-8 text-blue-400"></i></div>
            <h1 class="text-2xl font-extrabold mb-1 font-['Outfit'] tracking-wide uppercase">Partner Portal</h1>
            <p class="text-xs text-gray-400 uppercase tracking-widest mb-6">ZENTRAX VIP NETWORK</p>
            <?php if($login_err) echo "<div class='bg-red-900/20 text-red-400 p-3 rounded-xl text-xs mb-4 border border-red-900/50 font-bold'>$login_err</div>"; ?>
            <form method="POST" class="space-y-4">
                <input type="text" name="username" placeholder="Reseller ID" required class="w-full bg-[#0a0d16] border border-gray-800 p-4 text-white rounded-xl outline-none text-center font-bold focus:border-blue-500 transition-colors shadow-inner">
                <input type="password" name="password" placeholder="Password" required class="w-full bg-[#0a0d16] border border-gray-800 p-4 text-white rounded-xl outline-none text-center font-bold focus:border-blue-500 transition-colors shadow-inner">
                <button type="submit" name="login" class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white p-4 rounded-xl font-extrabold text-sm uppercase tracking-widest transition-all transform hover:-translate-y-0.5 shadow-[0_5px_15px_rgba(59,130,246,0.4)]">LOGIN SECURELY</button>
            </form>
        </div><script>lucide.createIcons();</script>
    </body></html>
    <?php exit;
}

$r_user = $_SESSION['reseller_user'];
$r_id = $_SESSION['reseller_id'];

// Fetch Fresh Balance & Threshold Alert
$stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
$stmt->execute([$r_id]);
$current_balance = (int)$stmt->fetchColumn();
$is_low_balance = $current_balance < 500;

// Fetch Pricing
$pricing = [];
$p_stmt = $pdo->query("SELECT * FROM pricing");
while($row = $p_stmt->fetch()) { $pricing[$row['duration_days']] = $row['price']; }

// ==========================================
// 3. POST ACTIONS 
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $page_redirect = $_GET['page'] ?? 'dashboard';

    // A. GENERATE KEYS
    if (isset($_POST['generate'])) {
        $qty = (int)$_POST['quantity']; 
        $dur_days = (int)$_POST['duration_val'];
        $feature = $_POST['feature'];
        
        $cost_per_key = isset($pricing[$dur_days]) ? $pricing[$dur_days] : 0;
        $total_cost = $cost_per_key * $qty;

        if ($cost_per_key == 0) {
            $_SESSION['err'] = "Invalid duration selected. Contact Admin.";
        } elseif ($current_balance < $total_cost) {
            $_SESSION['err'] = "Insufficient Balance! You need ₹$total_cost but have ₹$current_balance.";
        } else {
            // Deduct Balance
            $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$total_cost, $r_id]);
            
            // Generate Keys
            $generated = [];
            $stmt = $pdo->prepare("INSERT INTO zentrax_keys (license_key, feature_name, duration_val, duration_unit, max_ips, key_type, created_by) VALUES (?, ?, ?, 'Days', 1, 'paid', ?)");
            for($i=0; $i<$qty; $i++) {
                $key = "PRDTR-" . strtoupper(bin2hex(random_bytes(5))); // More secure key gen
                $stmt->execute([$key, $feature, $dur_days, $r_user]);
                $generated[] = $key;
            }
            
            $_SESSION['last_gen'] = ['qty' => $qty, 'cost' => $total_cost, 'dur' => $dur_days, 'keys' => implode("\n", $generated)];
            $_SESSION['download_keys'] = implode("\n", $generated); 
        }
        $page_redirect = 'generator';
    }
    
    // B. ADD FUNDS (UPLOAD DEPOSIT)
    if (isset($_POST['submit_deposit'])) {
        $amt = (int)$_POST['deposit_amount'];
        $tg = trim($_POST['tg_user']);
        $wa = trim($_POST['wa_num']);
        
        $upload_dir = __DIR__ . "/uploads";
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
        
        if(isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] == 0) {
            $ext = pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION);
            $filename = "dep_" . time() . "_" . mt_rand(1000,9999) . "." . $ext;
            $target = "uploads/" . $filename;
            
            if(move_uploaded_file($_FILES['screenshot']['tmp_name'], $target)) {
                $pdo->prepare("INSERT INTO deposits (user_id, amount, screenshot_path, telegram_username, whatsapp_number) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$r_id, $amt, $target, $tg, $wa]);
                $_SESSION['msg'] = "Deposit request of ₹$amt submitted! Admin will approve it shortly.";
            } else { $_SESSION['err'] = "Failed to upload screenshot. Check server permissions."; }
        } else { $_SESSION['err'] = "Please attach a valid screenshot."; }
        $page_redirect = 'wallet';
    }

    header("Location: resellerpanel.php?page=" . $page_redirect); exit;
}

// ==========================================
// 4. GET ACTIONS (WITH 3/DAY RESET LOGIC)
// ==========================================
if (isset($_GET['action'])) {
    $act = $_GET['action']; $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    $check = $pdo->prepare("SELECT * FROM zentrax_keys WHERE id = ? AND created_by = ?");
    $check->execute([$id, $r_user]);
    $k_data = $check->fetch();
    
    if ($k_data) {
        if ($act == 'ban_key') { $pdo->query("UPDATE zentrax_keys SET status = 0 WHERE id = $id"); $_SESSION['msg']="Key Banned Successfully!"; }
        if ($act == 'unban_key') { $pdo->query("UPDATE zentrax_keys SET status = 1 WHERE id = $id"); $_SESSION['msg']="Key Activated!"; }
        if ($act == 'delete_key') { 
            $pdo->query("DELETE FROM zentrax_keys WHERE id = $id"); 
            $pdo->prepare("DELETE FROM zentrax_binds WHERE license_key = ?")->execute([$k_data['license_key']]); 
            $_SESSION['msg']="Key & IP history completely deleted!"; 
        }
        if ($act == 'reset_key') {
            $today = date('Y-m-d');
            $resets = ($k_data['last_reset_date'] == $today) ? (int)$k_data['daily_resets'] : 0;
            
            if ($resets >= 3) {
                $_SESSION['err'] = "Limit Reached: This key has already been reset 3 times today!";
            } else {
                $resets++;
                $pdo->prepare("UPDATE zentrax_keys SET daily_resets = ?, last_reset_date = ? WHERE id = ?")->execute([$resets, $today, $id]);
                $pdo->prepare("DELETE FROM zentrax_binds WHERE license_key = ?")->execute([$k_data['license_key']]);
                $_SESSION['msg'] = "Device Reset Successful! ($resets/3 daily limit used)";
            }
        }
    } else { $_SESSION['err'] = "Unauthorized Action!"; }
    
    header("Location: resellerpanel.php?page=keys"); exit;
}

$page = $_GET['page'] ?? 'dashboard';
$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$err = $_SESSION['err'] ?? ''; unset($_SESSION['err']);
$last_gen = $_SESSION['last_gen'] ?? null; unset($_SESSION['last_gen']);
$auto_dl = $_SESSION['download_keys'] ?? ''; unset($_SESSION['download_keys']);

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
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZENTRAX PARTNER</title>
    <script src="https://cdn.tailwindcss.com"></script><script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { background:#050505; color:#f8fafc; font-family:'Inter', sans-serif; } 
        .glass-panel { background: #0a0d16; border: 1px solid #1e2438; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 12px; color: #94a3b8; font-weight: 600; font-size: 14px; transition: all 0.3s ease; }
        .nav-link:hover { color: #fff; background: rgba(59, 130, 246, 0.1); }
        .nav-link.active { background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(99, 102, 241, 0.2)); color: #fff; border: 1px solid rgba(59, 130, 246, 0.3); }
        .input-cool { background: #050505; border: 1px solid #1e2438; border-radius: 12px; color: #fff; padding: 16px; outline: none; transition: 0.3s; font-size: 14px; font-weight: bold;}
        .input-cool:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.2); }
        .btn-gradient { background: linear-gradient(135deg, #2563eb, #4f46e5); color: white; padding: 16px; border-radius: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; transition: 0.3s; box-shadow: 0 4px 15px rgba(37,99,235,0.3); }
        .btn-gradient:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37,99,235,0.4); cursor: pointer;}
        ::-webkit-scrollbar { width: 6px; height: 6px;} ::-webkit-scrollbar-track { background: #050505; } ::-webkit-scrollbar-thumb { background: #1e2438; border-radius: 3px; }
        
        /* Custom Select Styles */
        .custom-select-container { position: relative; width: 100%; user-select: none; }
        .custom-select-trigger { background: #050505; border: 1px solid #1e2438; border-radius: 12px; padding: 16px; color: #fff; font-size: 14px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: 0.3s; }
        .custom-select-trigger:hover { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.2); }
        .custom-select-dropdown { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #0a0d16; border: 1px solid #1e2438; border-radius: 12px; overflow: hidden; z-index: 50; box-shadow: 0 10px 30px rgba(0,0,0,0.8); opacity: 0; transform: translateY(-5px); pointer-events: none; transition: all 0.2s; }
        .custom-select-dropdown.open { opacity: 1; transform: translateY(0); pointer-events: auto; }
        .custom-option { padding: 14px 16px; font-size: 12px; font-weight: bold; color: #94a3b8; cursor: pointer; transition: 0.2s; border-bottom: 1px solid #1e2438; }
        .custom-option:last-child { border-bottom: none; }
        .custom-option:hover { background: rgba(59, 130, 246, 0.1); color: #fff; }
        .custom-option.selected { color: #3b82f6; }
        
        @keyframes pageFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-page { animation: pageFadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    </style>
</head>
<body class="flex flex-col lg:flex-row min-h-screen overflow-x-hidden">
    
    <div class="lg:hidden flex justify-between items-center p-5 bg-[#0a0d16] border-b border-[#1e2438] sticky top-0 z-50">
        <div class="font-['Outfit'] font-extrabold tracking-widest text-xl text-blue-500 flex items-center gap-2"><i data-lucide="briefcase"></i> ZENTRAX PARTNER</div>
        <button onclick="document.getElementById('mobileNav').classList.toggle('-translate-x-full')" class="text-white bg-[#1e2438] p-2 rounded-lg"><i data-lucide="menu"></i></button>
    </div>
    
    <div id="mobileNav" class="fixed lg:static inset-y-0 left-0 z-40 w-72 bg-[#0a0d16] border-r border-[#1e2438] p-6 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
        <div>
            <div class="hidden lg:flex items-center gap-3 mb-10 pl-2">
                <div class="w-10 h-10 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/30">
                    <i data-lucide="shield-check" class="text-white w-5 h-5"></i>
                </div>
                <div>
                    <h1 class="font-['Outfit'] text-xl font-extrabold tracking-wider">ZENTRAX</h1>
                    <p class="text-[10px] text-blue-400 uppercase tracking-widest font-bold">Partner Portal</p>
                </div>
            </div>

            <div class="bg-gradient-to-br from-[#0f172a] to-[#050505] border <?= $is_low_balance ? 'border-red-500/50 shadow-[0_0_20px_rgba(239,68,68,0.2)]' : 'border-[#1e2438]' ?> p-5 rounded-2xl mb-8 relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-24 h-24 bg-blue-500/10 rounded-full blur-2xl -mr-10 -mt-10 transition-transform group-hover:scale-150 duration-500"></div>
                <div class="flex justify-between items-center mb-2 relative z-10">
                    <p class="text-[10px] text-gray-400 uppercase tracking-widest font-bold flex items-center gap-1"><i data-lucide="wallet" class="w-3 h-3"></i> Wallet Balance</p>
                    <a href="?page=wallet" class="bg-blue-600/20 text-blue-400 p-1.5 rounded-lg hover:bg-blue-600 hover:text-white transition" title="Add Funds"><i data-lucide="plus" class="w-3 h-3"></i></a>
                </div>
                <p class="text-3xl font-bold <?= $is_low_balance ? 'text-red-400' : 'text-white' ?> relative z-10">₹<?= number_format($current_balance) ?></p>
                
                <?php if($is_low_balance): ?>
                    <a href="?page=wallet" class="mt-3 text-[10px] text-red-400 font-bold flex items-center gap-1 animate-pulse border border-red-900/50 bg-red-900/20 px-2 py-1.5 rounded-lg w-fit transition hover:bg-red-900/40">
                        <i data-lucide="alert-circle" class="w-3 h-3"></i> LOW BALANCE. TOP UP NOW.
                    </a>
                <?php endif; ?>
            </div>

            <nav class="space-y-3 relative z-10">
                <a href="?page=dashboard" class="nav-link <?= $page=='dashboard'?'active':'' ?>"><i data-lucide="layout-dashboard" class="w-5 h-5"></i> Overview</a>
                <a href="?page=generator" class="nav-link <?= $page=='generator'?'active':'' ?>"><i data-lucide="key" class="w-5 h-5"></i> Key Generator</a>
                <a href="?page=keys" class="nav-link <?= $page=='keys'?'active':'' ?>"><i data-lucide="list-video" class="w-5 h-5"></i> My Licenses</a>
                <a href="?page=wallet" class="nav-link <?= $page=='wallet'?'active':'' ?>"><i data-lucide="indian-rupee" class="w-5 h-5"></i> Add Funds</a>
            </nav>
        </div>
        <div class="pb-4 lg:pb-0">
            <a href="?logout=true" class="flex items-center gap-3 p-4 rounded-xl text-red-400 hover:bg-red-900/20 hover:text-red-300 transition-colors font-semibold text-sm border border-transparent hover:border-red-900/30"><i data-lucide="log-out" class="w-5 h-5"></i> Secure Logout</a>
        </div>
    </div>

    <div class="flex-1 p-4 lg:p-10 overflow-y-auto bg-[#050505] w-full h-screen animate-page">
        
        <div id="overlay" onclick="document.getElementById('mobileNav').classList.add('-translate-x-full')" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden"></div>

        <?php if($msg) echo "<div class='bg-emerald-900/20 border border-emerald-500/30 text-emerald-400 p-4 rounded-xl mb-6 flex items-center gap-3 text-sm font-semibold shadow-lg shadow-emerald-900/10'><i data-lucide='check-circle' class='w-5 h-5'></i> $msg</div>"; ?>
        <?php if($err) echo "<div class='bg-rose-900/20 border border-rose-500/30 text-rose-400 p-4 rounded-xl mb-6 flex items-center gap-3 text-sm font-semibold shadow-lg shadow-rose-900/10'><i data-lucide='alert-triangle' class='w-5 h-5'></i> $err</div>"; ?>
        
        <?php if($page == 'dashboard'): ?>
            <div class="mb-8">
                <h1 class="font-['Outfit'] text-3xl font-extrabold mb-1 uppercase tracking-wider">Welcome back, <?= ucfirst($r_user) ?> 👋</h1>
                <p class="text-gray-400 text-sm">Here's what's happening with your business today.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="glass-panel p-6 relative overflow-hidden group">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-purple-500/10 rounded-full blur-2xl transition-transform group-hover:scale-150 duration-500"></div>
                    <div class="w-12 h-12 bg-[#050505] border border-[#1e2438] rounded-xl flex items-center justify-center mb-4"><i data-lucide="key" class="w-6 h-6 text-purple-400"></i></div>
                    <p class="text-[11px] text-gray-500 font-bold uppercase tracking-widest mb-1">Total Keys Generated</p>
                    <h2 class="text-4xl font-extrabold text-white"><?= $pdo->query("SELECT COUNT(*) FROM zentrax_keys WHERE created_by='$r_user'")->fetchColumn() ?></h2>
                </div>
                <div class="glass-panel p-6 relative overflow-hidden group">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-emerald-500/10 rounded-full blur-2xl transition-transform group-hover:scale-150 duration-500"></div>
                    <div class="w-12 h-12 bg-[#050505] border border-[#1e2438] rounded-xl flex items-center justify-center mb-4"><i data-lucide="activity" class="w-6 h-6 text-emerald-400"></i></div>
                    <p class="text-[11px] text-gray-500 font-bold uppercase tracking-widest mb-1">Active Linked Devices</p>
                    <h2 class="text-4xl font-extrabold text-emerald-400"><?= $pdo->query("SELECT COUNT(DISTINCT b.ip_address) FROM zentrax_binds b JOIN zentrax_keys k ON b.license_key=k.license_key WHERE k.created_by='$r_user' AND b.expired_date > NOW()")->fetchColumn() ?></h2>
                </div>
            </div>

        <?php elseif($page == 'generator'): ?>
            <div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h1 class="font-['Outfit'] text-3xl font-extrabold mb-1 uppercase tracking-wider">Key Generator</h1>
                    <p class="text-gray-400 text-sm">Create and deliver premium access keys.</p>
                </div>
                <?php if($is_low_balance): ?>
                    <a href="?page=wallet" class="bg-red-900/20 border border-red-500/30 text-red-400 px-4 py-2 rounded-lg text-[10px] font-bold uppercase tracking-widest animate-pulse flex items-center gap-2"><i data-lucide="alert-triangle" class="w-4 h-4"></i> Low Balance</a>
                <?php endif; ?>
            </div>

            <?php if($last_gen): ?>
                <div class="max-w-2xl mb-6 bg-emerald-900/10 border-l-4 border-l-emerald-500 border border-t-[#1e2438] border-r-[#1e2438] border-b-[#1e2438] p-6 rounded-r-2xl">
                    <h3 class="flex items-center gap-2 text-emerald-400 font-bold text-sm mb-4"><i data-lucide="check-circle" class="w-5 h-5"></i> Keys Generated Successfully! (-₹<?= $last_gen['cost'] ?>)</h3>
                    
                    <div class="flex flex-col md:flex-row gap-4 mb-4">
                        <textarea id="genKeysText" readonly class="w-full bg-[#050505] border border-emerald-900/50 p-4 rounded-xl text-emerald-400 font-mono text-sm outline-none resize-none leading-relaxed shadow-inner" rows="<?= $last_gen['qty'] > 5 ? 5 : $last_gen['qty'] ?>"><?= htmlspecialchars($last_gen['keys']) ?></textarea>
                        <button onclick="copyKeys()" id="copyBtn" class="bg-emerald-600 hover:bg-emerald-500 text-white shadow-[0_0_15px_rgba(16,185,129,0.3)] px-6 py-4 md:py-0 rounded-xl font-extrabold uppercase tracking-widest text-xs transition">COPY</button>
                    </div>
                    
                    <div class="text-[10px] text-emerald-500/70 font-bold uppercase flex flex-wrap gap-4 border-t border-emerald-900/30 pt-3">
                        <span class="bg-emerald-900/30 px-2 py-1 rounded">Duration: <?= $last_gen['dur'] ?> Days</span> 
                        <span class="bg-emerald-900/30 px-2 py-1 rounded">Limit: 1 Device</span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="glass-panel p-8 max-w-2xl relative overflow-hidden">
                <form method="POST" class="space-y-6 relative z-10">
                    <input type="hidden" name="generate" value="1">
                    
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2 mb-2"><i data-lucide="target" class="w-3 h-3"></i> Select Game / Feature</label>
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
                    
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2 mb-2"><i class="w-3 h-3" data-lucide="calendar"></i> Duration Plan</label>
                        <div class="custom-select-container" id="cs-dur">
                            <?php $first_dur = array_key_first($pricing); $first_price = $pricing[$first_dur] ?? 0; ?>
                            <input type="hidden" name="duration_val" id="inp-dur" value="<?= $first_dur ?>">
                            <div class="custom-select-trigger" onclick="toggleSelect('cs-dur')">
                                <span id="txt-dur"><?= $first_dur ?> Day(s) - ₹<?= $first_price ?></span><i data-lucide="chevron-down" class="w-4 h-4 text-gray-500"></i>
                            </div>
                            <div class="custom-select-dropdown">
                                <?php foreach($pricing as $days => $price): 
                                    $isSelected = ($days == $first_dur) ? 'selected' : '';
                                ?>
                                    <div class="custom-option <?= $isSelected ?>" data-price="<?= $price ?>" onclick="selOptDur('cs-dur', '<?= $days ?>', '<?= $days ?> Day(s) - ₹<?= $price ?>', <?= $price ?>)"><?= $days ?> Day(s) - ₹<?= $price ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2 mb-2"><i class="w-3 h-3" data-lucide="smartphone"></i> Device Limit</label>
                        <input type="text" value="1 Device Limit (Strict)" class="input-cool w-full font-bold opacity-50 cursor-not-allowed text-gray-500 border-[#1a1a1a]" readonly title="Resellers can only generate 1-Device keys">
                    </div>
                    
                    <div>
                        <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2 mb-2"><i class="w-3 h-3" data-lucide="copy"></i> Number of Keys</label>
                        <input type="number" name="quantity" id="qtyInput" value="1" min="1" max="100" oninput="updateEstimates()" class="input-cool w-full" required>
                    </div>

                    <div class="bg-[#050505] border border-[#1e2438] p-5 rounded-xl flex justify-between items-center">
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">Total Cost Deduction</span>
                        <span class="text-xl font-extrabold text-white">₹<span id="estCost"><?= $first_price ?></span></span>
                    </div>
                    
                    <button type="submit" class="btn-gradient w-full flex items-center justify-center gap-2 mt-4" <?= $is_low_balance?'title="Low Balance Warning"':'' ?>>
                        <i data-lucide="zap" class="w-5 h-5"></i> GENERATE LICENSES
                    </button>
                </form>
            </div>
            
            <script>
                let currentPrice = <?= $first_price ?>;
                function updateEstimates() {
                    const qty = parseInt(document.getElementById('qtyInput').value) || 1;
                    document.getElementById('estCost').innerText = currentPrice * qty;
                }
                function selOptDur(containerId, val, text, price) {
                    selOpt(containerId, val, text);
                    currentPrice = price;
                    updateEstimates();
                }
                function copyKeys() {
                    const t = document.getElementById("genKeysText"); t.select(); document.execCommand("copy");
                    const btn = document.getElementById("copyBtn"); btn.innerText = 'COPIED!';
                    setTimeout(() => { btn.innerText = 'COPY'; }, 2000);
                }
            </script>

        <?php elseif($page == 'keys'): ?>
            <div class="mb-6 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div>
                    <h1 class="font-['Outfit'] text-3xl font-extrabold mb-1 uppercase tracking-wider">License Registry</h1>
                    <p class="text-gray-400 text-sm">Monitor usages and manage security actions.</p>
                </div>
            </div>

            <div class="glass-panel overflow-hidden flex flex-col h-[70vh]">
                <div class="p-4 border-b border-[#1e2438] bg-[#050505] flex justify-between items-center shrink-0">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2 hidden md:flex"><i data-lucide="shield" class="w-4 h-4"></i> YOUR LICENSES</span>
                    <div class="relative w-full md:w-72">
                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500"></i>
                        <input type="text" id="rtSearch" onkeyup="realtimeSearch()" placeholder="Search Key/Status..." class="bg-[#0a0d16] border border-[#1e2438] pl-9 pr-4 py-2.5 w-full rounded-xl text-xs font-bold outline-none focus:border-blue-500 text-white font-mono placeholder-gray-600 transition">
                    </div>
                </div>

                <div class="overflow-x-auto overflow-y-auto flex-1">
                    <table class="w-full text-sm text-left whitespace-nowrap">
                        <thead class="text-gray-500 text-[10px] uppercase tracking-widest bg-[#050505] border-b border-[#1e2438] sticky top-0 z-10">
                            <tr>
                                <th class="p-5 font-bold">Key Data & Status</th>
                                <th class="p-5 font-bold">Feature Assigned</th>
                                <th class="p-5 font-bold">Expiry Info</th>
                                <th class="p-5 font-bold text-right">Controls</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#1e2438]">
                            <?php 
                            $sql = "SELECT k.*, 
                                   (SELECT COUNT(*) FROM zentrax_binds b WHERE b.license_key = k.license_key) as used,
                                   (SELECT expired_date FROM zentrax_binds b WHERE b.license_key = k.license_key ORDER BY id ASC LIMIT 1) as exp_date
                                   FROM zentrax_keys k WHERE created_by = '$r_user' ORDER BY id DESC LIMIT 100";
                            $keys = $pdo->query($sql)->fetchAll();
                            
                            if(count($keys)==0) echo "<tr><td colspan='4' class='p-8 text-center text-gray-500 text-xs font-bold'>No licenses generated yet.</td></tr>";
                            
                            $today = date('Y-m-d');
                            foreach($keys as $k): 
                                // Advanced Status Logic
                                $stat_label = ""; $stat_color = "";
                                if ($k['status'] == 0) { $stat_label = "BANNED"; $stat_color = "red-500"; }
                                elseif (!$k['exp_date']) { $stat_label = "NOT REGISTERED"; $stat_color = "blue-400"; }
                                elseif (strtotime($k['exp_date']) < time()) { $stat_label = "EXPIRED"; $stat_color = "red-500"; }
                                else { $stat_label = "ACTIVE"; $stat_color = "green-400"; }
                                
                                $resets_today = ($k['last_reset_date'] == $today) ? (int)$k['daily_resets'] : 0;
                            ?>
                            <tr class="hover:bg-[#111] transition-colors group reg-row">
                                <td class="p-5">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-mono text-white font-bold <?= $stat_label=='BANNED'?'line-through opacity-50':'' ?>"><?= $k['license_key'] ?></span>
                                    </div>
                                    <div class="text-[10px] font-bold tracking-widest text-<?= $stat_color ?> uppercase flex items-center gap-1">● <?= $stat_label ?></div>
                                </td>
                                <td class="p-5">
                                    <div class="text-xs text-gray-300 font-bold uppercase"><?= formatFeature($k['feature_name']) ?></div>
                                    <div class="text-[10px] text-gray-500 mt-1 font-bold">DUR: <?= $k['duration_val'] ?> <?= $k['duration_unit'] ?> | LIMIT: <?= $k['max_ips'] ?> DEV</div>
                                </td>
                                <td class="p-5">
                                    <div class="font-mono text-xs <?= $stat_label=='EXPIRED'?'text-red-400':'text-gray-300 font-bold' ?>">
                                        <?= $k['exp_date'] ? date('M d, Y - H:i', strtotime($k['exp_date'])) : '<span class="text-gray-600 italic">Waiting for login...</span>' ?>
                                    </div>
                                    <div class="text-[9px] font-bold uppercase tracking-widest mt-1 <?= $resets_today>=3?'text-red-500':'text-blue-500' ?>">RESETS TODAY: <?= $resets_today ?>/3</div>
                                </td>
                                <td class="p-5 flex gap-2 justify-end opacity-80 group-hover:opacity-100 transition-opacity">
                                    <a href="?page=keys&action=reset_key&id=<?= $k['id'] ?>" onclick="return confirm('Reset Hardware ID? (Limit: 3/day)')" class="bg-[#050505] border border-[#1e2438] p-2.5 rounded-lg text-blue-400 hover:bg-blue-900/20 transition-all shadow-sm" title="Reset HWID"><i data-lucide="rotate-ccw" class="w-4 h-4"></i></a>
                                    
                                    <?php if($k['status']==1): ?>
                                        <a href="?page=keys&action=ban_key&id=<?= $k['id'] ?>" class="bg-[#050505] border border-[#1e2438] p-2.5 rounded-lg text-orange-400 hover:bg-orange-900/20 transition-all shadow-sm" title="Ban Key"><i data-lucide="ban" class="w-4 h-4"></i></a>
                                    <?php else: ?>
                                        <a href="?page=keys&action=unban_key&id=<?= $k['id'] ?>" class="bg-[#050505] border border-[#1e2438] p-2.5 rounded-lg text-green-400 hover:bg-green-900/20 transition-all shadow-sm" title="Unban Key"><i data-lucide="check" class="w-4 h-4"></i></a>
                                    <?php endif; ?>
                                    
                                    <a href="?page=keys&action=delete_key&id=<?= $k['id'] ?>" onclick="return confirm('Wipe this key completely?')" class="bg-[#050505] border border-[#1e2438] p-2.5 rounded-lg text-red-500 hover:bg-red-900/20 transition-all shadow-sm" title="Delete Key"><i data-lucide="trash-2" class="w-4 h-4"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <script>
                function realtimeSearch() {
                    let input = document.getElementById("rtSearch").value.toUpperCase();
                    let rows = document.querySelectorAll(".reg-row");
                    rows.forEach(row => { row.style.display = row.innerText.toUpperCase().includes(input) ? "" : "none"; });
                }
            </script>

        <?php elseif($page == 'wallet'): ?>
            <div class="mb-8 flex items-center gap-3">
                <div class="w-12 h-12 bg-blue-900/30 rounded-xl flex items-center justify-center border border-blue-500/30 shadow-[0_0_15px_rgba(59,130,246,0.3)]"><i data-lucide="indian-rupee" class="w-6 h-6 text-blue-400"></i></div>
                <div>
                    <h1 class="font-['Outfit'] text-3xl font-extrabold mb-1 uppercase tracking-wider">Add Funds</h1>
                    <p class="text-gray-400 text-sm">Top up your reseller wallet via UPI.</p>
                </div>
            </div>

            <div class="grid lg:grid-cols-2 gap-8">
                
                <div class="glass-panel p-8 relative overflow-hidden" id="step1">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-500/10 rounded-full blur-3xl pointer-events-none"></div>
                    <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#1e2438] relative z-10">
                        <span class="bg-blue-600 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold">1</span>
                        <h2 class="text-lg font-bold text-white uppercase tracking-wider">Enter Deposit Amount</h2>
                    </div>
                    <div class="space-y-5 relative z-10">
                        <div>
                            <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest flex items-center gap-2 mb-2">Amount (₹100 - ₹100,000)</label>
                            <div class="relative">
                                <span class="absolute left-5 top-1/2 -translate-y-1/2 text-gray-400 font-extrabold text-xl">₹</span>
                                <input type="number" id="payAmount" min="100" max="100000" class="input-cool w-full pl-12 text-2xl font-extrabold font-sans" placeholder="0">
                            </div>
                        </div>
                        <button onclick="generateQR()" class="btn-gradient w-full">Proceed to Pay</button>
                    </div>
                </div>

                <div class="glass-panel p-8 hidden animate-[pageFadeIn_0.3s_ease-out]" id="step2">
                    <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#1e2438]">
                        <span class="bg-blue-600 text-white w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold">2</span>
                        <h2 class="text-lg font-bold text-blue-400 uppercase tracking-wider">Scan & Submit Proof</h2>
                    </div>
                    
                    <div class="flex flex-col items-center justify-center mb-8 bg-[#050505] p-6 rounded-2xl border border-[#1e2438]">
                        <div class="bg-white p-3 rounded-xl shadow-lg mb-4">
                            <img id="qrImage" src="" alt="UPI QR Code" class="w-40 h-40">
                        </div>
                        <p class="text-[10px] text-gray-500 mb-1 font-bold uppercase tracking-widest">Amount to Pay</p>
                        <h3 class="text-4xl font-extrabold text-blue-400 tracking-tight" id="displayAmount">₹0</h3>
                        
                        <a id="upiLink" href="#" class="mt-5 w-full bg-blue-600/10 border border-blue-500/30 text-blue-400 hover:bg-blue-600 hover:text-white py-3.5 rounded-xl font-extrabold flex items-center justify-center gap-2 transition-all text-[11px] uppercase tracking-widest shadow-inner">
                            <i data-lucide="smartphone" class="w-4 h-4"></i> Open UPI App
                        </a>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="space-y-5">
                        <input type="hidden" name="submit_deposit" value="1">
                        <input type="hidden" name="deposit_amount" id="finalAmount" value="0">
                        
                        <div>
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-2 mb-2"><i data-lucide="image" class="w-3 h-3"></i> Payment Screenshot (Required)</label>
                            <input type="file" name="screenshot" accept="image/*" required class="w-full text-sm text-gray-400 file:mr-4 file:py-3 file:px-4 file:rounded-lg file:border-0 file:text-[10px] file:uppercase file:tracking-widest file:font-bold file:bg-[#1e2438] file:text-white hover:file:bg-gray-700 transition-colors bg-[#050505] border border-[#1e2438] rounded-xl p-1.5 cursor-pointer">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block">Telegram (Opt)</label>
                                <input type="text" name="tg_user" placeholder="@username" class="input-cool w-full !p-3">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-2 block">WhatsApp (Opt)</label>
                                <input type="text" name="wa_num" placeholder="+91..." class="input-cool w-full !p-3">
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-gradient w-full mt-2"><i data-lucide="send" class="inline w-4 h-4 mr-2"></i> Submit Deposit</button>
                    </form>
                </div>
            </div>

            <div class="mt-8 glass-panel p-6">
                <h2 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-5 flex items-center gap-2"><i data-lucide="history" class="w-4 h-4"></i> Recent Transactions</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <tbody class="divide-y divide-[#1e2438]">
                            <?php 
                            $deps = $pdo->query("SELECT * FROM deposits WHERE user_id=$r_id ORDER BY id DESC LIMIT 5")->fetchAll();
                            if(count($deps)==0) echo "<tr><td class='py-6 text-gray-600 text-xs font-bold text-center'>No transactions found.</td></tr>";
                            foreach($deps as $d): 
                            ?>
                            <tr class="hover:bg-[#111] transition-colors">
                                <td class="py-4 px-4">
                                    <div class="font-extrabold text-white mb-0.5 font-mono">DEP-<?= str_pad($d['id'], 4, '0', STR_PAD_LEFT) ?></div>
                                    <div class="text-[10px] text-gray-500 font-bold uppercase tracking-widest"><?= date('d M, Y - H:i', strtotime($d['created_at'])) ?></div>
                                </td>
                                <td class="py-4 px-4 font-extrabold text-lg text-blue-400">₹<?= $d['amount'] ?></td>
                                <td class="py-4 px-4 text-right">
                                    <?php if($d['status']=='pending') echo "<span class='text-yellow-500 bg-yellow-500/10 border border-yellow-500/20 px-3 py-1.5 rounded-md text-[9px] font-extrabold tracking-widest uppercase'>PENDING</span>";
                                    elseif($d['status']=='approved') echo "<span class='text-emerald-500 bg-emerald-500/10 border border-emerald-500/20 px-3 py-1.5 rounded-md text-[9px] font-extrabold tracking-widest uppercase'>APPROVED</span>";
                                    else echo "<span class='text-red-500 bg-red-500/10 border border-red-500/20 px-3 py-1.5 rounded-md text-[9px] font-extrabold tracking-widest uppercase'>DECLINED</span>"; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        lucide.createIcons();
        
        const mobileNav = document.getElementById('mobileNav');
        const overlay = document.getElementById('overlay');
        
        document.querySelector('button[onclick]').addEventListener('click', () => { overlay.classList.remove('hidden'); });
        overlay.addEventListener('click', () => { overlay.classList.add('hidden'); mobileNav.classList.add('-translate-x-full'); });

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
            document.querySelectorAll('#' + containerId + ' .custom-option').forEach(el => el.classList.remove('selected'));
            event.target.classList.add('selected');
            document.querySelector('#' + containerId + ' .custom-select-dropdown').classList.remove('open');
        }
        
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.custom-select-container')) {
                document.querySelectorAll('.custom-select-dropdown').forEach(el => el.classList.remove('open'));
            }
        });

        function generateQR() {
            let amt = document.getElementById('payAmount').value;
            if(amt < 100 || amt > 100000) { alert("Amount must be between ₹100 and ₹100,000"); return; }
            
            let upiID = "vineetsikdar@oksbi"; 
            let upiString = `upi://pay?pa=${upiID}&pn=Zentrax&am=${amt}&cu=INR`;
            
            document.getElementById('qrImage').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(upiString)}`;
            document.getElementById('upiLink').href = upiString;
            document.getElementById('displayAmount').innerText = "₹" + amt;
            document.getElementById('finalAmount').value = amt;
            
            document.getElementById('step1').classList.add('hidden');
            document.getElementById('step2').classList.remove('hidden');
        }

        <?php if($auto_dl): ?>
            const a = document.createElement('a'); 
            a.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent(`<?= $auto_dl ?>`); 
            a.download = 'PREDATOR_Reseller_Keys_<?= date("Y_m_d_H_i") ?>.txt'; 
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        <?php endif; ?>
    </script>
</body></html>
