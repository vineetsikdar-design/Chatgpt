<?php
// --- 1. FIX SESSION SPAM BUG ---
// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 2. SET PHP TIMEZONE TO GLOBAL (UTC) ---
date_default_timezone_set('UTC');

// --- 3. CENTRALIZED MASTER SECRET ---
define('MASTER_SECRET', 'ZENTRAX_ULTRA_GODMODE_!@#');

$host = 'localhost';
$db   = 'zxffvipp_proxypredator'; // Tera confirmed DB name
$user = 'zxffvipp_proxypredator'; //
$pass = 'zxffvipp_proxypredator'; //
$charset = 'utf8mb4'; //

$dsn = "mysql:host=$host;dbname=$db;charset=$charset"; //

// --- 4. DATABASE COLLATION FIX (Error 1267 Fix) ---
$options = [ 
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, //
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, //
    PDO::ATTR_EMULATE_PREPARES => false, //
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci" 
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options); //
    
    // SET MYSQL DATABASE TIMEZONE TO GLOBAL (+00:00)
    // Iske bina database server ka purana time hi uthayega
    $pdo->exec("SET time_zone = '+00:00'"); //
    
    // --- Core Tables ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS banned_ips (ip_address VARCHAR(50) PRIMARY KEY, banned_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); //
    $pdo->exec("CREATE TABLE IF NOT EXISTS zentrax_binds (id INT AUTO_INCREMENT PRIMARY KEY, license_key VARCHAR(50), ip_address VARCHAR(50), expired_date DATETIME, reset_count INT DEFAULT 0)"); //
    $pdo->exec("CREATE TABLE IF NOT EXISTS zentrax_sessions (ip_address VARCHAR(50) PRIMARY KEY, active_folder VARCHAR(100))"); //

    // --- Reseller & User Table ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE,
        password VARCHAR(255),
        role VARCHAR(20) DEFAULT 'reseller',
        balance INT DEFAULT 0,
        status INT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); //

    // --- Keys Table ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS zentrax_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        license_key VARCHAR(50) UNIQUE,
        feature_name VARCHAR(100),
        duration_val INT,
        duration_unit VARCHAR(20),
        max_ips INT DEFAULT 1,
        status INT DEFAULT 1,
        created_by VARCHAR(50) DEFAULT 'admin',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); //

    // --- Deposits Table ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS deposits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        amount INT,
        screenshot_path VARCHAR(255),
        telegram_username VARCHAR(100),
        whatsapp_number VARCHAR(20),
        status VARCHAR(20) DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"); //

    // --- Pricing Table ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS pricing (
        id INT AUTO_INCREMENT PRIMARY KEY, duration_days INT UNIQUE, price INT
    )"); //

    // --- INITIAL DATA INJECTIONS ---
    $admin_chk = $pdo->query("SELECT * FROM users WHERE username = 'admin'")->fetch(); //
    if(!$admin_chk) { //
        $hashed = password_hash('admin123', PASSWORD_DEFAULT); //
        $pdo->query("INSERT INTO users (username, password, role) VALUES ('admin', '$hashed', 'admin')"); //
    } //
    
    if($pdo->query("SELECT COUNT(*) FROM pricing")->fetchColumn() == 0) { //
        $pdo->exec("INSERT INTO pricing (duration_days, price) VALUES (1, 100), (7, 399), (15, 599), (30, 999)"); //
    } //

} catch (\PDOException $e) { //
    die("Database Connection Failed! " . $e->getMessage()); //
} //
?>
