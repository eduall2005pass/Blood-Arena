<?php  
// ── PWA Manifest endpoint — no separate file needed ──────────
if (isset($_GET['manifest'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo json_encode([
        "name"                    => "Blood Arena",
        "short_name"              => "Blood Arena",
        "description"             => "SHSMC Blood Donation Portal — জরুরি রক্ত খুঁজুন, রক্তদাতা হিসেবে যোগ দিন",
        "start_url"               => "/",
        "scope"                   => "/",
        "display"                 => "standalone",
        "orientation"             => "portrait-primary",
        "background_color"        => "#08090f",
        "theme_color"             => "#dc2626",
        "lang"                    => "bn",
        "categories"              => ["health","medical"],
        "prefer_related_applications" => false,
        "icons" => [
            ["src"=>"/icon.png","sizes"=>"192x192","type"=>"image/png","purpose"=>"any"],
            ["src"=>"/icon.png","sizes"=>"192x192","type"=>"image/png","purpose"=>"maskable"],
            ["src"=>"/icon.png","sizes"=>"512x512","type"=>"image/png","purpose"=>"any"],
            ["src"=>"/icon.png","sizes"=>"512x512","type"=>"image/png","purpose"=>"maskable"]
        ],
        "shortcuts" => [
            ["name"=>"রক্তদাতা খুঁজুন","short_name"=>"Donors","url"=>"/?tab=donors","icons"=>[["src"=>"/icon.png","sizes"=>"192x192"]]],
            ["name"=>"Emergency Request","short_name"=>"Emergency","url"=>"/?tab=emergency","icons"=>[["src"=>"/icon.png","sizes"=>"192x192"]]]
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Badge SVG endpoint — monochrome white blood drop for Android status bar ──
// Android notification status bar শুধু monochrome icon support করে।
// /icon.png colorful হওয়ায় white square দেখায়।
// এই endpoint থেকে proper monochrome blood drop SVG serve করা হয়।
if (isset($_GET['badge_icon'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96">'
       . '<path fill="#ffffff" fill-rule="evenodd" d="'
       . 'M48 8 C48 8 18 46 18 62 a30 30 0 0 0 60 0 C78 46 48 8 48 8z '
       . 'M44 52 L44 74 L52 74 L52 52 Z '
       . 'M37 59 L59 59 L59 67 L37 67 Z'
       . '"/>'
       . '</svg>';
    exit;
}
// ─────────────────────────────────────────────────────────────
ob_start(); // Buffer output — prevents PHP warnings/notices from corrupting JSON responses
include "db.php";  

// === EXTREME SQL INJECTION PROTECTION ===
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// === SCHEMA MIGRATION — file-flag ensures this runs only ONCE ever, not every request ===
// Running ALTER TABLE + UPDATE on every request caused 2-3s delay on all AJAX calls
$_schema_flag = __DIR__ . '/.schema_v1_done';
if (!file_exists($_schema_flag)) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("ALTER TABLE donors ADD COLUMN IF NOT EXISTS total_donations INT DEFAULT 0");
    $conn->query("ALTER TABLE donors ADD COLUMN IF NOT EXISTS badge_level VARCHAR(10) DEFAULT 'New'");
    $conn->query("ALTER TABLE donors ADD COLUMN IF NOT EXISTS willing_to_donate VARCHAR(3) DEFAULT 'yes'");
    $conn->query("ALTER TABLE donors ADD COLUMN IF NOT EXISTS reg_geo VARCHAR(300) DEFAULT 'Not captured'");
    $conn->query("ALTER TABLE donors ADD COLUMN IF NOT EXISTS reg_ip VARCHAR(50) DEFAULT NULL");
    $conn->query("ALTER TABLE donors ADD COLUMN IF NOT EXISTS reg_device VARCHAR(300) DEFAULT NULL");
    $conn->query("ALTER TABLE donors ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    $conn->query("UPDATE donors SET badge_level = CASE WHEN total_donations>=10 THEN 'Legend' WHEN total_donations>=5 THEN 'Hero' WHEN total_donations>=2 THEN 'Active' ELSE 'New' END WHERE badge_level IS NULL OR badge_level=''");
    // Also fix any badge_level that's out of sync with total_donations
    $conn->query("UPDATE donors SET badge_level = CASE WHEN total_donations>=10 THEN 'Legend' WHEN total_donations>=5 THEN 'Hero' WHEN total_donations>=2 THEN 'Active' ELSE 'New' END");
    $conn->query("UPDATE donors SET willing_to_donate='yes' WHERE willing_to_donate IS NULL OR willing_to_donate=''");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    @file_put_contents($_schema_flag, date('Y-m-d H:i:s'));
}
// ── One-time badge sync fix (v2) ─────────────────────────────
$_schema_v2 = __DIR__ . '/.schema_v2_done';
if(!file_exists($_schema_v2) && isset($conn)){
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("UPDATE donors SET badge_level = CASE WHEN total_donations>=10 THEN 'Legend' WHEN total_donations>=5 THEN 'Hero' WHEN total_donations>=2 THEN 'Active' ELSE 'New' END");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    @file_put_contents($_schema_v2, date('Y-m-d H:i:s'));
}
// ── Schema v3: fix blood_requests table for delete_token ──────
// Runs ONCE on first page load after deploy. Silently adds delete_token
// column and converts ENUM columns to VARCHAR for InfinityFree MySQL 5.7 compat.
$_schema_v3 = __DIR__ . '/.schema_v3_done';
if(!file_exists($_schema_v3) && isset($conn)){
    mysqli_report(MYSQLI_REPORT_OFF);
    // Create table fresh if not exists (with correct schema)
    $conn->query("CREATE TABLE IF NOT EXISTS `blood_requests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `patient_name` VARCHAR(100) NOT NULL,
        `blood_group` VARCHAR(5) NOT NULL,
        `hospital` VARCHAR(200) NOT NULL,
        `contact` VARCHAR(20) NOT NULL,
        `urgency` VARCHAR(10) DEFAULT 'High',
        `bags_needed` INT DEFAULT 1,
        `note` VARCHAR(500) DEFAULT '',
        `status` VARCHAR(20) DEFAULT 'Active',
        `delete_token` VARCHAR(10) DEFAULT NULL,
        `req_ip` VARCHAR(50) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add delete_token if missing (error suppressed if already exists)
    $conn->query("ALTER TABLE blood_requests ADD COLUMN delete_token VARCHAR(10) DEFAULT NULL");
    // Convert ENUM → VARCHAR (error suppressed if already VARCHAR)
    $conn->query("ALTER TABLE blood_requests MODIFY COLUMN urgency VARCHAR(10) DEFAULT 'High'");
    $conn->query("ALTER TABLE blood_requests MODIFY COLUMN status VARCHAR(20) DEFAULT 'Active'");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    @file_put_contents($_schema_v3, date('Y-m-d H:i:s'));
}
// ── Schema v4: persistent analytics_counters table ───────────
// This table stores ever-increasing counters that never decrease,
// even if call_logs are cleared or donors are deleted.
$_schema_v4 = __DIR__ . '/.schema_v4_done';
if(!file_exists($_schema_v4) && isset($conn)){
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("CREATE TABLE IF NOT EXISTS `analytics_counters` (
        `counter_name` VARCHAR(50) PRIMARY KEY,
        `counter_value` BIGINT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Seed initial values from existing data so counts don't reset on first deploy
    $conn->query("INSERT INTO analytics_counters (counter_name, counter_value)
        SELECT 'total_calls_ever', COUNT(*) FROM call_logs
        ON DUPLICATE KEY UPDATE counter_value = VALUES(counter_value)");
    $conn->query("INSERT INTO analytics_counters (counter_name, counter_value)
        SELECT 'total_donations_ever', COALESCE(SUM(total_donations),0) FROM donors
        ON DUPLICATE KEY UPDATE counter_value = VALUES(counter_value)");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    @file_put_contents($_schema_v4, date('Y-m-d H:i:s'));
}
if(isset($conn)){
    // Set strictly to utf8mb4 to prevent multi-byte encoding SQL injection attacks
    $conn->set_charset("utf8mb4");
}
// ── Schema v5: security code requests + service notifications + ref_code ──
$_schema_v5 = __DIR__ . '/.schema_v5_done';
if(!file_exists($_schema_v5) && isset($conn)){
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("ALTER TABLE donors ADD COLUMN IF NOT EXISTS ref_code VARCHAR(10) DEFAULT NULL");
    $existing = $conn->query("SELECT id FROM donors WHERE ref_code IS NULL OR ref_code=''");
    if($existing) {
        while($erow = $existing->fetch_assoc()) {
            $rc = 'RF' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $conn->query("UPDATE donors SET ref_code='$rc' WHERE id=".(int)$erow['id']." AND (ref_code IS NULL OR ref_code='')");
        }
    }
    $conn->query("CREATE TABLE IF NOT EXISTS `security_code_requests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `donor_number` VARCHAR(20) NOT NULL,
        `ref_code` VARCHAR(10) NOT NULL,
        `device_id` VARCHAR(100) NOT NULL,
        `req_ip` VARCHAR(50) DEFAULT NULL,
        `status` VARCHAR(20) DEFAULT 'pending',
        `admin_note` VARCHAR(500) DEFAULT '',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS `service_notifications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `device_id` VARCHAR(100) NOT NULL,
        `type` VARCHAR(30) NOT NULL,
        `message` TEXT NOT NULL,
        `is_read` TINYINT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    @file_put_contents($_schema_v5, date('Y-m-d H:i:s'));
}
// ── Schema v6: admin_messages table ──────────────────────
$_schema_v6 = __DIR__ . '/.schema_v6_done';
if(!file_exists($_schema_v6) && isset($conn)){
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("CREATE TABLE IF NOT EXISTS `admin_messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `sender_name` VARCHAR(100) NOT NULL,
        `sender_phone` VARCHAR(20) NOT NULL,
        `message` TEXT NOT NULL,
        `device_id` VARCHAR(100) NOT NULL,
        `is_read` TINYINT DEFAULT 0,
        `admin_reply` TEXT DEFAULT NULL,
        `replied_at` TIMESTAMP NULL DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    @file_put_contents($_schema_v6, date('Y-m-d H:i:s'));
}
// ── Schema v7: view_count for secret code retrieval (max 3) ──
$_schema_v7 = __DIR__ . '/.schema_v7_done';
if(!file_exists($_schema_v7) && isset($conn)){
    mysqli_report(MYSQLI_REPORT_OFF);
    // Add view_count to security_code_requests
    $conn->query("ALTER TABLE security_code_requests ADD COLUMN IF NOT EXISTS view_count TINYINT DEFAULT 0");
    // ref_code column on donors no longer used — make nullable silently
    $conn->query("ALTER TABLE donors MODIFY COLUMN ref_code VARCHAR(10) DEFAULT NULL");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    @file_put_contents($_schema_v7, date('Y-m-d H:i:s'));
}
// === ENHANCED SECURITY HEADERS (XSS + Clickjacking + HSTS + Permissions) ===
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.myinstants.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; img-src 'self' data: https: blob:; font-src 'self' https://fonts.gstatic.com; connect-src 'self' https://*.basemaps.cartocdn.com https://nominatim.openstreetmap.org; media-src 'self' https://www.myinstants.com;");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("Permissions-Policy: geolocation=(self)");
header("Cache-Control: no-store, no-cache, must-revalidate, private");
header("X-Permitted-Cross-Domain-Policies: none");

// === XSS ESCAPE HELPER ===
function esc($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// === CSRF & HIGH SECURITY SESSION ===
// Detect HTTPS — works on both localhost (HTTP) and production (HTTPS)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Strip port from HTTP_HOST for cookie domain (e.g. "localhost:8080" → "localhost")
$cookieDomain = strtok($_SERVER['HTTP_HOST'] ?? '', ':');

session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => $cookieDomain,
    'secure' => $isHttps, // true on HTTPS production, false on HTTP localhost
    'httponly' => true, // Prevents JS access to session
    'samesite' => 'Strict' // Prevents cross-site request forgery
]);
session_start();

// Prevent Session Fixation — only regenerate once per new session, not every request
if (empty($_SESSION['_initiated'])) {
    session_regenerate_id(true);
    $_SESSION['_initiated'] = true;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// === RATE LIMITING (session-based) ===
function checkRateLimit($action, $maxAttempts = 10, $windowSeconds = 60) {
    $key = 'rl_' . $action;
    $now = time();
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'window_start' => $now];
    }
    if ($now - $_SESSION[$key]['window_start'] > $windowSeconds) {
        $_SESSION[$key] = ['count' => 0, 'window_start' => $now];
    }
    $_SESSION[$key]['count']++;
    if ($_SESSION[$key]['count'] > $maxAttempts) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        while(ob_get_level()) ob_end_clean(); ob_start();
        die(json_encode(["status" => "error", "msg" => "অনেক বেশি চেষ্টা করা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।"]));
    }
}

// === INPUT LENGTH LIMITS ===
function validateLength($value, $max, $label) {
    if (mb_strlen($value, 'UTF-8') > $max) {
        header('Content-Type: application/json; charset=utf-8');
        while(ob_get_level()) ob_end_clean(); ob_start();
        die(json_encode(["status" => "error", "msg" => "$label অনেক বড়। সর্বোচ্চ $max অক্ষর।"]));
    }
}

// CSRF check function — also enforces POST-only for all sensitive actions
function checkCSRF() {
    // Block GET requests from ever triggering sensitive actions
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        while(ob_get_level()) ob_end_clean(); ob_start();
        die(json_encode(["status" => "error", "msg" => "Method not allowed."]));
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        header('Content-Type: application/json; charset=utf-8');
        while(ob_get_level()) ob_end_clean(); ob_start();
        http_response_code(403);
        die(json_encode(["status" => "error", "msg" => "Security check failed. Please refresh the page."]));
    }
}

// Generate Unique Secret Code
function generateSecretCode($conn) {
    $attempts = 0;
    do {
        $code = 'SHSMC-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
        $check = $conn->prepare("SELECT id FROM donors WHERE secret_code = ?");
        $check->bind_param("s", $code);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        $attempts++;
    } while($exists && $attempts < 20);
    return $code;
}

// === AJAX DETECTION — skip heavy queries on every AJAX call ===
$_is_ajax = !empty($_POST['log_call']) || !empty($_POST['get_phone'])
         || !empty($_POST['ajax_filter']) || !empty($_POST['ajax_submit'])
         || !empty($_POST['get_blood_requests']) || !empty($_POST['ajax_update'])
         || !empty($_POST['verify_secret']) || !empty($_POST['submit_report'])
         || !empty($_POST['submit_blood_request']) || !empty($_POST['save_push_sub'])
         || !empty($_POST['delete_blood_request']) || !empty($_POST['delete_donor'])
         || !empty($_POST['get_analytics']) || !empty($_POST['get_map_data'])
         || !empty($_POST['get_nearby_donors'])
         || !empty($_POST['request_new_secret_code'])
         || !empty($_POST['get_secret_code_by_ref'])
         || !empty($_POST['get_service_notifs'])
         || !empty($_POST['mark_service_notif_read'])
         || !empty($_POST['submit_admin_message'])
         || !empty($_POST['get_admin_messages'])
         || !empty($_POST['mark_admin_msg_read'])
         || !empty($_POST['save_device_id']);

// === LIVE COUNTS — only on full page load, never on AJAX ===
$avail_counts = ["A+"=>0,"A-"=>0,"B+"=>0,"B-"=>0,"AB+"=>0,"AB-"=>0,"O+"=>0,"O-"=>0];
$total_donors_count = 0;
if (!$_is_ajax) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $avail_q = $conn->query("SELECT blood_group, COUNT(*) as cnt FROM donors 
        WHERE (willing_to_donate IS NULL OR willing_to_donate='yes' OR willing_to_donate='')
          AND (last_donation='no' OR last_donation='' OR last_donation='0000-00-00'
               OR DATEDIFF(CURDATE(), last_donation) >= 120)
        GROUP BY blood_group");
    if ($avail_q) {
        while ($rowc = $avail_q->fetch_assoc())
            if (isset($avail_counts[$rowc['blood_group']]))
                $avail_counts[$rowc['blood_group']] = (int)$rowc['cnt'];
    }
    $tc = $conn->query("SELECT COUNT(*) as c FROM donors");
    $total_donors_count = $tc ? (int)($tc->fetch_assoc()['c'] ?? 0) : 0;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

// --- AJAX: Log Call Activity ---
if(isset($_POST['log_call'])){
    checkCSRF();
    header('Content-Type: text/plain; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start(); // flush any buffered warnings before output
    checkRateLimit('log_call', 20, 60);
    $d_id = (int)$_POST['donor_id'];
    $c_name = trim($_POST['caller_name'] ?? '');
    $c_phone = trim($_POST['caller_phone'] ?? '');
    $loc = trim($_POST['location_data'] ?? 'Not provided');
    $ip = $_SERVER['REMOTE_ADDR'];
    $device = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 300, 'UTF-8');

    // Server-side validation
    if(!preg_match('/^\+8801\d{9}$/', $c_phone)) { ob_clean(); echo "invalid"; exit(); }
    validateLength($c_name, 100, 'নাম');
    validateLength($loc, 500, 'Location');
    if(empty($c_name)) { ob_clean(); echo "invalid"; exit(); }

    $stmt = $conn->prepare("INSERT INTO call_logs (donor_id, caller_name, caller_phone, caller_ip, caller_location, device_info) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isssss", $d_id, $c_name, $c_phone, $ip, $loc, $device);
    $stmt->execute();
    $stmt->close();
    // Increment persistent counter — never decreases even if call_logs is cleared
    $conn->query("INSERT INTO analytics_counters (counter_name, counter_value) VALUES ('total_calls_ever', 1)
        ON DUPLICATE KEY UPDATE counter_value = counter_value + 1");
    while(ob_get_level()) ob_end_clean(); ob_start();
    echo "logged";
    exit();
}

// --- AJAX: Report Harassment ---
if(isset($_POST['submit_report'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start(); // flush any buffered warnings before JSON output
    checkRateLimit('submit_report', 5, 300); // Max 5 reports per 5 min
    $d_phone = trim($_POST['donor_phone'] ?? '');
    $h_info = trim($_POST['harasser_info'] ?? '');
    $comment = trim($_POST['report_comment'] ?? '');

    // Server-side validation
    if(!preg_match('/^\+8801\d{9}$/', $d_phone)) {
        echo json_encode(["status"=>"error","msg"=>"সঠিক ফোন নম্বর দিন।"]);
        exit();
    }
    validateLength($h_info, 200, 'হয়রানিকারীর তথ্য');
    validateLength($comment, 1000, 'অভিযোগ');
    if(empty($h_info) || empty($comment)) {
        echo json_encode(["status"=>"error","msg"=>"সব তথ্য দিন।"]);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO reports (donor_phone, harasser_info, report_comment) VALUES (?,?,?)");
    $stmt->bind_param("sss", $d_phone, $h_info, $comment);
    if($stmt->execute()){
        $to = "2005siam1hasan@gmail.com";
        $subject = "Donor Harassment Report - Blood Arena";
        // FIX: strip newlines to prevent email header injection
        $safe_phone   = str_replace(["\r", "\n"], '', $d_phone);
        $safe_hinfo   = str_replace(["\r", "\n"], '', $h_info);
        $safe_comment = str_replace(["\r", "\n"], '', $comment);
        $message = "Donor Phone: $safe_phone\nHarasser Details: $safe_hinfo\nComment: $safe_comment";
        mail($to, $subject, $message);
        while(ob_get_level()) ob_end_clean(); ob_start();
        echo "success";
    } else {
        while(ob_get_level()) ob_end_clean(); ob_start();
        echo json_encode(["status"=>"error","msg"=>"রিপোর্ট জমা দেওয়া ব্যর্থ হয়েছে। আবার চেষ্টা করুন।"]);
    }
    $stmt->close();
    exit();
}

// --- Secure Phone Fetch ---
if(isset($_POST['get_phone'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start(); // flush any buffered warnings before JSON output
    checkRateLimit('get_phone', 10, 60); // FIX: prevent phone number enumeration
    $id = (int)$_POST['id'];
    $stmt = $conn->prepare("SELECT phone FROM donors WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    while(ob_get_level()) ob_end_clean(); ob_start();
    echo $res ? trim($res['phone']) : "error";
    exit();
}

// === VERIFY SECRET CODE & LOAD INFO ===
if(isset($_POST['verify_secret'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('verify_secret', 15, 60);
    $code = trim($_POST['secret_code']);
    validateLength($code, 25, 'Secret Code');
    $stmt = $conn->prepare("SELECT name, location, last_donation, willing_to_donate, total_donations, reg_geo FROM donors WHERE secret_code=?");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if($res){
        $display_last = ($res['last_donation'] == 'no' || empty($res['last_donation']) || $res['last_donation']=='0000-00-00') ? 'no' : date('d/m/Y', strtotime($res['last_donation']));
        $badge = getBadgeInfo((int)$res['total_donations']);
        $geo_lat = ''; $geo_lng = '';
        if(!empty($res['reg_geo']) && preg_match('/Lat:\s*([\-0-9.]+),\s*Lon:\s*([\-0-9.]+)/', $res['reg_geo'], $gm)){
            $geo_lat = $gm[1]; $geo_lng = $gm[2];
        }
        echo json_encode([
            "status"=>"success",
            "name"=>$res['name'],
            "location"=>$res['location'],
            "last_donation"=>$display_last,
            "willing"=>$res['willing_to_donate'],
            "total_donations"=>(int)$res['total_donations'],
            "badge_level"=>$badge['level'],
            "badge_icon"=>$badge['icon'],
            "geo_lat"=>$geo_lat,
            "geo_lng"=>$geo_lng
        ]);
    } else {
        echo json_encode(["status"=>"error", "msg"=>"❌ Invalid Secret Code!"]);
    }
    exit();
}

// === UPDATE DONOR INFO ===
if(isset($_POST['ajax_update'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('ajax_update', 10, 60);
    $secret_code   = trim($_POST['secret_code']);
    $name          = trim($_POST['name']);
    $location      = trim($_POST['location']);
    $last_input    = trim($_POST['last_donation']);
    $new_secret_raw = trim($_POST['new_secret_code'] ?? '');

    validateLength($secret_code, 25, 'Secret Code');
    validateLength($name, 100, 'নাম');
    validateLength($location, 300, 'Location');

    if(empty($name) || !preg_match('/^[\p{Bengali}a-zA-Z\s]+$/u', $name)){
        echo json_encode(["status"=>"error", "msg"=>"❌ নামে শুধুমাত্র অক্ষর ও স্পেস থাকতে পারবে।"]);
        exit();
    }

    // === NEW SECRET CODE VALIDATION ===
    $change_secret = false;
    $new_secret_code = '';
    if($new_secret_raw !== '') {
        // Must be 6–20 alphanumeric chars (no spaces, no special chars)
        if(!preg_match('/^[A-Za-z0-9]{6,20}$/', $new_secret_raw)) {
            echo json_encode(["status"=>"error", "msg"=>"❌ নতুন Secret Code শুধুমাত্র ইংরেজি অক্ষর ও সংখ্যা দিয়ে হতে হবে। দৈর্ঘ্য ৬ থেকে ২০ অক্ষর।"]);
            exit();
        }
        // Prefix with SHSMC- to keep consistent format
        $new_secret_code = 'SHSMC-' . strtoupper($new_secret_raw);
        validateLength($new_secret_code, 27, 'New Secret Code');

        // Make sure the new code is not already taken by ANOTHER donor
        $chk = $conn->prepare("SELECT id FROM donors WHERE secret_code = ? AND secret_code != ?");
        $chk->bind_param("ss", $new_secret_code, $secret_code);
        $chk->execute();
        if($chk->get_result()->num_rows > 0) {
            $chk->close();
            echo json_encode(["status"=>"error", "msg"=>"❌ এই Secret Code টি ইতিমধ্যে ব্যবহৃত হচ্ছে। অন্য একটি বেছে নিন।"]);
            exit();
        }
        $chk->close();
        $change_secret = true;
    }

    $today = date("Y-m-d");
    $last_to_save = "no";
    $reg_geo_update = trim($_POST['reg_geo_update'] ?? '');
    validateLength($reg_geo_update, 200, 'Geo location');

    if(strtolower($last_input) != 'no' && !empty($last_input)){
        $d = DateTime::createFromFormat('d/m/Y', $last_input);
        if(!$d || $d->format('d/m/Y') !== $last_input){
            echo json_encode(["status"=>"error", "msg"=>"Error: Last Blood Donation Date must be 'no' or in dd/mm/yyyy format."]);
            exit();
        }
        $formatted = $d->format('Y-m-d');
        if($formatted > $today || (int)$d->format('Y') < 1940){
            echo json_encode(["status"=>"error", "msg"=>"Error: Invalid date."]);
            exit();
        }
        $last_to_save = $formatted;
    }

    $willing      = trim($_POST['willing_to_donate'] ?? 'yes');
    $just_donated = (int)($_POST['just_donated'] ?? 0);
    if(!in_array($willing, ['yes','no'], true)) $willing = 'yes';

    // Server-side guard: just_donated=1 is only valid if last_donation is today
    // Prevents double-counting if user clicks "I just donated" multiple times
    if($just_donated === 1){
        if($last_to_save !== date('Y-m-d')){
            $just_donated = 0; // Silently ignore — date mismatch means it's not a fresh donation
        } else {
            // Also check DB: if their current last_donation is already today, don't count again
            $chk_today = $conn->prepare("SELECT last_donation FROM donors WHERE secret_code=?");
            $chk_today->bind_param("s", $secret_code);
            $chk_today->execute();
            $chk_row = $chk_today->get_result()->fetch_assoc();
            $chk_today->close();
            if($chk_row && $chk_row['last_donation'] === date('Y-m-d')){
                $just_donated = 0; // Already counted today
            }
        }
    }

    // Build query — optionally include secret_code change and reg_geo update
    $geo_set  = !empty($reg_geo_update) ? ", reg_geo=?" : "";
    $badge_expr_inc = "CASE WHEN total_donations+1>=10 THEN 'Legend' WHEN total_donations+1>=5 THEN 'Hero' WHEN total_donations+1>=2 THEN 'Active' ELSE 'New' END";
    $badge_expr_cur = "CASE WHEN total_donations>=10 THEN 'Legend' WHEN total_donations>=5 THEN 'Hero' WHEN total_donations>=2 THEN 'Active' ELSE 'New' END";

    if($change_secret) {
        if($just_donated === 1){
            $stmt = $conn->prepare("UPDATE donors SET name=?, location=?, last_donation=?, willing_to_donate=?, secret_code=?, total_donations=total_donations+1, badge_level=$badge_expr_inc$geo_set WHERE secret_code=?");
            if(!empty($reg_geo_update)) { $stmt->bind_param("sssssss", $name, $location, $last_to_save, $willing, $new_secret_code, $reg_geo_update, $secret_code); }
            else { $stmt->bind_param("ssssss", $name, $location, $last_to_save, $willing, $new_secret_code, $secret_code); }
        } else {
            $stmt = $conn->prepare("UPDATE donors SET name=?, location=?, last_donation=?, willing_to_donate=?, secret_code=?, badge_level=$badge_expr_cur$geo_set WHERE secret_code=?");
            if(!empty($reg_geo_update)) { $stmt->bind_param("sssssss", $name, $location, $last_to_save, $willing, $new_secret_code, $reg_geo_update, $secret_code); }
            else { $stmt->bind_param("ssssss", $name, $location, $last_to_save, $willing, $new_secret_code, $secret_code); }
        }
    } else {
        if($just_donated === 1){
            $stmt = $conn->prepare("UPDATE donors SET name=?, location=?, last_donation=?, willing_to_donate=?, total_donations=total_donations+1, badge_level=$badge_expr_inc$geo_set WHERE secret_code=?");
            if(!empty($reg_geo_update)) { $stmt->bind_param("ssssss", $name, $location, $last_to_save, $willing, $reg_geo_update, $secret_code); }
            else { $stmt->bind_param("sssss", $name, $location, $last_to_save, $willing, $secret_code); }
        } else {
            $stmt = $conn->prepare("UPDATE donors SET name=?, location=?, last_donation=?, willing_to_donate=?, badge_level=$badge_expr_cur$geo_set WHERE secret_code=?");
            if(!empty($reg_geo_update)) { $stmt->bind_param("ssssss", $name, $location, $last_to_save, $willing, $reg_geo_update, $secret_code); }
            else { $stmt->bind_param("sssss", $name, $location, $last_to_save, $willing, $secret_code); }
        }
    }

    if($stmt->execute()){
        // Use the effective secret code (new if changed, else old) to fetch updated data
        $effective_code = $change_secret ? $new_secret_code : $secret_code;
        $s2 = $conn->prepare("SELECT total_donations FROM donors WHERE secret_code=?");
        $s2->bind_param("s", $effective_code);
        $s2->execute();
        $r2 = $s2->get_result()->fetch_assoc();
        if($r2){
            $badge = getBadgeInfo((int)$r2['total_donations']);
            $s2->close();
            // Increment persistent donation counter if donor just donated
            if($just_donated === 1){
                $conn->query("INSERT INTO analytics_counters (counter_name, counter_value) VALUES ('total_donations_ever', 1)
                    ON DUPLICATE KEY UPDATE counter_value = counter_value + 1");
            }
            $response = [
                "status"          => "success",
                "msg"             => "✅ তথ্য সফলভাবে আপডেট হয়েছে!",
                "badge_level"     => $badge['level'],
                "badge_icon"      => $badge['icon'],
                "total_donations" => (int)$r2['total_donations'],
                "secret_changed"  => $change_secret,
                "new_secret_code" => $change_secret ? $new_secret_code : null
            ];
            echo json_encode($response);
        } else {
            $s2->close();
            echo json_encode(["status"=>"error", "msg"=>"❌ Secret Code দিয়ে donor খুঁজে পাওয়া যায়নি।"]);
        }
    } else {
        echo json_encode(["status"=>"error", "msg"=>"❌ Update failed. Please try again."]);
    }
    $stmt->close();
    exit();
}

// === DELETE DONOR (Self-delete by Secret Code) ===
if(isset($_POST['delete_donor'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('delete_donor', 5, 300); // Max 5 attempts per 5 min

    $secret_code = trim($_POST['secret_code'] ?? '');
    $confirm     = trim($_POST['confirm']      ?? '');

    if(empty($secret_code)){
        echo json_encode(["status"=>"error","msg"=>"Secret Code দিন।"]);
        exit();
    }
    validateLength($secret_code, 25, 'Secret Code');
    if($confirm !== 'DELETE'){
        echo json_encode(["status"=>"error","msg"=>"❌ নিশ্চিত করতে DELETE লিখুন।"]);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, name FROM donors WHERE secret_code=?");
    $stmt->bind_param("s", $secret_code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$row){
        echo json_encode(["status"=>"error","msg"=>"❌ Secret Code মিলছে না। সঠিক Code দিন।"]);
        exit();
    }

    $del = $conn->prepare("DELETE FROM donors WHERE secret_code=?");
    $del->bind_param("s", $secret_code);
    if($del->execute()){
        echo json_encode(["status"=>"success","msg"=>"✅ আপনার সকল তথ্য database থেকে সম্পূর্ণ মুছে ফেলা হয়েছে।"]);
    } else {
        echo json_encode(["status"=>"error","msg"=>"❌ মুছতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।"]);
    }
    $del->close();
    exit();
}

// === AJAX Registration ===
if(isset($_POST['ajax_submit'])){
    checkCSRF();
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    checkRateLimit('register', 5, 300);

    $name       = trim($_POST['name']             ?? '');
    $phone      = trim($_POST['phone']            ?? '');
    $location   = trim($_POST['location']         ?? '');
    $group      = trim($_POST['group']            ?? '');
    $last_input = trim($_POST['last_donation']    ?? '');
    $reg_geo    = trim($_POST['reg_geo_location'] ?? 'Not captured');
    $reg_ip     = $_SERVER['REMOTE_ADDR'];
    $reg_device = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 300, 'UTF-8');

    validateLength($name,     100, 'নাম');
    validateLength($location, 300, 'Location');
    validateLength($reg_geo,  200, 'Geo location');

    if(empty($name) || !preg_match('/^[\p{Bengali}a-zA-Z\s]+$/u', $name)){
        echo json_encode(["status"=>"error","msg"=>"নামে শুধুমাত্র অক্ষর ও স্পেস থাকতে পারবে।"]);
        exit();
    }
    $valid_groups = ["A+","A-","B+","B-","AB+","AB-","O+","O-"];
    if(!in_array($group, $valid_groups, true)){
        echo json_encode(["status"=>"error","msg"=>"Invalid blood group."]);
        exit();
    }
    if(!preg_match('/^\+8801\d{9}$/', $phone)){
        echo json_encode(["status"=>"error","msg"=>"Phone must start with +8801 followed by 9 digits."]);
        exit();
    }

    $today        = date("Y-m-d");
    $last_to_save = "no";
    if(strtolower($last_input) !== 'no' && !empty($last_input)){
        $d = DateTime::createFromFormat('d/m/Y', $last_input);
        if(!$d || $d->format('d/m/Y') !== $last_input){
            echo json_encode(["status"=>"error","msg"=>"Last donation date must be 'no' or dd/mm/yyyy."]);
            exit();
        }
        $formatted_last = $d->format('Y-m-d');
        if($formatted_last > $today || (int)$d->format('Y') < 1940){
            echo json_encode(["status"=>"error","msg"=>"Invalid date."]);
            exit();
        }
        $last_to_save = $formatted_last;
    }

    $chk = $conn->prepare("SELECT id FROM donors WHERE phone=?");
    $chk->bind_param("s", $phone);
    $chk->execute();
    if($chk->get_result()->num_rows > 0){
        echo json_encode(["status"=>"error","msg"=>"এই নম্বরটি দিয়ে ইতোমধ্যে রেজিস্ট্রেশন করা হয়েছে।"]);
        $chk->close(); exit();
    }
    $chk->close();

    $reg_total_donations = max(0, (int)($_POST['total_donations_reg'] ?? 0));
    if($last_to_save === 'no') $reg_total_donations = 0;
    if($reg_total_donations > 999) $reg_total_donations = 999;
    $reg_badge   = getBadgeInfo($reg_total_donations)['level'];
    $secret_code = generateSecretCode($conn);

    $stmt = $conn->prepare("INSERT INTO donors (name,phone,location,blood_group,last_donation,reg_ip,reg_device,reg_geo,secret_code,total_donations,badge_level) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("sssssssssis", $name,$phone,$location,$group,$last_to_save,$reg_ip,$reg_device,$reg_geo,$secret_code,$reg_total_donations,$reg_badge);
    if($stmt->execute()){
        if($reg_total_donations > 0){
            $conn->query("INSERT INTO analytics_counters (counter_name,counter_value) VALUES ('total_donations_ever',$reg_total_donations)
                ON DUPLICATE KEY UPDATE counter_value=counter_value+$reg_total_donations");
        }
        echo json_encode(["status"=>"success", "secret_code"=>$secret_code]);
    } else {
        echo json_encode(["status"=>"error","msg"=>"Registration failed. Please try again."]);
    }
    $stmt->close();
    exit();
}

function getLiveStatus($last_donation, $willing = 'yes') {
    if($willing === 'no') return "Unavailable";
    if($last_donation == 'no' || empty($last_donation) || $last_donation == '0000-00-00') {
        return "Available";
    }
    $today = new DateTime();
    $last  = new DateTime($last_donation);
    $diff  = $today->diff($last)->days;
    return ($diff >= 120) ? "Available" : "Not Available";
}

function getBadgeInfo($total) {
    if($total >= 10) return ['level'=>'Legend','icon'=>'👑','color'=>'#f59e0b','bg'=>'rgba(245,158,11,0.15)','border'=>'rgba(245,158,11,0.4)'];
    if($total >= 5)  return ['level'=>'Hero',  'icon'=>'🦸','color'=>'#8b5cf6','bg'=>'rgba(139,92,246,0.15)','border'=>'rgba(139,92,246,0.4)'];
    if($total >= 2)  return ['level'=>'Active', 'icon'=>'⭐','color'=>'#3b82f6','bg'=>'rgba(59,130,246,0.15)','border'=>'rgba(59,130,246,0.4)'];
    return ['level'=>'New','icon'=>'🌱','color'=>'#10b981','bg'=>'rgba(16,185,129,0.15)','border'=>'rgba(16,185,129,0.4)'];
}

// === FULL AJAX FILTER WITH PAGINATION & NEW LOCATION FILTER ===
if(isset($_POST['ajax_filter'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start(); // flush any buffered warnings before JSON output
    checkRateLimit('ajax_filter', 60, 60);
    $f_group = trim($_POST['filter_group'] ?? 'All');
    $f_search = trim($_POST['search_query'] ?? '');
    $f_status = $_POST['filter_status'] ?? 'All';
    $f_location = trim($_POST['filter_location'] ?? 'All'); 
    $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;

    // Whitelist blood group
    $valid_groups = ["A+","A-","B+","B-","AB+","AB-","O+","O-","All"];
    if(!in_array($f_group, $valid_groups, true)) $f_group = "All";
    $valid_status = ["All","Available","Unavailable"];
    if(!in_array($f_status, $valid_status, true)) $f_status = "All";
    $f_badge = trim($_POST['filter_badge'] ?? 'All');
    $valid_badges = ["All","New","Active","Hero","Legend"];
    if(!in_array($f_badge, $valid_badges, true)) $f_badge = "All";

    // Length limits on filter inputs
    $f_search = mb_substr($f_search, 0, 100, 'UTF-8');
    $f_location = mb_substr($f_location, 0, 200, 'UTF-8');
    $limit = 20;
    $start = ($page - 1) * $limit;

    $query_parts = [];
    $params =[];
    $types = "";

    if($f_group != "All" && $f_group != "") { 
        $query_parts[] = "blood_group = ?";
        $params[] = $f_group;
        $types .= "s";
    }
    if($f_location != "All" && $f_location != "") { 
        $query_parts[] = "location LIKE ?";
        $params[] = $f_location . "%"; 
        $types .= "s";
    }
    if($f_search != "") { 
        $query_parts[] = "(name LIKE ? OR location LIKE ?)";
        $like = "%$f_search%";
        $params[] = $like;
        $params[] = $like;
        $types .= "ss";
    }
    if($f_status == "Available") {
        $query_parts[] = "(willing_to_donate='yes' AND (last_donation = 'no' OR last_donation = '' OR last_donation = '0000-00-00' OR DATEDIFF(CURDATE(), last_donation) >= 120))";
    } elseif($f_status == "Unavailable") {
        $query_parts[] = "(willing_to_donate='no' OR (willing_to_donate='yes' AND last_donation != 'no' AND last_donation != '' AND last_donation != '0000-00-00' AND DATEDIFF(CURDATE(), last_donation) < 120))";
    }
    if($f_badge != "All" && $f_badge != "") {
        $query_parts[] = "badge_level = ?";
        $params[] = $f_badge;
        $types .= "s";
    }
    
    $where = count($query_parts) > 0 ? "WHERE " . implode(" AND ", $query_parts) : "";
    
    $count_q = "SELECT COUNT(*) as total FROM donors $where";
    $stmt_count = $conn->prepare($count_q);
    if($types !== "") $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();

    $data_q = "SELECT * FROM donors $where ORDER BY id DESC LIMIT ?, ?";
    $stmt = $conn->prepare($data_q);
    $types_limit = $types . "ii";
    $params_limit = array_merge($params, [$start, $limit]);
    if($types !== "") {
        $stmt->bind_param($types_limit, ...$params_limit);
    } else {
        $stmt->bind_param("ii", $start, $limit);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    
    $output = "";   // desktop table rows
    $cards  = "";   // mobile cards
    $serial = $start + 1;
    $found = false;
    $counts =["A+"=>0,"A-"=>0,"B+"=>0,"B-"=>0,"AB+"=>0,"AB-"=>0,"O+"=>0,"O-"=>0];
    
    while($row = $res->fetch_assoc()){
        $last_val = $row['last_donation'];
        $willing_val = $row['willing_to_donate'] ?? 'yes';
        $current_status = getLiveStatus($last_val, $willing_val);
        // Use DB badge_level directly — avoids mismatch with total_donations
        $db_badge_level = $row['badge_level'] ?? '';
        if($db_badge_level === 'Legend') $donor_badge = getBadgeInfo(10);
        elseif($db_badge_level === 'Hero') $donor_badge = getBadgeInfo(5);
        elseif($db_badge_level === 'Active') $donor_badge = getBadgeInfo(2);
        else $donor_badge = getBadgeInfo(0); // New
        
        if($current_status == "Available") { 
            if (isset($counts[$row['blood_group']])) $counts[$row['blood_group']]++; 
        }
        
        $found = true;
        
        if($last_val == 'no' || empty($last_val) || $last_val == '0000-00-00'){
            $display_last = 'Never donated';
        } else {
            $display_last = date("d M Y", strtotime($last_val));
            if(strpos($display_last, '1970') !== false || strpos($display_last, '-0001') !== false) { $display_last = 'Never donated'; }
        }
        
        if($current_status == 'Available')      { $st_class='available';   $st_icon='✔'; $st_text='Available'; }
        elseif($current_status == 'Unavailable') { $st_class='unavailable';  $st_icon='⛔'; $st_text='Unavailable'; }
        else                                      { $st_class='notavailable'; $st_icon='✖'; $st_text='Not Available'; }
        $bg_class   = 'bg' . preg_replace('/[^a-zA-Z]/', '', $row['blood_group']) . (strpos($row['blood_group'],'+') !== false ? 'pos' : 'neg');
        $sn         = $serial++;

        // ── Call button state based on availability ──────────────────
        $is_available = ($current_status == 'Available');
        $call_btn_desktop = $is_available
            ? "<button class='phone-link' onclick=\"prepCall('".$row['id']."')\">📞 Call</button>"
            : "<button class='phone-link-disabled' disabled title='দাতা এখন Available নেই'>🚫 Unavailable</button>";
        $call_btn_mobile = $is_available
            ? "<button class='dc-call-btn unselectable' onclick=\"prepCall('".$row['id']."')\" oncontextmenu='return false;' aria-label='Call donor'>📞</button>"
            : "<button class='dc-call-btn-disabled' disabled title='দাতা এখন Available নেই' aria-label='Not available'>🚫</button>";

        // ── Desktop table row ──────────────────────────────────────────
        $output .= "<tr>
            <td><span class='serial-num'>$sn</span></td>
            <td style='text-align:left; font-weight:600;'>".esc($row['name'])." <span style='font-size:0.85em;opacity:0.85;' title='".$donor_badge['level']." Donor'>".$donor_badge['icon']."</span></td>
            <td><span class='blood-badge $bg_class'>".esc($row['blood_group'])."</span></td>
            <td><span class='$st_class'>$st_icon $st_text</span></td>
            <td style='text-align:left; color:var(--text-muted); font-size:0.88em;'>📍 ".esc($row['location'])."</td>
            <td style='color:var(--text-muted); font-size:0.88em;'>🗓 ".esc($display_last)."</td>
            <td class='unselectable' oncontextmenu='return false;' oncopy='return false;'>
                $call_btn_desktop
            </td>
        </tr>";

        // ── Mobile card ────────────────────────────────────────────────
        $cards .= "
        <div class='dc'>
            <div class='dc-badge-wrap'>
                <span class='dc-sn'>$sn</span>
                <span class='dc-badge $bg_class'>".esc($row['blood_group'])."</span>
            </div>
            <div class='dc-info'>
                <div class='dc-name'>".esc($row['name'])." <span style='font-size:0.85em;opacity:0.85;' title='".$donor_badge['level']." Donor'>".$donor_badge['icon']."</span></div>
                <span class='$st_class dc-status-badge'>$st_icon $st_text</span>
                <div class='dc-loc'>📍 ".esc($row['location'])."</div>
                <div class='dc-last'>🗓 $display_last</div>
            </div>
            $call_btn_mobile
        </div>";
    }
    
    if(!$found) { 
        $output = "<tr><td colspan='7' class='no-data'>✖ কোনো রক্তদাতা পাওয়া যায়নি।</td></tr>";
        $cards  = "<div class='no-data' style='text-align:center;padding:30px;'>✖ কোনো রক্তদাতা পাওয়া যায়নি।</div>";
    }
    
    $total_pages = ceil($total_records / $limit);
    $pag_html = '<div class="pagination">';
    if($page > 1) $pag_html .= '<a href="#" onclick="fetchFilteredData('.($page-1).',true); return false;">Previous</a>';
    for($i = 1; $i <= $total_pages; $i++){
        $active = ($i == $page) ? ' class="active-page"' : '';
        $pag_html .= '<a href="#" onclick="fetchFilteredData('.$i.',true); return false;"'.$active.'>'.$i.'</a>';
    }
    if($page < $total_pages) $pag_html .= '<a href="#" onclick="fetchFilteredData('.($page+1).',true); return false;">Next</a>';
    $pag_html .= '</div>';
    
    // Fresh available counts — always global (not filtered) for stat cards
    // FIX: $avail_counts stays 0 on AJAX calls. Run fresh query here instead.
    $fresh_counts = ["A+"=>0,"A-"=>0,"B+"=>0,"B-"=>0,"AB+"=>0,"AB-"=>0,"O+"=>0,"O-"=>0];
    mysqli_report(MYSQLI_REPORT_OFF);
    $fc_q = $conn->query("SELECT blood_group, COUNT(*) as cnt FROM donors
        WHERE (willing_to_donate IS NULL OR willing_to_donate='yes' OR willing_to_donate='')
          AND (last_donation='no' OR last_donation='' OR last_donation='0000-00-00'
               OR DATEDIFF(CURDATE(), last_donation) >= 120)
        GROUP BY blood_group");
    if ($fc_q) while ($fcr = $fc_q->fetch_assoc())
        if (isset($fresh_counts[$fcr['blood_group']]))
            $fresh_counts[$fcr['blood_group']] = (int)$fcr['cnt'];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $fresh_total_avail = array_sum($fresh_counts);
    echo json_encode(["table" => $output, "cards" => $cards, "counts" => $fresh_counts, "total_available" => $fresh_total_avail, "pagination" => $pag_html, "total" => $total_records]);
    $stmt->close();
    exit();
}

// === AJAX: Analytics Data ===
if(isset($_POST['get_analytics'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start(); // flush any buffered warnings before JSON output
    checkRateLimit('analytics', 10, 60);

    // Temporarily disable strict error reporting so missing columns don't crash analytics
    mysqli_report(MYSQLI_REPORT_OFF);

    $total       = (int)($conn->query("SELECT COUNT(*) as c FROM donors")->fetch_assoc()['c'] ?? 0);

    $r_avail = $conn->query("SELECT COUNT(*) as c FROM donors WHERE willing_to_donate='yes' AND (last_donation='no' OR last_donation='' OR last_donation='0000-00-00' OR DATEDIFF(CURDATE(),last_donation)>=120)");
    $available   = $r_avail ? (int)$r_avail->fetch_assoc()['c'] : 0;

    $r_unav = $conn->query("SELECT COUNT(*) as c FROM donors WHERE willing_to_donate='no'");
    $unavailable = $r_unav ? (int)$r_unav->fetch_assoc()['c'] : 0;

    $r_calls = $conn->query("SELECT counter_value as c FROM analytics_counters WHERE counter_name='total_calls_ever'");
    $r_calls_row = $r_calls ? $r_calls->fetch_assoc() : null;
    if($r_calls_row !== null){
        $total_calls = (int)$r_calls_row['c'];
    } else {
        // Fallback: counter table missing/not seeded yet
        $r_calls_fb = $conn->query("SELECT COUNT(*) as c FROM call_logs");
        $total_calls = $r_calls_fb ? (int)($r_calls_fb->fetch_assoc()['c'] ?? 0) : 0;
    }

    // Blood requests stats
    $r_active_req = $conn->query("SELECT COUNT(*) as c FROM blood_requests WHERE status='Active'");
    $active_requests = $r_active_req ? (int)$r_active_req->fetch_assoc()['c'] : 0;

    // "Successfully Donated" — persistent counter, never decreases even if donor deletes account
    $r_donated = $conn->query("SELECT counter_value as c FROM analytics_counters WHERE counter_name='total_donations_ever'");
    $r_donated_row = $r_donated ? $r_donated->fetch_assoc() : null;
    if($r_donated_row !== null){
        $fulfilled_requests = (int)$r_donated_row['c'];
    } else {
        // Fallback: counter table missing/not seeded yet
        $r_donated_fb = $conn->query("SELECT COALESCE(SUM(total_donations),0) as c FROM donors");
        $fulfilled_requests = $r_donated_fb ? (int)($r_donated_fb->fetch_assoc()['c'] ?? 0) : 0;
    }

    // Blood group breakdown
    $by_group = [];
    $bg_res = $conn->query("SELECT blood_group, COUNT(*) as cnt FROM donors GROUP BY blood_group ORDER BY cnt DESC");
    if($bg_res) while($r = $bg_res->fetch_assoc()) $by_group[$r['blood_group']] = (int)$r['cnt'];

    // Badge breakdown
    $by_badge = ['New'=>0,'Active'=>0,'Hero'=>0,'Legend'=>0];
    $badge_res = $conn->query("SELECT badge_level, COUNT(*) as cnt FROM donors GROUP BY badge_level");
    if($badge_res) {
        while($r = $badge_res->fetch_assoc()) {
            if(isset($by_badge[$r['badge_level']])) $by_badge[$r['badge_level']] = (int)$r['cnt'];
        }
    }
    // Fallback: if badge_level column missing, bucket by total_donations
    if(array_sum($by_badge) === 0) {
        $td_res = $conn->query("SELECT total_donations FROM donors");
        if($td_res) {
            while($r = $td_res->fetch_assoc()){
                $t = (int)($r['total_donations'] ?? 0);
                if($t >= 10)    $by_badge['Legend']++;
                elseif($t >= 5) $by_badge['Hero']++;
                elseif($t >= 2) $by_badge['Active']++;
                else            $by_badge['New']++;
            }
        } else {
            $by_badge['New'] = $total;
        }
    }

    // Monthly registrations — created_at may not exist on some installs
    $monthly = [];
    $monthly_res = $conn->query("SELECT DATE_FORMAT(created_at,'%b %Y') as month, COUNT(*) as cnt FROM donors WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY DATE_FORMAT(created_at,'%Y-%m') ASC");
    if($monthly_res) while($r = $monthly_res->fetch_assoc()) $monthly[] = $r;

    // Top locations
    $by_loc = [];
    $loc_res = $conn->query("SELECT SUBSTRING_INDEX(location,' - ',1) as area, COUNT(*) as cnt FROM donors GROUP BY area ORDER BY cnt DESC LIMIT 6");
    if($loc_res) while($r = $loc_res->fetch_assoc()) $by_loc[] = $r;

    // Available count by blood group (for stat cards live update)
    $by_group_avail = ["A+"=>0,"A-"=>0,"B+"=>0,"B-"=>0,"AB+"=>0,"AB-"=>0,"O+"=>0,"O-"=>0];
    $bga_res = $conn->query("SELECT blood_group, COUNT(*) as cnt FROM donors 
        WHERE (willing_to_donate IS NULL OR willing_to_donate='yes')
          AND (last_donation='no' OR last_donation='' OR last_donation='0000-00-00' OR DATEDIFF(CURDATE(),last_donation)>=120)
        GROUP BY blood_group");
    if($bga_res) while($r=$bga_res->fetch_assoc()) { if(isset($by_group_avail[$r['blood_group']])) $by_group_avail[$r['blood_group']]=(int)$r['cnt']; }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    echo json_encode(compact('total','available','unavailable','total_calls','active_requests','fulfilled_requests','by_group','by_group_avail','by_badge','monthly','by_loc'));
    exit();
}

// === AJAX: Map Data (donor locations with geo coords from reg_geo) ===
if(isset($_POST['get_map_data'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('map_data', 10, 60);
    $stmt = $conn->prepare("SELECT name, blood_group, location, reg_geo, last_donation, willing_to_donate, total_donations FROM donors WHERE reg_geo != 'Not captured' AND reg_geo != 'Not provided' AND reg_geo LIKE 'Lat:%' LIMIT 200");
    $stmt->execute();
    $res = $stmt->get_result();
    $markers = [];
    while($row = $res->fetch_assoc()){
        preg_match('/Lat:\s*([\-0-9.]+),\s*Lon:\s*([\-0-9.]+)/', $row['reg_geo'], $m);
        if(count($m) === 3){
            $status = getLiveStatus($row['last_donation'], $row['willing_to_donate'] ?? 'yes');
            $badge  = getBadgeInfo((int)($row['total_donations'] ?? 0));
            $markers[] = [
                'lat'   => (float)$m[1],
                'lng'   => (float)$m[2],
                'name'  => esc($row['name']),
                'group' => esc($row['blood_group']),
                'loc'   => esc($row['location']),
                'status'=> $status,
                'badge' => $badge['icon'].' '.$badge['level']
            ];
        }
    }
    $stmt->close();
    echo json_encode($markers);
    exit();
}

// ============================================================
// FEATURE: EMERGENCY BLOOD REQUESTS
// ============================================================

// Submit blood request
if(isset($_POST['submit_blood_request'])){
    checkCSRF();
    checkRateLimit('blood_request', 3, 300);

    $patient   = trim($_POST['patient_name']   ?? '');
    $blood_grp = trim($_POST['req_blood_group'] ?? '');
    $hospital  = trim($_POST['hospital']        ?? '');
    $contact   = trim($_POST['req_contact']     ?? '');
    $urgency   = trim($_POST['urgency']         ?? 'High');
    $bags      = max(1, min(10, (int)($_POST['bags_needed'] ?? 1)));
    $note      = trim($_POST['req_note']        ?? '');

    // Build response array — output NOTHING until the very end
    $resp = [];

    $valid_groups = ["A+","A-","B+","B-","AB+","AB-","O+","O-"];
    if(!in_array($blood_grp, $valid_groups, true)){
        $resp = ["status"=>"error","msg"=>"Invalid blood group."];
    } elseif(!preg_match('/^\+8801\d{9}$/', $contact)){
        $resp = ["status"=>"error","msg"=>"সঠিক যোগাযোগ নম্বর দিন।"];
    } elseif(empty($patient)||empty($hospital)){
        $resp = ["status"=>"error","msg"=>"রোগীর নাম ও হাসপাতাল দিন।"];
    } else {
        $valid_urgency=['Critical','High','Medium'];
        if(!in_array($urgency,$valid_urgency,true)) $urgency='High';

        mysqli_report(MYSQLI_REPORT_OFF);
        $conn->query("CREATE TABLE IF NOT EXISTS `blood_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `patient_name` VARCHAR(100) NOT NULL,
            `blood_group` VARCHAR(5) NOT NULL,
            `hospital` VARCHAR(200) NOT NULL,
            `contact` VARCHAR(20) NOT NULL,
            `urgency` VARCHAR(10) DEFAULT 'High',
            `bags_needed` INT DEFAULT 1,
            `note` VARCHAR(500) DEFAULT '',
            `status` VARCHAR(20) DEFAULT 'Active',
            `delete_token` VARCHAR(10) DEFAULT NULL,
            `req_ip` VARCHAR(50) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->query("UPDATE blood_requests SET status='Expired' WHERE status='Active' AND created_at < DATE_SUB(NOW(), INTERVAL 72 HOUR)");
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);

        $delete_token = str_pad((string)mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $ip = $_SERVER['REMOTE_ADDR'];

        try {
            $stmt = $conn->prepare("INSERT INTO blood_requests (patient_name,blood_group,hospital,contact,urgency,bags_needed,note,req_ip,delete_token) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("sssssisss",$patient,$blood_grp,$hospital,$contact,$urgency,$bags,$note,$ip,$delete_token);
            if($stmt->execute()){
                $new_id = $conn->insert_id;
                $resp = [
                    "status"       => "success",
                    "msg"          => "✅ রক্তের অনুরোধ পাঠানো হয়েছে!",
                    "request_id"   => (int)$new_id,
                    "delete_token" => (string)$delete_token
                ];
            } else {
                $resp = ["status"=>"error","msg"=>"ব্যর্থ হয়েছে। আবার চেষ্টা করুন।"];
            }
            $stmt->close();
        } catch(Exception $ex) {
            $resp = ["status"=>"error","msg"=>"DB error। আবার চেষ্টা করুন।"];
        }
    }

    // Clear ALL output buffers then send clean JSON
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp);
    exit();
}

// === DELETE BLOOD REQUEST (OTP token verify) ===
if(isset($_POST['delete_blood_request'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('delete_request', 10, 60);

    $req_id     = (int)($_POST['req_id'] ?? 0);
    $del_token  = trim($_POST['delete_token'] ?? '');
    $contact_in = trim($_POST['contact'] ?? '');

    if($req_id <= 0 || empty($del_token) || empty($contact_in)){
        echo json_encode(["status"=>"error","msg"=>"তথ্য অসম্পূর্ণ।"]);
        exit();
    }
    if(!preg_match('/^\d{6}$/', $del_token)){
        echo json_encode(["status"=>"error","msg"=>"❌ Token ভুল। ৬টি সংখ্যা দিন।"]);
        exit();
    }
    if(!preg_match('/^\+8801\d{9}$/', $contact_in)){
        echo json_encode(["status"=>"error","msg"=>"❌ সঠিক ফোন নম্বর দিন।"]);
        exit();
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $stmt = $conn->prepare("SELECT id, contact, delete_token, status FROM blood_requests WHERE id=? AND status='Active'");
        $stmt->bind_param("i", $req_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$row){
            echo json_encode(["status"=>"error","msg"=>"❌ Request পাওয়া যায়নি অথবা ইতোমধ্যে মুছে ফেলা হয়েছে।"]);
            exit();
        }
        if($row['contact'] !== $contact_in || $row['delete_token'] !== $del_token){
            echo json_encode(["status"=>"error","msg"=>"❌ ফোন নম্বর বা Delete Token মিলছে না।"]);
            exit();
        }

        $upd = $conn->prepare("UPDATE blood_requests SET status='Deleted' WHERE id=?");
        $upd->bind_param("i", $req_id);
        if($upd->execute()){
            echo json_encode(["status"=>"success","msg"=>"✅ আপনার Request সফলভাবে মুছে ফেলা হয়েছে।"]);
        } else {
            echo json_encode(["status"=>"error","msg"=>"❌ মুছতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।"]);
        }
        $upd->close();
    } catch(Exception $ex) {
        echo json_encode(["status"=>"error","msg"=>"DB error। আবার চেষ্টা করুন।"]);
    }
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    exit();
}

// Get active blood requests
if(isset($_POST['get_blood_requests'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('get_requests',30,60);
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("CREATE TABLE IF NOT EXISTS `blood_requests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `patient_name` VARCHAR(100) NOT NULL,
        `blood_group` VARCHAR(5) NOT NULL,
        `hospital` VARCHAR(200) NOT NULL,
        `contact` VARCHAR(20) NOT NULL,
        `urgency` VARCHAR(10) DEFAULT 'High',
        `bags_needed` INT DEFAULT 1,
        `note` VARCHAR(500) DEFAULT '',
        `status` VARCHAR(20) DEFAULT 'Active',
        `delete_token` VARCHAR(10) DEFAULT NULL,
        `req_ip` VARCHAR(50) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Auto-expire: any Active request older than 72 hours → Expired
    $conn->query("UPDATE blood_requests SET status='Expired' WHERE status='Active' AND created_at < DATE_SUB(NOW(), INTERVAL 72 HOUR)");
    // UNIX_TIMESTAMP = seconds since epoch, completely timezone-independent
    // DATE_FORMAT with 'Z' suffix failed on InfinityFree (MySQL timezone != UTC)
    $res = $conn->query("SELECT id,patient_name,blood_group,hospital,contact,urgency,bags_needed,note,UNIX_TIMESTAMP(created_at) as created_at FROM blood_requests WHERE status='Active' ORDER BY FIELD(urgency,'Critical','High','Medium'), created_at DESC LIMIT 20");
    $requests=[];
    if($res) while($r=$res->fetch_assoc()) $requests[]=$r;
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    echo json_encode($requests);
    exit();
}

// ============================================================
// FEATURE: NEARBY DONORS (Haversine distance filter)
// ============================================================
if(isset($_POST['get_nearby_donors'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('nearby',20,60);
    $user_lat = (float)($_POST['lat'] ?? 0);
    $user_lng = (float)($_POST['lng'] ?? 0);
    $radius_km = min(50, max(1, (float)($_POST['radius'] ?? 5)));
    $f_group  = trim($_POST['filter_group'] ?? 'All');
    $f_status = trim($_POST['filter_status'] ?? 'All');
    $valid_groups=["A+","A-","B+","B-","AB+","AB-","O+","O-","All"];
    $valid_statuses=["All","Available","Not Available","Unavailable"];
    if(!in_array($f_group,$valid_groups,true)) $f_group='All';
    if(!in_array($f_status,$valid_statuses,true)) $f_status='All';

    if($user_lat==0&&$user_lng==0){echo json_encode(["status"=>"error","msg"=>"Location পাওয়া যায়নি।"]);exit();}

    $stmt = $conn->prepare("SELECT id,name,blood_group,location,last_donation,willing_to_donate,total_donations,reg_geo FROM donors WHERE reg_geo LIKE 'Lat:%'");
    $stmt->execute();
    $res=$stmt->get_result();
    $nearby=[];
    while($row=$res->fetch_assoc()){
        preg_match('/Lat:\s*([\-0-9.]+),\s*Lon:\s*([\-0-9.]+)/',$row['reg_geo'],$m);
        if(count($m)!==3) continue;
        $dlat=deg2rad((float)$m[1]-$user_lat);
        $dlng=deg2rad((float)$m[2]-$user_lng);
        $a=sin($dlat/2)*sin($dlat/2)+cos(deg2rad($user_lat))*cos(deg2rad((float)$m[1]))*sin($dlng/2)*sin($dlng/2);
        $dist=6371*2*atan2(sqrt($a),sqrt(1-$a));
        if($dist>$radius_km) continue;
        if($f_group!=='All'&&$row['blood_group']!==$f_group) continue;
        $status=getLiveStatus($row['last_donation'],$row['willing_to_donate']??'yes');
        // Filter by live status
        if($f_status!=='All'&&$status!==$f_status) continue;
        $badge=getBadgeInfo((int)($row['total_donations']??0));
        $nearby[]=[
            'id'        =>$row['id'],
            'name'      =>esc($row['name']),
            'group'     =>esc($row['blood_group']),
            'loc'       =>esc($row['location']),
            'status'    =>$status,
            'badge'     =>$badge['icon'].' '.$badge['level'],
            'badge_icon'=>$badge['icon'],
            'dist'      =>round($dist,2)
        ];
    }
    $stmt->close();
    usort($nearby,fn($a,$b)=>$a['dist']<=>$b['dist']);
    echo json_encode(["status"=>"success","donors"=>array_slice($nearby,0,30)]);
    exit();
}

// ============================================================
// FEATURE: PUSH NOTIFICATION SUBSCRIPTION STORE
// ============================================================
if(isset($_POST['save_push_sub'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('push_sub', 5, 60);
    $endpoint  = trim($_POST['endpoint']  ?? '');
    $p256dh    = trim($_POST['p256dh']    ?? '');
    $auth      = trim($_POST['auth']      ?? '');
    $device_id = trim($_POST['device_id'] ?? '');
    if(empty($endpoint)||empty($p256dh)||empty($auth)){echo "error";exit();}
    validateLength($device_id, 100, 'Device ID');
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `endpoint` TEXT NOT NULL,
        `p256dh` TEXT NOT NULL,
        `auth` TEXT NOT NULL,
        `device_id` VARCHAR(100) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add device_id column if missing on older installs
    @$conn->query("ALTER TABLE push_subscriptions ADD COLUMN IF NOT EXISTS device_id VARCHAR(100) DEFAULT NULL");
    $chk=$conn->prepare("SELECT id FROM push_subscriptions WHERE endpoint=?");
    $chk->bind_param("s",$endpoint);$chk->execute();
    $existing=$chk->get_result()->fetch_assoc();$chk->close();
    if(!$existing){
        $ins=$conn->prepare("INSERT INTO push_subscriptions (endpoint,p256dh,auth,device_id) VALUES (?,?,?,?)");
        $ins->bind_param("ssss",$endpoint,$p256dh,$auth,$device_id);
        $ins->execute();$ins->close();
    } elseif(!empty($device_id)){
        // Update device_id if already exists but device_id was missing
        $upd=$conn->prepare("UPDATE push_subscriptions SET device_id=? WHERE endpoint=? AND (device_id IS NULL OR device_id='')");
        $upd->bind_param("ss",$device_id,$endpoint);$upd->execute();$upd->close();
    }
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    echo "ok";exit();
}

// === REQUEST NEW SECRET CODE ===
if(isset($_POST['request_new_secret_code'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('req_secret', 3, 300);
    $donor_number = trim($_POST['donor_number'] ?? '');
    $ref_code     = trim($_POST['ref_code'] ?? '');
    $device_id    = trim($_POST['device_id'] ?? '');

    // Validate phone
    if(!preg_match('/^\+8801\d{9}$/', $donor_number)){
        echo json_encode(["status"=>"error","msg"=>"সঠিক ফোন নম্বর দিন (+8801XXXXXXXXX)"]);
        exit();
    }
    // Validate ref_code: exactly 4 digits
    if(!preg_match('/^\d{4}$/', $ref_code)){
        echo json_encode(["status"=>"error","msg"=>"❌ Reference Code অবশ্যই ৪ সংখ্যার হতে হবে (যেমন: 1234)"]);
        exit();
    }
    // Block common/guessable codes
    $weak_codes = ['0000','1111','2222','3333','4444','5555','6666','7777','8888','9999',
        '1234','2345','3456','4567','5678','6789','0123','9876','8765','7654',
        '6543','5432','4321','3210','1212','2121','1313','0101','1010','1122'];
    $is_birth_year = preg_match('/^(19|20)\d{2}$/', $ref_code);
    $is_repeated_pair = preg_match('/^(\d)\1(\d)\2$/', $ref_code);
    if(in_array($ref_code, $weak_codes) || $is_birth_year || $is_repeated_pair){
        echo json_encode(["status"=>"error","msg"=>"❌ এই Reference Code সহজে guess করা যায়। অন্য একটি সংখ্যা বেছে নিন।"]);
        exit();
    }
    validateLength($device_id, 100, 'Device ID');
    if(empty($device_id)){
        echo json_encode(["status"=>"error","msg"=>"সব তথ্য দিন।"]);
        exit();
    }

    // Check donor exists
    $chk = $conn->prepare("SELECT id FROM donors WHERE phone=?");
    $chk->bind_param("s", $donor_number);
    $chk->execute();
    $donor = $chk->get_result()->fetch_assoc();
    $chk->close();
    if(!$donor){
        echo json_encode(["status"=>"error","msg"=>"❌ এই নম্বরে কোনো donor খুঁজে পাওয়া যায়নি।"]);
        exit();
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    // Block if already has a pending request
    $pending = $conn->prepare("SELECT id FROM security_code_requests WHERE donor_number=? AND status='pending'");
    $pending->bind_param("s", $donor_number);
    $pending->execute();
    $hasPending = $pending->get_result()->num_rows > 0;
    $pending->close();
    if($hasPending){
        echo json_encode(["status"=>"error","msg"=>"⚠️ আপনার আগের একটি request pending আছে। Admin process করলে notification আসবে।"]);
        exit();
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    // Store user-provided ref_code in the request (NOT in donors table)
    $stmt = $conn->prepare("INSERT INTO security_code_requests (donor_number, ref_code, device_id, req_ip, view_count) VALUES (?,?,?,?,0)");
    $stmt->bind_param("ssss", $donor_number, $ref_code, $device_id, $ip);
    if($stmt->execute()){
        echo json_encode(["status"=>"success","msg"=>"✅ Request পাঠানো হয়েছে। Admin approve করলে আপনার Services notification-এ জানাবে এবং এই Reference Code দিয়ে Secret Code দেখতে পারবেন।"]);
    } else {
        echo json_encode(["status"=>"error","msg"=>"❌ Request সংরক্ষণে সমস্যা হয়েছে।"]);
    }
    $stmt->close();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    exit();
}

// === GET SECRET CODE BY REF CODE (max 3 views) ===
if(isset($_POST['get_secret_code_by_ref'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('get_secret_ref', 5, 300);
    $donor_number = trim($_POST['donor_number'] ?? '');
    $ref_code     = trim($_POST['ref_code'] ?? '');

    if(!preg_match('/^\+8801\d{9}$/', $donor_number)){
        echo json_encode(["status"=>"error","msg"=>"সঠিক ফোন নম্বর দিন।"]);
        exit();
    }
    if(!preg_match('/^\d{4}$/', $ref_code)){
        echo json_encode(["status"=>"error","msg"=>"❌ Reference Code অবশ্যই ৪ সংখ্যার হতে হবে।"]);
        exit();
    }

    mysqli_report(MYSQLI_REPORT_OFF);

    // Find an approved request matching phone + ref_code that is not expired
    $stmt = $conn->prepare("SELECT scr.id, scr.view_count, d.secret_code
        FROM security_code_requests scr
        JOIN donors d ON d.phone = scr.donor_number
        WHERE scr.donor_number=? AND scr.ref_code=? AND scr.status='approved'
        ORDER BY scr.id DESC LIMIT 1");
    $stmt->bind_param("ss", $donor_number, $ref_code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$row){
        // Also check if there's an expired one to give better message
        $expChk = $conn->prepare("SELECT id FROM security_code_requests WHERE donor_number=? AND ref_code=? AND status='ref_expired' LIMIT 1");
        $expChk->bind_param("ss", $donor_number, $ref_code);
        $expChk->execute();
        $expRow = $expChk->get_result()->fetch_assoc();
        $expChk->close();
        if($expRow){
            echo json_encode(["status"=>"error","msg"=>"❌ এই Reference Code মেয়াদ শেষ হয়ে গেছে (৩ বার ব্যবহার হয়েছে)। নতুন request করুন।"]);
        } else {
            echo json_encode(["status"=>"error","msg"=>"❌ কোনো approved request পাওয়া যায়নি। আগে Admin এর কাছে Request করুন এবং Approve হওয়ার পর এখানে আসুন।"]);
        }
        exit();
    }

    $view_count = (int)$row['view_count'];
    $req_id     = (int)$row['id'];

    if($view_count >= 3){
        $conn->query("UPDATE security_code_requests SET status='ref_expired' WHERE id=".(int)$req_id);
        echo json_encode(["status"=>"error","msg"=>"❌ এই Reference Code মেয়াদ শেষ হয়ে গেছে (৩ বার ব্যবহার হয়েছে)। নতুন request করুন।"]);
        exit();
    }

    // Increment view_count
    $new_count = $view_count + 1;
    $conn->query("UPDATE security_code_requests SET view_count=".(int)$new_count." WHERE id=".(int)$req_id);

    // If this was the 3rd view, expire it now
    if($new_count >= 3){
        $conn->query("UPDATE security_code_requests SET status='ref_expired' WHERE id=".(int)$req_id);
    }

    $views_left = max(0, 3 - $new_count);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    echo json_encode([
        "status"      => "success",
        "secret_code" => $row['secret_code'],
        "views_used"  => $new_count,
        "views_left"  => $views_left,
        "expired"     => ($new_count >= 3)
    ]);
    exit();
}

// === GET SERVICE NOTIFICATIONS FOR DEVICE ===
if(isset($_POST['get_service_notifs'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('svc_notifs', 30, 60);
    $device_id = trim($_POST['device_id'] ?? '');
    validateLength($device_id, 100, 'Device ID');
    if(empty($device_id)){ echo json_encode([]); exit(); }
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("CREATE TABLE IF NOT EXISTS `service_notifications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `device_id` VARCHAR(100) NOT NULL,
        `type` VARCHAR(30) NOT NULL,
        `message` TEXT NOT NULL,
        `is_read` TINYINT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $conn->prepare("SELECT id, type, message, is_read, UNIX_TIMESTAMP(created_at) as ts FROM service_notifications WHERE device_id=? AND is_read=0 ORDER BY ts DESC LIMIT 30");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $notifs = [];
    while($r = $res->fetch_assoc()) $notifs[] = $r;
    $stmt->close();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    echo json_encode($notifs);
    exit();
}

// === MARK SERVICE NOTIFICATION READ ===
if(isset($_POST['mark_service_notif_read'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    $notif_id  = (int)($_POST['notif_id'] ?? 0);
    $device_id = trim($_POST['device_id'] ?? '');
    if($notif_id <= 0 || empty($device_id)){
        echo json_encode(["status"=>"error"]);
        exit();
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $stmt = $conn->prepare("UPDATE service_notifications SET is_read=1 WHERE id=? AND device_id=?");
    $stmt->bind_param("is", $notif_id, $device_id);
    $stmt->execute();
    $stmt->close();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    echo json_encode(["status"=>"success"]);
    exit();
}

// === ADMIN: GET PENDING SECURITY CODE REQUESTS ===
// Called from admin.php — returns pending list
if(isset($_POST['admin_get_secret_requests'])){
    // Admin session check (same as admin.php pattern)
    if(empty($_SESSION['admin_logged_in'])){
        header('Content-Type: application/json; charset=utf-8');
        while(ob_get_level()) ob_end_clean(); ob_start();
        echo json_encode(["status"=>"error","msg"=>"Unauthorized"]);
        exit();
    }
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    mysqli_report(MYSQLI_REPORT_OFF);
    $res = $conn->query("SELECT scr.id, scr.donor_number, scr.ref_code, scr.device_id, scr.req_ip, scr.status, scr.admin_note, scr.created_at, d.name as donor_name, d.secret_code as current_secret
        FROM security_code_requests scr
        LEFT JOIN donors d ON d.phone = scr.donor_number
        ORDER BY scr.status='pending' DESC, scr.created_at DESC LIMIT 50");
    $rows = [];
    if($res) while($r = $res->fetch_assoc()) $rows[] = $r;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    echo json_encode($rows);
    exit();
}

// === ADMIN: RESOLVE SECRET CODE REQUEST (reset + notify) ===
if(isset($_POST['admin_resolve_secret_request'])){
    if(empty($_SESSION['admin_logged_in'])){
        header('Content-Type: application/json; charset=utf-8');
        while(ob_get_level()) ob_end_clean(); ob_start();
        echo json_encode(["status"=>"error","msg"=>"Unauthorized"]);
        exit();
    }
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('admin_resolve', 30, 60);
    $req_id   = (int)($_POST['req_id'] ?? 0);
    $action   = trim($_POST['action'] ?? ''); // 'approve' or 'deny'
    $note     = trim($_POST['admin_note'] ?? '');
    if($req_id <= 0 || !in_array($action, ['approve','deny'], true)){
        echo json_encode(["status"=>"error","msg"=>"Invalid request"]);
        exit();
    }
    validateLength($note, 300, 'Admin Note');
    mysqli_report(MYSQLI_REPORT_OFF);
    $req_stmt = $conn->prepare("SELECT * FROM security_code_requests WHERE id=? AND status='pending'");
    $req_stmt->bind_param("i", $req_id);
    $req_stmt->execute();
    $req = $req_stmt->get_result()->fetch_assoc();
    $req_stmt->close();
    if(!$req){
        echo json_encode(["status"=>"error","msg"=>"Request not found or already processed"]);
        exit();
    }
    if($action === 'approve'){
        // Generate new secret code
        $new_code = generateSecretCode($conn);
        $upd = $conn->prepare("UPDATE donors SET secret_code=? WHERE phone=?");
        $upd->bind_param("ss", $new_code, $req['donor_number']);
        $upd->execute();
        $upd->close();
        // Send service notification to donor's device
        $msg = "✅ আপনার নতুন Secret Code: " . $new_code . " — এটি সংরক্ষণ করুন।";
        $ntype = 'secret_code_ready';
        $nsmt = $conn->prepare("INSERT INTO service_notifications (device_id, type, message) VALUES (?,?,?)");
        $nsmt->bind_param("sss", $req['device_id'], $ntype, $msg);
        $nsmt->execute();
        $nsmt->close();
        // Mark request approved with view_count=0
        $status = 'approved';
        $done = $conn->prepare("UPDATE security_code_requests SET status=?, admin_note=?, view_count=0 WHERE id=?");
        $done->bind_param("ssi", $status, $note, $req_id);
        $done->execute();
        $done->close();
        echo json_encode(["status"=>"success","msg"=>"✅ Approved! নতুন code: $new_code — Donor notification পাঠানো হয়েছে।","new_code"=>$new_code]);
    } else {
        // Deny
        $msg = "❌ আপনার Secret Code reset request টি Approved হয়নি।" . ($note ? " Admin note: $note" : "");
        $ntype = 'info';
        $nsmt = $conn->prepare("INSERT INTO service_notifications (device_id, type, message) VALUES (?,?,?)");
        $nsmt->bind_param("sss", $req['device_id'], $ntype, $msg);
        $nsmt->execute();
        $nsmt->close();
        $status = 'denied';
        $done = $conn->prepare("UPDATE security_code_requests SET status=?, admin_note=? WHERE id=?");
        $done->bind_param("ssi", $status, $note, $req_id);
        $done->execute();
        $done->close();
        echo json_encode(["status"=>"success","msg"=>"Request denied, notification sent to donor."]);
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    exit();
}

// === ADMIN: SEND CUSTOM SERVICE NOTIFICATION ===
if(isset($_POST['admin_send_service_notif'])){
    if(empty($_SESSION['admin_logged_in'])){
        header('Content-Type: application/json; charset=utf-8');
        while(ob_get_level()) ob_end_clean(); ob_start();
        echo json_encode(["status"=>"error","msg"=>"Unauthorized"]);
        exit();
    }
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    $device_id = trim($_POST['device_id'] ?? '');
    $type      = trim($_POST['notif_type'] ?? 'info');
    $message   = trim($_POST['message'] ?? '');
    $valid_types = ['secret_reset','secret_code_ready','location_on','notif_on','info','warning'];
    if(!in_array($type, $valid_types, true)) $type = 'info';
    validateLength($message, 500, 'Message');
    if(empty($device_id) || empty($message)){
        echo json_encode(["status"=>"error","msg"=>"device_id ও message দিন।"]);
        exit();
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $stmt = $conn->prepare("INSERT INTO service_notifications (device_id, type, message) VALUES (?,?,?)");
    $stmt->bind_param("sss", $device_id, $type, $message);
    if($stmt->execute()){
        echo json_encode(["status"=>"success","msg"=>"✅ Notification পাঠানো হয়েছে।"]);
    } else {
        echo json_encode(["status"=>"error","msg"=>"❌ Failed."]);
    }
    $stmt->close();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    exit();
}

// === SUBMIT MESSAGE TO ADMIN ===
if(isset($_POST['submit_admin_message'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('admin_msg', 3, 300); // max 3 per 5 min
    $sender_name  = trim($_POST['sender_name']  ?? '');
    $sender_phone = trim($_POST['sender_phone'] ?? '');
    $message      = trim($_POST['message']      ?? '');
    $device_id    = trim($_POST['device_id']    ?? '');
    if(empty($sender_name) || empty($sender_phone) || empty($message) || empty($device_id)){
        echo json_encode(["status"=>"error","msg"=>"সব তথ্য দিন।"]); exit();
    }
    if(!preg_match('/^\+8801\d{9}$/', $sender_phone)){
        echo json_encode(["status"=>"error","msg"=>"সঠিক ফোন নম্বর দিন (+8801XXXXXXXXX)।"]); exit();
    }
    validateLength($sender_name, 100, 'নাম');
    validateLength($message, 1000, 'Message');
    validateLength($device_id, 100, 'Device ID');
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("CREATE TABLE IF NOT EXISTS `admin_messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `sender_name` VARCHAR(100) NOT NULL,
        `sender_phone` VARCHAR(20) NOT NULL,
        `message` TEXT NOT NULL,
        `device_id` VARCHAR(100) NOT NULL,
        `is_read` TINYINT DEFAULT 0,
        `admin_reply` TEXT DEFAULT NULL,
        `replied_at` TIMESTAMP NULL DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $conn->prepare("INSERT INTO admin_messages (sender_name, sender_phone, message, device_id) VALUES (?,?,?,?)");
    $stmt->bind_param("ssss", $sender_name, $sender_phone, $message, $device_id);
    if($stmt->execute()){
        echo json_encode(["status"=>"success","msg"=>"✅ ধন্যবাদ! আপনার বার্তা পাঠানো হয়েছে। Admin এর reply আপনার Services notification এ আসবে।"]);
    } else {
        echo json_encode(["status"=>"error","msg"=>"❌ পাঠানো যায়নি। আবার চেষ্টা করুন।"]);
    }
    $stmt->close();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    exit();
}

// === GET ADMIN REPLIES FOR DEVICE ===
if(isset($_POST['get_admin_messages'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    checkRateLimit('get_admin_msgs', 20, 60);
    $device_id = trim($_POST['device_id'] ?? '');
    if(empty($device_id)){ echo json_encode([]); exit(); }
    validateLength($device_id, 100, 'Device ID');
    mysqli_report(MYSQLI_REPORT_OFF);
    // Only return messages FROM this device that have admin_reply
    $stmt = $conn->prepare("SELECT id, message, admin_reply, is_read, UNIX_TIMESTAMP(replied_at) as replied_ts
        FROM admin_messages WHERE device_id=? AND admin_reply IS NOT NULL ORDER BY replied_at DESC LIMIT 20");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    echo json_encode($rows);
    exit();
}

// === SAVE DEVICE ID (silent — permission allow OR deny উভয়ে call হয়) ===
if(isset($_POST['save_device_id'])){
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    $device_id = trim($_POST['device_id'] ?? '');
    $context   = trim($_POST['context']   ?? 'unknown');
    $valid_ctx = ['notif_allow','notif_deny','loc_allow','loc_deny','notif_prompt','loc_prompt','first_visit'];
    if(!in_array($context, $valid_ctx, true)) $context = 'unknown';
    validateLength($device_id, 100, 'Device ID');
    if(!empty($device_id)){
        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300, 'UTF-8');
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn->query("CREATE TABLE IF NOT EXISTS `device_tokens` (
            `device_id` VARCHAR(100) PRIMARY KEY,
            `context` VARCHAR(30) DEFAULT 'unknown',
            `ip` VARCHAR(50) DEFAULT NULL,
            `ua` VARCHAR(300) DEFAULT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $conn->prepare("INSERT INTO device_tokens (device_id, context, ip, ua) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE context=VALUES(context), ip=VALUES(ip), updated_at=CURRENT_TIMESTAMP");
        if($stmt){
            $stmt->bind_param("ssss", $device_id, $context, $ip, $ua);
            $stmt->execute();
            $stmt->close();
        }
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    }
    echo json_encode(["status"=>"ok"]);
    exit();
}

// === MARK ADMIN MSG REPLY AS READ ===
if(isset($_POST['mark_admin_msg_read'])){
    checkCSRF();
    header('Content-Type: application/json; charset=utf-8');
    while(ob_get_level()) ob_end_clean(); ob_start();
    $msg_id    = (int)($_POST['msg_id']    ?? 0);
    $device_id = trim($_POST['device_id'] ?? '');
    if($msg_id <= 0 || empty($device_id)){
        echo json_encode(["status"=>"error"]); exit();
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $stmt = $conn->prepare("UPDATE admin_messages SET is_read=1 WHERE id=? AND device_id=?");
    $stmt->bind_param("is", $msg_id, $device_id);
    $stmt->execute();
    $stmt->close();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    echo json_encode(["status"=>"success"]);
    exit();
}
?>  

<!DOCTYPE html>  
<html lang="en">  
<head>  
<meta charset="UTF-8">
<link rel="preload" as="image" href="logo.png">
<link rel="preload" as="image" href="logo1.png">
 
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<link rel="manifest" href="/?manifest=1">
<meta name="theme-color" content="#dc2626">
<!-- PWA: iOS Support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Blood Arena">
<link rel="apple-touch-icon" sizes="192x192" href="icon.png">
<link rel="apple-touch-icon" href="icon.png">
<meta name="application-name" content="Blood Arena">
<meta name="msapplication-TileColor" content="#dc2626">
<meta name="msapplication-TileImage" content="icon.png">
<title>Blood Arena</title>
<meta name="description" content="Blood Arena - শহীদ সোহরাওয়ার্দী মেডিকেল কলেজের একটি অনলাইন রক্তদান প্ল্যাটফর্ম। জরুরি প্রয়োজনে রক্তদাতা খুঁজে পেতে বা রক্তদাতা হিসেবে নাম লেখাতে আজই ভিজিট করুন।">
<meta name="keywords" content="Blood donation, SHSMC, Blood donor, সুহরাওয়ার্দী মেডিকেল কলেজ, রক্তদান, Blood Aren, Siam, Rafi">
<meta property="og:title" content="Blood Arena | সুহরাওয়ার্দী মেডিকেল কলেজ রক্তদান কেন্দ্র">
<meta property="og:description" content="রক্তের জন্য আর নয় অস্থিরতা। আমাদের অনলাইন ডাটাবেজে যুক্ত হোন।">
<meta property="og:image" content="https://bloodarena-shsmc.infinityfree.me/logo.png">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "MedicalOrganization",
  "name": "Blood Arena",
  "description": "Shaheed Suhrawardy Medical College Campus Blood Donation Portal",
  "url": "https://bloodarena-shsmc.infinityfree.me/",
  "logo": "https://bloodarena-shsmc.infinityfree.me/logo.png",
  "parentOrganization": {
    "@type": "MedicalOrganization",
    "name": "Shaheed Suhrawardy Medical College"
  },
  "contactPoint": {
    "@type": "ContactPoint",
    "telephone": "+8801518981827",
    "contactType": "Emergency Blood Support"
  }
}
</script>

<!-- PREVENT FOUC FOR DAY/NIGHT MODE -->
<script>
    if(localStorage.getItem('theme') === 'light'){
        document.documentElement.setAttribute('data-theme', 'light');
    }
</script>

<link rel="icon" type="image/png" href="icon.png"> 
<link rel="dns-prefetch" href="//fonts.googleapis.com">
<link rel="dns-prefetch" href="//fonts.gstatic.com">
<link rel="dns-prefetch" href="//cdn.jsdelivr.net">
<!-- Modern Fonts -->
<link rel="dns-prefetch" href="https://fonts.googleapis.com">
<link rel="dns-prefetch" href="https://fonts.gstatic.com">
<link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet"> 
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js" defer></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" defer></script>

<style>  
/* === MODERN UI/UX CSS VARIABLES (DAY & NIGHT) === */
:root {
    --bg-main: #08090f;
    --bg-card: rgba(18, 21, 28, 0.75);
    --bg-glass: rgba(10, 12, 16, 0.9);
    --primary-red: #e02424;
    --primary-red-hover: #c81e1e;
    --text-main: #edf0ff;
    --text-muted: #8fa3bf;
    --border-color: rgba(255, 255, 255, 0.07);
    --input-bg: rgba(0,0,0,0.35);
    --accent-orange: #f59e0b;
    --success: #10b981;
    --danger: #ef4444;
    --info: #3b82f6;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 20px;
    --font-heading: 'Poppins', sans-serif;
    --font-body: 'Inter', 'Roboto', sans-serif;
    --shadow-glass: 0 8px 32px 0 rgba(0, 0, 0, 0.45);
    --btn-text: #ffffff;
    --footer-bg: #060709;
    --footer-card-bg: rgba(255,255,255,0.05);
    --footer-card-border: rgba(255,255,255,0.1);
    --footer-text: #ffffff;
    --dc-zoom: 1;
}

[data-theme="light"] {
    --bg-main: #f0f4ff;
    --bg-card: rgba(255, 255, 255, 0.95);
    --bg-glass: rgba(255, 255, 255, 0.98);
    --text-main: #0b1120;
    --text-muted: #2e4060;
    --border-color: rgba(99, 102, 241, 0.15);
    --input-bg: #eef2ff;
    --shadow-glass: 0 8px 30px rgba(99, 102, 241, 0.1);
    --primary-red: #e11d48;
    --primary-red-hover: #be123c;
    --btn-text: #ffffff;
    --accent-orange: #d97706;
    --success: #059669;
    --info: #2563eb;
    --footer-bg: #1e1b4b;
    --footer-card-bg: rgba(255,255,255,0.08);
    --footer-card-border: rgba(255,255,255,0.15);
    --footer-text: #ffffff;
}

*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%;text-size-adjust:100%;}
body{overflow-anchor:none;}
body{font-family:var(--font-body);background:var(--bg-main);color:var(--text-main);line-height:1.6;overflow-x:hidden;overscroll-behavior-y:none;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-rendering:optimizeSpeed;}  

/* Subtle animated gradient background for dark mode */
/* body::before removed — was causing GPU layer bloat & repaint on scroll */

header{ 
    background: rgb(14,16,22);
    color:white; padding:12px 18px; display:flex; align-items:center; justify-content:space-between; 
    flex-wrap:nowrap; border-bottom: 1px solid rgba(224,36,36,0.18); 
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 50; 
    box-shadow: 0 2px 12px rgba(0,0,0,0.5);
    height: 76px;
    /* NOTE: NO contain here — it creates a stacking context that traps the notification panel */
}
/* Compensate for fixed header only (nav bar removed) */
body { padding-top: 76px !important; }
@media(max-width: 650px) { body { padding-top: 76px !important; } }

header img{height: 52px; width: auto; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.5));}  
header h1{
    font-family: var(--font-heading); font-weight:800; font-size:1.6rem; letter-spacing:0.3px; 
    flex-grow: 1; text-align: center; margin: 0 10px; 
    background: linear-gradient(90deg, #fff 0%, #fca5a5 60%, #f9a8d4 100%); 
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}  

/* Theme Toggle Button */
.theme-toggle { 
    background: rgba(255,255,255,0.07); border: 1.5px solid rgba(255,255,255,0.12); 
    font-size: 1.3rem; cursor: pointer; border-radius: 50%; width: 42px; height: 42px; 
    display: flex; align-items: center; justify-content: center; 
    transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease; 
    color: var(--text-main); margin:0; padding:0; box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.theme-toggle:hover { transform: scale(1.12) rotate(20deg); box-shadow: 0 4px 14px rgba(224,36,36,0.3); border-color: var(--primary-red); }

.container{width:95%; max-width:1200px; margin:auto; padding: 0 10px;}  

form{ 
    background: var(--bg-card); padding:30px; border-radius: var(--radius-lg); 
    border: 1px solid var(--border-color); box-shadow: var(--shadow-glass); 
    transition: border-color 0.3s ease;
}
form h2{text-align:center; margin-bottom:25px; color: var(--primary-red); font-family: var(--font-heading); font-weight: 700; font-size: 1.8rem;}  

.input-group { display: flex; flex-direction: column; gap: 15px; }
@media(min-width: 768px) {
    .input-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
}

input, select, textarea{ 
    width:100%; padding:13px 16px; margin: 8px 0; border-radius: var(--radius-md); 
    border: 1px solid var(--border-color); font-size:1rem; outline:none; 
    background: var(--input-bg); color: var(--text-main); font-family: var(--font-body); 
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease; 
    cursor: text; -webkit-appearance: none; appearance: none; 
}  
input::placeholder, textarea::placeholder { color: var(--text-muted); }
input:focus, select:focus, textarea:focus { 
    border-color: var(--primary-red); 
    background: rgba(224,36,36,0.04); 
    box-shadow: 0 0 0 3px rgba(224,36,36,0.15); 
}
select option { background: var(--bg-main); color: var(--text-main); font-weight:500; }
select optgroup { color: var(--primary-red); font-weight: bold; background: var(--bg-main); font-style: normal; }
select { cursor: pointer; }


button{
    background: linear-gradient(135deg, var(--primary-red) 0%, var(--primary-red-hover) 100%); 
    color:var(--btn-text); border:none; padding: 13px 24px; border-radius: var(--radius-md); 
    cursor:pointer; font-weight:600; font-size: 1rem; font-family: var(--font-heading); 
    transition: all 0.22s ease; width: 100%; margin-top: 15px; letter-spacing: 0.3px;
    box-shadow: 0 4px 14px rgba(224,36,36,0.25);
}  
button:hover{ 
    background: linear-gradient(135deg, var(--primary-red-hover) 0%, #a81616 100%); 
    transform: translateY(-2px); 
    box-shadow: 0 10px 24px rgba(224,36,36,0.45); 
}
button:active { transform: translateY(0); box-shadow: 0 2px 8px rgba(224,36,36,0.3); }

/* ── Override: compact inline buttons that must NOT be full-width ── */
.req-tab-btn, .req-bg-chip, .req-bg-clear,
.btn-deny-notif, .btn-emergency, .btn-view-requests,
.shift-btn, .sd-toggle-btn, .willing-btn,
.phone-link, .phone-link-disabled, .dc-call-btn, .dc-call-btn-disabled {
    width: auto !important;
    margin-top: 0 !important;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
}

.note{
    font-size:0.85em; color: var(--accent-orange); margin-bottom:20px; display:block; 
    text-align:center; background: rgba(245, 158, 11, 0.08); padding: 12px 16px; 
    border-radius: var(--radius-sm); border-left: 3px solid var(--accent-orange);
    line-height: 1.6;
}

/* Real Button Look for Call */
.phone-link { 
    background: linear-gradient(135deg, var(--info), #2563eb); color: #fff !important; 
    padding: 10px 16px; border-radius: 8px; font-weight: 700; font-family: var(--font-heading);
    cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; border: none;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4); font-size: 0.95em; width: 100%; margin: 0; text-transform: uppercase; letter-spacing: 0.5px;
} 
.phone-link:hover { transform: scale(1.05) translateY(-2px); box-shadow: 0 8px 20px rgba(59, 130, 246, 0.6); }
.unselectable { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }

/* Highly Interactive Report Button */
.report-btn-footer { background: var(--input-bg); color: var(--danger); padding: 16px 30px; font-size: 1.05em; font-family: var(--font-heading); font-weight: 700; border-radius: 40px; width: auto; margin: 30px auto; display: flex; align-items: center; justify-content: center; gap: 10px; border: 2px solid var(--danger); cursor: pointer; transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.2);}
.report-btn-footer:hover { background: var(--danger); color: #fff; transform: translateY(-5px) scale(1.02); box-shadow: 0 10px 25px rgba(239, 68, 68, 0.5);}

.call-notice-wrapper { overflow: hidden; white-space: nowrap; margin-top: 25px; background: rgba(220, 38, 38, 0.05); padding: 12px 0; border-radius: var(--radius-md); border: 1px solid rgba(220, 38, 38, 0.2); box-shadow: inset 0 0 10px rgba(0,0,0,0.05);}
.call-notice-text { display: inline-block; padding-left: 100%; animation: marquee-call 15s linear infinite; color: var(--accent-orange); font-size: 0.95em; font-weight: 500; font-family: var(--font-body); will-change: transform; }
@keyframes marquee-call { 0% { transform: translate3d(0, 0, 0); } 100% { transform: translate3d(-100%, 0, 0); } }

.donor-table-wrapper{overflow-x:auto; margin-top:15px; border-radius: var(--radius-lg); border: 1px solid var(--border-color); box-shadow: var(--shadow-glass); background: var(--bg-card);}  
.donor-table-wrapper::-webkit-scrollbar { height: 6px; }
.donor-table-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.05); border-radius: 10px; }
.donor-table-wrapper::-webkit-scrollbar-thumb { background: rgba(224,36,36,0.3); border-radius: 10px; }
.donor-table{ width:100%; border-collapse:collapse; min-width: 700px; }  
.donor-table th, .donor-table td{padding:13px 16px; text-align:center; border-bottom:1px solid var(--border-color); font-size:0.92em;}  
.donor-table th{background: rgba(224,36,36,0.07); color: var(--text-main); font-family: var(--font-heading); font-weight: 600; letter-spacing: 0.5px; white-space: nowrap;}  
.donor-table tr:hover { background: rgba(255,255,255,0.03); transition: background 0.15s ease; }
.donor-table tr:last-child td { border-bottom: none; }

/* Blood group badge */
.blood-badge { display:inline-block; font-weight:800; font-size:0.95em; padding:3px 10px; border-radius:20px; letter-spacing:0.5px; }
.bgApos  { background:rgba(231,76,60,0.15);  color:#e74c3c; border:1px solid rgba(231,76,60,0.3); }
.bgAneg  { background:rgba(192,57,43,0.15);  color:#c0392b; border:1px solid rgba(192,57,43,0.3); }
.bgBpos  { background:rgba(52,152,219,0.15); color:#3498db; border:1px solid rgba(52,152,219,0.3); }
.bgBneg  { background:rgba(41,128,185,0.15); color:#2980b9; border:1px solid rgba(41,128,185,0.3); }
.bgABpos { background:rgba(155,89,182,0.15); color:#9b59b6; border:1px solid rgba(155,89,182,0.3); }
.bgABneg { background:rgba(142,68,173,0.15); color:#8e44ad; border:1px solid rgba(142,68,173,0.3); }
.bgOpos  { background:rgba(243,156,18,0.15); color:#f39c12; border:1px solid rgba(243,156,18,0.3); }
.bgOneg  { background:rgba(230,126,34,0.15); color:#e67e22; border:1px solid rgba(230,126,34,0.3); }

.label-icon { margin-right:3px; }
.serial-num { color:var(--text-muted); font-size:0.85em; font-weight:600; }
.available{color: var(--success); font-weight:600; background: rgba(16, 185, 129, 0.1); padding: 6px 12px; border-radius: 20px; display: inline-block; border: 1px solid rgba(16, 185, 129, 0.2);}  
.notavailable{color: var(--danger); font-weight:600; background: rgba(239, 68, 68, 0.1); padding: 6px 12px; border-radius: 20px; display: inline-block; border: 1px solid rgba(239, 68, 68, 0.2);}  
.no-data { padding: 40px; text-align: center; color: var(--text-muted); font-weight: 500; }

.quick-shift-container { 
    display: flex; 
    overflow-x: auto; 
    gap: 10px; 
    padding: 10px 12px; 
    scrollbar-width: none; 
    -ms-overflow-style: none; 
    scroll-behavior: smooth;
    position: sticky;
    top: 118px; /* header(76) + app-page-header(42) */
    z-index: 28;
    background: var(--bg-main);
    border-bottom: 1px solid var(--border-color);
    margin: 0 -10px;
}
@media(min-width: 651px) {
    .quick-shift-container {
        top: 118px;
        margin: 0;
        border-radius: 0;
    }
}
.quick-shift-container::-webkit-scrollbar { display: none; }

.shift-btn { flex: 0 0 auto; background: var(--input-bg); color: var(--text-main); border: 2px solid var(--border-color); padding: 10px 24px; border-radius: 30px; cursor: pointer; font-weight: 600; font-family: var(--font-heading); transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease; text-align: center; min-width: 80px; width: auto; margin: 0; box-shadow: 0 2px 5px rgba(0,0,0,0.05);}
.shift-btn:hover { background: rgba(128,128,128,0.2); box-shadow: 0 3px 10px rgba(0,0,0,0.12); border-color: var(--primary-red);}
.shift-btn.active { background: var(--primary-red); color: white; border-color: var(--primary-red); box-shadow: 0 6px 20px rgba(220, 38, 38, 0.5); font-weight: 700;}
.shift-btn.active::after { content: "✓"; margin-left: 6px; font-size: 0.9em; }

.filter-container { 
    background: var(--bg-card); padding: 22px; border-radius: var(--radius-lg); margin-top: 20px; 
    border: 1px solid var(--border-color); box-shadow: var(--shadow-glass);
}
.filter-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
@media(min-width: 768px) {
    .filter-grid { grid-template-columns: 2fr 1fr 1fr 1fr; }
}

/* ====================== COMPACT BEAUTIFUL STATS CARDS ====================== */
.stats-container { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 25px auto 35px; padding: 0 10px; max-width: 1200px; } 
.stat-card { 
    background: var(--bg-card); padding: 14px 8px; border-radius: 14px; text-align: center; 
    border: 1px solid var(--border-color); 
    transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.3s ease; 
    cursor: default; position: relative; overflow: hidden; box-shadow: var(--shadow-glass); 
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    transform: translateZ(0);
    will-change: transform;
}
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 3px; }
.stat-card::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(255,255,255,0.03) 0%, transparent 60%);
    pointer-events: none;
}
.stat-card:hover { transform: translateY(-6px) scale(1.03); box-shadow: 0 16px 36px rgba(0,0,0,0.25); contain: layout; } 

.stat-card h4 { font-size: 1.3em; font-weight: 800; margin-bottom: 3px; letter-spacing: 0.5px; font-family: var(--font-heading); z-index: 1;} 
.stat-card .count { font-size: 0.78em; font-weight: 600; color: var(--text-muted); display: flex; align-items: center; gap: 5px; z-index: 1;}

/* Colors for Stats */
.blood-Aplus::before { background: #e74c3c; box-shadow: 0 0 10px #e74c3c; } .blood-Aplus h4 { color: #e74c3c; }
.blood-Aminus::before { background: #c0392b; box-shadow: 0 0 10px #c0392b; } .blood-Aminus h4 { color: #c0392b; }
.blood-Bplus::before { background: #3498db; box-shadow: 0 0 10px #3498db; } .blood-Bplus h4 { color: #3498db; }
.blood-Bminus::before { background: #2980b9; box-shadow: 0 0 10px #2980b9; } .blood-Bminus h4 { color: #2980b9; }
.blood-ABplus::before { background: #9b59b6; box-shadow: 0 0 10px #9b59b6; } .blood-ABplus h4 { color: #9b59b6; }
.blood-ABminus::before{ background: #8e44ad; box-shadow: 0 0 10px #8e44ad; } .blood-ABminus h4 { color: #8e44ad; }
.blood-Oplus::before { background: #f39c12; box-shadow: 0 0 10px #f39c12; } .blood-Oplus h4 { color: #f39c12; }
.blood-Ominus::before { background: #e67e22; box-shadow: 0 0 10px #e67e22; } .blood-Ominus h4 { color: #e67e22; }

.pagination{text-align:center; margin-top:30px; display: flex; justify-content: center; flex-wrap:wrap; gap: 8px; margin-bottom: 40px;}  
.pagination a{display:inline-flex; align-items:center; justify-content:center; min-width: 40px; height: 40px; padding: 0 12px; background: var(--input-bg); color: var(--text-main); border-radius: var(--radius-sm); text-decoration:none; font-size:0.95em; transition: background 0.2s ease, transform 0.2s ease; border: 1px solid var(--border-color); font-weight: 500;}  
.pagination a:hover{ background: rgba(128,128,128,0.2); transform: translateY(-3px); box-shadow: 0 5px 10px rgba(0,0,0,0.1); }
.pagination .active-page { background: var(--primary-red) !important; color: #fff !important; border-color: var(--primary-red); box-shadow: 0 4px 10px rgba(220, 38, 38, 0.3);}

/* FOOTER STYLES (Light & Dark Support) */
footer{ background: var(--footer-bg); color: var(--footer-text); padding: 50px 20px 30px; text-align:center; display:flex; flex-direction:column; gap:30px; align-items:center; border-top: 1px solid var(--border-color); margin-top: 50px;}  

.footer-card-container { display: flex; flex-wrap: wrap; justify-content: center; gap: 25px; width: 100%;}
.footer-card{background: var(--footer-card-bg); padding:25px; border-radius: var(--radius-lg); width:260px; border: 1px solid var(--footer-card-border); transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;}  
.footer-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); border-color: var(--primary-red);}
.footer-card img{width:100px; height:100px; object-fit:cover; border-radius:50%; border:3px solid var(--primary-red); margin-bottom:15px; padding: 3px; background: #000;}  
.footer-card .developed-by{font-size:0.85em; color: var(--text-muted); margin-bottom:8px; text-transform: uppercase; letter-spacing: 1px;}  
.footer-card span{font-weight:600; font-family: var(--font-heading); color: var(--footer-text); font-size:1.1em; display: block;}  
.footer-card p { text-align: center; word-break: break-word; font-size: 0.85em; color: var(--text-muted); margin-top: 12px; font-style: italic; line-height: 1.5; }

/* Interactive Footer Links */
.footer-links { margin-top: 10px; display: flex; justify-content: center; gap: 20px; flex-wrap: wrap; width: 100%; }
.footer-links a { 
    background: var(--footer-card-bg); color: var(--footer-text); text-decoration: none; 
    font-weight: 600; font-family: var(--font-heading); font-size: 1.05em; 
    padding: 12px 25px; border-radius: 30px; border: 2px solid var(--footer-card-border);
    transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease; box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    display: inline-flex; align-items: center; gap: 8px;
}
.footer-links a:hover { background: var(--info); color: #ffffff; border-color: var(--info); transform: translateY(-5px) scale(1.05); box-shadow: 0 10px 20px rgba(59, 130, 246, 0.4); }

/* ===== PAGE FOOTER (all navigation tabs) ===== */
.page-footer-bar {
    text-align: center;
    padding: 18px 16px 28px;
    margin-top: 30px;
    border-top: 1px solid var(--border-color);
    font-size: 0.78em;
    color: var(--text-muted);
    letter-spacing: 0.3px;
    font-family: var(--font-body);
    background: transparent;
}
.page-footer-bar span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 6px 16px;
    font-weight: 500;
}
[data-theme="light"] .page-footer-bar span {
    background: rgba(80,110,200,0.06);
    border-color: rgba(80,110,200,0.14);
    color: #4b5680;
}

/* === MODALS & POPUPS === */
.popup-overlay{ 
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%; 
    background: rgba(0,0,0,0.82);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px); 
    display: flex;
    justify-content: center;
    align-items: center; 
    visibility: hidden;
    opacity: 0; 
    transition: opacity 0.25s ease, visibility 0.25s ease; 
    z-index: 10100; /* above mobile-bottom-nav(9999) and settings(9990) */
}
/* Validation/result popup must always render above all other popups */
#popup {
    z-index: 10200;
}
.popup{ 
    background: var(--bg-card);
    padding: 32px 24px;
    border-radius: var(--radius-lg);
    text-align: center; 
    transform: scale(0.92) translateY(16px); 
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    width: 90%; max-width: 460px; 
    box-shadow: 0 32px 64px rgba(0,0,0,0.65), 0 1px 0 rgba(255,255,255,0.05); 
    border: 1px solid var(--border-color);
    max-height: 88vh;
    overflow-y: auto;
}
.popup-overlay.active { visibility: visible; opacity: 1; }
.popup-overlay.active .popup { transform: scale(1) translateY(0); }
.tick{font-size:55px; margin-bottom:15px; line-height: 1;}
.success-tick{color: var(--success); filter: drop-shadow(0 0 10px rgba(16, 185, 129, 0.4));}
.error-tick{color: var(--danger); filter: drop-shadow(0 0 10px rgba(239, 68, 68, 0.4));}
.warning-tick{color: var(--accent-orange); filter: drop-shadow(0 0 10px rgba(245, 158, 11, 0.4));}

.scroll-content { text-align: left; max-height: 400px; overflow-y: auto; background: var(--input-bg); padding: 20px; border-radius: var(--radius-md); margin: 20px 0; font-size: 0.95em; color: var(--text-muted); border: 1px solid var(--border-color); }
.scroll-content::-webkit-scrollbar { width: 6px; }
.scroll-content::-webkit-scrollbar-track { background: transparent; }
.scroll-content::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.4); border-radius: 10px; }
.scroll-content h4 { color: var(--text-main); margin-top: 15px; margin-bottom: 8px; font-family: var(--font-heading); font-weight: 600;}
.scroll-content p { margin-bottom: 12px; line-height: 1.7; }
.scroll-content strong { color: var(--text-main); }

.sponsor-banner { background: linear-gradient(135deg, rgba(220, 38, 38, 0.1) 0%, rgba(0,0,0,0) 100%); color: var(--text-main); padding: 16px 20px; text-align: center; border-left: 4px solid var(--primary-red); border-right: 4px solid var(--primary-red); font-family: var(--font-body); border-radius: var(--radius-md); margin: 20px auto; max-width: 1200px; width: 95%; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);}
.sponsor-banner p { font-size: 0.95em; margin: 0; line-height: 1.6; font-weight: 500;}
.sponsor-banner .highlight-number { display: inline-block; margin-top: 5px;}
.sponsor-banner .highlight-number a { color: var(--accent-orange); font-weight: 700; font-size: 1.1em; padding: 4px 12px; background: rgba(245, 158, 11, 0.1); border-radius: 20px; border: 1px dashed rgba(245, 158, 11, 0.3); text-decoration: none; transition: background 0.2s ease;}
.sponsor-banner .highlight-number a:hover { background: rgba(245, 158, 11, 0.2); transform: scale(1.05); display: inline-block;}

#callConfirmBox h3 { color: var(--text-main); margin-bottom: 20px; font-family: var(--font-heading); font-weight: 600; font-size: 1.5rem;}
.caller-info-item { background: var(--input-bg); padding: 15px; border-radius: var(--radius-md); margin-bottom: 15px; text-align: left; border-left: 3px solid var(--info); }
.caller-info-item small { color: var(--text-muted); font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;}
.caller-info-item p { font-weight: 500; color: var(--text-main); margin-top: 5px; font-size: 1.05em;}

.location-blocked-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.75); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); display: none; align-items: center; justify-content: center; z-index: 1000001; }
.location-blocked-box { background: var(--bg-card); border: 1px solid var(--border-color); border-top: 4px solid var(--primary-red); border-radius: var(--radius-lg); padding: 40px 30px; max-width: 460px; text-align: center; box-shadow: 0 25px 60px rgba(0,0,0,0.5); width: 90%;}
.location-blocked-box .icon { font-size: 70px; margin-bottom: 20px; filter: drop-shadow(0 0 15px rgba(245, 158, 11, 0.4)); }
.location-blocked-box h2 { color: var(--text-main); font-family: var(--font-heading); margin-bottom: 15px; font-size: 1.6rem; font-weight: 600;}
.location-blocked-box p { color: var(--text-muted); line-height: 1.6; margin-bottom: 30px; font-size: 0.95rem; }

/* Highly Interactive Tab Header */
.tab-header { 
    display:flex; background: var(--input-bg); border-radius: var(--radius-lg); padding: 6px; 
    margin: 25px 0 15px; border: 1px solid var(--border-color); position: relative; z-index: 1; 
    box-shadow: inset 0 2px 8px rgba(0,0,0,0.15);
}
.tab-btn { 
    flex:1; padding: 14px 10px; background: transparent; border: 2px solid transparent; 
    color: var(--text-muted); font-weight: 700; border-radius: var(--radius-md); cursor:pointer; 
    transition: transform 0.2s cubic-bezier(0.34,1.1,0.64,1), background 0.15s ease; font-size: 1em; margin: 0; 
    font-family: var(--font-heading); text-transform: uppercase; letter-spacing: 0.5px;
}
.tab-btn:hover { color: var(--text-main); background: rgba(255,255,255,0.05); transform: translateY(-2px); }
.tab-btn.active { 
    background: var(--bg-card); color: var(--primary-red); 
    box-shadow: 0 4px 16px rgba(0,0,0,0.2); 
    border: 2px solid rgba(224,36,36,0.2); 
    border-bottom: 3px solid var(--primary-red);
}
.tab-content { display:none; animation: fadeIn 0.22s ease; }
.tab-content.active { display:block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

.secret-box { background: var(--input-bg); padding: 18px; border-radius: var(--radius-md); text-align:center; border: 1px dashed var(--accent-orange); margin: 20px 0; font-size: 1.4em; font-weight: 700; letter-spacing: 2px; color: var(--text-main); font-family: monospace; box-shadow: inset 0 0 10px rgba(0,0,0,0.1);}
.copy-btn { background: rgba(245, 158, 11, 0.1); color: var(--accent-orange); padding: 12px 24px; border: 2px solid var(--accent-orange); border-radius: var(--radius-md); font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; margin: 0 auto; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.2); transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;}
.copy-btn:hover { background: var(--accent-orange); color: #000; transform: scale(1.05); box-shadow: 0 6px 15px rgba(245, 158, 11, 0.4);}

.countdown-btn { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid var(--success); font-weight: 600; box-shadow: none;}
.countdown-btn:disabled { opacity: 0.6; cursor: not-allowed; border-color: transparent; color: var(--text-muted); background: var(--input-bg);}
.countdown-btn.active, .countdown-btn:not(:disabled) { background: var(--success); color: #000; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);}

/* Skeleton Loading */
.skeleton { background: linear-gradient(90deg, rgba(128,128,128,0.1) 25%, rgba(128,128,128,0.2) 50%, rgba(128,128,128,0.1) 75%); background-size: 200% 100%; animation: skeleton-blink 1.5s infinite; height: 24px; border-radius: 6px; width: 100%; }
@keyframes skeleton-blink { to { background-position-x: -200%; } }
.skeleton-row td { padding: 18px 16px !important; }

/* ============================================================
   MOBILE CARD STYLES
   ============================================================ */
.donor-cards-container { display: none; margin-top: 10px; }
@media(max-width:767px) {
    .donor-cards-container { display: block !important; }
}

.dc-skeleton { padding: 10px; min-height: 54px; }

/* ============================================================
   LIGHT MODE — RICH COLORFUL DESIGN OVERRIDES
   ============================================================ */

/* Gradient page background */
[data-theme="light"] body {
    background: linear-gradient(135deg, #f0f4ff 0%, #fce7f3 40%, #ede9fe 100%);
    background-attachment: fixed;
}

/* Header — deep red gradient */
[data-theme="light"] header {
    background: linear-gradient(135deg, #be123c 0%, #e11d48 50%, #9f1239 100%) !important;
    color: #ffffff !important;
    border-bottom: none !important;
    box-shadow: 0 4px 20px rgba(225, 29, 72, 0.4) !important;
}
[data-theme="light"] header h1 {
    background: linear-gradient(90deg, #fff, #fecdd3) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
}
[data-theme="light"] header img { filter: drop-shadow(0 2px 6px rgba(0,0,0,0.25)) brightness(1.1) !important; }
[data-theme="light"] .theme-toggle {
    background: rgba(255,255,255,0.2) !important;
    border-color: rgba(255,255,255,0.4) !important;
    color: #ffffff !important;
}
[data-theme="light"] .theme-toggle:hover {
    background: rgba(255,255,255,0.35) !important;
    border-color: #ffffff !important;
}

/* Sponsor banner */
[data-theme="light"] .sponsor-banner {
    background: linear-gradient(135deg, rgba(225,29,72,0.08), rgba(99,102,241,0.06)) !important;
    border-left-color: #e11d48 !important;
    border-right-color: #6366f1 !important;
}

/* Stat cards — colorful gradient backgrounds */
[data-theme="light"] .stat-card {
    background: linear-gradient(145deg, #ffffff, #f8f5ff) !important;
    box-shadow: 0 4px 16px rgba(99,102,241,0.12) !important;
    border-color: rgba(99,102,241,0.15) !important;
}
[data-theme="light"] .stat-card:hover {
    box-shadow: 0 10px 28px rgba(99,102,241,0.22) !important;
}

/* Tab header — indigo gradient accent */
[data-theme="light"] .tab-header {
    background: linear-gradient(135deg, #ede9fe, #e0e7ff) !important;
    border-color: rgba(99,102,241,0.2) !important;
}
[data-theme="light"] .tab-btn { color: #4338ca !important; }
[data-theme="light"] .tab-btn:hover { background: rgba(99,102,241,0.12) !important; color: #1d4ed8 !important; }
[data-theme="light"] .tab-btn.active {
    background: #ffffff !important;
    color: #e11d48 !important;
    border-color: rgba(99,102,241,0.2) !important;
    border-bottom-color: #e11d48 !important;
    box-shadow: 0 6px 20px rgba(225,29,72,0.15) !important;
}

/* Forms — white card with colored left border accent */
[data-theme="light"] form {
    background: rgba(255,255,255,0.95) !important;
    border-color: rgba(99,102,241,0.15) !important;
    border-left: 4px solid #e11d48 !important;
    box-shadow: 0 8px 30px rgba(99,102,241,0.1) !important;
}
[data-theme="light"] .filter-container {
    background: rgba(255,255,255,0.9) !important;
    border-color: rgba(99,102,241,0.15) !important;
    border-top: 3px solid #6366f1 !important;
    box-shadow: 0 6px 20px rgba(99,102,241,0.1) !important;
}

/* Inputs — indigo tinted bg */
[data-theme="light"] input,
[data-theme="light"] select,
[data-theme="light"] textarea {
    background: #eef2ff !important;
    border-color: rgba(99,102,241,0.25) !important;
    color: #0f172a !important;
}
[data-theme="light"] input:focus,
[data-theme="light"] select:focus,
[data-theme="light"] textarea:focus {
    border-color: #6366f1 !important;
    background: #ffffff !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.15) !important;
}
[data-theme="light"] input::placeholder,
[data-theme="light"] textarea::placeholder { color: #94a3b8 !important; }
[data-theme="light"] select option,
[data-theme="light"] select optgroup { background: #ffffff !important; }

/* Note strip */
[data-theme="light"] .note {
    background: linear-gradient(135deg, rgba(217,119,6,0.1), rgba(245,158,11,0.06)) !important;
    border-left-color: #d97706 !important;
    color: #92400e !important;
}

/* Quick filter buttons */
[data-theme="light"] .shift-btn {
    background: linear-gradient(135deg, #ffffff, #f0f4ff) !important;
    border-color: rgba(99,102,241,0.25) !important;
    color: #3730a3 !important;
    box-shadow: 0 2px 8px rgba(99,102,241,0.1) !important;
}
[data-theme="light"] .shift-btn:hover {
    background: linear-gradient(135deg, #ede9fe, #e0e7ff) !important;
    border-color: #6366f1 !important;
}
[data-theme="light"] .shift-btn.active {
    background: linear-gradient(135deg, #e11d48, #be123c) !important;
    border-color: #e11d48 !important;
    color: #ffffff !important;
    box-shadow: 0 6px 20px rgba(225,29,72,0.35) !important;
}

/* Donor table */
[data-theme="light"] .donor-table-wrapper {
    background: rgba(255,255,255,0.95) !important;
    border-color: rgba(99,102,241,0.15) !important;
    box-shadow: 0 6px 20px rgba(99,102,241,0.1) !important;
}
[data-theme="light"] .donor-table th {
    background: linear-gradient(135deg, rgba(225,29,72,0.08), rgba(99,102,241,0.06)) !important;
    color: #1e1b4b !important;
}
[data-theme="light"] .donor-table tr:hover { background: rgba(99,102,241,0.05) !important; }
[data-theme="light"] .donor-table td { border-bottom-color: rgba(99,102,241,0.08) !important; }

/* Mobile donor cards */
[data-theme="light"] .dc {
    background: rgba(255,255,255,0.97) !important;
    border-color: rgba(99,102,241,0.14) !important;
    box-shadow: 0 3px 12px rgba(99,102,241,0.1) !important;
}
[data-theme="light"] .dc-body { border-top-color: rgba(99,102,241,0.1) !important; }

/* Popups */
[data-theme="light"] .popup {
    background: rgba(255,255,255,0.98) !important;
    border-color: rgba(99,102,241,0.2) !important;
    box-shadow: 0 30px 60px rgba(99,102,241,0.2) !important;
}
[data-theme="light"] .popup-overlay { background: rgba(30,27,75,0.55) !important; }

/* Caller info items */
[data-theme="light"] .caller-info-item {
    background: #eef2ff !important;
    border-left-color: #6366f1 !important;
}

/* Secret box */
[data-theme="light"] .secret-box {
    background: linear-gradient(135deg, #fef3c7, #fffbeb) !important;
    border-color: #d97706 !important;
    color: #78350f !important;
}

/* Pagination */
[data-theme="light"] .pagination a {
    background: rgba(255,255,255,0.9) !important;
    border-color: rgba(99,102,241,0.2) !important;
    color: #3730a3 !important;
}
[data-theme="light"] .pagination a:hover { background: #ede9fe !important; }
[data-theme="light"] .pagination .active-page {
    background: linear-gradient(135deg, #e11d48, #be123c) !important;
    color: #fff !important;
    border-color: #e11d48 !important;
}

/* Skeleton */
[data-theme="light"] .skeleton {
    background: linear-gradient(90deg, rgba(99,102,241,0.08) 25%, rgba(99,102,241,0.15) 50%, rgba(99,102,241,0.08) 75%) !important;
    background-size: 200% 100% !important;
}

/* Location blocked overlay */
[data-theme="light"] .location-blocked-overlay { background: rgba(30,27,75,0.6) !important; }
[data-theme="light"] .location-blocked-box {
    background: #ffffff !important;
    border-top-color: #e11d48 !important;
    border-color: rgba(99,102,241,0.2) !important;
}

/* Scroll content (terms/about) */
[data-theme="light"] .scroll-content {
    background: #f0f4ff !important;
    border-color: rgba(99,102,241,0.15) !important;
}

/* ============================================================
   MOBILE  ≤767px
   ============================================================ */
@media(max-width:767px){

  /* --- Header --- */
  header { padding: 10px 12px; }
  header img { height: 38px; }
  header h1 { font-size: 1.25rem; line-height: 1.3; margin: 0 6px; font-weight: 800; }

  /* --- Stat cards --- */
  .stats-container { grid-template-columns: repeat(4,1fr); gap:5px; margin:12px auto 20px; padding:0 8px; }
  .stat-card { padding:8px 3px; border-radius:10px; }
  .stat-card h4 { font-size:1em; margin-bottom:1px; }
  .stat-card .count { font-size:0.58em; }

  /* --- Forms / filters --- */
  form { padding:18px 14px; }
  form h2 { font-size:1.3rem; margin-bottom:18px; }
  .filter-container { padding:14px; }
  .filter-grid { grid-template-columns:1fr 1fr; gap:10px; }
  .tab-btn { font-size:0.8em; padding:11px 5px; }

  /* --- Popups --- */
  .popup { padding:22px 16px; }
  .popup-overlay .popup { max-height:90vh; overflow-y:auto; }
  .footer-card { width:100%; max-width:300px; }

  /* --- Touch targets + iOS zoom --- */
  input, select, textarea { min-height:48px; font-size:16px !important; }
  button { min-height:44px; }
  .shift-btn { min-height:40px; padding:9px 16px; }

  /* --- Hide desktop table, show mobile cards --- */
  .donor-table-wrapper { display: none; }
  .donor-cards-container { display: block; }
}

/* ============================================================
   DONOR BADGE STYLES
   ============================================================ */
.donor-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 0.72em; font-weight: 700; padding: 2px 8px;
    border-radius: 20px; letter-spacing: 0.3px;
}
.unavailable { color: #6b7280; font-weight:600; background: rgba(107,114,128,0.1); padding: 6px 12px; border-radius: 20px; display: inline-block; border: 1px solid rgba(107,114,128,0.2); }
.dc-badge-inline { font-size:0.85em; }

/* Badge Card in Update Form */
.badge-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    box-shadow: var(--shadow-glass);
}
.badge-card-left { display:flex; align-items:center; gap:14px; }
.badge-icon-big { font-size: 2.8rem; line-height:1; }
.badge-level-name { font-size:1.15em; font-weight:800; font-family:var(--font-heading); color:var(--text-main); }
.badge-donations { font-size:0.82em; color:var(--text-muted); margin-top:3px; font-weight:500; }
.badge-progress-wrap { flex:1; min-width:0; }
.badge-progress-bar { background:var(--input-bg); border-radius:20px; height:8px; overflow:hidden; border:1px solid var(--border-color); }
.badge-progress-fill { height:100%; border-radius:20px; background: linear-gradient(90deg, var(--primary-red), #f59e0b); transition:width 0.8s cubic-bezier(0.34,1.56,0.64,1); }
.badge-next-label { font-size:0.75em; color:var(--text-muted); margin-top:5px; text-align:right; }

/* Just Donated button */
.just-donated-btn {
    width:100%; margin-top:12px;
    background: linear-gradient(135deg, #dc2626, #b91c1c) !important;
    color:#fff !important;
    border-radius:14px !important;
    font-size:1em !important;
    padding:16px !important;
    box-shadow: 0 6px 20px rgba(220,38,38,0.4) !important;
    animation: pulse-red 2s infinite;
}
@keyframes pulse-red {
    0%,100% { box-shadow: 0 6px 20px rgba(220,38,38,0.4); }
    50%      { box-shadow: 0 8px 28px rgba(220,38,38,0.7); }
}

/* ============================================================
   SECRET CODE CHANGE SECTION
   ============================================================ */
.secret-change-wrap {
    background: var(--input-bg);
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: border-color 0.2s;
}
.secret-change-wrap:focus-within {
    border-color: var(--accent-orange);
}
.secret-change-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 16px;
    cursor: pointer;
    font-size: 0.9em;
    font-weight: 700;
    color: var(--text-main);
    user-select: none;
    -webkit-user-select: none;
    transition: background 0.15s;
}
.secret-change-header:hover { background: rgba(245,158,11,0.06); }
.secret-change-arrow {
    font-size: 1.2em;
    color: var(--text-muted);
    transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1), color 0.2s;
}
.secret-change-arrow.open {
    transform: rotate(90deg);
    color: var(--accent-orange);
}
.secret-change-body {
    padding: 0 16px 16px;
    border-top: 1px solid var(--border-color);
}
.secret-change-note {
    font-size: 0.78em;
    color: var(--accent-orange);
    background: rgba(245,158,11,0.08);
    border-left: 3px solid var(--accent-orange);
    padding: 8px 10px;
    border-radius: 0 6px 6px 0;
    margin: 12px 0 0;
    line-height: 1.6;
}
.secret-prefix-badge {
    background: rgba(245,158,11,0.12);
    border: 1.5px solid rgba(245,158,11,0.35);
    color: var(--accent-orange);
    font-family: monospace;
    font-weight: 800;
    font-size: 0.95em;
    padding: 10px 10px;
    border-radius: var(--radius-sm);
    white-space: nowrap;
    flex-shrink: 0;
    letter-spacing: 1px;
}
.secret-hint {
    font-size: 0.76em;
    margin: 6px 0 0;
    padding: 5px 8px;
    border-radius: 6px;
    font-weight: 500;
}
.secret-hint.ok  { color: var(--success); background: rgba(16,185,129,0.08); }
.secret-hint.err { color: var(--danger);  background: rgba(239,68,68,0.08); }
[data-theme="light"] .secret-change-wrap {
    background: #f8f9ff;
    border-color: rgba(99,102,241,0.18);
}
[data-theme="light"] .secret-change-header { color: #0b1120; }
[data-theme="light"] .secret-change-body { border-top-color: rgba(99,102,241,0.1); }

/* ============================================================
   WILLING TOGGLE
    background: var(--input-bg);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 16px;
}
.willing-toggle-row { display:flex; gap:10px; }
.willing-btn {
    flex:1; padding:12px 8px !important; border-radius:10px !important;
    font-size:0.88em !important; margin:0 !important; font-weight:600 !important;
    transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease, border-color 0.15s ease !important;
    border: 2px solid var(--border-color) !important;
    background: var(--bg-card) !important;
    color: var(--text-muted) !important;
    box-shadow: none !important;
}
.willing-btn.active.willing-yes { background: rgba(16,185,129,0.15) !important; color: #059669 !important; border-color: #059669 !important; }
.willing-btn.active.willing-no  { background: rgba(239,68,68,0.12) !important;  color: #ef4444 !important; border-color: #ef4444 !important; }
.willing-note { font-size:0.8em; color:var(--text-muted); margin-top:8px; margin-bottom:0; text-align:center; }

/* ============================================================
   ANALYTICS SECTION
   ============================================================ */
.analytics-section, .map-section {
    margin-top: 60px;
    padding-bottom: 20px;
}
.section-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 22px;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 12px;
}
.section-title {
    margin:0; font-family:var(--font-heading);
    color:var(--text-main); font-size:1.8rem; font-weight:800;
}
.section-sub { margin:4px 0 0; color:var(--text-muted); font-size:0.88em; }
.analytics-refresh-btn {
    background: var(--input-bg) !important;
    color: var(--text-main) !important;
    border: 1px solid var(--border-color) !important;
    padding: 10px 18px !important;
    border-radius: 30px !important;
    font-size: 0.9em !important;
    font-weight: 600 !important;
    width: auto !important;
    margin: 0 !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease, border-color 0.15s ease !important;
    white-space: nowrap;
}
.analytics-refresh-btn:hover { transform:translateY(-2px) !important; border-color:var(--primary-red) !important; color:var(--primary-red) !important; }

/* KPI grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 18px 12px;
    text-align: center;
    box-shadow: var(--shadow-glass);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    position: relative;
    overflow: hidden;
    will-change: transform;
    transform: translateZ(0);
}
.kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.kpi-total::before  { background: linear-gradient(90deg,#6366f1,#8b5cf6); box-shadow: 0 0 8px rgba(99,102,241,0.5); }
.kpi-avail::before  { background: linear-gradient(90deg,#10b981,#059669); box-shadow: 0 0 8px rgba(16,185,129,0.5); }
.kpi-unav::before   { background: linear-gradient(90deg,#ef4444,#dc2626); box-shadow: 0 0 8px rgba(239,68,68,0.5); }
.kpi-calls::before  { background: linear-gradient(90deg,#3b82f6,#2563eb); box-shadow: 0 0 8px rgba(59,130,246,0.5); }
.kpi-req::before    { background: linear-gradient(90deg,#f59e0b,#d97706); box-shadow: 0 0 8px rgba(245,158,11,0.5); }
.kpi-donated::before{ background: linear-gradient(90deg,#e02424,#f87171); box-shadow: 0 0 8px rgba(220,36,36,0.5); }
.kpi-card:hover { transform:translateY(-5px); box-shadow:0 14px 32px rgba(0,0,0,0.2); }
.kpi-icon { font-size:1.7rem; margin-bottom:8px; }
.kpi-val { font-size:2.2rem; font-weight:900; font-family:var(--font-heading); color:var(--text-main); line-height:1.1; }
.kpi-label { font-size:0.73em; color:var(--text-muted); margin-top:5px; font-weight:600; }

/* Charts grid */
.charts-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.chart-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 20px;
    box-shadow: var(--shadow-glass);
}
.chart-title { font-family:var(--font-heading); font-size:1em; font-weight:700; color:var(--text-main); margin:0 0 16px; }

/* Bar chart */
.bar-chart-wrap { display:flex; flex-direction:column; gap:8px; }
.bar-row { display:flex; align-items:center; gap:10px; }
.bar-label { font-size:0.78em; font-weight:700; min-width:36px; text-align:right; }
.bar-track { flex:1; background:var(--input-bg); border-radius:20px; height:20px; overflow:hidden; }
.bar-fill { height:100%; border-radius:20px; transition:width 0.9s cubic-bezier(0.34,1.56,0.64,1); display:flex; align-items:center; justify-content:flex-end; padding-right:6px; }
.bar-count { font-size:0.7em; font-weight:700; color:#fff; }

/* Badge donut */
.badge-donut-wrap { display:flex; align-items:center; justify-content:center; gap:20px; }
.badge-legend { display:flex; flex-direction:column; gap:8px; }
.badge-legend-item { display:flex; align-items:center; gap:8px; font-size:0.82em; font-weight:600; color:var(--text-main); }
.badge-legend-dot { width:12px; height:12px; border-radius:50%; flex-shrink:0; }

/* Location chart */
.loc-chart-wrap { display:flex; flex-direction:column; gap:8px; }
.loc-row { display:flex; align-items:center; gap:10px; }
.loc-name { font-size:0.8em; font-weight:600; min-width:120px; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.loc-bar-track { flex:1; background:var(--input-bg); border-radius:20px; height:18px; overflow:hidden; }
.loc-bar-fill { height:100%; border-radius:20px; background:linear-gradient(90deg,var(--primary-red),#f59e0b); display:flex; align-items:center; justify-content:flex-end; padding-right:6px; }
.loc-count { font-size:0.68em; font-weight:700; color:#fff; }

/* ============================================================
   MAP FILTER BAR — above the map
   ============================================================ */
.map-filter-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 14px 16px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.map-filter-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.map-filter-label {
    font-size: 0.78em;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.map-filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.map-pill {
    padding: 5px 13px;
    border-radius: 20px;
    font-size: 0.78em;
    font-weight: 700;
    border: 1.5px solid var(--border-color);
    background: var(--input-bg);
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.15s ease;
    margin: 0;
    width: auto;
    min-height: unset;
    box-shadow: none;
    font-family: var(--font-heading);
    letter-spacing: 0.2px;
}
.map-pill:hover {
    border-color: var(--primary-red);
    color: var(--primary-red);
    background: rgba(220,38,38,0.06);
    transform: translateY(-1px);
}
.map-pill.active {
    background: var(--primary-red);
    color: #fff;
    border-color: var(--primary-red);
    box-shadow: 0 3px 10px rgba(220,38,38,0.35);
}
.map-pill-avail.active  { background: #10b981; border-color: #10b981; box-shadow: 0 3px 10px rgba(16,185,129,0.35); }
.map-pill-notavail.active { background: #ef4444; border-color: #ef4444; box-shadow: 0 3px 10px rgba(239,68,68,0.35); }
.map-pill-unwill.active { background: #6b7280; border-color: #6b7280; box-shadow: 0 3px 10px rgba(107,114,128,0.35); }
.map-pill-avail:hover   { border-color: #10b981; color: #10b981; background: rgba(16,185,129,0.08); }
.map-pill-notavail:hover { border-color: #ef4444; color: #ef4444; background: rgba(239,68,68,0.08); }
.map-pill-unwill:hover  { border-color: #6b7280; color: #6b7280; background: rgba(107,114,128,0.08); }
.map-filter-info {
    font-size: 0.78em;
    color: var(--text-muted);
    padding: 6px 10px;
    background: rgba(59,130,246,0.07);
    border-radius: 8px;
    border: 1px solid rgba(59,130,246,0.15);
    font-weight: 500;
}
[data-theme="light"] .map-filter-bar {
    background: rgba(255,255,255,0.95);
    border-color: rgba(99,102,241,0.15);
}
[data-theme="light"] .map-pill {
    background: #f0f4ff;
    border-color: rgba(99,102,241,0.2);
    color: #4338ca;
}
[data-theme="light"] .map-pill:hover {
    background: rgba(225,29,72,0.06);
    border-color: #e11d48;
    color: #e11d48;
}
[data-theme="light"] .map-pill.active {
    background: #e11d48;
    border-color: #e11d48;
    color: #fff;
}
@media(max-width:767px) {
    .map-filter-bar { padding: 10px 12px; gap: 10px; }
    .map-pill { font-size: 0.72em; padding: 4px 10px; }
}

/* ============================================================
   MAP SECTION
   ============================================================ */
.map-container {
    width: 100%;
    height: 420px;
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-glass);
    background: var(--input-bg);
    position: relative;
}
.map-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--text-muted);
    gap: 8px;
}
.map-legend {
    display: flex;
    gap: 20px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 12px;
}
.map-legend-item { display:flex; align-items:center; gap:6px; font-size:0.85em; font-weight:600; color:var(--text-main); }

/* ============================================================
   EMERGENCY BLOOD REQUEST STYLES
   ============================================================ */
.emergency-banner {
    background: linear-gradient(135deg, rgba(224,36,36,0.12), rgba(245,158,11,0.07));
    border: 1px solid rgba(224,36,36,0.35);
    border-radius: var(--radius-lg);
    padding: 16px 22px;
    margin: 20px auto;
    width: 95%; max-width: 1200px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
    box-shadow: 0 4px 24px rgba(224,36,36,0.12), inset 0 1px 0 rgba(255,255,255,0.05);
    animation: pulse-border 2.5s ease-in-out infinite;
}
@keyframes pulse-border {
    0%,100%{box-shadow:0 4px 20px rgba(220,38,38,0.15);}
    50%{box-shadow:0 4px 30px rgba(220,38,38,0.35);}
}
.emergency-banner-left { display:flex; align-items:center; gap:12px; }
.emergency-banner-icon { font-size:2rem; animation: pulse-icon 1s ease-in-out infinite alternate; }
@keyframes pulse-icon { from{transform:scale(1);}to{transform:scale(1.15);} }
.emergency-banner-text h4 { color:var(--danger); font-family:var(--font-heading); font-size:1.05rem; margin-bottom:2px; }
.emergency-banner-text p  { color:var(--text-muted); font-size:0.82em; }
.emergency-banner-btns { display:flex; gap:8px; flex-wrap:wrap; }
.btn-emergency { background:var(--danger); color:#fff; padding:10px 18px; border-radius:25px; font-size:0.9em; font-weight:700; cursor:pointer; border:none; transition:all 0.2s; width:auto; margin:0; }
.btn-emergency:hover { background:#b91c1c; transform:translateY(-2px); box-shadow:0 6px 15px rgba(220,38,38,0.4); }
.btn-view-requests { background:transparent; color:var(--accent-orange); border:1.5px solid var(--accent-orange); padding:9px 16px; border-radius:25px; font-size:0.9em; font-weight:700; cursor:pointer; transition:all 0.2s; width:auto; margin:0; }
.btn-view-requests:hover { background:var(--accent-orange); color:#000; }

/* Request cards */
.req-section { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; margin:20px auto; width:95%; max-width:1200px; display:none; }
.req-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; margin-top:14px; }
/* ── Filter rows ── */
.req-filter-row { display:flex; flex-wrap:wrap; gap:7px; align-items:center; }
.req-tab-btn {
    width:auto !important; min-height:unset !important; margin:0 !important; box-shadow:none !important;
    padding:7px 16px; border-radius:20px; font-size:0.83em; font-weight:700; cursor:pointer;
    border:1.5px solid var(--border-color); background:transparent; color:var(--text-muted);
    transition:all 0.18s; letter-spacing:0.2px;
}
.req-tab-btn.req-tab-active {
    background:var(--danger) !important; color:#fff !important; border-color:var(--danger) !important;
    box-shadow:0 2px 10px rgba(220,38,38,0.3) !important;
}
.req-bg-chip {
    width:auto !important; min-height:unset !important; margin:0 !important; box-shadow:none !important;
    padding:5px 10px; border-radius:16px; font-size:0.76em; font-weight:800; cursor:pointer;
    border:1.5px solid var(--border-color); background:var(--bg-main); color:var(--text-muted);
    transition:all 0.15s; letter-spacing:0.3px;
}
.req-bg-chip.chip-active {
    background:rgba(220,38,38,0.12) !important; color:var(--danger) !important;
    border-color:rgba(220,38,38,0.5) !important; transform:scale(1.08);
}
.req-bg-clear {
    width:auto !important; min-height:unset !important; margin:0 !important; box-shadow:none !important;
    padding:4px 10px; border-radius:14px; font-size:0.74em; font-weight:700; cursor:pointer;
    border:1px solid rgba(220,38,38,0.35); background:rgba(220,38,38,0.08); color:var(--danger);
    transition:all 0.15s;
}
.req-card { background:var(--bg-main); border-radius:var(--radius-md); border:1px solid var(--border-color); padding:16px; position:relative; overflow:hidden; }
.req-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.req-card.critical::before { background:var(--danger); }
.req-card.high::before { background:var(--accent-orange); }
.req-card.medium::before { background:var(--info); }
.req-card-urgency { font-size:0.72em; font-weight:700; text-transform:uppercase; letter-spacing:1px; padding:3px 8px; border-radius:20px; display:inline-block; margin-bottom:8px; }
.req-card-urgency.critical { background:rgba(239,68,68,0.15); color:var(--danger); }
.req-card-urgency.high     { background:rgba(245,158,11,0.15); color:var(--accent-orange); }
.req-card-urgency.medium   { background:rgba(59,130,246,0.15); color:var(--info); }
.req-card-group { font-size:2rem; font-weight:800; color:var(--primary-red); font-family:var(--font-heading); line-height:1; margin-bottom:4px; }
.req-card-name  { font-weight:600; font-size:0.95em; color:var(--text-main); }
.req-card-hosp  { color:var(--text-muted); font-size:0.82em; margin:4px 0; }
.req-card-meta  { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
.req-tag { font-size:0.78em; padding:3px 8px; border-radius:12px; background:var(--input-bg); color:var(--text-muted); }
.req-call-btn { background:linear-gradient(135deg,var(--success),#059669); color:#fff; border:none; padding:9px 14px; border-radius:8px; font-size:0.88em; font-weight:700; cursor:pointer; width:100%; margin-top:10px; transition:all 0.2s; }
.req-call-btn:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(16,185,129,0.4); }

/* Nearby donor section */
.nearby-section { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; margin:20px auto; width:95%; max-width:1200px; }
.nearby-controls { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
.nearby-controls select, .nearby-controls input { margin:0; flex:1; min-width:130px; }
.nearby-results { display:block; }
.nearby-results.donor-cards-container { display:block !important; }
.nearby-card { background:var(--bg-main); border-radius:var(--radius-md); border:1px solid var(--border-color); padding:14px; display:flex; flex-direction:column; gap:6px; min-width:0; box-sizing:border-box; }
@media(max-width:650px){
    .nearby-section { padding: 14px; }
}
.nearby-dist { font-size:0.78em; color:var(--info); font-weight:700; background:rgba(59,130,246,0.1); padding:3px 8px; border-radius:12px; display:inline-block; }
.nearby-empty { text-align:center; padding:40px; color:var(--text-muted); }

/* Push notification prompt — iOS-style */
.notif-prompt {
    position: fixed; bottom: 20px; left: 50%;
    transform: translateX(-50%) translateY(30px);
    background: var(--bg-card);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 20px; padding: 18px 18px 16px;
    box-shadow: 0 12px 48px rgba(0,0,0,0.55), 0 2px 8px rgba(0,0,0,0.3), 0 0 0 0.5px rgba(255,255,255,0.06);
    z-index: 10050; max-width: 360px; width: calc(100% - 32px);
    opacity: 0; pointer-events: none;
    transition: opacity 0.35s ease, transform 0.4s cubic-bezier(0.34,1.56,0.64,1);
    will-change: opacity, transform;
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
}
.notif-prompt.np-show {
    opacity: 1; pointer-events: auto;
    transform: translateX(-50%) translateY(0);
}
@media(max-width:650px){
    .notif-prompt {
        bottom: calc(82px + env(safe-area-inset-bottom, 0px));
        width: calc(100% - 24px); max-width: none; left: 50%;
        border-radius: 18px;
    }
}
@keyframes slide-up { from{transform:translateX(-50%) translateY(30px);opacity:0;} to{transform:translateX(-50%) translateY(0);opacity:1;} }
.notif-prompt-icon { font-size:2.2rem; flex-shrink:0; }
.notif-prompt-text h4 { color:var(--text-main); font-size:0.95em; font-weight:700; margin-bottom:3px; }
.notif-prompt-text p  { color:var(--text-muted); font-size:0.8em; }
.notif-prompt-btns { display:flex; gap:8px; margin-top:8px; }
.btn-allow-notif {
    background: var(--primary-red); color: #fff; border: none;
    padding: 9px 0; border-radius: 10px; font-size: 0.87em;
    font-weight: 700; cursor: pointer; flex: 1; margin: 0;
    transition: opacity 0.15s;
    width: auto !important; display: inline-flex !important;
    align-items: center; justify-content: center;
}
.btn-allow-notif:hover { background: var(--primary-red) !important; transform: none !important; box-shadow: none !important; }
.btn-allow-notif:active { opacity: 0.82; transform: none !important; }
.btn-deny-notif {
    background: rgba(128,128,128,0.14); color: var(--text-muted);
    border: 1px solid var(--border-color);
    padding: 9px 0; border-radius: 10px; font-size: 0.87em;
    font-weight: 600; cursor: pointer; flex: 1; margin: 0;
    transition: opacity 0.15s;
}
.btn-deny-notif:hover { background: rgba(128,128,128,0.2) !important; transform: none !important; box-shadow: none !important; }
.btn-deny-notif:active { opacity: 0.7; transform: none !important; }
/* np-btn-row: force equal-width flex buttons, override global button width:100% */
.np-btn-row { display: flex; gap: 8px; }
.np-btn-row button,
.np-btn-row .btn-allow-notif,
.np-btn-row .btn-deny-notif {
    flex: 1 !important;
    width: 0 !important; /* flex-basis 0 so both grow equally */
    min-width: 0 !important;
    margin-top: 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}
/* Notif prompt app-icon style row */
.np-app-row {
    display: flex; align-items: flex-start; gap: 13px;
}
.np-app-icon {
    width: 50px; height: 50px; flex-shrink: 0;
    background: linear-gradient(135deg, #dc2626, #9f1239);
    border-radius: 12px; display: flex; align-items: center;
    justify-content: center; font-size: 1.55rem;
    box-shadow: 0 4px 12px rgba(220,38,38,0.4);
}
.np-text-wrap { flex: 1; min-width: 0; }
.np-app-name { font-weight: 800; font-size: 0.93em; color: var(--text-main); margin-bottom: 2px; line-height: 1.2; }
.np-msg { font-size: 0.81em; color: var(--text-muted); line-height: 1.45; margin-bottom: 11px; }

/* Admin link in footer */
.admin-link { color:var(--text-muted); font-size:0.75em; text-decoration:none; opacity:0.4; transition:opacity 0.2s; }
.admin-link:hover { opacity:1; color:var(--primary-red); }

@media(max-width:767px){
    .req-grid,.nearby-results { grid-template-columns:1fr; }
    .emergency-banner { flex-direction:column; align-items:flex-start; }
}
@media(max-width:767px){
    .kpi-grid { grid-template-columns: repeat(2,1fr); gap:8px; }
    .kpi-val { font-size:1.5rem; }
    .kpi-icon { font-size:1.2rem; }
    .charts-grid { grid-template-columns:1fr; }
    .badge-donut-wrap { flex-direction:column; }
    .map-container { height:320px; }
    .badge-card { flex-direction:column; align-items:flex-start; }
    .badge-progress-wrap { width:100%; }
    .willing-toggle-row { flex-direction:column; }
    .section-title { font-size:1.4rem; }
    .loc-name { min-width:90px; }
}


/* ============================================================
   DEVELOPER SECTION — Cards + AI Logos
   ============================================================ */
.dev-section {
    padding: 18px 14px 14px;
    max-width: 500px;
    margin: 0 auto;
}
.dev-section-label {
    text-align:center;
    font-size:0.62em;
    text-transform:uppercase;
    letter-spacing:2.5px;
    color:var(--text-muted);
    font-weight:700;
    margin-bottom:12px;
}
.dev-cards-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.dev-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 14px 10px 12px;
    text-align: center;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    transition: transform 0.22s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.22s ease;
}
.dev-card:hover {
    transform: translateY(-4px) scale(1.02);
    box-shadow: 0 14px 32px rgba(0,0,0,0.3);
}
.dev-card-bar {
    position:absolute; top:0; left:0; right:0; height:2.5px;
}
.dev-avatar {
    width:58px; height:58px; border-radius:50%; object-fit:cover;
    border: 2.5px solid transparent;
    margin: 6px auto 8px; display:block;
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}
.dev-avatar-svg {
    width:58px; height:58px; border-radius:50%;
    border: 2.5px solid #cc785c;
    margin: 6px auto 8px; display:flex;
    align-items:center; justify-content:center;
    background: radial-gradient(circle at 30% 30%, rgba(204,120,92,0.15), rgba(0,0,0,0.3));
    box-shadow: 0 4px 12px rgba(204,120,92,0.25), inset 0 0 0 1px rgba(204,120,92,0.1);
}
.dev-name {
    font-weight:800; font-family:var(--font-heading);
    color:var(--text-main); font-size:0.83em; margin:0 0 2px;
}
.dev-role { font-size:0.67em; color:var(--text-muted); margin:0 0 9px; }
.dev-btn {
    display:inline-flex; align-items:center; gap:4px;
    border-radius:20px; padding:5px 12px;
    font-size:0.72em; font-weight:700; text-decoration:none;
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.dev-btn:hover { opacity:0.85; transform:scale(1.06); }
.dev-btn-red   { background:rgba(220,38,38,0.12);  color:var(--primary-red); }
.dev-btn-claude{ background:rgba(204,120,92,0.12); color:#cc785c; }
.dev-btn-si    { background:rgba(59,130,246,0.12); color:#3b82f6; }
.dev-badge {
    display:inline-flex; align-items:center; gap:4px;
    border-radius:20px; padding:5px 11px;
    font-size:0.70em; font-weight:700;
    border: 1px solid transparent;
    letter-spacing:0.2px;
}
.dev-badge-red   { background:rgba(220,38,38,0.10); color:var(--primary-red); border-color:rgba(220,38,38,0.22); }
.dev-badge-green { background:rgba(16,185,129,0.10); color:#10b981; border-color:rgba(16,185,129,0.22); }
.dev-badge-orange{ background:rgba(245,158,11,0.10); color:#f59e0b; border-color:rgba(245,158,11,0.22); }
.dev-badge-purple{ background:rgba(139,92,246,0.10); color:#8b5cf6; border-color:rgba(139,92,246,0.22); }

/* Blood Arena logo avatar — contain fit so logo isn't cropped */
.dev-avatar-logo {
    object-fit: contain !important;
    background: #0a0e1a;
    padding: 6px;
}
[data-theme="light"] .dev-avatar-logo {
    background: #0d1a35;
}

/* Claude chip special accent */
.ai-logo-chip-claude {
    border-color: rgba(204,120,92,0.35) !important;
    color: #cc785c !important;
}
.ai-logo-chip-claude:hover {
    background: rgba(204,120,92,0.12) !important;
    border-color: #cc785c !important;
    color: #d4956a !important;
}

/* AI Tools Row */
.ai-tools-row {
    margin-top: 12px;
    text-align: center;
}
.ai-tools-label {
    font-size:0.60em; text-transform:uppercase; letter-spacing:1.5px;
    color:var(--text-muted); font-weight:600; margin-bottom:8px;
    opacity:0.7;
}
.ai-tools-logos {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
}
.ai-logo-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 20px;
    background: var(--input-bg);
    border: 1px solid var(--border-color);
    font-size: 0.67em;
    font-weight: 600;
    color: var(--text-muted);
    text-decoration: none;
    transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease, border-color 0.18s ease;
    font-family: var(--font-heading);
    white-space: nowrap;
}
.ai-logo-chip:hover {
    background: rgba(255,255,255,0.08);
    color: var(--text-main);
    border-color: rgba(255,255,255,0.2);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
[data-theme="light"] .ai-logo-chip:hover {
    background: rgba(99,102,241,0.08);
    color: #1e1b4b;
    border-color: rgba(99,102,241,0.25);
}

/* ============================================================
   GLOBAL UI POLISH — stat cards, hero bar, donor cards
   ============================================================ */

/* Smoother hero bar */
.home-hero-bar {
    background: linear-gradient(135deg, var(--bg-card) 0%, rgba(224,36,36,0.04) 100%) !important;
    border: 1px solid var(--border-color) !important;
}
.home-hero-num { text-shadow: 0 2px 8px rgba(224,36,36,0.2); }

/* Stat cards — glassy shimmer edge */
.stat-card::after {
    background: linear-gradient(135deg, rgba(255,255,255,0.05) 0%, transparent 50%) !important;
}

/* Donor cards — slightly elevated feel */
.dc {
    transition: box-shadow 0.2s ease, transform 0.2s ease !important;
}
.dc:active {
    transform: scale(0.985) !important;
}

/* Scrollbar polish */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(220,38,38,0.25); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: rgba(220,38,38,0.4); }

/* Light mode dev card */
[data-theme="light"] .dev-card {
    background: #fff !important;
    border-color: rgba(99,102,241,0.14) !important;
    box-shadow: 0 3px 14px rgba(99,102,241,0.08) !important;
}
[data-theme="light"] .ai-logo-chip {
    background: #f0f4ff !important;
    border-color: rgba(99,102,241,0.18) !important;
    color: #4338ca !important;
}

/* ============================================================
   SMART DATE PICKER
   ============================================================ */
.smart-date-wrap { }
.smart-date-toggle { display:flex; gap:8px; margin-bottom:4px; }
.sd-toggle-btn {
    flex:1; padding:10px 8px !important; border-radius:10px !important;
    font-size:0.88em !important; margin:0 !important; font-weight:600 !important;
    border: 2px solid var(--border-color) !important;
    background: var(--bg-card) !important; color: var(--text-muted) !important;
    box-shadow: none !important; cursor:pointer; transition:all 0.2s ease !important;
    width:auto !important; min-height:40px;
}
.sd-toggle-btn.sd-active {
    background: rgba(224,36,36,0.12) !important;
    color: var(--primary-red) !important;
    border-color: var(--primary-red) !important;
}

/* ============================================================
   CALL BUTTON — DISABLED STATE FOR NON-AVAILABLE DONORS
   ============================================================ */
/* ── Called donor button states ── */
.phone-link.btn-called {
    background: linear-gradient(135deg, #065f46, #047857) !important;
    color: #6ee7b7 !important;
    box-shadow: 0 4px 10px rgba(5,150,105,0.35) !important;
    cursor: pointer;
    opacity: 0.88;
    font-size: 0.85em;
    letter-spacing: 0.3px;
    /* pointer-events kept ON — user can call again */
}
.dc-call-btn.btn-called {
    background: linear-gradient(180deg, #047857 0%, #065f46 100%) !important;
    color: #6ee7b7 !important;
    border-left: 1px solid rgba(255,255,255,0.12) !important;
    opacity: 0.88;
    font-size: 0.75em;
    cursor: pointer;
    /* pointer-events kept ON — user can call again */
}
/* ── Next donor blink on the call button ── */
@keyframes nextCallBlink {
    0%   { box-shadow: 0 0 0 0 rgba(220,38,38,0.85), 0 4px 12px rgba(220,38,38,0.5); transform: scale(1); }
    30%  { box-shadow: 0 0 0 8px rgba(220,38,38,0), 0 4px 12px rgba(220,38,38,0.5); transform: scale(1.08); }
    50%  { box-shadow: 0 0 0 0 rgba(220,38,38,0), 0 4px 12px rgba(220,38,38,0.5); transform: scale(1); }
    75%  { box-shadow: 0 0 0 5px rgba(220,38,38,0), 0 4px 12px rgba(220,38,38,0.5); transform: scale(1.05); }
    100% { box-shadow: 0 0 0 0 rgba(220,38,38,0), 0 4px 12px rgba(220,38,38,0.5); transform: scale(1); }
}
.phone-link.btn-next-blink {
    animation: nextCallBlink 0.42s ease 9 !important;
    background: linear-gradient(135deg, #dc2626, #b91c1c) !important;
}
.dc-call-btn.btn-next-blink {
    animation: nextCallBlink 0.42s ease 9 !important;
    background: linear-gradient(180deg, #dc2626 0%, #b91c1c 100%) !important;
}
.phone-link-disabled {
    background: rgba(107,114,128,0.18) !important;
    color: #6b7280 !important;
    padding: 10px 16px; border-radius: 8px; font-weight: 700;
    font-family: var(--font-heading); cursor: not-allowed;
    border: 1.5px solid rgba(107,114,128,0.25) !important;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    box-shadow: none !important; font-size: 0.9em; width: 100%; margin: 0;
    text-transform: uppercase; letter-spacing: 0.5px; pointer-events:none;
}
.dc-call-btn-disabled {
    background: rgba(107,114,128,0.15) !important;
    color: #6b7280 !important; border: 1.5px solid rgba(107,114,128,0.2) !important;
    border-radius: 10px; padding: 8px 10px; font-size: 1rem; cursor: not-allowed;
    pointer-events:none; margin:0; box-shadow:none !important;
    min-width:38px; min-height:38px; display:flex; align-items:center; justify-content:center;
}

/* ============================================================
   NOTIFICATION BELL — LIVE ANIMATION
   ============================================================ */
@keyframes bellShake {
    0%   { transform: rotate(0deg) scale(1); }
    10%  { transform: rotate(-15deg) scale(1.15); }
    20%  { transform: rotate(15deg) scale(1.18); }
    30%  { transform: rotate(-12deg) scale(1.12); }
    40%  { transform: rotate(12deg) scale(1.15); }
    50%  { transform: rotate(-8deg) scale(1.1); }
    60%  { transform: rotate(8deg) scale(1.08); }
    70%  { transform: rotate(-4deg) scale(1.04); }
    80%  { transform: rotate(4deg) scale(1.02); }
    90%  { transform: rotate(-2deg) scale(1.01); }
    100% { transform: rotate(0deg) scale(1); }
}
@keyframes badgePulse {
    0%,100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239,68,68,0.7); }
    50%     { transform: scale(1.25); box-shadow: 0 0 0 5px rgba(239,68,68,0); }
}
.notif-bell.live-ring {
    animation: bellShake 0.7s ease forwards;
    border-color: var(--accent-orange) !important;
    box-shadow: 0 0 0 3px rgba(245,158,11,0.3), 0 4px 14px rgba(245,158,11,0.4) !important;
}
.notif-badge.on {
    animation: badgePulse 1.4s ease infinite;
}
/* When panel opens — themed header glow */
.notif-panel.show {
    border-color: rgba(245,158,11,0.4) !important;
    box-shadow: 0 18px 45px rgba(0,0,0,0.5), 0 0 0 1.5px rgba(245,158,11,0.3) !important;
}
.notif-panel.show .notif-panel-hdr {
    background: linear-gradient(90deg, rgba(220,38,38,0.12), rgba(245,158,11,0.08));
    border-radius: 8px 8px 0 0;
}



/* Light theme table & card text fix */
[data-theme="light"] .donor-table td { color: #0b1120 !important; }
[data-theme="light"] .dc-name { color: #0b1120 !important; }
[data-theme="light"] .dc-loc, [data-theme="light"] .dc-last { color: #2e4060 !important; }
[data-theme="light"] input, [data-theme="light"] select, [data-theme="light"] textarea {
    color: #0b1120 !important; background: #e8eeff !important;
}
[data-theme="light"] input::placeholder, [data-theme="light"] textarea::placeholder { color: #2e4060 !important; }

/* Notification Bell */
.notif-bell-wrap { position:relative; display:inline-flex; align-items:center; }
.notif-bell {
    background:rgba(255,255,255,0.07); border:1.5px solid rgba(255,255,255,0.12);
    font-size:1.2rem; cursor:pointer; border-radius:50%; width:42px; height:42px;
    display:flex; align-items:center; justify-content:center;
    transition:transform 0.3s,box-shadow 0.3s,border-color 0.3s;
    color:var(--text-main); padding:0; margin:0 2px;
    box-shadow:0 2px 8px rgba(0,0,0,0.2); position:relative;
}
.notif-bell:hover { transform:scale(1.1) rotate(12deg); box-shadow:0 4px 14px rgba(245,158,11,0.4); border-color:var(--accent-orange); }
.notif-bell.ring { animation:bRing 0.4s ease 0s 5 alternate; }
@keyframes bRing { 0%{transform:rotate(-12deg);}100%{transform:rotate(12deg);} }
.notif-badge {
    position:absolute; top:-5px; right:-5px;
    background:var(--danger); color:#fff; font-size:0.55em; font-weight:800;
    min-width:17px; height:17px; border-radius:50%;
    display:none; align-items:center; justify-content:center;
    border:2px solid var(--bg-main);
}
.notif-badge.on { display:flex; }

/* Notification Panel anchor — sits at body level to escape stacking context */
.notif-panel-anchor {
    position: fixed;
    top: 76px;
    right: 0;
    z-index: 10050; /* above mobile-bottom-nav(9999) */
    pointer-events: none;
}
.notif-panel-anchor .notif-panel {
    position: relative;
    top: 0;
    right: 0;
    pointer-events: all;
    margin: 4px 12px 0 0;
}

/* Notification Panel */
.notif-panel {
    background:var(--bg-card); border:1px solid var(--border-color);
    border-radius:var(--radius-lg); width:290px; max-height:420px; overflow-y:auto;
    overflow-x: hidden;
    box-shadow:0 18px 45px rgba(0,0,0,0.55); backdrop-filter:blur(20px);
    -webkit-backdrop-filter:blur(20px); z-index:9100; display:none; padding:10px;
    scroll-behavior: smooth;
    scrollbar-width: thin;
    scrollbar-color: rgba(220,38,38,0.4) transparent;
}
.notif-panel::-webkit-scrollbar { width: 4px; }
.notif-panel::-webkit-scrollbar-track { background: transparent; }
.notif-panel::-webkit-scrollbar-thumb { background: rgba(220,38,38,0.4); border-radius: 4px; }
.notif-panel.show { display:block; animation:fadeIn 0.2s ease; }
.notif-panel-hdr { font-weight:700; font-size:0.85em; color:var(--text-main);
    padding:5px 8px 10px; border-bottom:1px solid var(--border-color); margin-bottom:6px;
    display:flex; justify-content:space-between; }
/* ── Notification 2-tab system ── */
.notif-tabs-hdr {
    display:flex; border-bottom:1px solid var(--border-color); flex-shrink:0;
}
.notif-tab-btn {
    flex:1; padding:9px 4px 8px; background:transparent; border:none;
    font-size:0.78em; font-weight:700; color:var(--text-muted); cursor:pointer;
    border-bottom:2px solid transparent; transition:color 0.15s, border-color 0.15s;
    position:relative; white-space:nowrap; min-height:unset; box-shadow:none; margin:0;
    border-radius:0 !important;
}
.notif-tab-btn.active { color:var(--primary-red); border-bottom-color:var(--primary-red); }
.notif-tab-badge {
    display:inline-block; background:var(--primary-red); color:#fff;
    border-radius:10px; font-size:0.72em; padding:1px 5px; margin-left:4px;
    font-weight:800; vertical-align:middle;
}
.notif-panel-subhdr {
    font-weight:700; font-size:0.82em; color:var(--text-main);
    padding:7px 8px 8px; border-bottom:1px solid var(--border-color); margin-bottom:4px;
    display:flex; justify-content:space-between;
}
/* Service notification rows — modern swipeable */
.svc-notif-row {
    position:relative; overflow:hidden;
    padding:10px 12px; border-radius:12px; margin-bottom:6px;
    border:1px solid var(--border-color); background:var(--input-bg);
    display:flex; align-items:flex-start; gap:10px;
    transition:transform 0.25s ease, opacity 0.25s ease, max-height 0.3s ease;
    max-height:200px; touch-action:pan-y;
}
.svc-notif-row.unread {
    border-color:rgba(59,130,246,0.35);
    background:rgba(59,130,246,0.06);
    box-shadow:0 0 0 1px rgba(59,130,246,0.15);
}
.svc-notif-row.swiping-out {
    transform:translateX(110%);
    opacity:0;
    max-height:0;
    padding:0;
    margin:0;
    border-width:0;
    pointer-events:none;
}
.svc-notif-icon { font-size:1.25em; flex-shrink:0; line-height:1.4; margin-top:1px; }
.svc-notif-body { flex:1; min-width:0; }
.svc-notif-msg { font-size:0.82em; color:var(--text-main); line-height:1.55; word-break:break-word; }
.svc-notif-time { font-size:0.7em; color:var(--text-muted); margin-top:4px; }
.svc-notif-actions { display:flex; flex-direction:column; gap:4px; flex-shrink:0; align-self:center; }
.svc-notif-read-btn {
    background:transparent; border:1px solid var(--border-color);
    color:var(--text-muted); font-size:0.65em; font-weight:600; border-radius:8px;
    padding:4px 8px; cursor:pointer; min-height:unset; box-shadow:none; margin:0;
    white-space:nowrap; transition:all 0.15s; line-height:1.4;
}
.svc-notif-read-btn:hover { color:var(--success); border-color:var(--success); }
.svc-notif-del-btn {
    background:transparent; border:1px solid rgba(220,38,38,0.2);
    color:rgba(220,38,38,0.5); font-size:0.65em; border-radius:8px;
    padding:4px 8px; cursor:pointer; min-height:unset; box-shadow:none; margin:0;
    white-space:nowrap; transition:all 0.15s; line-height:1.4;
}
.svc-notif-del-btn:hover { color:var(--danger); border-color:var(--danger); }
/* Delete all + swipe hint bar */
.svc-notif-toolbar {
    display:flex; align-items:center; justify-content:space-between;
    padding:4px 2px 8px;
}
.svc-notif-hint { font-size:0.7em; color:var(--text-muted); }
.svc-delete-all-btn {
    background:rgba(220,38,38,0.08); border:1px solid rgba(220,38,38,0.2);
    color:var(--danger); font-size:0.72em; font-weight:700; border-radius:20px;
    padding:4px 12px; cursor:pointer; min-height:unset; box-shadow:none; margin:0;
    transition:all 0.15s;
}
.svc-delete-all-btn:hover { background:rgba(220,38,38,0.15); }
.notif-row { padding:9px; border-radius:10px; cursor:pointer; transition:background 0.12s; margin-bottom:3px; display:flex; align-items:flex-start; justify-content:space-between; gap:6px; }
.notif-row:hover { background:rgba(220,38,38,0.08); }
.notif-row-grp { font-size:1.3em; font-weight:900; color:var(--primary-red); font-family:var(--font-heading); }
.notif-row-info { font-size:0.78em; color:var(--text-muted); margin-top:2px; line-height:1.45; }
.notif-row-left { flex:1; min-width:0; }
.notif-mark-btn {
    flex-shrink:0;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    color: var(--text-muted);
    font-size: 0.68em;
    font-weight: 600;
    border-radius: 8px;
    padding: 4px 8px;
    cursor: pointer;
    white-space: nowrap;
    min-height: unset;
    width: auto;
    box-shadow: none;
    margin: 0;
    line-height: 1.4;
    transition: all 0.15s;
}
.notif-mark-btn:hover { background: rgba(16,185,129,0.15); border-color: #10b981; color: #10b981; }
.notif-panel-mark-all {
    width: 100%;
    background: transparent;
    border: 1px solid var(--border-color);
    color: var(--text-muted);
    font-size: 0.75em;
    font-weight: 600;
    border-radius: 8px;
    padding: 7px;
    cursor: pointer;
    margin-top: 8px;
    min-height: unset;
    box-shadow: none;
    transition: all 0.15s;
}
.notif-panel-mark-all:hover { background: rgba(16,185,129,0.1); border-color: #10b981; color: #10b981; }
.notif-empty { text-align:center; padding:25px; color:var(--text-muted); font-size:0.84em; }

/* Live Toast */
#toastWrap { position:fixed; bottom:18px; right:16px; z-index:99999;
    display:flex; flex-direction:column; gap:8px;
    max-width:320px; width:calc(100% - 32px); pointer-events:none; }
.toast-item {
    background:var(--bg-card); border:1px solid rgba(220,38,38,0.3);
    border-left:4px solid var(--danger); border-radius:14px;
    padding:12px 13px; box-shadow:0 8px 28px rgba(0,0,0,0.45);
    backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px);
    pointer-events:all; display:flex; align-items:flex-start; gap:10px;
    animation:tIn 0.32s cubic-bezier(0.34,1.5,0.64,1);
}
.toast-item.bye { animation:tOut 0.26s ease forwards; }
@keyframes tIn  { from{transform:translateX(110%);opacity:0;} to{transform:translateX(0);opacity:1;} }
@keyframes tOut { to{transform:translateX(110%);opacity:0;} }
.toast-ico { font-size:1.6rem; flex-shrink:0; line-height:1; }
.toast-bd { flex:1; min-width:0; }
.toast-ttl { font-weight:700; font-size:0.86em; color:var(--danger); margin-bottom:2px; font-family:var(--font-heading); }
.toast-sub { font-size:0.77em; color:var(--text-muted); line-height:1.4; }
.toast-x { background:none; border:none; color:var(--text-muted); font-size:1rem; cursor:pointer;
    padding:0; margin:0; width:auto; min-height:unset; flex-shrink:0; line-height:1; }
.toast-x:hover { color:var(--text-main); transform:none; box-shadow:none; }
@media(max-width:767px){
    #toastWrap { bottom:82px; right:8px; max-width:calc(100% - 16px); }
    .notif-panel { width: calc(100vw - 24px); }
    .notif-panel-anchor { right: 0; }
}

/* Smart Suggestion Box */
.sug-wrap { position:relative; }
.sug-list {
    position:absolute; top:100%; left:0; right:0; z-index:300;
    background:var(--bg-card); border:1px solid var(--border-color); border-top:none;
    border-radius:0 0 var(--radius-md) var(--radius-md);
    max-height:200px; overflow-y:auto;
    box-shadow:0 10px 28px rgba(0,0,0,0.35);
    backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); display:none;
}
.sug-list.on { display:block; }
.sug-opt {
    padding:8px 12px; cursor:pointer; font-size:0.85em; color:var(--text-main);
    border-bottom:1px solid var(--border-color);
    display:flex; justify-content:space-between; align-items:center; gap:8px;
    transition:background 0.1s;
}
.sug-opt:last-child { border-bottom:none; }
.sug-opt:hover, .sug-opt.act { background:rgba(220,38,38,0.08); color:var(--primary-red); }
.sug-opt mark { background:transparent; color:var(--primary-red); font-weight:800; }
.sug-cat { font-size:0.68em; color:var(--text-muted); background:var(--input-bg); padding:1px 6px; border-radius:8px; white-space:nowrap; flex-shrink:0; }
[data-theme="light"] .sug-list { background:#fff; }
[data-theme="light"] .sug-opt { color:#0b1120; }

/* WhatsApp button */
.wa-btn {
    background:linear-gradient(135deg,#25D366,#0f9e4e) !important;
    color:#fff !important; border:none !important;
    display:inline-flex !important; align-items:center; justify-content:center; gap:5px;
}
.wa-btn:hover { box-shadow:0 6px 20px rgba(37,211,102,0.5) !important; transform:translateY(-2px) !important; }


/* ============================================================
   PERFORMANCE: containment, GPU promotion, paint isolation
   ============================================================ */
.stats-grid, .cards-grid, .req-grid, .kpi-grid, .charts-grid { contain: layout style; }
.tab-content { contain: layout; }
.dc { contain: layout style; }
.stat-card { contain: layout style; }
.kpi-card { contain: layout style; }
img { decoding: async; }

/* ── SCROLL & LAYOUT PERFORMANCE ── */
html { scrollbar-gutter: stable; scroll-padding-top: 130px; }
.quick-shift-container, .donor-table-wrapper, .scroll-content {
    -webkit-overflow-scrolling: touch;
    scroll-behavior: smooth;
}
.tab-btn:not(.active):hover { opacity: 0.85; }

/* ============================================================
   FIXED HEADER ONLY — nav bar removed, header is the only fixed element
   ============================================================ */

/* ── GPU & PAINT ISOLATION ── */
.dc, .stat-card, .kpi-card, .req-card, .nearby-card, .footer-card {
    contain: layout style;
    transform: translateZ(0);
}
/* Below-fold sections — skip rendering until near viewport (desktop only) */
@media(min-width: 651px) {
#analyticsSection, #mapSection {
    content-visibility: auto;
    contain-intrinsic-size: 0 600px;
}
}
/* Compositor layer for fixed header */
header { isolation: isolate; }
/* Prevent subpixel jank on text during scroll */
.section-title, h1, h2, h3 { text-rendering: optimizeSpeed; }
html { scroll-padding-top: 92px; }

/* ============================================================
   APP-MODE: PAGE SWITCHING SYSTEM with smooth animations
   ============================================================ */
.app-page {
    display: none;
    min-height: calc(100vh - 110px);
    opacity: 0;
}
.app-page.page-active {
    display: block;
    animation: pageSlideIn 0.28s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
}
.app-page.page-exit {
    animation: pageSlideOut 0.2s ease forwards;
}
@keyframes pageSlideIn {
    from { opacity: 0; transform: translateY(12px) scale(0.99); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes pageSlideOut {
    from { opacity: 1; transform: translateY(0); }
    to   { opacity: 0; transform: translateY(-8px); }
}

/* On desktop: show all as normal scroll page */
@media(min-width: 651px) {
    .app-page { display: block !important; opacity: 1 !important; animation: none !important; }
    .mobile-bottom-nav { display: none !important; }
    .app-page-header { display: none !important; }
}
/* On mobile: show the bottom nav bar (FIXED, never scrolls away) */
@media(max-width: 650px) {
    .mobile-bottom-nav { display: flex !important; }
    /* Push page content up so it never hides behind the bottom nav */
    body { padding-bottom: calc(64px + env(safe-area-inset-bottom, 0px)); }
    .app-page { min-height: calc(100vh - 110px - 64px); padding-bottom: 8px; }
    /* Toast should sit above bottom nav */
    #toastWrap { bottom: calc(80px + env(safe-area-inset-bottom, 0px)) !important; }
    /* Notification prompt above bottom nav */
    /* notif-prompt: own media query above */
    /* Footer only shows on desktop; developer cards are inside page-home on mobile */
    .site-footer { display: none !important; }
}

/* ============================================================
   APP PAGE HEADER (title bar for each page on mobile)
   ============================================================ */
.app-page-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    font-family: var(--font-heading);
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--text-main);
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-main);
    position: sticky;
    top: 76px; /* sticks just under the fixed header */
    z-index: 30; /* below header(50) and notif panel(9100) */
    margin-bottom: 0;
}
.app-page-header .ph-icon { font-size: 1.25rem; }
.app-version-badge {
    margin-left: auto;
    font-size: 0.58em;
    font-weight: 600;
    color: var(--text-muted);
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 2px 8px;
    letter-spacing: 0.5px;
    font-family: var(--font-body);
    opacity: 0.7;
}

/* ============================================================
   COMPACT DONOR CARDS — grid layout, big blood badge, all info
   ============================================================ */
.dc {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    margin-bottom: 4px;
    overflow: hidden;
    position: relative;
    transform: translateZ(0);
    display: grid;
    grid-template-columns: 46px 1fr 40px;
    align-items: stretch;
    gap: 0;
    contain: layout style;
    transition: background 0.1s;
}
.dc:active { background: rgba(128,128,128,0.05); }
.dc-top { display: contents; }
.dc-top-left { display: contents; }
.dc-body { display: contents; }
.dc-meta { display: contents; }
.dc-top, .dc-body { padding: 0 !important; border: none !important; }

/* Left: blood group badge column */
.dc-badge-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    align-self: stretch;
    background: rgba(0,0,0,0.08);
    border-right: 1px solid var(--border-color);
    padding: 4px 2px;
    gap: 2px;
}
[data-theme="light"] .dc-badge-wrap { background: rgba(0,0,0,0.04); }

/* Serial number above blood group in card */
.dc-sn {
    font-size: 0.72em;
    font-weight: 800;
    color: var(--text-muted);
    line-height: 1;
    text-align: center;
    opacity: 0.9;
    display: block;
    letter-spacing: 0.5px;
}
.dc-badge {
    font-size: 0.72em !important;
    padding: 5px 2px !important;
    border-radius: 7px !important;
    font-weight: 900 !important;
    letter-spacing: 0.2px;
    display: block;
    width: 40px;
    text-align: center;
    line-height: 1.15;
    word-break: break-all;
}

/* Middle: info column */
.dc-serial { display: none; }
.dc-info {
    padding: 5px 7px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 1px;
    min-width: 0;
}
.dc-name {
    font-weight: 700;
    font-size: calc(0.80em * var(--dc-zoom));
    color: var(--text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}
.dc-status-badge {
    font-size: calc(0.56em * var(--dc-zoom)) !important;
    padding: 1px 5px !important;
    border-radius: 20px !important;
    display: inline-block !important;
    font-weight: 600 !important;
    white-space: nowrap;
    align-self: flex-start;
    margin-top: 1px;
}
.dc-loc {
    font-size: calc(0.64em * var(--dc-zoom));
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.15;
}
.dc-last {
    font-size: calc(0.59em * var(--dc-zoom));
    color: var(--text-muted);
    line-height: 1.1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Right: call button column — full height tap target */
.dc-call-btn {
    position: static !important;
    transform: none !important;
    align-self: stretch;
    width: 40px !important;
    height: auto !important;
    min-height: 52px !important;
    border-radius: 0 10px 10px 0 !important;
    background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%) !important;
    color: #fff !important;
    font-size: 1.1em;
    display: flex !important;
    align-items: center;
    justify-content: center;
    border: none !important;
    border-left: 1px solid rgba(255,255,255,0.08) !important;
    padding: 0 !important;
    margin: 0 !important;
    box-shadow: none !important;
    cursor: pointer;
    transition: filter 0.1s;
    line-height: 1;
    -webkit-tap-highlight-color: transparent;
    -webkit-appearance: none;
}
.dc-call-btn:active { filter: brightness(0.85) !important; }

/* Skeleton card */
.dc-skeleton { min-height: 52px; }

/* ============================================================
   MOBILE APP-LIKE BOTTOM NAVIGATION BAR
   ============================================================ */
/* ── Bottom Navigation Bar ── */
.mobile-bottom-nav {
    display: none;
    position: fixed !important;
    bottom: 0 !important;
    left: 0 !important;
    right: 0 !important;
    top: auto !important;
    z-index: 9999 !important;
    background: var(--bg-card);
    border-top: 1px solid rgba(255,255,255,0.06);
    box-shadow: 0 -8px 32px rgba(0,0,0,0.5), 0 -1px 0 rgba(255,255,255,0.04);
    padding-bottom: env(safe-area-inset-bottom, 0px);
    backdrop-filter: blur(28px);
    -webkit-backdrop-filter: blur(28px);
    transform: none !important;
    will-change: auto;
    -webkit-transform: translateZ(0);
    backface-visibility: hidden;
}
.mobile-bottom-nav-inner {
    display: flex;
    align-items: center;
    height: 64px;
    padding: 0 4px;
    gap: 2px;
    width: 100%;
}
.mbn-item {
    flex: 1 1 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    cursor: pointer;
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-size: 0.68rem;
    font-weight: 600;
    padding: 5px 2px;
    min-height: unset;
    width: auto;
    box-shadow: none;
    border-radius: 14px;
    margin: 4px 0;
    transition: color 0.18s ease, background 0.18s ease;
    -webkit-tap-highlight-color: transparent;
    position: relative;
    letter-spacing: 0px;
    overflow: visible;
}
/* pill wrapper for icon */
.mbn-pill {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 26px;
    border-radius: 13px;
    background: transparent;
    transition: background 0.22s ease, box-shadow 0.22s ease;
    flex-shrink: 0;
}
.mbn-item .mbn-icon {
    width: 20px;
    height: 20px;
    display: block;
    flex-shrink: 0;
    transition: transform 0.2s cubic-bezier(0.34,1.56,0.64,1);
    stroke: currentColor;
    fill: none;
}
.mbn-item span:last-child {
    font-size: 1em;
    display: block;
    white-space: nowrap;
    max-width: 100%;
    line-height: 1;
    text-align: center;
}
/* ── Active state ── */
.mbn-item.mbn-active {
    color: #16a34a;
}
.mbn-item.mbn-active .mbn-pill {
    background: rgba(22,163,74,0.13);
    box-shadow: 0 0 12px rgba(22,163,74,0.25), inset 0 0 0 1px rgba(22,163,74,0.18);
}
.mbn-item.mbn-active .mbn-icon {
    transform: scale(1.12);
    stroke: #16a34a;
}
.mbn-item.mbn-active::before { display: none; }
.mbn-item.mbn-active::after  { display: none; }
/* ── Hover / tap feedback ── */
.mbn-item:active .mbn-pill {
    background: rgba(22,163,74,0.1);
    transform: scale(0.95);
}
/* ── Blood Request bottom sheet animation ── */
#bloodReqModal { transition: opacity 0.15s ease, visibility 0.15s ease !important; }
#bloodReqModal.active #bloodReqSheet { transform: translateY(0) !important; }
.req-group-btn.selected {
    background: rgba(220,38,38,0.15) !important;
    border-color: #ef4444 !important;
    color: #ef4444 !important;
    box-shadow: 0 0 0 2px rgba(220,38,38,0.2) !important;
}
[data-theme="light"] .req-group-btn.selected {
    background: rgba(220,38,38,0.08) !important;
}

/* ── Center emergency button — REMOVED ── */

/* Settings panel */
/* ============================================================
   FAQ ACCORDION STYLES
   ============================================================ */
.faq-item { border:1px solid var(--border-color); border-radius:12px; margin-bottom:8px; overflow:hidden; transition:border-color 0.2s; }
.faq-q { display:flex; align-items:center; justify-content:space-between; padding:13px 16px; cursor:pointer; font-size:0.88em; font-weight:600; color:var(--text-main); background:var(--input-bg); gap:10px; user-select:none; -webkit-user-select:none; }
.faq-q:active { opacity:0.8; }
.faq-arrow { font-size:1.2em; color:var(--text-muted); transition:transform 0.25s cubic-bezier(0.34,1.56,0.64,1); flex-shrink:0; font-weight:400; }
.faq-a { display:none; padding:0 16px; background:var(--bg-card); }
.faq-a.open { display:block; padding:12px 16px 14px; }
.faq-a p { font-size:0.83em; color:var(--text-muted); line-height:1.65; margin:0 0 6px; }
.faq-a p:last-child { margin-bottom:0; }
.faq-a strong { color:var(--text-main); }
.faq-open .faq-arrow { transform:rotate(90deg); color:var(--primary-red); }

.settings-panel-overlay {
    position: fixed; inset: 0; z-index: 9990;
    /* Sits exactly on top of the 64px nav bar — zero gap */
    bottom: calc(64px + env(safe-area-inset-bottom, 0px));
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    opacity: 0; visibility: hidden;
    transition: opacity 0.2s, visibility 0.2s;
}
.settings-panel-overlay.active { opacity: 1; visibility: visible; }
.settings-panel {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    background: var(--bg-card);
    border-radius: 20px 20px 0 0;
    padding: 0 0 0;
    transform: translateY(100%);
    transition: transform 0.3s cubic-bezier(0.34,1.1,0.64,1);
    /* Max height = viewport minus nav (64px) minus safe area */
    max-height: calc(100vh - 64px - env(safe-area-inset-bottom, 0px));
    display: flex;
    flex-direction: column;
}
/* The scrollable content area inside settings */
.settings-panel .settings-list {
    flex: 1 1 auto;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(220,38,38,0.3) transparent;
    padding: 8px 0 20px;
}
.settings-panel .settings-list::-webkit-scrollbar { width: 3px; }
.settings-panel .settings-list::-webkit-scrollbar-thumb { background: rgba(220,38,38,0.3); border-radius: 4px; }
.settings-panel-overlay.active .settings-panel {
    transform: translateY(0);
}
.settings-panel-handle {
    width: 40px; height: 4px;
    background: rgba(128,128,128,0.3);
    border-radius: 4px;
    margin: 12px auto 0;
}
.settings-panel-title {
    font-family: var(--font-heading);
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text-main);
    padding: 14px 20px 10px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.settings-title-actions {
    display: flex; align-items: center; gap: 8px;
}
.settings-close-btn, .settings-reload-btn {
    background: rgba(255,255,255,0.07);
    border: 1.5px solid rgba(255,255,255,0.15);
    color: var(--text-muted);
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.95rem;
    cursor: pointer;
    transition: background 0.15s, color 0.15s, transform 0.2s;
    flex-shrink: 0;
    min-height: unset !important;
    padding: 0 !important;
    margin: 0 !important;
    box-shadow: none !important;
    line-height: 1;
}
.settings-close-btn:hover {
    background: rgba(220,38,38,0.15);
    border-color: rgba(220,38,38,0.4);
    color: var(--primary-red);
    transform: rotate(90deg);
}
.settings-reload-btn:hover {
    background: rgba(59,130,246,0.15);
    border-color: rgba(59,130,246,0.4);
    color: #3b82f6;
    transform: rotate(360deg);
}
.settings-reload-btn.spinning { animation: spinOnce 0.5s linear; }
@keyframes spinOnce { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
[data-theme="light"] .settings-close-btn,
[data-theme="light"] .settings-reload-btn {
    background: rgba(0,0,0,0.05);
    border-color: rgba(0,0,0,0.12);
}
/* ── PWA Install Prompt ── */
/* ── PWA Install Banner (Top) ── */
#pwaInstallOverlay {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 10500;
    display: flex;
    justify-content: center;
    transform: translateY(-110%);
    transition: transform 0.4s cubic-bezier(0.34,1.26,0.64,1);
    pointer-events: none;
}
#pwaInstallOverlay.show {
    transform: translateY(0);
    pointer-events: auto;
}
#pwaInstallBox {
    background: #13161f;
    border: 1px solid var(--border-color);
    border-top: none;
    border-radius: 0 0 20px 20px;
    width: 100%; max-width: 540px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.75);
    overflow: hidden;
}
[data-theme="light"] #pwaInstallBox {
    background: #ffffff;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
}
.pwa-handle { display: none; }
.pwa-install-inner {
    padding: 14px 18px 16px;
}
/* Compact single-row layout */
.pwa-top-row {
    display: flex; align-items: center; gap: 12px;
}
.pwa-app-icon {
    width: 44px; height: 44px; border-radius: 11px;
    object-fit: cover;
    box-shadow: 0 3px 10px rgba(0,0,0,0.25);
    flex-shrink: 0;
}
.pwa-install-titles { flex: 1; min-width: 0; }
.pwa-install-titles strong {
    display: block; font-size: 0.92rem; font-weight: 700;
    color: var(--text-main); font-family: var(--font-heading);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pwa-install-titles span {
    font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 1px;
}
.pwa-top-btns {
    display: flex; gap: 8px; flex-shrink: 0;
}
.pwa-install-btn {
    background: linear-gradient(135deg, #e02424, #b91c1c);
    color: #fff; border: none; border-radius: 10px;
    padding: 9px 16px; font-size: 0.85rem; font-weight: 700;
    cursor: pointer; font-family: var(--font-heading);
    box-shadow: 0 3px 10px rgba(220,38,38,0.35);
    transition: transform 0.15s, box-shadow 0.15s;
    white-space: nowrap;
}
.pwa-install-btn:active { transform: scale(0.96); }
.pwa-dismiss-btn {
    background: transparent;
    color: var(--text-muted); border: 1px solid var(--border-color);
    border-radius: 10px; padding: 9px 12px;
    font-size: 0.82rem; font-weight: 600; cursor: pointer;
    white-space: nowrap;
}
/* Features pills — below the row */
.pwa-install-desc {
    font-size: 0.8rem; color: var(--text-muted);
    margin: 8px 0 0; line-height: 1.5;
}
.pwa-features {
    display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;
}
.pwa-feat-pill {
    background: rgba(220,38,38,0.1);
    border: 1px solid rgba(220,38,38,0.2);
    color: var(--primary-red);
    border-radius: 20px; padding: 3px 9px;
    font-size: 0.72rem; font-weight: 600;
}
/* iOS steps */
.pwa-ios-steps {
    background: rgba(59,130,246,0.08);
    border: 1px solid rgba(59,130,246,0.2);
    border-radius: 10px; padding: 10px 12px;
    font-size: 0.8rem; color: var(--text-muted);
    line-height: 1.65; margin-top: 10px;
}
.pwa-ios-steps strong { color: var(--text-main); }
.pwa-btn-row { display: flex; gap: 8px; margin-top: 10px; }
.pwa-btn-row .pwa-dismiss-btn { flex: 1; }
@media (max-width: 650px) {
    .pwa-install-inner { padding: 12px 14px 14px; }
    .pwa-app-icon { width: 38px; height: 38px; }
    .pwa-install-btn { padding: 8px 13px; font-size: 0.82rem; }
    .pwa-dismiss-btn { padding: 8px 10px; }
}
/* ── Offline Alert Banner ── */
#offlineAlert {
    position: fixed; top: 0; left: 0; right: 0; z-index: 10600;
    background: linear-gradient(90deg, #dc2626, #b91c1c);
    color: #fff;
    font-size: 0.82rem;
    font-weight: 700;
    text-align: center;
    padding: 8px 16px;
    display: none;
    align-items: center;
    justify-content: center;
    gap: 8px;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 12px rgba(220,38,38,0.4);
}
#offlineAlert.show { display: flex; animation: slideDownAlert 0.3s ease; }
@keyframes slideDownAlert {
    from { transform: translateY(-100%); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}
.offline-retry-btn {
    background: rgba(255,255,255,0.25);
    border: 1px solid rgba(255,255,255,0.5);
    color: #fff;
    border-radius: 12px;
    padding: 2px 10px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    min-height: unset !important;
    margin: 0 !important;
    transition: background 0.15s;
}
.offline-retry-btn:hover { background: rgba(255,255,255,0.4); }
.settings-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid rgba(128,128,128,0.08);
    cursor: pointer;
    transition: background 0.1s;
    -webkit-tap-highlight-color: transparent;
}
.settings-item:active { background: rgba(128,128,128,0.07); }
.settings-item:last-child { border-bottom: none; }
.settings-item-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.settings-item-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.si-theme .settings-item-icon { background: rgba(245,158,11,0.15); }
.si-notif .settings-item-icon  { background: rgba(59,130,246,0.15); }
.si-sound .settings-item-icon  { background: rgba(16,185,129,0.15); }
.si-loc .settings-item-icon    { background: rgba(220,38,38,0.15); }
.si-about .settings-item-icon  { background: rgba(139,92,246,0.15); }
.si-terms .settings-item-icon  { background: rgba(107,114,128,0.15); }
.si-faq .settings-item-icon    { background: rgba(234,179,8,0.15); }
.si-install .settings-item-icon { background: rgba(220,38,38,0.15); }
.si-faq    .settings-item-icon { background: rgba(234,179,8,0.15); }
.si-clear  .settings-item-icon { background: rgba(239,68,68,0.12); }
.si-zoom .settings-item-icon   { background: rgba(6,182,212,0.15); }

/* Zoom stepper widget in settings */
.zoom-stepper {
    display: flex;
    align-items: center;
    gap: 0;
    background: var(--input-bg);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    overflow: hidden;
}
.zoom-btn {
    width: 34px; height: 30px;
    display: flex; align-items: center; justify-content: center;
    background: transparent;
    border: none; cursor: pointer;
    font-size: 1.1em; font-weight: 700;
    color: var(--text-main);
    transition: background 0.12s;
    flex-shrink: 0;
    min-height: unset !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}
.zoom-btn:active { background: rgba(6,182,212,0.15); }
.zoom-val {
    font-size: 0.72em; font-weight: 800;
    color: #06b6d4;
    min-width: 38px; text-align: center;
    font-family: var(--font-heading);
    pointer-events: none;
    border-left: 1px solid var(--border-color);
    border-right: 1px solid var(--border-color);
    height: 30px; line-height: 30px;
}
.settings-item-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.settings-item-label {
    font-size: 0.92em;
    font-weight: 700;
    color: var(--text-main);
}
.settings-item-sub {
    font-size: 0.74em;
    color: var(--text-muted);
}
.settings-item-right {
    color: var(--text-muted);
    font-size: 0.85em;
}
/* Toggle switch */
.settings-toggle {
    width: 46px; height: 26px;
    background: rgba(128,128,128,0.25);
    border-radius: 13px;
    position: relative;
    transition: background 0.2s;
    flex-shrink: 0;
}
.settings-toggle::after {
    content: '';
    position: absolute;
    width: 20px; height: 20px;
    background: #fff;
    border-radius: 50%;
    top: 3px; left: 3px;
    transition: transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 1px 4px rgba(0,0,0,0.25);
}
.settings-toggle.on {
    background: var(--primary-red);
}
.settings-toggle.on::after {
    transform: translateX(20px);
}

/* On mobile: show bottom nav + use page system */
@media(max-width: 650px) {
    .mobile-bottom-nav { display: block; }
    body { padding-top: 122px; padding-bottom: calc(58px + env(safe-area-inset-bottom, 0px)); }

    /* Mobile navigation — bottom nav handles pages */

    /* Stats grid compact */
    .stats-container {
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 7px !important;
        padding: 0 8px !important;
        margin: 12px auto 16px !important;
    }
    .stat-card { padding: 9px 4px !important; }
    .stat-card h4 { font-size: 1.05em !important; }
    .stat-card .count { font-size: 0.65em !important; }

    /* Mobile kpi 2x2 */
    .kpi-grid { grid-template-columns: repeat(2, 1fr) !important; }
    .charts-grid { grid-template-columns: 1fr !important; }

    /* Pagination */
    .pagination a { min-width: 34px; height: 34px; font-size: 0.8em; padding: 0 8px; }

    /* Emergency banner */
    .emergency-banner { flex-direction: column; gap: 10px; }
    .emergency-banner-btns { width: 100%; flex-direction: row; }
    .btn-emergency, .btn-view-requests { flex: 1; text-align: center; font-size: 0.82em; padding: 8px 8px; }

    /* Donor cards list padding */
    .donor-cards-container { padding: 0 8px; }

    /* Hide desktop table on mobile */
    .donor-table-wrapper { display: none; }
}

/* Toast above bottom nav */
@media(max-width: 767px){
    #toastWrap { bottom: 82px; right: 8px; max-width: calc(100% - 16px); }
}

/* ============================================================
   HOME HERO BAR
   ============================================================ */
.home-hero-bar {
    display: flex;
    align-items: center;
    justify-content: space-around;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    margin: 10px 12px 0;
    padding: 12px 8px;
    box-shadow: var(--shadow-glass);
}
.home-hero-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    flex: 1;
}
.home-hero-num {
    font-family: var(--font-heading);
    font-size: 1.6rem;
    font-weight: 900;
    color: var(--primary-red);
    line-height: 1.1;
}
.home-hero-lbl {
    font-size: 0.62em;
    color: var(--text-muted);
    font-weight: 600;
    text-align: center;
}
.home-hero-divider {
    width: 1px;
    height: 36px;
    background: var(--border-color);
    flex-shrink: 0;
}
[data-theme="light"] .home-hero-bar {
    background: #fff;
    box-shadow: 0 2px 12px rgba(99,102,241,0.10);
}

[data-theme="light"] .quick-shift-container {
    background: #f0f4ff;
    border-bottom: 1px solid rgba(99,102,241,0.15);
}

/* ============================================================
   LIGHT MODE FIXES FOR NEW ELEMENTS
   ============================================================ */
[data-theme="light"] .mobile-bottom-nav {
    background: rgba(255,255,255,0.97);
    border-top: 1px solid rgba(80,110,200,0.15);
    box-shadow: 0 -2px 16px rgba(80,110,200,0.10);
}
[data-theme="light"] .mbn-item { color: #7a8599; }
[data-theme="light"] .mbn-item.mbn-active { color: #16a34a; }
[data-theme="light"] .mbn-item.mbn-active .mbn-pill {
    background: rgba(22,163,74,0.1);
    box-shadow: 0 0 10px rgba(22,163,74,0.2), inset 0 0 0 1px rgba(22,163,74,0.15);
}
[data-theme="light"] .settings-panel {
    background: #fff;
    border-top: 1px solid rgba(80,110,200,0.15);
}
[data-theme="light"] .settings-item { border-bottom: 1px solid rgba(80,110,200,0.08); }
[data-theme="light"] .settings-panel-title { color: #0b1120; border-bottom: 1px solid rgba(80,110,200,0.12); }
[data-theme="light"] .app-page-header {
    background: rgba(240,244,255,0.99);
    border-bottom: 1px solid rgba(80,110,200,0.15);
    color: #0b1120;
}
[data-theme="light"] .app-version-badge {
    background: rgba(80,110,200,0.08);
    border-color: rgba(80,110,200,0.2);
    color: #4338ca;
}
[data-theme="light"] .dc {
    background: #fff;
    border: 1px solid rgba(80,110,200,0.14);
}
[data-theme="light"] .dc:active { background: rgba(80,110,200,0.04); }

/* ============================================================
   BOTTOM NAV — scroll hint fade on right edge
   ============================================================ */
.mobile-bottom-nav::after {
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 24px;
    background: linear-gradient(to right, transparent, var(--bg-card));
    pointer-events: none;
    border-radius: 0 0 0 0;
}
[data-theme="light"] .mobile-bottom-nav::after {
    background: linear-gradient(to right, transparent, rgba(255,255,255,0.97));
}

/* ============================================================
   GPS PERMISSION PROMPT STYLE
   ============================================================ */
#gpsPermPrompt .popup { text-align: center; }
#gpsAllowBtn { font-size: 1em; font-weight: 700; }

/* ============================================================
   MAP PICKER MODAL
   ============================================================ */
#mapPickerModal .popup {
    padding: 0 !important;
    max-height: 90vh;
    width: 95%;
    max-width: 560px;
}

/* ============================================================
   DESKTOP IMPROVEMENTS
   ============================================================ */
@media(min-width: 651px) {
    /* Show last donation on all donors desktop */
    .dc-last { display: block !important; }
    
    /* Donor cards slightly bigger on desktop */
    .dc { grid-template-columns: 52px 1fr 44px; }
    .dc-badge { width: 46px; font-size: 0.8em !important; }
    .dc-call-btn { width: 44px !important; }

    /* Home hero bigger numbers on desktop */
    .home-hero-num { font-size: 2rem; }
    .home-hero-bar { margin: 20px auto 0; max-width: 1200px; }
    
    /* Quick filter pills bigger */
    .shift-btn { padding: 10px 22px; font-size: 0.9em; }
    
    /* Stats grid stay 4 cols */
    .stats-container { grid-template-columns: repeat(4, 1fr) !important; }
    
    /* Analytics charts better on desktop */
    .kpi-grid { grid-template-columns: repeat(3, 1fr) !important; }
    .charts-grid { grid-template-columns: 1fr 1fr !important; }
}

@media(min-width: 900px) {
    /* Wider donor cards layout on large screens */
    .donor-cards-container { display: none; }
    .donor-table-wrapper { display: block; }
}
@media(max-width: 899px) {
    .donor-table-wrapper { display: none; }
    .donor-cards-container { display: block; }
}


/* ============================================================
   PWA SPLASH SCREEN
   ============================================================ */
#pwaSplash {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: #08090f;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0;
    transition: opacity 0.45s ease, visibility 0.45s ease;
}
#pwaSplash.splash-hide {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.45s ease, visibility 0.45s ease;
}
#pwaSplash.splash-done {
    display: none !important; /* fully remove after fade so no z-index interference */
}
.splash-logo {
    width: 96px;
    height: 96px;
    border-radius: 22px;
    box-shadow: 0 8px 32px rgba(220,38,38,0.45);
    animation: splashLogoPop 0.55s cubic-bezier(0.34,1.56,0.64,1) both;
}
@keyframes splashLogoPop {
    from { transform: scale(0.5); opacity: 0; }
    to   { transform: scale(1);   opacity: 1; }
}
.splash-name {
    margin-top: 18px;
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 1.7rem;
    font-weight: 800;
    letter-spacing: 1px;
    color: #fff;
    animation: splashNameSlide 0.5s 0.2s cubic-bezier(0.34,1.4,0.64,1) both;
}
@keyframes splashNameSlide {
    from { transform: translateY(14px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}
.splash-name span { color: #ef4444; }
.splash-tagline {
    margin-top: 6px;
    font-size: 0.78rem;
    color: rgba(255,255,255,0.45);
    letter-spacing: 2px;
    text-transform: uppercase;
    animation: splashNameSlide 0.5s 0.35s cubic-bezier(0.34,1.4,0.64,1) both;
}
.splash-spinner {
    margin-top: 38px;
    width: 36px;
    height: 36px;
    animation: splashNameSlide 0.4s 0.5s ease both;
    opacity: 0.7;
    transition: animation-duration 0.15s linear;
}
/* Progress bar under gear */
.splash-progress-wrap {
    margin-top: 18px;
    width: 140px;
    height: 3px;
    background: rgba(255,255,255,0.12);
    border-radius: 10px;
    overflow: hidden;
    animation: splashNameSlide 0.4s 0.6s ease both;
}
.splash-progress-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #ef4444, #f59e0b);
    border-radius: 10px;
    transition: width 0.08s linear;
}
[data-theme="light"] .splash-progress-wrap { background: rgba(0,0,0,0.1); }
@keyframes gearSpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

/* ── Page transition loader (white flash fix) ── */
#pageLoader {
    position: fixed;
    inset: 0;
    z-index: 99998;
    background: #08090f;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.15s ease, visibility 0.15s ease;
}
#pageLoader.loader-show {
    opacity: 1;
    visibility: visible;
}
.page-loader-gear {
    width: 44px;
    height: 44px;
    opacity: 0.7;
    animation: gearSpin 1s linear infinite;
}
[data-theme="light"] #pwaSplash,
[data-theme="light"] #pageLoader { background: #f8fafc; }
[data-theme="light"] .splash-name { color: #0b1120; }
[data-theme="light"] .splash-tagline { color: rgba(0,0,0,0.4); }

</style>  
</head>  

<body>  

<!-- ══ PWA SPLASH SCREEN ══ -->
<div id="pwaSplash">
    <img src="icon.png" alt="Blood Arena" class="splash-logo" onerror="this.style.display='none'">
    <div class="splash-name">Blood <span>Solution</span></div>
    <div class="splash-tagline">SHSMC · রক্তদান পোর্টাল</div>
    <div class="splash-spinner" id="splashGear"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
  <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>
  <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>
</svg></div>
    <div class="splash-progress-wrap">
        <div class="splash-progress-fill" id="splashProgressFill"></div>
    </div>
</div>

<!-- ══ PAGE TRANSITION LOADER (white flash fix) ══ -->
<div id="pageLoader">
    <div class="page-loader-gear"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
  <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>
  <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>
</svg></div>
</div>

<audio id="successSound" src="https://www.myinstants.com/media/sounds/success-fanfare-trumpets.mp3" preload="auto"></audio>

<div class="location-blocked-overlay" id="locationBlockedOverlay">
    <div class="location-blocked-box">
        <div class="icon">📍</div>
        <h2>লোকেশন অনুমতি আবশ্যক</h2>
        <p>রেজিস্ট্রেশন করতে অথবা কল করতে আপনার বর্তমান লোকেশনের অনুমতি প্রয়োজন।<br><br>নিচের বাটনে ক্লিক করে লোকেশন অন করুন।</p>
        <button onclick="requestLocationAgain()">📍 লোকেশন অন করুন</button>
    </div>
</div>

<div class="popup-overlay" id="popup">
    <div class="popup">
        <div id="popupIcon" class="tick"></div>
        <h2 id="popupTitle" style="color:var(--text-main); margin-bottom: 12px; font-family: var(--font-heading); font-weight: 600;"></h2>
        <p id="popupMsg" style="color:var(--text-muted); line-height:1.6; font-size: 0.95em;"></p>
        <div id="successNotice" style="display:none; margin:20px 0; padding:15px; background: rgba(245, 158, 11, 0.05); border-radius: var(--radius-md); border:1px solid rgba(245, 158, 11, 0.2); font-size:0.9em; color:var(--accent-orange); text-align: left;">
            <strong style="display:block; margin-bottom:5px; color:var(--text-main);">✅ গুরুত্বপূর্ণ নোটিশ:</strong>
            Secret Code সংরক্ষণ করুন। এটি দিয়ে পরে আপনি তথ্য আপডেট করতে পারবেন।
        </div>
        <button id="popupOkBtn" onclick="closePopup()" class="countdown-btn" disabled>OK (5)</button>
    </div>
</div>

<div class="popup-overlay" id="callerInfoPopup">
    <div class="popup">
        <h2 style="color:var(--info); margin-bottom:10px; font-family:var(--font-heading);">আপনার তথ্য দিন</h2>
        <p style="font-size:0.9em; color:var(--text-muted); margin-bottom:20px;">দাতার নিরাপত্তা ও লগের জন্য কল করার আগে আপনার নাম ও মোবাইল নম্বর প্রদান করুন।</p>
        <input type="text" id="inputCallerName" placeholder="আপনার পূর্ণ নাম" required>
        <input type="text" id="inputCallerPhone" placeholder="আপনার মোবাইল নম্বর" value="+8801" required>
        <div style="display:flex; gap:12px; margin-top: 20px;">
            <button onclick="document.getElementById('callerInfoPopup').classList.remove('active')" style="background:transparent; border:1px solid var(--border-color); color:var(--text-main); box-shadow:none;">Cancel</button>
            <button onclick="submitCallerInfo()" style="background:var(--info); color:#fff; margin-top:0;">Submit & Proceed</button>
        </div>
    </div>
</div>

<div class="popup-overlay" id="callConfirmPopup">
    <div class="popup" id="callConfirmBox">
        <div class="tick" style="color:var(--info); font-size: 45px; margin-bottom: 5px;">📞</div>
        <h3>Call Confirmation</h3>
        <p style="font-size:0.9em; color:var(--text-muted); margin-bottom:20px;">আপনার তথ্য সিস্টেমে লগ করা হচ্ছে আইনি নিরাপত্তার স্বার্থে।</p>
        <div class="caller-info-item">
            <small>Donor Name</small>
            <p id="confDonorName"></p>
        </div>
        <div class="caller-info-item">
            <small>Blood Group & Location</small>
            <p id="confDonorLoc"></p>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:20px;">
            <button onclick="document.getElementById('callConfirmPopup').classList.remove('active')" style="background:transparent;border:1px solid var(--border-color);color:var(--text-main);margin:0;box-shadow:none;font-size:0.88em;padding:11px 4px;">✕ Cancel</button>
            <button id="finalCallBtn" style="background:var(--success);color:#000;margin:0;font-size:0.88em;padding:11px 4px;">📞 Call</button>
            <button id="finalWaBtn" class="wa-btn" style="margin:0;font-size:0.88em;padding:11px 4px;">💬 WhatsApp</button>
        </div>
    </div>
</div>

<div class="popup-overlay" id="reportPopup">
    <div class="popup">
        <h2 style="color:var(--danger); margin-bottom:10px; font-family:var(--font-heading);">Report Harassment</h2>
        <p style="font-size:0.9em; color:var(--text-muted); margin-bottom:20px;">দাতার সাথে অশালীন আচরণ বা হয়রানি করলে আইনি ব্যবস্থা নেওয়া হবে।</p>
        <input type="text" id="repDonorPhone" placeholder="দাতার ফোন নম্বরটি দিন (+8801XXXXXXXXX)" required>
        <input type="text" id="harasserInfo" placeholder="হয়রানিকারীর ফোন নম্বর ও নাম (যদি জানা থাকে)" required>
        <textarea id="reportComment" placeholder="অভিযোগটি বিস্তারিত লিখুন..." style="width:100%; height:100px; resize:none;" required></textarea>
        <div style="display:flex; gap:12px; margin-top:15px;">
            <button onclick="document.getElementById('reportPopup').classList.remove('active')" style="background:transparent; border:1px solid var(--border-color); color:var(--text-main); margin-top:0; box-shadow:none;">Close</button>
            <button onclick="submitReport()" style="background:var(--danger); color:#fff; margin-top:0;">Send Report</button>
        </div>
    </div>
</div>

<div class="popup-overlay" id="warningPopupOverlay">
    <div class="popup">
        <div class="tick warning-tick">⚠️</div>
        <h2 style="color:var(--text-main); margin-bottom: 15px; font-family:var(--font-heading);">সতর্কবার্তা</h2>
        <p style="color:var(--text-muted); font-size:0.95rem; margin-bottom: 25px; line-height: 1.7;">
            ভুল তথ্য দিয়ে জীবনকে ঝুঁকির মুখে ফেলবেন না। মানুষ ইমার্জেন্সি মুহূর্তেই রক্তের খোঁজ করে, তাই আপনার ভুল তথ্য অন্যের মনে আশা সঞ্চার করলেও আপনার ভুল তথ্যটি অসুস্থ রোগীর জন্য ক্ষতির কারণ হবে।
        </p>
        <button onclick="showTerms()" style="background:var(--accent-orange); color:#000;">I have read and agree</button>
    </div>
</div>

<div class="popup-overlay" id="termsPopupOverlay">
    <div class="popup">
        <h2 style="color:var(--primary-red); margin-bottom: 10px; font-family:var(--font-heading);">শর্তাবলী ও নীতিমালা</h2>
        <div class="scroll-content">
            <p>এই পোর্টালে রক্তদাতা হিসেবে নিবন্ধিত হওয়ার পূর্বে দয়া করে নিচের শর্তাবলীগুলো মনোযোগ দিয়ে পড়ুন। নিবন্ধন সম্পন্ন করার অর্থ হলো আপনি এই নীতিমালের সাথে একমত পোষণ করেছেন।</p>
            
            <h4>১. তথ্যের সঠিকতা ও দায়বদ্ধতা</h4>
            <p><strong>সঠিক তথ্য প্রদান:</strong> রক্তদাতা হিসেবে আপনাকে অবশ্যই আপনার নাম, ফোন নম্বর, রক্ত গ্রুপ এবং সর্বশেষ রক্তদানের তারিখ সঠিকভাবে প্রদান করতে হবে।</p>
            <p><strong>সতর্কবার্তা:</strong> ভুল তথ্য প্রদান করে কোনো মুমূর্ষু রোগীর জীবনকে ঝুঁকির মুখে ফেলবেন না। আপনার দেওয়া ভুল তথ্যের কারণে জরুরি মুহূর্তে রক্ত সংগ্রহে বিলম্ব হলে তার দায়ভার আপনার ওপর বর্তাবে।</p>
            
            <h4>২. গোপনীয়তা ও যোগাযোগ</h4>
            <p><strong>ফোন নম্বর দৃশ্যমানতা:</strong> আপনি রক্তদাতা হিসেবে নিবন্ধিত হওয়ার সাথে সাথে আপনার ফোন নম্বরটি আমাদের ডাটাবেসে সাধারণ মানুষের জন্য উন্মুক্ত (Public) হবে।</p>
            <p><strong>অযাচিত কল:</strong> জনসমক্ষে নম্বর থাকায় কোনো অপ্রাসঙ্গিক বা বিরক্তিকর কলের জন্য পোর্টাল কর্তৃপক্ষ দায়ী থাকবে না।</p>
            
            <h4>৩. রক্তদান প্রক্রিয়া</h4>
            <p><strong>স্বেচ্ছাসেবী মনোভাব:</strong> এখানে নিবন্ধন করা মানে আপনি একজন স্বেচ্ছাসেবী রক্তদাতা। রক্তদানের বিনিময়ে কোনো আর্থিক লেনদেন বা অনৈতিক দাবি করা সম্পূর্ণ নিষিদ্ধ।</p>
            
            <h4>৪. ডাটাবেস পরিবর্তন</h4>
            <p>কর্তৃপক্ষ চাইলে যেকোনো সময় ভুল বা ভুয়া তথ্য ডিলিট করার অধিকার রাখে।</p>
        </div>
        <button onclick="dismissAllPopups()">Agree & Continue</button>
    </div>
</div>

<div class="popup-overlay" id="aboutUsPopupOverlay">
    <div class="popup" style="max-width: 550px;">
        <h2 style="color:var(--primary-red); margin-bottom: 10px; font-family:var(--font-heading);">আমাদের কথা (About Us)</h2>
        <div class="scroll-content">
            <p style="font-weight:600; color:var(--text-main); font-size:1.05em; margin-bottom:20px;">"রক্তের জন্য আর নয় অস্থিরতা " — এই সুদৃঢ় অঙ্গীকার নিয়ে Shaheed Suhrawardy Medical Campus Blood Portal-এর পথচলা শুরু হয়েছে।</p>
            
            <h4>আমাদের লক্ষ্য ও উদ্দেশ্য:</h4>
            <p>আমাদের মূল লক্ষ্য হলো জরুরি মুহূর্তে রক্তদাতার অভাবজনিত কারণে সৃষ্ট মানবিক সংকটের স্থায়ী সমাধান করা। একজন মুমূর্ষু রোগীর স্বজনরা যেন কোনো প্রকার বিড়ম্বনা ছাড়াই দ্রুততম সময়ে রক্তদাতার সন্ধান পান, সেটি নিশ্চিত করাই এই পোর্টালের প্রধান উদ্দেশ্য। আমাদের এই ক্ষুদ্র প্রয়াস বর্তমানে শহীদ সোহরাওয়ার্দী মেডিকেল কলেজ কেন্দ্রিক হলেও, আমাদের সুদূরপ্রসারী পরিকল্পনা হলো এই সেবামূলক প্ল্যাটফর্মটিকে বাংলাদেশের প্রতিটি মেডিকেল ক্যাম্পাস এবং প্রতিটি জেলা পর্যায়ে বিস্তৃত করা।</p>
            
            <h4>উদ্যোগের প্রেক্ষাপট:</h4>
            <p>শহীদ সোহরাওয়ার্দী মেডিকেল কলেজের চত্বরে অবস্থানকালীন সময়ে প্রতিনিয়ত অসংখ্য রোগীর রক্তের প্রয়োজনীয়তা ও তা সংগ্রহের প্রতিকূলতা আমাদের দৃষ্টিগোচর হয়েছে। সাধারণ মানুষের এই ভোগান্তি নিরসনে এবং সামাজিক দায়বদ্ধতা থেকে আমরা Blood Arena Team একটি আধুনিক ও স্বচ্ছ প্ল্যাটফর্ম তৈরির প্রয়োজনীয়তা অনুভব করি।</p>
            
            <h4>পোর্টালের প্রধান বৈশিষ্ট্যসমূহ:</h4>
            <p>• <strong>লাইভ স্ট্যাটাস:</strong> সিস্টেম স্বয়ংক্রিয়ভাবে রক্তদাতার বর্তমান প্রাপ্যতা প্রদর্শন করে।<br>
            • <strong>সরাসরি যোগাযোগ:</strong> রক্তগ্রহীতা সরাসরি দাতার সাথে যোগাযোগ করতে পারেন।<br>
            • <strong>নিরাপত্তা:</strong> আমরা তথ্যের নির্ভুলতার ওপর সর্বোচ্চ গুরুত্ব প্রদান করি।</p>
            
            <h4>সিয়াম ও রাফি-র বার্তা:</h4>
            <p style="font-style:italic; color:var(--text-muted); border-left: 3px solid var(--primary-red); padding-left: 15px; margin-top:15px; background: var(--input-bg); padding-top:10px; padding-bottom:10px; border-radius: 0 8px 8px 0;">“দীর্ঘ শ্রম এবং প্রচেষ্টার পর আমরা এই পোর্টালটি নির্মাণ করতে সক্ষম হয়েছি। আমাদের এই প্রযুক্তিগত উদ্যোগ যদি একজন মুমূর্ষু মানুষের জীবন রক্ষায় সামান্যতম অবদান রাখতে পারে, তবেই আমাদের পরিশ্রম সার্থক হবে। সোহরাওয়ার্দী ক্যাম্পাস থেকে শুরু হওয়া এই সেবা ইনশাআল্লাহ বাংলাদেশের প্রতিটি মেডিকেল ক্যাম্পাসে ছড়িয়ে যাবে।”</p>
        </div>
        <button onclick="closeAboutUs()" style="background:transparent; border:1px solid var(--border-color); color:var(--text-main); box-shadow:none;">Close</button>
    </div>
</div>

<header>
  <img src="logo.png" alt="Left Logo" loading="eager" decoding="sync" fetchpriority="high">
  <h1>Blood Arena</h1>
  <div class="notif-bell-wrap" id="nBellWrap">
    <button class="notif-bell" id="nBell" onclick="toggleNPanel()" title="Live Requests">
      🔔<span class="notif-badge" id="nBadge"></span>
    </button>
  </div>
  <img src="logo1.png" alt="Right Logo" loading="eager" decoding="sync" fetchpriority="high">
</header>
<!-- Notif panel rendered at body level to escape header stacking context -->
<div class="notif-panel-anchor">
  <div class="notif-panel" id="nPanel">
    <!-- Tab header -->
    <div class="notif-tabs-hdr">
      <button class="notif-tab-btn active" id="nTabBlood" onclick="switchNTab('blood')">
        🆘 Blood Request<span class="notif-tab-badge" id="nTabBloodBadge" style="display:none;"></span>
      </button>
      <button class="notif-tab-btn" id="nTabSvc" onclick="switchNTab('service')">
        ⚙️ Services<span class="notif-tab-badge" id="nTabSvcBadge" style="display:none;"></span>
      </button>
    </div>
    <!-- Blood Request tab -->
    <div id="nTabBloodContent">
      <div class="notif-panel-subhdr"><span>🆘 Active Requests</span><span id="nCount" style="color:var(--text-muted);font-size:0.82em;"></span></div>
      <div id="nList"><div class="notif-empty">কোনো active request নেই</div></div>
    </div>
    <!-- Services tab -->
    <div id="nTabSvcContent" style="display:none;">
      <div class="notif-panel-subhdr">
        <span>⚙️ Service Notifications</span>
        <span id="nSvcCount" style="color:var(--text-muted);font-size:0.82em;"></span>
      </div>
      <div class="svc-notif-toolbar">
        <span class="svc-notif-hint">← swipe করে remove করুন</span>
        <button class="svc-delete-all-btn" onclick="deleteAllSvcNotifs()">🗑 সব মুছুন</button>
      </div>
      <div id="nSvcList"><div class="notif-empty">কোনো service notification নেই</div></div>
    </div>
  </div>
</div>
<div id="toastWrap"></div>

<!-- ===== APP PAGE: HOME ===== -->
<div class="app-page page-active" id="page-home">
<div class="app-page-header"><span class="ph-icon">🩸</span> Blood Arena<span class="app-version-badge">Stable v2.8.0</span></div>

<!-- HOME HERO: Total Summary -->
<div class="home-hero-bar">
    <div class="home-hero-stat">
        <span class="home-hero-num" id="heroTotalDonors"><?php echo $total_donors_count; ?></span>
        <span class="home-hero-lbl">মোট Donors</span>
    </div>
    <div class="home-hero-divider"></div>
    <div class="home-hero-stat">
        <span class="home-hero-num" id="heroAvailDonors" style="color:var(--success);"><?php echo array_sum($avail_counts); ?></span>
        <span class="home-hero-lbl">Available Now</span>
    </div>
    <div class="home-hero-divider"></div>
    <div class="home-hero-stat" onclick="appSwitchPage('register')" style="cursor:pointer;">
        <span class="home-hero-num" style="font-size:1.4rem;">📝</span>
        <span class="home-hero-lbl">Register</span>
    </div>
</div>
<div class="emergency-banner" id="requestSection">
    <div class="emergency-banner-left">
        <div class="emergency-banner-icon">🆘</div>
        <div class="emergency-banner-text">
            <h4>জরুরি রক্তের প্রয়োজন?</h4>
            <p>Emergency request করুন — সব donor দেখতে পাবে</p>
        </div>
    </div>
    <div class="emergency-banner-btns">
        <button class="btn-view-requests" onclick="toggleRequestSection()">📋 Active Requests দেখুন</button>
        <button class="btn-emergency" onclick="openBloodRequestModal()">🆘 Emergency Request</button>
    </div>
</div>

<!-- ── MANUAL TOKEN RECOVERY MODAL ── -->
<div class="popup-overlay" id="manualTokenModal" style="z-index:10050;" onclick="closeManualTokenModal()">
  <div style="background:var(--bg-card);border-radius:20px;padding:24px 20px;max-width:360px;width:92%;border:1px solid rgba(220,38,38,0.25);" onclick="event.stopPropagation()">
    <div style="text-align:center;margin-bottom:16px;">
      <div style="font-size:2rem;">🔑</div>
      <h3 style="color:var(--danger);font-family:var(--font-heading);margin-bottom:4px;">Token দিয়ে Request খুঁজুন</h3>
      <p style="color:var(--text-muted);font-size:0.82em;">Request submit করার সময় পাওয়া ID ও Token দিন</p>
    </div>
    <div style="margin-bottom:12px;">
      <label style="font-size:0.82em;color:var(--text-muted);display:block;margin-bottom:5px;">Request ID (নম্বর)</label>
      <input type="tel" id="manual_req_id" placeholder="যেমন: 42" style="margin:0;font-size:1.1em;text-align:center;font-family:monospace;">
    </div>
    <div style="margin-bottom:12px;">
      <label style="font-size:0.82em;color:var(--text-muted);display:block;margin-bottom:5px;">Delete Token (৬ সংখ্যা)</label>
      <input type="tel" id="manual_token" maxlength="6" placeholder="000000" style="margin:0;font-size:1.4rem;letter-spacing:8px;text-align:center;font-family:monospace;">
    </div>
    <div id="manual_token_error" style="display:none;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;padding:8px 12px;color:var(--danger);font-size:0.82em;margin-bottom:10px;"></div>
    <div style="display:flex;gap:10px;">
      <button onclick="closeManualTokenModal()" style="flex:1;padding:11px;background:var(--input-bg);border:1px solid var(--border-color);color:var(--text-muted);border-radius:12px;font-size:0.88rem;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">বাতিল</button>
      <button onclick="saveManualToken()" style="flex:2;padding:11px;background:var(--danger);color:#fff;border:none;border-radius:12px;font-size:0.88rem;font-weight:700;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">✅ সংরক্ষণ করুন</button>
    </div>
  </div>
</div>

<!-- ── DELETE TOKEN INFO MODAL (submission সফলের পর দেখায়) ── -->
<div class="popup-overlay" id="deleteTokenInfoModal" style="z-index:10050;">
  <div style="background:var(--bg-card);border-radius:20px;padding:24px 20px;max-width:380px;width:94%;border:1px solid rgba(220,38,38,0.3);" onclick="event.stopPropagation()">

    <!-- Header -->
    <div style="text-align:center;margin-bottom:14px;">
      <div style="font-size:2.8rem;line-height:1;">✅</div>
      <h3 style="color:var(--text-main);font-family:var(--font-heading);margin:8px 0 4px;font-size:1.1rem;">Request সফলভাবে পাঠানো হয়েছে!</h3>
      <p style="color:var(--text-muted);font-size:0.82em;">Request ID: <strong id="dtm_req_id_show" style="color:var(--danger);font-family:monospace;font-size:1.05em;"></strong></p>
    </div>

    <!-- Warning box -->
    <div style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.4);border-radius:12px;padding:10px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start;">
      <span style="font-size:1.3rem;flex-shrink:0;">⚠️</span>
      <p style="color:#f59e0b;font-size:0.82em;font-weight:600;line-height:1.5;margin:0;">এই Token ছাড়া Request মুছতে পারবেন না। নিচের Token-টি <strong>Screenshot নিন</strong> অথবা <strong>Copy করে সেভ করুন।</strong></p>
    </div>

    <!-- Token display -->
    <div style="background:rgba(220,38,38,0.07);border:2px dashed rgba(220,38,38,0.45);border-radius:14px;padding:18px 14px;margin-bottom:14px;text-align:center;">
      <p style="color:var(--text-muted);font-size:0.75em;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;">🔑 আপনার Delete Token</p>
      <div id="dtm_token_show" style="font-size:2.6rem;font-weight:900;color:var(--danger);letter-spacing:12px;font-family:monospace;line-height:1.2;user-select:all;"></div>
      <p style="color:var(--text-muted);font-size:0.72em;margin-top:6px;">উপরের নম্বরটি চেপে ধরলে select হবে</p>
    </div>

    <!-- Copy button -->
    <button id="dtm_copy_btn" onclick="copyDeleteToken()" style="width:100%;padding:12px;background:rgba(220,38,38,0.1);border:1.5px solid rgba(220,38,38,0.35);color:var(--danger);border-radius:12px;font-size:0.92rem;font-weight:700;cursor:pointer;margin-bottom:10px;min-height:unset;box-shadow:none;">
      📋 Token ও ID Copy করুন
    </button>

    <!-- Confirm button — only active after copy or 5s -->
    <button id="dtm_ok_btn" onclick="closeDeleteTokenInfoModal()" style="width:100%;padding:13px;background:#aaa;color:#fff;border:none;border-radius:14px;font-size:0.95rem;font-weight:700;cursor:not-allowed;min-height:unset;box-shadow:none;" disabled>
      ✓ Copy/Screenshot করেছি — বন্ধ করুন
    </button>
    <p id="dtm_countdown" style="text-align:center;font-size:0.75em;color:var(--text-muted);margin-top:8px;"></p>
  </div>
</div>

<!-- ── DELETE REQUEST CONFIRMATION MODAL ── -->
<div class="popup-overlay" id="deleteRequestModal" style="z-index:10050;">
  <div style="background:var(--bg-card);border-radius:20px;padding:24px 20px;max-width:360px;width:92%;border:1px solid rgba(220,38,38,0.25);" onclick="event.stopPropagation()">
    <div style="text-align:center;margin-bottom:16px;">
      <div style="font-size:2rem;">🗑️</div>
      <h3 style="color:var(--danger);font-family:var(--font-heading);margin-bottom:4px;">Request Delete করুন</h3>
      <p style="color:var(--text-muted);font-size:0.83em;">শুধুমাত্র যিনি request পাঠিয়েছেন তিনিই মুছতে পারবেন</p>
    </div>
    <input type="hidden" id="del_req_id">
    <input type="hidden" id="del_contact_pre">
    <div style="margin-bottom:14px;">
      <label style="font-size:0.83em;color:var(--text-muted);display:block;margin-bottom:6px;">🔑 Delete Token (৬ সংখ্যা)</label>
      <input type="tel" id="del_token_input" maxlength="6" placeholder="000000"
        style="width:100%;padding:12px 14px;background:var(--input-bg);border:1px solid var(--border-color);border-radius:12px;color:var(--text-main);font-size:1.6rem;letter-spacing:10px;text-align:center;font-family:monospace;box-sizing:border-box;">
    </div>
    <div id="del_error_msg" style="display:none;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:10px;padding:10px 12px;color:var(--danger);font-size:0.83em;margin-bottom:12px;"></div>
    <div style="display:flex;gap:10px;">
      <button onclick="closeDeleteRequestModal()" style="flex:1;padding:12px;background:var(--input-bg);border:1px solid var(--border-color);color:var(--text-muted);border-radius:12px;font-size:0.9rem;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">বাতিল</button>
      <button id="del_confirm_btn" onclick="confirmDeleteRequest()" style="flex:2;padding:12px;background:var(--danger);color:#fff;border:none;border-radius:12px;font-size:0.9rem;font-weight:700;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">✅ Delete নিশ্চিত করুন</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: REQUEST NEW SECRET CODE ===== -->
<div class="popup-overlay" id="requestSecretCodeModal" style="z-index:10050;" onclick="closeRequestSecretCodeModal()">
  <div style="background:var(--bg-card);border-radius:20px;padding:24px 20px;max-width:380px;width:94%;border:1px solid rgba(59,130,246,0.3);" onclick="event.stopPropagation()">
    <div style="text-align:center;margin-bottom:18px;">
      <div style="font-size:2.4rem;line-height:1;">📩</div>
      <h3 style="color:var(--info);font-family:var(--font-heading);margin:8px 0 4px;">নতুন Secret Code Request</h3>
      <p style="color:var(--text-muted);font-size:0.82em;">Admin review করার পর Services notification-এ নতুন Code পাবেন</p>
    </div>
    <div style="margin-bottom:12px;">
      <label style="font-size:0.83em;color:var(--text-muted);display:block;margin-bottom:6px;">📞 আপনার Registered Phone Number</label>
      <input type="tel" id="rsc_phone" value="+8801" maxlength="14" placeholder="+8801XXXXXXXXX"
        style="margin:0;width:100%;font-family:monospace;font-size:1.05em;letter-spacing:1px;">
    </div>
    <div style="margin-bottom:12px;">
      <label style="font-size:0.83em;color:var(--text-muted);display:block;margin-bottom:6px;">🔑 আপনার পছন্দের ৪ সংখ্যার Reference Code সেট করুন</label>

      <!-- Instruction card -->
      <div style="background:rgba(59,130,246,0.07);border:1px solid rgba(59,130,246,0.2);border-radius:10px;padding:9px 12px;margin-bottom:8px;font-size:0.76em;color:var(--text-muted);line-height:1.7;">
        <strong style="color:var(--info);">✅ ভালো Reference Code:</strong> এমন সংখ্যা যা অন্যরা সহজে অনুমান করতে পারবে না।<br>
        <strong style="color:var(--danger);">❌ এড়িয়ে চলুন:</strong>
        <span style="background:rgba(220,38,38,0.1);border-radius:4px;padding:1px 5px;margin:0 2px;">1234</span>
        <span style="background:rgba(220,38,38,0.1);border-radius:4px;padding:1px 5px;margin:0 2px;">0000</span>
        <span style="background:rgba(220,38,38,0.1);border-radius:4px;padding:1px 5px;margin:0 2px;">1111</span>
        <span style="background:rgba(220,38,38,0.1);border-radius:4px;padding:1px 5px;margin:0 2px;">1234</span>
        <span style="background:rgba(220,38,38,0.1);border-radius:4px;padding:1px 5px;margin:0 2px;">জন্মসাল</span>
        — এগুলো সহজে guess করা যায়।<br>
        <strong style="color:var(--text-main);">💡 টিপস:</strong> একটি র‍্যান্ডম সংখ্যা বেছে নিন যা শুধু আপনি জানেন।
      </div>

      <input type="tel" id="rsc_ref" placeholder="৪ সংখ্যা দিন" maxlength="4"
        style="margin:0;width:100%;font-family:monospace;font-size:1.6em;letter-spacing:8px;text-align:center;"
        oninput="this.value=this.value.replace(/[^0-9]/g,'').substring(0,4);checkRefStrength(this.value)">

      <!-- Live strength indicator -->
      <div id="rsc_strength" style="margin-top:6px;font-size:0.76em;min-height:18px;"></div>
    </div>
    <div id="rsc_error" style="display:none;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:10px;padding:10px 12px;color:var(--danger);font-size:0.83em;margin-bottom:12px;"></div>
    <div id="rsc_success" style="display:none;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);border-radius:10px;padding:10px 12px;color:var(--success);font-size:0.83em;margin-bottom:12px;"></div>
    <div style="display:flex;gap:10px;">
      <button onclick="closeRequestSecretCodeModal()" style="flex:1;padding:12px;background:var(--input-bg);border:1px solid var(--border-color);color:var(--text-muted);border-radius:12px;font-size:0.88rem;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">বাতিল</button>
      <button id="rsc_submit_btn" onclick="submitRequestSecretCode()" style="flex:2;padding:12px;background:var(--info);color:#fff;border:none;border-radius:12px;font-size:0.88rem;font-weight:700;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">📩 Request পাঠান</button>
    </div>
  </div>
</div>

<!-- ===== MODAL: GET SECRET CODE BY REF ===== -->
<div class="popup-overlay" id="getSecretCodeModal" style="z-index:10050;" onclick="closeGetSecretCodeModal()">
  <div style="background:var(--bg-card);border-radius:20px;padding:24px 20px;max-width:380px;width:94%;border:1px solid rgba(16,185,129,0.3);" onclick="event.stopPropagation()">
    <div style="text-align:center;margin-bottom:18px;">
      <div style="font-size:2.4rem;line-height:1;">🔍</div>
      <h3 style="color:var(--success);font-family:var(--font-heading);margin:8px 0 4px;">Secret Code দেখুন</h3>
      <p style="color:var(--text-muted);font-size:0.82em;">ফোন নম্বর ও Reference Code দিলে Secret Code দেখাবে</p>
    </div>
    <div style="margin-bottom:12px;">
      <label style="font-size:0.83em;color:var(--text-muted);display:block;margin-bottom:6px;">📞 Registered Phone Number</label>
      <input type="tel" id="gsc_phone" value="+8801" maxlength="14" placeholder="+8801XXXXXXXXX"
        style="margin:0;width:100%;font-family:monospace;font-size:1.05em;">
    </div>
    <div style="margin-bottom:12px;">
      <label style="font-size:0.83em;color:var(--text-muted);display:block;margin-bottom:6px;">🔑 Reference Code (৪ সংখ্যা)</label>
      <input type="tel" id="gsc_ref" placeholder="1234" maxlength="4"
        style="margin:0;width:100%;font-family:monospace;font-size:1.6em;letter-spacing:8px;text-align:center;"
        oninput="this.value=this.value.replace(/[^0-9]/g,'').substring(0,4)">
    </div>
    <div id="gsc_error" style="display:none;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:10px;padding:10px 12px;color:var(--danger);font-size:0.83em;margin-bottom:12px;"></div>
    <div id="gsc_result" style="display:none;background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.3);border-radius:10px;padding:14px;margin-bottom:12px;text-align:center;">
      <div style="font-size:0.82em;color:var(--text-muted);margin-bottom:6px;">✅ আপনার Secret Code:</div>
      <div class="secret-box" id="gsc_code_display" style="margin:0 0 8px;font-size:1em;"></div>
      <button onclick="copyGscCode()" style="padding:8px 18px;background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.4);color:var(--success);border-radius:10px;font-size:0.85rem;font-weight:700;cursor:pointer;min-height:unset;margin:0;box-shadow:none;">📋 Copy Code</button>
      <div id="gsc_views_info" style="margin-top:8px;font-size:0.78em;color:var(--text-muted);"></div>
    </div>
    <div style="display:flex;gap:10px;">
      <button onclick="closeGetSecretCodeModal()" style="flex:1;padding:12px;background:var(--input-bg);border:1px solid var(--border-color);color:var(--text-muted);border-radius:12px;font-size:0.88rem;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">বন্ধ করুন</button>
      <button id="gsc_submit_btn" onclick="submitGetSecretCode()" style="flex:2;padding:12px;background:var(--success);color:#fff;border:none;border-radius:12px;font-size:0.88rem;font-weight:700;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">🔍 Secret Code দেখুন</button>
    </div>
  </div>
</div>

<!-- ACTIVE BLOOD REQUESTS SECTION -->
<div class="req-section" id="reqSection">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
        <div>
            <h3 style="color:var(--danger);font-family:var(--font-heading);font-size:1.2rem;margin:0;">🆘 Active Blood Requests</h3>
            <p style="color:var(--text-muted);font-size:0.8em;margin:2px 0 0;">রক্তের জন্য অপেক্ষা করছেন এমন রোগীরা</p>
        </div>
        <button class="btn-deny-notif" onclick="toggleRequestSection()" style="margin:0;width:auto!important;min-height:unset!important;">✕</button>
    </div>

    <!-- Row 1: Main tabs -->
    <div class="req-filter-row">
        <button id="reqTab_all" class="req-tab-btn req-tab-active" onclick="setReqTab('all')">🩸 সব</button>
        <button id="reqTab_mine" class="req-tab-btn" onclick="setReqTab('mine')">👤 আমার Request</button>
    </div>

    <!-- Row 2: Blood group chips -->
    <div class="req-filter-row" style="margin-top:8px;gap:6px;">
        <span style="font-size:0.72em;color:var(--text-muted);font-weight:600;white-space:nowrap;align-self:center;">গ্রুপ:</span>
        <?php foreach(["A+","A-","B+","B-","AB+","AB-","O+","O-"] as $g): ?>
        <button class="req-bg-chip" data-group="<?= $g ?>" onclick="setReqGroupFilter('<?= $g ?>')"><?= $g ?></button>
        <?php endforeach; ?>
        <button id="reqBgFilterClear" class="req-bg-clear" onclick="clearReqGroupFilter()" style="display:none;">✕ Clear</button>
    </div>

    <div class="req-grid" id="reqGrid">
        <div style="text-align:center;padding:30px;color:var(--text-muted);grid-column:1/-1;">⏳ লোড হচ্ছে...</div>
    </div>
</div>

<!-- SPONSOR BANNER RIGHT BELOW HEADER -->
<div class="sponsor-banner">
    <p>আমাদের এই মহৎ উদ্যোগে স্পন্সর হিসেবে যুক্ত হতে আগ্রহী হলে, দয়া করে এই নাম্বারে যোগাযোগ করুন: <span class="highlight-number"><a href="tel:01518981827">০১৫১৮৯৮১৮২৭</a></span></p>
</div>

<!-- ==================== COMPACT LIVE STATS CARDS ==================== -->
<div class="stats-container" id="statsSection">
    <?php 
    $__id_map = ['A+'=>'Aplus','A-'=>'Aminus','B+'=>'Bplus','B-'=>'Bminus','AB+'=>'ABplus','AB-'=>'ABminus','O+'=>'Oplus','O-'=>'Ominus'];
    foreach(["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"] as $g){
        $bg_id = $__id_map[$g];
        $color_class = "blood-" . $bg_id;
        echo "<div class='stat-card $color_class' onclick=\"appSwitchPage('donors'); quickFilter('$g');\">
                <h4>$g</h4>
                <div class='count' id='count-$bg_id'>🩸 ".$avail_counts[$g]." Available</div>
              </div>";
    } 
    ?>
</div>


<!-- ===== DEVELOPER CARDS (Home only, compact side-by-side) ===== -->
<div class="dev-section">
    <p class="dev-section-label">⚡ Developed By</p>
    <div class="dev-cards-row">

        <!-- Siam Card -->
        <div class="dev-card dev-card-siam">
            <div class="dev-card-bar" style="background:linear-gradient(90deg,var(--primary-red),#f59e0b);"></div>
            <img src="siam.jpg" alt="Siam" class="dev-avatar" style="border-color:var(--primary-red);">
            <p class="dev-name">Siam-258</p>
            <p class="dev-role">Sh-20 · Lead Dev</p>
            <span class="dev-badge dev-badge-red" style="margin-bottom:5px;">💻 Lead Developer</span>
            <span class="dev-badge dev-badge-orange" style="margin-bottom:5px;">🩸 Planner of Blood Arena</span>
            <span class="dev-badge dev-badge-purple">🩸 Planner of Blood Solution</span>
        </div>

        <!-- Rafi Card -->
        <div class="dev-card dev-card-si">
            <div class="dev-card-bar" style="background:linear-gradient(90deg,#10b981,#3b82f6,#6366f1);"></div>
            <img src="rafi.jpg" alt="Rafi" class="dev-avatar" style="border-color:#10b981;">
            <p class="dev-name">Rafi-293</p>
            <p class="dev-role">Sh-20 · Planner</p>
            <span class="dev-badge dev-badge-green">🩸 Planner of Blood Arena</span>
        </div>
    </div>

    <!-- AI Tools Row -->
    <div class="ai-tools-row">
        <p class="ai-tools-label">Powered with the help of</p>
        <div class="ai-tools-logos">

            <!-- Claude AI — first -->
            <a href="https://claude.ai" target="_blank" rel="noopener" class="ai-logo-chip ai-logo-chip-claude" title="Claude AI">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="16" height="16"><path d="M12.0002 1.5C10.0785 1.5 8.38209 2.6513 7.5752 4.3125C6.20801 4.02539 4.76465 4.43359 3.75 5.43848C2.73535 6.44336 2.32715 7.88672 2.61426 9.25391C0.953125 10.0625 -0.000488281 11.7607 -0.000488281 13.5C-0.000488281 15.3955 1.08887 17.0596 2.75977 17.8008C2.55664 19.167 3.01172 20.5781 4.01172 21.5752C5.01172 22.5723 6.41309 23.0273 7.7793 22.8242C8.5752 24.2969 10.1924 25.2676 11.9999 25.2676C13.8076 25.2676 15.3174 24.2969 16.1133 22.8242C17.4795 23.0273 18.8809 22.5723 19.8809 21.5752C20.8809 20.5781 21.3359 19.167 21.1328 17.8008C22.8037 17.0596 23.9951 15.3955 23.9951 13.5C23.9951 11.7607 23.041 10.0625 21.3799 9.25391C21.667 7.88672 21.2588 6.44336 20.2441 5.43848C19.2295 4.43359 17.7861 4.02539 16.4189 4.3125C15.6133 2.6513 13.9219 1.5 12.0002 1.5Z" fill="#cc785c"/></svg>
                <span>Claude</span>
            </a>

            <!-- ChatGPT / OpenAI -->
            <a href="https://chat.openai.com" target="_blank" rel="noopener" class="ai-logo-chip" title="ChatGPT">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.676l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.896zm16.597 3.855l-5.833-3.387 2.02-1.168a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.412-.663zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.607-1.5z"/></svg>
                <span>ChatGPT</span>
            </a>

            <!-- Grok / xAI -->
            <a href="https://grok.x.ai" target="_blank" rel="noopener" class="ai-logo-chip" title="Grok">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                <span>Grok</span>
            </a>

            <!-- Gemini -->
            <a href="https://gemini.google.com" target="_blank" rel="noopener" class="ai-logo-chip" title="Gemini">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><defs><linearGradient id="gem-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#4285f4"/><stop offset="50%" stop-color="#34a853"/><stop offset="100%" stop-color="#ea4335"/></linearGradient></defs><path d="M12 2C12 2 6.477 6.477 6.477 12C6.477 17.523 12 22 12 22C12 22 17.523 17.523 17.523 12C17.523 6.477 12 2 12 2Z" fill="url(#gem-g)"/><path d="M12 2C12 2 12 6.477 12 12C12 17.523 12 22 12 22C12 22 17.523 17.523 17.523 12C17.523 6.477 12 2 12 2Z" fill="#4285f4" opacity="0.6"/></svg>
                <span>Gemini</span>
            </a>

            <!-- Google AI Studio -->
            <a href="https://aistudio.google.com" target="_blank" rel="noopener" class="ai-logo-chip" title="Google AI Studio">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                <span>AI Studio</span>
            </a>

        </div>
    </div>
</div>
<div class="page-footer-bar"><span>🩸 © 2026 Blood Arena — All Rights Reserved.</span></div>
</div><!-- end page-home -->

<!-- ===== APP PAGE: REGISTER ===== -->
<div class="app-page" id="page-register">
<div class="app-page-header"><span class="ph-icon">📝</span> রেজিস্ট্রেশন</div>
<div class="container" id="regSection">  
<div class="tab-header">
    <button class="tab-btn active" onclick="switchTab(0)">➕ Donor Registration</button>
    <button class="tab-btn" onclick="switchTab(1)">✏️ Update My Info</button>
</div>

<!-- TAB 0: Register -->
<div id="tab0" class="tab-content active">

    <!-- REGISTRATION TOGGLE BUTTON -->
    <div id="regToggleContainer" style="text-align: center; margin-top: 20px;">
        <p style="color: var(--text-muted); margin-bottom: 12px; font-size: 1.05em; font-weight:600;">নতুন রক্তদাতা হিসেবে যুক্ত হতে নিচের বাটনে ক্লিক করুন</p>
        <button id="toggleFormBtn" onclick="toggleRegForm()" style="background: var(--success); color: #000; max-width: 320px; box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); font-size: 1.15em; display: inline-flex; justify-content: center; align-items: center; gap: 8px; margin:0 auto; padding: 18px; border-radius: 40px;">
            📝 Click Here to Register
        </button>
    </div>

    <!-- TOGGLEABLE FORM -->
    <form id="regForm" style="display:none; opacity: 0; transform: translateY(-15px); transition: opacity 0.4s ease, transform 0.4s ease; margin-top: 25px;">  
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
        <input type="hidden" name="reg_geo_location" id="reg_geo_location" value="Not captured">
        
        <h2>Register as Blood Donor</h2>  
        
        <div class="input-group">
            <div class="input-row">
                <input type="text" name="name" placeholder="Full Name" onfocus="handleNameFocus()" required oninput="validateName(this)">
                <input type="tel" name="phone" value="+880" placeholder="Enter your number" required pattern="^\+8801\d{9}$" title="Must start with +8801 followed by 9 digits">  
            </div>
            
            <!-- NEW: Location with Map Picker Only (No dropdown) -->
            <div>
                <label style="font-size: 0.85em; font-weight: 500; color: var(--text-muted); margin-bottom: 4px; display: block; padding-left: 4px;">📍 Donor Location</label>  
                
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="regExactLocation" placeholder="✍️ Your Area, House, Road... অথবা 🗺️ Map থেকে Pin করুন" style="margin:0;flex:1;" required>
                    <button type="button" onclick="openMapPicker()" title="Google Map থেকে Location বেছে নিন" style="margin:0;padding:10px 13px;min-height:unset;width:auto;background:rgba(66,133,244,0.12);border:1.5px solid rgba(66,133,244,0.35);color:#4285f4;border-radius:10px;font-size:1.25rem;flex-shrink:0;box-shadow:none;cursor:pointer;" aria-label="Map Picker">🗺️</button>
                </div>
                <p style="font-size:0.71em;color:var(--text-muted);margin:4px 0 0;padding-left:2px;">💡 🗺️ বাটনে ক্লিক করে Map থেকে সরাসরি লোকেশন পিন করুন</p>
            </div>
            
            <div class="input-row">
                <div>
                    <label style="font-size: 0.85em; font-weight: 500; color: var(--text-muted); margin-bottom: 4px; display: block; padding-left: 4px;">Blood Group</label>  
                    <select name="group" required style="margin-top:0;">  
                        <option value="" style="color:var(--text-muted);" disabled selected>Select Group</option>  
                        <option>A+</option><option>A-</option>  
                        <option>B+</option><option>B-</option>  
                        <option>AB+</option><option>AB-</option>  
                        <option>O+</option><option>O-</option>  
                    </select>  
                </div>
                <div>  
                    <label style="font-size: 0.85em; font-weight: 500; color: var(--text-muted); margin-bottom: 4px; display: block; padding-left: 4px;">Last Blood Donation Date</label>  
                    <!-- Smart date picker: toggle between "Never" and date -->
                    <div class="smart-date-wrap" style="margin-top:0;">
                        <div class="smart-date-toggle">
                            <button type="button" id="sdNeverBtn" class="sd-toggle-btn sd-active" onclick="setDonationNever()">🚫 Never Donated</button>
                            <button type="button" id="sdDateBtn" class="sd-toggle-btn" onclick="setDonationDate()">📅 Pick a Date</button>
                        </div>
                        <input type="hidden" name="last_donation" id="lastDonationHidden" value="no" required>
                        <div id="sdDatePickerWrap" style="display:none;margin-top:8px;">
                            <input type="date" id="sdDateInput" style="margin:0;" max="" onchange="syncDonationDate(this.value)">
                            <p style="font-size:0.72em;color:var(--text-muted);margin:3px 0 0;padding-left:2px;">📅 তারিখ বেছে নিন (min: 1940-01-01 · max: আজ)</p>
                        </div>
                        <div id="sdNeverMsg" style="margin-top:8px;padding:9px 12px;background:rgba(239,68,68,0.07);border-radius:8px;font-size:0.82em;color:var(--text-muted);">আপনি আগে কখনো রক্তদান করেননি — স্বয়ংক্রিয়ভাবে "no" সেট হবে।</div>
                    </div>
                </div>  
            </div>

            <!-- How many times donated — optional -->
            <div id="regDonationCountWrap" style="display:none; margin-top:4px; padding:14px 16px; background:rgba(59,130,246,0.06); border:1px solid rgba(59,130,246,0.18); border-radius:12px;">
                <label style="font-size:0.85em;font-weight:600;color:var(--text-muted);display:block;margin-bottom:8px;">🩸 এখন পর্যন্ত মোট কতবার রক্ত দিয়েছেন? <span style="font-weight:400;font-size:0.9em;">(Optional)</span></label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <button type="button" onclick="regDonCountChange(-1)" style="width:38px;height:38px;border-radius:50%;background:rgba(239,68,68,0.12);border:1.5px solid rgba(239,68,68,0.3);color:var(--primary-red);font-size:1.3rem;font-weight:700;cursor:pointer;flex-shrink:0;padding:0;min-height:unset;">−</button>
                    <div style="flex:1;text-align:center;">
                        <span id="regDonCountDisplay" style="font-size:1.6rem;font-weight:800;color:var(--text-main);">0</span>
                        <span style="font-size:0.8em;color:var(--text-muted);margin-left:4px;">বার</span>
                    </div>
                    <button type="button" onclick="regDonCountChange(+1)" style="width:38px;height:38px;border-radius:50%;background:rgba(16,185,129,0.12);border:1.5px solid rgba(16,185,129,0.3);color:#10b981;font-size:1.3rem;font-weight:700;cursor:pointer;flex-shrink:0;padding:0;min-height:unset;">+</button>
                </div>
                <input type="hidden" id="regDonCountHidden" name="total_donations_reg" value="0">
                <div id="regBadgePreview" style="margin-top:10px;display:flex;align-items:center;gap:8px;padding:8px 12px;background:rgba(16,185,129,0.08);border-radius:8px;">
                    <span id="regBadgeIcon" style="font-size:1.3rem;">🌱</span>
                    <div>
                        <div style="font-size:0.82em;font-weight:700;color:var(--text-main);" id="regBadgeName">New Donor</div>
                        <div style="font-size:0.72em;color:var(--text-muted);" id="regBadgeNote">১ম donation করলে progress শুরু হবে</div>
                    </div>
                </div>
            </div>
        </div>
        <button type="button" onclick="submitRegistration()">Submit Registration</button>  
    </form>  
</div>

<!-- TAB 1: Update Info -->
<div id="tab1" class="tab-content">
<form id="updateForm">
    <h2>Update Your Information</h2>
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
    
    <div style="display:flex; flex-direction:column; align-items:center; max-width:500px; margin:0 auto;">
        <input type="text" id="secretCodeInput" placeholder="Enter Your Secret Code (BS-...)" required maxlength="25" style="text-align:center; font-family:monospace; letter-spacing:1px; font-size:1.1em;">
        <button type="button" onclick="verifyAndLoadInfo()" style="margin-top:15px; background:var(--info);">🔐 Verify & Load Info</button>
    </div>

    <!-- Donor Badge Display -->
    <div id="donorBadgeCard" style="display:none; margin:20px auto; max-width:500px;">
        <div class="badge-card">
            <div class="badge-card-left">
                <div id="badgeIconBig" class="badge-icon-big">🌱</div>
                <div>
                    <div class="badge-level-name" id="badgeLevelName">New Donor</div>
                    <div class="badge-donations" id="badgeDonations">0 donations</div>
                </div>
            </div>
            <div class="badge-progress-wrap">
                <div class="badge-progress-bar"><div class="badge-progress-fill" id="badgeProgressFill"></div></div>
                <div class="badge-next-label" id="badgeNextLabel"></div>
            </div>
        </div>
        <!-- Quick action: Just Donated button -->
        <button type="button" id="justDonatedBtn" onclick="triggerJustDonated()" class="just-donated-btn">
            🩸 আমি এইমাত্র রক্ত দিয়েছি — Update করুন
        </button>
        <p id="justDonatedLockMsg" style="display:none;text-align:center;font-size:0.82em;color:#f59e0b;margin-top:6px;padding:7px 12px;background:rgba(245,158,11,0.1);border-radius:8px;"></p>
    </div>

    <div id="updateFields" style="display:none; margin-top:20px; border-top:1px solid var(--border-color); padding-top:25px;">
        <div class="input-group">
            <input type="text" id="u_name" placeholder="Full Name" required oninput="validateName(this)">
            
            <div>
                <label style="font-size: 0.85em; font-weight: 500; color: var(--text-muted); margin-bottom: 4px; display: block;">Update Location</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="u_location" placeholder="✍️ Your Area, House, Road, Landmark..." required style="margin:0;flex:1;">
                    <button type="button" onclick="openUpdateMapPicker()" title="Map থেকে Location বেছে নিন" style="margin:0;padding:10px 13px;min-height:unset;width:auto;background:rgba(66,133,244,0.12);border:1.5px solid rgba(66,133,244,0.35);color:#4285f4;border-radius:10px;font-size:1.25rem;flex-shrink:0;box-shadow:none;cursor:pointer;" aria-label="Map Picker">🗺️</button>
                </div>
                <input type="hidden" id="u_reg_geo" value="">
                <p style="font-size:0.71em;color:var(--text-muted);margin:4px 0 0;padding-left:2px;">💡 🗺️ বাটনে ক্লিক করে Map থেকে সরাসরি লোকেশন পিন করুন</p>
            </div>

            <div>
                <label style="font-size: 0.85em; font-weight: 500; color: var(--text-muted); margin-bottom: 4px; display: block;">Last Blood Donation Date</label>
                <div class="smart-date-wrap" style="margin-top:0;">
                    <div class="smart-date-toggle">
                        <button type="button" id="uSdNeverBtn" class="sd-toggle-btn sd-active" onclick="setUpdateDonationNever()">🚫 Never / Reset</button>
                        <button type="button" id="uSdDateBtn"  class="sd-toggle-btn" onclick="setUpdateDonationDate()">📅 Pick a Date</button>
                    </div>
                    <input type="hidden" id="u_last" value="no">
                    <div id="uSdDatePickerWrap" style="display:none;margin-top:8px;">
                        <input type="date" id="uSdDateInput" style="margin:0;" max="" onchange="syncUpdateDonationDate(this.value)">
                        <p style="font-size:0.72em;color:var(--text-muted);margin:3px 0 0;padding-left:2px;">📅 তারিখ বেছে নিন (min: 1940-01-01 · max: আজ)</p>
                    </div>
                    <div id="uSdNeverMsg" style="margin-top:8px;padding:9px 12px;background:rgba(239,68,68,0.07);border-radius:8px;font-size:0.82em;color:var(--text-muted);">তারিখ নেই বা reset করতে চান — "no" সেট হবে।</div>
                </div>
            </div>

            <!-- Willing to Donate Toggle -->
            <div class="willing-toggle-wrap">
                <label style="font-size:0.95em; font-weight:600; color:var(--text-main); margin-bottom:10px; display:block;">🩸 রক্ত দিতে ইচ্ছুক?</label>
                <div class="willing-toggle-row">
                    <button type="button" id="willingYesBtn" class="willing-btn willing-yes active" onclick="setWilling('yes')">✅ হ্যাঁ, দিতে রাজি আছি</button>
                    <button type="button" id="willingNoBtn"  class="willing-btn willing-no"  onclick="setWilling('no')">⛔ এখন দিতে পারব না</button>
                </div>
                <input type="hidden" id="u_willing" value="yes">
                <p class="willing-note" id="willingNote">আপনি Available হিসেবে তালিকায় থাকবেন।</p>
            </div>

            <!-- ===== SECRET CODE CHANGE SECTION ===== -->
            <div class="secret-change-wrap" id="secretChangeWrap">
                <div class="secret-change-header" onclick="toggleSecretChange()">
                    <span>🔑 Secret Code পরিবর্তন করুন</span>
                    <span class="secret-change-arrow" id="secretChangeArrow">›</span>
                </div>
                <div class="secret-change-body" id="secretChangeBody" style="display:none;">
                    <p class="secret-change-note">⚠️ নতুন Code সেট করলে পুরনো Code আর কাজ করবে না। নতুন Code অবশ্যই মনে রাখুন বা কোথাও সংরক্ষণ করুন।</p>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:10px;">
                        <span class="secret-prefix-badge">SHSMC-</span>
                        <input type="text" id="u_new_secret" placeholder="আপনার পছন্দের Code (৬-২০ অক্ষর)" maxlength="20"
                            style="margin:0; flex:1; font-family:monospace; letter-spacing:1px; font-size:1em; text-transform:uppercase;"
                            oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,''); validateNewSecret(this);">
                    </div>
                    <p class="secret-hint" id="secretHint" style="display:none;"></p>
                    <div id="newSecretPreview" style="display:none; margin-top:10px; font-size:1.1em;" class="secret-box"></div>
                </div>
            </div>

        </div>
        <input type="hidden" id="u_just_donated" value="0">
        <button type="button" onclick="submitUpdate()" style="background:var(--success); color:#000; margin-top:20px;">💾 Save Changes</button>

        <!-- ===== DELETE MY INFO SECTION ===== -->
        <div style="margin-top:28px;border-top:1px solid rgba(220,38,38,0.2);padding-top:20px;">
            <div onclick="toggleDeleteDonorSection()" style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;padding:10px 14px;background:rgba(220,38,38,0.06);border:1px solid rgba(220,38,38,0.2);border-radius:12px;user-select:none;">
                <span style="color:var(--danger);font-weight:700;font-size:0.9em;">🗑️ আমার সকল তথ্য মুছে ফেলুন</span>
                <span id="deleteDonorArrow" style="color:var(--danger);font-size:1.2em;transition:transform 0.2s;">›</span>
            </div>
            <div id="deleteDonorBody" style="display:none;margin-top:12px;padding:16px;background:rgba(220,38,38,0.04);border:1px solid rgba(220,38,38,0.15);border-radius:12px;">
                <p style="color:var(--danger);font-weight:700;font-size:0.88em;margin-bottom:6px;">⚠️ সতর্কতা — এই কাজ পূর্বাবস্থায় ফেরানো যাবে না!</p>
                <p style="color:var(--text-muted);font-size:0.83em;margin-bottom:14px;">আপনার নাম, ফোন নম্বর, রক্তের গ্রুপ, location সহ সকল তথ্য database থেকে <strong style="color:var(--danger);">চিরতরে মুছে যাবে।</strong></p>
                <label style="font-size:0.83em;color:var(--text-muted);display:block;margin-bottom:6px;">নিশ্চিত করতে নিচের বক্সে <strong style="color:var(--danger);">DELETE</strong> লিখুন:</label>
                <input type="text" id="del_donor_confirm" placeholder="DELETE" maxlength="6"
                    style="font-family:monospace;font-size:1.1em;letter-spacing:3px;text-transform:uppercase;margin-bottom:12px;"
                    oninput="this.value=this.value.toUpperCase()">
                <div id="del_donor_error" style="display:none;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;padding:8px 12px;color:var(--danger);font-size:0.82em;margin-bottom:10px;"></div>
                <button type="button" id="del_donor_btn" onclick="submitDeleteDonor()"
                    style="width:100%;background:var(--danger);color:#fff;border:none;border-radius:12px;padding:12px;font-size:0.92rem;font-weight:700;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">
                    🗑️ হ্যাঁ, আমার তথ্য সম্পূর্ণ মুছে দিন
                </button>
            </div>
        </div>
    </div>
    
    <p style="text-align:center; margin-top:25px; color:var(--text-muted); font-size: 0.9em;">
        Secret Code ভুলে গেছেন?
    </p>
    <div style="display:flex;flex-direction:column;gap:10px;max-width:360px;margin:10px auto 0;">
        <button type="button" onclick="openRequestSecretCodeModal()" style="background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.35);color:var(--info);padding:11px 16px;border-radius:12px;font-size:0.88em;font-weight:700;cursor:pointer;margin:0;box-shadow:none;width:100%;">
            📩 নতুন Secret Code-এর Request করুন
        </button>
        <button type="button" onclick="openGetSecretCodeModal()" style="background:rgba(16,185,129,0.10);border:1px solid rgba(16,185,129,0.3);color:var(--success);padding:11px 16px;border-radius:12px;font-size:0.88em;font-weight:700;cursor:pointer;margin:0;box-shadow:none;width:100%;">
            🔍 Reference Code দিয়ে Secret Code দেখুন
        </button>
    </div>
</form>
</div>
</div>
<div class="page-footer-bar"><span>🩸 © 2026 Blood Arena — All Rights Reserved.</span></div>
</div><!-- end page-register -->

<!-- ===== APP PAGE: DONORS ===== -->
<div class="app-page" id="page-donors">
<div class="app-page-header"><span class="ph-icon">👥</span> রক্তদাতার তালিকা</div>
<div class="container" id="donorListSection">  
    
<!-- Database Header -->
<div style="display:flex; align-items:center; justify-content:space-between; margin-top:20px; margin-bottom:12px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px;">
    <h3 style="margin:0; font-family:var(--font-heading); color:var(--text-main); font-size:1.5rem; font-weight:800;">👥 Donor Database</h3>
    <button onclick="resetFilters()" style="background:rgba(128,128,128,0.12);border:1px solid var(--border-color);color:var(--text-muted);padding:6px 14px;border-radius:20px;font-size:0.8em;margin:0;box-shadow:none;width:auto;min-height:unset;" title="Reset all filters">🔄 Reset</button>
</div>

<div class="quick-shift-container">
    <button class="shift-btn active" onclick="quickFilter('All')">All Groups</button>
    <?php foreach(["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"] as $g){
        echo "<button class='shift-btn' onclick=\"quickFilter('$g')\">$g</button>";
    } ?>
</div>
<div style="height:8px;"></div>

<div class="filter-container">
    <div class="filter-grid">
        <div>
            <label style="font-size: 0.85em; font-weight: 500; color:var(--text-muted); display:block; margin-bottom:6px;">Search by Name / Exact Place</label>
            <input type="text" id="searchInput" placeholder="Search name or exact location..." onkeyup="debouncedSearch()" style="margin:0;">
        </div>
        <!-- Location filter removed — always filters All Areas -->
        <input type="hidden" id="locationFilter" value="All">

        <div>
            <label style="font-size: 0.85em; font-weight: 500; color:var(--text-muted); display:block; margin-bottom:6px;">Filter by Group</label>
            <select id="groupFilter" onchange="fetchFilteredData(1)" style="margin:0;">  
                <option value="All">All Groups</option>  
                <?php foreach(["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"] as $g){ echo "<option value='$g'>$g</option>"; } ?>
            </select>
        </div>
        <div>
            <label style="font-size: 0.85em; font-weight: 500; color:var(--text-muted); display:block; margin-bottom:6px;">Live Status</label>
            <select id="statusFilter" onchange="fetchFilteredData(1)" style="margin:0;">  
                <option value="All">Show All</option>  
                <option value="Available">Available Only</option>  
                <option value="Unavailable">Not Willing (⛔)</option>
            </select>
        </div>
        <div>
            <label style="font-size: 0.85em; font-weight: 500; color:var(--text-muted); display:block; margin-bottom:6px;">🏅 Badge Level</label>
            <select id="badgeFilter" onchange="fetchFilteredData(1)" style="margin:0;">
                <option value="All">All Badges</option>
                <option value="New">🌱 New</option>
                <option value="Active">⭐ Active</option>
                <option value="Hero">🦸 Hero</option>
                <option value="Legend">👑 Legend</option>
            </select>
        </div>
    </div>
</div>

<div class="call-notice-wrapper">
    <div class="call-notice-text">
        👤রক্তদাতার সাথে যোগাযোগ করতে (📞Call) এ ক্লিক করুন। রক্তদাতার 📍Location দেখার জন্য টেবিল ডান থেকে বামে ⬅️Scroll করুন।
    </div>
</div>

<!-- Desktop table (hidden on mobile) -->
<div class="donor-table-wrapper">
<table class="donor-table">  
<thead>
    <tr>
        <th>No.</th> 
        <th>Name</th> 
        <th>Blood Group</th> 
        <th>Status</th> 
        <th>Location</th> 
        <th>Last Donation</th> 
        <th>Phone</th>
    </tr>  
</thead>
<tbody id="donorTableBody"></tbody>
</table>  
</div>

<!-- Mobile cards (hidden on desktop) -->
<div id="donorCardsBody" class="donor-cards-container"></div>  

<div id="paginationSection" class="pagination"></div>  

<div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin:30px auto 0;max-width:500px;padding:0 12px;">
<button class="report-btn-footer" onclick="openGeneralReportModal()" style="flex:1;min-width:180px;">
    ⚠️ Report Harassment
</button>
<button class="report-btn-footer" onclick="openAdminMessageModal()" style="flex:1;min-width:180px;border-color:var(--info);color:var(--info);box-shadow:0 4px 15px rgba(59,130,246,0.2);">
    💬 Message to Admin
</button>
</div>

<!-- MODAL: MESSAGE TO ADMIN -->
<div class="popup-overlay" id="adminMsgModal" style="z-index:10050;" onclick="if(event.target===this)closeAdminMsgModal()">
  <div class="popup" style="max-width:400px;padding:24px 20px;">
    <h2 style="color:var(--info);margin-bottom:6px;font-family:var(--font-heading);">💬 Admin কে Message</h2>
    <p style="font-size:0.82em;color:var(--text-muted);margin-bottom:16px;">আপনার idea বা আমাদের ত্রুটি সম্পর্কে জানান। Admin reply করলে আপনার Services notification এ আসবে।</p>
    <input type="text" id="adm_sender_name" placeholder="আপনার নাম" maxlength="100" style="margin-bottom:10px;">
    <input type="tel" id="adm_sender_phone" placeholder="+8801XXXXXXXXX" value="+8801" maxlength="14" style="margin-bottom:10px;font-family:monospace;" oninput="if(!this.value.startsWith('+880'))this.value='+880'">
    <textarea id="adm_sender_msg" rows="4" placeholder="আপনার idea বা আমাদের ত্রুটি লিখুন..." maxlength="1000" style="width:100%;padding:11px 14px;background:var(--input-bg);border:1px solid var(--border-color);border-radius:12px;color:var(--text-main);font-size:0.9em;resize:none;font-family:var(--font-body);margin-bottom:10px;box-sizing:border-box;"></textarea>
    <div id="adm_msg_error" style="display:none;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:10px;padding:9px 12px;color:var(--danger);font-size:0.82em;margin-bottom:10px;"></div>
    <div id="adm_msg_success" style="display:none;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);border-radius:10px;padding:9px 12px;color:var(--success);font-size:0.82em;margin-bottom:10px;"></div>
    <div style="display:flex;gap:10px;margin-top:4px;">
      <button onclick="closeAdminMsgModal()" style="flex:1;padding:12px;background:var(--input-bg);border:1px solid var(--border-color);color:var(--text-muted);border-radius:12px;font-size:0.88rem;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">বাতিল</button>
      <button id="adm_msg_btn" onclick="submitAdminMessage()" style="flex:2;padding:12px;background:var(--info);color:#fff;border:none;border-radius:12px;font-size:0.88rem;font-weight:700;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">📤 পাঠান</button>
    </div>
  </div>
</div>

</div>
<div class="page-footer-bar"><span>🩸 © 2026 Blood Arena — All Rights Reserved.</span></div>
</div><!-- end page-donors -->

<!-- ===== APP PAGE: NEARBY ===== -->
<div class="app-page" id="page-nearby">
<div class="app-page-header"><span class="ph-icon">📍</span> Nearby Donors & Map</div>

<!-- ==================== NEARBY DONORS SECTION ==================== -->
<div class="container nearby-section" id="nearbySection">
    <div class="section-header-row">
        <div>
            <h3 class="section-title">📍 আমার কাছের Donors</h3>
            <p class="section-sub">GPS দিয়ে কাছের রক্তদাতা খুঁজুন</p>
        </div>
        <button class="analytics-refresh-btn" id="nearbyLoadBtn" onclick="loadNearbyDonors()">📡 খুঁজুন</button>
    </div>
    <div class="nearby-controls">
        <div style="flex:1;min-width:120px;">
            <label style="font-size:0.8em;color:var(--text-muted);display:block;margin-bottom:4px;">🩸 Blood Group</label>
            <select id="nearbyGroupFilter" style="margin:0;" onchange="if(document.getElementById('nearbyResults').querySelector('.nearby-card')) loadNearbyDonors();">
                <option value="All">All Groups</option>
                <?php foreach(["A+","A-","B+","B-","AB+","AB-","O+","O-"] as $g) echo "<option>$g</option>"; ?>
            </select>
        </div>
        <div style="flex:1;min-width:120px;">
            <label style="font-size:0.8em;color:var(--text-muted);display:block;margin-bottom:4px;">🟢 Live Status</label>
            <select id="nearbyStatusFilter" style="margin:0;" onchange="if(document.getElementById('nearbyResults').querySelector('.nearby-card')) loadNearbyDonors();">
                <option value="All">সব দেখুন</option>
                <option value="Available">✔ Available</option>
                <option value="Not Available">✖ Not Available</option>
                <option value="Unavailable">⛔ Not Willing</option>
            </select>
        </div>
        <div style="flex:1;min-width:120px;">
            <label style="font-size:0.8em;color:var(--text-muted);display:block;margin-bottom:4px;">📍 Radius (km)</label>
            <select id="nearbyRadius" style="margin:0;">
                <option value="2">2 km</option>
                <option value="5" selected>5 km</option>
                <option value="10">10 km</option>
                <option value="20">20 km</option>
                <option value="50">50 km</option>
            </select>
        </div>
    </div>
    <div class="nearby-results donor-cards-container" id="nearbyResults">
        <div class="nearby-empty" style="grid-column:1/-1;">
            <div style="font-size:3rem;margin-bottom:10px;">📡</div>
            <p style="font-weight:600;margin-bottom:5px;">Location ব্যবহার করে কাছের donor খুঁজুন</p>
            <p style="font-size:0.85em;color:var(--text-muted);">উপরের বাটনে ক্লিক করুন</p>
        </div>
    </div>
</div>

<!-- ==================== MAP SECTION ==================== -->
<div class="container map-section" id="mapSection">
    <div class="section-header-row">
        <div>
            <h3 class="section-title">🗺️ Donor Map</h3>
            <p class="section-sub">রক্তদাতারা কোথায় আছেন</p>
        </div>
        <button class="analytics-refresh-btn" onclick="loadMap()">📍 Load Map</button>
    </div>

    <!-- MAP FILTERS -->
    <div class="map-filter-bar" id="mapFilterBar">
        <div class="map-filter-group">
            <label class="map-filter-label">🩸 Blood Group</label>
            <div class="map-filter-pills" id="mapGroupPills">
                <button class="map-pill active" data-val="All" onclick="setMapFilter('group','All',this)">All</button>
                <?php foreach(["A+","A-","B+","B-","AB+","AB-","O+","O-"] as $g): ?>
                <button class="map-pill" data-val="<?php echo $g; ?>" onclick="setMapFilter('group','<?php echo $g; ?>',this)"><?php echo $g; ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="map-filter-group">
            <label class="map-filter-label">🟢 Live Status</label>
            <div class="map-filter-pills" id="mapStatusPills">
                <button class="map-pill active" data-val="All" onclick="setMapFilter('status','All',this)">All</button>
                <button class="map-pill map-pill-avail" data-val="Available" onclick="setMapFilter('status','Available',this)">✔ Available</button>
                <button class="map-pill map-pill-notavail" data-val="Not Available" onclick="setMapFilter('status','Not Available',this)">✖ Not Available</button>
                <button class="map-pill map-pill-unwill" data-val="Unavailable" onclick="setMapFilter('status','Unavailable',this)">⛔ Not Willing</button>
            </div>
        </div>
        <div id="mapFilterInfo" class="map-filter-info" style="display:none;"></div>
    </div>

    <div id="mapContainer" class="map-container">
        <div class="map-placeholder" id="mapPlaceholder">
            <div style="font-size:3rem;">🗺️</div>
            <p style="font-weight:600; margin:10px 0 5px;">Map লোড করতে উপরের বাটনে ক্লিক করুন</p>
            <p style="font-size:0.82em; color:var(--text-muted);">শুধুমাত্র যেসব donors location permission দিয়েছেন তারা map-এ দেখাবে</p>
        </div>
        <div id="leafletMap" style="display:none; width:100%; height:100%; border-radius:16px;"></div>
    </div>
    <div id="mapLegend" class="map-legend" style="display:none;">
        <span class="map-legend-item"><span style="color:#10b981; font-size:1.2em;">●</span> Available</span>
        <span class="map-legend-item"><span style="color:#ef4444; font-size:1.2em;">●</span> Not Available</span>
        <span class="map-legend-item"><span style="color:#6b7280; font-size:1.2em;">●</span> Not Willing</span>
    </div>
</div>
<div class="page-footer-bar"><span>🩸 © 2026 Blood Arena — All Rights Reserved.</span></div>
</div><!-- end page-nearby -->

<!-- ===== APP PAGE: ANALYTICS ===== -->
<div class="app-page" id="page-more">
<div class="app-page-header"><span class="ph-icon">📊</span> Analytics</div>

<!-- ==================== ANALYTICS SECTION ==================== -->
<div class="container analytics-section" id="analyticsSection">
    <div class="section-header-row">
        <div>
            <h3 class="section-title">📊 Data Analytics</h3>
            <p class="section-sub">Blood Arena-র সার্বিক পরিসংখ্যান</p>
        </div>
        <button class="analytics-refresh-btn" onclick="loadAnalytics()">🔄 Refresh</button>
    </div>
    <div class="kpi-grid" id="kpiGrid">
        <div class="kpi-card kpi-total"><div class="kpi-icon">👥</div><div class="kpi-val" id="kpiTotal">—</div><div class="kpi-label">মোট Donors</div></div>
        <div class="kpi-card kpi-avail"><div class="kpi-icon">✅</div><div class="kpi-val" id="kpiAvail">—</div><div class="kpi-label">Available</div></div>
        <div class="kpi-card kpi-unav"> <div class="kpi-icon">⛔</div><div class="kpi-val" id="kpiUnav">—</div><div class="kpi-label">Not Willing</div></div>
        <div class="kpi-card kpi-calls"><div class="kpi-icon">📞</div><div class="kpi-val" id="kpiCalls">—</div><div class="kpi-label">মোট Calls</div></div>
        <div class="kpi-card kpi-req">  <div class="kpi-icon">🆘</div><div class="kpi-val" id="kpiReq">—</div><div class="kpi-label">Active Requests</div></div>
        <div class="kpi-card kpi-donated"><div class="kpi-icon">🩸</div><div class="kpi-val" id="kpiFulfilled">—</div><div class="kpi-label">Successfully Donated</div></div>
    </div>
    <div class="charts-grid">
        <div class="chart-card">
            <h4 class="chart-title">🩸 Blood Group Distribution</h4>
            <div id="bgChartWrap" class="bar-chart-wrap"></div>
        </div>
        <div class="chart-card">
            <h4 class="chart-title">🏅 Donor Badge Levels</h4>
            <div class="badge-donut-wrap">
                <canvas id="badgeDonut" width="180" height="180"></canvas>
                <div id="badgeLegend" class="badge-legend"></div>
            </div>
        </div>
    </div>
    <div class="chart-card" style="margin-top:16px;">
        <h4 class="chart-title">📍 Top Donor Areas</h4>
        <div id="locChartWrap" class="loc-chart-wrap"></div>
    </div>
</div>
<div class="page-footer-bar"><span>🩸 © 2026 Blood Arena — All Rights Reserved.</span></div>
</div><!-- end page-more -->

<!-- PWA INSTALL PROMPT -->
<div id="pwaInstallOverlay" role="dialog" aria-modal="true" aria-label="App Install Prompt">
  <div id="pwaInstallBox">
    <div class="pwa-install-inner">

      <!-- Android / Chrome: compact single row -->
      <div id="pwaAndroidContent">
        <div class="pwa-top-row">
          <img src="icon.png" alt="Blood Arena" class="pwa-app-icon">
          <div class="pwa-install-titles">
            <strong>Blood Arena</strong>
            <span>Home Screen-এ Add করুন</span>
          </div>
          <div class="pwa-top-btns">
            <button class="pwa-install-btn" onclick="pwaDoInstall()">📲 Install</button>
            <button class="pwa-dismiss-btn" onclick="pwaDismiss()">✕</button>
          </div>
        </div>
        <div class="pwa-features">
          <span class="pwa-feat-pill">⚡ দ্রুত লোড</span>
          <span class="pwa-feat-pill">📵 Offline</span>
          <span class="pwa-feat-pill">🔔 Notification</span>
          <span class="pwa-feat-pill">📱 App Feel</span>
        </div>
      </div>

      <!-- iOS Safari: step instructions -->
      <div id="pwaIOSContent" style="display:none;">
        <div class="pwa-top-row">
          <img src="icon.png" alt="Blood Arena" class="pwa-app-icon">
          <div class="pwa-install-titles">
            <strong>Home Screen-এ Add করুন</strong>
            <span>Blood Arena · iOS Safari</span>
          </div>
          <button class="pwa-dismiss-btn" onclick="pwaDismiss()" style="flex-shrink:0;">✕</button>
        </div>
        <div class="pwa-ios-steps">
          নিচের <strong>Share ⎋</strong> বাটন চাপুন →
          <strong>"Add to Home Screen"</strong> বেছে নিন →
          উপরে <strong>"Add"</strong> চাপুন
        </div>
      </div>

    </div>
  </div>
</div>

<!-- OFFLINE ALERT BANNER -->
<div id="offlineAlert">
  <span>📵 ইন্টারনেট সংযোগ নেই — Cached content দেখাচ্ছে</span>
  <button class="offline-retry-btn" onclick="offlineRetry(this)">🔄 Retry</button>
</div>

<!-- SETTINGS PANEL OVERLAY (Bottom Sheet) -->
<div class="settings-panel-overlay" id="settingsPanelOverlay" onclick="closeSettings(event)">
  <div class="settings-panel" id="settingsPanel">
    <div class="settings-panel-handle"></div>
    <div class="settings-panel-title">
      <span>⚙️ Settings</span>
      <div class="settings-title-actions">
        <button onclick="settingsReload()" class="settings-reload-btn" title="Reload page">🔄</button>
        <button onclick="closeSettingsPanel()" class="settings-close-btn" title="Close">✕</button>
      </div>
    </div>
    <div class="settings-list">

      <!-- Donation reminder hint card -->
      <div style="margin:0 0 12px;padding:12px 14px;background:linear-gradient(135deg,rgba(220,38,38,0.10),rgba(245,158,11,0.08));border:1px solid rgba(220,38,38,0.22);border-radius:12px;cursor:pointer;" onclick="closeSettingsPanel(); setTimeout(()=>{ appSwitchPage('register'); setTimeout(()=>{ switchTab(1); },200); },300);">
        <div style="display:flex;align-items:flex-start;gap:10px;">
          <span style="font-size:1.4rem;flex-shrink:0;">🩸</span>
          <div>
            <div style="font-size:0.84em;font-weight:700;color:var(--text-main);margin-bottom:4px;">রক্ত দিয়েছেন? এখনই Update করুন!</div>
            <div style="font-size:0.76em;color:var(--text-muted);line-height:1.6;">রক্ত দেওয়ার <strong style="color:var(--text-main);">সাথে সাথে বা একই দিনের মধ্যে</strong> "Update My Info"-এ গিয়ে <strong style="color:var(--text-main);">"আমি এইমাত্র রক্ত দিয়েছি 🩸"</strong> বাটন চাপুন।<br>এতে আপনার donation count ও badge update হবে এবং অন্যরা জানবে আপনি এখন available নন।</div>
            <div style="margin-top:7px;display:inline-flex;align-items:center;gap:5px;font-size:0.72em;font-weight:700;color:var(--primary-red);background:rgba(220,38,38,0.08);padding:4px 10px;border-radius:20px;border:1px solid rgba(220,38,38,0.2);">✏️ Update My Info খুলুন →</div>
          </div>
        </div>
      </div>

      <div class="settings-item si-theme" onclick="toggleTheme(); updateSettingsToggles();">
        <div class="settings-item-left">
          <div class="settings-item-icon">🌙</div>
          <div class="settings-item-text">
            <span class="settings-item-label">Dark / Light Mode</span>
            <span class="settings-item-sub">Night mode চালু/বন্ধ করুন</span>
          </div>
        </div>
        <div class="settings-toggle" id="settingsThemeToggle"></div>
      </div>
      <div class="settings-item si-sound" onclick="toggleSoundSetting()">
        <div class="settings-item-left">
          <div class="settings-item-icon">🔊</div>
          <div class="settings-item-text">
            <span class="settings-item-label">Notification Sound</span>
            <span class="settings-item-sub">Registration ও notification sound</span>
          </div>
        </div>
        <div class="settings-toggle on" id="settingsSoundToggle"></div>
      </div>
      <div class="settings-item si-autoscroll" onclick="toggleAutoScrollSetting()">
        <div class="settings-item-left">
          <div class="settings-item-icon">⬇️</div>
          <div class="settings-item-text">
            <span class="settings-item-label">Auto Scroll After Call</span>
            <span class="settings-item-sub">Call করলে next donor-এ চলে যাবে</span>
          </div>
        </div>
        <div class="settings-toggle" id="settingsAutoScrollToggle"></div>
      </div>
      <!-- Donor Card Zoom -->
      <div class="settings-item si-zoom" style="cursor:default;">
        <div class="settings-item-left">
          <div class="settings-item-icon">🔍</div>
          <div class="settings-item-text">
            <span class="settings-item-label">Donor Card Text Size</span>
            <span class="settings-item-sub">Donor list এর লেখার সাইজ</span>
          </div>
        </div>
        <div class="zoom-stepper">
          <button class="zoom-btn" onclick="changeZoom(-1)" title="Smaller">−</button>
          <span class="zoom-val" id="zoomValLabel">100%</span>
          <button class="zoom-btn" onclick="changeZoom(1)" title="Larger">+</button>
        </div>
      </div>
      <div class="settings-item si-notif" onclick="requestBrowserNotif()">
        <div class="settings-item-left">
          <div class="settings-item-icon">🔔</div>
          <div class="settings-item-text">
            <span class="settings-item-label">Browser Notifications</span>
            <span class="settings-item-sub" id="notifStatusText">নতুন blood request এলে জানুন</span>
          </div>
        </div>
        <div class="settings-item-right" id="notifStatusBadge">›</div>
      </div>
      <div class="settings-item si-loc" onclick="requestLocationSetting()">
        <div class="settings-item-left">
          <div class="settings-item-icon">📍</div>
          <div class="settings-item-text">
            <span class="settings-item-label">Location Permission</span>
            <span class="settings-item-sub" id="locStatusText">Nearby donors খুঁজতে দরকার</span>
          </div>
        </div>
        <div class="settings-item-right" id="locStatusBadge">›</div>
      </div>
      <div class="settings-item si-about" onclick="closeSettingsPanel(); openAboutUsModal();">
        <div class="settings-item-left">
          <div class="settings-item-icon">ℹ️</div>
          <div class="settings-item-text">
            <span class="settings-item-label">আমাদের কথা</span>
            <span class="settings-item-sub">Blood Arena সম্পর্কে জানুন</span>
          </div>
        </div>
        <div class="settings-item-right">›</div>
      </div>
      <div class="settings-item si-terms" onclick="closeSettingsPanel(); openTermsModal();">
        <div class="settings-item-left">
          <div class="settings-item-icon">📄</div>
          <div class="settings-item-text">
            <span class="settings-item-label">শর্তাবলী ও নীতিমালা</span>
            <span class="settings-item-sub">Terms & Conditions পড়ুন</span>
          </div>
        </div>
        <div class="settings-item-right">›</div>
      </div>
      <div class="settings-item si-install" id="settingsInstallItem" onclick="settingsInstallApp()">
        <div class="settings-item-left">
          <div class="settings-item-icon">📲</div>
          <div class="settings-item-text">
            <span class="settings-item-label">App হিসেবে Install করুন</span>
            <span class="settings-item-sub" id="installStatusText">Home Screen-এ Add করুন</span>
          </div>
        </div>
        <div class="settings-item-right" id="installStatusBadge">›</div>
      </div>
      <div class="settings-item si-faq" onclick="closeSettingsPanel(); openFAQModal();">
        <div class="settings-item-left">
          <div class="settings-item-icon">❓</div>
          <div class="settings-item-text">
            <span class="settings-item-label">প্রশ্ন ও উত্তর (FAQ)</span>
            <span class="settings-item-sub">সাধারণ প্রশ্নের উত্তর দেখুন</span>
          </div>
        </div>
        <div class="settings-item-right">›</div>
      </div>
      <div class="settings-item si-clear" onclick="clearAppData()">
        <div class="settings-item-left">
          <div class="settings-item-icon">🧹</div>
          <div class="settings-item-text">
            <span class="settings-item-label" style="color:var(--danger);">Clear App Data</span>
            <span class="settings-item-sub">Cache, token ও settings মুছে fresh reload নেবে</span>
          </div>
        </div>
        <div class="settings-item-right" style="color:var(--danger);">›</div>
      </div>
    </div>
  </div>
</div>

<!-- PUSH NOTIFICATION PROMPT — iOS-style -->
<div id="notifPrompt" class="notif-prompt">
  <div class="np-app-row">
    <div class="np-app-icon">🩸</div>
    <div class="np-text-wrap">
      <div class="np-app-name">Blood Arena</div>
      <div class="np-msg">নতুন emergency blood request হলে সাথে সাথে notification পাঠাতে চায়</div>
      <div class="np-btn-row">
        <button class="btn-deny-notif" onclick="dismissNotifPrompt()">না থাক</button>
        <button class="btn-allow-notif" onclick="enableNotifications()">✅ Allow</button>
      </div>
    </div>
  </div>
</div>

<!-- BLOOD REQUEST MODAL -->
<div class="popup-overlay" id="bloodReqModal" style="align-items:flex-end;">
    <div style="
        width:100%; max-width:580px;
        background:var(--bg-card);
        border-radius:24px 24px 0 0;
        overflow:hidden;
        transform:translateY(100%);
        transition:transform 0.22s cubic-bezier(0.32,1.1,0.64,1);
        max-height:92vh;
        display:flex; flex-direction:column;
        box-shadow:0 -12px 48px rgba(0,0,0,0.5);
        border-top:1px solid rgba(255,255,255,0.08);
        padding-bottom:env(safe-area-inset-bottom,0px);
    " id="bloodReqSheet">

        <!-- Drag handle -->
        <div style="display:flex;justify-content:center;padding:12px 0 0;">
            <div style="width:40px;height:4px;background:rgba(128,128,128,0.3);border-radius:4px;"></div>
        </div>

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px 12px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:42px;height:42px;background:linear-gradient(135deg,#dc2626,#9f1239);border-radius:12px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(220,38,38,0.4);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                </div>
                <div>
                    <div style="font-family:var(--font-heading);font-weight:800;color:var(--text-main);font-size:1.05rem;line-height:1.2;">Emergency Blood Request</div>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:1px;">সব donors-কে notify করা হবে</div>
                </div>
            </div>
            <button onclick="closeBloodReqModal()" style="background:var(--input-bg);border:1px solid var(--border-color);color:var(--text-muted);width:34px;height:34px;border-radius:10px;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;min-height:unset;box-shadow:none;margin:0;flex-shrink:0;">✕</button>
        </div>

        <!-- Divider -->
        <div style="height:1px;background:var(--border-color);margin:0 20px;"></div>

        <!-- Scrollable form body -->
        <div style="overflow-y:auto;padding:18px 20px 8px;flex:1;">

            <!-- Blood Group — big tap targets -->
            <div style="margin-bottom:16px;">
                <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:8px;">Blood Group <span style="color:#ef4444;">*</span></label>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;" id="reqGroupGrid">
                    <?php foreach(["A+","A-","B+","B-","AB+","AB-","O+","O-"] as $g): ?>
                    <button type="button" class="req-group-btn" onclick="selectReqGroup(this,'<?= $g ?>')"
                        style="height:44px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--input-bg);color:var(--text-main);font-weight:700;font-size:0.9rem;cursor:pointer;transition:all 0.15s;box-shadow:none;margin:0;padding:0;"
                        data-group="<?= $g ?>"><?= $g ?></button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" id="req_group">
            </div>

            <!-- Patient Name + Bags in one row -->
            <div style="display:grid;grid-template-columns:1fr 80px;gap:10px;margin-bottom:14px;">
                <div>
                    <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:6px;">রোগীর নাম <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="req_patient" placeholder="পুরো নাম লিখুন" autocomplete="off"
                        style="margin:0;height:46px;font-size:0.92rem;padding:0 14px;border-radius:12px;">
                </div>
                <div>
                    <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:6px;">ব্যাগ</label>
                    <input type="number" id="req_bags" value="1" min="1" max="10"
                        style="margin:0;height:46px;font-size:1rem;padding:0;text-align:center;border-radius:12px;">
                </div>
            </div>

            <!-- Hospital -->
            <div style="margin-bottom:14px;">
                <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:6px;">হাসপাতাল / Ward <span style="color:#ef4444;">*</span></label>
                <input type="text" id="req_hospital" placeholder="যেমন: DMCH, Ward 5" autocomplete="off"
                    style="margin:0;height:46px;font-size:0.92rem;padding:0 14px;border-radius:12px;">
            </div>

            <!-- Contact + Urgency -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                <div>
                    <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:6px;">যোগাযোগ <span style="color:#ef4444;">*</span></label>
                    <input type="tel" id="req_contact" placeholder="+8801XXXXXXXXX" value="+8801" autocomplete="tel"
                        style="margin:0;height:46px;font-size:0.88rem;padding:0 12px;border-radius:12px;">
                </div>
                <div>
                    <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:6px;">Urgency</label>
                    <select id="req_urgency" style="margin:0;height:46px;font-size:0.88rem;padding:0 10px;border-radius:12px;">
                        <option value="Critical">🔴 Critical</option>
                        <option value="High" selected>🟠 High</option>
                        <option value="Medium">🔵 Medium</option>
                    </select>
                </div>
            </div>

            <!-- Note -->
            <div style="margin-bottom:20px;">
                <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:6px;">অতিরিক্ত তথ্য <span style="color:var(--text-muted);font-weight:400;">(Optional)</span></label>
                <input type="text" id="req_note" placeholder="রোগের ধরন, patient condition ইত্যাদি"
                    style="margin:0;height:46px;font-size:0.88rem;padding:0 14px;border-radius:12px;">
            </div>
        </div>

        <!-- Sticky action buttons -->
        <div style="padding:12px 20px 16px;border-top:1px solid var(--border-color);display:grid;grid-template-columns:1fr 2.5fr;gap:10px;">
            <button onclick="closeBloodReqModal()" style="height:50px;background:var(--input-bg);border:1px solid var(--border-color);color:var(--text-muted);border-radius:14px;font-size:0.9rem;font-weight:600;cursor:pointer;margin:0;box-shadow:none;">বাতিল</button>
            <button onclick="submitBloodRequest()" style="height:50px;background:linear-gradient(135deg,#dc2626,#9f1239);color:#fff;border:none;border-radius:14px;font-size:0.97rem;font-weight:800;cursor:pointer;margin:0;box-shadow:0 4px 16px rgba(220,38,38,0.4);font-family:var(--font-heading);letter-spacing:0.3px;">🆘 Emergency Request পাঠান</button>
        </div>
    </div>
</div>

<!-- ========== MAP PICKER MODAL (Leaflet-based, no API key needed) ========== -->
<div class="popup-overlay" id="mapPickerModal">
    <div class="popup" style="max-width:560px;padding:0;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-color);">
            <strong style="font-family:var(--font-heading);font-size:1em;">🗺️ Map থেকে Location বেছে নিন</strong>
            <button onclick="closeMapPicker()" style="background:none;border:none;color:var(--text-muted);font-size:1.2rem;cursor:pointer;width:auto;min-height:unset;margin:0;padding:4px 8px;box-shadow:none;">✕</button>
        </div>
        <!-- Map search bar -->
        <div style="padding:8px 12px;border-bottom:1px solid var(--border-color);display:flex;gap:6px;align-items:center;">
            <input type="text" id="mapSearchInput" placeholder="🔍 এলাকার নাম লিখুন... (e.g. Mirpur, Kafrul)" style="margin:0;flex:1;font-size:0.83em;padding:8px 12px;" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();doMapSearch();}">
            <button onclick="doMapSearch()" style="margin:0;width:auto;min-height:unset;padding:8px 14px;background:rgba(59,130,246,0.15);color:#3b82f6;border:1px solid rgba(59,130,246,0.3);border-radius:10px;font-size:0.82em;font-weight:700;flex-shrink:0;box-shadow:none;">🔍 খুঁজুন</button>
        </div>
        <div style="position:relative;height:330px;">
            <div id="leafletMapPicker" style="width:100%;height:100%;"></div>
            <!-- My Location button -->
            <button id="mapMyLocBtn" onclick="mapGoToMyLocation()" title="আমার Location" style="position:absolute;bottom:12px;right:12px;z-index:999;width:40px;height:40px;border-radius:50%;background:#fff;border:none;box-shadow:0 2px 10px rgba(0,0,0,0.35);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.25rem;padding:0;margin:0;min-height:unset;">📍</button>
            <div id="mapPickerLoading" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:var(--bg-card);font-size:1.5rem;flex-direction:column;gap:8px;z-index:10;">
                <div style="font-size:2.5rem;">🗺️</div>
                <p style="font-size:0.85em;color:var(--text-muted);">Map লোড হচ্ছে...</p>
            </div>
        </div>
        <div style="padding:12px 18px;display:flex;align-items:center;gap:10px;border-top:1px solid var(--border-color);">
            <input type="text" id="mapPickerResult" placeholder="📍 Map-এ ক্লিক করুন অথবা এখানে লিখুন..." style="margin:0;flex:1;font-size:0.85em;" oninput="" autocomplete="off">
            <button onclick="useMapPickerLocation()" style="margin:0;width:auto;min-height:unset;padding:10px 16px;background:var(--success);color:#000;font-size:0.85em;font-weight:700;flex-shrink:0;">✅ ব্যবহার করুন</button>
        </div>
    </div>
</div>

<!-- ========== FAQ MODAL ========== -->
<div class="popup-overlay" id="faqModal">
    <div class="popup" style="max-width:580px;padding:0;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border-color);position:sticky;top:0;background:var(--bg-card);z-index:2;">
            <div>
                <strong style="font-family:var(--font-heading);font-size:1.1em;color:var(--text-main);">❓ প্রশ্ন ও উত্তর</strong>
                <p style="font-size:0.75em;color:var(--text-muted);margin:2px 0 0;">Blood Arena — FAQ</p>
            </div>
            <button onclick="closeFAQModal()" style="background:none;border:none;color:var(--text-muted);font-size:1.2rem;cursor:pointer;width:auto;min-height:unset;margin:0;padding:6px 10px;box-shadow:none;border-radius:8px;">✕</button>
        </div>
        <div class="scroll-content" style="padding:16px 20px;max-height:72vh;overflow-y:auto;">

            <!-- Category: Basic Usage -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:0 0 10px;">ব্যবহার পদ্ধতি</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>📖 এই পোর্টাল কীভাবে ব্যবহার করবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Blood Arena ব্যবহার করা অত্যন্ত সহজ:</p>
                    <p>• <strong>Donors দেখুন:</strong> নিচের Donors ট্যাবে যান। রক্তের গ্রুপ, Badge ও availability অনুযায়ী filter করুন।</p>
                    <p>• <strong>Register করুন:</strong> Register ট্যাবে গিয়ে আপনার তথ্য দিন — এটা সম্পূর্ণ বিনামূল্যে।</p>
                    <p>• <strong>Emergency Request:</strong> জরুরি রক্তের দরকার হলে SOS বাটন চেপে Emergency Request পাঠান। Request পাঠানোর পর একটি Delete Token পাবেন — সেটি দিয়ে পরে request মুছতে পারবেন।</p>
                    <p>• <strong>Nearby Donors:</strong> কাছের donors খুঁজতে Location চালু রেখে Nearby ট্যাবে যান।</p>
                    <p>• <strong>তথ্য মুছুন:</strong> Update My Info → Secret Code Verify → নিচে "🗑️ আমার সকল তথ্য মুছে ফেলুন" থেকে নিজেই account delete করতে পারবেন।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🩸 রক্তদাতা হিসেবে কীভাবে নিবন্ধন করবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Register ট্যাবে গিয়ে আপনার নাম, রক্তের গ্রুপ, মোবাইল নম্বর, এলাকা এবং availability status দিন। Registration সম্পূর্ণ হলে আপনাকে একটি Secret Code দেওয়া হবে। এটির Screenshot নিন এবং Copy করে Ok Press করুন। এটি পরে তথ্য update করতে কাজে লাগবে</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>✏️ আমার তথ্য কীভাবে update করবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Register → "Update My Info" ট্যাবে যান। আপনার Secret Code দিয়ে লগইন করুন, তারপর তথ্য বদলান। Availability, ফোন নম্বর, এলাকা সব বদলানো যাবে।</p>
                    <p style="margin-top:8px;padding:8px 10px;background:rgba(220,38,38,0.08);border-left:3px solid var(--primary-red);border-radius:0 6px 6px 0;"><strong>⚠️ রক্ত দেওয়ার পর:</strong> রক্ত দেওয়ার <strong>সাথে সাথে বা একই দিনের মধ্যে</strong> update করুন — <strong>"আমি এইমাত্র রক্ত দিয়েছি 🩸"</strong> বাটন চেপে Save করুন। এতে আপনার donation count বাড়বে এবং আপনি ১২০ দিনের জন্য "Not Available" হবেন।</p>
                </div>
            </div>

            <!-- Category: Location -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:18px 0 10px;">Location ও Permission</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>📍 Location কেন নেওয়া হয়?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Location দুটো কারণে ব্যবহার করা হয়:</p>
                    <p>• <strong>Nearby Donors:</strong> আপনার কাছাকাছি (নির্দিষ্ট km-এর মধ্যে) কোন donors আছেন তা খুঁজে বের করতে।</p>
                    <p>• <strong>নিরাপত্তা:</strong> Emergency Request বা Registration-এর সময় IP/Location log করা হয় — এটি জালিয়াতি ও স্প্যাম প্রতিরোধের জন্য।</p>
                    <p><em>আপনার location কখনো তৃতীয় পক্ষকে দেওয়া হয় না।</em></p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🚫 Location permission দিতে না পারলে কী করবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Location ছাড়াও বেশিরভাগ feature কাজ করে। শুধু Nearby Donors কাজ করবে না।</p>
                    <p><strong>Browser-এ Permission চালু করতে:</strong></p>
                    <p>• Chrome: Address bar-এ 🔒 আইকনে ক্লিক → Site settings → Location → Allow</p>
                    <p>• Firefox: Address bar-এ 🔒 আইকনে ক্লিক → Connection secure → More info → Permissions → Access your location → Allow</p>
                    <p>• Safari (iOS): Settings → Safari → Location → Allow</p>
                    <p>• Chrome (Android): Settings → Site settings → Location → Allow</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🗺️ Map-এ location pick করবো কীভাবে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Registration form-এ 🗺️ বাটন চেপে Map Picker খুলুন। Map-এ আপনার এলাকায় ক্লিক করুন অথবা নিচের 📍 বাটন চেপে GPS থেকে auto-detect করুন। Address confirm হলে "✅ ব্যবহার করুন" চাপুন।</p>
                </div>
            </div>

            <!-- Category: Notifications -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:18px 0 10px;">Notification ও Sound</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🔔 Notification কীভাবে চালু করবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Settings → Browser Notifications-এ ক্লিক করুন। Browser একটি permission popup দেখাবে — "Allow" চাপুন। এরপর নতুন Blood Request এলে সরাসরি phone-এ notification আসবে, এমনকি browser বন্ধ থাকলেও।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🔇 Notification sound বন্ধ করবো কীভাবে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Settings → Notification Sound toggle টি বন্ধ করুন। এরপর Registration এবং নতুন Blood Request — সব sound বন্ধ হয়ে যাবে।</p>
                </div>
            </div>

            <!-- Category: Privacy & Security -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:18px 0 10px;">Privacy ও নিরাপত্তা</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🔒 আমার ফোন নম্বর কি সবার কাছে দেখা যাবে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>হ্যাঁ। Donor list-এ আপনার নাম ও ফোন নম্বর দেখা যাবে — এটাই এই পোর্টালের উদ্দেশ্য, যাতে রোগীর স্বজনরা সরাসরি যোগাযোগ করতে পারেন। তবে আপনার Secret Code কখনো প্রদর্শিত হয় না।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🗑️ আমার তথ্য কি মুছে ফেলা যাবে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>হ্যাঁ, দুটো উপায়ে:</p>
                    <p>• <strong>Unavailable করুন:</strong> Update My Info-এ গিয়ে availability "⛔ এখন দিতে পারব না" করুন — এতে আপনি list-এ দেখাবেন না কিন্তু তথ্য থাকবে।</p>
                    <p>• <strong>সম্পূর্ণ মুছুন:</strong> Update My Info → Secret Code দিয়ে Verify করুন → নিচে স্ক্রোল করুন → <strong>"🗑️ আমার সকল তথ্য মুছে ফেলুন"</strong> section খুলুন → DELETE লিখে confirm করুন। আপনার নাম, ফোন, রক্তের গ্রুপ সহ সকল তথ্য চিরতরে মুছে যাবে।</p>
                </div>
            </div>

            <!-- Category: Emergency Blood Request -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:18px 0 10px;">Emergency Blood Request</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🆘 Emergency Request পাঠানোর পর কীভাবে মুছব?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Request submit করার সাথে সাথে একটি <strong>6-digit Delete Token</strong> দেওয়া হয় (যেমন: 482917)। এই Token অবশ্যই সেভ করুন।</p>
                    <p>পরে Request মুছতে:</p>
                    <p>• Home-এ "📋 Active Requests দেখুন" বাটনে ক্লিক করুন।</p>
                    <p>• <strong>"👤 আমার Request"</strong> tab-এ যান — নিজের card দেখতে পাবেন।</p>
                    <p>• <strong>"🗑️ আমার Request মুছুন"</strong> বাটনে ক্লিক করুন।</p>
                    <p>• Delete Token দিলে Request সাথে সাথে মুছে যাবে।</p>
                    <p><em>⚠️ Token হারিয়ে ফেললে request নিজে মুছতে পারবেন না — ৭২ ঘণ্টা পর স্বয়ংক্রিয়ভাবে Expire হয়ে যাবে।</em></p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🩸 Active Requests-এ কীভাবে filter করবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Active Requests section-এ দুই ধরনের filter আছে:</p>
                    <p>• <strong>🩸 সব:</strong> সকল active request দেখাবে।</p>
                    <p>• <strong>👤 আমার Request:</strong> শুধু আপনার পাঠানো request দেখাবে — এখান থেকেই delete করা যাবে।</p>
                    <p>• <strong>Blood Group Filter (A+, B+, O+ ...):</strong> নির্দিষ্ট গ্রুপের request আলাদা করে দেখতে পারবেন। একটি group-এ ক্লিক করলে highlight হয়, আবার ক্লিক করলে clear হয়।</p>
                    <p><em>💡 Tab ও Blood Group filter একসাথে ব্যবহার করা যায়।</em></p>
                </div>
            </div>

            <!-- Category: Settings -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:18px 0 10px;">Settings</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>⚙️ Settings-এ কী কী option আছে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Settings panel-এ নিচের option গুলো পাবেন:</p>
                    <p>• <strong>🌙 Dark / Light Mode:</strong> রাতে পড়তে সুবিধার জন্য Dark mode চালু করুন।</p>
                    <p>• <strong>🔊 Notification Sound:</strong> Registration success ও নতুন blood request-এর sound চালু/বন্ধ করুন।</p>
                    <p>• <strong>⬇️ Auto Scroll After Call:</strong> কোনো donor-কে call করার পর automatically পরের donor-এ scroll করবে। একসাথে অনেকজনকে call করার সময় কাজে আসে।</p>
                    <p>• <strong>🔍 Donor Card Text Size:</strong> Donor list-এর লেখা বড় বা ছোট করুন (+/− বাটন দিয়ে)।</p>
                    <p>• <strong>🔔 Browser Notifications:</strong> নতুন Emergency Blood Request এলে phone-এ notification পাঠাবে।</p>
                    <p>• <strong>📍 Location Permission:</strong> Nearby Donors feature-এর জন্য GPS চালু করুন।</p>
                    <p>• <strong>🧹 Clear App Data:</strong> Cache, token ও সব settings মুছে app fresh করে reload নেবে — কোনো সমস্যা হলে এটি ব্যবহার করুন।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>⬇️ Auto Scroll After Call কীভাবে কাজ করে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Settings → <strong>"Auto Scroll After Call"</strong> চালু থাকলে, কোনো donor-কে call করার পর list স্বয়ংক্রিয়ভাবে <strong>পরের donor-এ</strong> চলে যাবে।</p>
                    <p><strong>Off থাকলে</strong> scroll হয় না — পেজ যেখানে ছিল সেখানেই থাকবে।</p>
                    <p>Call করা donor-এর button-এ <strong>✅ চিহ্ন</strong> যোগ হয় এবং Call icon থাকে — যাতে বোঝা যায় আগে call হয়েছে, আবার call করতে চাইলেও করা যাবে।</p>
                    <p>পরের available donor-এর Call button কয়েকবার <strong>দ্রুত blink</strong> করে — এটি দেখিয়ে দেয় এরপর কাকে call করতে হবে।</p>
                    <p><em>💡 Tip: Blood group filter করে রাখুন, তারপর Auto Scroll চালু করলে সবচেয়ে বেশি সুবিধা পাবেন।</em></p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🧹 Clear App Data কী করে? কখন ব্যবহার করব?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Settings → <strong>"🧹 Clear App Data"</strong> চাপলে নিচের সব কিছু একসাথে মুছে যাবে এবং app fresh হয়ে reload নেবে:</p>
                    <p>• <strong>LocalStorage ও SessionStorage</strong> — সেভ করা token, preferences, dismissed notifications সব</p>
                    <p>• <strong>Service Worker ও Cache</strong> — পুরনো cached files মুছে সার্ভার থেকে নতুন করে লোড হবে</p>
                    <p><strong>কখন ব্যবহার করবেন:</strong></p>
                    <p>• App আটকে গেলে বা সঠিকভাবে কাজ না করলে</p>
                    <p>• Update দেওয়ার পরেও পুরনো version দেখালে</p>
                    <p>• "আমার Request" tab-এ data না দেখালে</p>
                    <p>• যেকোনো অদ্ভুত সমস্যায়</p>
                    <p><em>⚠️ এটি আপনার donation তথ্য বা database-এর কিছু মুছবে না — শুধু browser-এ জমা local data clear হবে।</em></p>
                </div>
            </div>

            <!-- Category: Badge System -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:18px 0 10px;">Badge System</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🏅 Badge system কী? কীভাবে পাবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Badge হলো রক্তদানের অভিজ্ঞতার স্বীকৃতি। মোট রক্তদানের সংখ্যার উপর ভিত্তি করে badge নির্ধারিত হয়:</p>
                    <p>• <strong>🌱 New</strong> — ০–১ বার &nbsp;&nbsp;|&nbsp;&nbsp; নতুন donor হিসেবে স্বাগতম!</p>
                    <p>• <strong>⭐ Active</strong> — ২–৪ বার &nbsp;&nbsp;|&nbsp;&nbsp; নিয়মিত দাতা।</p>
                    <p>• <strong>🦸 Hero</strong> — ৫–৯ বার &nbsp;&nbsp;|&nbsp;&nbsp; সত্যিকারের রক্তবীর!</p>
                    <p>• <strong>👑 Legend</strong> — ১০+ বার &nbsp;&nbsp;|&nbsp;&nbsp; কিংবদন্তি দাতা!</p>
                    <p>Badge আপনার নামের পাশে donor list-এ সবার কাছে দেখা যায়।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🔄 Badge কীভাবে update হবে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p><strong>Register → Update My Info</strong>-এ যান। Secret Code দিয়ে login করুন, তারপর <strong>"আজ রক্ত দিয়েছি ✅"</strong> চেকবক্সটি tick করুন এবং Last Donation date দিন।</p>
                    <p>Save করলে donation count বাড়বে এবং প্রয়োজনীয় সংখ্যায় পৌঁছালে Badge স্বয়ংক্রিয়ভাবে upgrade হবে।</p>
                    <p><em>নতুন registration-এর সময়েও আগের donation count দেওয়া যায়।</em></p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>⏰ রক্ত দেওয়ার পর কতক্ষণের মধ্যে update করতে হবে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>রক্ত দেওয়ার <strong>সাথে সাথে বা একই দিনের মধ্যে</strong> update করুন — এটাই সবচেয়ে ভালো।</p>
                    <p>কারণ:</p>
                    <p>• <strong>Availability সঠিক থাকে:</strong> রক্ত দেওয়ার পর আপনি ১২০ দিন পর্যন্ত "Not Available" — এটা system-এ আপডেট না হলে রোগীর স্বজনরা আপনাকে call করতে পারেন, কিন্তু আপনি দিতে পারবেন না।</p>
                    <p>• <strong>Donation count সঠিক থাকে:</strong> একই দিনে update করলেই system সঠিকভাবে count গণনা করতে পারে।</p>
                    <p>• <strong>Badge upgrade হয়:</strong> পরের দিন বা অনেক পরে update করলে count ঠিকমতো নাও হতে পারে।</p>
                    <p style="margin-top:8px;padding:8px 10px;background:rgba(220,38,38,0.08);border-left:3px solid var(--primary-red);border-radius:0 6px 6px 0;"><strong>💡 টিপস:</strong> রক্ত দিয়ে hospital থেকে বের হওয়ার আগেই phone খুলে Blood Arena-তে update করে নিন — মাত্র ১ মিনিটের কাজ।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>📋 রক্ত দেওয়ার পর update করার ধাপগুলো কী?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>রক্ত দেওয়ার পর নিচের ধাপ অনুসরণ করুন:</p>
                    <p><strong>১।</strong> Register ট্যাব → <strong>"Update My Info"</strong> ট্যাবে যান।</p>
                    <p><strong>২।</strong> আপনার <strong>Secret Code</strong> দিয়ে Verify করুন।</p>
                    <p><strong>৩।</strong> <strong>🩸 "আমি এইমাত্র রক্ত দিয়েছি"</strong> বাটনে চাপুন — আজকের তারিখ ও Willing: Yes স্বয়ংক্রিয়ভাবে set হয়ে যাবে।</p>
                    <p><strong>৪।</strong> <strong>"Save Changes"</strong> চাপুন।</p>
                    <p>এতে আপনার donation count বাড়বে, badge update হবে এবং আপনি পরের ১২০ দিনের জন্য "Not Available" হিসেবে mark হবেন — যাতে এই সময়ে কেউ unnecessarily call না করে।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🔍 Badge দিয়ে কি filter করা যায়?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>হ্যাঁ! Donors ট্যাবে Badge filter আছে। New, Active, Hero বা Legend — যেকোনো badge-এর donor আলাদা করে দেখা যাবে।</p>
                    <p>Hero বা Legend filter করলে অভিজ্ঞ donors পাবেন — তারা রক্তদানে অভ্যস্ত, তাই সফলভাবে দেওয়ার সম্ভাবনা বেশি।</p>
                </div>
            </div>

            <!-- Category: গোপনীয়তা ও Permission -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:18px 0 10px;">গোপনীয়তা ও Permission</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🔔 Notification Permission কেন চাওয়া হয়?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Blood Arena নতুন Emergency Blood Request আসলে আপনাকে সাথে সাথে phone notification পাঠাতে পারে — এর জন্য permission দরকার।</p>
                    <p><strong>Allow করলে:</strong> নতুন request এলে আপনার notification bar-এ alert আসবে।</p>
                    <p><strong>Deny করলেও:</strong> App সম্পূর্ণ কাজ করবে, শুধু push notification আসবে না। In-app notification panel থেকে দেখতে পারবেন।</p>
                    <p style="margin-top:8px;padding:8px 10px;background:rgba(245,158,11,0.08);border-left:3px solid var(--accent-orange);border-radius:0 6px 6px 0;"><strong>⚠️ নোট:</strong> Allow বা Deny — উভয় ক্ষেত্রেই আপনার Device ID সংরক্ষণ করা হয়, যাতে ভবিষ্যতে সব device-এ push notification পাঠানো যায়।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>📍 Location Permission কেন চাওয়া হয়?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Location permission দুটি কাজে ব্যবহার হয়:</p>
                    <p>• <strong>Nearby Donors:</strong> আপনার কাছের রক্তদাতা GPS দিয়ে খুঁজে বের করতে।</p>
                    <p>• <strong>Registration location log:</strong> Donor হিসেবে register করার সময় আপনার approximate GPS position সংরক্ষণ হয় — যাতে ম্যাপে দেখানো যায়।</p>
                    <p><strong>Deny করলেও:</strong> App চলবে, শুধু Nearby Donors ও Map feature কাজ করবে না।</p>
                    <p style="margin-top:8px;padding:8px 10px;background:rgba(245,158,11,0.08);border-left:3px solid var(--accent-orange);border-radius:0 6px 6px 0;"><strong>⚠️ নোট:</strong> Location allow বা deny — উভয় ক্ষেত্রেই Device ID সংরক্ষণ হয়।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🆔 Device ID কী? কেন সংরক্ষণ হয়?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Device ID হলো আপনার browser-এ তৈরি একটি unique anonymous identifier। এটি কোনো personal তথ্য (নাম, ফোন নম্বর) ধারণ করে না।</p>
                    <p><strong>কেন দরকার:</strong></p>
                    <p>• Push notification পাঠাতে — যাতে Emergency request এলে সব device-এ alert যায়।</p>
                    <p>• Services notification (Secret Code approval, Admin reply) আপনার নির্দিষ্ট device-এ পৌঁছাতে।</p>
                    <p><strong>কখন সংরক্ষণ হয়:</strong> Page load হওয়ার সাথে সাথে এবং Notification / Location permission Allow বা Deny করার সময়।</p>
                    <p><strong>মুছতে চাইলে:</strong> Settings → <b>🧹 Clear App Data</b> চাপুন — এতে Device ID সহ সব local data মুছে যাবে।</p>
                </div>
            </div>

            <!-- Category: Technical সমস্যা -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:18px 0 10px;">Technical সমস্যা</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>⚠️ পেজ লোড হচ্ছে না / কাজ করছে না?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>• Browser refresh করুন (Pull-to-refresh বা F5)</p>
                    <p>• Internet connection চেক করুন</p>
                    <p>• Browser cache clear করুন: Chrome → Settings → Privacy → Clear browsing data</p>
                    <p>• অন্য browser-এ try করুন (Chrome recommended)</p>
                    <p>• সমস্যা থাকলে উপরের যোগাযোগ নম্বরে call করুন</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>📱 Mobile-এ ভালো কাজ করে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>হ্যাঁ, Blood Arena সম্পূর্ণ mobile-friendly। নিচের bottom navigation bar দিয়ে সব section-এ যাওয়া যাবে। Chrome বা Samsung Internet browser-এ সবচেয়ে ভালো কাজ করে। "Add to Home Screen" করলে app-এর মতো ব্যবহার করা যাবে।</p>
                </div>
            </div>

            <!-- Category: Secret Code System -->
            <p style="font-size:0.7em;text-transform:uppercase;letter-spacing:2px;color:var(--primary-red);font-weight:700;margin:18px 0 10px;">Secret Code ও নিরাপত্তা</p>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>📝 Register করার পর Secret Code কোথায় পাবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Registration সম্পন্ন হওয়ার পর success popup-এ Secret Code সরাসরি দেখানো হয় না — সুরক্ষার জন্য এটি Admin-approved প্রক্রিয়ায় দেওয়া হয়।</p>
                    <p><strong>পাওয়ার ধাপ:</strong></p>
                    <p><strong>১।</strong> Success popup-এর <b>"📩 এখনই Secret Code Request করুন"</b> বাটনে চাপুন।</p>
                    <p><strong>২।</strong> আপনার Phone Number ও একটি <b>৪ সংখ্যার Reference Code</b> সেট করুন (যেটা শুধু আপনি জানেন)।</p>
                    <p><strong>৩।</strong> Admin approve করলে আপনার <b>Services</b> notification-এ Secret Code আসবে।</p>
                    <p><strong>৪।</strong> এরপর "Update My Info" → <b>"🔍 Reference Code দিয়ে Secret Code দেখুন"</b> থেকেও দেখতে পারবেন।</p>
                    <p style="margin-top:8px;padding:8px 10px;background:rgba(59,130,246,0.08);border-left:3px solid var(--info);border-radius:0 6px 6px 0;"><strong>💡 টিপস:</strong> Registration-এর পরপরই Request করুন — যাতে Admin দ্রুত approve করতে পারেন।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🔑 Secret Code কী? কেন দরকার?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Secret Code হলো আপনার Blood Arena account-এর একমাত্র চাবি। এটি দিয়ে:</p>
                    <p>• <strong>তথ্য update</strong> করতে পারবেন (নাম, location, availability)</p>
                    <p>• <strong>Donation record</strong> করতে পারবেন</p>
                    <p>• <strong>Account delete</strong> করতে পারবেন</p>
                    <p style="margin-top:8px;padding:8px 10px;background:rgba(220,38,38,0.08);border-left:3px solid var(--primary-red);border-radius:0 6px 6px 0;"><strong>⚠️ গুরুত্বপূর্ণ:</strong> Registration সম্পূর্ণ হলে Secret Code দেখাবে। সাথে সাথে <strong>Copy করুন বা Screenshot নিন</strong> — OK চাপার পর আর দেখা যাবে না।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>😱 Secret Code ভুলে গেলে কী করবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Secret Code হারিয়ে ফেললে নিচের পদ্ধতিতে নতুন code পেতে পারবেন:</p>
                    <p><strong>ধাপ ১:</strong> Register → "Update My Info" → নিচে <strong>"📩 নতুন Secret Code-এর Request করুন"</strong> বাটনে চাপুন।</p>
                    <p><strong>ধাপ ২:</strong> আপনার Registered ফোন নম্বর দিন এবং একটি <strong>নিজের পছন্দের ৪ সংখ্যার Reference Code</strong> সেট করুন (যেমন: ১২৩৪)। এই ৪ সংখ্যাটি মনে রাখুন।</p>
                    <p><strong>ধাপ ৩:</strong> Request পাঠান। Admin review করে approve করলে আপনার <strong>Services notification</strong> (🔔 bell → ⚙️ Services tab)-এ জানাবে।</p>
                    <p><strong>ধাপ ৪:</strong> Approved হলে <strong>"🔍 Reference Code দিয়ে Secret Code দেখুন"</strong> বাটনে গিয়ে ফোন নম্বর ও ৪ সংখ্যার Reference Code দিন — নতুন Secret Code দেখতে পাবেন।</p>
                    <p style="margin-top:8px;padding:8px 10px;background:rgba(245,158,11,0.08);border-left:3px solid var(--accent-orange);border-radius:0 6px 6px 0;"><strong>⚠️ সীমাবদ্ধতা:</strong> একটি Reference Code দিয়ে সর্বোচ্চ <strong>৩ বার</strong> Secret Code দেখা যাবে। ৩ বারের পর সেই Reference Code expire হয়ে যাবে এবং নতুন request করতে হবে।</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>⏳ Reference Code expire হয়ে গেলে কী করবো?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>Reference Code ৩ বার ব্যবহারের পর <strong>স্বয়ংক্রিয়ভাবে expire</strong> হয়ে যায় — এটি নিরাপত্তার জন্য।</p>
                    <p>Expired হলে আবার নতুন করে <strong>"📩 নতুন Secret Code-এর Request করুন"</strong> দিয়ে আরেকটি request করুন এবং নতুন ৪ সংখ্যার Reference Code সেট করুন।</p>
                    <p><em>💡 Secret Code পেয়ে গেলে সাথে সাথে copy করে নিন — বারবার request এর ঝামেলা এড়াতে।</em></p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-q" onclick="toggleFaq(this)">
                    <span>🔐 Secret Code নিরাপদে রাখবো কীভাবে?</span>
                    <span class="faq-arrow">›</span>
                </div>
                <div class="faq-a">
                    <p>• <strong>Screenshot নিন:</strong> Registration popup থেকে সাথে সাথে screenshot নিন।</p>
                    <p>• <strong>Notes-এ সেভ করুন:</strong> Phone এর Notes app-এ "Blood Arena Secret Code: SHSMC-XXXX" লিখে রাখুন।</p>
                    <p>• <strong>কাউকে দেবেন না:</strong> Secret Code আপনার একার — কাউকে শেয়ার করবেন না।</p>
                    <p>• <strong>Admin কখনো চাইবে না:</strong> Admin কখনো আপনার Secret Code চাইবে না — কেউ চাইলে বুঝবেন সেটি প্রতারণা।</p>
                </div>
            </div>

            <div style="text-align:center;padding:20px 0 5px;color:var(--text-muted);font-size:0.8em;">
                <p>আরও প্রশ্ন থাকলে: <a href="tel:01518981827" style="color:var(--primary-red);font-weight:700;">০১৫১৮৯৮১৮২৭</a></p>
                <p style="margin-top:4px;opacity:0.5;">Blood Arena — v2.7.0</p>
            </div>
        </div>
    </div>
</div>

<!-- ========== GPS PERMISSION PROMPT (soft, non-blocking) ========== -->
<div class="popup-overlay" id="gpsPermPrompt">
    <div class="popup" style="max-width:420px;">
        <div style="font-size:3rem;text-align:center;margin-bottom:10px;">📍</div>
        <h3 style="text-align:center;color:var(--text-main);font-family:var(--font-heading);margin-bottom:10px;">Location Permission</h3>
        <p id="gpsPromptMsg" style="text-align:center;color:var(--text-muted);font-size:0.9em;line-height:1.6;margin-bottom:20px;">আপনার location log করা হবে — এটি শুধুমাত্র নিরাপত্তা ও জালিয়াতি প্রতিরোধের জন্য।</p>
        <div style="display:flex;gap:10px;">
            <button id="gpsAllowBtn" onclick="gpsAllow()" style="flex:1;background:var(--primary-red);color:#fff;margin:0;">📍 Allow</button>
            <button onclick="gpsSkip()" style="flex:1;background:transparent;border:1px solid var(--border-color);color:var(--text-muted);margin:0;box-shadow:none;">এড়িয়ে যান</button>
        </div>
        <p style="text-align:center;font-size:0.72em;color:var(--text-muted);margin-top:12px;">Location দিলে Nearby Donors feature আরো ভালো কাজ করবে।</p>
    </div>
</div>

<footer class="site-footer">

<!-- ==================== SMART INTERACTIVE LINKS ==================== -->
<div class="footer-links">
    <a href="#" onclick="openTermsModal(); return false;">📄 শর্তাবলী ও নীতিমালা</a>
    <a href="#" onclick="openAboutUsModal(); return false;">ℹ️ আমাদের কথা (About Us)</a>
</div>
<div style="margin-top:25px; font-size: 0.85em; color: #64748b;">&copy; <?php echo date("Y"); ?> Blood Arena. All rights reserved. &nbsp;|&nbsp; <span style="opacity:0.5;font-size:0.9em;">v2.5.8</span></div>
</footer>

<script>
// ============================================================
// GLOBAL CONSTANTS — defined first, used everywhere
// ============================================================
const CSRF_TOKEN = '<?php echo $_SESSION["csrf_token"] ?? ""; ?>';

// ============================================================
// safeJSON — InfinityFree HTML injection থেকে সুরক্ষা
// InfinityFree response-এর আগে বা পরে HTML inject করে।
// প্রথম { বা [ থেকে শেষ } বা ] পর্যন্ত extract করে parse করা হয়।
// ============================================================
function safeJSON(r) {
    return r.text().then(function(text) {
        // Direct parse — সবচেয়ে ভালো case
        try { return JSON.parse(text); }
        catch(e) {}

        // InfinityFree আগে বা পরে HTML inject করে।
        // প্রথম { খুঁজে শেষ } পর্যন্ত নাও
        var firstObj = text.indexOf('{');
        var lastObj  = text.lastIndexOf('}');
        if (firstObj !== -1 && lastObj > firstObj) {
            try { return JSON.parse(text.substring(firstObj, lastObj + 1)); }
            catch(e2) {}
        }

        // Array response — প্রথম [ থেকে শেষ ]
        var firstArr = text.indexOf('[');
        var lastArr  = text.lastIndexOf(']');
        if (firstArr !== -1 && lastArr > firstArr) {
            try { return JSON.parse(text.substring(firstArr, lastArr + 1)); }
            catch(e3) {}
        }

        // শেষ চেষ্টা — শেষ } পর্যন্ত (পুরনো logic, fallback)
        if (lastObj > 0) {
            try { return JSON.parse(text.substring(0, lastObj + 1)); }
            catch(e4) {}
        }

        // সব fail — raw text console-এ log করো debug-এর জন্য
        console.warn('[safeJSON] parse failed. Raw response:', text.substring(0, 300));
        return { status: 'error', msg: 'Response parse করা যায়নি। আবার চেষ্টা করুন।' };
    });
}

// ============================================================
// SCROLL LOCK — prevents background scroll when any popup/overlay is open
// ============================================================
let _scrollLockCount = 0;
function lockBodyScroll() {
    _scrollLockCount++;
    if (_scrollLockCount === 1) {
        const scrollY = window.scrollY;
        document.body.style.position = 'fixed';
        document.body.style.top = '-' + scrollY + 'px';
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.overflow = 'hidden';
        document.body.dataset.scrollY = scrollY;
    }
}
function unlockBodyScroll() {
    _scrollLockCount = Math.max(0, _scrollLockCount - 1);
    if (_scrollLockCount === 0) {
        const scrollY = parseInt(document.body.dataset.scrollY || '0', 10);
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.overflow = '';
        window.scrollTo(0, scrollY);
    }
}
function openOverlay(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('active');
    lockBodyScroll();
}
function closeOverlay(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const wasActive = el.classList.contains('active');
    el.classList.remove('active');
    if (wasActive) unlockBodyScroll();
}

// ── Global scroll-lock via MutationObserver on body ──
// Watches for any .popup-overlay or .settings-panel-overlay gaining/losing 'active'
(function() {
    function syncScrollLock() {
        const anyOpen = document.querySelector('.popup-overlay.active, .settings-panel-overlay.active');
        if (anyOpen) {
            if (document.body.dataset.scrollLocked !== '1') {
                document.body.dataset.scrollLocked = '1';
                const scrollY = window.scrollY;
                document.body.style.position = 'fixed';
                document.body.style.top = '-' + scrollY + 'px';
                document.body.style.left = '0';
                document.body.style.right = '0';
                document.body.style.overflow = 'hidden';
                document.body.dataset.scrollY = scrollY;
            }
        } else {
            if (document.body.dataset.scrollLocked === '1') {
                document.body.dataset.scrollLocked = '0';
                const scrollY = parseInt(document.body.dataset.scrollY || '0', 10);
                document.body.style.position = '';
                document.body.style.top = '';
                document.body.style.left = '';
                document.body.style.right = '';
                document.body.style.overflow = '';
                window.scrollTo(0, scrollY);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const observer = new MutationObserver(syncScrollLock);
        observer.observe(document.body, { subtree: true, attributeFilter: ['class'], attributes: true });
        syncScrollLock();
    });
})();

// ── Clear App Data: strip ?_cache_bust= from URL after fresh reload ──
(function(){
    if (window.location.search.indexOf('_cache_bust=') !== -1) {
        var clean = window.location.origin + window.location.pathname;
        window.history.replaceState(null, '', clean);
    }
})();

// Prevent backdrop click closing the popup when secret code hasn't been copied yet
document.addEventListener('DOMContentLoaded', function() {
    var popupOverlay = document.getElementById('popup');
    if (popupOverlay) {
        popupOverlay.addEventListener('click', function(e) {
            if (e.target === popupOverlay) {
                // Only allow backdrop-close if: not a success popup, OR secret already copied
                if (lastStatus === 'success' && !secretCopied) {
                    // Shake the copy button to draw attention
                    var copyBtn = document.querySelector('.copy-btn');
                    if (copyBtn) {
                        copyBtn.style.animation = 'none';
                        copyBtn.offsetWidth; // reflow
                        copyBtn.style.animation = 'pulse-red 0.4s ease 3';
                        setTimeout(function() { copyBtn.style.animation = ''; }, 1200);
                    }
                    return; // Block close
                }
                closePopup();
            }
        });
    }
});

// === THEME TOGGLE LOGIC ===
// Search debounce — prevents server request on every keypress
let _searchTimer = null;
function debouncedSearch() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => fetchFilteredData(1), 400);
}

// ============================================================
// NAVIGATION — smooth scroll + active state + mobile menu
// ============================================================
function navGo(sectionId) {
    const el = document.getElementById(sectionId);
    if (!el) return;

    // Only header now (nav bar removed)
    const hdrH = (document.querySelector('header') || {offsetHeight:76}).offsetHeight;
    const top  = el.getBoundingClientRect().top + window.scrollY - hdrH - 8;
    window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });

    // Donors section — always trigger fresh load
    if (sectionId === 'donorListSection') fetchFilteredData(1);
}



function toggleTheme() {
    const htmlObj = document.documentElement;
    const isDark  = htmlObj.getAttribute('data-theme') !== 'light';
    if (isDark) {
        htmlObj.setAttribute('data-theme', 'light');
        localStorage.setItem('theme', 'light');
    } else {
        htmlObj.removeAttribute('data-theme');
        localStorage.setItem('theme', 'dark');
    }
    // Sync settings panel toggle state + icon
    if (typeof updateSettingsToggles === 'function') updateSettingsToggles();
    // Redraw badge donut for correct bg color
    if (typeof renderBadgeDonut === 'function' && window._lastBadgeData) renderBadgeDonut(window._lastBadgeData);
    // Update Leaflet map tile layer to match new theme
    if (leafletMap && window._mapTileLayer) {
        var _newTileUrl = 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
        leafletMap.removeLayer(window._mapTileLayer);
        window._mapTileLayer = L.tileLayer(_newTileUrl, {
            attribution: '© OpenStreetMap © CARTO',
            subdomains: 'abcd',
            maxZoom: 19
        });
        window._mapTileLayer.addTo(leafletMap);
        window._mapTileLayer.bringToBack();
        // Re-apply filters so markers use correct color after theme change
        if (_allMapMarkers.length > 0) setTimeout(function() { applyMapFilter(); }, 100);
        setTimeout(function() { if (leafletMap) leafletMap.invalidateSize(); }, 100);
    }
}
// Apply saved theme immediately on parse
(function(){
    if (localStorage.getItem('theme') === 'light') {
        document.documentElement.setAttribute('data-theme','light');
    }
})();
// Sync settings icon on load (in case page loads in light mode)
window.addEventListener('DOMContentLoaded', function() {
    if (typeof updateSettingsToggles === 'function') updateSettingsToggles();
});

// Smart location search with suggestion dropdown


document.addEventListener('mousedown', e => {
    const opt = e.target.closest('.sug-opt[data-v]');
    if (opt) {
        e.preventDefault();
        const v=opt.dataset.v, inp=opt.dataset.inp, sel=opt.dataset.sel;
        document.getElementById(inp).value = v;
        const s = document.getElementById(sel);
        if (s && s.options) { Array.from(s.options).forEach(o => { if(o.value===v) s.value=v; }); }
        else if (s) { s.value = v; }
        const sb = document.getElementById('sb_'+inp);
        if (sb) sb.classList.remove('on');
        if(sel==='locationFilter') fetchFilteredData(1);
    }
});
document.addEventListener('click', e => {
    document.querySelectorAll('.sug-list.on').forEach(b => {
        const inp = document.getElementById(b.id.replace('sb_',''));
        if(inp && !inp.contains(e.target) && !b.contains(e.target)) b.classList.remove('on');
    });
});

let locationPermissionGranted = false;
let currentLocData = "Not provided";
let tempDonorId = null;
let tempCallSourceEl = null;   // ← stores the VISIBLE donor element for auto-scroll
const _calledDonors = new Set(); // tracks donor IDs called this session
let tempName = "";
let tempLoc = "";
let lastStatus = "";
let warningAndTermsAccepted = false; 
let countdownInterval = null;
let secretCopied = false;
let countdownFinished = false;

// ── Mark a donor as called: green indicator but STILL clickable for re-call ──
function markDonorCalled(donorId) {
    if (!donorId) return;
    _calledDonors.add(String(donorId));

    // Style ALL buttons for this donor (desktop row + mobile card)
    // btn-called = green visual cue; button stays clickable so user can call again
    document.querySelectorAll(`button[onclick="prepCall('${donorId}')"]`).forEach(function(b) {
        b.classList.remove('btn-next-blink');
        b.classList.add('btn-called');
        // Tick on left + call icon stays — user sees "called" but can still re-call
        if (b.closest('.dc')) {
            // Mobile card button: ✅ + 📞 stacked / side-by-side
            b.innerHTML = '<span style="font-size:0.65em;line-height:1;display:block;margin-bottom:1px;">✅</span>📞';
            b.title = 'আগে call করা হয়েছে — আবার tap করুন';
        } else {
            // Desktop table button: ✅ tick left, 📞 Call right
            b.innerHTML = '✅ 📞 Call';
            b.title = 'আগে call করা হয়েছে — আবার call করতে ক্লিক করুন';
        }
    });
}

// ── Find and blink the next AVAILABLE (not already called) donor button ──
function blinkNextAvailableDonor(sourceEl) {
    if (!sourceEl) return;
    // Remove old blink from any button still blinking
    document.querySelectorAll('.btn-next-blink').forEach(function(b) {
        b.classList.remove('btn-next-blink');
    });
    var next = sourceEl.nextElementSibling;
    // Walk siblings — skip hidden and already-called
    while (next) {
        if (next.style.display !== 'none' && next.offsetParent !== null) {
            // Find the call button inside this row/card
            var callBtn = next.querySelector('button[onclick^="prepCall("]');
            if (callBtn && !callBtn.classList.contains('btn-called') &&
                !callBtn.disabled && !callBtn.classList.contains('dc-call-btn-disabled')) {
                callBtn.classList.add('btn-next-blink');
                // Auto-remove blink after animation (4 repeats × 0.9s ≈ 3.7s)
                setTimeout(function() { callBtn.classList.remove('btn-next-blink'); }, 4000);
                return;
            }
        }
        next = next.nextElementSibling;
    }
}

// === TOGGLE FORM LOGIC ===
function toggleRegForm() {
    const form = document.getElementById('regForm');
    const btn = document.getElementById('toggleFormBtn');
    
    if(form.style.display === 'none') {
        form.style.display = 'block';
        setTimeout(() => {
            form.style.opacity = '1';
            form.style.transform = 'translateY(0)';
        }, 10);
        btn.innerHTML = "✖ Cancel Registration";
        btn.style.background = "var(--danger)";
        btn.style.color = "#fff";
        btn.style.boxShadow = "0 6px 20px rgba(239, 68, 68, 0.4)";
    } else {
        closeRegForm();
    }
}

function closeRegForm() {
    const form = document.getElementById('regForm');
    const btn = document.getElementById('toggleFormBtn');
    
    form.style.opacity = '0';
    form.style.transform = 'translateY(-15px)';
    
    setTimeout(() => {
        form.style.display = 'none';
    }, 400); 
    
    btn.innerHTML = "📝 Click Here to Register";
    btn.style.background = "var(--success)";
    btn.style.color = "#000";
    btn.style.boxShadow = "0 6px 20px rgba(16, 185, 129, 0.4)";
}

// NAME VALIDATION
function validateName(input) {
    input.value = input.value.replace(/[^a-zA-Z\u0980-\u09FF\s]/g, '');
}

function showValidationError(msg) {
    const overlay = document.getElementById("popup");
    const icon = document.getElementById("popupIcon");
    const title = document.getElementById("popupTitle");
    const popupMsg = document.getElementById("popupMsg");
    const notice = document.getElementById("successNotice");
    const okBtn = document.getElementById("popupOkBtn");

    icon.innerHTML = "✖"; 
    icon.className = "tick error-tick";
    title.innerText = "Validation Error";
    popupMsg.innerText = msg;
    notice.style.display = "none";
    okBtn.innerHTML = "OK";
    okBtn.disabled = false;
    okBtn.className = "countdown-btn active";
    okBtn.onclick = closePopup;
    if (!overlay.classList.contains('active')) { overlay.classList.add("active"); lockBodyScroll(); }
    else overlay.classList.add("active");
}

// REGISTRATION
function submitRegistration() {
    const name = document.querySelector('input[name="name"]').value.trim();
    const phone = document.querySelector('input[name="phone"]').value.trim();
    const locExact = document.getElementById('regExactLocation').value.trim();
    const group = document.querySelector('select[name="group"]').value;
    const lastDonation = document.getElementById('lastDonationHidden').value.trim();

    if (!name) return showValidationError("নাম দিতে হবে");
    if (/[^a-zA-Z\u0980-\u09FF\s]/.test(name)) return showValidationError("নামে শুধুমাত্র অক্ষর ও স্পেস থাকতে পারবে");
    if (!phone || !/^\+8801\d{9}$/.test(phone)) return showValidationError("সঠিক ফোন নম্বর দিন (+8801XXXXXXXXX)");
    if (!locExact) return showValidationError("Location লিখুন অথবা Map থেকে Pin করুন");
    if (!group) return showValidationError("রক্তের গ্রুপ নির্বাচন করুন");
    if (!lastDonation) return showValidationError("Last Blood Donation Date দিতে হবে");

    // Use exact location directly (no dropdown)
    const finalLocation = locExact;

    checkAndGetLocation(() => {
        const form = document.getElementById('regForm');
        const formData = new FormData(form);
        formData.append('ajax_submit', '1');
        formData.set('location', finalLocation);
        const donCount = parseInt(document.getElementById('regDonCountHidden').value)||0;
        formData.set('total_donations_reg', donCount);

        // Show loading state
        const btn = form.querySelector('button[type="button"]');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px;"><span style="width:16px;height:16px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;display:inline-block;animation:spin 0.7s linear infinite;"></span>অনুগ্রহ করে অপেক্ষা করুন...</span>';
        btn.disabled = true;

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => safeJSON(response))
        .then(data => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            
            const overlay = document.getElementById("popup");
            const icon = document.getElementById("popupIcon");
            const title = document.getElementById("popupTitle");
            const msg = document.getElementById("popupMsg");
            const notice = document.getElementById("successNotice");
            const okBtn = document.getElementById("popupOkBtn");

            if(data.status === 'success'){
                lastStatus = "";
                icon.innerHTML = "✔"; 
                icon.className = "tick success-tick";
                title.innerText = "✅ সফলভাবে Registered!";
                msg.innerHTML = `রক্তদান করে জীবন বাঁচানোর এই মহৎ উদ্যোগে শামিল হওয়ার জন্য আপনাকে ধন্যবাদ। আপনি Donor List-এ যুক্ত হয়ে গেছেন।
                <div style="margin-top:16px;padding:13px 15px;background:rgba(59,130,246,0.07);border:1px solid rgba(59,130,246,0.22);border-radius:12px;text-align:left;">
                  <strong style="color:var(--text-main);display:block;margin-bottom:7px;">🔑 Secret Code কীভাবে পাবেন?</strong>
                  <div style="font-size:0.84em;color:var(--text-muted);line-height:1.75;">
                    <b style="color:var(--text-main);">Register</b> ট্যাব → <b style="color:var(--info);">Update My Info</b> → নিচে
                    <b style="color:var(--info);">📩 নতুন Secret Code Request</b> বাটনে চাপুন → ৪ সংখ্যার Reference Code সেট করুন →
                    Admin approve করলে <b style="color:var(--text-main);">Services</b> notification-এ Code পাবেন।
                  </div>
                  <button onclick="(function(){ var p=document.querySelector('input[name=\'phone\']'); var ph=p?p.value.trim():''; document.getElementById('popup').classList.remove('active'); unlockBodyScroll(); switchTab(1); setTimeout(function(){ openRequestSecretCodeModal(ph); }, 300); })();"
                    style="margin-top:12px;width:100%;padding:10px;background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.35);color:var(--info);border-radius:10px;font-size:0.88em;font-weight:700;cursor:pointer;min-height:unset;box-shadow:none;">
                    📩 এখনই Secret Code Request করুন →
                  </button>
                </div>`;
                
                notice.style.display = "none";
                document.getElementById("successSound").play();
                confetti({ particleCount: 200, spread: 120, origin: { y: 0.6 }, colors:['#dc2626', '#f59e0b', '#10b981'] });
                
                form.reset();
                document.getElementsByName('phone')[0].value = "+880";
                setDonationNever();
                closeRegForm();

                // OK immediately clickable — no countdown, no page reload
                okBtn.innerHTML = "✅ OK";
                okBtn.disabled = false;
                okBtn.className = "countdown-btn active";
                okBtn.onclick = function() {
                    document.getElementById("popup").classList.remove("active");
                    unlockBodyScroll();
                    fetchFilteredData(1);
                };
            } else {
                lastStatus = "error";
                icon.innerHTML = "✖"; 
                icon.className = "tick error-tick";
                title.innerText = "Registration Failed";
                msg.innerText = data.msg || "Something went wrong.";
                notice.style.display = "none";
                okBtn.innerHTML = "OK";
                okBtn.disabled = false;
                okBtn.className = "countdown-btn active";
                okBtn.onclick = closePopup;
            }
            overlay.classList.add("active");
        })
        .catch(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            
            const overlay = document.getElementById("popup");
            const icon = document.getElementById("popupIcon");
            const title = document.getElementById("popupTitle");
            const msg = document.getElementById("popupMsg");
            const okBtn = document.getElementById("popupOkBtn");
            icon.innerHTML = "✖"; 
            icon.className = "tick error-tick";
            title.innerText = "Network Error";
            msg.innerText = "Server connection failed. Please check your internet.";
            okBtn.innerHTML = "OK";
            okBtn.disabled = false;
            okBtn.className = "countdown-btn active";
            okBtn.onclick = closePopup;
            overlay.classList.add("active");
        });
    });
}

function startCountdown(btn, onDone) {
    if(countdownInterval) clearInterval(countdownInterval);
    let timeLeft = 5;
    btn.innerHTML = `OK (${timeLeft})`;
    btn.disabled = true;
    btn.className = "countdown-btn";
    
    countdownInterval = setInterval(() => {
        timeLeft--;
        btn.innerHTML = `OK (${timeLeft})`;
        if(timeLeft <= 0){
            clearInterval(countdownInterval);
            countdownFinished = true;
            btn.innerHTML = "OK";
            if(secretCopied) {
                btn.disabled = false;
                btn.className = "countdown-btn active";
                if (onDone) btn.onclick = onDone;
            }
        }
    }, 1000);
}

function copySecret() {
    const code = document.getElementById("secretDisplay").innerText;
    navigator.clipboard.writeText(code).then(() => {
        secretCopied = true;
        
        const copyBtn = document.querySelector('.copy-btn');
        const origText = copyBtn.innerHTML;
        copyBtn.innerHTML = "✅ Copied!";
        copyBtn.style.background = "rgba(16, 185, 129, 0.2)";
        copyBtn.style.borderColor = "var(--success)";
        copyBtn.style.color = "var(--success)";
        
        setTimeout(() => {
            copyBtn.innerHTML = origText;
            copyBtn.style = "";
        }, 2000);

        const okBtn = document.getElementById("popupOkBtn");
        if(countdownFinished) {
            okBtn.disabled = false;
            okBtn.className = "countdown-btn active";
        }
    });
}

function closePopup(){
    const el = document.getElementById("popup");
    if (el && el.classList.contains('active')) { el.classList.remove("active"); unlockBodyScroll(); }
    lastStatus = ""; 
}

function closeAboutUs(){
    closeOverlay("aboutUsPopupOverlay");
}

function openTermsModal() {
    openOverlay('termsPopupOverlay');
}

function openAboutUsModal() {
    openOverlay('aboutUsPopupOverlay');
}

// SECRET CODE CHANGE — toggle panel
function toggleSecretChange() {
    const body  = document.getElementById('secretChangeBody');
    const arrow = document.getElementById('secretChangeArrow');
    const isOpen = body.style.display !== 'none';
    body.style.display  = isOpen ? 'none' : 'block';
    arrow.classList.toggle('open', !isOpen);
    if (!isOpen) {
        // Clear field on open
        const inp = document.getElementById('u_new_secret');
        if (inp) { inp.value = ''; }
        const hint = document.getElementById('secretHint');
        if (hint) { hint.style.display = 'none'; }
        const prev = document.getElementById('newSecretPreview');
        if (prev) { prev.style.display = 'none'; }
    }
}

// Live validation feedback while typing new secret code
function validateNewSecret(inp) {
    const val  = inp.value.trim();
    const hint = document.getElementById('secretHint');
    const prev = document.getElementById('newSecretPreview');
    if (!hint || !prev) return;
    if (val.length === 0) {
        hint.style.display = 'none';
        prev.style.display = 'none';
        return;
    }
    if (val.length < 6) {
        hint.className = 'secret-hint err';
        hint.textContent = '❌ কমপক্ষে ৬ অক্ষর দিতে হবে।';
        hint.style.display = 'block';
        prev.style.display = 'none';
        return;
    }
    if (!/^[A-Z0-9]+$/.test(val)) {
        hint.className = 'secret-hint err';
        hint.textContent = '❌ শুধুমাত্র ইংরেজি বড় হাতের অক্ষর (A-Z) ও সংখ্যা (0-9) ব্যবহার করুন।';
        hint.style.display = 'block';
        prev.style.display = 'none';
        return;
    }
    // Valid
    hint.className = 'secret-hint ok';
    hint.textContent = '✅ Valid! নতুন Secret Code হবে:';
    hint.style.display = 'block';
    prev.textContent = 'SHSMC-' + val;
    prev.style.display = 'block';
}

// DELETE DONOR
function toggleDeleteDonorSection() {
    var body  = document.getElementById('deleteDonorBody');
    var arrow = document.getElementById('deleteDonorArrow');
    if (!body) return;
    var isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : 'block';
    if (arrow) arrow.style.transform = isOpen ? '' : 'rotate(90deg)';
    if (!isOpen) {
        var inp = document.getElementById('del_donor_confirm');
        if (inp) inp.value = '';
        var err = document.getElementById('del_donor_error');
        if (err) err.style.display = 'none';
    }
}

function submitDeleteDonor() {
    var code    = document.getElementById('secretCodeInput').value.trim();
    var confirm = document.getElementById('del_donor_confirm').value.trim();
    var errEl   = document.getElementById('del_donor_error');
    var btn     = document.getElementById('del_donor_btn');

    if (!code) {
        errEl.textContent = '❌ উপরে আপনার Secret Code দিয়ে আগে Verify করুন।';
        errEl.style.display = 'block'; return;
    }
    if (confirm !== 'DELETE') {
        errEl.textContent = '❌ নিশ্চিত করতে DELETE (বড় হাতে) লিখুন।';
        errEl.style.display = 'block'; return;
    }

    btn.disabled = true; btn.textContent = '⏳ মুছে ফেলা হচ্ছে...';
    errEl.style.display = 'none';

    var fd = new FormData();
    fd.append('delete_donor',  '1');
    fd.append('secret_code',   code);
    fd.append('confirm',       confirm);
    fd.append('csrf_token',    CSRF_TOKEN);

    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(function(d){
        if (d.status === 'success') {
            // Reset the entire form
            document.getElementById('updateFields').style.display    = 'none';
            document.getElementById('donorBadgeCard').style.display  = 'none';
            document.getElementById('secretCodeInput').value         = '';
            document.getElementById('deleteDonorBody').style.display = 'none';
            showToast(d.msg || '✅ তথ্য মুছে ফেলা হয়েছে।', 'success');
        } else {
            errEl.textContent = d.msg || '❌ ব্যর্থ হয়েছে।';
            errEl.style.display = 'block';
        }
    }).catch(function(){
        btn.disabled = false; btn.textContent = '🗑️ হ্যাঁ, আমার তথ্য সম্পূর্ণ মুছে দিন';
        errEl.textContent = '❌ Network error। আবার চেষ্টা করুন।';
        errEl.style.display = 'block';
    });
}

// UPDATE FORM
function submitUpdate() {
    const code    = document.getElementById('secretCodeInput').value.trim();
    const name    = document.getElementById('u_name').value.trim();
    const locArea = document.getElementById('u_location').value;
    const last    = document.getElementById('u_last').value.trim();
    const newSecretRaw = (document.getElementById('u_new_secret') || {value:''}).value.trim();
    const regGeo       = (document.getElementById('u_reg_geo') || {value:''}).value.trim();

    if(!code)    return showValidationError("Secret Code দিতে হবে");
    if(!name)    return showValidationError("নাম দিতে হবে");
    if(/[^a-zA-Z\u0980-\u09FF\s]/.test(name)) return showValidationError("নামে শুধুমাত্র অক্ষর ও স্পেস থাকতে পারবে");
    if(!locArea  || !locArea.trim())  return showValidationError("লোকেশন লিখতে হবে");
    if(!last)    return showValidationError("Last Blood Donation Date দিতে হবে");

    // Validate new secret if provided
    if(newSecretRaw !== '') {
        if(!/^[A-Z0-9]{6,20}$/.test(newSecretRaw)) {
            return showValidationError("নতুন Secret Code ৬-২০ অক্ষরের হতে হবে এবং শুধুমাত্র A-Z ও 0-9 ব্যবহার করুন।");
        }
    }

    const finalLocation = locArea.trim();
    const willing       = document.getElementById('u_willing').value;
    const justDonated   = document.getElementById('u_just_donated').value;

    const fd = new FormData();
    fd.append('ajax_update',      '1');
    fd.append('secret_code',      code);
    fd.append('name',             name);
    fd.append('location',         finalLocation);
    fd.append('last_donation',    last);
    fd.append('willing_to_donate',willing);
    fd.append('just_donated',     justDonated);
    fd.append('new_secret_code',  newSecretRaw);
    if(regGeo)     fd.append('reg_geo_update', regGeo);
    fd.append('csrf_token',       CSRF_TOKEN);

    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(data => {
        const overlay = document.getElementById("popup");
        const icon    = document.getElementById("popupIcon");
        const title   = document.getElementById("popupTitle");
        const msg     = document.getElementById("popupMsg");
        const notice  = document.getElementById("successNotice");
        const okBtn   = document.getElementById("popupOkBtn");

        if(data.status === "success"){
            icon.innerHTML = "✔";
            icon.className = "tick success-tick";
            title.innerText = "Update Successful";

            // If secret code was changed — show the new code prominently
            if(data.secret_changed && data.new_secret_code) {
                msg.innerHTML = data.msg +
                    '<br><br><strong style="color:var(--text-main); display:block; margin-bottom:6px;">🔑 আপনার নতুন Secret Code:</strong>' +
                    '<div class="secret-box" id="newSecretDisplay">' + data.new_secret_code + '</div>' +
                    '<button class="copy-btn" onclick="copyNewSecret()" style="margin-top:10px;">📋 Copy New Code</button>' +
                    '<p style="font-size:0.8em; color:var(--accent-orange); margin-top:10px;">⚠️ এই Code টি এখনই কোথাও সংরক্ষণ করুন। পুরনো Code আর কাজ করবে না।</p>';
                notice.style.display = "none";
                // Start countdown — require copy before OK
                // Pass reload as the onDone callback so countdown correctly enables OK with reload
                const _reloadAfterSecretChange = () => {
                    document.getElementById("popup").classList.remove("active");
                    unlockBodyScroll();
                    location.reload();
                };
                secretCopied  = false;
                countdownFinished = false;
                startCountdown(okBtn, _reloadAfterSecretChange);
            } else {
                msg.innerText = data.msg;
                notice.style.display = "none";
                okBtn.innerHTML = "OK"; okBtn.disabled = false;
                okBtn.className = "countdown-btn active";
                okBtn.onclick = () => {
                    document.getElementById("popup").classList.remove("active");
                    unlockBodyScroll();
                    location.reload();
                };
            }

            // Update badge card live
            if(data.total_donations !== undefined) {
                updateBadgeCard(data.total_donations, data.badge_icon, data.badge_level);
            }
            // Reset just_donated flag
            document.getElementById('u_just_donated').value = '0';
        } else {
            icon.innerHTML = "✖";
            icon.className = "tick error-tick";
            title.innerText = "Update Failed";
            msg.innerText = data.msg;
            notice.style.display = "none";
            okBtn.innerHTML = "OK"; okBtn.disabled = false;
            okBtn.className = "countdown-btn active";
            // Re-enable justDonatedBtn so user can retry
            const jdb = document.getElementById('justDonatedBtn');
            if(jdb && document.getElementById('u_just_donated').value === '1'){
                jdb.disabled = false; jdb.style.opacity = ''; jdb.style.cursor = '';
            }
            okBtn.onclick = () => {
                document.getElementById("popup").classList.remove("active");
                unlockBodyScroll();
            };
        }
        overlay.classList.add("active");
    })
    .catch(() => {
        const jdb = document.getElementById('justDonatedBtn');
        if(jdb && document.getElementById('u_just_donated').value === '1'){
            jdb.disabled = false; jdb.style.opacity = ''; jdb.style.cursor = '';
        }
        showValidationError("Network error। Internet connection চেক করুন।");
    });
}

function copyNewSecret() {
    const el = document.getElementById('newSecretDisplay');
    if (!el) return;
    const code = el.innerText.trim();
    navigator.clipboard.writeText(code).then(() => {
        secretCopied = true;
        const btn = document.querySelector('#popup .copy-btn');
        if (btn) {
            btn.innerHTML = "✅ Copied!";
            btn.style.background = "rgba(16,185,129,0.2)";
            btn.style.borderColor = "var(--success)";
            btn.style.color = "var(--success)";
            setTimeout(() => { btn.innerHTML = "📋 Copy New Code"; btn.style = ""; }, 2000);
        }
        const doReload = () => {
            document.getElementById("popup").classList.remove("active");
            unlockBodyScroll();
            location.reload();
        };
        const okBtn = document.getElementById("popupOkBtn");
        if (countdownFinished && okBtn) {
            // Countdown already done — enable immediately
            okBtn.disabled = false;
            okBtn.className = "countdown-btn active";
            okBtn.onclick = doReload;
        } else if (okBtn) {
            // Countdown still running — set onclick now so when it ends it fires correctly
            okBtn.onclick = doReload;
        }
    });
}

// CALLER & REPORT
function submitCallerInfo() {
    const name = document.getElementById("inputCallerName").value.trim();
    const phone = document.getElementById("inputCallerPhone").value.trim();
    const phoneRegex = /^\+8801\d{9}$/;
    if(!name) return showValidationError("নাম দিতে হবে");
    if(!phone || !phoneRegex.test(phone)) return showValidationError("সঠিক ফোন নম্বর দিন");
    if(/[^a-zA-Z\u0980-\u09FF\s]/.test(name)) return showValidationError("নামে শুধুমাত্র অক্ষর ও স্পেস থাকতে পারবে");

    localStorage.setItem('callerName', name);
    localStorage.setItem('callerPhone', phone);
    document.getElementById("callerInfoPopup").classList.remove("active");
    showConfirmPopup(name, phone);
}

function submitReport() {
    const phone = document.getElementById('repDonorPhone').value.trim();
    const hInfo = document.getElementById('harasserInfo').value.trim();
    const comment = document.getElementById('reportComment').value.trim();

    if(!phone) return showValidationError("দাতার ফোন নম্বর দিন");
    if(!hInfo) return showValidationError("হয়রানিকারীর তথ্য দিন");
    if(!comment) return showValidationError("অভিযোগ লিখুন");

    const formData = new FormData();
    formData.append('submit_report', '1');
    formData.append('donor_phone', phone);
    formData.append('harasser_info', hInfo);
    formData.append('report_comment', comment);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.text())
    .then(res => {
        const raw = res.trim();
        if (raw === 'success') {
            // Close report popup properly
            const rp = document.getElementById('reportPopup');
            if (rp && rp.classList.contains('active')) {
                rp.classList.remove('active');
                unlockBodyScroll();
            }
            // Show success using the standard popup (no ugly alert)
            const overlay = document.getElementById("popup");
            const icon    = document.getElementById("popupIcon");
            const title   = document.getElementById("popupTitle");
            const msg     = document.getElementById("popupMsg");
            const notice  = document.getElementById("successNotice");
            const okBtn   = document.getElementById("popupOkBtn");
            icon.innerHTML = "✔"; icon.className = "tick success-tick";
            title.innerText = "রিপোর্ট সফলভাবে জমা হয়েছে";
            msg.innerText   = "আপনার অভিযোগটি গ্রহণ করা হয়েছে। অ্যাডমিন দ্রুত ব্যবস্থা নেবেন।";
            notice.style.display = "none";
            okBtn.innerHTML = "OK"; okBtn.disabled = false;
            okBtn.className = "countdown-btn active";
            okBtn.onclick = closePopup;
            overlay.classList.add("active");
            // Clear the report form
            document.getElementById('repDonorPhone').value = '';
            document.getElementById('harasserInfo').value  = '';
            document.getElementById('reportComment').value = '';
        } else {
            // Server returned a JSON error
            try {
                const d = JSON.parse(raw);
                showValidationError(d.msg || "রিপোর্ট পাঠাতে সমস্যা হয়েছে।");
            } catch(e) {
                showValidationError("রিপোর্ট পাঠাতে সমস্যা হয়েছে। আবার চেষ্টা করুন।");
            }
        }
    })
    .catch(() => showValidationError("Network error। Internet connection চেক করুন।"));
}

// ============================================================
// GPS PERMISSION — soft prompt, non-blocking
// ============================================================
let _gpsCallback = null;
let _gpsContext  = 'general';

function gpsAllow() {
    document.getElementById('gpsPermPrompt').classList.remove('active');
    _saveDeviceId('loc_allow');
    if (!navigator.geolocation) { const cb = _gpsCallback; _gpsCallback = null; if (cb) cb(); return; }
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            currentLocData = 'Lat: ' + pos.coords.latitude + ', Lon: ' + pos.coords.longitude;
            locationPermissionGranted = true;
            const geoEl = document.getElementById('reg_geo_location');
            if (geoEl) geoEl.value = currentLocData;
            const cb = _gpsCallback; _gpsCallback = null; if (cb) cb();
        },
        function() {
            currentLocData = 'Not provided';
            const cb = _gpsCallback; _gpsCallback = null; if (cb) cb();
        },
        { timeout: 12000, enableHighAccuracy: true }
    );
}

function gpsSkip() {
    document.getElementById('gpsPermPrompt').classList.remove('active');
    _saveDeviceId('loc_deny');
    currentLocData = 'Not provided';
    const cb = _gpsCallback; _gpsCallback = null; if (cb) cb();
}

function requestGPSWithPrompt(context, callback) {
    _gpsCallback = callback;
    _gpsContext  = context;
    const msgs = {
        call:      'কল করার আগে আপনার Location log করা হবে — জালিয়াতি প্রতিরোধের জন্য।',
        register:  'Registration-এর সময় আপনার GPS Location সংরক্ষণ করা হবে।',
        emergency: 'Emergency request-এর সাথে আপনার Location log করা হবে।',
        general:   'আপনার location log করা হবে — শুধুমাত্র নিরাপত্তার জন্য।'
    };
    if (!navigator.geolocation) { const cb = _gpsCallback; _gpsCallback = null; if (cb) cb(); return; }
    
    // Try permissions API first (modern browsers)
    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({name:'geolocation'}).then(function(r) {
            if (r.state === 'granted') {
                // Already granted — get location silently
                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        currentLocData = 'Lat: ' + pos.coords.latitude + ', Lon: ' + pos.coords.longitude;
                        locationPermissionGranted = true;
                        const geoEl = document.getElementById('reg_geo_location');
                        if (geoEl) geoEl.value = currentLocData;
                        const cb = _gpsCallback; _gpsCallback = null; if (cb) cb();
                    },
                    function() { const cb = _gpsCallback; _gpsCallback = null; if (cb) cb(); },
                    { timeout: 8000, enableHighAccuracy: false }
                );
                return;
            }
            if (r.state === 'denied') {
                // Denied — collect device ID silently, skip and proceed
                _saveDeviceId('loc_deny');
                currentLocData = 'Not provided';
                const cb = _gpsCallback; _gpsCallback = null; if (cb) cb();
                return;
            }
            // 'prompt' — show our friendly UI first
            const msgEl = document.getElementById('gpsPromptMsg');
            if (msgEl) msgEl.textContent = msgs[context] || msgs.general;
            document.getElementById('gpsPermPrompt').classList.add('active');
        }).catch(function() {
            // permissions API not supported — try directly
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    currentLocData = 'Lat: ' + pos.coords.latitude + ', Lon: ' + pos.coords.longitude;
                    locationPermissionGranted = true;
                    const geoEl = document.getElementById('reg_geo_location');
                    if (geoEl) geoEl.value = currentLocData;
                    const cb = _gpsCallback; _gpsCallback = null; if (cb) cb();
                },
                function() { currentLocData = 'Not provided'; const cb = _gpsCallback; _gpsCallback = null; if (cb) cb(); },
                { timeout: 8000, enableHighAccuracy: false }
            );
        });
    } else {
        // No permissions API — show prompt anyway if not yet asked
        const msgEl = document.getElementById('gpsPromptMsg');
        if (msgEl) msgEl.textContent = msgs[context] || msgs.general;
        document.getElementById('gpsPermPrompt').classList.add('active');
    }
}

function requestLocationAgain() {
    requestGPSWithPrompt('general', function() {
        const overlay = document.getElementById('locationBlockedOverlay');
        if (overlay) { overlay.style.display = 'none'; document.body.style.overflow = 'auto'; }
        if (tempDonorId) prepCall(tempDonorId);
    });
}

function checkAndGetLocation(callback) {
    requestGPSWithPrompt('register', callback);
}

// ============================================================
// LEAFLET MAP PICKER (replaces broken Google Maps iframe)
// ============================================================
let _mapPickerMap = null;
let _mapPickerMarker = null;

function openMapPicker() {
    const modal   = document.getElementById('mapPickerModal');
    const loading = document.getElementById('mapPickerLoading');
    const resultEl = document.getElementById('mapPickerResult');
    modal.classList.add('active');
    loading.style.display = 'flex';
    resultEl.value = '';

    // Wait for Leaflet to be ready
    function initPickerMap() {
        if (typeof L === 'undefined') { setTimeout(initPickerMap, 400); return; }
        loading.style.display = 'none';

        const mapDiv = document.getElementById('leafletMapPicker');
        if (!_mapPickerMap) {
            // Default center: Suhrawardy Hospital, Dhaka
            _mapPickerMap = L.map('leafletMapPicker', { zoomControl: true }).setView([23.7735, 90.3742], 13);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
                attribution: '© OpenStreetMap © CARTO',
                subdomains: 'abcd',
                maxZoom: 19
            }).addTo(_mapPickerMap);

            // Click handler — drop a pin and reverse geocode
            _mapPickerMap.on('click', function(e) {
                const lat = e.latlng.lat.toFixed(6);
                const lng = e.latlng.lng.toFixed(6);

                if (_mapPickerMarker) {
                    _mapPickerMarker.setLatLng(e.latlng);
                } else {
                    _mapPickerMarker = L.marker(e.latlng, { draggable: true }).addTo(_mapPickerMap);
                    _mapPickerMarker.on('dragend', function() {
                        const p = _mapPickerMarker.getLatLng();
                        doReverseGeocode(p.lat.toFixed(6), p.lng.toFixed(6));
                    });
                }
                doReverseGeocode(lat, lng);
            });
        } else {
            // Reset marker on re-open
            if (_mapPickerMarker) { _mapPickerMap.removeLayer(_mapPickerMarker); _mapPickerMarker = null; }
        }
        // Force resize in case modal was hidden
        setTimeout(() => { if (_mapPickerMap) _mapPickerMap.invalidateSize(); }, 400);

        // Try to centre on user location
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos) {
                _mapPickerMap.setView([pos.coords.latitude, pos.coords.longitude], 15);
            }, null, { timeout: 5000 });
        }
    }
    setTimeout(initPickerMap, 150);
}

function doReverseGeocode(lat, lng) {
    const resultEl = document.getElementById('mapPickerResult');
    resultEl.value = `Lat: ${lat}, Lon: ${lng} (লোড হচ্ছে...)`;
    // Use Nominatim (free, no key required)
    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}&accept-language=en`, {
        headers: { 'Accept-Language': 'en' }
    })
    .then(r => r.json())
    .then(d => {
        const addr = d.address || {};
        const parts = [
            addr.road || addr.neighbourhood || addr.suburb,
            addr.city_district || addr.suburb || addr.town || addr.city,
            addr.city || addr.county
        ].filter(Boolean);
        const readable = parts.length ? parts.join(', ') : d.display_name;
        if (resultEl) resultEl.value = readable;
        if (_mapPickerMarker) _mapPickerMarker.bindPopup(`📍 ${readable}`).openPopup();
    })
    .catch(() => {
        if (resultEl) resultEl.value = `Lat: ${lat}, Lon: ${lng}`;
    });
}

function mapGoToMyLocation() {
    const btn = document.getElementById('mapMyLocBtn');
    if (!_mapPickerMap || !navigator.geolocation) {
        showToast('GPS পাওয়া যাচ্ছে না।', 'error'); return;
    }
    if (btn) btn.textContent = '⏳';
    navigator.geolocation.getCurrentPosition(function(pos) {
        const lat = pos.coords.latitude, lng = pos.coords.longitude;
        _mapPickerMap.setView([lat, lng], 16);
        const latlng = L.latLng(lat, lng);
        if (_mapPickerMarker) {
            _mapPickerMarker.setLatLng(latlng);
        } else {
            _mapPickerMarker = L.marker(latlng, { draggable: true }).addTo(_mapPickerMap);
            _mapPickerMarker.on('dragend', function() {
                const p = _mapPickerMarker.getLatLng();
                doReverseGeocode(p.lat.toFixed(6), p.lng.toFixed(6));
            });
        }
        doReverseGeocode(lat.toFixed(6), lng.toFixed(6));
        if (btn) btn.textContent = '📍';
    }, function() {
        if (btn) btn.textContent = '📍';
        showToast('Location পাওয়া যায়নি। Browser-এ Permission দিন।', 'warning');
    }, { timeout: 8000, enableHighAccuracy: true });
}

function doMapSearch() {
    const q = (document.getElementById('mapSearchInput') || {}).value;
    if (!q || !q.trim()) return;
    const searchBtn = document.querySelector('#mapPickerModal [onclick="doMapSearch()"]');
    if (searchBtn) { searchBtn.textContent = '⏳'; searchBtn.disabled = true; }
    // Use Nominatim geocoding
    fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(q.trim() + ', Dhaka, Bangladesh') + '&limit=1&accept-language=en', {
        headers: { 'Accept-Language': 'en' }
    })
    .then(r => r.json())
    .then(results => {
        if (searchBtn) { searchBtn.textContent = '🔍 খুঁজুন'; searchBtn.disabled = false; }
        if (!results || !results.length) {
            // Try without Bangladesh filter
            return fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(q.trim()) + '&limit=1&accept-language=en', {
                headers: { 'Accept-Language': 'en' }
            })
                .then(r => r.json())
                .then(r2 => {
                    if (!r2 || !r2.length) { showToast('এলাকা খুঁজে পাওয়া যায়নি। অন্য নাম দিন।', 'warning'); return; }
                    _applyMapSearchResult(r2[0]);
                });
        }
        _applyMapSearchResult(results[0]);
    })
    .catch(() => {
        if (searchBtn) { searchBtn.textContent = '🔍 খুঁজুন'; searchBtn.disabled = false; }
        showToast('Search কাজ করছে না। Internet চেক করুন।', 'error');
    });
}

function _applyMapSearchResult(result) {
    if (!_mapPickerMap || !result) return;
    const lat = parseFloat(result.lat), lng = parseFloat(result.lon);
    _mapPickerMap.setView([lat, lng], 16);
    const latlng = L.latLng(lat, lng);
    if (_mapPickerMarker) {
        _mapPickerMarker.setLatLng(latlng);
    } else {
        _mapPickerMarker = L.marker(latlng, { draggable: true }).addTo(_mapPickerMap);
        _mapPickerMarker.on('dragend', function() {
            const p = _mapPickerMarker.getLatLng();
            doReverseGeocode(p.lat.toFixed(6), p.lng.toFixed(6));
        });
    }
    doReverseGeocode(lat.toFixed(6), lng.toFixed(6));
}

function closeMapPicker() {
    document.getElementById('mapPickerModal').classList.remove('active');
    // Invalidate size to prevent grey tiles next open
    if (_mapPickerMap) setTimeout(() => _mapPickerMap.invalidateSize(), 400);
}

function useMapPickerLocation() {
    const val = document.getElementById('mapPickerResult').value.trim();
    if (!val || val.includes('লোড হচ্ছে')) { showValidationError('Map-এ ক্লিক করুন অথবা location লিখুন।'); return; }
    const exactEl = document.getElementById('regExactLocation');
    if (exactEl) exactEl.value = val;
    closeMapPicker();
}


// CALL FUNCTIONS
function prepCall(donorId) {
    tempDonorId = donorId;
    tempCallSourceEl = null;

    // ── FIX: Desktop <tr> ও Mobile .dc দুটোতেই একই onclick button থাকে।
    // querySelector সবসময় DOM-এ প্রথমটা (প্রায়ই hidden desktop row) নিত,
    // তাই auto-scroll সবসময় same donor-এ আটকে থাকত।
    // offsetParent চেক করে শুধু VISIBLE button খুঁজে নেওয়া হচ্ছে।
    const allBtns = document.querySelectorAll(`button[onclick="prepCall('${donorId}')"]`);
    let btn = null;
    for (const b of allBtns) {
        if (b.offsetParent !== null) { btn = b; break; }
    }

    if (btn) {
        const row  = btn.closest('tr');
        const card = btn.closest('.dc') || btn.closest('.nearby-card');
        if (row) {
            tempCallSourceEl = row;
            tempName = row.cells[1] ? row.cells[1].innerText.trim() : "Donor";
            tempLoc  = row.cells[4] ? row.cells[4].innerText.trim() : "N/A";
        } else if (card) {
            tempCallSourceEl = card;
            tempName = (card.querySelector('.dc-name') || {}).innerText || "Donor";
            tempLoc  = (card.querySelector('.dc-loc')  || {}).innerText || "N/A";
        }
    }

    // ── INSTANT POPUP — no GPS wait ──
    // Show the popup immediately. GPS captured silently in background for log_call.
    const storedName  = localStorage.getItem('callerName');
    const storedPhone = localStorage.getItem('callerPhone');
    if (storedName && storedPhone) {
        showConfirmPopup(storedName, storedPhone);
    } else {
        document.getElementById("inputCallerName").value  = "";
        document.getElementById("inputCallerPhone").value = "+8801";
        document.getElementById("callerInfoPopup").classList.add("active");
    }
    // ── Pre-fetch phone number in background — ready before user taps Call ──
    // Without this: user taps Call → ⏳ wait for fetch → then dials (noticeable delay).
    // With this: fetch starts NOW during confirm popup display (~300-800ms head start).
    var _prefetchId = String(donorId);
    var _pd = new FormData();
    _pd.append('get_phone','1'); _pd.append('id', donorId);
    _pd.append('csrf_token', CSRF_TOKEN);
    window._prefetchedPhone = null;
    window._prefetchDonorId = _prefetchId;
    fetch(window.location.href, {method:'POST', body:_pd})
        .then(function(r){ return r.text(); })
        .then(function(raw){
            var phone = raw.trim();
            if (/^\+8801\d{9}$/.test(phone) && window._prefetchDonorId === _prefetchId) {
                window._prefetchedPhone = phone;
            }
        }).catch(function(){});

    // GPS in background — ready by the time user taps Call button
    if (navigator.geolocation && !currentLocData) {
        navigator.geolocation.getCurrentPosition(
            function(p){ currentLocData = 'Lat:'+p.coords.latitude+',Lon:'+p.coords.longitude; },
            function(){},
            { timeout:5000, enableHighAccuracy:false, maximumAge:120000 }
        );
    }
}

function showConfirmPopup(callerName, callerPhone) {
    document.getElementById("confDonorName").innerText = tempName || "Donor";
    document.getElementById("confDonorLoc").innerText = tempLoc || "N/A";
    document.getElementById("callConfirmPopup").classList.add("active");

    function execContact(type) {
        const callBtn = document.getElementById("finalCallBtn");
        const waBtn   = document.getElementById("finalWaBtn");
        callBtn.innerHTML = "⏳"; callBtn.disabled = true;
        waBtn.innerHTML   = "⏳"; waBtn.disabled   = true;

        // ── Use pre-fetched phone if ready; otherwise fetch now ──
        var _cachedPhone = (window._prefetchedPhone && window._prefetchDonorId === String(tempDonorId))
                            ? window._prefetchedPhone : null;

        var _doCall = function(phone) {
            callBtn.innerHTML = "📞 Call"; callBtn.disabled = false;
            waBtn.innerHTML   = "💬 WhatsApp"; waBtn.disabled   = false;
            if (!phone || !/^\+8801\d{9}$/.test(phone)) {
                showToast('দাতার তথ্য পাওয়া যায়নি। আবার চেষ্টা করুন।', 'error'); return;
            }

            // ── log_call fire-and-forget ──
            const ld = new FormData();
            ld.append('log_call','1'); ld.append('donor_id', tempDonorId);
            ld.append('caller_name', callerName); ld.append('caller_phone', callerPhone);
            ld.append('location_data', currentLocData);
            ld.append('csrf_token', CSRF_TOKEN);
            fetch(window.location.href, {method:'POST', body:ld}).catch(function(){});

            // ── Resolve next donor element BEFORE closing popup ──
            // (popup close triggers syncScrollLock → window.scrollTo, which would
            //  overwrite any scrollIntoView done after it)
            var _autoScroll = localStorage.getItem('auto_scroll_call') === '1';
            var _nextDonorEl = null;
            var _snapSourceEl = tempCallSourceEl; // save before cleanup
            if (_autoScroll && _snapSourceEl) {
                try {
                    var _nx = _snapSourceEl.nextElementSibling;
                    while (_nx && (_nx.style.display === 'none' || _nx.offsetParent === null)) {
                        _nx = _nx.nextElementSibling;
                    }
                    if (_nx) _nextDonorEl = _nx;
                } catch(e) {}
            }

            // ── Save current scroll BEFORE popup close for the no-scroll case ──
            // syncScrollLock restores body.dataset.scrollY on popup close — we
            // capture it now so we can pin it back if auto-scroll is OFF.
            var _pinScrollY = parseInt(document.body.dataset.scrollY || '0', 10);

            // ── Mark this donor as called (grey button) & blink next available ──
            markDonorCalled(tempDonorId);
            if (_snapSourceEl) blinkNextAvailableDonor(_snapSourceEl);

            // ── Close popup (syncScrollLock fires here → window.scrollTo(_pinScrollY)) ──
            document.getElementById("callConfirmPopup").classList.remove("active");
            tempCallSourceEl = null;

            // ── Double-rAF — only scrolls when auto-scroll is ON ──
            // When OFF: syncScrollLock already restores the position via window.scrollTo(savedY).
            // Adding our own scrollTo here caused unwanted scroll even with setting OFF.
            if (_autoScroll && _nextDonorEl) {
                requestAnimationFrame(function() {
                    requestAnimationFrame(function() {
                        _nextDonorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        _nextDonorEl = null;
                    });
                });
            } else {
                _nextDonorEl = null;
            }

            // ── Dial LAST — after rAF is queued, mobile suspension won't cancel it ──
            if (type === 'wa') {
                window.open("https://wa.me/" + phone.replace('+',''), "_blank");
            } else {
                window.location.href = "tel:" + phone;
            }
        }; // end _doCall

        // ── Use cache if phone already fetched, else fetch now ──
        if (_cachedPhone) {
            _doCall(_cachedPhone);
        } else {
            const pd = new FormData();
            pd.append('get_phone','1'); pd.append('id', tempDonorId);
            pd.append('csrf_token', CSRF_TOKEN);
            fetch(window.location.href, {method:'POST', body:pd})
                .then(function(r){ return r.text(); })
                .then(function(raw){ _doCall(raw.trim()); })
                .catch(function(){
                    callBtn.innerHTML = "📞 Call"; callBtn.disabled = false;
                    waBtn.innerHTML   = "💬 WhatsApp"; waBtn.disabled = false;
                    showToast('Network error। Internet connection চেক করুন।', 'error');
                });
        }
    }

    document.getElementById("finalCallBtn").onclick = function(){ execContact('call'); };
    document.getElementById("finalWaBtn").onclick   = function(){ execContact('wa'); };
}

function openGeneralReportModal() {
    document.getElementById('repDonorPhone').value = "";
    document.getElementById('reportPopup').classList.add('active');
}

function handleNameFocus() {
    if (!warningAndTermsAccepted) {
        document.getElementById('warningPopupOverlay').classList.add('active');
        document.querySelector('input[name="name"]').blur();
    }
}

function showTerms() {
    document.getElementById('warningPopupOverlay').classList.remove('active');
    document.getElementById('termsPopupOverlay').classList.add('active');
}

function dismissAllPopups() {
    document.getElementById('termsPopupOverlay').classList.remove('active');
    document.getElementById('warningPopupOverlay').classList.remove('active');
    warningAndTermsAccepted = true; 
    document.querySelector('input[name="name"]').focus();
    
    // Automatically open form if not opened
    if(document.getElementById('regForm').style.display === 'none'){
        toggleRegForm();
    }
}

// QUICK FILTER + PAGINATION RESET
// RESET ALL FILTERS
function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('groupFilter').value = 'All';
    document.getElementById('statusFilter').value = 'All';
    document.getElementById('locationFilter').value = 'All';
    const bf = document.getElementById('badgeFilter');
    if (bf) bf.value = 'All';
    // Reset quick filter buttons
    document.querySelectorAll('.shift-btn').forEach(b => b.classList.remove('active'));
    const allBtn = document.querySelector('.shift-btn');
    if (allBtn) allBtn.classList.add('active');
    fetchFilteredData(1);
}

function quickFilter(group) {
    document.getElementById('groupFilter').value = group;
    if(group !== 'All') {
        document.getElementById('statusFilter').value = 'Available';
    } else {
        document.getElementById('statusFilter').value = 'All';
    }
    
    const buttons = document.querySelectorAll('.shift-btn');
    buttons.forEach(btn => {
        if(btn.innerText.includes(group)) btn.classList.add('active');
        else btn.classList.remove('active');
    });

    fetchFilteredData(1);
    
    // Scroll to table — instant to avoid janky smooth scroll on mobile
    const section = document.getElementById('donorListSection');
    const y = section.getBoundingClientRect().top + window.pageYOffset - 70;
    window.scrollTo({ top: y });
}

// FULL AJAX WITH PAGINATION & LOCATION FILTER
// ── PERFORMANCE: abort previous filter requests ──
let _filterController = null;

function fetchFilteredData(page = 1, doScroll = false) {
    if (_filterController) _filterController.abort();
    _filterController = new AbortController();
    const group    = document.getElementById('groupFilter').value;
    const search   = document.getElementById('searchInput').value;
    const status   = document.getElementById('statusFilter').value;
    const location = document.getElementById('locationFilter').value;
    const badge    = document.getElementById('badgeFilter')?.value || 'All';

    const tableBody = document.getElementById('donorTableBody');
    const cardsBody = document.getElementById('donorCardsBody');

    // Skeleton for table
    let skeletonHtml = '';
    for (let i = 0; i < 5; i++) { 
        skeletonHtml += `<tr class="skeleton-row"><td colspan="7"><div class="skeleton"></div></td></tr>`;
    }
    tableBody.innerHTML = skeletonHtml;

    // Skeleton for mobile cards
    let cardSkel = '';
    for (let i = 0; i < 4; i++) {
        cardSkel += `<div class="dc dc-skeleton"><div class="skeleton" style="height:18px;margin-bottom:8px;width:60%;"></div><div class="skeleton" style="height:14px;margin-bottom:8px;width:80%;"></div><div class="skeleton" style="height:44px;border-radius:10px;"></div></div>`;
    }
    cardsBody.innerHTML = cardSkel;

    const formData = new FormData();
    formData.append('ajax_filter', '1');
    formData.append('filter_group', group);
    formData.append('search_query', search);
    formData.append('filter_status', status);
    formData.append('filter_location', location);
    formData.append('filter_badge', badge);
    formData.append('page', page);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch(window.location.href, { method: 'POST', body: formData, signal: _filterController.signal })
    .then(response => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return safeJSON(response);
    })
    .then(data => {
        tableBody.innerHTML  = data.table  || `<tr><td colspan='7' class='no-data'>✖ কোনো রক্তদাতা পাওয়া যায়নি।</td></tr>`;
        cardsBody.innerHTML  = data.cards  || `<div class='no-data' style='text-align:center;padding:30px;'>✖ কোনো রক্তদাতা পাওয়া যায়নি।</div>`;
        const pagEl = document.getElementById('paginationSection');
        if (pagEl && data.pagination) pagEl.innerHTML = data.pagination;

        // Update stat cards + hero bar with fresh global counts from server
        if (data.counts) {
            const groupMap = {'A+':'Aplus','A-':'Aminus','B+':'Bplus','B-':'Bminus','AB+':'ABplus','AB-':'ABminus','O+':'Oplus','O-':'Ominus'};
            for (const [g, id] of Object.entries(groupMap)) {
                const el = document.getElementById('count-' + id);
                if (el) {
                    const cnt = data.counts[g] || 0;
                    el.textContent = '🩸 ' + cnt + ' Available';
                }
            }
            // Also update hero bar available count
            if (typeof data.total_available !== 'undefined') {
                const heroAvail = document.getElementById('heroAvailDonors');
                if (heroAvail) heroAvail.textContent = data.total_available;
            }
        }

        // Update bottom nav active state — only if currently on donors page
        if (_currentPage === 'donors') updateBottomNav('donors');

        if (doScroll) {
            const target = document.getElementById('donorListSection');
            if (target) {
                const offset = target.getBoundingClientRect().top + window.scrollY - 72;
                window.scrollTo({ top: offset, behavior: 'smooth' });
            }
        }
    })
    .catch(e => {
        if (e && e.name === 'AbortError') return; // ignore aborted requests
        tableBody.innerHTML = `<tr><td colspan='7' class='no-data'>✖ লোড করতে সমস্যা হয়েছে। পেজ রিফ্রেশ করুন।</td></tr>`;
        cardsBody.innerHTML = `<div class='no-data' style='text-align:center;padding:30px;'>✖ লোড করতে সমস্যা হয়েছে। পেজ রিফ্রেশ করুন।</div>`;
    });
}

// TAB SWITCH
function switchTab(n) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('#regSection .tab-btn').forEach(b => b.classList.remove('active'));
    const tabEl = document.getElementById('tab'+n);
    if (tabEl) tabEl.classList.add('active');
    const btns = document.querySelectorAll('#regSection .tab-btn');
    if (btns[n]) btns[n].classList.add('active');
}

function verifyAndLoadInfo() {
    const code = document.getElementById('secretCodeInput').value.trim();
    if(!code) return showValidationError("সার্ট কোড দিতে হবে");

    const fd = new FormData();
    fd.append('verify_secret', '1');
    fd.append('secret_code', code);
    fd.append('csrf_token', CSRF_TOKEN);

    // Reset secret change panel
    (function resetSecretPanel() {
        const body  = document.getElementById('secretChangeBody');
        const arrow = document.getElementById('secretChangeArrow');
        const inp   = document.getElementById('u_new_secret');
        const hint  = document.getElementById('secretHint');
        const prev  = document.getElementById('newSecretPreview');
        if (body)  { body.style.display = 'none'; }
        if (arrow) { arrow.classList.remove('open'); }
        if (inp)   { inp.value = ''; }
        if (hint)  { hint.style.display = 'none'; hint.textContent = ''; }
        if (prev)  { prev.style.display = 'none'; prev.textContent = ''; }
    })();

    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(data => {
        _loadUpdateFields(data);
    })
    .catch(() => showValidationError("Network error। Internet connection চেক করুন আবার চেষ্টা করুন।"));
}

function _loadUpdateFields(data) {
    if(data.status === "success"){
        document.getElementById('updateFields').style.display = 'block';
        document.getElementById('u_name').value = data.name;

        // Parse Area and Exact Location
        let fullLoc = data.location;
        let parts = fullLoc.split(" - ");
        let areaVal  = parts[0] ? parts[0].trim() : fullLoc;
        let exactVal = parts.length > 1 ? parts.slice(1).join(" - ").trim() : "";
        document.getElementById('u_location').value  = areaVal;
        document.getElementById('uExactLocation').value = exactVal;

        // Smart date picker
        if(!data.last_donation || data.last_donation === 'no') {
            setUpdateDonationNever();
        } else {
            var parts2 = data.last_donation.split('/');
            if(parts2.length === 3) {
                setUpdateDonationDate(parts2[2]+'-'+parts2[1]+'-'+parts2[0]);
            } else {
                setUpdateDonationNever();
            }
        }

        // Willing toggle
        setWilling(data.willing || 'yes');

        // Badge card
        document.getElementById('donorBadgeCard').style.display = 'block';
        updateBadgeCard(data.total_donations || 0, data.badge_icon, data.badge_level);

        // 120-day lock
        checkJustDonatedLock(data.last_donation);

        document.getElementById('updateFields').scrollIntoView({ block: 'center' });
    } else {
        showValidationError(data.msg || "❔ সার্ট কোড সঠিক নয় বা খুঁজে পাওয়া যায়নি।");
    }
}

// ── Change 5: Just Donated 120-day lock ────────────────────
function checkJustDonatedLock(lastDonation) {
    const btn     = document.getElementById('justDonatedBtn');
    const lockMsg = document.getElementById('justDonatedLockMsg');
    if(!btn) return;

    if(!lastDonation || lastDonation === 'no') {
        // Never donated — unlock
        btn.disabled = false;
        btn.style.opacity = '';
        btn.style.cursor  = '';
        btn.innerHTML = '🩸 আমি এইমাত্র রক্ত দিয়েছি — Update করুন';
        lockMsg.style.display = 'none';
        return;
    }

    // Parse dd/mm/yyyy
    var parts = lastDonation.split('/');
    if(parts.length !== 3) { btn.disabled = false; lockMsg.style.display='none'; return; }
    var lastDate = new Date(parts[2], parts[1]-1, parts[0]);
    var now      = new Date();
    var diffDays = Math.floor((now - lastDate) / (1000*60*60*24));
    var remaining = 120 - diffDays;

    if(remaining > 0) {
        btn.disabled      = true;
        btn.style.opacity = '0.45';
        btn.style.cursor  = 'not-allowed';
        btn.innerHTML     = '⏳ রক্তদানের ১২০ দিন হয়নি';
        lockMsg.style.display = 'block';
        lockMsg.textContent   = '⚠️ শেষ রক্তদানের পর আরও ' + remaining + ' দিন বাকি। (' + lastDonation + ' থেকে ১২০ দিন)';
    } else {
        btn.disabled = false;
        btn.style.opacity = '';
        btn.style.cursor  = '';
        btn.innerHTML = '🩸 আমি এইমাত্র রক্ত দিয়েছি — Update করুন';
        lockMsg.style.display = 'none';
    }
}

function updateBadgeCard(total, icon, level) {
    document.getElementById('badgeIconBig').textContent = icon || '🌱';
    document.getElementById('badgeLevelName').textContent = (icon||'🌱') + ' ' + (level||'New') + ' Donor';
    document.getElementById('badgeDonations').textContent = total + ' টি রক্তদান';
    let next, needed, progressPct;
    if(total < 2)      { next='Active'; needed=2; progressPct=(total/2)*100; }
    else if(total < 5) { next='Hero';   needed=5; progressPct=(total/5)*100; }
    else if(total < 10){ next='Legend'; needed=10; progressPct=(total/10)*100; }
    else               { next='MAX';    needed=10; progressPct=100; }
    document.getElementById('badgeProgressFill').style.width = progressPct + '%';
    document.getElementById('badgeNextLabel').textContent = next === 'MAX' ? '🏆 সর্বোচ্চ স্তর!' : `পরের Badge: ${next} (${needed-total} আর দরকার)`;
}

// ── Change 4: Smart date picker for update form ─────────────
function setUpdateDonationNever() {
    document.getElementById('u_last').value = 'no';
    document.getElementById('uSdNeverBtn').classList.add('sd-active');
    document.getElementById('uSdDateBtn').classList.remove('sd-active');
    document.getElementById('uSdDatePickerWrap').style.display = 'none';
    document.getElementById('uSdNeverMsg').style.display = 'block';
}
function setUpdateDonationDate(presetISO) {
    document.getElementById('uSdNeverBtn').classList.remove('sd-active');
    document.getElementById('uSdDateBtn').classList.add('sd-active');
    document.getElementById('uSdDatePickerWrap').style.display = 'block';
    document.getElementById('uSdNeverMsg').style.display = 'none';
    var today = new Date().toISOString().split('T')[0];
    var inp = document.getElementById('uSdDateInput');
    inp.max = today; inp.min = '1940-01-01';
    if(presetISO) {
        inp.value = presetISO;
        syncUpdateDonationDate(presetISO);
    } else if(!inp.value) {
        inp.value = today;
        syncUpdateDonationDate(today);
    }
}
function syncUpdateDonationDate(val) {
    if(!val) return;
    var p = val.split('-');
    if(p.length === 3) {
        document.getElementById('u_last').value = p[2]+'/'+p[1]+'/'+p[0];
    }
}

// ── Change 4: Map picker for update form ────────────────────
let _updateMapPickerMap    = null;
let _updateMapPickerMarker = null;

function openUpdateMapPicker() {
    // Reuse the same modal — just swap the confirm handler
    const modal   = document.getElementById('mapPickerModal');
    const loading = document.getElementById('mapPickerLoading');
    const resultEl = document.getElementById('mapPickerResult');
    modal.classList.add('active');
    loading.style.display = 'flex';
    resultEl.value = '';

    // Override the confirm button to fill update form
    const confirmBtn = modal.querySelector('[onclick="useMapPickerLocation()"]');
    if(confirmBtn) confirmBtn.setAttribute('onclick','useUpdateMapPickerLocation()');

    function initUpdatePickerMap() {
        if(typeof L === 'undefined') { setTimeout(initUpdatePickerMap, 400); return; }
        loading.style.display = 'none';
        if(!_mapPickerMap) {
            _mapPickerMap = L.map('leafletMapPicker', {zoomControl:true}).setView([23.7735, 90.3742], 13);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
                attribution: '© OpenStreetMap © CARTO', subdomains:'abcd', maxZoom:19
            }).addTo(_mapPickerMap);
            _mapPickerMap.on('click', function(e) {
                const lat = e.latlng.lat.toFixed(6), lng = e.latlng.lng.toFixed(6);
                if(_mapPickerMarker) { _mapPickerMarker.setLatLng(e.latlng); }
                else {
                    _mapPickerMarker = L.marker(e.latlng, {draggable:true}).addTo(_mapPickerMap);
                    _mapPickerMarker.on('dragend', function() {
                        const p = _mapPickerMarker.getLatLng();
                        doReverseGeocode(p.lat.toFixed(6), p.lng.toFixed(6));
                    });
                }
                doReverseGeocode(lat, lng);
            });
        } else {
            if(_mapPickerMarker) { _mapPickerMap.removeLayer(_mapPickerMarker); _mapPickerMarker = null; }
        }
        setTimeout(()=>{ if(_mapPickerMap) _mapPickerMap.invalidateSize(); }, 400);
        if(navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos){
                _mapPickerMap.setView([pos.coords.latitude, pos.coords.longitude], 15);
            }, null, {timeout:5000});
        }
    }
    setTimeout(initUpdatePickerMap, 150);
}

function useUpdateMapPickerLocation() {
    const val = document.getElementById('mapPickerResult').value.trim();
    if(!val || val.includes('লোড হচ্ছে')) { showValidationError('Map-এ ক্লিক করুন অথবা location লিখুন।'); return; }
    // Fill update form location field
    document.getElementById('u_location').value = val;
    // Save geo coordinates
    if(_mapPickerMarker) {
        const p = _mapPickerMarker.getLatLng();
        document.getElementById('u_reg_geo').value = 'Lat: '+p.lat.toFixed(6)+', Lon: '+p.lng.toFixed(6);
    }
    // Restore original confirm button handler
    const modal = document.getElementById('mapPickerModal');
    const confirmBtn = modal.querySelector('[onclick="useUpdateMapPickerLocation()"]');
    if(confirmBtn) confirmBtn.setAttribute('onclick','useMapPickerLocation()');
    closeMapPicker();
}

function setWilling(val) {
    document.getElementById('u_willing').value = val;
    const yBtn = document.getElementById('willingYesBtn');
    const nBtn = document.getElementById('willingNoBtn');
    const note = document.getElementById('willingNote');
    if(val === 'yes') {
        yBtn.classList.add('active'); nBtn.classList.remove('active');
        note.textContent = '✅ আপনি Available হিসেবে তালিকায় থাকবেন।';
        note.style.color = '#059669';
    } else {
        nBtn.classList.add('active'); yBtn.classList.remove('active');
        note.textContent = '⛔ আপনি Unavailable হিসেবে mark হবেন। যেকোনো সময় পরিবর্তন করা যাবে।';
        note.style.color = '#ef4444';
    }
}

function triggerJustDonated() {
    const btn = document.getElementById('justDonatedBtn');
    if(btn && btn.disabled) return; // Already triggered — prevent double click
    if(!confirm('আপনি কি নিশ্চিত যে এইমাত্র রক্ত দিয়েছেন? এতে আপনার donation count বাড়বে।')) return;
    document.getElementById('u_just_donated').value = '1';
    const today = new Date();
    const dd = String(today.getDate()).padStart(2,'0');
    const mm = String(today.getMonth()+1).padStart(2,'0');
    const yyyy = today.getFullYear();
    const isoToday = yyyy+'-'+mm+'-'+dd;
    setUpdateDonationDate(isoToday);
    setWilling('yes');
    if(btn){
        btn.disabled = true;
        btn.style.opacity = '0.7';
        btn.style.cursor = 'not-allowed';
        btn.innerHTML = '✅ ধন্যবাদ! "Save Changes" করুন';
        btn.style.background = 'linear-gradient(135deg,#059669,#10b981)';
    }
}

// PAGE LOAD → AUTO FETCH FIRST PAGE (pagination always works)
window.onload = function() {
    // ── Hide splash screen with dynamic progress bar + gear speed ──
    (function() {
        var splash = document.getElementById('pwaSplash');
        var pl = document.getElementById('pageLoader');
        if (pl) pl.classList.remove('loader-show');
        if (!splash) return;

        var gear  = document.getElementById('splashGear');
        var fill  = document.getElementById('splashProgressFill');
        var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                        || window.navigator.standalone === true;

        // Progress: 0→100 over ~1.1s with acceleration near end
        var progress = 0;
        var _animFrame;
        var _startTime = performance.now();
        var TOTAL_MS = isStandalone ? 600 : 1100;

        function easeInOut(t) {
            return t < 0.5 ? 2*t*t : -1+(4-2*t)*t;
        }

        function animProgress(now) {
            var elapsed = now - _startTime;
            var t = Math.min(elapsed / TOTAL_MS, 1);
            progress = Math.round(easeInOut(t) * 100);
            if (fill) fill.style.width = progress + '%';

            // Gear rotation speed: slow at start (1.8s/rev), fast at 80%+ (0.3s/rev)
            if (gear) {
                var speed = 1.8 - 1.5 * easeInOut(t); // 1.8s → 0.3s
                gear.style.animation = 'splashNameSlide 0.4s 0.5s ease both, gearSpin ' + speed.toFixed(2) + 's linear infinite';
            }

            if (t < 1) {
                _animFrame = requestAnimationFrame(animProgress);
            } else {
                // Done — fade out
                splash.classList.add('splash-hide');
                setTimeout(function() { splash.classList.add('splash-done'); }, 500);
            }
        }

        if (isStandalone) {
            splash.classList.add('splash-hide');
            setTimeout(function() { splash.classList.add('splash-done'); }, 500);
            return;
        }

        // Start progress animation immediately
        _animFrame = requestAnimationFrame(animProgress);
    })();

    fetchFilteredData(1);
    loadAnalytics();
    startAnalyticsAutoRefresh();
    
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('page') && parseInt(urlParams.get('page')) > 1) {
        document.getElementById("donorListSection").scrollIntoView();
    }
    
    // Mobile: always start on home page
    if (window.innerWidth <= 650) {
        document.querySelectorAll('.app-page').forEach(function(p){ p.classList.remove('page-active'); });
        var home = document.getElementById('page-home');
        if (home) home.classList.add('page-active');
        _currentPage = 'home';
        updateBottomNav('home');
    }
};

// ============================================================
// ANALYTICS
// ============================================================
const BLOOD_COLORS = {
    'A+':'#e74c3c','A-':'#c0392b','B+':'#3498db','B-':'#2980b9',
    'AB+':'#9b59b6','AB-':'#6c3483','O+':'#f39c12','O-':'#e67e22'
};
const BADGE_COLORS = { 'New':'#10b981','Active':'#3b82f6','Hero':'#8b5cf6','Legend':'#f59e0b' };

// ── refreshHomeCounts: lightweight hero bar + stat card refresh ──
// Called when user navigates back to Home tab — avoids showing stale 0 counts.
// Does NOT redraw charts (that's loadAnalytics). Just updates numbers.
function refreshHomeCounts() {
    const fd = new FormData();
    fd.append('get_analytics','1');
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(d => {
        const hTotal = document.getElementById('heroTotalDonors');
        const hAvail = document.getElementById('heroAvailDonors');
        if (hTotal) hTotal.textContent = d.total || 0;
        if (hAvail) hAvail.textContent = d.available || 0;
        if (d.by_group_avail) {
            const gm = {'A+':'Aplus','A-':'Aminus','B+':'Bplus','B-':'Bminus',
                        'AB+':'ABplus','AB-':'ABminus','O+':'Oplus','O-':'Ominus'};
            for (const [g, id] of Object.entries(gm)) {
                const el = document.getElementById('count-' + id);
                if (el) el.textContent = '🩸 ' + (d.by_group_avail[g] || 0) + ' Available';
            }
        }
    }).catch(function(){});
}

function loadAnalytics() {
    const fd = new FormData();
    fd.append('get_analytics','1');
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(window.location.href,{method:'POST',body:fd})
    .then(safeJSON)
    .then(d => {
        // KPIs
        animateNum('kpiTotal', d.total);
        animateNum('kpiAvail', d.available);
        animateNum('kpiUnav',  d.unavailable);
        animateNum('kpiCalls', d.total_calls || 0);
        animateNum('kpiReq',       d.active_requests   || 0);
        animateNum('kpiFulfilled', d.fulfilled_requests || 0);
        // Update home hero bar
        const hTotal = document.getElementById('heroTotalDonors');
        const hAvail = document.getElementById('heroAvailDonors');
        if (hTotal) animateNum('heroTotalDonors', d.total);
        if (hAvail) animateNum('heroAvailDonors', d.available);
        // ── Update stat cards (live counts per blood group) ──
        if (d.by_group_avail) {
            const groupMap = {'A+':'Aplus','A-':'Aminus','B+':'Bplus','B-':'Bminus','AB+':'ABplus','AB-':'ABminus','O+':'Oplus','O-':'Ominus'};
            for (const [g, id] of Object.entries(groupMap)) {
                const el = document.getElementById('count-' + id);
                if (el) {
                    const cnt = d.by_group_avail[g] || 0;
                    el.textContent = '🩸 ' + cnt + ' Available';
                }
            }
        }
        // Blood group bar chart
        renderBarChart(d.by_group);
        // Badge donut
        renderBadgeDonut(d.by_badge);
        // Location chart
        renderLocChart(d.by_loc);
    }).catch((err)=>{
        console.error('Analytics error:', err);
    });
}

// Auto-refresh analytics + stat cards every 60 seconds
let _analyticsRefreshTimer = null;
function startAnalyticsAutoRefresh() {
    if (_analyticsRefreshTimer) clearInterval(_analyticsRefreshTimer);
    _analyticsRefreshTimer = setInterval(function() {
        if (!document.hidden) loadAnalytics();
    }, 60000);
}
// Restart timer when tab becomes visible again (prevents stale data)
document.addEventListener('visibilitychange', function() {
    if (!document.hidden && _analyticsRefreshTimer) {
        clearInterval(_analyticsRefreshTimer);
        _analyticsRefreshTimer = null;
        startAnalyticsAutoRefresh();
    }
});

function animateNum(id, target) {
    const el = document.getElementById(id);
    if(!el) return;
    let start = 0, duration = 900;
    let startTime = null;
    function step(ts) {
        if(!startTime) startTime = ts;
        let progress = Math.min((ts-startTime)/duration, 1);
        let ease = 1 - Math.pow(1 - progress, 3);
        el.textContent = Math.round(ease * target).toLocaleString();
        if(progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
}

function renderBarChart(byGroup) {
    const wrap = document.getElementById('bgChartWrap');
    if(!wrap) return;
    const max = Math.max(...Object.values(byGroup), 1);
    const groups = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
    wrap.innerHTML = groups.map(g => {
        const cnt = byGroup[g] || 0;
        const pct = Math.round((cnt/max)*100);
        const col = BLOOD_COLORS[g] || '#6b7280';
        return `<div class="bar-row">
            <span class="bar-label" style="color:${col};">${g}</span>
            <div class="bar-track">
                <div class="bar-fill" style="width:${pct}%;background:${col};">
                    <span class="bar-count">${cnt}</span>
                </div>
            </div>
        </div>`;
    }).join('');
}

function renderBadgeDonut(byBadge) {
    window._lastBadgeData = byBadge; // cache for theme-switch redraw
    const canvas = document.getElementById('badgeDonut');
    const legend = document.getElementById('badgeLegend');
    if(!canvas || !legend) return;
    const ctx = canvas.getContext('2d');
    const levels = ['New','Active','Hero','Legend'];
    const vals = levels.map(l => byBadge[l] || 0);
    const total = vals.reduce((a,b)=>a+b,0) || 1;
    const colors = levels.map(l => BADGE_COLORS[l]);
    const icons  = {'New':'🌱','Active':'⭐','Hero':'🦸','Legend':'👑'};
    
    // Draw donut
    let startAngle = -Math.PI/2;
    const cx=90, cy=90, outerR=80, innerR=50;
    ctx.clearRect(0,0,180,180);
    vals.forEach((v,i) => {
        const sweep = (v/total)*2*Math.PI;
        ctx.beginPath();
        ctx.moveTo(cx,cy);
        ctx.arc(cx,cy,outerR,startAngle,startAngle+sweep);
        ctx.closePath();
        ctx.fillStyle = colors[i];
        ctx.fill();
        startAngle += sweep;
    });
    // Inner circle (donut hole)
    ctx.beginPath();
    ctx.arc(cx,cy,innerR,0,2*Math.PI);
    ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--bg-main').trim() || '#0f1115';
    ctx.fill();
    // Center text
    ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text-main').trim() || '#fff';
    ctx.font = 'bold 22px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(total, cx, cy);

    legend.innerHTML = levels.map((l,i) => 
        `<div class="badge-legend-item"><div class="badge-legend-dot" style="background:${colors[i]};"></div>${icons[l]} ${l} (${vals[i]})</div>`
    ).join('');
}

function renderLocChart(byLoc) {
    const wrap = document.getElementById('locChartWrap');
    if(!wrap || !byLoc.length) return;
    const max = byLoc[0].cnt || 1;
    wrap.innerHTML = byLoc.map(r => {
        const pct = Math.round((r.cnt/max)*100);
        return `<div class="loc-row">
            <span class="loc-name" title="${r.area}">${r.area}</span>
            <div class="loc-bar-track">
                <div class="loc-bar-fill" style="width:${pct}%;">
                    <span class="loc-count">${r.cnt}</span>
                </div>
            </div>
        </div>`;
    }).join('');
}

// ============================================================
// MAP (Leaflet.js — OpenStreetMap)
// ============================================================
let leafletMap = null;
let _allMapMarkers = []; // store all fetched markers for client-side filtering
let _mapFilterGroup  = 'All';
let _mapFilterStatus = 'All';

function setMapFilter(type, val, btn) {
    if (type === 'group') {
        _mapFilterGroup = val;
        document.querySelectorAll('#mapGroupPills .map-pill').forEach(function(b){ b.classList.remove('active'); });
    } else {
        _mapFilterStatus = val;
        document.querySelectorAll('#mapStatusPills .map-pill').forEach(function(b){ b.classList.remove('active'); });
    }
    if (btn) btn.classList.add('active');
    // If map already loaded, re-apply filter without re-fetching
    if (_allMapMarkers.length > 0) {
        applyMapFilter();
    }
}

function applyMapFilter() {
    if (!leafletMap) return;
    // Remove existing markers
    leafletMap.eachLayer(function(l) {
        if (l instanceof L.CircleMarker) leafletMap.removeLayer(l);
    });
    const filtered = _allMapMarkers.filter(function(m) {
        const groupOk  = _mapFilterGroup  === 'All' || m.group  === _mapFilterGroup;
        const statusOk = _mapFilterStatus === 'All' || m.status === _mapFilterStatus;
        return groupOk && statusOk;
    });
    const infoEl = document.getElementById('mapFilterInfo');
    if (infoEl) {
        if (_mapFilterGroup !== 'All' || _mapFilterStatus !== 'All') {
            infoEl.style.display = 'block';
            infoEl.textContent = '🔍 ' + filtered.length + ' জন donor দেখাচ্ছে (মোট ' + _allMapMarkers.length + ' জনের মধ্যে)';
        } else {
            infoEl.style.display = 'none';
        }
    }
    const bounds = [];
    filtered.forEach(function(m) {
        const color = m.status === 'Available' ? '#10b981' : m.status === 'Unavailable' ? '#6b7280' : '#ef4444';
        const circle = L.circleMarker([m.lat, m.lng], {
            radius: 9, fillColor: color, color: '#fff',
            weight: 2, opacity: 1, fillOpacity: 0.9
        }).addTo(leafletMap);
        circle.bindPopup(
            '<div style="font-family:sans-serif; min-width:160px;">' +
            '<strong style="font-size:1em;">' + m.name + '</strong><br>' +
            '<span style="color:' + color + '; font-weight:700;">🩸 ' + m.group + '</span>' +
            '<span style="float:right; font-size:0.85em; color:#888;">' + m.badge + '</span><br>' +
            '<small>📍 ' + m.loc + '</small><br>' +
            '<small style="color:' + color + ';">' + (m.status === 'Available' ? '✔ Available' : m.status === 'Unavailable' ? '⛔ Not Willing' : '✖ Not Available') + '</small>' +
            '</div>'
        );
        bounds.push([m.lat, m.lng]);
    });
    if (bounds.length && filtered.length !== _allMapMarkers.length) {
        leafletMap.fitBounds(bounds, {padding:[30,30], maxZoom:14});
    }
}

function loadMap() {
    const placeholder = document.getElementById('mapPlaceholder');
    const mapDiv = document.getElementById('leafletMap');
    const legend = document.getElementById('mapLegend');

    if(typeof L === 'undefined') {
        placeholder.innerHTML = '<div style="font-size:2rem;">⏳</div><p>Leaflet লোড হচ্ছে, একটু অপেক্ষা করুন...</p>';
        setTimeout(loadMap, 800);
        return;
    }

    placeholder.innerHTML = '<div style="font-size:2rem;">⏳</div><p>Map লোড হচ্ছে...</p>';

    const fd = new FormData();
    fd.append('get_map_data','1');
    fd.append('csrf_token', CSRF_TOKEN);

    fetch(window.location.href,{method:'POST',body:fd})
    .then(safeJSON)
    .then(markers => {
        if(!markers.length) {
            placeholder.innerHTML = '<div style="font-size:2rem;">😞</div><p>Location data সহ কোনো donor নেই।</p>';
            return;
        }
        placeholder.style.display = 'none';
        mapDiv.style.display = 'block';
        legend.style.display = 'flex';

        // Store all markers for client-side filtering
        _allMapMarkers = markers;

        // Init Leaflet map
        if(!leafletMap) {
            leafletMap = L.map('leafletMap', {zoomControl:true}).setView([23.8103, 90.4125], 10);
            window._mapTileLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
                attribution: '© OpenStreetMap © CARTO',
                subdomains: 'abcd',
                maxZoom: 19
            }).addTo(leafletMap);
        } else {
            leafletMap.eachLayer(function(l) {
                if(l instanceof L.CircleMarker) leafletMap.removeLayer(l);
            });
        }

        // Fix blank map
        const _doInvalidate = () => { if(leafletMap) leafletMap.invalidateSize(); };
        if(typeof ResizeObserver !== 'undefined') {
            const _ro = new ResizeObserver((entries, obs) => {
                if(mapDiv.offsetWidth > 0 && mapDiv.offsetHeight > 0) {
                    _doInvalidate(); obs.disconnect();
                }
            });
            _ro.observe(mapDiv);
            setTimeout(_doInvalidate, 400);
        } else {
            setTimeout(_doInvalidate, 300);
            setTimeout(_doInvalidate, 500);
        }

        // Render all markers (respecting any pre-set filter)
        const bounds = [];
        markers.forEach(function(m) {
            const color = m.status === 'Available' ? '#10b981' : m.status === 'Unavailable' ? '#6b7280' : '#ef4444';
            const groupOk  = _mapFilterGroup  === 'All' || m.group  === _mapFilterGroup;
            const statusOk = _mapFilterStatus === 'All' || m.status === _mapFilterStatus;
            if (!groupOk || !statusOk) return;
            const circle = L.circleMarker([m.lat, m.lng], {
                radius: 9, fillColor: color, color: '#fff',
                weight: 2, opacity: 1, fillOpacity: 0.9
            }).addTo(leafletMap);
            circle.bindPopup(
                '<div style="font-family:sans-serif; min-width:160px;">' +
                '<strong style="font-size:1em;">' + m.name + '</strong><br>' +
                '<span style="color:' + color + '; font-weight:700;">🩸 ' + m.group + '</span>' +
                '<span style="float:right; font-size:0.85em; color:#888;">' + m.badge + '</span><br>' +
                '<small>📍 ' + m.loc + '</small><br>' +
                '<small style="color:' + color + ';">' + (m.status === 'Available' ? '✔ Available' : m.status === 'Unavailable' ? '⛔ Not Willing' : '✖ Not Available') + '</small>' +
                '</div>'
            );
            bounds.push([m.lat, m.lng]);
        });
        if(bounds.length) leafletMap.fitBounds(bounds, {padding:[30,30]});

        // Update filter info
        const infoEl = document.getElementById('mapFilterInfo');
        if (infoEl && (_mapFilterGroup !== 'All' || _mapFilterStatus !== 'All')) {
            infoEl.style.display = 'block';
            infoEl.textContent = '🔍 ' + bounds.length + ' জন donor দেখাচ্ছে (মোট ' + markers.length + ' জনের মধ্যে)';
        }
    }).catch(() => {
        placeholder.innerHTML = '<p style="color:#ef4444;">Map লোড করতে সমস্যা হয়েছে।</p>';
    });
}

// ══════════════════════════════════════════════════════════════
// NOTIFICATION PROMPT — global functions, no closure
// ══════════════════════════════════════════════════════════════
function notifWasDismissed() {
    try {
        var raw = localStorage.getItem('notif_dismissed');
        if (!raw) return false;
        // Legacy value '1' — clear and treat as not dismissed
        if (raw === '1') { localStorage.removeItem('notif_dismissed'); return false; }
        var data = JSON.parse(raw);
        if (data.until && Date.now() < data.until) return true;
        localStorage.removeItem('notif_dismissed'); // expired
        return false;
    } catch(e) { return false; }
}

var _notifRetryCount = 0;
function maybeShowNotifPrompt() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') return;
    if (Notification.permission === 'denied') return;
    if (notifWasDismissed()) return;
    // If PWA overlay visible, retry — but max 4 times (12s total), then show anyway
    var pwaOverlay = document.getElementById('pwaInstallOverlay');
    if (pwaOverlay && pwaOverlay.classList.contains('show') && _notifRetryCount < 4) {
        _notifRetryCount++;
        setTimeout(maybeShowNotifPrompt, 3000); return;
    }
    var p = document.getElementById('notifPrompt');
    if (!p) return;
    requestAnimationFrame(function() { p.classList.add('np-show'); });
}

// Trigger 4s after page loads — completely independent of DOMContentLoaded
setTimeout(maybeShowNotifPrompt, 4000);

// ══════════════════════════════════════════════════════════════
// POPUP STACKING FIX
// ══════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
    const popupIds = [
        'callerInfoPopup',
        'callConfirmPopup',
        'reportPopup',
        'warningPopupOverlay',
        'termsPopupOverlay',
        'aboutUsPopupOverlay',
        'locationBlockedOverlay',
        'bloodReqModal',
        'deleteTokenInfoModal',   // FIX: was missing — token popup never got appended to body
        'deleteRequestModal',     // FIX: was missing — delete modal stuck inside page-home
        'manualTokenModal',       // FIX: was missing
        'requestSecretCodeModal', // new: request secret code
        'getSecretCodeModal',     // new: get secret code by ref
        'adminMsgModal',          // new: message to admin
        'mapPickerModal',
        'gpsPermPrompt',
        'faqModal',
        'popup'  // must be last
    ];
    popupIds.forEach(function(id) {
        const el = document.getElementById(id);
        if (el) document.body.appendChild(el);
    });

    // ── Notification permission prompt ──────────────────────────
    // Shows after 4s. Retries every 3s if PWA is currently open (non-blocking).
    // GPS prompt: 9s পরে — notification prompt settle হওয়ার পরে
    setTimeout(function() {
        if (navigator.geolocation && !localStorage.getItem('gps_prompted')) {
            var pwaOverlay = document.getElementById('pwaInstallOverlay');
            var pwaVisible = pwaOverlay && pwaOverlay.classList.contains('show');
            var notifP = document.getElementById('notifPrompt');
            var notifVisible = notifP && notifP.style.display !== 'none';
            if (!pwaVisible && !notifVisible) {
                showGpsPrompt();
            } else {
                setTimeout(showGpsPrompt, 5000);
            }
        }
    }, 9000);

    function showGpsPrompt() {
        if (navigator.geolocation && !localStorage.getItem('gps_prompted')) {
            localStorage.setItem('gps_prompted', '1');
            const msgEl = document.getElementById('gpsPromptMsg');
            if (msgEl) msgEl.textContent = 'Nearby Donors feature ও Call log-এর জন্য আপনার Location দরকার। Allow করলে কাছের রক্তদাতা খুঁজে পাবেন।';
            const el = document.getElementById('gpsPermPrompt');
            if (el) el.classList.add('active');
        }
    }
});

// ============================================================
// FEATURE: EMERGENCY BLOOD REQUESTS
// ============================================================
// ── PERFORMANCE: debounce utility ──
function _debounce(fn, ms) {
    let t;
    return function(...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms); };
}

// ── Offline / Online Alert ─────────────────────────────────
(function() {
    var _alert = null;
    function getAlert() {
        if (!_alert) _alert = document.getElementById('offlineAlert');
        return _alert;
    }
    function showOffline() {
        var el = getAlert();
        if (el) el.classList.add('show');
    }
    function showOnline() {
        var el = getAlert();
        if (el) {
            el.classList.remove('show');
            // Silently refresh donor list when connection restored
            setTimeout(function() {
                if (typeof fetchFilteredData === 'function') fetchFilteredData(1);
            }, 500);
        }
    }
    window.addEventListener('offline', showOffline);
    window.addEventListener('online',  showOnline);
    // Check on load
    if (!navigator.onLine) showOffline();

    // Smart retry — test connection before reloading
    window.offlineRetry = function(btn) {
        if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
        fetch(window.location.href, { method: 'HEAD', cache: 'no-store' })
            .then(function(r) {
                if (r.ok) {
                    window.location.reload();
                } else {
                    if (btn) { btn.disabled = false; btn.textContent = '🔄 Retry'; }
                }
            })
            .catch(function() {
                if (btn) { btn.disabled = false; btn.textContent = '🔄 Retry'; }
            });
    };
})();

// ── Settings Reload Button ─────────────────────────────────
function settingsReload() {
    var btn = document.querySelector('.settings-reload-btn');
    if (btn) {
        btn.classList.remove('spinning');
        void btn.offsetWidth; // reflow to restart animation
        btn.classList.add('spinning');
        setTimeout(function() { btn.classList.remove('spinning'); }, 500);
    }
    // Close settings then reload
    if (typeof closeSettingsPanel === 'function') closeSettingsPanel();
    setTimeout(function() { window.location.reload(true); }, 200);
}



function openBloodRequestModal(){
    document.getElementById('req_group').value = '';
    // Clear previously selected group button
    document.querySelectorAll('#reqGroupGrid .req-group-btn').forEach(function(b){ b.classList.remove('selected'); });
    document.getElementById('bloodReqModal').classList.add('active');
}

function closeBloodReqModal(){
    document.getElementById('bloodReqModal').classList.remove('active');
    // FIX: ensure scroll lock released when modal closes (MutationObserver may lag)
    _forceUnlockBodyScroll();
}

function selectReqGroup(btn, group){
    document.querySelectorAll('#reqGroupGrid button').forEach(function(b){ b.classList.remove('selected'); });
    btn.classList.add('selected');
    document.getElementById('req_group').value = group;
}

function submitBloodRequest(){
    const patient  = document.getElementById('req_patient').value.trim();
    const group    = document.getElementById('req_group').value;
    const hospital = document.getElementById('req_hospital').value.trim();
    const contact  = document.getElementById('req_contact').value.trim();
    const urgency  = document.getElementById('req_urgency').value;
    const bags     = document.getElementById('req_bags').value;
    const note     = document.getElementById('req_note').value.trim();
    if(!patient||!group||!hospital){ showValidationError('রোগীর নাম, blood group ও হাসপাতাল দিতে হবে।'); return; }
    if(!/^\+8801\d{9}$/.test(contact)){ showValidationError('সঠিক যোগাযোগ নম্বর দিন (+8801XXXXXXXXX)।'); return; }

    // ── FIX: GPS fire-and-forget — do NOT block submit on GPS ──
    // requestGPSWithPrompt caused a popup + wait before submit, making form feel frozen.
    // GPS captured silently in background; submit fires immediately.
    if (navigator.geolocation && (!currentLocData || currentLocData === 'Not provided')) {
        navigator.geolocation.getCurrentPosition(
            function(p){ currentLocData = 'Lat:'+p.coords.latitude+',Lon:'+p.coords.longitude; },
            function(){},
            { timeout:4000, enableHighAccuracy:false, maximumAge:120000 }
        );
    }

    const submitBtn = document.querySelector('#bloodReqSheet button[onclick="submitBloodRequest()"]');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '⏳ পাঠানো হচ্ছে...'; }

    const fd = new FormData();
    fd.append('submit_blood_request','1');
    fd.append('patient_name', patient);
    fd.append('req_blood_group', group);
    fd.append('hospital', hospital);
    fd.append('req_contact', contact);
    fd.append('urgency', urgency);
    fd.append('bags_needed', bags);
    fd.append('req_note', note);
    fd.append('req_location', currentLocData);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(d=>{
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '🆘 Emergency Request পাঠান'; }
        closeBloodReqModal();
        if(d.status==='success'){
            document.getElementById('req_patient').value = '';
            document.getElementById('req_group').value = '';
            document.getElementById('req_hospital').value = '';
            document.getElementById('req_contact').value = '+8801';
            document.getElementById('req_urgency').value = 'High';
            document.getElementById('req_bags').value = '1';
            document.getElementById('req_note').value = '';
            document.querySelectorAll('#reqGroupGrid .req-group-btn').forEach(function(b){ b.classList.remove('selected'); });
            loadBloodRequests();
            document.getElementById('reqSection').style.display='block';
            // Show token modal after bloodReqModal close animation
            // FIX: increased from 320ms → 450ms so MutationObserver scroll-unlock
            // fully completes before token modal re-locks scroll (prevents hang)
            if(d.delete_token && d.request_id){
                addMyRequest(d.request_id, d.delete_token);
                setTimeout(function(){
                    showDeleteTokenModal(d.request_id, d.delete_token);
                }, 450);
            } else if(d.status === 'success') {
                // Token missing — show toast with manual instructions
                showToast('✅ Request পাঠানো হয়েছে! (Token পাওয়া যায়নি — Settings > Clear App Data চাপুন, তারপর আবার চেষ্টা করুন।)', 'success');
            }
        } else {
            showValidationError(d.msg||'ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
        }
    }).catch(function(){
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '🆘 Emergency Request পাঠান'; }
        showValidationError('Network error। Internet connection চেক করুন।');
    });
}

function toggleRequestSection(){
    const sec = document.getElementById('reqSection');
    if(sec.style.display==='none'||sec.style.display===''){
        sec.style.display='block';
        loadBloodRequests();
    } else {
        sec.style.display='none';
    }
}

// ── My Requests localStorage helpers ──────────────────────────
var _myRequests = (function(){
    try { return JSON.parse(localStorage.getItem('my_blood_requests') || '[]'); } catch(e){ return []; }
})();
function _saveMyRequests(){ try{ localStorage.setItem('my_blood_requests', JSON.stringify(_myRequests)); }catch(e){} }
function addMyRequest(id, token){
    var sid = String(id);
    // Avoid duplicates
    _myRequests = _myRequests.filter(function(x){ return String(x.id) !== sid; });
    _myRequests.push({id: sid, token: String(token)});
    _saveMyRequests();
}
function getMyRequestToken(id){ var sid=String(id); var r=_myRequests.find(function(x){ return String(x.id)===sid; }); return r?r.token:null; }
function isMyRequest(id){ return !!getMyRequestToken(id); }

// ── Active filter state ────────────────────────────────────────
var _reqAllData    = [];
var _reqTabMode    = 'all';
var _reqGroupFilter = '';

function setReqTab(mode){
    _reqTabMode = mode;
    var allBtn  = document.getElementById('reqTab_all');
    var mineBtn = document.getElementById('reqTab_mine');
    if(allBtn)  allBtn.classList.toggle('req-tab-active',  mode === 'all');
    if(mineBtn) mineBtn.classList.toggle('req-tab-active', mode === 'mine');
    applyReqFilter();
}

function setReqGroupFilter(group){
    _reqGroupFilter = (_reqGroupFilter === group) ? '' : group;
    document.querySelectorAll('.req-bg-chip').forEach(function(b){
        b.classList.toggle('chip-active', b.dataset.group === _reqGroupFilter);
    });
    var clearBtn = document.getElementById('reqBgFilterClear');
    if(clearBtn) clearBtn.style.display = _reqGroupFilter ? 'inline-block' : 'none';
    applyReqFilter();
}

function clearReqGroupFilter(){
    _reqGroupFilter = '';
    document.querySelectorAll('.req-bg-chip').forEach(function(b){ b.classList.remove('chip-active'); });
    var clearBtn = document.getElementById('reqBgFilterClear');
    if(clearBtn) clearBtn.style.display = 'none';
    applyReqFilter();
}

function applyReqFilter(){
    var filtered = _reqAllData;
    if(_reqTabMode === 'mine') filtered = filtered.filter(function(r){ return isMyRequest(r.id); });
    if(_reqGroupFilter)        filtered = filtered.filter(function(r){ return r.blood_group === _reqGroupFilter; });
    renderReqGrid(filtered, _reqTabMode === 'mine');
}

function renderReqGrid(reqs, showDeleteBtns) {
    var grid = document.getElementById('reqGrid');
    if(!grid) return;
    if(!reqs.length){
        var emptyMsg = (_reqTabMode === 'mine')
            ? '<div style="font-size:2.5rem;margin-bottom:10px;">📭</div>'
              +'<p style="font-weight:700;color:var(--text-main);">এখানে আপনার কোনো Request নেই</p>'
              +'<p style="font-size:0.82em;margin-top:5px;color:var(--text-muted);">নতুন device বা browser থেকে এলে Request দেখাবে না।</p>'
              +'<button onclick="openManualTokenModal()" style="margin-top:14px;width:auto!important;min-height:unset!important;padding:9px 18px;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);color:var(--danger);border-radius:20px;font-size:0.82em;font-weight:700;box-shadow:none;">🔑 Token দিয়ে Request খুঁজুন</button>'
            : '<div style="font-size:3rem;margin-bottom:10px;">🕊️</div><p style="font-weight:600;color:var(--text-main);">এখন কোনো active request নেই</p><p style="font-size:0.85em;margin-top:5px;color:var(--text-muted);">জরুরি প্রয়োজনে উপরের 🆘 বাটনে ক্লিক করুন</p>';
        grid.innerHTML = '<div style="text-align:center;padding:40px;grid-column:1/-1;">' + emptyMsg + '</div>';
        return;
    }
    var urgencyClass = {Critical:'critical', High:'high', Medium:'medium'};
    var urgencyIcon  = {Critical:'🔴', High:'🟠', Medium:'🔵'};
    var timeAgo = function(dt){
        var unix = parseInt(dt, 10);
        var ms   = isNaN(unix) ? new Date(dt).getTime() : unix * 1000;
        var diff = Math.floor((Date.now() - ms) / 60000);
        if (isNaN(diff) || diff < 1) return 'এইমাত্র';
        if (diff < 60)               return diff + 'মিনিট আগে';
        if (diff < 1440)             return Math.floor(diff / 60) + 'ঘণ্টা আগে';
        return Math.floor(diff / 1440) + 'দিন আগে';
    };
    var escHtml = function(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); };
    grid.innerHTML = reqs.map(function(r){
        var mine = isMyRequest(r.id);
        var deleteBtn = mine
            ? '<button onclick="openDeleteRequestModal('+r.id+', \''+escHtml(r.contact)+'\')" style="margin-top:8px;width:100%;padding:9px;background:rgba(220,38,38,0.07);border:1px solid rgba(220,38,38,0.35);color:var(--danger);border-radius:10px;font-size:0.82em;cursor:pointer;font-weight:700;min-height:unset;box-shadow:none;margin-top:8px;">🗑️ আমার Request মুছুন</button>'
            : '';
        var myBadge = mine ? '<span style="font-size:0.7em;background:rgba(220,38,38,0.12);color:var(--danger);border-radius:20px;padding:2px 8px;font-weight:700;margin-left:6px;">👤 আমার</span>' : '';
        return '<div class="req-card '+(urgencyClass[r.urgency]||'high')+'">'
            +'<div style="display:flex;justify-content:space-between;align-items:flex-start;">'
            +'<span class="req-card-urgency '+(urgencyClass[r.urgency]||'high')+'">'+(urgencyIcon[r.urgency]||'')+' '+escHtml(r.urgency)+'</span>'
            +'<span style="font-size:0.75em;color:var(--text-muted);">'+timeAgo(r.created_at)+'</span>'
            +'</div>'
            +'<div class="req-card-group">🩸 '+escHtml(r.blood_group)+myBadge+'</div>'
            +'<div class="req-card-name">👤 '+escHtml(r.patient_name)+'</div>'
            +'<div class="req-card-hosp">🏥 '+escHtml(r.hospital)+'</div>'
            +'<div class="req-card-meta">'
            +'<span class="req-tag">🩸 '+escHtml(r.bags_needed)+' ব্যাগ</span>'
            +(r.note ? '<span class="req-tag">📝 '+escHtml(r.note)+'</span>' : '')
            +'</div>'
            +'<button class="req-call-btn" onclick="window.location=\'tel:'+escHtml(r.contact)+'\'">📞 '+escHtml(r.contact)+' — এখনই Call করুন</button>'
            +deleteBtn
            +'</div>';
    }).join('');
}

function loadBloodRequests(){
    var fd = new FormData();
    fd.append('get_blood_requests','1');
    fd.append('csrf_token', CSRF_TOKEN);

    fetch(window.location.href,{method:'POST',body:fd})
    .then(safeJSON)
    .then(function(reqs){
        _reqAllData = reqs;
        applyReqFilter();
    }).catch(function(){
        document.getElementById('reqGrid').innerHTML = '<div style="text-align:center;padding:20px;color:var(--danger);grid-column:1/-1;">❌ লোড করতে সমস্যা</div>';
    });
}

// ============================================================
// FEATURE: DELETE BLOOD REQUEST (OTP token verify)
// ============================================================
var _dtmCountdownTimer = null;

function showDeleteTokenModal(reqId, token) {
    document.getElementById('dtm_req_id_show').textContent = '#' + reqId;
    document.getElementById('dtm_token_show').textContent  = token;
    // Reset state
    var okBtn       = document.getElementById('dtm_ok_btn');
    var copyBtn     = document.getElementById('dtm_copy_btn');
    var countdownEl = document.getElementById('dtm_countdown');
    okBtn.disabled  = true;
    okBtn.style.background = '#aaa';
    okBtn.style.cursor     = 'not-allowed';
    copyBtn.innerHTML      = '📋 Token ও ID Copy করুন';
    // Store for copy use
    okBtn.dataset.reqId = reqId;
    okBtn.dataset.token = token;
    // Countdown — enable close button after 5s
    var secs = 5;
    countdownEl.textContent = secs + ' সেকেন্ড পর বন্ধ করতে পারবেন...';
    if (_dtmCountdownTimer) clearInterval(_dtmCountdownTimer);
    _dtmCountdownTimer = setInterval(function(){
        secs--;
        if(secs > 0){
            countdownEl.textContent = secs + ' সেকেন্ড পর বন্ধ করতে পারবেন...';
        } else {
            clearInterval(_dtmCountdownTimer);
            _dtmCountdownTimer = null;
            okBtn.disabled          = false;
            okBtn.style.background  = 'var(--danger)';
            okBtn.style.cursor      = 'pointer';
            countdownEl.textContent = '✅ এখন বন্ধ করতে পারবেন।';
        }
    }, 1000);
    document.getElementById('deleteTokenInfoModal').classList.add('active');
}

function copyDeleteToken() {
    var btn     = document.getElementById('dtm_copy_btn');
    var okBtn   = document.getElementById('dtm_ok_btn');
    var reqId   = document.getElementById('dtm_req_id_show').textContent;
    var token   = document.getElementById('dtm_token_show').textContent;
    var text    = 'Blood Arena — Emergency Request\nRequest ID: ' + reqId + '\nDelete Token: ' + token + '\n⚠️ এই Token দিয়ে request মুছতে পারবেন।';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function(){
            btn.innerHTML = '✅ Copy হয়েছে!';
            // Immediately enable OK button after copy
            if (_dtmCountdownTimer) { clearInterval(_dtmCountdownTimer); _dtmCountdownTimer = null; }
            okBtn.disabled         = false;
            okBtn.style.background = 'var(--danger)';
            okBtn.style.cursor     = 'pointer';
            document.getElementById('dtm_countdown').textContent = '✅ Copy সম্পন্ন। এখন বন্ধ করতে পারবেন।';
        }).catch(function(){ _fallbackCopy(text, btn, okBtn); });
    } else {
        _fallbackCopy(text, btn, okBtn);
    }
}

function _fallbackCopy(text, btn, okBtn) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try {
        document.execCommand('copy');
        btn.innerHTML = '✅ Copy হয়েছে!';
        if (_dtmCountdownTimer) { clearInterval(_dtmCountdownTimer); _dtmCountdownTimer = null; }
        okBtn.disabled         = false;
        okBtn.style.background = 'var(--danger)';
        okBtn.style.cursor     = 'pointer';
        document.getElementById('dtm_countdown').textContent = '✅ Copy সম্পন্ন। এখন বন্ধ করতে পারবেন।';
    } catch(e) { btn.innerHTML = '❌ Copy করুন manually'; }
    document.body.removeChild(ta);
}

function openDeleteRequestModal(reqId, contact) {
    document.getElementById('del_req_id').value = reqId;
    document.getElementById('del_contact_pre').value = contact;
    // Auto-fill token if saved in localStorage
    var savedToken = getMyRequestToken(reqId);
    document.getElementById('del_token_input').value = savedToken || '';
    document.getElementById('del_error_msg').style.display = 'none';
    document.getElementById('deleteRequestModal').classList.add('active');
}

function closeDeleteRequestModal() {
    document.getElementById('deleteRequestModal').classList.remove('active');
    _forceUnlockBodyScroll();
}

function closeDeleteTokenInfoModal() {
    document.getElementById('deleteTokenInfoModal').classList.remove('active');
    // FIX: Force-reset ALL scroll lock state.
    // Previously only _forceUnlockBodyScroll() was called, but if _scrollLockCount
    // was out of sync (due to modal stacking), body stayed position:fixed forever —
    // causing home tab and all navigation to appear frozen/broken.
    _scrollLockCount = 0;
    document.body.dataset.scrollLocked = '0';
    var scrollY = parseInt(document.body.dataset.scrollY || '0', 10);
    document.body.style.position = '';
    document.body.style.top      = '';
    document.body.style.left     = '';
    document.body.style.right    = '';
    document.body.style.overflow = '';
    window.scrollTo(0, scrollY);
    showToast('✅ Emergency request পাঠানো হয়েছে! Available donors এখন দেখতে পাবেন।', 'success');
}

function openManualTokenModal() {
    document.getElementById('manual_req_id').value  = '';
    document.getElementById('manual_token').value   = '';
    document.getElementById('manual_token_error').style.display = 'none';
    document.getElementById('manualTokenModal').classList.add('active');
}
function closeManualTokenModal() {
    document.getElementById('manualTokenModal').classList.remove('active');
    // Force-unlock body scroll in case MutationObserver missed it
    _forceUnlockBodyScroll();
}
// Safety: unlock body scroll if no popup-overlay or settings panel is open
function _forceUnlockBodyScroll() {
    setTimeout(function() {
        var anyOpen = document.querySelector('.popup-overlay.active, .settings-panel-overlay.active');
        if (!anyOpen) {
            // FIX: also reset _scrollLockCount so counter never stays stuck at >0
            _scrollLockCount = 0;
            document.body.dataset.scrollLocked = '0';
            var scrollY = parseInt(document.body.dataset.scrollY || '0', 10);
            document.body.style.position = '';
            document.body.style.top      = '';
            document.body.style.left     = '';
            document.body.style.right    = '';
            document.body.style.overflow = '';
            window.scrollTo(0, scrollY);
        }
    }, 50);
}
function saveManualToken() {
    var rid   = document.getElementById('manual_req_id').value.trim();
    var token = document.getElementById('manual_token').value.trim();
    var errEl = document.getElementById('manual_token_error');
    if(!rid || isNaN(parseInt(rid,10))) {
        errEl.textContent='❌ সঠিক Request ID দিন।'; errEl.style.display='block'; return;
    }
    if(!/^\d{6}$/.test(token)) {
        errEl.textContent='❌ ৬ সংখ্যার Token দিন।'; errEl.style.display='block'; return;
    }
    addMyRequest(parseInt(rid,10), token);
    closeManualTokenModal();
    showToast('✅ Request সংরক্ষণ হয়েছে। "👤 আমার Request" tab-এ দেখুন।', 'success');
    loadBloodRequests();
}

function confirmDeleteRequest() {
    const reqId   = document.getElementById('del_req_id').value;
    const contact = document.getElementById('del_contact_pre').value;
    const token   = document.getElementById('del_token_input').value.trim();
    const errEl   = document.getElementById('del_error_msg');

    if(!/^\d{6}$/.test(token)){
        errEl.textContent = '৬ সংখ্যার Delete Token দিন।';
        errEl.style.display = 'block';
        return;
    }

    const btn = document.getElementById('del_confirm_btn');
    btn.disabled = true; btn.textContent = '⏳ যাচাই হচ্ছে...';

    const fd = new FormData();
    fd.append('delete_blood_request', '1');
    fd.append('req_id', reqId);
    fd.append('contact', contact);
    fd.append('delete_token', token);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(d=>{
        btn.disabled = false; btn.textContent = '✅ Delete নিশ্চিত করুন';
        if(d.status === 'success'){
            // Remove from localStorage
            _myRequests = _myRequests.filter(function(x){ return String(x.id) !== String(reqId); });
            _saveMyRequests();
            closeDeleteRequestModal();
            showToast(d.msg || '✅ Request মুছে ফেলা হয়েছে।', 'success');
            loadBloodRequests();
        } else {
            errEl.textContent = d.msg || '❌ ব্যর্থ হয়েছে।';
            errEl.style.display = 'block';
        }
    }).catch(()=>{
        btn.disabled = false; btn.textContent = '✅ Delete নিশ্চিত করুন';
        errEl.textContent = '❌ Network error। আবার চেষ্টা করুন।';
        errEl.style.display = 'block';
    });
}

// ============================================================
// FEATURE: NEARBY DONORS (GPS)
// ============================================================
function loadNearbyDonors(){
    const btn = document.getElementById('nearbyLoadBtn');
    const results = document.getElementById('nearbyResults');
    btn.textContent = '⏳ Location নিচ্ছে...';
    btn.disabled = true;

    if(!navigator.geolocation){
        btn.textContent='📡 খুঁজুন'; btn.disabled=false;
        results.innerHTML='<div class="nearby-empty" style="grid-column:1/-1;"><div style="font-size:2.5rem;">😢</div><p>আপনার browser Geolocation সাপোর্ট করে না।</p></div>';
        return;
    }
    navigator.geolocation.getCurrentPosition(pos=>{
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        btn.textContent = '⏳ Donors খুঁজছে...';

        const fd = new FormData();
        fd.append('get_nearby_donors','1');
        fd.append('lat', lat);
        fd.append('lng', lng);
        fd.append('radius', document.getElementById('nearbyRadius').value);
        fd.append('filter_group', document.getElementById('nearbyGroupFilter').value);
        fd.append('filter_status', document.getElementById('nearbyStatusFilter') ? document.getElementById('nearbyStatusFilter').value : 'All');
        fd.append('csrf_token', CSRF_TOKEN);

        fetch(window.location.href,{method:'POST',body:fd})
        .then(safeJSON)
        .then(d=>{
            btn.textContent='🔄 আবার খুঁজুন'; btn.disabled=false;
            if(d.status!=='success'){ results.innerHTML=`<div class="nearby-empty" style="grid-column:1/-1;">❌ ${d.msg}</div>`; return; }
            if(!d.donors.length){
                results.innerHTML=`<div class="nearby-empty" style="grid-column:1/-1;">
                    <div style="font-size:2.5rem;margin-bottom:10px;">🔍</div>
                    <p style="font-weight:600;">এই এলাকায় কোনো donor পাওয়া যায়নি</p>
                    <p style="font-size:0.85em;color:var(--text-muted);margin-top:5px;">Radius বাড়িয়ে আবার চেষ্টা করুন</p>
                </div>`; return;
            }
            const stCls = {Available:'available', Unavailable:'unavailable', 'Not Available':'notavailable'};
            const stIcon= {Available:'✔', Unavailable:'⛔', 'Not Available':'✖'};
            const bgMap = {'A+':'Aplus','A-':'Aminus','B+':'Bplus','B-':'Bminus','AB+':'ABplus','AB-':'ABminus','O+':'Oplus','O-':'Ominus'};
            results.innerHTML = d.donors.map(dn=>{
                const isAvail = dn.status === 'Available';
                const bgClass = 'blood-' + (bgMap[dn.group] || dn.group.replace(/[^a-zA-Z]/g,''));
                const callBtn = isAvail
                    ? `<button class="dc-call-btn unselectable" onclick="prepCall('${dn.id}')" aria-label="Call donor">📞</button>`
                    : `<button class="dc-call-btn-disabled" disabled title="দাতা এখন Available নেই" aria-label="Not available">🚫</button>`;
                const stText = dn.status === 'Available' ? 'Available' : dn.status === 'Unavailable' ? 'Not Willing' : 'Not Available';
                return `<div class="dc">
                    <div class="dc-badge-wrap">
                        <span class="dc-badge ${bgClass}">${dn.group}</span>
                    </div>
                    <div class="dc-info">
                        <div class="dc-name">${dn.name} <span style="font-size:0.85em;opacity:0.85;">${dn.badge_icon||''}</span></div>
                        <span class="${stCls[dn.status]||'available'} dc-status-badge">${stIcon[dn.status]||'✔'} ${stText}</span>
                        <div class="dc-loc">📍 ${dn.loc}</div>
                        <div class="dc-last">📍 ${dn.dist} km দূরে</div>
                    </div>
                    ${callBtn}
                </div>`;
            }).join('');
        }).catch(()=>{ results.innerHTML='<div class="nearby-empty" style="grid-column:1/-1;">❌ Network error. আবার চেষ্টা করুন।</div>'; btn.textContent='📡 খুঁজুন'; btn.disabled=false; });
    }, err=>{
        btn.textContent='📡 খুঁজুন'; btn.disabled=false;
        let msg = '📍 Location পাওয়া যায়নি।';
        if(err.code===1) msg = '📍 Location permission দিন। Settings এ গিয়ে Allow করুন।';
        results.innerHTML=`<div class="nearby-empty" style="grid-column:1/-1;">
            <div style="font-size:2.5rem;margin-bottom:10px;">📍</div>
            <p style="font-weight:600;">${msg}</p>
            <button class="req-call-btn" style="margin-top:12px;" onclick="loadNearbyDonors()">🔄 আবার চেষ্টা করুন</button>
        </div>`;
    },{timeout:15000, enableHighAccuracy:true});
}

// ============================================================
// LIVE NOTIFICATION SYSTEM — 30s polling, toast, bell
// ============================================================
let _lnTimer = null;
let _seenIds  = new Set();

function toggleNPanel() {
    const p = document.getElementById('nPanel');
    p.classList.toggle('show');
    if(p.classList.contains('show')) {
        document.getElementById('nBadge').classList.remove('on');
        // Clear PWA app icon badge when user opens the panel
        if ('clearAppBadge' in navigator && Notification.permission === 'granted') {
            navigator.clearAppBadge().catch(function(){});
        }
    }
}
document.addEventListener('click', function(e){
    // Close notification panel — check bell wrap AND panel itself
    const w = document.getElementById('nBellWrap');
    const p = document.getElementById('nPanel');
    if(p && p.classList.contains('show')) {
        if((!w || !w.contains(e.target)) && !p.contains(e.target)) {
            p.classList.remove('show');
        }
    }
    // Close mobile nav
    const nav = document.getElementById('siteNav');
    if (nav && !nav.contains(e.target)) {
        const links = document.getElementById('navLinks');
        if (links && links.classList.contains('open')) {
            links.classList.remove('open');
            document.body.style.overflow = '';
        }
    }
});

function showToast(r, type) {
    const wrap = document.getElementById('toastWrap');
    if(!wrap) return;
    // If called with a plain string (e.g. GPS error), show simple toast
    if (typeof r === 'string') {
        const el = document.createElement('div');
        el.className = 'toast-item';
        const ico = type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️';
        el.innerHTML = '<div class="toast-ico">' + ico + '</div>'
            + '<div class="toast-bd"><div class="toast-sub" style="color:var(--text-main);font-size:0.88em;">' + r + '</div></div>'
            + '<button class="toast-x" onclick="var t=this.closest(\'.toast-item\');t.classList.add(\'bye\');setTimeout(function(){t.remove()},260)">✕</button>';
        wrap.appendChild(el);
        setTimeout(function(){ if(el.parentNode){el.classList.add('bye');setTimeout(function(){el.remove();},260);} }, 4000);
        return;
    }
    // Blood request object toast — শুধু in-app toast, system notification showSystemNotif() করে
    const icons={Critical:'🔴',High:'🟠',Medium:'🔵'};
    const el = document.createElement('div');
    el.className = 'toast-item';
    el.innerHTML = '<div class="toast-ico">🆘</div>'
        + '<div class="toast-bd">'
        + '<div class="toast-ttl">' + (icons[r.urgency]||'🟠') + ' ' + (r.urgency||'') + ' — ' + (r.blood_group||'') + ' রক্ত দরকার!</div>'
        + '<div class="toast-sub">🏥 ' + (r.hospital||'') + '<br>📞 ' + (r.contact||'') + '</div>'
        + '</div>'
        + '<button class="toast-x" onclick="var t=this.closest(\'.toast-item\');t.classList.add(\'bye\');setTimeout(function(){t.remove()},260)">✕</button>';
    wrap.appendChild(el);
    setTimeout(function(){ if(el.parentNode){el.classList.add('bye');setTimeout(function(){el.remove();},260);} }, 7000);
    // FIX: system notification সরানো হয়েছে — showSystemNotif() এখন এটা handle করে
    // Play notification sound for new blood requests
    if (localStorage.getItem('sound_off') !== '1') {
        try {
            const s = document.getElementById('successSound');
            if (s) { s.currentTime = 0; s.play().catch(function(){}); }
        } catch(e) {}
    }
}

// ── Mark-as-read — localStorage-এ read request IDs রাখি ──────
var _readIds = (function(){
    try { return new Set(JSON.parse(localStorage.getItem('notif_read_ids') || '[]')); }
    catch(e) { return new Set(); }
})();
function _saveReadIds() {
    try { localStorage.setItem('notif_read_ids', JSON.stringify([..._readIds])); } catch(e) {}
}
function markNotifRead(reqId) {
    _readIds.add(String(reqId));
    _saveReadIds();
    // Panel re-render — current data থেকে unread গুলো দেখাও
    refreshNPanel(_reqAllData || []);
}
function markAllNotifRead() {
    (_reqAllData || []).forEach(function(r){ _readIds.add(String(r.id)); });
    _saveReadIds();
    refreshNPanel(_reqAllData || []);
}

function refreshNPanel(reqs) {
    const list  = document.getElementById('nList');
    const count = document.getElementById('nCount');
    const badge = document.getElementById('nBadge');
    if(!list) return;

    // Read filter — read করা IDs বাদ দাও
    const unread = reqs.filter(function(r){ return !_readIds.has(String(r.id)); });

    if(!unread.length) {
        list.innerHTML = reqs.length && _readIds.size
            ? '<div class="notif-empty">✅ সব পড়া হয়েছে</div>'
            : '<div class="notif-empty">কোনো active request নেই</div>';
        if(count) count.textContent = ''; badge.classList.remove('on');
        if ('clearAppBadge' in navigator && Notification.permission === 'granted') {
            navigator.clearAppBadge().catch(function(){});
        }
        return;
    }

    if(count) count.textContent = unread.length + 'টি unread';
    const icons = {Critical:'🔴', High:'🟠', Medium:'🔵'};

    list.innerHTML = unread.map(function(r){
        return '<div class="notif-row">'
            + '<div class="notif-row-left" onclick="toggleRequestSection();document.getElementById(\'nPanel\').classList.remove(\'show\')">'
            + '<div class="notif-row-grp">' + r.blood_group + ' <span style="font-size:0.55em;font-weight:700;">' + (icons[r.urgency]||'') + ' ' + r.urgency + '</span></div>'
            + '<div class="notif-row-info">🏥 ' + r.hospital + '<br>📞 ' + r.contact + '</div>'
            + '</div>'
            + '<button class="notif-mark-btn" onclick="event.stopPropagation();markNotifRead(' + r.id + ')" title="Mark as read">✓ Read</button>'
            + '</div>';
    }).join('');

    // Mark All Read button
    list.innerHTML += '<button class="notif-panel-mark-all" onclick="markAllNotifRead()">✓ সব Mark as Read করুন</button>';

    badge.textContent = unread.length > 9 ? '9+' : unread.length;
    if(!document.getElementById('nPanel').classList.contains('show')) badge.classList.add('on');
    // Update blood tab badge
    var bloodTabBadge = document.getElementById('nTabBloodBadge');
    if (bloodTabBadge) {
        if (unread.length) {
            bloodTabBadge.textContent = unread.length;
            bloodTabBadge.style.display = '';
        } else {
            bloodTabBadge.style.display = 'none';
        }
    }
    if ('setAppBadge' in navigator && Notification.permission === 'granted') {
        navigator.setAppBadge(unread.length).catch(function(){});
    }
}

function startLiveNotif() {
    if(_lnTimer) return;
    function poll() {
        // Tab hidden থাকলে poll করব না — network save
        if (document.hidden) return;
        var fd = new FormData();
        fd.append('get_blood_requests','1');
        fd.append('csrf_token', CSRF_TOKEN);
        fetch(window.location.href,{method:'POST',body:fd})
        .then(safeJSON)
        .then(function(reqs){
            var newOnes = reqs.filter(function(r){return !_seenIds.has(String(r.id));});
            if(_seenIds.size>0 && newOnes.length>0) {
                // FIX: showToast বদলে showSystemNotif — in-app toast বন্ধ,
                // শুধু phone notification panel-এ system notification যাবে
                newOnes.forEach(function(r){ showSystemNotif(r); });
                triggerBellRing();
                // নতুন request এলে read list থেকে সরাও — unread হিসেবে দেখাবে
                newOnes.forEach(function(r){ _readIds.delete(String(r.id)); });
                _saveReadIds();
            }
            reqs.forEach(function(r){_seenIds.add(String(r.id));});
            // _reqAllData সব সময় update রাখো — refreshNPanel এটা ব্যবহার করে
            _reqAllData = reqs;
            refreshNPanel(reqs);
            var sec = document.getElementById('reqSection');
            if(sec && sec.style.display !== 'none' && sec.style.display !== '') {
                // Don't re-render if manualTokenModal is open — would reset user input
                var mtModal = document.getElementById('manualTokenModal');
                if (!mtModal || !mtModal.classList.contains('active')) {
                    applyReqFilter();
                }
            }
        }).catch(function(){});
    }
    poll();
    _lnTimer = setInterval(poll, 30000);
    // Tab visible হলে immediately poll করি
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) poll();
    });
}
window.addEventListener('load', startLiveNotif);
window.addEventListener('load', startSvcNotifPoll);

// ============================================================
// DEVICE ID — localStorage UUID, persistent per browser
// Save হয় exactly একবার — same ID থাকলে আর পাঠায় না।
// Cache clear বা নতুন browser = নতুন ID = আবার একবার save।
// ============================================================
function getDeviceId() {
    var id = localStorage.getItem('ba_device_id');
    if (!id) {
        id = 'dev_' + Math.random().toString(36).substr(2,9) + '_' + Date.now().toString(36);
        localStorage.setItem('ba_device_id', id);
    }
    return id;
}

(function() {
    window.addEventListener('load', function() {
        var currentId  = getDeviceId();
        var lastSavedId = localStorage.getItem('ba_device_saved_id');
        if (lastSavedId === currentId) return; // same ID — already saved, skip
        setTimeout(function() {
            _saveDeviceId('first_visit');
            localStorage.setItem('ba_device_saved_id', currentId);
        }, 800);
    });
})();
// ============================================================
// NOTIFICATION PANEL — 2-TAB SYSTEM
// ============================================================
var _currentNTab = 'blood'; // 'blood' | 'service'

function switchNTab(tab) {
    _currentNTab = tab;
    var bloodBtn  = document.getElementById('nTabBlood');
    var svcBtn    = document.getElementById('nTabSvc');
    var bloodCont = document.getElementById('nTabBloodContent');
    var svcCont   = document.getElementById('nTabSvcContent');
    if (tab === 'blood') {
        bloodBtn.classList.add('active');
        svcBtn.classList.remove('active');
        bloodCont.style.display = '';
        svcCont.style.display   = 'none';
    } else {
        svcBtn.classList.add('active');
        bloodBtn.classList.remove('active');
        svcCont.style.display   = '';
        bloodCont.style.display = 'none';
        // Load fresh service notifs when tab is opened
        _loadSvcNotifs();
    }
}

// Override toggleNPanel to support tab badge clearing
(function(){
    var _orig = window.toggleNPanel;
    window.toggleNPanel = function() {
        var p = document.getElementById('nPanel');
        p.classList.toggle('show');
        if (p.classList.contains('show')) {
            document.getElementById('nBadge').classList.remove('on');
            if ('clearAppBadge' in navigator && Notification.permission === 'granted') {
                navigator.clearAppBadge().catch(function(){});
            }
            // If services tab active, reload
            if (_currentNTab === 'service') _loadSvcNotifs();
        }
    };
})();

// ============================================================
// SERVICE NOTIFICATIONS — device-specific, polls every 30s
// ============================================================
var _svcNotifTimer = null;
var _svcNotifsData = [];

function startSvcNotifPoll() {
    if (_svcNotifTimer) return;
    _loadSvcNotifs();
    _svcNotifTimer = setInterval(function() {
        if (!document.hidden) _loadSvcNotifs();
    }, 30000);
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) _loadSvcNotifs();
    });
}

function _loadSvcNotifs() {
    var fd = new FormData();
    fd.append('get_service_notifs', '1');
    fd.append('device_id', getDeviceId());
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(function(notifs) {
        // Read notifications filter — server থেকে আসা read items render করে না
        _svcNotifsData = (notifs || []).filter(function(n){ return !n.is_read; });
        _renderSvcNotifs(_svcNotifsData);
        _updateSvcBadge(_svcNotifsData);
        // Trigger bell ring for new unread service notifs
        var unread = _svcNotifsData.filter(function(n){ return !n.is_read; });
        if (unread.length > 0) triggerBellRing();
    }).catch(function(){});
}

function _renderSvcNotifs(notifs) {
    var list = document.getElementById('nSvcList');
    if (!list) return;
    var countEl = document.getElementById('nSvcCount');
    var unread = notifs.filter(function(n){ return !n.is_read; });
    if (countEl) countEl.textContent = unread.length ? unread.length + 'টি unread' : '';

    if (!notifs.length) {
        list.innerHTML = '<div class="notif-empty">কোনো service notification নেই</div>';
        return;
    }

    var iconMap = {
        'secret_reset':'🔑', 'location_on':'📍', 'notif_on':'🔔',
        'secret_code_ready':'✅', 'info':'ℹ️', 'warning':'⚠️', 'admin_reply':'💬'
    };

    list.innerHTML = notifs.map(function(n) {
        var icon = iconMap[n.type] || 'ℹ️';
        var ts   = n.ts ? new Date(n.ts * 1000).toLocaleString('bn-BD') : '';
        var unreadCls = !n.is_read ? ' unread' : '';
        var readBtn = !n.is_read
            ? '<button class="svc-notif-read-btn" onclick="event.stopPropagation();markSvcNotifRead(' + n.id + ')">✓ পড়েছি</button>'
            : '';
        return '<div class="svc-notif-row' + unreadCls + '" id="svcn_' + n.id + '">'
            + '<div class="svc-notif-icon">' + icon + '</div>'
            + '<div class="svc-notif-body">'
            + '<div class="svc-notif-msg">' + (n.message || '') + '</div>'
            + '<div class="svc-notif-time">' + ts + '</div>'
            + '</div>'
            + '<div class="svc-notif-actions">'
            + readBtn
            + '</div>'
            + '</div>';
    }).join('');

    // Attach swipe-to-dismiss handlers
    list.querySelectorAll('.svc-notif-row').forEach(function(row) {
        _attachSwipeDismiss(row);
    });

    if (unread.length) {
        list.innerHTML += '<button class="notif-panel-mark-all" onclick="markAllSvcNotifsRead()" style="margin-top:4px;">✓ সব Read করুন</button>';
    }
}

// Swipe to dismiss (touch + mouse)
function _attachSwipeDismiss(el) {
    var startX = 0, curX = 0, swiping = false;
    function onStart(e) {
        startX = (e.touches ? e.touches[0].clientX : e.clientX);
        swiping = true; curX = 0;
    }
    function onMove(e) {
        if (!swiping) return;
        curX = (e.touches ? e.touches[0].clientX : e.clientX) - startX;
        if (curX > 10) { // only right swipe
            el.style.transform = 'translateX(' + Math.min(curX, 120) + 'px)';
            el.style.opacity = String(1 - curX / 200);
        }
    }
    function onEnd() {
        swiping = false;
        if (curX > 80) {
            // Swipe to dismiss = mark as read + animate out
            var idMatch = el.id.match(/svcn_(\d+)/);
            if (idMatch) {
                var nid = parseInt(idMatch[1]);
                el.classList.add('swiping-out');
                setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 320);
                _svcNotifsData = (_svcNotifsData || []).map(function(n){
                    return n.id == nid ? Object.assign({}, n, {is_read: 1}) : n;
                });
                _updateSvcBadge(_svcNotifsData);
                _markSvcNotifReadServer(nid);
            }
        } else {
            el.style.transform = '';
            el.style.opacity = '';
        }
    }
    el.addEventListener('touchstart', onStart, {passive:true});
    el.addEventListener('touchmove',  onMove,  {passive:true});
    el.addEventListener('touchend',   onEnd);
    el.addEventListener('mousedown',  onStart);
    el.addEventListener('mousemove',  onMove);
    el.addEventListener('mouseup',    onEnd);
}

function deleteSvcNotif(id, skipConfirm) {
    var el = document.getElementById('svcn_' + id);
    if (el) {
        el.classList.add('swiping-out');
        setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 320);
    }
    // Remove from local data
    _svcNotifsData = (_svcNotifsData || []).filter(function(n){ return n.id != id; });
    _updateSvcBadge(_svcNotifsData);
    // Mark as read on server (no separate delete endpoint needed — just read+hide)
    markSvcNotifRead(id);
}

function deleteAllSvcNotifs() {
    var list = document.getElementById('nSvcList');
    if (list) {
        list.querySelectorAll('.svc-notif-row').forEach(function(row) {
            row.classList.add('swiping-out');
        });
        setTimeout(function() {
            (_svcNotifsData || []).forEach(function(n) { markSvcNotifRead(n.id); });
            _svcNotifsData = [];
            _renderSvcNotifs([]);
            _updateSvcBadge([]);
        }, 320);
    }
}

function _updateSvcBadge(notifs) {
    var badge = document.getElementById('nTabSvcBadge');
    var mainBadge = document.getElementById('nBadge');
    var unread = notifs.filter(function(n){ return !n.is_read; });
    if (!badge) return;
    if (unread.length) {
        badge.textContent = unread.length;
        badge.style.display = '';
        // Also update main bell badge to show combined
        var bloodUnread = (_reqAllData||[]).filter(function(r){ return !_readIds.has(String(r.id)); }).length;
        var total = bloodUnread + unread.length;
        if (mainBadge && !document.getElementById('nPanel').classList.contains('show')) {
            mainBadge.textContent = total > 9 ? '9+' : total;
            mainBadge.classList.add('on');
        }
    } else {
        badge.style.display = 'none';
    }
}

function _markSvcNotifReadServer(id) {
    var fd = new FormData();
    fd.append('mark_service_notif_read', '1');
    fd.append('notif_id', id);
    fd.append('device_id', getDeviceId());
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(window.location.href, {method:'POST', body:fd}).catch(function(){});
}

function markSvcNotifRead(id) {
    // Optimistic UI — local state update, NO re-fetch
    // (re-fetch করলে server থেকে সব notification ফিরে আসে — এটাই bug ছিল)
    _svcNotifsData = (_svcNotifsData || []).map(function(n){
        return n.id == id ? Object.assign({}, n, {is_read: 1}) : n;
    });
    _renderSvcNotifs(_svcNotifsData);
    _updateSvcBadge(_svcNotifsData);
    _markSvcNotifReadServer(id);
}

function markAllSvcNotifsRead() {
    (_svcNotifsData || []).forEach(function(n) {
        if (!n.is_read) markSvcNotifRead(n.id);
    });
}

// Send a service push notification to a device (called from admin panel side via PHP)
// Also: show system browser notification for service notifs
function _showSvcSystemNotif(msg, type) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    var iconMap = {'secret_reset':'🔑','secret_code_ready':'✅','location_on':'📍','notif_on':'🔔'};
    var icon = iconMap[type] || 'ℹ️';
    var opts = {
        body: msg,
        icon: '/?badge_icon=1',
        badge: '/?badge_icon=1',
        tag: 'svc_' + type + '_' + Date.now(),
        vibrate: [100, 50, 100],
        requireInteraction: false
    };
    if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        navigator.serviceWorker.ready.then(function(reg) {
            reg.showNotification(icon + ' Blood Arena — Service', opts).catch(function(){});
        });
    }
}

// ============================================================
// MODAL: REQUEST NEW SECRET CODE
// ============================================================
function openRequestSecretCodeModal(prefillPhone) {
    document.getElementById('rsc_phone').value = (prefillPhone && /^\+8801\d{9}$/.test(prefillPhone)) ? prefillPhone : '+8801';
    document.getElementById('rsc_ref').value = '';
    document.getElementById('rsc_error').style.display = 'none';
    document.getElementById('rsc_success').style.display = 'none';
    document.getElementById('rsc_submit_btn').disabled = false;
    document.getElementById('rsc_submit_btn').textContent = '📩 Request পাঠান';
    var el = document.getElementById('rsc_strength');
    if (el) el.innerHTML = '';
    openOverlay('requestSecretCodeModal');
}

function closeRequestSecretCodeModal() {
    closeOverlay('requestSecretCodeModal');
}

// Live Reference Code strength checker
var _WEAK_CODES = ['0000','1111','2222','3333','4444','5555','6666','7777','8888','9999',
    '1234','2345','3456','4567','5678','6789','0123','9876','8765','7654',
    '6543','5432','4321','3210','1212','2121','1313','0101','1010','1122',
    '2233','3344','4455','5566','6677','7788','8899'];

function checkRefStrength(val) {
    var el = document.getElementById('rsc_strength');
    if (!el) return;
    if (!val || val.length < 4) { el.innerHTML = ''; return; }

    var isWeak = _WEAK_CODES.indexOf(val) !== -1;
    // Common birth year pattern: 19xx or 20xx
    var isBirthYear = /^(19|20)\d{2}$/.test(val);
    // Repeated pairs: 1122, 2233
    var isRepeatedPair = /^(\d)\1(\d)\2$/.test(val);
    // All same after first: 1000, 0001 pattern (already in list mostly)

    if (isWeak || isBirthYear || isRepeatedPair) {
        el.innerHTML = '<span style="color:#ef4444;font-weight:600;">⚠️ সহজে guess করা যায় — অন্য সংখ্যা বেছে নিন!</span>';
    } else {
        el.innerHTML = '<span style="color:#10b981;font-weight:600;">✅ ভালো Reference Code!</span>';
    }
}

function submitRequestSecretCode() {
    var phone  = document.getElementById('rsc_phone').value.trim();
    var ref    = document.getElementById('rsc_ref').value.trim();
    var errEl  = document.getElementById('rsc_error');
    var sucEl  = document.getElementById('rsc_success');
    var btn    = document.getElementById('rsc_submit_btn');

    errEl.style.display = 'none';
    sucEl.style.display = 'none';

    if (!/^\+8801\d{9}$/.test(phone)) {
        errEl.textContent = '❌ সঠিক ফোন নম্বর দিন (+8801XXXXXXXXX)';
        errEl.style.display = 'block';
        return;
    }
    if (!/^\d{4}$/.test(ref)) {
        errEl.textContent = '❌ Reference Code অবশ্যই ৪ সংখ্যার হতে হবে (যেমন: 1234)';
        errEl.style.display = 'block';
        return;
    }
    // Block weak codes client-side too
    var _wk = _WEAK_CODES || [];
    var _by = /^(19|20)\d{2}$/.test(ref);
    var _rp = /^(\d)\1(\d)\2$/.test(ref);
    if (_wk.indexOf(ref) !== -1 || _by || _rp) {
        errEl.textContent = '❌ এই Reference Code সহজে guess করা যায়। অন্য সংখ্যা বেছে নিন।';
        errEl.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ পাঠানো হচ্ছে...';

    var fd = new FormData();
    fd.append('request_new_secret_code', '1');
    fd.append('donor_number', phone);
    fd.append('ref_code', ref);
    fd.append('device_id', getDeviceId());
    fd.append('csrf_token', CSRF_TOKEN);

    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(function(d) {
        btn.disabled = false;
        btn.textContent = '📩 Request পাঠান';
        if (d.status === 'success') {
            sucEl.innerHTML = (d.msg || '✅ Request পাঠানো হয়েছে।')
                + '<br><strong style="color:var(--text-main);">আপনার Reference Code: ' + ref + '</strong> — এটি মনে রাখুন!';
            sucEl.style.display = 'block';
            btn.disabled = true;
            btn.textContent = '✅ Request পাঠানো হয়েছে';
        } else {
            errEl.textContent = d.msg || '❌ কিছু ভুল হয়েছে।';
            errEl.style.display = 'block';
        }
    }).catch(function() {
        btn.disabled = false;
        btn.textContent = '📩 Request পাঠান';
        errEl.textContent = '❌ Network error। আবার চেষ্টা করুন।';
        errEl.style.display = 'block';
    });
}

// ============================================================
// MODAL: GET SECRET CODE BY REF CODE
// ============================================================
function openGetSecretCodeModal() {
    document.getElementById('gsc_phone').value = '+8801';
    document.getElementById('gsc_ref').value = '';
    document.getElementById('gsc_error').style.display = 'none';
    document.getElementById('gsc_result').style.display = 'none';
    document.getElementById('gsc_submit_btn').disabled = false;
    document.getElementById('gsc_submit_btn').textContent = '🔍 Secret Code দেখুন';
    openOverlay('getSecretCodeModal');
}

function closeGetSecretCodeModal() {
    closeOverlay('getSecretCodeModal');
}

function submitGetSecretCode() {
    var phone  = document.getElementById('gsc_phone').value.trim();
    var ref    = document.getElementById('gsc_ref').value.trim();
    var errEl  = document.getElementById('gsc_error');
    var resEl  = document.getElementById('gsc_result');
    var btn    = document.getElementById('gsc_submit_btn');

    errEl.style.display = 'none';
    resEl.style.display = 'none';

    if (!/^\+8801\d{9}$/.test(phone)) {
        errEl.textContent = '❌ সঠিক ফোন নম্বর দিন।';
        errEl.style.display = 'block';
        return;
    }
    if (!/^\d{4}$/.test(ref)) {
        errEl.textContent = '❌ Reference Code অবশ্যই ৪ সংখ্যার হতে হবে।';
        errEl.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ খোঁজা হচ্ছে...';

    var fd = new FormData();
    fd.append('get_secret_code_by_ref', '1');
    fd.append('donor_number', phone);
    fd.append('ref_code', ref);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(function(d) {
        btn.disabled = false;
        btn.textContent = '🔍 Secret Code দেখুন';
        if (d.status === 'success') {
            document.getElementById('gsc_code_display').textContent = d.secret_code || '';
            // Show views left info
            var infoEl = document.getElementById('gsc_views_info');
            if (infoEl) {
                if (d.expired) {
                    infoEl.innerHTML = '⚠️ <strong style="color:var(--danger);">এটি আপনার শেষ সুযোগ ছিল। এই Reference Code আর কাজ করবে না।</strong> নতুন request করুন।';
                    infoEl.style.color = 'var(--danger)';
                } else {
                    infoEl.textContent = '⏳ আর ' + d.views_left + ' বার দেখতে পারবেন। (মোট ৩ বার)';
                }
            }
            resEl.style.display = 'block';
            // Disable button if expired
            if (d.expired) {
                btn.disabled = true;
                btn.textContent = '⛔ Expired';
                btn.style.background = 'var(--danger)';
            }
        } else {
            errEl.textContent = d.msg || '❌ কিছু ভুল হয়েছে।';
            errEl.style.display = 'block';
        }
    }).catch(function() {
        btn.disabled = false;
        btn.textContent = '🔍 Secret Code দেখুন';
        errEl.textContent = '❌ Network error। আবার চেষ্টা করুন।';
        errEl.style.display = 'block';
    });
}

function copyGscCode() {
    var code = document.getElementById('gsc_code_display').textContent;
    if (!code) return;
    navigator.clipboard.writeText(code).then(function() {
        showToast('✅ Secret Code copy হয়েছে!', 'success');
    }).catch(function() {
        showToast('⚠️ Copy করতে পারেনি। নিজে select করুন।', 'warning');
    });
}

// ============================================================
// ADMIN MESSAGE MODAL
// ============================================================
function openAdminMessageModal() {
    document.getElementById('adm_sender_name').value = '';
    document.getElementById('adm_sender_phone').value = '+8801';
    document.getElementById('adm_sender_msg').value = '';
    document.getElementById('adm_msg_error').style.display = 'none';
    document.getElementById('adm_msg_success').style.display = 'none';
    var btn = document.getElementById('adm_msg_btn');
    if (btn) { btn.disabled = false; btn.textContent = '📤 পাঠান'; }
    openOverlay('adminMsgModal');
}

function closeAdminMsgModal() {
    closeOverlay('adminMsgModal');
}

function submitAdminMessage() {
    var name  = document.getElementById('adm_sender_name').value.trim();
    var phone = document.getElementById('adm_sender_phone').value.trim();
    var msg   = document.getElementById('adm_sender_msg').value.trim();
    var errEl = document.getElementById('adm_msg_error');
    var sucEl = document.getElementById('adm_msg_success');
    var btn   = document.getElementById('adm_msg_btn');
    errEl.style.display = 'none';
    sucEl.style.display = 'none';

    if (!name) { errEl.textContent = '❌ নাম দিন।'; errEl.style.display = 'block'; return; }
    if (!/^\+8801\d{9}$/.test(phone)) { errEl.textContent = '❌ সঠিক ফোন নম্বর দিন (+8801XXXXXXXXX)।'; errEl.style.display = 'block'; return; }
    if (!msg || msg.length < 5) { errEl.textContent = '❌ Message লিখুন (কমপক্ষে ৫ অক্ষর)।'; errEl.style.display = 'block'; return; }

    btn.disabled = true; btn.textContent = '⏳ পাঠানো হচ্ছে...';
    var fd = new FormData();
    fd.append('submit_admin_message', '1');
    fd.append('sender_name', name);
    fd.append('sender_phone', phone);
    fd.append('message', msg);
    fd.append('device_id', getDeviceId());
    fd.append('csrf_token', CSRF_TOKEN);

    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(function(d) {
        btn.disabled = false; btn.textContent = '📤 পাঠান';
        if (d.status === 'success') {
            sucEl.textContent = d.msg || '✅ ধন্যবাদ! বার্তা পাঠানো হয়েছে।';
            sucEl.style.display = 'block';
            btn.disabled = true; btn.textContent = '✅ পাঠানো হয়েছে';
            document.getElementById('adm_sender_msg').value = '';
            // Start polling for admin reply
            _startAdminReplyPoll();
        } else {
            errEl.textContent = d.msg || '❌ কিছু ভুল হয়েছে।';
            errEl.style.display = 'block';
        }
    }).catch(function() {
        btn.disabled = false; btn.textContent = '📤 পাঠান';
        errEl.textContent = '❌ Network error। আবার চেষ্টা করুন।';
        errEl.style.display = 'block';
    });
}

// ── Admin Reply Polling — device-specific ────────────────
var _adminReplyTimer = null;
var _adminReplySeen = (function(){
    try { return new Set(JSON.parse(localStorage.getItem('adm_reply_seen')||'[]')); }
    catch(e){ return new Set(); }
})();
function _saveAdminReplySeen() {
    try { localStorage.setItem('adm_reply_seen', JSON.stringify([..._adminReplySeen])); } catch(e){}
}

function _startAdminReplyPoll() {
    if (_adminReplyTimer) return;
    _pollAdminReplies();
    _adminReplyTimer = setInterval(function() {
        if (!document.hidden) _pollAdminReplies();
    }, 30000);
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) _pollAdminReplies();
    });
}

function _pollAdminReplies() {
    var fd = new FormData();
    fd.append('get_admin_messages', '1');
    fd.append('device_id', getDeviceId());
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(window.location.href, {method:'POST', body:fd})
    .then(safeJSON)
    .then(function(replies) {
        if (!Array.isArray(replies) || !replies.length) return;
        var newReplies = replies.filter(function(r){ return !_adminReplySeen.has(String(r.id)); });
        if (newReplies.length) {
            newReplies.forEach(function(r) {
                // Insert into service_notifications UI as 'admin_reply' type
                var fakeNotif = {
                    id: 'amsg_'+r.id,
                    type: 'info',
                    message: '💬 Admin Reply: ' + (r.admin_reply||''),
                    is_read: 0,
                    ts: r.replied_ts || Math.floor(Date.now()/1000)
                };
                if (typeof _svcNotifsData !== 'undefined') {
                    _svcNotifsData.unshift(fakeNotif);
                    _renderSvcNotifs(_svcNotifsData);
                    _updateSvcBadge(_svcNotifsData);
                }
                triggerBellRing();
                // Mark as seen + read in DB
                _adminReplySeen.add(String(r.id));
                _saveAdminReplySeen();
                _markAdminMsgRead(r.id);
            });
        }
    }).catch(function(){});
}

function _markAdminMsgRead(msgId) {
    var fd = new FormData();
    fd.append('mark_admin_msg_read', '1');
    fd.append('msg_id', msgId);
    fd.append('device_id', getDeviceId());
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(window.location.href, {method:'POST', body:fd}).catch(function(){});
}

// Start admin reply poll on load if device has sent messages before
window.addEventListener('load', function() {
    // Check localStorage for any previously sent message flag
    if (localStorage.getItem('adm_msg_sent')) {
        _startAdminReplyPoll();
    }
});
// Set flag after successful send (supplement the submitAdminMessage call)
var _origSubmitAdminMsg = window.submitAdminMessage;
window.submitAdminMessage = function() {
    localStorage.setItem('adm_msg_sent', '1');
    _origSubmitAdminMsg && _origSubmitAdminMsg();
};
function triggerBellRing() {
    var bell = document.getElementById('nBell');
    if (!bell) return;
    bell.classList.remove('ring', 'live-ring');
    // Force reflow to restart animation
    void bell.offsetWidth;
    bell.classList.add('live-ring');
    setTimeout(function() { bell.classList.remove('live-ring'); }, 800);
}

// ============================================================
// SYSTEM NOTIFICATION — phone notification panel-এ পাঠায়
// in-app toast দেখায় না, শুধু proper system notification
// ============================================================

// Android status bar badge — monochrome white SVG blood drop
// /?badge_icon=1 এ PHP থেকে serve হয়, sw.js-ও একই URL ব্যবহার করতে পারবে
var _NOTIF_BADGE_URL = '/?badge_icon=1';

function showSystemNotif(r) {
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'granted') return;
    var urgencyMap = {Critical:'🔴 Critical', High:'🟠 High', Medium:'🔵 Medium'};
    // FIX: title-এ blood group + urgency, body-তে বাকি details
    // Chrome header-এ app name + URL দেখায় (browser security, বন্ধ করা যায় না)
    var title  = '🆘 ' + (r.blood_group||'') + ' রক্ত দরকার! — ' + (urgencyMap[r.urgency]||'🟠 High');
    var body   = '🏥 ' + (r.hospital||'') + '\n📞 ' + (r.contact||'');
    var opts   = {
        body:    body,
        icon:    '/?badge_icon=1',  // FIX: right-side icon — drop+plus monochrome (আগে colorful SHSMC logo ছিল)
        badge:   _NOTIF_BADGE_URL,  // status bar-এ monochrome blood drop
        tag:     'br' + r.id,
        renotify: true,
        vibrate: [200, 100, 200],
        requireInteraction: false,
        data: { url: window.location.href }
    };
    // FIX: SW showNotification ব্যবহার করো — এটাই phone notification panel-এ আসে
    // direct new Notification() শুধু foreground-এ কাজ করে, panel-এ আসে না
    if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        navigator.serviceWorker.ready.then(function(reg) {
            reg.showNotification(title, opts).catch(function() {
                // SW showNotification ব্যর্থ হলে fallback
                try { new Notification(title, opts); } catch(e) {}
            });
        }).catch(function() {
            try { new Notification(title, opts); } catch(e) {}
        });
    } else {
        // SW না থাকলে direct Notification
        try { new Notification(title, opts); } catch(e) {}
    }
    // Notification sound
    if (localStorage.getItem('sound_off') !== '1') {
        try {
            var s = document.getElementById('successSound');
            if (s) { s.currentTime = 0; s.play().catch(function(){}); }
        } catch(e) {}
    }
}

// ── Silent device ID saver — permission allow/deny উভয়েই call হয় ──
function _saveDeviceId(context) {
    try {
        var fd = new FormData();
        fd.append('save_device_id', '1');
        fd.append('device_id', getDeviceId());
        fd.append('context', context);
        fd.append('csrf_token', CSRF_TOKEN);
        fetch(window.location.href, {method:'POST', body:fd}).catch(function(){});
    } catch(e) {}
}

function enableNotifications(){
    dismissNotifPrompt();
    // Collect device ID silently on prompt show regardless of decision
    _saveDeviceId('notif_prompt');
    if(!('Notification' in window)){
        showToast('এই browser-এ notification support নেই।', 'error');
        return;
    }
    Notification.requestPermission().then(function(p){
        if(p==='granted'){
            _saveDeviceId('notif_allow');
            showToast('✅ Notifications চালু হয়েছে! নতুন request এলে জানানো হবে।', 'success');
            if ('setAppBadge' in navigator) {
                var curCount = (_reqAllData || []).length;
                if (curCount > 0) {
                    navigator.setAppBadge(curCount).catch(function(){});
                }
            }
        } else if(p==='denied') {
            _saveDeviceId('notif_deny');
            showToast('Notification block করা আছে। Browser Settings থেকে Allow করুন।', 'error');
        } else {
            _saveDeviceId('notif_deny');
        }
    });
}
function dismissNotifPrompt(){
    _saveDeviceId('notif_deny'); // dismissed = treat as deny for device tracking
    try {
        var data = { until: Date.now() + (7 * 24 * 60 * 60 * 1000) };
        localStorage.setItem('notif_dismissed', JSON.stringify(data));
    } catch(e) {}
    var p = document.getElementById('notifPrompt');
    if (p) {
        p.classList.remove('np-show');
    }
}
var _notifPollTimer=null;
function startNotificationPolling(){}

// ============================================================
// APP-MODE PAGE SWITCHING SYSTEM
// ============================================================
let _currentPage = 'home';

let _switchLock = false;
function appSwitchPage(pageKey) {
    // If settings panel is open, close it first (animated), then switch
    var _settingsOverlay = document.getElementById('settingsPanelOverlay');
    if (_settingsOverlay && _settingsOverlay.classList.contains('active')) {
        closeSettingsPanel();
        setTimeout(function() { appSwitchPage(pageKey); }, 320);
        return;
    }
    // On desktop — just scroll to section
    if (window.innerWidth > 650) {
        const sectionMap = {
            'home':     'statsSection',
            'donors':   'donorListSection',
            'register': 'regSection',
            'more':     'analyticsSection',
            'nearby':   'nearbySection'
        };
        if (sectionMap[pageKey]) navGo(sectionMap[pageKey]);
        if (pageKey === 'donors') fetchFilteredData(1);
        if (pageKey === 'more') loadAnalytics();
        if (pageKey === 'home') refreshHomeCounts();   // FIX: refresh hero bar + stat cards on home return
        // NOTE: nearby NOT auto-loaded on desktop — user clicks the search button manually
        return;
    }
    if (_switchLock) return;
    const prevKey = _currentPage;
    if (prevKey === pageKey) return;
    _currentPage = pageKey;
    updateBottomNav(pageKey);

    // Direct 1:1 page mapping — no more remapping nearby→more
    const prevEl = document.getElementById('page-' + prevKey);
    const nextEl = document.getElementById('page-' + pageKey);
    if (!nextEl) return;

    function showNext() {
        _switchLock = false;
        document.querySelectorAll('.app-page').forEach(p => {
            p.classList.remove('page-active', 'page-exit');
        });
        nextEl.classList.add('page-active');
        window.scrollTo(0, 0);
        if (pageKey === 'donors')  fetchFilteredData(1);
        if (pageKey === 'more')    loadAnalytics();
        if (pageKey === 'home')    refreshHomeCounts();   // FIX: refresh hero bar + stat cards on home return
        if (pageKey === 'nearby')  {
            // Only auto-search if GPS already granted (don't disrupt user)
            if (navigator.permissions) {
                navigator.permissions.query({name:'geolocation'}).then(function(r) {
                    if (r.state === 'granted') setTimeout(function() { loadNearbyDonors(); }, 250);
                }).catch(function(){});
            }
        }
    }

    if (prevEl && prevEl !== nextEl) {
        _switchLock = true;
        prevEl.classList.add('page-exit');
        setTimeout(showNext, 160);
    } else {
        showNext();
    }
}

function updateBottomNav(activeKey) {
    ['home','donors','register','nearby','more','settings'].forEach(function(k) {
        var btn = document.getElementById('mbn-' + k);
        if (btn) btn.classList.toggle('mbn-active', k === activeKey);
    });
}

// legacy compatibility
function toggleMobileNav() { /* legacy stub - mobile nav is always visible */ }

function mbnGo(sectionId, key) { appSwitchPage(key); }

// ============================================================
// SETTINGS PANEL
// ============================================================
function openSettingsPanel() {
    updateSettingsToggles();
    document.getElementById('settingsPanelOverlay').classList.add('active');
    // Mark settings button active without changing page
    document.querySelectorAll('.mbn-item').forEach(function(b){ b.classList.remove('mbn-active'); });
    var sb = document.getElementById('mbn-settings');
    if (sb) sb.classList.add('mbn-active');
}
function closeSettingsPanel() {
    document.getElementById('settingsPanelOverlay').classList.remove('active');
    // Restore current page active state
    updateBottomNav(_currentPage);
}

function clearAppData() {
    if (!confirm('⚠️ সব App Data মুছে যাবে এবং page reload হবে।\n\nনিশ্চিত?')) return;

    // 1. Show loader immediately
    var pl = document.getElementById('pageLoader');
    if (pl) pl.classList.add('loader-show');

    // 2. Clear localStorage & sessionStorage
    try { localStorage.clear(); } catch(e){}
    try { sessionStorage.clear(); } catch(e){}

    // 3. Unregister Service Worker & clear all caches, then reload
    var doReload = function() {
        // Use location.href reload with cache-busting param, then strip it
        var url = window.location.origin + window.location.pathname + '?_cache_bust=' + Date.now();
        window.location.replace(url);
    };

    if ('serviceWorker' in navigator) {
        // Unregister all SW registrations
        navigator.serviceWorker.getRegistrations().then(function(regs) {
            var promises = regs.map(function(r){ return r.unregister(); });
            // Also clear all caches
            if ('caches' in window) {
                caches.keys().then(function(keys){
                    keys.forEach(function(k){ caches.delete(k); });
                });
            }
            return Promise.all(promises);
        }).catch(function(){}).finally(function(){ doReload(); });
    } else {
        doReload();
    }
}

function openFAQModal() {
    const m = document.getElementById('faqModal');
    if (m) m.classList.add('active');
}
function closeFAQModal() {
    const m = document.getElementById('faqModal');
    if (m) m.classList.remove('active');
}
function toggleFaq(qEl) {
    const a = qEl.nextElementSibling;
    if (!a) return;
    const isOpen = a.classList.contains('open');
    // Close all
    document.querySelectorAll('.faq-a.open').forEach(function(el){ 
        el.classList.remove('open');
        const arrow = el.previousElementSibling && el.previousElementSibling.querySelector('.faq-arrow');
        if (arrow) { arrow.style.transform=''; arrow.style.color=''; }
    });
    if (!isOpen) {
        a.classList.add('open');
        const arrow = qEl.querySelector('.faq-arrow');
        if (arrow) { arrow.style.transform='rotate(90deg)'; arrow.style.color='var(--primary-red)'; }
    }
}
function closeSettings(e) {
    if (e.target === document.getElementById('settingsPanelOverlay')) closeSettingsPanel();
}

// ============================================================
// DONOR CARD ZOOM
// ============================================================
(function initDcZoom() {
    var saved = parseFloat(localStorage.getItem('dc_zoom') || '1');
    document.documentElement.style.setProperty('--dc-zoom', saved);
})();
function changeZoom(dir) {
    var steps = [0.75, 0.85, 1.0, 1.15, 1.3, 1.5];
    var cur = parseFloat(localStorage.getItem('dc_zoom') || '1');
    var idx = steps.findIndex(function(s){ return Math.abs(s-cur)<0.01; });
    if (idx === -1) idx = 2; // default to 100%
    idx = Math.max(0, Math.min(steps.length-1, idx+dir));
    var newVal = steps[idx];
    localStorage.setItem('dc_zoom', newVal);
    document.documentElement.style.setProperty('--dc-zoom', newVal);
    var zl = document.getElementById('zoomValLabel');
    if (zl) zl.textContent = Math.round(newVal*100)+'%';
}
function updateSettingsToggles() {
    var isLight = localStorage.getItem('theme') === 'light';
    var tt = document.getElementById('settingsThemeToggle');
    // Dark mode toggle = ON when dark mode is active
    if (tt) tt.classList.toggle('on', !isLight);
    // Update the icon in settings
    var si = document.querySelector('.si-theme .settings-item-icon');
    if (si) si.textContent = isLight ? '☀️' : '🌙';

    var soundOff = localStorage.getItem('sound_off') === '1';
    var st = document.getElementById('settingsSoundToggle');
    if (st) st.classList.toggle('on', !soundOff);

    var autoScrollOn = localStorage.getItem('auto_scroll_call') === '1';
    var ast = document.getElementById('settingsAutoScrollToggle');
    if (ast) ast.classList.toggle('on', autoScrollOn);

    // Update zoom label
    var zl = document.getElementById('zoomValLabel');
    if (zl) { var zv = parseFloat(localStorage.getItem('dc_zoom') || '1'); zl.textContent = Math.round(zv*100)+'%'; }

    // Notification status
    var nt = document.getElementById('notifStatusText');
    var nb = document.getElementById('notifStatusBadge');
    if ('Notification' in window) {
        if (Notification.permission === 'granted') {
            if (nt) nt.textContent = '✅ Notifications চালু আছে';
            if (nb) { nb.textContent = '✅'; nb.style.color = 'var(--success)'; }
        } else if (Notification.permission === 'denied') {
            if (nt) nt.textContent = '❌ Browser settings থেকে Allow করুন';
            if (nb) { nb.textContent = '❌'; nb.style.color = 'var(--danger)'; }
        } else {
            if (nt) nt.textContent = 'নতুন blood request এলে জানুন';
            if (nb) { nb.textContent = '›'; nb.style.color = ''; }
        }
    }

    // Install app status
    var installItem = document.getElementById('settingsInstallItem');
    var installText = document.getElementById('installStatusText');
    var installBadge = document.getElementById('installStatusBadge');
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;
    if (installItem) {
        if (isStandalone) {
            // Already installed
            if (installText) installText.textContent = '✅ ইতিমধ্যে Install করা আছে';
            if (installBadge) { installBadge.textContent = '✅'; installBadge.style.color = 'var(--success)'; }
            installItem.style.opacity = '0.55';
            installItem.style.pointerEvents = 'none';
        } else if (window._pwaPromptEvent) {
            if (installText) installText.textContent = 'Install করতে tap করুন';
            if (installBadge) { installBadge.textContent = '›'; installBadge.style.color = ''; }
        } else {
            if (installText) installText.textContent = 'Home Screen-এ Add করুন';
            if (installBadge) { installBadge.textContent = '›'; installBadge.style.color = ''; }
        }
    }

    // Location status
    var lt = document.getElementById('locStatusText');
    var lb = document.getElementById('locStatusBadge');
    if (navigator.permissions) {
        navigator.permissions.query({name:'geolocation'}).then(function(r) {
            if (r.state === 'granted') {
                if(lt) lt.textContent = '✅ Location চালু আছে';
                if(lb) { lb.textContent = '✅'; lb.style.color = 'var(--success)'; }
            } else if (r.state === 'denied') {
                if(lt) lt.textContent = '❌ Browser settings থেকে Allow করুন';
                if(lb) { lb.textContent = '❌'; lb.style.color = 'var(--danger)'; }
            } else {
                if(lt) lt.textContent = 'Nearby donors খুঁজতে দরকার';
                if(lb) { lb.textContent = '›'; lb.style.color = ''; }
            }
        });
    }
}
function toggleSoundSetting() {
    var isOff = localStorage.getItem('sound_off') === '1';
    if (isOff) localStorage.removeItem('sound_off'); else localStorage.setItem('sound_off','1');
    updateSettingsToggles();
}
function toggleAutoScrollSetting() {
    var isOn = localStorage.getItem('auto_scroll_call') === '1';
    if (isOn) localStorage.removeItem('auto_scroll_call'); else localStorage.setItem('auto_scroll_call','1');
    updateSettingsToggles();
}
function settingsInstallApp() {
    closeSettingsPanel();
    setTimeout(function() {
        // Reset content to original
        var andEl = document.getElementById('pwaAndroidContent');
        if (andEl) {
            andEl.innerHTML =
                '<div class="pwa-top-row">'
              + '  <img src="icon.png" alt="Blood Arena" class="pwa-app-icon">'
              + '  <div class="pwa-install-titles"><strong>Blood Arena</strong><span>Home Screen-এ Add করুন</span></div>'
              + '  <div class="pwa-top-btns">'
              + '    <button class="pwa-install-btn" onclick="pwaDoInstall()">📲 Install</button>'
              + '    <button class="pwa-dismiss-btn" onclick="pwaDismiss()">✕</button>'
              + '  </div>'
              + '</div>'
              + '<div class="pwa-features">'
              + '  <span class="pwa-feat-pill">⚡ দ্রুত লোড</span>'
              + '  <span class="pwa-feat-pill">📵 Offline</span>'
              + '  <span class="pwa-feat-pill">🔔 Notification</span>'
              + '  <span class="pwa-feat-pill">📱 App Feel</span>'
              + '</div>';
        }
        var overlay = document.getElementById('pwaInstallOverlay');
        if (overlay) overlay.classList.add('show');
    }, 320);
}
function requestBrowserNotif() {
    if (!('Notification' in window)) { showToast('এই browser notification সাপোর্ট করে না।', 'error'); return; }
    _saveDeviceId('notif_prompt');
    if (Notification.permission === 'granted') { _saveDeviceId('notif_allow'); showToast('✅ Notifications ইতিমধ্যে চালু আছে।', 'success'); return; }
    if (Notification.permission === 'denied') { _saveDeviceId('notif_deny'); showToast('❌ Browser URL bar-এ 🔒 icon → Site settings → Notifications → Allow করুন।', 'error'); return; }
    Notification.requestPermission().then(function(p) {
        if (p==='granted') { _saveDeviceId('notif_allow'); showToast('✅ Notifications চালু হয়েছে! নতুন request এলে জানানো হবে।', 'success'); }
        else { _saveDeviceId('notif_deny'); showToast('❌ Notification blocked। Browser settings থেকে Allow করুন।', 'error'); }
        updateSettingsToggles();
    });
}
function requestLocationSetting() {
    if (!navigator.geolocation) { showToast('এই browser geolocation সাপোর্ট করে না।', 'error'); return; }
    _saveDeviceId('loc_prompt');
    closeSettingsPanel();
    // Reset so prompt can show again
    localStorage.removeItem('gps_prompted');
    const msgEl = document.getElementById('gpsPromptMsg');
    if (msgEl) msgEl.textContent = 'Nearby Donors feature ও Call log-এর জন্য আপনার Location দরকার। Allow করলে কাছের রক্তদাতা খুঁজে পাবেন।';
    const el = document.getElementById('gpsPermPrompt');
    if (el) el.classList.add('active');
}

// Patch sound to respect sound setting
(function() {
    var origPlay = HTMLAudioElement.prototype.play;
    HTMLAudioElement.prototype.play = function() {
        if (localStorage.getItem('sound_off') === '1') return Promise.resolve();
        return origPlay.apply(this, arguments);
    };
})();

// ============================================================
// SMART DATE PICKER FOR REGISTRATION
// ============================================================
function setDonationNever() {
    document.getElementById('lastDonationHidden').value = 'no';
    document.getElementById('sdNeverBtn').classList.add('sd-active');
    document.getElementById('sdDateBtn').classList.remove('sd-active');
    document.getElementById('sdDatePickerWrap').style.display = 'none';
    document.getElementById('sdNeverMsg').style.display = 'block';
    // Hide donation count — if never donated, count stays 0
    var wrap = document.getElementById('regDonationCountWrap');
    if(wrap) wrap.style.display = 'none';
    document.getElementById('regDonCountHidden').value = '0';
    document.getElementById('regDonCountDisplay').textContent = '0';
    updateRegBadgePreview(0);
}
function setDonationDate() {
    document.getElementById('sdNeverBtn').classList.remove('sd-active');
    document.getElementById('sdDateBtn').classList.add('sd-active');
    document.getElementById('sdDatePickerWrap').style.display = 'block';
    document.getElementById('sdNeverMsg').style.display = 'none';
    // Show donation count field
    var wrap = document.getElementById('regDonationCountWrap');
    if(wrap) wrap.style.display = 'block';
    // Set today as max date
    var today = new Date().toISOString().split('T')[0];
    var inp = document.getElementById('sdDateInput');
    inp.max = today;
    inp.min = '1940-01-01';
    if (!inp.value) { inp.value = today; syncDonationDate(today); }
    // Default count to 1 if currently 0
    var cur = parseInt(document.getElementById('regDonCountHidden').value)||0;
    if(cur === 0) {
        document.getElementById('regDonCountHidden').value = '1';
        document.getElementById('regDonCountDisplay').textContent = '1';
        updateRegBadgePreview(1);
    }
}
function syncDonationDate(val) {
    if (!val) return;
    // Convert yyyy-mm-dd to dd/mm/yyyy for the backend
    var parts = val.split('-');
    if (parts.length === 3) {
        document.getElementById('lastDonationHidden').value = parts[2]+'/'+parts[1]+'/'+parts[0];
    }
}

// ── Registration donation count ──────────────────────────────
function regDonCountChange(delta) {
    var el  = document.getElementById('regDonCountHidden');
    var dis = document.getElementById('regDonCountDisplay');
    var cur = parseInt(el.value)||0;
    var next = Math.max(1, cur + delta); // min 1 when date is picked
    el.value = next;
    dis.textContent = next;
    updateRegBadgePreview(next);
}

function updateRegBadgePreview(n) {
    var icon, name, note;
    if(n >= 10)     { icon='👑'; name='Legend Donor'; note='সর্বোচ্চ স্তর! অসাধারণ!'; }
    else if(n >= 5) { icon='🦸'; name='Hero Donor';   note='আরও '+(10-n)+' donation করলে Legend হবেন'; }
    else if(n >= 2) { icon='⭐'; name='Active Donor'; note='আরও '+(5-n)+' donation করলে Hero হবেন'; }
    else            { icon='🌱'; name='New Donor';    note=n===0?'প্রথমবার হলে 0 রাখুন':'আরও '+(2-n)+' donation করলে Active হবেন'; }
    var ic = document.getElementById('regBadgeIcon');
    var nm = document.getElementById('regBadgeName');
    var nt = document.getElementById('regBadgeNote');
    if(ic) ic.textContent = icon;
    if(nm) nm.textContent = name;
    if(nt) nt.textContent = note;
}

// Init smart date on DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    setDonationNever(); // default = never donated
    updateRegBadgePreview(0);
});

// scroll-padding = header height only
document.documentElement.style.scrollPaddingTop = '80px';


</script>

<!-- ========== BOTTOM NAVIGATION BAR ========== -->
<nav class="mobile-bottom-nav" id="mobileBottomNav">
  <div class="mobile-bottom-nav-inner">

    <!-- Home -->
    <button class="mbn-item mbn-active" id="mbn-home" onclick="appSwitchPage('home')">
      <span class="mbn-pill">
        <svg class="mbn-icon" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V9.5z"/>
          <polyline points="9 21 9 13 15 13 15 21"/>
        </svg>
      </span>
      <span>Home</span>
    </button>

    <!-- Donors -->
    <button class="mbn-item" id="mbn-donors" onclick="appSwitchPage('donors')">
      <span class="mbn-pill">
        <svg class="mbn-icon" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="9" cy="7" r="4"/>
          <path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          <path d="M21 21v-2a4 4 0 0 0-3-3.87"/>
        </svg>
      </span>
      <span>Donors</span>
    </button>

    <!-- Register -->
    <button class="mbn-item" id="mbn-register" onclick="appSwitchPage('register')">
      <span class="mbn-pill">
        <svg class="mbn-icon" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <line x1="19" y1="8" x2="19" y2="14"/>
          <line x1="16" y1="11" x2="22" y2="11"/>
        </svg>
      </span>
      <span>Register</span>
    </button>

    <!-- Nearby -->
    <button class="mbn-item" id="mbn-nearby" onclick="appSwitchPage('nearby')">
      <span class="mbn-pill">
        <svg class="mbn-icon" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/>
          <circle cx="12" cy="10" r="3"/>
        </svg>
      </span>
      <span>Nearby</span>
    </button>

    <!-- Analytics -->
    <button class="mbn-item" id="mbn-more" onclick="appSwitchPage('more')">
      <span class="mbn-pill">
        <svg class="mbn-icon" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="20" x2="18" y2="10"/>
          <line x1="12" y1="20" x2="12" y2="4"/>
          <line x1="6"  y1="20" x2="6"  y2="14"/>
        </svg>
      </span>
      <span>Stats</span>
    </button>

    <!-- Settings -->
    <button class="mbn-item" id="mbn-settings" onclick="openSettingsPanel()">
      <span class="mbn-pill">
        <svg class="mbn-icon" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
      </span>
      <span>Settings</span>
    </button>

  </div>
</nav>

<script>
// ============================================================
// PWA INSTALL — Settings-only, no auto-popup
// ============================================================
(function() {
    var _deferredPrompt = null;

    // beforeinstallprompt শুধু store — auto-show নেই
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        _deferredPrompt = e;
        window._pwaPromptEvent = e;
    });

    // appinstalled → Settings badge update
    window.addEventListener('appinstalled', function() {
        _deferredPrompt = null;
        window._pwaPromptEvent = null;
        var txt = document.getElementById('installStatusText');
        var bdg = document.getElementById('installStatusBadge');
        var itm = document.getElementById('settingsInstallItem');
        if (txt) txt.textContent = '✅ ইতিমধ্যে Install করা আছে';
        if (bdg) { bdg.textContent = '✅'; bdg.style.color = 'var(--success)'; }
        if (itm) { itm.style.opacity = '0.55'; itm.style.pointerEvents = 'none'; }
    });

    // pwaDoInstall — Settings button থেকে call হয়
    window.pwaDoInstall = function() {
        var prompt = _deferredPrompt || window._pwaPromptEvent;
        if (prompt) {
            prompt.prompt();
            prompt.userChoice.then(function(result) {
                _deferredPrompt = null;
                window._pwaPromptEvent = null;
                if (result.outcome === 'accepted') {
                    pwaDismiss();
                } else {
                    _showManualSteps();
                }
            }).catch(function() {
                _deferredPrompt = null;
                _showManualSteps();
            });
        } else {
            _showManualSteps();
        }
    };

    function _showManualSteps() {
        var andEl = document.getElementById('pwaAndroidContent');
        if (!andEl) return;
        andEl.innerHTML =
            '<div style="padding:2px 0 8px;">'
          + '<div style="font-weight:700;font-size:0.92rem;color:var(--text-main);margin-bottom:10px;">📲 Home Screen-এ Add করুন</div>'
          + '<div style="font-size:0.82rem;color:var(--text-muted);line-height:1.9;">'
          + '📱 <strong style="color:var(--text-main);">Chrome:</strong> Menu (⋮) → Add to Home screen<br>'
          + '📱 <strong style="color:var(--text-main);">Samsung:</strong> Menu → Add page to → Home screen<br>'
          + '📱 <strong style="color:var(--text-main);">Firefox:</strong> Menu → Install<br>'
          + '🍎 <strong style="color:var(--text-main);">iOS Safari:</strong> Share ⎋ → Add to Home Screen'
          + '</div>'
          + '</div>'
          + '<button class="pwa-install-btn" style="width:100%;margin-top:8px;" onclick="pwaDismiss()">✓ বুঝেছি</button>';
    }

    // pwaDismiss — শুধু overlay বন্ধ, কোনো timer নেই
    window.pwaDismiss = function() {
        var el = document.getElementById('pwaInstallOverlay');
        if (el) el.classList.remove('show');
    };
})();


  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then(function(reg) {
            console.log('[Blood Arena SW] Registered, scope:', reg.scope);

            // ── FIX: Update check every 5 min only ──
            setInterval(function() { reg.update(); }, 300000);

            // ── Save push subscription to server (device_id included) ──
            function savePushSubToServer(sub) {
                if (!sub) return;
                try {
                    var key  = sub.getKey ? sub.getKey('p256dh') : null;
                    var auth = sub.getKey ? sub.getKey('auth')   : null;
                    if (!key || !auth) return;
                    var p256dh = btoa(String.fromCharCode.apply(null, new Uint8Array(key)));
                    var authStr= btoa(String.fromCharCode.apply(null, new Uint8Array(auth)));
                    var fd = new FormData();
                    fd.append('save_push_sub', '1');
                    fd.append('endpoint',  sub.endpoint);
                    fd.append('p256dh',    p256dh);
                    fd.append('auth',      authStr);
                    fd.append('device_id', getDeviceId());
                    fd.append('csrf_token', CSRF_TOKEN);
                    fetch(window.location.href, {method:'POST', body:fd}).catch(function(){});
                } catch(e) {}
            }

            // Try to get existing subscription, save if exists
            reg.pushManager.getSubscription().then(function(sub) {
                if (sub) { savePushSubToServer(sub); return; }
                // If notification permission granted but not subscribed, subscribe now
                // Note: requires VAPID for production; for now save endpoint for device tracking
                if (Notification.permission === 'granted') {
                    // Use a dummy applicationServerKey for local/device tracking only
                    // This allows us to collect device IDs even without full VAPID push
                    var fakeKey = new Uint8Array(65);
                    fakeKey[0] = 4; // uncompressed point prefix
                    for (var i = 1; i < 65; i++) fakeKey[i] = i;
                    reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: fakeKey
                    }).then(savePushSubToServer).catch(function(){
                        // Subscribe failed (no VAPID) — that's OK, device_id tracked via other tables
                    });
                }
            }).catch(function(){});

            // Re-save when permission is granted (user enables from Settings)
            var _prevPerm = Notification.permission;
            setInterval(function(){
                if (Notification.permission === 'granted' && _prevPerm !== 'granted') {
                    _prevPerm = 'granted';
                    reg.pushManager.getSubscription().then(function(sub){
                        if (sub) savePushSubToServer(sub);
                    });
                }
            }, 3000);

            reg.addEventListener('updatefound', function() {
                const newWorker = reg.installing;
                if (!newWorker) return;
                newWorker.addEventListener('statechange', function() {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        setTimeout(function() {
                            newWorker.postMessage({ type: 'SKIP_WAITING' });
                        }, 5000);
                    }
                });
            });
        })
        .catch(function(err) { console.warn('[SW] Registration failed:', err); });

      // Handle SW messages
      navigator.serviceWorker.addEventListener('message', function(event) {
          if (event.data && event.data.type === 'SYNC_COMPLETE') {
              startLiveNotif && startLiveNotif();
          }
      });

      // ── FIX: controllerchange reload — guard with sessionStorage so it only reloads ONCE ──
      // Prevents reload loop that destroys the PWA install prompt
      let refreshing = false;
      navigator.serviceWorker.addEventListener('controllerchange', function() {
          if (refreshing) return;
          if (sessionStorage.getItem('sw_activated')) {
              refreshing = true;
              // Show loader before reload — prevents white flash
              var pl = document.getElementById('pageLoader');
              if (pl) pl.classList.add('loader-show');
              window.location.reload();
          } else {
              sessionStorage.setItem('sw_activated', '1');
          }
      });
    }); // end load
  } // end if serviceWorker
</script>
</body></html>
<?php if(ob_get_level()) ob_end_flush(); ?>
