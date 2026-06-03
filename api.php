<?php
// 🔥 THE ULTIMATE ANTI-BOT & POISON TRAP 🔥
$agent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$is_unauthorized = false;

// Block Headless Tools
if (empty($agent) || preg_match('/(curl|postman|wget|python|insomnia|go-http)/i', $agent)) {
    $is_unauthorized = true;
}

// Block Direct Browser Navigation
if (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'document') {
    $is_unauthorized = true;
}

// Check New Dynamic Header
$header_token = $_SERVER['HTTP_X_ZENTRAX_AUTH'] ?? '';
$parts = explode('.', $header_token);

if (count($parts) !== 2) {
    $is_unauthorized = true;
} else {
    $client_ts = (int)$parts[0];
    $client_hash = $parts[1];
    
    if (abs(time() - $client_ts) > 300) {
        $is_unauthorized = true;
    } else {
        $k = "ZENTRAX";
        $s = (string)$client_ts;
        $expected_hash = "";
        for($i=0; $i<strlen($s); $i++) {
            $val = (ord($s[$i]) + ord($k[$i % strlen($k)])) ^ 42;
            $expected_hash .= str_pad(dechex($val), 2, "0", STR_PAD_LEFT);
        }
        if ($client_hash !== $expected_hash) {
            $is_unauthorized = true;
        }
    }
}

if ($is_unauthorized) {
    http_response_code(404);
    die("<html><head><title>404 Not Found</title></head><body><center><h1>404 Not Found</h1><hr>nginx/1.24.0</center></body></html>");
}

// ==========================================
require 'config.php';

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'];
}
$user_ip = getClientIP();

$request_file = $_GET['file'] ?? '';
$action = $_GET['action'] ?? ''; 

$stmt = $pdo->prepare("SELECT * FROM zentrax_binds WHERE ip_address = ? AND expired_date > NOW()");
$stmt->execute([$user_ip]);
$is_valid = $stmt->fetch() ? true : false;

$active_folder = "";
if ($is_valid) {
    $ses_stmt = $pdo->query("SELECT active_folder FROM zentrax_sessions WHERE ip_address = '$user_ip'");
    $active_folder = $ses_stmt->fetchColumn();
}

if ($action === 'popup') {
    header('Content-Type: text/plain');
    if ($is_valid) {
        $feat = $active_folder ? str_replace("MOD: ", "", $active_folder) : "PROXY ACTIVE";
        echo "[c][b][00FF00]PREDATOR VIP ACCESS GRANTED\n[FFFFFF]----------------------------\n[00FFFF]$feat\n[FFFFFF]TG: @predatorxit";
    } else {
        echo "[c][b][FFFF00]⚠️ IP NOT REGISTERED!\n[FFFFFF]Please login and authorize your IP at:\n[FF0000]proxy.predatorxits.in\n[FFFFFF]or contact [00FFFF]@predatorxit";
    }
    exit;
}

if ($request_file) {
    if ($is_valid) {
        if (!$active_folder) {
            http_response_code(400); die("Error: No game feature set in portal.");
        }
        
        $file_path = "secure_hex/{$active_folder}/" . basename($request_file) . '.txt';
        
        if (file_exists($file_path)) {
            header('Content-Type: text/plain');
            
            $raw_hex = file_get_contents($file_path);
            $clean_hex = preg_replace('/\s+/', '', $raw_hex);
            
            // 🚨 THE MASTER TRAP: Inject 1 random garbage char after every 2 real chars (1 byte)
            $poisoned = "";
            $chars = "0123456789abcdef";
            for ($i = 0; $i < strlen($clean_hex); $i += 2) {
                // Ignore incomplete bytes at the end
                if ($i + 1 < strlen($clean_hex)) {
                    $byte = $clean_hex[$i] . $clean_hex[$i+1];
                    $garbage = $chars[mt_rand(0, 15)];
                    $poisoned .= $byte . $garbage;
                }
            }
            
            echo "ZTX" . $poisoned . "PRD";
            exit;
            
        } else {
            http_response_code(404); die("Error: Payload file not found.");
        }
    } else {
        http_response_code(403); die("Error: Unauthorized Access.");
    }
}
?>
