<?php
/**
 * Blood Solution — Hardened Admin Panel  v3.3.0
 * NEW: Call Log Delete | Bulk Delete | Token Manager | IP Whitelist | Password Change
 */

ini_set('display_errors', 0);
error_reporting(0);
ob_start();

// ── 1. SECURITY HEADERS ─────────────────────────────────
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:;");
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
         || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
if($isHttps) header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

// ── 2. CONFIG ────────────────────────────────────────────
$config_file = __DIR__ . '/admin_config.php';
if(!file_exists($config_file)){ ob_end_clean(); header('Location: admin_setup.php'); exit(); }
include_once $config_file;

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS',    900);
define('SESSION_IDLE_LIMIT', 1800);
define('SESSION_HARD_LIMIT', 14400);

// ── 3. SESSION ───────────────────────────────────────────
$domain = strtok($_SERVER['HTTP_HOST'] ?? 'localhost', ':');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>$domain,'secure'=>$isHttps,'httponly'=>true,'samesite'=>'Strict']);
ini_set('session.use_strict_mode',1); ini_set('session.use_only_cookies',1);
ini_set('session.cookie_httponly',1); ini_set('session.use_trans_sid',0);
ini_set('session.sid_length',48);     ini_set('session.sid_bits_per_character',6);
session_start();

// ── 4. DB ────────────────────────────────────────────────
$conn=null; $db_error='';
try {
    mysqli_report(MYSQLI_REPORT_OFF);
    if(file_exists(__DIR__.'/db.php')){
        include_once __DIR__.'/db.php';
        if(isset($conn)&&$conn instanceof mysqli){ $conn->set_charset("utf8mb4"); }
        else { $db_error='DB connection failed.'; $conn=null; }
    } else { $db_error='db.php not found.'; }
} catch(Throwable $e){ $db_error='DB error.'; $conn=null; }

// ── 5. HELPERS ───────────────────────────────────────────
function esc($v){ return htmlspecialchars($v??'',ENT_QUOTES|ENT_HTML5,'UTF-8'); }
function dbq($c,$s){ if(!$c) return null; $r=$c->query($s); return $r?:null; }

function ensureConn(&$conn, &$db_error){
    if($conn instanceof mysqli){ if(@$conn->ping()) return true; $conn=null; }
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        if(file_exists(__DIR__.'/db.php')){
            $tmp=null;
            (function() use (&$tmp){ global $conn; include __DIR__.'/db.php'; $tmp=$conn; })();
            if($tmp instanceof mysqli){ $tmp->set_charset("utf8mb4"); $conn=$tmp; return true; }
        }
    } catch(Throwable $e){}
    $db_error='DB reconnect failed.';
    return false;
}
function getIP(){
    foreach(['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k){
        if(!empty($_SERVER[$k])){ $ip=trim(explode(',',$_SERVER[$k])[0]); if(filter_var($ip,FILTER_VALIDATE_IP)) return $ip; }
    }
    return 'unknown';
}
function sessionFingerprint(){ return hash('sha256', getIP().'|'.($_SERVER['HTTP_USER_AGENT']??'')); }

function generateSecretCode($conn){
    $attempts=0;
    do {
        $code='SHSMC-'.strtoupper(substr(bin2hex(random_bytes(6)),0,12));
        $check=$conn->prepare("SELECT id FROM donors WHERE secret_code=?");
        $check->bind_param("s",$code); $check->execute();
        $exists=$check->get_result()->num_rows>0; $check->close(); $attempts++;
    } while($exists && $attempts<20);
    return $code;
}

function generateToken(){
    return 'BST-'.strtoupper(bin2hex(random_bytes(16)));
}

// ── 6. AUDIT LOG ─────────────────────────────────────────
function ensureAuditTable($conn){
    if(!$conn) return;
    $conn->query("CREATE TABLE IF NOT EXISTS `admin_audit_log`(`id` INT AUTO_INCREMENT PRIMARY KEY,`event` VARCHAR(80) NOT NULL,`ip` VARCHAR(50) NOT NULL,`user_agent` VARCHAR(300) DEFAULT NULL,`detail` VARCHAR(300) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function auditLog($conn,$event,$detail=''){
    if(!$conn) return;
    ensureAuditTable($conn);
    $ip=mysqli_real_escape_string($conn,getIP());
    $ua=mysqli_real_escape_string($conn,mb_substr($_SERVER['HTTP_USER_AGENT']??'',0,300));
    $ev=mysqli_real_escape_string($conn,$event);
    $dt=mysqli_real_escape_string($conn,$detail);
    $conn->query("INSERT INTO admin_audit_log (event,ip,user_agent,detail) VALUES ('$ev','$ip','$ua','$dt')");
}

// ── 7. BRUTE-FORCE ───────────────────────────────────────
function getBruteKey(){ return 'bf_'.hash('sha256',getIP()); }
function isLockedOut(){
    $k=getBruteKey(); if(!isset($_SESSION[$k])) return false;
    $b=$_SESSION[$k];
    if($b['count']>=MAX_LOGIN_ATTEMPTS){ if(time()-$b['since']<LOCKOUT_SECONDS) return true; unset($_SESSION[$k]); }
    return false;
}
function recordFailedAttempt(){
    $k=getBruteKey(); if(!isset($_SESSION[$k])) $_SESSION[$k]=['count'=>0,'since'=>time()];
    if(time()-$_SESSION[$k]['since']>=LOCKOUT_SECONDS) $_SESSION[$k]=['count'=>0,'since'=>time()];
    $_SESSION[$k]['count']++;
}
function clearFailedAttempts(){ unset($_SESSION[getBruteKey()]); }
function attemptsLeft(){ $k=getBruteKey(); if(!isset($_SESSION[$k])) return MAX_LOGIN_ATTEMPTS; return max(0,MAX_LOGIN_ATTEMPTS-$_SESSION[$k]['count']); }
function lockoutSecondsLeft(){ $k=getBruteKey(); if(!isset($_SESSION[$k])) return 0; return max(0,LOCKOUT_SECONDS-(time()-$_SESSION[$k]['since'])); }

// ── 8. CSRF ──────────────────────────────────────────────
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
function checkCSRF(){
    if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); die(json_encode(['ok'=>false,'msg'=>'Method not allowed.'])); }
    $token=$_POST['csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??'');
    if(!hash_equals($_SESSION['csrf']??'',$token)){ http_response_code(403); die(json_encode(['ok'=>false,'msg'=>'CSRF failed.'])); }
}

// ── 9. SESSION VALIDATION ────────────────────────────────
function validateSession(){
    if(empty($_SESSION['adm'])) return false;
    if(isset($_SESSION['fp'])&&$_SESSION['fp']!==sessionFingerprint()){ session_destroy(); return false; }
    if(isset($_SESSION['last_active'])&&(time()-$_SESSION['last_active'])>SESSION_IDLE_LIMIT){ session_destroy(); return false; }
    if(isset($_SESSION['adm_start'])&&(time()-$_SESSION['adm_start'])>SESSION_HARD_LIMIT){ session_destroy(); return false; }
    $_SESSION['last_active']=time(); return true;
}

// ── 10. IP WHITELIST CHECK ───────────────────────────────
function ensureAdminTables($conn){
    if(!$conn) return;
    $conn->query("CREATE TABLE IF NOT EXISTS `admin_settings`(`setting_key` VARCHAR(80) PRIMARY KEY,`setting_value` TEXT DEFAULT NULL,`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS `ip_whitelist`(`id` INT AUTO_INCREMENT PRIMARY KEY,`ip` VARCHAR(50) NOT NULL,`label` VARCHAR(100) DEFAULT '',`is_active` TINYINT(1) DEFAULT 1,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS `api_tokens`(`id` INT AUTO_INCREMENT PRIMARY KEY,`token_name` VARCHAR(100) NOT NULL,`token_value` VARCHAR(80) NOT NULL,`is_active` TINYINT(1) DEFAULT 1,`last_used` TIMESTAMP NULL DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS `admin_users`(`id` INT AUTO_INCREMENT PRIMARY KEY,`username` VARCHAR(60) NOT NULL UNIQUE,`pass_hash` VARCHAR(255) NOT NULL,`role` ENUM('super_admin','moderator') NOT NULL DEFAULT 'moderator',`is_active` TINYINT(1) DEFAULT 1,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS `admin_messages`(`id` INT AUTO_INCREMENT PRIMARY KEY,`sender_name` VARCHAR(100) NOT NULL,`sender_phone` VARCHAR(20) NOT NULL,`message` TEXT NOT NULL,`device_id` VARCHAR(100) NOT NULL,`is_read` TINYINT DEFAULT 0,`admin_reply` TEXT DEFAULT NULL,`replied_at` TIMESTAMP NULL DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Default settings
    $conn->query("INSERT IGNORE INTO admin_settings (setting_key,setting_value) VALUES ('ip_whitelist_enabled','0')");
}

function checkIpWhitelist($conn){
    if(!$conn) return; // DB unavailable — allow through
    ensureAdminTables($conn);
    $res=$conn->query("SELECT setting_value FROM admin_settings WHERE setting_key='ip_whitelist_enabled' LIMIT 1");
    if(!$res) return;
    $row=$res->fetch_assoc();
    if(($row['setting_value']??'0')!=='1') return; // Not enabled
    $current_ip=getIP();
    $esc_ip=mysqli_real_escape_string($conn,$current_ip);
    $check=$conn->query("SELECT id FROM ip_whitelist WHERE ip='$esc_ip' AND is_active=1 LIMIT 1");
    if(!$check||$check->num_rows===0){
        ob_end_clean();
        http_response_code(403);
        die('<!DOCTYPE html><html><head><title>403 Access Denied</title><style>*{margin:0;padding:0;box-sizing:border-box;}body{background:#0f1115;color:#ef4444;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;flex-direction:column;gap:14px;text-align:center;padding:20px;}.box{background:#1a1d24;border:1px solid rgba(239,68,68,.25);border-radius:16px;padding:36px;max-width:400px;}.icon{font-size:3rem;margin-bottom:12px;}.ip{font-family:monospace;font-size:.85em;color:#6b7280;margin-top:8px;background:#111;padding:4px 12px;border-radius:8px;display:inline-block;}</style></head><body><div class="box"><div class="icon">🚫</div><h2>Access Denied</h2><p style="color:#9ca3af;font-size:.9em;margin-top:8px;">আপনার IP address এই panel access করার অনুমতি নেই।</p><div class="ip">IP: '.htmlspecialchars($current_ip).'</div></div></body></html>');
    }
}

// Run IP whitelist check before anything else (except AJAX if already in session)
checkIpWhitelist($conn);

// ── 11. LOGOUT ───────────────────────────────────────────
if(isset($_GET['logout'])||isset($_POST['logout'])){
    if(isset($_POST['logout'])){ $tok=$_POST['csrf']??''; if(!hash_equals($_SESSION['csrf']??'',$tok)){ http_response_code(403); die('Forbidden'); } }
    auditLog($conn,'LOGOUT');
    session_unset(); session_destroy();
    header('Location: admin.php'); exit();
}

// ── 12. LOGIN ────────────────────────────────────────────
$login_error=''; $locked=isLockedOut(); $lockout_left=$locked?lockoutSecondsLeft():0;
if(!$locked && isset($_POST['admin_login'])){
    checkCSRF();
    if(!empty($_POST['website'])){ auditLog($conn,'BOT_TRAP','honeypot'); sleep(3); die(''); }
    $pass_input=$_POST['pass']??'';
    $uname_input=trim($_POST['username']??'');

    $logged_as_role = null;

    // ── Super admin login (legacy — no username, uses ADMIN_HASH from config)
    if(empty($uname_input)){
        if(defined('ADMIN_HASH')&&password_verify($pass_input,ADMIN_HASH)){
            $logged_as_role='super_admin';
        }
    }

    // ── Moderator / admin_users table login
    if($logged_as_role===null && $conn && !empty($uname_input)){
        if($conn) ensureAdminTables($conn);
        $esc_u=mysqli_real_escape_string($conn,$uname_input);
        $ur=$conn->query("SELECT id,pass_hash,role,is_active FROM admin_users WHERE username='$esc_u' LIMIT 1");
        $urow=$ur?$ur->fetch_assoc():null;
        if($urow && (int)$urow['is_active']===1 && password_verify($pass_input,$urow['pass_hash'])){
            $logged_as_role=$urow['role'];
        }
    }

    // ── Also allow super_admin via username field (admin_users table)
    if($logged_as_role===null && $conn && !empty($uname_input)){
        // already checked above — no-op
    }

    if($logged_as_role !== null){
        clearFailedAttempts(); session_regenerate_id(true);
        $_SESSION['adm']=true; $_SESSION['adm_start']=time(); $_SESSION['last_active']=time();
        $_SESSION['fp']=sessionFingerprint(); $_SESSION['csrf']=bin2hex(random_bytes(32));
        $_SESSION['adm_role']=$logged_as_role;
        auditLog($conn,'LOGIN_SUCCESS',"role:$logged_as_role uname:$uname_input");
        header('Location: admin.php'); exit();
    } else {
        recordFailedAttempt(); auditLog($conn,'LOGIN_FAIL','attempts_left:'.attemptsLeft().' uname:'.$uname_input);
        $left=attemptsLeft();
        $login_error=$left===0?'🔒 আপনাকে '.ceil(LOCKOUT_SECONDS/60).' মিনিটের জন্য block করা হয়েছে!':'❌ Wrong credentials! আর '.$left.' টি সুযোগ বাকি।';
        if($left===0) $locked=true;
        sleep(1);
    }
}

$logged_in=validateSession();
// Role detection — default: super_admin for legacy sessions (no role set)
$adm_role     = $_SESSION['adm_role'] ?? 'super_admin';
$is_super     = ($adm_role === 'super_admin');
$is_moderator = ($adm_role === 'moderator');

// Helper: block moderators from destructive actions
function requireSuperAdmin(){
    global $is_super;
    if(!$is_super){ echo json_encode(['ok'=>false,'msg'=>'🚫 Super Admin only.']); exit(); }
}

if($logged_in&&!isset($_POST['act'])&&!isset($_POST['admin_login'])){
    if(empty($_SERVER['HTTP_X_CSRF_TOKEN'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
}

// ── 13. AJAX ACTIONS ─────────────────────────────────────
if($logged_in && isset($_POST['act'])){
    header('Content-Type: application/json; charset=utf-8');
    ob_clean();
    checkCSRF();
    ensureConn($conn, $db_error);

    $act=$_POST['act']; $id=(int)($_POST['id']??0);
    $allowed=[
        'del_donor','del_req','fulfill_req','del_report','del_call',
        'del_multiple',
        'reset_secret','reveal_secret','adv_search','edit_donor','get_donor',
        'add_token','del_token','toggle_token',
        'add_ip','del_ip','toggle_ip_whitelist',
        'change_password',
        'get_settings',
        'send_notif_bulk','send_notif_donor','send_notif_selected_donors','run_auto_reminder',
        'save_schedule','delete_schedule','get_schedules','run_due_schedules','toggle_schedule',
        'get_secret_requests','resolve_secret_request','delete_secret_request','delete_all_processed_secret_requests',
        'get_inbox','reply_inbox_msg','mark_inbox_read',
        'del_inbox_msg','clear_inbox',
        'add_moderator','del_moderator','list_moderators',
        'get_admin_poll'
    ];
    if(!in_array($act,$allowed,true)){ echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit(); }

    // ── Role-based blocks: moderator CANNOT do these ─────
    $super_only_acts = [
        'del_donor','del_req','del_report','del_call','del_multiple',
        'del_token','del_ip',
        'change_password','toggle_ip_whitelist',
        'add_token','add_ip',
        'reply_inbox_msg',  // moderator cannot reply messages
        'add_moderator','del_moderator'
    ];
    if($is_moderator && in_array($act,$super_only_acts,true)){
        echo json_encode(['ok'=>false,'msg'=>'🚫 এই কাজটি শুধুমাত্র Super Admin করতে পারবে।']); exit();
    }

    // ── Get donor for edit ───────────────────────────────
    if($act==='get_donor' && $conn && $id>0){
        $stmt=$conn->prepare("SELECT id,name,phone,blood_group,location,last_donation,willing_to_donate,total_donations,reg_geo FROM donors WHERE id=?");
        $stmt->bind_param("i",$id); $stmt->execute();
        $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if($r){
            $ld=$r['last_donation']??'no';
            $r['last_donation_fmt']=($ld==='no'||empty($ld)||$ld==='0000-00-00')?'no':date('d/m/Y',strtotime($ld));
            $geo=$r['reg_geo']??''; $r['geo_lat']=''; $r['geo_lng']='';
            if(preg_match('/Lat:\s*([\-0-9.]+),\s*Lon:\s*([\-0-9.]+)/',$geo,$gm)){ $r['geo_lat']=$gm[1]; $r['geo_lng']=$gm[2]; }
            unset($r['reg_geo']);
            echo json_encode(['ok'=>true,'donor'=>$r]);
        } else { echo json_encode(['ok'=>false,'msg'=>'Donor not found']); }
        exit();
    }

    // ── Edit donor ───────────────────────────────────────
    if($act==='edit_donor' && $conn && $id>0){
        $name=trim($_POST['name']??''); $phone=trim($_POST['phone']??'');
        $blood_group=trim($_POST['blood_group']??''); $location=trim($_POST['location']??'');
        $last_raw=trim($_POST['last_donation']??'no'); $willing=trim($_POST['willing_to_donate']??'yes');
        $total_don=max(0,(int)($_POST['total_donations']??0)); $reg_geo_new=trim($_POST['reg_geo']??'');
        if(!$name||!$phone||!$location){ echo json_encode(['ok'=>false,'msg'=>'নাম, ফোন ও লোকেশন দিতে হবে।']); exit(); }
        if(!preg_match('/^[\p{Bengali}a-zA-Z\s\.]+$/u',$name)){ echo json_encode(['ok'=>false,'msg'=>'নামে অবৈধ অক্ষর।']); exit(); }
        if(!in_array($blood_group,['A+','A-','B+','B-','AB+','AB-','O+','O-'],true)){ echo json_encode(['ok'=>false,'msg'=>'Invalid blood group.']); exit(); }
        if(!in_array($willing,['yes','no'],true)) $willing='yes';
        $last_to_save='no';
        if(strtolower($last_raw)!=='no'&&!empty($last_raw)){
            $d=DateTime::createFromFormat('d/m/Y',$last_raw);
            if(!$d||$d->format('d/m/Y')!==$last_raw){ echo json_encode(['ok'=>false,'msg'=>'Date format ভুল। dd/mm/yyyy ব্যবহার করুন।']); exit(); }
            $fmt=$d->format('Y-m-d');
            if($fmt>date('Y-m-d')||(int)$d->format('Y')<1940){ echo json_encode(['ok'=>false,'msg'=>'Invalid date.']); exit(); }
            $last_to_save=$fmt;
        }
        $badge_level=$total_don>=10?'Legend':($total_don>=5?'Hero':($total_don>=2?'Active':'New'));
        if(!empty($reg_geo_new)){
            $stmt=$conn->prepare("UPDATE donors SET name=?,phone=?,blood_group=?,location=?,last_donation=?,willing_to_donate=?,total_donations=?,badge_level=?,reg_geo=? WHERE id=?");
            $stmt->bind_param("sssssssssi",$name,$phone,$blood_group,$location,$last_to_save,$willing,$total_don,$badge_level,$reg_geo_new,$id);
        } else {
            $stmt=$conn->prepare("UPDATE donors SET name=?,phone=?,blood_group=?,location=?,last_donation=?,willing_to_donate=?,total_donations=?,badge_level=? WHERE id=?");
            $stmt->bind_param("ssssssssi",$name,$phone,$blood_group,$location,$last_to_save,$willing,$total_don,$badge_level,$id);
        }
        if($stmt->execute()){ auditLog($conn,'EDIT_DONOR',"id:$id name:$name"); echo json_encode(['ok'=>true,'msg'=>'✅ Donor updated!']); }
        else { echo json_encode(['ok'=>false,'msg'=>'Update failed: '.($conn->error??'')]); }
        $stmt->close(); exit();
    }

    // ── Advanced Search ──────────────────────────────────
    if($act==='adv_search' && $conn){
        $name=trim($_POST['s_name']??''); $phone=trim($_POST['s_phone']??'');
        $group=trim($_POST['s_group']??'All'); $loc=trim($_POST['s_loc']??'');
        $status=trim($_POST['s_status']??'All'); $badge=trim($_POST['s_badge']??'All');
        $from=trim($_POST['s_from']??''); $to=trim($_POST['s_to']??'');
        $vg=["A+","A-","B+","B-","AB+","AB-","O+","O-","All"]; if(!in_array($group,$vg,true)) $group='All';
        $vb=["New","Active","Hero","Legend","All"]; if(!in_array($badge,$vb,true)) $badge='All';
        $vs=["All","Available","Not Available","Not Willing"]; if(!in_array($status,$vs,true)) $status='All';
        $parts=[]; $params=[]; $types='';
        if($name!==''){     $parts[]="name LIKE ?";         $params[]="%$name%"; $types.='s'; }
        if($phone!==''){    $parts[]="phone LIKE ?";        $params[]="%$phone%"; $types.='s'; }
        if($group!=='All'){ $parts[]="blood_group=?";       $params[]=$group; $types.='s'; }
        if($loc!==''){      $parts[]="location LIKE ?";     $params[]="%$loc%"; $types.='s'; }
        if($badge!=='All'){ $parts[]="badge_level=?";       $params[]=$badge; $types.='s'; }
        if($from!==''){     $parts[]="DATE(created_at)>=?"; $params[]=$from; $types.='s'; }
        if($to!==''){       $parts[]="DATE(created_at)<=?"; $params[]=$to; $types.='s'; }
        if($status==='Available')     $parts[]="(willing_to_donate='yes' AND (last_donation='no' OR last_donation='' OR last_donation='0000-00-00' OR DATEDIFF(CURDATE(),last_donation)>=120))";
        elseif($status==='Not Willing') $parts[]="willing_to_donate='no'";
        elseif($status==='Not Available') $parts[]="(willing_to_donate='yes' AND last_donation!='no' AND last_donation!='' AND last_donation!='0000-00-00' AND DATEDIFF(CURDATE(),last_donation)<120)";
        $where=count($parts)?'WHERE '.implode(' AND ',$parts):'';
        $sql="SELECT id,name,blood_group,phone,location,last_donation,willing_to_donate,badge_level,total_donations,secret_code,created_at FROM donors $where ORDER BY id DESC LIMIT 200";
        $stmt=$conn->prepare($sql);
        if($types) $stmt->bind_param($types,...$params);
        $stmt->execute(); $res=$stmt->get_result();
        $rows=[];
        while($row=$res->fetch_assoc()){
            $ld=$row['last_donation']??'no'; $w=$row['willing_to_donate']??'yes';
            if($w==='no') $st='Not Willing';
            elseif($ld==='no'||empty($ld)||$ld==='0000-00-00'||(strtotime($ld)&&(time()-strtotime($ld))/86400>=120)) $st='Available';
            else $st='Not Available';
            $row['_status']=$st; $row['secret_code']='';
            $rows[]=$row;
        }
        $stmt->close();
        auditLog($conn,'ADV_SEARCH',"name:$name grp:$group st:$status");
        echo json_encode(['ok'=>true,'rows'=>$rows,'count'=>count($rows)]); exit();
    }

    // ── Reset Secret Code ────────────────────────────────
    if($act==='reset_secret' && $conn && $id>0){
        $newCode=generateSecretCode($conn);
        $stmt=$conn->prepare("UPDATE donors SET secret_code=? WHERE id=?");
        $stmt->bind_param("si",$newCode,$id); $stmt->execute(); $stmt->close();
        // Get donor phone for auto-notify
        $phoneRow=$conn->query("SELECT phone FROM donors WHERE id=$id");
        $dPhone=$phoneRow?$phoneRow->fetch_assoc()['phone']??'':'';
        $autoNotified=false;
        @$conn->query("CREATE TABLE IF NOT EXISTS \(\ INT AUTO_INCREMENT PRIMARY KEY,\ VARCHAR(100) NOT NULL,\ VARCHAR(30) NOT NULL,\ TEXT NOT NULL,\ TINYINT DEFAULT 0,\ TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @$conn->query("CREATE TABLE IF NOT EXISTS \(\ INT AUTO_INCREMENT PRIMARY KEY,\ VARCHAR(20) NOT NULL,\ VARCHAR(10) NOT NULL,\ VARCHAR(100) NOT NULL,\ VARCHAR(50) DEFAULT NULL,\ VARCHAR(20) DEFAULT 'pending',\ VARCHAR(500) DEFAULT '',\ TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if($dPhone){
            $pendQ=$conn->prepare("SELECT id,device_id FROM security_code_requests WHERE donor_number=? AND status='pending' LIMIT 1");
            $pendQ->bind_param("s",$dPhone); $pendQ->execute();
            $pendRow=$pendQ->get_result()->fetch_assoc(); $pendQ->close();
            if($pendRow){
                $msg="✅ আপনার নতুন Secret Code: ".$newCode." — এটি সংরক্ষণ করুন।";
                $ntype='secret_code_ready';
                $nsmt=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
                $nsmt->bind_param("sss",$pendRow['device_id'],$ntype,$msg); $nsmt->execute(); $nsmt->close();
                $doneSt='approved'; $doneNote='Auto-resolved via Secret Code Manager';
                $doneQ=$conn->prepare("UPDATE security_code_requests SET status=?,admin_note=? WHERE id=?");
                $doneQ->bind_param("ssi",$doneSt,$doneNote,$pendRow['id']); $doneQ->execute(); $doneQ->close();
                $autoNotified=true;
            }
        }
        auditLog($conn,'RESET_SECRET',"id:$id auto_notified:".($autoNotified?'yes':'no')); 
        echo json_encode(['ok'=>true,'code'=>$newCode,'auto_notified'=>$autoNotified]); exit();
    }

    // ── Reveal Secret Code ───────────────────────────────
    if($act==='reveal_secret' && $conn && $id>0){
        $stmt=$conn->prepare("SELECT secret_code,name FROM donors WHERE id=?");
        $stmt->bind_param("i",$id); $stmt->execute();
        $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if($r){ auditLog($conn,'REVEAL_SECRET',"id:$id name:{$r['name']}"); echo json_encode(['ok'=>true,'code'=>$r['secret_code'],'name'=>$r['name']]); }
        else   { echo json_encode(['ok'=>false,'msg'=>'Donor not found']); }
        exit();
    }

    // ── Token Management ─────────────────────────────────
    if($act==='add_token' && $conn){
        $tname=trim($_POST['token_name']??'');
        if(empty($tname)){ echo json_encode(['ok'=>false,'msg'=>'Token name দিন।']); exit(); }
        $tname=mb_substr($tname,0,100);
        $tval=generateToken();
        $stmt=$conn->prepare("INSERT INTO api_tokens (token_name,token_value,is_active) VALUES (?,?,1)");
        $stmt->bind_param("ss",$tname,$tval); $stmt->execute();
        $newId=(int)$conn->insert_id; $stmt->close();
        auditLog($conn,'ADD_TOKEN',"name:$tname");
        echo json_encode(['ok'=>true,'id'=>$newId,'token_name'=>$tname,'token_value'=>$tval,'is_active'=>1,'created_at'=>date('d M Y, h:i A')]); exit();
    }

    if($act==='del_token' && $conn && $id>0){
        $stmt=$conn->prepare("DELETE FROM api_tokens WHERE id=?");
        $stmt->bind_param("i",$id); $exec=$stmt->execute(); $aff=$stmt->affected_rows; $stmt->close();
        if($exec&&$aff>0){ auditLog($conn,'DEL_TOKEN',"id:$id"); echo json_encode(['ok'=>true]); }
        else { echo json_encode(['ok'=>false,'msg'=>'Token পাওয়া যায়নি।']); }
        exit();
    }

    if($act==='toggle_token' && $conn && $id>0){
        $stmt=$conn->prepare("UPDATE api_tokens SET is_active=(1-is_active) WHERE id=?");
        $stmt->bind_param("i",$id); $stmt->execute(); $stmt->close();
        $r=$conn->query("SELECT is_active FROM api_tokens WHERE id=$id");
        $row=$r?$r->fetch_assoc():null;
        echo json_encode(['ok'=>true,'is_active'=>(int)($row['is_active']??0)]); exit();
    }

    // ── IP Whitelist ─────────────────────────────────────
    if($act==='add_ip' && $conn){
        $ip=trim($_POST['ip_addr']??'');
        $label=mb_substr(trim($_POST['ip_label']??''),0,100);
        if(!filter_var($ip,FILTER_VALIDATE_IP)){ echo json_encode(['ok'=>false,'msg'=>'সঠিক IP address দিন।']); exit(); }
        // Check duplicate
        $esc=mysqli_real_escape_string($conn,$ip);
        $ex=$conn->query("SELECT id FROM ip_whitelist WHERE ip='$esc' LIMIT 1");
        if($ex&&$ex->num_rows>0){ echo json_encode(['ok'=>false,'msg'=>'এই IP আগেই আছে।']); exit(); }
        $stmt=$conn->prepare("INSERT INTO ip_whitelist (ip,label,is_active) VALUES (?,?,1)");
        $stmt->bind_param("ss",$ip,$label); $stmt->execute();
        $newId=(int)$conn->insert_id; $stmt->close();
        auditLog($conn,'ADD_IP',"ip:$ip label:$label");
        echo json_encode(['ok'=>true,'id'=>$newId,'ip'=>$ip,'label'=>$label,'is_active'=>1,'created_at'=>date('d M Y, h:i A')]); exit();
    }

    if($act==='del_ip' && $conn && $id>0){
        $stmt=$conn->prepare("DELETE FROM ip_whitelist WHERE id=?");
        $stmt->bind_param("i",$id); $exec=$stmt->execute(); $aff=$stmt->affected_rows; $stmt->close();
        if($exec&&$aff>0){ auditLog($conn,'DEL_IP',"id:$id"); echo json_encode(['ok'=>true]); }
        else { echo json_encode(['ok'=>false,'msg'=>'IP পাওয়া যায়নি।']); }
        exit();
    }

    if($act==='toggle_ip_whitelist' && $conn){
        $cur=$conn->query("SELECT setting_value FROM admin_settings WHERE setting_key='ip_whitelist_enabled' LIMIT 1");
        $curRow=$cur?$cur->fetch_assoc():null;
        $newVal=($curRow&&$curRow['setting_value']==='1')?'0':'1';
        $conn->query("INSERT INTO admin_settings (setting_key,setting_value) VALUES ('ip_whitelist_enabled','$newVal') ON DUPLICATE KEY UPDATE setting_value='$newVal'");
        auditLog($conn,'TOGGLE_IP_WL',"enabled:$newVal");
        echo json_encode(['ok'=>true,'enabled'=>$newVal==='1']); exit();
    }

    // ── Change Password ──────────────────────────────────
    if($act==='change_password'){
        $current_pass=trim($_POST['current_pass']??'');
        $new_pass=trim($_POST['new_pass']??'');
        $confirm_pass=trim($_POST['confirm_pass']??'');
        if(!defined('ADMIN_HASH')||!password_verify($current_pass,ADMIN_HASH)){
            echo json_encode(['ok'=>false,'msg'=>'বর্তমান password ভুল।']); exit();
        }
        if(strlen($new_pass)<8){ echo json_encode(['ok'=>false,'msg'=>'নতুন password কমপক্ষে ৮ অক্ষরের হতে হবে।']); exit(); }
        if($new_pass!==$confirm_pass){ echo json_encode(['ok'=>false,'msg'=>'নতুন password ও confirm password মিলছে না।']); exit(); }
        $newHash=password_hash($new_pass,PASSWORD_BCRYPT,['cost'=>12]);
        // Write new hash to admin_config.php
        $configPath=__DIR__.'/admin_config.php';
        $configContent=file_get_contents($configPath);
        if($configContent===false){ echo json_encode(['ok'=>false,'msg'=>'Config file পড়তে পারছি না।']); exit(); }
        $newConfig=preg_replace("/define\s*\(\s*'ADMIN_HASH'\s*,\s*'[^']+'\s*\)/","define('ADMIN_HASH','$newHash')",$configContent);
        if($newConfig===null||$newConfig===$configContent){
            // Try double-quote version
            $newConfig=preg_replace('/define\s*\(\s*"ADMIN_HASH"\s*,\s*"[^"]+"\s*\)/',"define('ADMIN_HASH','$newHash')",$configContent);
        }
        if(!$newConfig||$newConfig===$configContent){ echo json_encode(['ok'=>false,'msg'=>'Config update করতে পারছি না। ADMIN_HASH define পাওয়া যায়নি।']); exit(); }
        if(file_put_contents($configPath,$newConfig)===false){ echo json_encode(['ok'=>false,'msg'=>'Config file লিখতে পারছি না।']); exit(); }
        auditLog($conn,'CHANGE_PASSWORD','password updated');
        echo json_encode(['ok'=>true,'msg'=>'✅ Password সফলভাবে পরিবর্তন হয়েছে!']); exit();
    }

    // ── Get Settings ─────────────────────────────────────
    if($act==='get_settings' && $conn){
        ensureAdminTables($conn);
        $wl=$conn->query("SELECT setting_value FROM admin_settings WHERE setting_key='ip_whitelist_enabled' LIMIT 1");
        $wlRow=$wl?$wl->fetch_assoc():null;
        $enabled=($wlRow&&$wlRow['setting_value']==='1');
        $ips=[]; $ir=$conn->query("SELECT * FROM ip_whitelist ORDER BY id DESC");
        if($ir) while($r=$ir->fetch_assoc()) $ips[]=$r;
        $tokens=[]; $tr=$conn->query("SELECT id,token_name,token_value,is_active,created_at,last_used FROM api_tokens ORDER BY id DESC");
        if($tr) while($r=$tr->fetch_assoc()) $tokens[]=$r;
        echo json_encode(['ok'=>true,'ip_whitelist_enabled'=>$enabled,'ips'=>$ips,'tokens'=>$tokens]); exit();
    }

    // ── Send Bulk Notification ───────────────────────────
    if($act==='send_notif_bulk' && $conn){
        $type=trim($_POST['notif_type']??'info');
        $message=trim($_POST['message']??'');
        $vtypes=['secret_reset','secret_code_ready','location_on','notif_on','info','warning'];
        if(!in_array($type,$vtypes,true)) $type='info';
        if(empty($message)){echo json_encode(['ok'=>false,'msg'=>'Message লিখুন।']);exit();}
        if(mb_strlen($message,'UTF-8')>500){echo json_encode(['ok'=>false,'msg'=>'সর্বোচ্চ ৫০০ অক্ষর।']);exit();}
        @$conn->query("CREATE TABLE IF NOT EXISTS `service_notifications`(`id` INT AUTO_INCREMENT PRIMARY KEY,`device_id` VARCHAR(100) NOT NULL,`type` VARCHAR(30) NOT NULL,`message` TEXT NOT NULL,`is_read` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @$conn->query("CREATE TABLE IF NOT EXISTS `push_subscriptions`(`id` INT AUTO_INCREMENT PRIMARY KEY,`endpoint` TEXT NOT NULL,`p256dh` TEXT NOT NULL,`auth` TEXT NOT NULL,`device_id` VARCHAR(100) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $allDevices=[];
        $rps=$conn->query("SELECT DISTINCT device_id FROM push_subscriptions WHERE device_id IS NOT NULL AND device_id!=''");
        if($rps) while($r=$rps->fetch_assoc()) $allDevices[$r['device_id']]=true;
        $rsn=$conn->query("SELECT DISTINCT device_id FROM service_notifications WHERE device_id!=''");
        if($rsn) while($r=$rsn->fetch_assoc()) $allDevices[$r['device_id']]=true;
        // Also include devices that have made security code requests
        @$conn->query("CREATE TABLE IF NOT EXISTS `security_code_requests`(`id` INT AUTO_INCREMENT PRIMARY KEY,`donor_number` VARCHAR(20),`ref_code` VARCHAR(10),`device_id` VARCHAR(100),`req_ip` VARCHAR(50) DEFAULT NULL,`status` VARCHAR(20) DEFAULT 'pending',`admin_note` VARCHAR(500) DEFAULT '',`view_count` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rscr=$conn->query("SELECT DISTINCT device_id FROM security_code_requests WHERE device_id IS NOT NULL AND device_id!=''");
        if($rscr) while($r=$rscr->fetch_assoc()) $allDevices[$r['device_id']]=true;
        // Also include devices from admin_messages
        @$conn->query("CREATE TABLE IF NOT EXISTS `admin_messages`(`id` INT AUTO_INCREMENT PRIMARY KEY,`sender_name` VARCHAR(100),`sender_phone` VARCHAR(20),`message` TEXT,`device_id` VARCHAR(100),`is_read` TINYINT DEFAULT 0,`admin_reply` TEXT DEFAULT NULL,`replied_at` TIMESTAMP NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ramsg=$conn->query("SELECT DISTINCT device_id FROM admin_messages WHERE device_id IS NOT NULL AND device_id!=''");
        if($ramsg) while($r=$ramsg->fetch_assoc()) $allDevices[$r['device_id']]=true;
        $count=0;
        foreach(array_keys($allDevices) as $did){
            $sn=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
            $sn->bind_param("sss",$did,$type,$message);$sn->execute();$sn->close();
            $count++;
        }
        auditLog($conn,'BULK_NOTIF',"type:$type devices:$count msg:".mb_substr($message,0,60));
        echo json_encode(['ok'=>true,'msg'=>"✅ {$count} টি device এ notification পাঠানো হয়েছে।",'count'=>$count]); exit();
    }

    // ── Send Notification to Specific Donor ─────────────
    if($act==='send_notif_donor' && $conn){
        $phone=trim($_POST['donor_phone']??'');
        $type=trim($_POST['notif_type']??'info');
        $message=trim($_POST['message']??'');
        $vtypes=['secret_reset','secret_code_ready','location_on','notif_on','info','warning'];
        if(!in_array($type,$vtypes,true)) $type='info';
        if(empty($phone)||empty($message)){echo json_encode(['ok'=>false,'msg'=>'Phone ও message দিন।']);exit();}
        if(!preg_match('/^\+8801\d{9}$/',$phone)){echo json_encode(['ok'=>false,'msg'=>'সঠিক ফোন নম্বর দিন।']);exit();}
        if(mb_strlen($message,'UTF-8')>500){echo json_encode(['ok'=>false,'msg'=>'সর্বোচ্চ ৫০০ অক্ষর।']);exit();}
        @$conn->query("CREATE TABLE IF NOT EXISTS `service_notifications`(`id` INT AUTO_INCREMENT PRIMARY KEY,`device_id` VARCHAR(100) NOT NULL,`type` VARCHAR(30) NOT NULL,`message` TEXT NOT NULL,`is_read` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @$conn->query("CREATE TABLE IF NOT EXISTS `security_code_requests`(`id` INT AUTO_INCREMENT PRIMARY KEY,`donor_number` VARCHAR(20) NOT NULL,`ref_code` VARCHAR(10) NOT NULL,`device_id` VARCHAR(100) NOT NULL,`req_ip` VARCHAR(50) DEFAULT NULL,`status` VARCHAR(20) DEFAULT 'pending',`admin_note` VARCHAR(500) DEFAULT '',`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $dq=$conn->prepare("SELECT device_id FROM security_code_requests WHERE donor_number=? ORDER BY id DESC LIMIT 1");
        $dq->bind_param("s",$phone);$dq->execute();
        $drow=$dq->get_result()->fetch_assoc();$dq->close();
        if(!$drow||empty($drow['device_id'])){echo json_encode(['ok'=>false,'msg'=>'❌ এই donor এর device ID পাওয়া যায়নি। তাকে আগে Secret Code Request করতে হবে।']);exit();}
        $did=$drow['device_id'];
        $sn=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
        $sn->bind_param("sss",$did,$type,$message);$sn->execute();$sn->close();
        auditLog($conn,'DONOR_NOTIF',"phone:$phone type:$type");
        echo json_encode(['ok'=>true,'msg'=>"✅ Notification পাঠানো হয়েছে।"]); exit();
    }

    // ── Send Notification to Multiple Selected Donors ──────
    if($act==='send_notif_selected_donors' && $conn){
        $ids_raw=trim($_POST['donor_ids']??'');
        $type=trim($_POST['notif_type']??'info');
        $message=trim($_POST['message']??'');
        $vtypes=['secret_reset','secret_code_ready','location_on','notif_on','info','warning'];
        if(!in_array($type,$vtypes,true)) $type='info';
        if(empty($ids_raw)||empty($message)){echo json_encode(['ok'=>false,'msg'=>'IDs ও message দিন।']);exit();}
        if(mb_strlen($message,'UTF-8')>500){echo json_encode(['ok'=>false,'msg'=>'সর্বোচ্চ ৫০০ অক্ষর।']);exit();}
        $ids=array_filter(array_map('intval',explode(',',$ids_raw)));
        if(empty($ids)){echo json_encode(['ok'=>false,'msg'=>'কোনো donor select করা হয়নি।']);exit();}
        @$conn->query("CREATE TABLE IF NOT EXISTS `service_notifications`(`id` INT AUTO_INCREMENT PRIMARY KEY,`device_id` VARCHAR(100) NOT NULL,`type` VARCHAR(30) NOT NULL,`message` TEXT NOT NULL,`is_read` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Find device_ids for these donor IDs via phone number matching
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $phones_q=$conn->prepare("SELECT phone FROM donors WHERE id IN ($placeholders)");
        $phones_q->bind_param(str_repeat('i',count($ids)),...$ids);
        $phones_q->execute();
        $phones_res=$phones_q->get_result();
        $phones=[];
        while($pr=$phones_res->fetch_assoc()) $phones[]=$pr['phone'];
        $phones_q->close();
        $sent=0; $no_device=0;
        // Also get device_id directly from donors table
        $donor_device_map = [];
        $ph_list = implode(',', array_fill(0, count($phones), '?'));
        if(!empty($phones)){
            $ddq = $conn->prepare("SELECT phone, device_id FROM donors WHERE phone IN ($ph_list) AND device_id IS NOT NULL AND device_id!=''");
            $ddq->bind_param(str_repeat('s',count($phones)), ...$phones);
            $ddq->execute();
            $ddr = $ddq->get_result();
            while($ddr_row=$ddr->fetch_assoc()) $donor_device_map[$ddr_row['phone']] = $ddr_row['device_id'];
            $ddq->close();
        }
        foreach($phones as $phone){
            // 1st: donors.device_id
            $did = $donor_device_map[$phone] ?? '';
            // 2nd fallback: security_code_requests
            if(empty($did)){
                $dq=$conn->prepare("SELECT device_id FROM security_code_requests WHERE donor_number=? AND device_id IS NOT NULL AND device_id!='' ORDER BY id DESC LIMIT 1");
                $dq->bind_param("s",$phone); $dq->execute();
                $drow=$dq->get_result()->fetch_assoc(); $dq->close();
                $did=$drow['device_id'] ?? '';
            }
            if(empty($did)){ $no_device++; continue; }
            $sn=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
            $sn->bind_param("sss",$did,$type,$message); $sn->execute(); $sn->close();
            $sent++;
        }
        auditLog($conn,'SELECTED_NOTIF',"donors:".count($ids)." sent:$sent no_device:$no_device type:$type");
        $msg="✅ $sent জন donor কে notification পাঠানো হয়েছে।";
        if($no_device>0) $msg.=" ($no_device জনের device ID নেই — তাদের আগে Secret Code Request করতে হবে)";
        echo json_encode(['ok'=>true,'msg'=>$msg,'sent'=>$sent,'no_device'=>$no_device]); exit();
    }

    // ── Auto Reminder for Not-Willing Donors (triggers every 3 days) ─
    if($act==='run_auto_reminder' && $conn){
        // Create reminder log table
        $conn->query("CREATE TABLE IF NOT EXISTS `donor_reminder_log`(
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `donor_id` INT NOT NULL,
            `donor_phone` VARCHAR(20) NOT NULL,
            `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(donor_id), INDEX(sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Also check last global auto-reminder run time (avoid hammering)
        $conn->query("INSERT IGNORE INTO admin_settings (setting_key,setting_value) VALUES ('last_auto_reminder_run','0')");
        $last_run_r=$conn->query("SELECT setting_value FROM admin_settings WHERE setting_key='last_auto_reminder_run' LIMIT 1");
        $last_run=(int)($last_run_r?$last_run_r->fetch_assoc()['setting_value']:0);
        // Run at most once per hour (unless forced)
        $force = trim($_POST['force']??'') === '1';
        if(!$force && (time()-$last_run)<3600){ echo json_encode(['ok'=>true,'skipped'=>true,'msg'=>'Too soon']); exit(); }
        // Update last run time
        $conn->query("UPDATE admin_settings SET setting_value='".time()."' WHERE setting_key='last_auto_reminder_run'");
        // Find not-willing donors who haven't received a reminder in 3 days
        $remind_q=$conn->query("SELECT d.id, d.phone, d.name, d.device_id FROM donors d
            WHERE d.willing_to_donate='no'
            AND (
                NOT EXISTS (SELECT 1 FROM donor_reminder_log l WHERE l.donor_id=d.id AND l.sent_at > DATE_SUB(NOW(), INTERVAL 3 DAY))
            )
            LIMIT 50");
        if(!$remind_q){ echo json_encode(['ok'=>false,'msg'=>'Query failed']); exit(); }
        @$conn->query("CREATE TABLE IF NOT EXISTS `service_notifications`(`id` INT AUTO_INCREMENT PRIMARY KEY,`device_id` VARCHAR(100) NOT NULL,`type` VARCHAR(30) NOT NULL,`message` TEXT NOT NULL,`is_read` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $sent=0; $no_device=0;
        while($donor=$remind_q->fetch_assoc()){
            // Find device_id
            // 1st: donors.device_id
            $did = $donor['device_id'] ?? '';
            // 2nd fallback: security_code_requests
            if(empty($did)){
                $dq=$conn->prepare("SELECT device_id FROM security_code_requests WHERE donor_number=? AND device_id IS NOT NULL AND device_id!='' ORDER BY id DESC LIMIT 1");
                $dq->bind_param("s",$donor['phone']); $dq->execute();
                $drow=$dq->get_result()->fetch_assoc(); $dq->close();
                $did=$drow['device_id'] ?? '';
            }
            if(empty($did)){ $no_device++; continue; }
            $rmsg="🩸 আপনি এখনো রক্তদানে অনিচ্ছুক আছেন। আপনি কি এখন available হতে পারবেন? অনেকে আপনার সাহায্যের অপেক্ষায় আছে।\n\n💡 Available হতে:\nRegister → Update My Info → রক্তদানে ইচ্ছুক ON করুন।";
            $sn=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
            $sn->bind_param("sss",$did,'info',$rmsg); $sn->execute(); $sn->close();
            // Log this reminder
            $log=$conn->prepare("INSERT INTO donor_reminder_log (donor_id,donor_phone) VALUES (?,?)");
            $log->bind_param("is",$donor['id'],$donor['phone']); $log->execute(); $log->close();
            $sent++;
        }
        auditLog($conn,'AUTO_REMINDER',"sent:$sent no_device:$no_device");
        echo json_encode(['ok'=>true,'sent'=>$sent,'no_device'=>$no_device,'msg'=>"Auto reminder: $sent জনকে পাঠানো হয়েছে।"]); exit();
    }

    // ══════════════════════════════════════════════════════
    // SCHEDULED NOTIFICATION SYSTEM
    // ══════════════════════════════════════════════════════
    // Helper: ensure scheduled_notifications table exists
    function ensureScheduleTable($conn){
        $conn->query("CREATE TABLE IF NOT EXISTS `scheduled_notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `message` TEXT NOT NULL,
            `notif_type` VARCHAR(30) DEFAULT 'info',
            `target` VARCHAR(20) DEFAULT 'all',
            `donor_phone` VARCHAR(20) DEFAULT NULL,
            `repeat_type` VARCHAR(20) DEFAULT 'once',
            `run_at` DATETIME NOT NULL,
            `next_run` DATETIME NOT NULL,
            `last_run` DATETIME DEFAULT NULL,
            `run_count` INT DEFAULT 0,
            `is_active` TINYINT DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Save/create schedule
    if($act==='save_schedule' && $conn){
        ensureScheduleTable($conn);
        $title       = trim($_POST['title']??'');
        $message     = trim($_POST['message']??'');
        $notif_type  = trim($_POST['notif_type']??'info');
        $target      = trim($_POST['target']??'all');
        $donor_phone = trim($_POST['donor_phone']??'');
        $repeat_type = trim($_POST['repeat_type']??'once');
        $run_at_raw  = trim($_POST['run_at']??'');

        $valid_types  = ['info','warning','location_on','notif_on','secret_reset','secret_code_ready'];
        $valid_targets= ['all','donor','not_willing'];
        $blood_group_filter = trim($_POST['blood_group_filter'] ?? 'All');
        $valid_groups = ['All','A+','A-','B+','B-','AB+','AB-','O+','O-'];
        if(!in_array($blood_group_filter, $valid_groups, true)) $blood_group_filter = 'All';
        $valid_repeat = ['once','daily','weekly','monthly'];
        if(!in_array($notif_type,$valid_types,true)) $notif_type='info';
        if(!in_array($target,$valid_targets,true)) $target='all';
        if(!in_array($repeat_type,$valid_repeat,true)) $repeat_type='once';
        if(empty($title)||empty($message)||empty($run_at_raw)){
            echo json_encode(['ok'=>false,'msg'=>'Title, message ও run time দিন।']); exit();
        }
        if(mb_strlen($message,'UTF-8')>500){ echo json_encode(['ok'=>false,'msg'=>'Message সর্বোচ্চ ৫০০ অক্ষর।']); exit(); }
        if($target==='donor' && !preg_match('/^\+8801\d{9}$/',$donor_phone)){
            echo json_encode(['ok'=>false,'msg'=>'Specific donor এর জন্য সঠিক phone দিন।']); exit();
        }
        // Parse run_at (format: Y-m-d\TH:i from datetime-local input)
        $run_at_ts = strtotime($run_at_raw);
        if(!$run_at_ts || $run_at_ts < time()-60){
            echo json_encode(['ok'=>false,'msg'=>'ভবিষ্যতের একটি সময় নির্বাচন করুন।']); exit();
        }
        $run_at_fmt = date('Y-m-d H:i:s', $run_at_ts);
        $stmt = $conn->prepare("INSERT INTO scheduled_notifications (title,message,notif_type,target,donor_phone,repeat_type,run_at,next_run) VALUES (?,?,?,?,?,?,?,?)");
        // Store blood_group_filter in donor_phone field when target=not_willing (prefixed)
        $donor_phone_save = ($target === 'not_willing') ? 'BG:'.$blood_group_filter : $donor_phone;
        $stmt->bind_param("ssssssss",$title,$message,$notif_type,$target,$donor_phone_save,$repeat_type,$run_at_fmt,$run_at_fmt);
        if($stmt->execute()){
            auditLog($conn,'SCHEDULE_CREATE',"title:$title at:$run_at_fmt repeat:$repeat_type");
            echo json_encode(['ok'=>true,'msg'=>'✅ Schedule তৈরি হয়েছে।','id'=>$conn->insert_id]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'❌ Save failed.']);
        }
        $stmt->close(); exit();
    }

    // Delete schedule
    if($act==='delete_schedule' && $conn){
        ensureScheduleTable($conn);
        $sid = (int)($_POST['schedule_id']??0);
        if($sid<=0){ echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit(); }
        $stmt=$conn->prepare("DELETE FROM scheduled_notifications WHERE id=?");
        $stmt->bind_param("i",$sid); $stmt->execute(); $stmt->close();
        auditLog($conn,'SCHEDULE_DELETE',"id:$sid");
        echo json_encode(['ok'=>true,'msg'=>'✅ Schedule মুছে ফেলা হয়েছে।']); exit();
    }

    // Toggle active/inactive
    if($act==='toggle_schedule' && $conn){
        ensureScheduleTable($conn);
        $sid = (int)($_POST['schedule_id']??0);
        $stmt=$conn->prepare("UPDATE scheduled_notifications SET is_active = 1 - is_active WHERE id=?");
        $stmt->bind_param("i",$sid); $stmt->execute(); $stmt->close();
        echo json_encode(['ok'=>true]); exit();
    }

    // Get all schedules
    if($act==='get_schedules' && $conn){
        ensureScheduleTable($conn);
        $rows=[];
        $res=$conn->query("SELECT * FROM scheduled_notifications ORDER BY next_run ASC LIMIT 50");
        if($res) while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['ok'=>true,'schedules'=>$rows]); exit();
    }

    // Run due schedules (called by polling every 60s)
    if($act==='run_due_schedules' && $conn){
        ensureScheduleTable($conn);
        @$conn->query("CREATE TABLE IF NOT EXISTS `service_notifications`(`id` INT AUTO_INCREMENT PRIMARY KEY,`device_id` VARCHAR(100) NOT NULL,`type` VARCHAR(30) NOT NULL,`message` TEXT NOT NULL,`is_read` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @$conn->query("CREATE TABLE IF NOT EXISTS `device_tokens`(`device_id` VARCHAR(100) PRIMARY KEY,`context` VARCHAR(30) DEFAULT 'unknown',`ip` VARCHAR(50) DEFAULT NULL,`ua` VARCHAR(300) DEFAULT NULL,`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $due=$conn->query("SELECT * FROM scheduled_notifications WHERE is_active=1 AND next_run <= NOW()");
        $fired=0;
        if($due) while($sch=$due->fetch_assoc()){
            $type = $sch['notif_type'];
            $msg  = $sch['message'];
            $sent = 0;
            if($sch['target']==='all'){
                // Collect all devices from all tables
                $allDevices=[];
                $tbls=[
                    "SELECT DISTINCT device_id FROM push_subscriptions WHERE device_id IS NOT NULL AND device_id!=''",
                    "SELECT DISTINCT device_id FROM service_notifications WHERE device_id!=''",
                    "SELECT DISTINCT device_id FROM security_code_requests WHERE device_id IS NOT NULL AND device_id!=''",
                    "SELECT DISTINCT device_id FROM admin_messages WHERE device_id IS NOT NULL AND device_id!=''",
                    "SELECT DISTINCT device_id FROM device_tokens WHERE device_id IS NOT NULL AND device_id!=''"
                ];
                foreach($tbls as $tq){ $r=@$conn->query($tq); if($r) while($row=$r->fetch_assoc()) $allDevices[$row['device_id']]=true; }
                foreach(array_keys($allDevices) as $did){
                    $sn=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
                    $sn->bind_param("sss",$did,$type,$msg); $sn->execute(); $sn->close();
                    $sent++;
                }
            } elseif($sch['target']==='not_willing'){
                // Not-willing donors with device_id — optional blood group filter
                $bg_filter = '';
                $donor_phone_field = $sch['donor_phone'] ?? '';
                if(strpos($donor_phone_field, 'BG:') === 0){
                    $bg = substr($donor_phone_field, 3);
                    if($bg !== 'All' && in_array($bg,['A+','A-','B+','B-','AB+','AB-','O+','O-'],true)){
                        $bg_esc = $conn->real_escape_string($bg);
                        $bg_filter = " AND d.blood_group='$bg_esc'";
                    }
                }
                // Find device_ids via security_code_requests + device_tokens matched by phone
                $nwq = $conn->query("SELECT d.phone, d.name FROM donors d WHERE d.willing_to_donate='no'$bg_filter LIMIT 200");
                $allDevices = [];
                if($nwq) while($donor = $nwq->fetch_assoc()){
                    // Try security_code_requests
                    $dq = $conn->prepare("SELECT device_id FROM security_code_requests WHERE donor_number=? AND device_id IS NOT NULL AND device_id!='' ORDER BY id DESC LIMIT 1");
                    $dq->bind_param("s",$donor['phone']); $dq->execute();
                    $drow = $dq->get_result()->fetch_assoc(); $dq->close();
                    if($drow && !empty($drow['device_id'])) $allDevices[$drow['device_id']] = true;
                }
                // Also check device_tokens with reg_success context (phones registered via app)
                foreach(array_keys($allDevices) as $did){
                    $sn=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
                    $sn->bind_param("sss",$did,$type,$msg); $sn->execute(); $sn->close();
                    $sent++;
                }
            } else {
                // Specific donor
                $phone=$sch['donor_phone'];
                if(!empty($phone)){
                    $dq=$conn->prepare("SELECT device_id FROM security_code_requests WHERE donor_number=? AND device_id IS NOT NULL AND device_id!='' ORDER BY id DESC LIMIT 1");
                    $dq->bind_param("s",$phone); $dq->execute();
                    $drow=$dq->get_result()->fetch_assoc(); $dq->close();
                    $did=$drow['device_id']??'';
                    if(!empty($did)){
                        $sn=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
                        $sn->bind_param("sss",$did,$type,$msg); $sn->execute(); $sn->close();
                        $sent++;
                    }
                }
            }
            // Calculate next_run
            $now=time();
            switch($sch['repeat_type']){
                case 'daily':   $next=date('Y-m-d H:i:s',strtotime('+1 day',$now)); break;
                case 'weekly':  $next=date('Y-m-d H:i:s',strtotime('+7 days',$now)); break;
                case 'monthly': $next=date('Y-m-d H:i:s',strtotime('+1 month',$now)); break;
                default:        $next=null; // once — deactivate
            }
            if($next){
                $upd=$conn->prepare("UPDATE scheduled_notifications SET last_run=NOW(), run_count=run_count+1, next_run=? WHERE id=?");
                $upd->bind_param("si",$next,(int)$sch['id']); $upd->execute(); $upd->close();
            } else {
                $upd=$conn->prepare("UPDATE scheduled_notifications SET last_run=NOW(), run_count=run_count+1, is_active=0 WHERE id=?");
                $upd->bind_param("i",(int)$sch['id']); $upd->execute(); $upd->close();
            }
            auditLog($conn,'SCHEDULE_RUN',"id:{$sch['id']} sent:$sent target:{$sch['target']}");
            $fired++;
        }
        echo json_encode(['ok'=>true,'fired'=>$fired]); exit();
    }

    // ── Delete Secret Code Request ───────────────────────
    if($act==='delete_secret_request' && $conn){
        $req_id = (int)($_POST['req_id'] ?? 0);
        if($req_id <= 0){ echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit(); }
        $stmt = $conn->prepare("DELETE FROM security_code_requests WHERE id=? AND status!='pending'");
        $stmt->bind_param("i", $req_id); $stmt->execute();
        $affected = $stmt->affected_rows; $stmt->close();
        if($affected > 0){
            auditLog($conn,'DELETE_SECRET_REQ',"id:$req_id");
            echo json_encode(['ok'=>true,'msg'=>'✅ Request মুছে ফেলা হয়েছে।']);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'❌ Pending request মুছে ফেলা যাবে না। আগে Approve বা Deny করুন।']);
        }
        exit();
    }

    // ── Delete All Processed Secret Requests ─────────────
    if($act==='delete_all_processed_secret_requests' && $conn){
        $conn->query("DELETE FROM security_code_requests WHERE status IN ('approved','denied','ref_expired')");
        $count = $conn->affected_rows;
        auditLog($conn,'DELETE_ALL_PROCESSED_SECRET_REQS',"count:$count");
        echo json_encode(['ok'=>true,'msg'=>"✅ {$count} টি processed request মুছে ফেলা হয়েছে।"]);
        exit();
    }

    // ── Get Security Code Requests ───────────────────────
    if($act==='get_secret_requests' && $conn){
        @$conn->query("CREATE TABLE IF NOT EXISTS `security_code_requests`(`id` INT AUTO_INCREMENT PRIMARY KEY,`donor_number` VARCHAR(20) NOT NULL,`ref_code` VARCHAR(10) NOT NULL,`device_id` VARCHAR(100) NOT NULL,`req_ip` VARCHAR(50) DEFAULT NULL,`status` VARCHAR(20) DEFAULT 'pending',`admin_note` VARCHAR(500) DEFAULT '',`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $res=$conn->query("SELECT scr.id,scr.donor_number,scr.ref_code,scr.device_id,scr.req_ip,scr.status,scr.admin_note,scr.view_count,scr.created_at,d.name as donor_name,d.id as donor_id FROM security_code_requests scr LEFT JOIN donors d ON d.phone=scr.donor_number ORDER BY scr.status='pending' DESC,scr.created_at DESC LIMIT 50");
        $rows=[];
        if($res) while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit();
    }

    // ── Resolve Security Code Request ────────────────────
    if($act==='resolve_secret_request' && $conn){
        $req_id=(int)($_POST['req_id']??0);
        $action=trim($_POST['action']??'');
        $note=trim($_POST['admin_note']??'');
        if($req_id<=0||!in_array($action,['approve','deny'],true)){echo json_encode(['ok'=>false,'msg'=>'Invalid.']);exit();}
        if(mb_strlen($note,'UTF-8')>300) $note=mb_substr($note,0,300,'UTF-8');
        @$conn->query("CREATE TABLE IF NOT EXISTS `security_code_requests`(`id` INT AUTO_INCREMENT PRIMARY KEY,`donor_number` VARCHAR(20) NOT NULL,`ref_code` VARCHAR(10) NOT NULL,`device_id` VARCHAR(100) NOT NULL,`req_ip` VARCHAR(50) DEFAULT NULL,`status` VARCHAR(20) DEFAULT 'pending',`admin_note` VARCHAR(500) DEFAULT '',`view_count` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @$conn->query("ALTER TABLE security_code_requests ADD COLUMN IF NOT EXISTS view_count TINYINT DEFAULT 0");
        @$conn->query("CREATE TABLE IF NOT EXISTS `service_notifications`(`id` INT AUTO_INCREMENT PRIMARY KEY,`device_id` VARCHAR(100) NOT NULL,`type` VARCHAR(30) NOT NULL,`message` TEXT NOT NULL,`is_read` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rq=$conn->prepare("SELECT * FROM security_code_requests WHERE id=? AND status='pending'");
        $rq->bind_param("i",$req_id);$rq->execute();
        $req=$rq->get_result()->fetch_assoc();$rq->close();
        if(!$req){echo json_encode(['ok'=>false,'msg'=>'Request পাওয়া যায়নি বা ইতোমধ্যে processed।']);exit();}
        $newCode='';
        if($action==='approve'){
            $dq=$conn->prepare("SELECT id FROM donors WHERE phone=?");
            $dq->bind_param("s",$req['donor_number']);$dq->execute();
            $drow=$dq->get_result()->fetch_assoc();$dq->close();
            if(!$drow){echo json_encode(['ok'=>false,'msg'=>'Donor found করা যায়নি।']);exit();}
            $newCode=generateSecretCode($conn);
            $upd=$conn->prepare("UPDATE donors SET secret_code=? WHERE id=?");
            $upd->bind_param("si",$newCode,$drow['id']);$upd->execute();$upd->close();
            // Notify donor: new code ready, and they can use ref_code up to 3 times
            $msg="✅ আপনার Secret Code reset approve হয়েছে। 🔑 নতুন Code দেখতে: Register → Update My Info → '🔍 Reference Code দিয়ে Secret Code দেখুন' — আপনার ৪ সংখ্যার Reference Code দিন। (সর্বোচ্চ ৩ বার দেখা যাবে)";
            $ntype='secret_code_ready';
            $ns=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
            $ns->bind_param("sss",$req['device_id'],$ntype,$msg);$ns->execute();$ns->close();
            // Set status approved + reset view_count to 0
            $status='approved';
            $dn=$conn->prepare("UPDATE security_code_requests SET status=?,admin_note=?,view_count=0 WHERE id=?");
            $dn->bind_param("ssi",$status,$note,$req_id);$dn->execute();$dn->close();
        } else {
            $msg="❌ আপনার Secret Code reset request Approved হয়নি।".($note?" Admin note: {$note}":"");
            $ntype='info';
            $ns=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
            $ns->bind_param("sss",$req['device_id'],$ntype,$msg);$ns->execute();$ns->close();
            $status='denied';
            $dn=$conn->prepare("UPDATE security_code_requests SET status=?,admin_note=? WHERE id=?");
            $dn->bind_param("ssi",$status,$note,$req_id);$dn->execute();$dn->close();
        }
        auditLog($conn,'RESOLVE_SECRET_REQ',"id:$req_id action:$action phone:{$req['donor_number']}");
        $retMsg=$action==='approve'?"✅ Approved! Donor কে notification পাঠানো হয়েছে — ৩ বার পর্যন্ত ref code দিয়ে code দেখতে পারবে।":"✅ Denied. Notification পাঠানো হয়েছে.";
        echo json_encode(['ok'=>true,'msg'=>$retMsg,'new_code'=>$newCode]); exit();
    }

    // ── Admin Inbox: Get Messages ────────────────────────
    if($act==='get_inbox' && $conn){
        ensureAdminTables($conn);
        $filter=trim($_POST['filter']??'all'); // all | unread | replied
        $where='1';
        if($filter==='unread') $where='is_read=0 AND admin_reply IS NULL';
        elseif($filter==='replied') $where='admin_reply IS NOT NULL';
        $res=$conn->query("SELECT id,sender_name,sender_phone,message,device_id,is_read,admin_reply,replied_at,created_at FROM admin_messages WHERE $where ORDER BY created_at DESC LIMIT 100");
        $rows=[];
        if($res) while($r=$res->fetch_assoc()) $rows[]=$r;
        $unread=(int)($conn->query("SELECT COUNT(*) c FROM admin_messages WHERE is_read=0 AND admin_reply IS NULL")?->fetch_assoc()['c']??0);
        echo json_encode(['ok'=>true,'rows'=>$rows,'unread'=>$unread]); exit();
    }

    // ── Admin Inbox: Reply to Message ────────────────────
    if($act==='reply_inbox_msg' && $conn && $id>0){
        $reply=trim($_POST['reply']??'');
        if(empty($reply)){ echo json_encode(['ok'=>false,'msg'=>'Reply লিখুন।']); exit(); }
        if(mb_strlen($reply,'UTF-8')>1000){ echo json_encode(['ok'=>false,'msg'=>'Reply সর্বোচ্চ ১০০০ অক্ষর।']); exit(); }
        // Get device_id for service notification
        $mq=$conn->prepare("SELECT device_id,sender_name FROM admin_messages WHERE id=?");
        $mq->bind_param("i",$id); $mq->execute();
        $mrow=$mq->get_result()->fetch_assoc(); $mq->close();
        if(!$mrow){ echo json_encode(['ok'=>false,'msg'=>'Message পাওয়া যায়নি।']); exit(); }
        // Save reply
        $upd=$conn->prepare("UPDATE admin_messages SET admin_reply=?,replied_at=NOW(),is_read=1 WHERE id=?");
        $upd->bind_param("si",$reply,$id); $upd->execute(); $upd->close();
        // Send service notification to device
        @$conn->query("CREATE TABLE IF NOT EXISTS `service_notifications`(`id` INT AUTO_INCREMENT PRIMARY KEY,`device_id` VARCHAR(100) NOT NULL,`type` VARCHAR(30) NOT NULL,`message` TEXT NOT NULL,`is_read` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $notifMsg="💬 Admin Reply: ".$reply;
        $ntype='info';
        $did=$mrow['device_id'];
        $ns=$conn->prepare("INSERT INTO service_notifications (device_id,type,message) VALUES (?,?,?)");
        $ns->bind_param("sss",$did,$ntype,$notifMsg); $ns->execute(); $ns->close();
        auditLog($conn,'INBOX_REPLY',"id:$id device:".substr($did,0,20));
        echo json_encode(['ok'=>true,'msg'=>'✅ Reply পাঠানো হয়েছে এবং user notification পেবে।']); exit();
    }

    // ── Admin Inbox: Mark as Read ────────────────────────
    if($act==='mark_inbox_read' && $conn && $id>0){
        $conn->query("UPDATE admin_messages SET is_read=1 WHERE id=$id");
        echo json_encode(['ok'=>true]); exit();
    }

    // ── Delete Single Inbox Message ───────────────────────
    if($act==='del_inbox_msg' && $conn && $id>0){
        $stmt=$conn->prepare("DELETE FROM admin_messages WHERE id=?");
        $stmt->bind_param("i",$id); $stmt->execute(); $aff=$stmt->affected_rows; $stmt->close();
        auditLog($conn,'DEL_INBOX_MSG',"id:$id");
        echo json_encode(['ok'=>$aff>0]); exit();
    }

    // ── Clear All Inbox Messages ──────────────────────────
    if($act==='clear_inbox' && $conn){
        $filter=trim($_POST['filter']??'all');
        if($filter==='all'){
            $conn->query("DELETE FROM admin_messages");
        } elseif($filter==='replied'){
            $conn->query("DELETE FROM admin_messages WHERE admin_reply IS NOT NULL");
        } elseif($filter==='unread'){
            $conn->query("DELETE FROM admin_messages WHERE is_read=0 AND admin_reply IS NULL");
        }
        $aff=(int)$conn->affected_rows;
        auditLog($conn,'CLEAR_INBOX',"filter:$filter deleted:$aff");
        echo json_encode(['ok'=>true,'deleted'=>$aff,'msg'=>"✅ $aff টি message মুছে ফেলা হয়েছে।"]); exit();
    }

    // ── Moderator Management ─────────────────────────────
    if($act==='list_moderators' && $conn){
        ensureAdminTables($conn);
        $res=$conn->query("SELECT id,username,role,is_active,created_at FROM admin_users ORDER BY id DESC");
        $rows=[];
        if($res) while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['ok'=>true,'rows'=>$rows]); exit();
    }

    if($act==='add_moderator' && $conn){
        $uname=trim($_POST['uname']??'');
        $pass =trim($_POST['pass'] ??'');
        $role =trim($_POST['role'] ??'moderator');
        if(!in_array($role,['super_admin','moderator'],true)) $role='moderator';
        if(empty($uname)||empty($pass)){ echo json_encode(['ok'=>false,'msg'=>'Username ও Password দিন।']); exit(); }
        if(mb_strlen($uname,'UTF-8')>60||!preg_match('/^[a-zA-Z0-9_\-]+$/',$uname)){ echo json_encode(['ok'=>false,'msg'=>'Username শুধু a-z 0-9 _ - ব্যবহার করুন (max 60)।']); exit(); }
        if(strlen($pass)<8){ echo json_encode(['ok'=>false,'msg'=>'Password কমপক্ষে ৮ অক্ষর।']); exit(); }
        // Check duplicate
        $chk=$conn->prepare("SELECT id FROM admin_users WHERE username=?");
        $chk->bind_param("s",$uname); $chk->execute();
        if($chk->get_result()->num_rows>0){ $chk->close(); echo json_encode(['ok'=>false,'msg'=>'এই username ইতোমধ্যে আছে।']); exit(); }
        $chk->close();
        $hash=password_hash($pass,PASSWORD_BCRYPT,['cost'=>12]);
        $ins=$conn->prepare("INSERT INTO admin_users (username,pass_hash,role,is_active) VALUES (?,?,?,1)");
        $ins->bind_param("sss",$uname,$hash,$role); $ins->execute();
        $newId=(int)$conn->insert_id; $ins->close();
        auditLog($conn,'ADD_MODERATOR',"uname:$uname role:$role");
        echo json_encode(['ok'=>true,'id'=>$newId,'username'=>$uname,'role'=>$role,'is_active'=>1,'created_at'=>date('d M Y')]); exit();
    }

    if($act==='del_moderator' && $conn && $id>0){
        $stmt=$conn->prepare("DELETE FROM admin_users WHERE id=?");
        $stmt->bind_param("i",$id); $stmt->execute(); $aff=$stmt->affected_rows; $stmt->close();
        if($aff>0){ auditLog($conn,'DEL_MODERATOR',"id:$id"); echo json_encode(['ok'=>true]); }
        else { echo json_encode(['ok'=>false,'msg'=>'User পাওয়া যায়নি।']); }
        exit();
    }

    // ── Admin Poll — returns counts for notification polling ──
    if($act==='get_admin_poll' && $conn){
        ensureAdminTables($conn);
        @$conn->query("CREATE TABLE IF NOT EXISTS `security_code_requests`(`id` INT AUTO_INCREMENT PRIMARY KEY,`donor_number` VARCHAR(20),`ref_code` VARCHAR(10),`device_id` VARCHAR(100),`req_ip` VARCHAR(50) DEFAULT NULL,`status` VARCHAR(20) DEFAULT 'pending',`admin_note` VARCHAR(500) DEFAULT '',`view_count` TINYINT DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @$conn->query("CREATE TABLE IF NOT EXISTS `admin_messages`(`id` INT AUTO_INCREMENT PRIMARY KEY,`sender_name` VARCHAR(100),`sender_phone` VARCHAR(20),`message` TEXT,`device_id` VARCHAR(100),`is_read` TINYINT DEFAULT 0,`admin_reply` TEXT DEFAULT NULL,`replied_at` TIMESTAMP NULL DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $since = (int)($_POST['since'] ?? 0); // Unix timestamp of last known item
        // New inbox messages since last poll
        $inbox_q = $conn->query("SELECT id,sender_name,sender_phone,message,UNIX_TIMESTAMP(created_at) as ts FROM admin_messages WHERE is_read=0 AND admin_reply IS NULL".($since?" AND UNIX_TIMESTAMP(created_at)>$since":"")." ORDER BY id DESC LIMIT 10");
        $new_inbox = [];
        if($inbox_q) while($r=$inbox_q->fetch_assoc()) $new_inbox[] = $r;
        // New pending secret code requests since last poll
        $scr_q = $conn->query("SELECT id,donor_number,UNIX_TIMESTAMP(created_at) as ts FROM security_code_requests WHERE status='pending'".($since?" AND UNIX_TIMESTAMP(created_at)>$since":"")." ORDER BY id DESC LIMIT 10");
        $new_scr = [];
        if($scr_q) while($r=$scr_q->fetch_assoc()) $new_scr[] = $r;
        // Totals for badge update
        $total_inbox = (int)($conn->query("SELECT COUNT(*) c FROM admin_messages WHERE is_read=0 AND admin_reply IS NULL")?->fetch_assoc()['c']??0);
        $total_scr   = (int)($conn->query("SELECT COUNT(*) c FROM security_code_requests WHERE status='pending'")?->fetch_assoc()['c']??0);
        // Auto-reminder check — once per hour (non-blocking, silent)
        $auto_reminder_due = false;
        $conn->query("INSERT IGNORE INTO admin_settings (setting_key,setting_value) VALUES ('last_auto_reminder_run','0')");
        $lar_r=$conn->query("SELECT setting_value FROM admin_settings WHERE setting_key='last_auto_reminder_run' LIMIT 1");
        $lar=(int)($lar_r?$lar_r->fetch_assoc()['setting_value']:0);
        if((time()-$lar)>=3600) $auto_reminder_due=true;
        echo json_encode([
            'ok'          => true,
            'new_inbox'   => $new_inbox,
            'new_scr'     => $new_scr,
            'total_inbox' => $total_inbox,
            'total_scr'   => $total_scr,
            'server_time' => time(),
            'auto_reminder_due' => $auto_reminder_due
        ]); exit();
    }

    // ── Bulk Delete ──────────────────────────────────────
    if($act==='del_multiple' && $conn){
        $table=trim($_POST['table']??'');
        $ids_raw=trim($_POST['ids']??'');
        $tableMap=['donors'=>['donors','del_donor'],'requests'=>['blood_requests','del_req'],'reports'=>['reports','del_report'],'calls'=>['call_logs','del_call']];
        if(!isset($tableMap[$table])){ echo json_encode(['ok'=>false,'msg'=>'Invalid table.']); exit(); }
        $tname=$tableMap[$table][0];
        // Parse and validate IDs
        $ids=array_filter(array_map('intval',explode(',',$ids_raw)),fn($x)=>$x>0);
        if(empty($ids)){ echo json_encode(['ok'=>false,'msg'=>'কোনো ID পাওয়া যায়নি।']); exit(); }
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $types=str_repeat('i',count($ids));
        $stmt=$conn->prepare("DELETE FROM `$tname` WHERE id IN ($placeholders)");
        $stmt->bind_param($types,...$ids);
        $stmt->execute();
        $affected=$stmt->affected_rows;
        $stmt->close();
        if(method_exists($conn,'commit')) $conn->commit();
        auditLog($conn,'DEL_MULTIPLE',"table:$tname count:$affected ids:".implode(',',$ids));
        echo json_encode(['ok'=>true,'deleted'=>$affected]); exit();
    }

    // ── Standard CRUD actions ────────────────────────────
    if($conn && $id>=0){
        $stmt=null;
        if($act==='del_donor')       $stmt=$conn->prepare("DELETE FROM donors WHERE id=?");
        elseif($act==='del_req')     $stmt=$conn->prepare("DELETE FROM blood_requests WHERE id=?");
        elseif($act==='fulfill_req') $stmt=$conn->prepare("UPDATE blood_requests SET status='Fulfilled' WHERE id=?");
        elseif($act==='del_report')  $stmt=$conn->prepare("DELETE FROM reports WHERE id=?");
        elseif($act==='del_call')    $stmt=$conn->prepare("DELETE FROM call_logs WHERE id=?");

        if($stmt){
            $stmt->bind_param("i",$id);
            $exec_ok=$stmt->execute();
            $affected=$stmt->affected_rows;
            $stmt->close();
            if(!$exec_ok||$affected<1){
                echo json_encode(['ok'=>false,'msg'=>'⚠️ Delete হয়নি — row পাওয়া যায়নি বা DB error। (affected:'.$affected.')']);
                exit();
            }
            if(method_exists($conn,'commit')) $conn->commit();
            auditLog($conn,strtoupper($act),"id:$id affected:{$affected}");
            echo json_encode(['ok'=>true]); exit();
        } else {
            echo json_encode(['ok'=>false,'msg'=>'DB prepare error: '.($conn->error??'unknown')]);
            exit();
        }
    }
    echo json_encode(['ok'=>false,'msg'=>'Invalid ID বা DB unavailable']); exit();
}

// ── 14. FETCH DATA ────────────────────────────────────────
$donors=$requests=$reports=$calls=$audit_log=$ip_list=$token_list=[];
$stats=['donors'=>0,'available'=>0,'calls'=>0,'active_req'=>0,'reports'=>0,'week'=>0];
$ip_whitelist_enabled=false;

// One-time badge sync
if($conn){
    $_adm_schema_v2=__DIR__.'/.schema_v2_done';
    if(!file_exists($_adm_schema_v2)){
        @$conn->query("UPDATE donors SET badge_level=CASE WHEN total_donations>=10 THEN 'Legend' WHEN total_donations>=5 THEN 'Hero' WHEN total_donations>=2 THEN 'Active' ELSE 'New' END");
        @file_put_contents($_adm_schema_v2,date('Y-m-d H:i:s'));
    }
}

if($logged_in && $conn){
    ensureAdminTables($conn);

    $stats['donors']    =(int)(dbq($conn,"SELECT COUNT(*) c FROM donors")?->fetch_assoc()['c']??0);
    $r=dbq($conn,"SELECT COUNT(*) c FROM donors WHERE willing_to_donate='yes' AND (last_donation='no' OR last_donation='' OR last_donation='0000-00-00' OR DATEDIFF(CURDATE(),last_donation)>=120)");
    $stats['available'] =$r?(int)$r->fetch_assoc()['c']:0;
    $r2=dbq($conn,"SELECT COUNT(*) c FROM call_logs");
    $stats['calls']     =$r2?(int)$r2->fetch_assoc()['c']:0;
    $r3=dbq($conn,"SELECT COUNT(*) c FROM donors WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");
    $stats['week']      =$r3?(int)$r3->fetch_assoc()['c']:0;

    $conn->query("ALTER TABLE donors ADD COLUMN IF NOT EXISTS secret_code VARCHAR(25) DEFAULT NULL");

    $res=dbq($conn,"SELECT id,name,blood_group,location,phone,last_donation,willing_to_donate,total_donations,badge_level,reg_ip,reg_geo,created_at FROM donors ORDER BY id DESC LIMIT 300");
    if(!$res) $res=dbq($conn,"SELECT id,name,blood_group,location,phone,last_donation,willing_to_donate,total_donations,badge_level FROM donors ORDER BY id DESC LIMIT 300");
    if($res) while($row=$res->fetch_assoc()) $donors[]=$row;

    $conn->query("CREATE TABLE IF NOT EXISTS `blood_requests`(`id` INT AUTO_INCREMENT PRIMARY KEY,`patient_name` VARCHAR(100),`blood_group` VARCHAR(5),`hospital` VARCHAR(200),`contact` VARCHAR(20),`urgency` VARCHAR(10) DEFAULT 'High',`bags_needed` INT DEFAULT 1,`note` VARCHAR(500) DEFAULT '',`status` VARCHAR(20) DEFAULT 'Active',`delete_token` VARCHAR(10) DEFAULT NULL,`req_ip` VARCHAR(50) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Ensure delete_token column exists on older installs
    @$conn->query("ALTER TABLE blood_requests ADD COLUMN IF NOT EXISTS delete_token VARCHAR(10) DEFAULT NULL");
    $conn->query("ALTER TABLE `blood_requests` AUTO_INCREMENT=1");
    $res2=dbq($conn,"SELECT * FROM blood_requests ORDER BY FIELD(status,'Active','Fulfilled','Expired'),created_at DESC LIMIT 100");
    if($res2) while($row=$res2->fetch_assoc()) $requests[]=$row;
    $stats['active_req']=count(array_filter($requests,function($r){return $r['status']==='Active';}));

    // Security code requests
    @$conn->query("CREATE TABLE IF NOT EXISTS `security_code_requests`(`id` INT AUTO_INCREMENT PRIMARY KEY,`donor_number` VARCHAR(20) NOT NULL,`ref_code` VARCHAR(10) NOT NULL,`device_id` VARCHAR(100) NOT NULL,`req_ip` VARCHAR(50) DEFAULT NULL,`status` VARCHAR(20) DEFAULT 'pending',`admin_note` VARCHAR(500) DEFAULT '',`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pending_secret_reqs=(int)(dbq($conn,"SELECT COUNT(*) c FROM security_code_requests WHERE status='pending'")?->fetch_assoc()['c']??0);
    $inbox_unread=(int)(dbq($conn,"SELECT COUNT(*) c FROM admin_messages WHERE is_read=0 AND admin_reply IS NULL")?->fetch_assoc()['c']??0);

    $res3=dbq($conn,"SELECT * FROM reports ORDER BY id DESC LIMIT 100");
    if($res3) while($row=$res3->fetch_assoc()) $reports[]=$row;
    $stats['reports']=count($reports);

    $conn->query("ALTER TABLE call_logs ADD COLUMN IF NOT EXISTS caller_location VARCHAR(500) DEFAULT 'Not provided'");
    $conn->query("ALTER TABLE call_logs ADD COLUMN IF NOT EXISTS device_info VARCHAR(300) DEFAULT NULL");
    $res4=dbq($conn,"SELECT cl.*,d.name donor_name,d.blood_group FROM call_logs cl LEFT JOIN donors d ON cl.donor_id=d.id ORDER BY cl.id DESC LIMIT 200");
    if($res4) while($row=$res4->fetch_assoc()) $calls[]=$row;

    ensureAuditTable($conn);
    $res5=dbq($conn,"SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 50");
    if($res5) while($row=$res5->fetch_assoc()) $audit_log[]=$row;

    // IP whitelist data
    $wlSetting=$conn->query("SELECT setting_value FROM admin_settings WHERE setting_key='ip_whitelist_enabled' LIMIT 1");
    if($wlSetting){ $wlRow=$wlSetting->fetch_assoc(); $ip_whitelist_enabled=($wlRow['setting_value']??'0')==='1'; }
    $irAll=$conn->query("SELECT * FROM ip_whitelist ORDER BY id DESC");
    if($irAll) while($row=$irAll->fetch_assoc()) $ip_list[]=$row;

    // Token data
    $trAll=$conn->query("SELECT id,token_name,token_value,is_active,created_at,last_used FROM api_tokens ORDER BY id DESC");
    if($trAll) while($row=$trAll->fetch_assoc()) $token_list[]=$row;
}

$CSRF=$_SESSION['csrf'];
$currentIP=getIP();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Admin — Blood Solution</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{
  --bg:#0f1115;--card:#1a1d24;--card2:#1e2230;--bdr:rgba(255,255,255,0.08);
  --red:#dc2626;--green:#10b981;--blue:#3b82f6;--orange:#f59e0b;--purple:#8b5cf6;--cyan:#06b6d4;
  --text:#f3f4f6;--muted:#9ca3af;--inp:rgba(0,0,0,0.35);
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* ── LOGIN ── */
.lp{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;}
.lb{background:var(--card);border:1px solid var(--bdr);border-radius:20px;padding:40px;width:100%;max-width:360px;text-align:center;}
.lb h1{color:var(--red);font-size:1.6rem;margin-bottom:4px;}
.lb p{color:var(--muted);font-size:.87em;margin-bottom:26px;}
.lb input[type=password]{width:100%;padding:13px;background:var(--inp);border:1px solid var(--bdr);border-radius:10px;color:var(--text);font-size:1rem;margin-bottom:10px;outline:none;}
.lb input[type=password]:focus{border-color:var(--red);}
.lb button{width:100%;padding:13px;background:var(--red);color:#fff;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;}
.lb button:hover{background:#b91c1c;}
.err{color:var(--red);background:rgba(220,38,38,.1);border-radius:8px;padding:9px;margin-bottom:10px;font-size:.86em;}
.warn-box{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:var(--orange);border-radius:8px;padding:9px;margin-bottom:10px;font-size:.84em;}
.lock-box{background:rgba(239,68,68,.08);border:2px solid rgba(239,68,68,.3);border-radius:12px;padding:20px;margin-bottom:12px;text-align:center;}
.lock-box .lock-icon{font-size:2.5rem;margin-bottom:8px;}
.lock-box p{color:var(--red);font-weight:600;margin-bottom:4px;}
.lock-box small{color:var(--muted);font-size:.82em;}
.honey{display:none;}

/* ── IDLE WARNING ── */
.idle-warn{position:fixed;top:56px;right:16px;background:rgba(245,158,11,.96);color:#000;padding:10px 16px;border-radius:10px;font-size:.83em;font-weight:700;z-index:9999;display:none;box-shadow:0 4px 15px rgba(0,0,0,.4);}

/* ── HEADER ── */
.hdr{background:#141720;border-bottom:1px solid var(--bdr);padding:10px 18px;display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:200;height:48px;}
.hdr h1{color:var(--red);font-size:1rem;font-weight:800;}
.hdr small{color:var(--muted);font-size:.75em;margin-left:6px;}
.hdr-right{display:flex;align-items:center;gap:10px;}
.session-info{font-size:.72em;color:var(--muted);background:rgba(255,255,255,.04);padding:4px 9px;border-radius:8px;}
.lout{background:rgba(220,38,38,.12);color:var(--red);border:1px solid rgba(220,38,38,.25);padding:5px 12px;border-radius:20px;font-size:.78em;font-weight:600;cursor:pointer;text-decoration:none;}

/* ── LAYOUT ── */
.layout{display:flex;padding-top:48px;}
.sb{width:200px;background:#141720;border-right:1px solid var(--bdr);min-height:calc(100vh - 48px);padding:8px 0;flex-shrink:0;position:fixed;top:48px;left:0;height:calc(100vh - 48px);overflow-y:auto;}
.main{flex:1;padding:20px;margin-left:200px;overflow:auto;min-height:calc(100vh - 48px);}
.ni{display:flex;align-items:center;gap:7px;padding:10px 16px;cursor:pointer;font-size:.86em;color:var(--muted);border-left:3px solid transparent;transition:all .14s;}
.ni:hover{color:var(--text);background:rgba(255,255,255,.04);}
.ni.on{color:var(--text);background:rgba(220,38,38,.09);border-left-color:var(--red);}
.ni .cnt{margin-left:auto;background:var(--red);color:#fff;font-size:.67em;padding:1px 6px;border-radius:10px;font-weight:700;}
.ni-sep{height:1px;background:var(--bdr);margin:6px 12px;}
.ni-label{padding:8px 16px 2px;font-size:.65em;text-transform:uppercase;letter-spacing:1.5px;color:rgba(156,163,175,.45);font-weight:700;}

/* ── MOBILE TABS ── */
.mtabs{display:none;overflow-x:auto;gap:5px;padding:8px 10px;background:#141720;border-bottom:1px solid var(--bdr);position:fixed;top:48px;left:0;right:0;z-index:190;}
.mt{flex-shrink:0;padding:6px 12px;background:var(--inp);border:1px solid var(--bdr);border-radius:20px;color:var(--muted);font-size:.78em;cursor:pointer;white-space:nowrap;}
.mt.on{background:var(--red);color:#fff;border-color:var(--red);}
@media(max-width:700px){
  .sb{display:none;}
  .mtabs{display:flex;}
  .main{margin-left:0;padding:12px;padding-top:56px;}
  .layout{padding-top:48px;}
}

/* ── TABS ── */
.tab{display:none;}.tab.on{display:block;}

/* ── STATS ── */
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:20px;}
.sc{background:var(--card);border:1px solid var(--bdr);border-radius:12px;padding:14px;text-align:center;}
.sc .v{font-size:1.8rem;font-weight:800;line-height:1;}
.sc .l{font-size:.73em;color:var(--muted);margin-top:4px;}
.sc.r .v{color:var(--red);}.sc.g .v{color:var(--green);}.sc.b .v{color:var(--blue);}.sc.o .v{color:var(--orange);}.sc.p .v{color:var(--purple);}

/* ── TABLE ── */
.tbox{background:var(--card);border:1px solid var(--bdr);border-radius:12px;overflow:hidden;margin-bottom:16px;}
.tbar{padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;border-bottom:1px solid var(--bdr);}
.tbar h3{font-size:.9rem;font-weight:700;}
.srch{background:var(--inp);border:1px solid var(--bdr);border-radius:8px;padding:7px 11px;color:var(--text);font-size:.85em;outline:none;min-width:160px;}
.srch:focus{border-color:var(--red);}
.ow{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th{background:rgba(220,38,38,.07);padding:8px 10px;text-align:left;font-size:.72em;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
th.cb-th{width:32px;text-align:center;padding:8px 6px;}
td{padding:8px 10px;font-size:.83em;border-bottom:1px solid var(--bdr);vertical-align:middle;}
td.cb-td{text-align:center;padding:8px 6px;width:32px;}
tr:hover td{background:rgba(255,255,255,.02);}
tr:last-child td{border-bottom:none;}
.bg{display:inline-block;padding:2px 7px;border-radius:20px;font-weight:700;font-size:.8em;background:rgba(220,38,38,.15);color:var(--red);}
.av{color:var(--green);font-weight:600;}.unav{color:var(--muted);}.nav2{color:var(--orange);}
.btn{padding:4px 9px;border:none;border-radius:7px;font-size:.76em;font-weight:600;cursor:pointer;white-space:nowrap;}
.bd{background:rgba(239,68,68,.15);color:var(--red);}.bd:hover{background:var(--red);color:#fff;}
.bo{background:rgba(16,185,129,.15);color:var(--green);}.bo:hover{background:var(--green);color:#fff;}
.bb{background:rgba(59,130,246,.15);color:var(--blue);}.bb:hover{background:var(--blue);color:#fff;}
.bpu{background:rgba(139,92,246,.15);color:var(--purple);}.bpu:hover{background:var(--purple);color:#fff;}
.bc{background:rgba(6,182,212,.15);color:var(--cyan);}.bc:hover{background:var(--cyan);color:#000;}
.bo2{background:rgba(245,158,11,.15);color:var(--orange);}.bo2:hover{background:var(--orange);color:#000;}
.uc{display:inline-block;padding:2px 7px;border-radius:10px;font-size:.76em;font-weight:700;}
.uc.cr{background:rgba(239,68,68,.15);color:var(--red);}
.uc.hi{background:rgba(245,158,11,.15);color:var(--orange);}
.uc.me{background:rgba(59,130,246,.15);color:var(--blue);}
.sp{display:inline-block;padding:2px 7px;border-radius:10px;font-size:.76em;font-weight:700;}
.sp.ac{background:rgba(16,185,129,.15);color:var(--green);}
.sp.fu,.sp.ex{background:rgba(107,114,128,.15);color:var(--muted);}
.empty{text-align:center;padding:36px;color:var(--muted);}
.stitle{font-size:1.2rem;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.bbar{display:flex;align-items:center;gap:6px;}
.bbar-in{flex:1;height:5px;background:rgba(255,255,255,.07);border-radius:3px;}
.bbar-fill{height:100%;background:var(--green);border-radius:3px;}
.audit-ev{display:inline-block;padding:2px 7px;border-radius:10px;font-size:.74em;font-weight:700;background:rgba(59,130,246,.12);color:var(--blue);}
.audit-ev.fail{background:rgba(239,68,68,.12);color:var(--red);}
.audit-ev.success{background:rgba(16,185,129,.12);color:var(--green);}

/* ── BULK DELETE BAR ── */
.bulk-bar{display:none;align-items:center;gap:8px;padding:8px 16px;background:rgba(239,68,68,.07);border-bottom:1px solid rgba(239,68,68,.15);}
.bulk-bar.show{display:flex;}
.bulk-bar span{font-size:.82em;color:var(--muted);}
.bulk-bar span strong{color:var(--text);}
.bulk-del-btn{padding:5px 14px;background:var(--red);color:#fff;border:none;border-radius:8px;font-size:.8em;font-weight:700;cursor:pointer;}
.bulk-del-btn:hover{background:#b91c1c;}
.bulk-cancel-btn{padding:5px 12px;background:rgba(255,255,255,.07);color:var(--muted);border:1px solid var(--bdr);border-radius:8px;font-size:.8em;cursor:pointer;}
input[type=checkbox].row-cb{width:15px;height:15px;cursor:pointer;accent-color:var(--red);}
input[type=checkbox]#cb-all-donors,input[type=checkbox]#cb-all-requests,input[type=checkbox]#cb-all-reports,input[type=checkbox]#cb-all-calls{width:15px;height:15px;cursor:pointer;accent-color:var(--red);}

/* ── DEVICE / LOCATION CELL ── */
.dev-cell{max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.74em;color:var(--muted);cursor:default;}
.loc-cell{max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.74em;color:var(--cyan);}

/* ── SECRET CODE TAB ── */
.sc-search-bar{display:flex;flex-wrap:wrap;gap:8px;padding:14px 16px;border-bottom:1px solid var(--bdr);background:var(--card2);}
.sc-search-bar input,.sc-search-bar select{background:var(--inp);border:1px solid var(--bdr);border-radius:8px;padding:7px 11px;color:var(--text);font-size:.84em;outline:none;min-width:130px;}
.sc-search-bar input:focus,.sc-search-bar select:focus{border-color:var(--purple);}
.sc-search-bar button{padding:7px 16px;border:none;border-radius:8px;font-size:.84em;font-weight:700;cursor:pointer;background:var(--purple);color:#fff;}
.code-hidden{font-family:monospace;letter-spacing:2px;color:var(--muted);font-size:.8em;}
.code-visible{font-family:monospace;letter-spacing:1px;color:var(--cyan);font-size:.8em;font-weight:700;}
.badge-pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.72em;font-weight:700;}
.bp-new{background:rgba(16,185,129,.15);color:var(--green);}
.bp-active{background:rgba(59,130,246,.15);color:var(--blue);}
.bp-hero{background:rgba(139,92,246,.15);color:var(--purple);}
.bp-legend{background:rgba(245,158,11,.15);color:var(--orange);}

/* ── ADVANCED SEARCH TAB ── */
.adv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;padding:16px;border-bottom:1px solid var(--bdr);background:var(--card2);}
.adv-grid label{font-size:.72em;color:var(--muted);display:block;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.adv-grid input,.adv-grid select{width:100%;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;padding:7px 10px;color:var(--text);font-size:.84em;outline:none;}
.adv-grid input:focus,.adv-grid select:focus{border-color:var(--blue);}
.adv-actions{padding:12px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.adv-actions button{padding:8px 18px;border:none;border-radius:8px;font-size:.84em;font-weight:700;cursor:pointer;}
.adv-run{background:var(--blue);color:#fff;}
.adv-clear{background:rgba(255,255,255,.07);color:var(--muted);border:1px solid var(--bdr);}
.adv-export{background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.3);}
.adv-count{margin-left:auto;font-size:.8em;color:var(--muted);}
#advResults{padding:0;}
.st-av{color:var(--green);font-weight:600;}.st-nw{color:var(--muted);}.st-na{color:var(--orange);}
.loading-spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.2);border-top-color:var(--blue);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px;}
@keyframes spin{to{transform:rotate(360deg);}}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;}
.modal{background:var(--card);border:1px solid var(--bdr);border-radius:16px;padding:24px;max-width:420px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.6);}
.modal h3{font-size:1rem;font-weight:700;margin-bottom:12px;}
.modal .code-box{background:var(--inp);border:1px dashed var(--cyan);border-radius:10px;padding:14px;text-align:center;font-family:monospace;font-size:1.15rem;font-weight:700;color:var(--cyan);letter-spacing:2px;margin:12px 0;}
.modal-btns{display:flex;gap:8px;margin-top:14px;}
.modal-btns button{flex:1;padding:9px;border:none;border-radius:8px;font-size:.85em;font-weight:700;cursor:pointer;}

/* ── EDIT DONOR MODAL ── */
.edit-modal{background:var(--card);border:1px solid var(--bdr);border-radius:16px;padding:24px;max-width:540px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.7);max-height:90vh;overflow-y:auto;}
.edit-modal h3{font-size:1rem;font-weight:700;margin-bottom:16px;color:var(--text);}
.ef-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
.ef-row.full{grid-template-columns:1fr;}
.ef-label{font-size:.72em;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:3px;}
.ef-input{width:100%;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;padding:8px 11px;color:var(--text);font-size:.88em;outline:none;}
.ef-input:focus{border-color:var(--blue);}
.ef-input[type=date]::-webkit-calendar-picker-indicator{filter:invert(0.6);}
.ef-toggle{display:flex;gap:6px;}
.ef-toggle button{flex:1;padding:7px;border:1px solid var(--bdr);border-radius:8px;font-size:.82em;font-weight:600;cursor:pointer;background:var(--inp);color:var(--muted);transition:all .15s;}
.ef-toggle button.on-yes{background:rgba(16,185,129,.2);border-color:var(--green);color:var(--green);}
.ef-toggle button.on-no{background:rgba(239,68,68,.2);border-color:var(--red);color:var(--red);}
.ef-save{width:100%;padding:11px;background:var(--blue);color:#fff;border:none;border-radius:10px;font-size:.92em;font-weight:700;cursor:pointer;margin-top:14px;}
.ef-save:hover{background:#2563eb;}
.ef-cancel{width:100%;padding:9px;background:rgba(255,255,255,.05);color:var(--muted);border:1px solid var(--bdr);border-radius:10px;font-size:.86em;font-weight:600;cursor:pointer;margin-top:6px;}
.ef-map-btn{padding:8px 11px;background:rgba(66,133,244,.12);border:1.5px solid rgba(66,133,244,.35);color:#4285f4;border-radius:8px;cursor:pointer;font-size:1.1rem;flex-shrink:0;}
.ef-geo-status{font-size:.72em;color:var(--green);margin-top:3px;display:none;}

/* ── SETTINGS / IP WHITELIST / TOKEN TABS ── */
.settings-section{background:var(--card);border:1px solid var(--bdr);border-radius:12px;padding:20px;margin-bottom:16px;}
.settings-section h4{font-size:.95rem;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.settings-section p{font-size:.83em;color:var(--muted);margin-bottom:14px;line-height:1.5;}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:12px;background:var(--card2);border-radius:10px;margin-bottom:12px;}
.toggle-row span{font-size:.88em;font-weight:600;}
.toggle-btn{position:relative;width:48px;height:26px;background:rgba(255,255,255,.1);border:none;border-radius:13px;cursor:pointer;transition:background .2s;flex-shrink:0;}
.toggle-btn.on{background:var(--green);}
.toggle-btn::after{content:'';position:absolute;top:3px;left:3px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.3);}
.toggle-btn.on::after{transform:translateX(22px);}
.ip-add-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
.ip-add-row input{flex:1;min-width:130px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;padding:9px 12px;color:var(--text);font-size:.86em;outline:none;}
.ip-add-row input:focus{border-color:var(--green);}
.ip-add-row button{padding:9px 18px;background:var(--green);color:#fff;border:none;border-radius:8px;font-size:.85em;font-weight:700;cursor:pointer;white-space:nowrap;}
.ip-tag{display:flex;align-items:center;gap:8px;padding:9px 12px;background:var(--card2);border-radius:10px;margin-bottom:7px;border:1px solid var(--bdr);}
.ip-tag .ip-addr{font-family:monospace;font-size:.85em;color:var(--cyan);font-weight:700;}
.ip-tag .ip-label{font-size:.78em;color:var(--muted);flex:1;}
.ip-tag .ip-badge{font-size:.72em;padding:2px 7px;border-radius:8px;font-weight:700;}
.ip-tag .ip-badge.active{background:rgba(16,185,129,.15);color:var(--green);}
.ip-tag .ip-badge.inactive{background:rgba(107,114,128,.15);color:var(--muted);}
.ip-del-btn{padding:3px 9px;background:rgba(239,68,68,.15);color:var(--red);border:none;border-radius:6px;font-size:.75em;cursor:pointer;}
.ip-del-btn:hover{background:var(--red);color:#fff;}
.ip-mine{font-size:.78em;color:var(--muted);margin-bottom:12px;background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.15);border-radius:8px;padding:8px 12px;}
.ip-mine code{font-family:monospace;color:var(--blue);}

/* Password change form */
.pw-form{display:flex;flex-direction:column;gap:10px;}
.pw-form label{font-size:.72em;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:3px;}
.pw-form input{width:100%;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;padding:9px 12px;color:var(--text);font-size:.88em;outline:none;}
.pw-form input:focus{border-color:var(--purple);}
.pw-save-btn{padding:11px;background:var(--purple);color:#fff;border:none;border-radius:10px;font-size:.92em;font-weight:700;cursor:pointer;margin-top:4px;}
.pw-save-btn:hover{background:#7c3aed;}
.pw-result{margin-top:8px;font-size:.85em;padding:9px 12px;border-radius:8px;display:none;}
.pw-result.ok{background:rgba(16,185,129,.1);color:var(--green);border:1px solid rgba(16,185,129,.2);}
.pw-result.err{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2);}

/* Token tab */
.token-add-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.token-add-row input{flex:1;min-width:150px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;padding:9px 12px;color:var(--text);font-size:.86em;outline:none;}
.token-add-row input:focus{border-color:var(--cyan);}
.token-add-row button{padding:9px 18px;background:var(--cyan);color:#000;border:none;border-radius:8px;font-size:.85em;font-weight:700;cursor:pointer;}
.token-row{display:flex;align-items:center;gap:10px;padding:11px 14px;background:var(--card2);border:1px solid var(--bdr);border-radius:10px;margin-bottom:8px;flex-wrap:wrap;}
.token-row .tok-name{font-weight:700;font-size:.88em;min-width:100px;}
.token-row .tok-val{font-family:monospace;font-size:.75em;color:var(--cyan);background:rgba(6,182,212,.08);padding:3px 8px;border-radius:6px;flex:1;word-break:break-all;}
.token-row .tok-status{font-size:.74em;padding:2px 8px;border-radius:8px;font-weight:700;flex-shrink:0;}
.tok-active{background:rgba(16,185,129,.15);color:var(--green);}
.tok-inactive{background:rgba(107,114,128,.15);color:var(--muted);}
.tok-date{font-size:.74em;color:var(--muted);white-space:nowrap;}
.tok-copy{padding:4px 10px;background:rgba(6,182,212,.12);color:var(--cyan);border:1px solid rgba(6,182,212,.25);border-radius:7px;font-size:.76em;cursor:pointer;}
.tok-toggle{padding:4px 10px;font-size:.76em;border:none;border-radius:7px;cursor:pointer;font-weight:600;}
.tok-toggle.active-btn{background:rgba(245,158,11,.15);color:var(--orange);}
.tok-toggle.inactive-btn{background:rgba(16,185,129,.15);color:var(--green);}
.tok-del{padding:4px 9px;background:rgba(239,68,68,.15);color:var(--red);border:none;border-radius:7px;font-size:.76em;cursor:pointer;}
.tok-del:hover{background:var(--red);color:#fff;}

/* Tab refresh button */
.tab-refresh-btn {
    padding:4px 12px; background:rgba(107,114,128,.12); color:var(--muted);
    border:1px solid var(--bdr); border-radius:20px; font-size:.76em;
    cursor:pointer; font-weight:600; transition:all .15s; white-space:nowrap;
}
.tab-refresh-btn:hover { background:rgba(59,130,246,.15); color:var(--blue); border-color:rgba(59,130,246,.3); }

.admin-footer{text-align:center;padding:18px;margin-top:28px;border-top:1px solid var(--bdr);font-size:.72em;color:rgba(156,163,175,.45);}
.admin-footer span{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.03);border:1px solid var(--bdr);border-radius:20px;padding:5px 14px;font-weight:500;letter-spacing:.3px;}
</style>
</head>
<body>

<?php if(!$logged_in): ?>
<!-- ══════════════ LOGIN ══════════════ -->
<div class="lp">
  <div class="lb">
    <div style="font-size:2.6rem;margin-bottom:8px;">🔐</div>
    <h1>Admin Panel</h1>
    <p>Blood Solution — SHSMC</p>
    <?php if($db_error):?><div class="warn-box">⚠️ <?=esc($db_error)?></div><?php endif;?>
    <?php if($locked):?>
      <div class="lock-box">
        <div class="lock-icon">🔒</div>
        <p>অ্যাকাউন্ট সাময়িক বন্ধ!</p>
        <small>অনেক বেশি ভুল চেষ্টা। <span id="lockTimer"><?=ceil($lockout_left/60)?></span> মিনিট পর আবার।</small>
      </div>
    <?php else:?>
      <?php if($login_error):?><div class="err"><?=esc($login_error)?></div><?php endif;?>
      <?php if(isset($_GET['timeout'])):?><div class="err">⏰ Session শেষ — আবার login করুন।</div><?php endif;?>
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=esc($CSRF)?>">
        <div class="honey"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
        <input type="text" name="username" placeholder="👤 Username (Moderator only)" autocomplete="username" style="margin-bottom:8px;" tabindex="1">
        <input type="password" name="pass" placeholder="🔑 Password" required autocomplete="new-password" tabindex="2">
        <p style="font-size:.73em;color:var(--muted);margin-bottom:12px;margin-top:-4px;">Super Admin: Username ফাঁকা রেখে শুধু Password দিন</p>
        <button type="submit" name="admin_login">🔐 Secure Login</button>
      </form>
    <?php endif;?>
  </div>
</div>
<?php if($locked):?>
<script>
let s=<?=max(0,$lockout_left)?>;const el=document.getElementById('lockTimer');
if(el){const t=setInterval(()=>{s--;if(el)el.textContent=Math.ceil(s/60);if(s<=0){clearInterval(t);location.reload();}},1000);}
</script>
<?php endif;?>
<div class="admin-footer" style="position:fixed;bottom:0;left:0;right:0;padding:10px;"><span>🩸 © 2026 Siam Innovatives — All Rights Reserved.</span></div>

<?php else: ?>
<!-- ══════════════ ADMIN PANEL ══════════════ -->
<div class="idle-warn" id="idleWarn">⚠️ <span id="idleSecs"></span>s এ auto-logout!</div>

<!-- MODAL (Secret Code reveal/reset result) -->
<div class="modal-overlay" id="codeModal" style="display:none;" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h3 id="modalTitle">🔑 Secret Code</h3>
    <div class="code-box" id="modalCode">—</div>
    <div style="font-size:.78em;color:var(--muted);text-align:center;margin-bottom:4px;" id="modalDonor"></div>
    <div class="modal-btns">
      <button onclick="copyModalCode()" style="background:rgba(6,182,212,.2);color:var(--cyan);border:1px solid rgba(6,182,212,.3);" id="copyModalBtn">📋 Copy</button>
      <button onclick="closeModal()" style="background:rgba(255,255,255,.07);color:var(--muted);border:1px solid var(--bdr);">Close</button>
    </div>
  </div>
</div>

<div class="hdr">
  <div><h1>🩸 BloodArena Admin</h1><small>SHSMC</small></div>
  <div class="hdr-right">
    <?php if($is_super):?>
    <span style="font-size:.72em;background:rgba(220,38,38,.15);color:#ef4444;border:1px solid rgba(220,38,38,.3);padding:3px 10px;border-radius:20px;font-weight:700;">👑 Super Admin</span>
    <?php else:?>
    <span style="font-size:.72em;background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid rgba(59,130,246,.3);padding:3px 10px;border-radius:20px;font-weight:700;">🛡️ Moderator</span>
    <?php endif;?>
    <span class="session-info" id="sessionTimer">⏱ <span id="sClock"></span></span>
    <a href="admin.php?logout=1" class="lout">🚪 Logout</a>
  </div>
</div>

<div class="mtabs" id="mtabs">
  <div class="mt on"  onclick="go('dashboard',this)">📊 Dashboard</div>
  <div class="mt"     onclick="go('donors',this)">👥 Donors</div>
  <div class="mt"     onclick="go('requests',this)">🆘 Requests</div>
  <div class="mt"     onclick="go('reports',this)">⚠️ Reports</div>
  <div class="mt"     onclick="go('calls',this)">📞 Calls</div>
  <div class="mt"     onclick="go('secrets',this)">🔑 Secrets</div>
  <div class="mt"     onclick="go('secretreqs',this)">📩 Requests<?php if(!empty($pending_secret_reqs)&&$pending_secret_reqs>0):?> (<?=$pending_secret_reqs?>)<?php endif;?></div>
  <div class="mt"     onclick="go('notifications',this)">🔔 Notif</div>
  <?php if($is_super):?><div class="mt" onclick="go('inbox',this)">📬 Inbox<?php if(!empty($inbox_unread)&&$inbox_unread>0):?> (<?=$inbox_unread?>)<?php endif;?></div><?php endif;?>
  <?php if($is_super):?><div class="mt" onclick="go('moderators',this)">👥 Mods</div><?php endif;?>
  <div class="mt"     onclick="go('tokens',this)">🪙 Tokens</div>
  <div class="mt"     onclick="go('advsearch',this)">🔍 Search</div>
  <div class="mt"     onclick="go('audit',this)">📋 Audit</div>
  <div class="mt"     onclick="go('settings',this)">⚙️ Settings</div>
</div>

<div class="layout">
  <nav class="sb">
    <div class="ni-label">Main</div>
    <div class="ni on" onclick="go('dashboard',this)">📊 Dashboard</div>
    <div class="ni"    onclick="go('donors',this)">👥 Donors <span class="cnt"><?=count($donors)?></span></div>
    <div class="ni"    onclick="go('requests',this)">🆘 Requests <span class="cnt"><?=$stats['active_req']?></span></div>
    <div class="ni"    onclick="go('reports',this)">⚠️ Reports <?php if($stats['reports']>0):?><span class="cnt"><?=$stats['reports']?></span><?php endif;?></div>
    <div class="ni"    onclick="go('calls',this)">📞 Call Logs</div>
    <div class="ni-sep"></div>
    <div class="ni-label">Tools</div>
    <div class="ni"    onclick="go('secrets',this)">🔑 Secret Codes</div>
    <div class="ni"    onclick="go('secretreqs',this)">📩 Secret Requests <?php if(!empty($pending_secret_reqs)&&$pending_secret_reqs>0):?><span class="cnt"><?=$pending_secret_reqs?></span><?php endif;?></div>
    <div class="ni"    onclick="go('notifications',this)">🔔 Notifications</div>
    <?php if($is_super):?><div class="ni" onclick="go('inbox',this)">📬 Inbox <?php if(!empty($inbox_unread)&&$inbox_unread>0):?><span class="cnt"><?=$inbox_unread?></span><?php endif;?></div><?php endif;?>
    <div class="ni"    onclick="go('tokens',this)">🪙 Token Manager</div>
    <?php if($is_super):?>
    <div class="ni"    onclick="go('moderators',this)">👥 Moderators</div>
    <?php endif;?>
    <div class="ni"    onclick="go('advsearch',this)">🔍 Advanced Search</div>
    <div class="ni-sep"></div>
    <div class="ni-label">System</div>
    <div class="ni"    onclick="go('audit',this)">📋 Audit Log</div>
    <div class="ni"    onclick="go('settings',this)">⚙️ Settings <span id="adminNotifDot" style="display:none;width:7px;height:7px;background:var(--green);border-radius:50%;margin-left:auto;flex-shrink:0;"></span></div>
    <div style="padding:12px 16px;margin-top:6px;border-top:1px solid var(--bdr);">
      <a href="index.php" style="font-size:.78em;color:var(--muted);display:block;margin-bottom:6px;">🌐 Site দেখুন</a>
      <span style="font-size:.7em;color:rgba(100,116,139,.5);">IP: <?=esc(getIP())?></span>
    </div>
  </nav>

  <main class="main">
    <?php if($db_error):?><div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:var(--orange);border-radius:10px;padding:11px;margin-bottom:14px;font-size:.84em;">⚠️ DB: <?=esc($db_error)?></div><?php endif;?>

    <!-- ══ DASHBOARD ══ -->
    <div class="tab on" id="tab-dashboard">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">📊 Dashboard<button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="stats">
        <div class="sc r"><div class="v"><?=$stats['donors']?></div><div class="l">মোট Donors</div></div>
        <div class="sc g"><div class="v"><?=$stats['available']?></div><div class="l">Available</div></div>
        <div class="sc o"><div class="v"><?=$stats['active_req']?></div><div class="l">Active Requests</div></div>
        <div class="sc b"><div class="v"><?=$stats['calls']?></div><div class="l">Total Calls</div></div>
        <div class="sc p"><div class="v"><?=$stats['reports']?></div><div class="l">Reports</div></div>
        <div class="sc g"><div class="v"><?=$stats['week']?></div><div class="l">এ সপ্তাহে নতুন</div></div>
        <?php if($is_super && !empty($inbox_unread) && $inbox_unread>0):?>
        <div class="sc b" onclick="go('inbox',document.querySelector('.ni[onclick*=inbox]'))" style="cursor:pointer;border-color:rgba(59,130,246,.4);position:relative;">
          <div class="v" style="color:var(--blue);"><?=$inbox_unread?></div>
          <div class="l">📬 Unread Inbox</div>
        </div>
        <?php endif;?>
        <?php if(!empty($pending_secret_reqs) && $pending_secret_reqs>0):?>
        <div class="sc o" onclick="go('secretreqs',document.querySelector('.ni[onclick*=secretreqs]'))" style="cursor:pointer;border-color:rgba(245,158,11,.4);position:relative;">
          <div class="v" style="color:var(--orange);"><?=$pending_secret_reqs?></div>
          <div class="l">📩 Pending Requests</div>
        </div>
        <?php endif;?>
      </div>
      <div class="tbox">
        <div class="tbar"><h3>🩸 Blood Group Summary</h3></div>
        <div class="ow"><table>
          <thead><tr><th>Group</th><th>Total</th><th>Available</th><th>%</th></tr></thead>
          <tbody>
          <?php if($conn): foreach(["A+","A-","B+","B-","AB+","AB-","O+","O-"] as $g):
            $eg=mysqli_real_escape_string($conn,$g);
            $tot=(int)(dbq($conn,"SELECT COUNT(*) c FROM donors WHERE blood_group='$eg'")?->fetch_assoc()['c']??0);
            $avr=dbq($conn,"SELECT COUNT(*) c FROM donors WHERE blood_group='$eg' AND willing_to_donate='yes' AND (last_donation='no' OR last_donation='' OR last_donation='0000-00-00' OR DATEDIFF(CURDATE(),last_donation)>=120)");
            $av=$avr?(int)$avr->fetch_assoc()['c']:0;
            $pct=$tot>0?round($av/$tot*100):0;
          ?><tr>
            <td><span class="bg"><?=$g?></span></td>
            <td><?=$tot?></td><td class="av"><?=$av?></td>
            <td><div class="bbar"><div class="bbar-in"><div class="bbar-fill" style="width:<?=$pct?>%;"></div></div><span style="font-size:.76em;color:var(--muted);"><?=$pct?>%</span></div></td>
          </tr><?php endforeach; endif;?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- ══ DONORS ══ -->
    <div class="tab" id="tab-donors">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">👥 Donors <span style="font-size:.65em;color:var(--muted);font-weight:400;">(<?=count($donors)?>)</span><button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="tbox">
        <div class="tbar">
          <h3>All Donors</h3>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input class="srch" placeholder="🔍 নাম / ফোন / লোকেশন..." oninput="ft('dtb',this.value)">
          </div>
        </div>
        <div class="bulk-bar" id="bulk-bar-donors" <?php if($is_moderator) echo 'style="display:none!important;"'; ?>>
          <span>✅ <strong id="donors-sel-count">0</strong> টি selected</span>
          <button class="bulk-del-btn" onclick="bulkDelete('donors')">🗑 Delete Selected</button>
          <button style="padding:5px 14px;background:rgba(59,130,246,.15);color:var(--blue);border:1px solid rgba(59,130,246,.3);border-radius:8px;font-size:.8em;font-weight:700;cursor:pointer;" onclick="openNotifySelectedModal()">📢 Notify Selected</button>
          <button class="bulk-cancel-btn" onclick="clearSelection('donors')">✕ Cancel</button>
        </div>
        <div class="ow"><table>
          <thead><tr>
            <th class="cb-th"><input type="checkbox" id="cb-all-donors" onchange="toggleAll('donors',this.checked)"></th>
            <th>#</th><th>Name</th><th>Group</th><th>Phone</th><th>Location</th><th>Status</th><th>Badge</th><th>Donations</th><th>Joined</th><th>Reg IP</th><th>📍 Reg Location</th><th>Actions</th>
          </tr></thead>
          <tbody id="dtb">
          <?php
          $badge_icons=['Legend'=>'👑','Hero'=>'🦸','Active'=>'⭐','New'=>'🌱'];
          foreach($donors as $i=>$d):
            $w=$d['willing_to_donate']??'yes'; $ld=$d['last_donation']??'no';
            $isav=($ld==='no'||empty($ld)||$ld==='0000-00-00'||(strtotime($ld)&&(time()-strtotime($ld))/86400>=120));
            $cls=$w==='no'?'unav':($isav?'av':'nav2');
            $stx=$w==='no'?'⛔ Not Willing':($isav?'✔ Available':'✖ Not Available');
            $jn=!empty($d['created_at'])?date('d M Y',strtotime($d['created_at'])):'—';
            $rip=esc($d['reg_ip']??'—');
            $rgeo=$d['reg_geo']??'Not captured';
            $hasLatLng=preg_match('/Lat:\s*([\-0-9.]+),\s*Lon:\s*([\-0-9.]+)/',$rgeo,$gm2);
            $mapsUrl=$hasLatLng?'https://www.google.com/maps?q='.$gm2[1].','.$gm2[2]:'https://www.google.com/maps/search/'.urlencode($d['location']??'');
            $rgeoLabel=$hasLatLng?$gm2[1].','.$gm2[2]:'—';
            $locMapsUrl='https://www.google.com/maps/search/'.urlencode($d['location']??'');
          ?><tr id="drow<?=$d['id']?>" class="donor-row">
            <td class="cb-td"><input type="checkbox" class="row-cb donors-cb" value="<?=$d['id']?>" onchange="onRowCbChange('donors')"></td>
            <td style="color:var(--muted);"><?=$i+1?></td>
            <td style="font-weight:600;white-space:nowrap;"><?=esc($d['name'])?></td>
            <td><span class="bg"><?=esc($d['blood_group'])?></span></td>
            <td style="font-family:monospace;font-size:.8em;"><?=esc($d['phone'])?></td>
            <td style="font-size:.8em;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <a href="<?=esc($locMapsUrl)?>" target="_blank" rel="noopener" title="Google Maps" style="color:var(--cyan);text-decoration:none;">📍 <?=esc($d['location']??'')?></a>
            </td>
            <td class="<?=$cls?>" style="white-space:nowrap;"><?=$stx?></td>
            <td style="font-size:.79em;"><?=($badge_icons[$d['badge_level']??'New']??'🌱')?> <?=esc($d['badge_level']??'New')?></td>
            <td style="text-align:center;"><?=(int)($d['total_donations']??0)?></td>
            <td style="font-size:.77em;color:var(--muted);white-space:nowrap;"><?=$jn?></td>
            <td style="font-family:monospace;font-size:.74em;color:var(--muted);"><?=$rip?></td>
            <td style="font-size:.73em;white-space:nowrap;">
              <?php if($hasLatLng):?>
                <a href="<?=esc($mapsUrl)?>" target="_blank" rel="noopener" style="color:var(--green);text-decoration:none;font-weight:600;" title="Google Maps exact location">🗺️ <?=esc($rgeoLabel)?></a>
              <?php else:?><span style="color:var(--muted);">—</span><?php endif;?>
            </td>
            <td>
              <div style="display:flex;gap:4px;">
                <button class="btn bb" onclick="openEditDonor(<?=$d['id']?>,this)" title="Edit">✏️</button>
                <?php if($is_super):?><button class="btn bd" onclick="act('del_donor',<?=$d['id']?>,this,'drow<?=$d['id']?>')">🗑</button><?php endif;?>
              </div>
            </td>
          </tr><?php endforeach; if(empty($donors)):?><tr><td colspan="13" class="empty">কোনো donor নেই</td></tr><?php endif;?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- ══ REQUESTS ══ -->
    <div class="tab" id="tab-requests">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">🆘 Blood Requests <span style="font-size:.65em;color:var(--muted);font-weight:400;">(<?=count($requests)?>)</span><button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="tbox">
        <div class="tbar">
          <h3>All Requests</h3>
          <input class="srch" placeholder="🔍 খুঁজুন..." oninput="ft('rtb',this.value)">
        </div>
        <div class="bulk-bar" id="bulk-bar-requests" <?php if($is_moderator) echo 'style="display:none!important;"'; ?>>
          <span>✅ <strong id="requests-sel-count">0</strong> টি selected</span>
          <button class="bulk-del-btn" onclick="bulkDelete('requests')">🗑 Delete Selected</button>
          <button class="bulk-cancel-btn" onclick="clearSelection('requests')">✕ Cancel</button>
        </div>
        <div class="ow"><table>
          <thead><tr>
            <th class="cb-th"><input type="checkbox" id="cb-all-requests" onchange="toggleAll('requests',this.checked)"></th>
            <th>#</th><th>Patient</th><th>Group</th><th>Hospital</th><th>Contact</th><th>Urgency</th><th>Bags</th><th>Delete Token</th><th>Status</th><th>Time</th><th>Actions</th>
          </tr></thead>
          <tbody id="rtb">
          <?php foreach($requests as $i=>$r):
            $uc=strtolower($r['urgency']??'high');
            if(!in_array($uc,['critical','high','medium'])) $uc='high';
            $ucmap=['critical'=>'cr','high'=>'hi','medium'=>'me'];
            $tm=!empty($r['created_at'])?date('d M, h:i A',strtotime($r['created_at'])):'—';
          ?><tr id="rr<?=$r['id']?>" class="requests-row">
            <td class="cb-td"><input type="checkbox" class="row-cb requests-cb" value="<?=$r['id']?>" onchange="onRowCbChange('requests')"></td>
            <td style="color:var(--muted);"><?=$i+1?></td>
            <td style="font-weight:600;"><?=esc($r['patient_name'])?></td>
            <td><span class="bg"><?=esc($r['blood_group'])?></span></td>
            <td style="font-size:.81em;"><?=esc($r['hospital'])?></td>
            <td style="font-family:monospace;font-size:.8em;"><?=esc($r['contact'])?></td>
            <td><span class="uc <?=$ucmap[$uc]?>"><?=esc($r['urgency'])?></span></td>
            <td style="text-align:center;"><?=(int)($r['bags_needed']??1)?></td>
            <td style="font-family:monospace;font-size:.78em;color:var(--cyan);"><?=esc($r['delete_token']??'—')?></td>
            <td><span class="sp <?=strtolower(substr($r['status']??'Active',0,2))?>"><?=esc($r['status']??'Active')?></span></td>
            <td style="font-size:.77em;color:var(--muted);white-space:nowrap;"><?=$tm?></td>
            <td>
              <div style="display:flex;gap:4px;">
                <?php if(($r['status']??'')==='Active'):?><button class="btn bo" onclick="act('fulfill_req',<?=$r['id']?>,this,'rr<?=$r['id']?>')">✅</button><?php endif;?>
                <?php if($is_super):?><button class="btn bd" onclick="act('del_req',<?=$r['id']?>,this,'rr<?=$r['id']?>')">🗑</button><?php endif;?>
              </div>
            </td>
          </tr><?php endforeach; if(empty($requests)):?><tr><td colspan="12" class="empty">কোনো request নেই</td></tr><?php endif;?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- ══ REPORTS ══ -->
    <div class="tab" id="tab-reports">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">⚠️ Reports <span style="font-size:.65em;color:var(--muted);font-weight:400;">(<?=count($reports)?>)</span><button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="tbox">
        <div class="tbar"><h3>Harassment Reports</h3></div>
        <div class="bulk-bar" id="bulk-bar-reports" <?php if($is_moderator) echo 'style="display:none!important;"'; ?>>
          <span>✅ <strong id="reports-sel-count">0</strong> টি selected</span>
          <button class="bulk-del-btn" onclick="bulkDelete('reports')">🗑 Delete Selected</button>
          <button class="bulk-cancel-btn" onclick="clearSelection('reports')">✕ Cancel</button>
        </div>
        <div class="ow"><table>
          <thead><tr>
            <th class="cb-th"><input type="checkbox" id="cb-all-reports" onchange="toggleAll('reports',this.checked)"></th>
            <th>#</th><th>Donor Phone</th><th>Harasser Info</th><th>Comment</th><th>Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach($reports as $i=>$r):?><tr id="pr<?=$r['id']?>" class="reports-row">
            <td class="cb-td"><input type="checkbox" class="row-cb reports-cb" value="<?=$r['id']?>" onchange="onRowCbChange('reports')"></td>
            <td style="color:var(--muted);"><?=$i+1?></td>
            <td style="font-family:monospace;"><?=esc($r['donor_phone']??'')?></td>
            <td style="font-size:.81em;max-width:160px;"><?=esc($r['harasser_info']??'')?></td>
            <td style="font-size:.8em;color:var(--muted);max-width:220px;"><?=esc($r['report_comment']??'')?></td>
            <td><?php if($is_super):?><button class="btn bd" onclick="act('del_report',<?=$r['id']?>,this,'pr<?=$r['id']?>')">🗑</button><?php endif;?></td>
          </tr><?php endforeach; if(empty($reports)):?><tr><td colspan="6" class="empty">🕊️ কোনো report নেই</td></tr><?php endif;?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- ══ CALL LOGS ══ -->
    <div class="tab" id="tab-calls">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">📞 Call Logs <span style="font-size:.65em;color:var(--muted);font-weight:400;">(<?=count($calls)?>)</span><button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="tbox">
        <div class="tbar">
          <h3>Recent Calls</h3>
          <input class="srch" placeholder="🔍 Donor / Caller / IP..." oninput="ft('ctb',this.value)">
        </div>
        <div class="bulk-bar" id="bulk-bar-calls" <?php if($is_moderator) echo 'style="display:none!important;"'; ?>>
          <span>✅ <strong id="calls-sel-count">0</strong> টি selected</span>
          <button class="bulk-del-btn" onclick="bulkDelete('calls')">🗑 Delete Selected</button>
          <button class="bulk-cancel-btn" onclick="clearSelection('calls')">✕ Cancel</button>
        </div>
        <div class="ow"><table>
          <thead><tr>
            <th class="cb-th"><input type="checkbox" id="cb-all-calls" onchange="toggleAll('calls',this.checked)"></th>
            <th>#</th><th>Donor</th><th>Group</th><th>Caller Name</th><th>Caller Phone</th><th>IP</th><th>📍 Location</th><th>📱 Device</th><th>Time</th><th>Del</th>
          </tr></thead>
          <tbody id="ctb">
          <?php foreach($calls as $i=>$c):
            $tm=!empty($c['created_at'])?date('d M, h:i A',strtotime($c['created_at'])):'—';
            $loc=$c['caller_location']??'Not provided';
            $dev=$c['device_info']??'';
            $devShort='Unknown';
            if($dev){
                if(preg_match('/iPhone|iPad/i',$dev)) $devShort='📱 iOS';
                elseif(preg_match('/Android/i',$dev)){ preg_match('/Android\s[\d.]+.*?;\s([^;)]+)/i',$dev,$dm); $devShort='🤖 '.trim($dm[1]??'Android'); }
                elseif(preg_match('/Windows/i',$dev))  $devShort='🖥️ Windows';
                elseif(preg_match('/Macintosh|Mac OS/i',$dev)) $devShort='🍎 Mac';
                elseif(preg_match('/Linux/i',$dev))    $devShort='🐧 Linux';
                else $devShort=mb_substr($dev,0,30,'UTF-8');
                if(preg_match('/Chrome\/([\d.]+)/i',$dev)) $devShort.=' / Chrome';
                elseif(preg_match('/Firefox\/([\d.]+)/i',$dev)) $devShort.=' / Firefox';
                elseif(preg_match('/Safari\/([\d.]+)/i',$dev)&&!preg_match('/Chrome/i',$dev)) $devShort.=' / Safari';
            }
            $locShort=$loc==='Not provided'?'—':mb_substr($loc,0,50,'UTF-8');
            $locMapsUrl='';
            if($loc!=='Not provided'&&!empty($loc)){
                if(preg_match('/Lat:\s*([\-0-9.]+),\s*Lon:\s*([\-0-9.]+)/i',$loc,$lm)){
                    $locMapsUrl='https://www.google.com/maps?q='.$lm[1].','.$lm[2];
                } else {
                    $locMapsUrl='https://www.google.com/maps/search/'.urlencode($loc);
                }
            }
          ?><tr id="crow<?=$c['id']?>" class="calls-row">
            <td class="cb-td"><input type="checkbox" class="row-cb calls-cb" value="<?=$c['id']?>" onchange="onRowCbChange('calls')"></td>
            <td style="color:var(--muted);"><?=$i+1?></td>
            <td style="font-weight:600;white-space:nowrap;"><?=esc($c['donor_name']??'—')?></td>
            <td><span class="bg"><?=esc($c['blood_group']??'—')?></span></td>
            <td><?=esc($c['caller_name']??'')?></td>
            <td style="font-family:monospace;font-size:.8em;"><?=esc($c['caller_phone']??'')?></td>
            <td style="font-family:monospace;font-size:.74em;color:var(--muted);"><?=esc($c['caller_ip']??'—')?></td>
            <td><?php if($locMapsUrl):?><a href="<?=esc($locMapsUrl)?>" target="_blank" rel="noopener" class="loc-cell" title="<?=esc($loc)?>" style="color:var(--cyan);text-decoration:none;display:inline-block;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">📍 <?=esc($locShort)?><?=$loc!=='Not provided'&&strlen($loc)>50?'…':''?></a><?php else:?><span style="color:var(--muted);font-size:.74em;">—</span><?php endif;?></td>
            <td><div class="dev-cell" title="<?=esc($dev)?>"><?=esc($devShort)?></div></td>
            <td style="font-size:.77em;color:var(--muted);white-space:nowrap;"><?=$tm?></td>
            <td><?php if($is_super):?><button class="btn bd" onclick="act('del_call',<?=$c['id']?>,this,'crow<?=$c['id']?>')">🗑</button><?php endif;?></td>
          </tr><?php endforeach; if(empty($calls)):?><tr><td colspan="11" class="empty">কোনো call log নেই</td></tr><?php endif;?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- ══ SECRET CODE MANAGER ══ -->
    <div class="tab" id="tab-secrets">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">🔑 Secret Code Manager<button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="tbox">
        <div class="sc-search-bar">
          <input type="text" id="scName"  placeholder="🔍 নাম..." oninput="filterSecrets()">
          <input type="text" id="scPhone" placeholder="📞 ফোন..." oninput="filterSecrets()">
          <select id="scGroup" onchange="filterSecrets()">
            <option value="">All Groups</option>
            <?php foreach(["A+","A-","B+","B-","AB+","AB-","O+","O-"] as $g) echo "<option>$g</option>"; ?>
          </select>
          <select id="scBadge" onchange="filterSecrets()">
            <option value="">All Badges</option>
            <option>New</option><option>Active</option><option>Hero</option><option>Legend</option>
          </select>
          <button onclick="resetSecretFilters()">↺ Reset</button>
          <span id="scCount" style="font-size:.8em;color:var(--muted);align-self:center;margin-left:4px;"></span>
        </div>
        <div class="ow"><table>
          <thead><tr><th>#</th><th>Name</th><th>Group</th><th>Phone</th><th>Badge</th><th>Donations</th><th>Secret Code</th><th>Actions</th></tr></thead>
          <tbody id="scTable">
          <?php
          $badge_icons_sc=['Legend'=>'👑','Hero'=>'🦸','Active'=>'⭐','New'=>'🌱'];
          $sc_donors=[];
          if($conn){
            $scr=dbq($conn,"SELECT id,name,blood_group,phone,badge_level,total_donations,secret_code FROM donors ORDER BY id DESC LIMIT 500");
            if($scr) while($row=$scr->fetch_assoc()) $sc_donors[]=$row;
          }
          foreach($sc_donors as $i=>$d):
            $bp='bp-'.strtolower($d['badge_level']??'new');
            $bicon=$badge_icons_sc[$d['badge_level']??'New']??'🌱';
          ?><tr class="sc-row"
               data-name="<?=strtolower(esc($d['name']))?>"
               data-phone="<?=esc($d['phone']??'')?>"
               data-group="<?=esc($d['blood_group']??'')?>"
               data-badge="<?=esc($d['badge_level']??'New')?>">
            <td style="color:var(--muted);"><?=$i+1?></td>
            <td style="font-weight:600;"><?=esc($d['name'])?></td>
            <td><span class="bg"><?=esc($d['blood_group'])?></span></td>
            <td style="font-family:monospace;font-size:.79em;"><?=esc($d['phone']??'')?></td>
            <td><span class="badge-pill <?=$bp?>"><?=$bicon?> <?=esc($d['badge_level']??'New')?></span></td>
            <td style="text-align:center;"><?=(int)($d['total_donations']??0)?></td>
            <td>
              <?php if(!empty($d['secret_code'])):?>
                <span class="code-hidden" id="code-<?=$d['id']?>">●●●●●●●●●●●●●●●●●●</span>
              <?php else:?>
                <span style="color:var(--muted);font-size:.78em;font-style:italic;">No code</span>
              <?php endif;?>
            </td>
            <td style="display:flex;gap:5px;flex-wrap:wrap;">
              <?php if(!empty($d['secret_code'])):?>
                <button class="btn bc" onclick="revealSecret(<?=$d['id']?>,this)" title="Show code">👁 Reveal</button>
              <?php endif;?>
              <button class="btn bpu" onclick="resetSecret(<?=$d['id']?>,this,<?=json_encode($d['name'])?>)" title="Generate new code">🔄 Reset</button>
            </td>
          </tr><?php endforeach; if(empty($sc_donors)):?><tr><td colspan="8" class="empty">কোনো donor নেই</td></tr><?php endif;?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- ══ SECRET CODE REQUESTS ══ -->
    <div class="tab" id="tab-secretreqs">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">📩 Secret Code Requests <span style="font-size:.65em;color:var(--muted);font-weight:400;" id="scReqCountBadge"><?php if($pending_secret_reqs>0) echo "($pending_secret_reqs pending)"; ?></span><button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="tbox">
        <div class="tbar">
          <h3>Donor-Requested Code Resets</h3>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button onclick="loadSecretReqs()" style="padding:5px 14px;background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);color:var(--blue);border-radius:8px;font-size:.8em;cursor:pointer;">🔄 Refresh</button>
            <button onclick="deleteAllProcessedSecretReqs()" style="padding:5px 14px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--red);border-radius:8px;font-size:.8em;cursor:pointer;font-weight:700;">🗑 সব Processed মুছুন</button>
          </div>
        </div>
        <div class="ow"><table>
          <thead><tr><th>#</th><th>Donor Name</th><th>Phone</th><th>Ref Code</th><th>Device ID</th><th>IP</th><th>Status</th><th>Requested</th><th>Actions</th></tr></thead>
          <tbody id="scReqTable"><tr><td colspan="9" class="empty">⏳ Load করুন...</td></tr></tbody>
        </table></div>
      </div>
    </div>

    <!-- ══ NOTIFICATIONS ══ -->
    <div class="tab" id="tab-notifications">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">🔔 Notifications<button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>

      <!-- Bulk Notification -->
      <div class="settings-section">
        <h4>📢 Bulk Notification — সব Device</h4>
        <p style="font-size:.82em;color:var(--muted);margin-bottom:10px;">যেসব device এর কাছে আগে কোনো service notification গেছে বা push subscription আছে, সবাইকে একসাথে পাঠাবে।</p>
        <div style="display:grid;gap:8px;max-width:560px;">
          <div>
            <label style="font-size:.78em;color:var(--muted);display:block;margin-bottom:4px;">Notification Type</label>
            <select id="bulk_notif_type" style="padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;width:100%;">
              <option value="info">ℹ️ Info (General)</option>
              <option value="warning">⚠️ Warning</option>
              <option value="location_on">📍 Location চালু করুন</option>
              <option value="notif_on">🔔 Notification চালু করুন</option>
              <option value="secret_reset">🔑 Secret Code Reset</option>
            </select>
          </div>
          <div>
            <label style="font-size:.78em;color:var(--muted);display:block;margin-bottom:4px;">Message</label>
            <textarea id="bulk_notif_msg" rows="3" placeholder="সব user কে যে message পাঠাবেন..." style="width:100%;padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;resize:vertical;"></textarea>
          </div>
          <button onclick="sendBulkNotif()" style="padding:10px 20px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.88em;width:fit-content;">📢 Bulk Notification পাঠান</button>
          <div id="bulk_notif_result" style="display:none;font-size:.83em;padding:8px 12px;border-radius:8px;margin-top:4px;"></div>
        </div>
      </div>

      <!-- Auto Reminder Status -->
      <div class="settings-section">
        <h4>⏰ Auto Reminder — Not-Willing Donors (প্রতি ৩ দিন)</h4>
        <p style="font-size:.82em;color:var(--muted);margin-bottom:8px;">যেসব donor <strong>willing_to_donate = No</strong> কিন্তু device ID আছে, তাদের প্রতি ৩ দিন অন্তর স্বয়ংক্রিয়ভাবে একটি reminder notification পাঠানো হবে। Donor available হয়ে গেলে পরের poll cycle থেকে আর পাঠানো হবে না।</p>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
          <span style="font-size:.82em;padding:6px 14px;border-radius:20px;background:rgba(16,185,129,.12);color:var(--green);border:1px solid rgba(16,185,129,.25);">✅ Auto-enabled — polling চালু থাকলে কাজ করে</span>
          <button onclick="triggerReminderNow()" style="padding:7px 16px;background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3);border-radius:8px;font-size:.8em;font-weight:700;cursor:pointer;">🔔 এখনই Reminder পাঠান</button>
        </div>
        <div id="reminder_result" style="display:none;font-size:.82em;padding:8px 12px;border-radius:8px;margin-top:8px;"></div>
      </div>

      <!-- Specific Donor Notification -->
      <div class="settings-section">
        <h4>👤 Specific Donor — একজন Donor কে Notification</h4>
        <p style="font-size:.82em;color:var(--muted);margin-bottom:10px;">Donor এর phone number দিন। তাকে আগে কোনো Secret Code Request করতে হবে যাতে device ID পাওয়া যায়।</p>
        <div style="display:grid;gap:8px;max-width:560px;">
          <div>
            <label style="font-size:.78em;color:var(--muted);display:block;margin-bottom:4px;">Donor Phone Number</label>
            <input type="tel" id="donor_notif_phone" placeholder="+8801XXXXXXXXX" style="padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;width:100%;">
          </div>
          <div>
            <label style="font-size:.78em;color:var(--muted);display:block;margin-bottom:4px;">Notification Type</label>
            <select id="donor_notif_type" style="padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;width:100%;">
              <option value="info">ℹ️ Info</option>
              <option value="warning">⚠️ Warning</option>
              <option value="location_on">📍 Location চালু করুন</option>
              <option value="notif_on">🔔 Notification চালু করুন</option>
              <option value="secret_code_ready">✅ Secret Code Ready</option>
              <option value="secret_reset">🔑 Secret Code Reset</option>
            </select>
          </div>
          <div>
            <label style="font-size:.78em;color:var(--muted);display:block;margin-bottom:4px;">Message</label>
            <textarea id="donor_notif_msg" rows="3" placeholder="Donor কে যে message পাঠাবেন..." style="width:100%;padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;resize:vertical;"></textarea>
          </div>
          <button onclick="sendDonorNotif()" style="padding:10px 20px;background:var(--green);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.88em;width:fit-content;">📤 Notification পাঠান</button>
          <div id="donor_notif_result" style="display:none;font-size:.83em;padding:8px 12px;border-radius:8px;margin-top:4px;"></div>
        </div>
      </div>
      <!-- ── Scheduled Notifications ── -->
      <div class="settings-section">
        <h4>🗓️ Scheduled Notifications — Auto সময়মতো পাঠান</h4>
        <p style="font-size:.82em;color:var(--muted);margin-bottom:14px;">একটি নির্দিষ্ট সময়ে notification পাঠানোর schedule তৈরি করুন। Once, Daily, Weekly, Monthly — যেকোনো repeat সেট করা যাবে।</p>

        <!-- Create new schedule form -->
        <div style="background:var(--inp);border:1px solid var(--bdr);border-radius:10px;padding:16px;margin-bottom:16px;">
          <div style="font-size:.88em;font-weight:700;color:var(--text);margin-bottom:12px;">➕ নতুন Schedule তৈরি করুন</div>
          <div style="display:grid;gap:10px;max-width:560px;">
            <div>
              <label style="font-size:.76em;color:var(--muted);display:block;margin-bottom:4px;">Title (নিজের reference এর জন্য)</label>
              <input type="text" id="sch_title" placeholder="যেমন: Weekly Reminder" maxlength="100" style="width:100%;padding:8px 10px;background:var(--card);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div>
                <label style="font-size:.76em;color:var(--muted);display:block;margin-bottom:4px;">Notification Type</label>
                <select id="sch_type" style="width:100%;padding:8px 10px;background:var(--card);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
                  <option value="info">ℹ️ Info</option>
                  <option value="warning">⚠️ Warning</option>
                  <option value="location_on">📍 Location চালু করুন</option>
                  <option value="notif_on">🔔 Notification চালু করুন</option>
                  <option value="secret_reset">🔑 Secret Code Reset</option>
                </select>
              </div>
              <div>
                <label style="font-size:.76em;color:var(--muted);display:block;margin-bottom:4px;">Target</label>
                <select id="sch_target" onchange="toggleSchPhone()" style="width:100%;padding:8px 10px;background:var(--card);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
                  <option value="all">📢 সব Device</option>
                  <option value="not_willing">⛔ Not-Willing Donors</option>
                  <option value="donor">👤 Specific Donor</option>
                </select>
              </div>
            </div>
            <div id="sch_phone_wrap" style="display:none;">
              <label style="font-size:.76em;color:var(--muted);display:block;margin-bottom:4px;">Donor Phone</label>
              <input type="tel" id="sch_donor_phone" placeholder="+8801XXXXXXXXX" style="width:100%;padding:8px 10px;background:var(--card);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
            </div>
            <div id="sch_bg_wrap" style="display:none;">
              <label style="font-size:.76em;color:var(--muted);display:block;margin-bottom:4px;">🩸 Blood Group Filter <span style="font-weight:400;opacity:.7;">(শুধু এই group-এর not-willing donors পাবে)</span></label>
              <select id="sch_blood_group" style="width:100%;padding:8px 10px;background:var(--card);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
                <option value="All">🩸 সব Blood Group</option>
                <option value="A+">A+</option><option value="A-">A-</option>
                <option value="B+">B+</option><option value="B-">B-</option>
                <option value="AB+">AB+</option><option value="AB-">AB-</option>
                <option value="O+">O+</option><option value="O-">O-</option>
              </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div>
                <label style="font-size:.76em;color:var(--muted);display:block;margin-bottom:4px;">🗓️ Run Time</label>
                <input type="datetime-local" id="sch_run_at" style="width:100%;padding:8px 10px;background:var(--card);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
              </div>
              <div>
                <label style="font-size:.76em;color:var(--muted);display:block;margin-bottom:4px;">🔁 Repeat</label>
                <select id="sch_repeat" style="width:100%;padding:8px 10px;background:var(--card);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
                  <option value="once">একবার (Once)</option>
                  <option value="daily">প্রতিদিন (Daily)</option>
                  <option value="weekly">প্রতি সপ্তাহ (Weekly)</option>
                  <option value="monthly">প্রতি মাস (Monthly)</option>
                </select>
              </div>
            </div>
            <div>
              <label style="font-size:.76em;color:var(--muted);display:block;margin-bottom:4px;">Message</label>
              <textarea id="sch_message" rows="3" maxlength="500" placeholder="notification message লিখুন..." style="width:100%;padding:8px 10px;background:var(--card);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;resize:vertical;"></textarea>
              <div style="text-align:right;font-size:.72em;color:var(--muted);margin-top:2px;"><span id="sch_msg_count">0</span>/500</div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
              <button onclick="saveSchedule()" style="padding:9px 20px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.86em;">🗓️ Schedule Save করুন</button>
              <div id="sch_save_result" style="font-size:.82em;"></div>
            </div>
          </div>
        </div>

        <!-- Schedule list -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
          <div style="font-size:.88em;font-weight:700;color:var(--text);">📋 সব Schedules</div>
          <button onclick="loadSchedules()" style="padding:5px 12px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;font-size:.78em;color:var(--muted);cursor:pointer;">🔄 Refresh</button>
        </div>
        <div id="sch_list_wrap">
          <div style="text-align:center;padding:20px;color:var(--muted);font-size:.83em;">⏳ লোড হচ্ছে...</div>
        </div>
      </div>

    </div><!-- end tab-notifications -->
    <?php if($is_super):?>
    <div class="tab" id="tab-inbox">
      <div class="stitle">📬 User Messages (Inbox)
        <?php if($inbox_unread>0):?><span style="font-size:.6em;background:rgba(220,38,38,.15);color:var(--red);border-radius:20px;padding:2px 10px;margin-left:8px;font-weight:700;"><?=$inbox_unread?> unread</span><?php endif;?>
      </div>
      <div class="tbox">
        <div class="tbar" style="flex-wrap:wrap;gap:8px;">
          <h3>User Messages to Admin</h3>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button onclick="loadInbox('all')" id="ibf_all" class="inbox-filter-btn active" style="padding:5px 12px;border-radius:20px;background:rgba(59,130,246,.15);color:var(--blue);border:1px solid rgba(59,130,246,.3);font-size:.78em;cursor:pointer;">📬 All</button>
            <button onclick="loadInbox('unread')" id="ibf_unread" class="inbox-filter-btn" style="padding:5px 12px;border-radius:20px;background:var(--inp);color:var(--muted);border:1px solid var(--bdr);font-size:.78em;cursor:pointer;">🔴 Unread</button>
            <button onclick="loadInbox('replied')" id="ibf_replied" class="inbox-filter-btn" style="padding:5px 12px;border-radius:20px;background:var(--inp);color:var(--muted);border:1px solid var(--bdr);font-size:.78em;cursor:pointer;">✅ Replied</button>
            <button onclick="loadInbox(_inboxFilter)" style="padding:5px 12px;border-radius:20px;background:rgba(16,185,129,.12);color:var(--green);border:1px solid rgba(16,185,129,.25);font-size:.78em;cursor:pointer;">🔄 Refresh</button>
          </div>
        </div>
        <div id="inboxList" style="padding:8px 0;">
          <div style="text-align:center;padding:40px;color:var(--muted);font-size:.85em;">⏳ লোড হচ্ছে...</div>
        </div>
      </div>
    </div>

    <?php endif; // end inbox super only ?>

    <!-- ══ MODERATOR MANAGEMENT (Super Admin only) ══ -->
    <?php if($is_super):?>
    <div class="tab" id="tab-moderators">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">👥 Moderator Management<button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>

      <!-- Add Moderator -->
      <div class="settings-section">
        <h4>➕ নতুন Moderator / Admin যোগ করুন</h4>
        <p style="font-size:.82em;color:var(--muted);margin-bottom:12px;">Moderator: notification পাঠাতে ও secret code request approve/deny করতে পারবে। Delete করতে পারবে না, messages reply করতে পারবে না।</p>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;max-width:600px;margin-bottom:10px;">
          <input type="text" id="mod_uname" placeholder="Username (a-z 0-9 _-)" style="padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
          <input type="password" id="mod_pass" placeholder="Password (min 8 chars)" style="padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
          <select id="mod_role" style="padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;">
            <option value="moderator">🛡️ Moderator</option>
            <option value="super_admin">👑 Super Admin</option>
          </select>
        </div>
        <button onclick="addModerator()" style="padding:9px 20px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.85em;">➕ যোগ করুন</button>
        <div id="mod_result" style="display:none;font-size:.83em;padding:8px 12px;border-radius:8px;margin-top:8px;"></div>
      </div>

      <!-- List -->
      <div class="settings-section">
        <h4>📋 সব Users (<?php
          $mu_count=0;
          if($conn){ $mr=$conn->query("SELECT COUNT(*) c FROM admin_users"); if($mr) $mu_count=(int)$mr->fetch_assoc()['c']; }
          echo $mu_count;
        ?>)</h4>
        <div id="modList">
        <?php
        $mod_users=[];
        if($conn){ ensureAdminTables($conn); $mur=$conn->query("SELECT id,username,role,is_active,created_at FROM admin_users ORDER BY id DESC"); if($mur) while($r=$mur->fetch_assoc()) $mod_users[]=$r; }
        if(empty($mod_users)):?>
          <div style="text-align:center;padding:20px;color:var(--muted);font-size:.84em;">কোনো user নেই। উপরে যোগ করুন।</div>
        <?php else: foreach($mod_users as $mu):
          $mdate=!empty($mu['created_at'])?date('d M Y',strtotime($mu['created_at'])):'—';
          $mroleClr=$mu['role']==='super_admin'?'var(--red)':'var(--blue)';
          $mroleIcon=$mu['role']==='super_admin'?'👑':'🛡️';
        ?>
          <div class="token-row" id="modrow<?=$mu['id']?>" style="align-items:center;">
            <span style="font-weight:700;min-width:120px;"><?=esc($mu['username'])?></span>
            <span style="font-size:.8em;padding:2px 9px;border-radius:20px;background:rgba(255,255,255,.07);color:<?=$mroleClr?>;border:1px solid <?=$mroleClr?>33;"><?=$mroleIcon?> <?=esc($mu['role'])?></span>
            <span class="tok-date">📅 <?=$mdate?></span>
            <span style="font-size:.76em;padding:2px 8px;border-radius:20px;background:<?=$mu['is_active']?'rgba(16,185,129,.15)':'rgba(107,114,128,.15)'?>;color:<?=$mu['is_active']?'var(--green)':'var(--muted)'?>;"><?=$mu['is_active']?'✅ Active':'⛔ Inactive'?></span>
            <button class="tok-del" onclick="delModerator(<?=$mu['id']?>,this,'<?=esc($mu['username'])?>')">🗑 Remove</button>
          </div>
        <?php endforeach; endif;?>
        </div>
      </div>
    </div>
    <?php endif;?>

    <!-- ══ TOKEN MANAGER ══ -->
    <div class="tab" id="tab-tokens">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">🪙 Token Manager<button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="settings-section">
        <h4>➕ নতুন Token তৈরি</h4>
        <p>API token তৈরি করুন। Token দিয়ে external apps data access করতে পারবে।</p>
        <div class="token-add-row">
          <input type="text" id="newTokenName" placeholder="Token এর নাম (যেমন: App v1, Website API...)">
          <button onclick="addToken()">➕ Token তৈরি করুন</button>
        </div>
        <div id="tokenResult" style="font-size:.84em;margin-top:6px;display:none;"></div>
      </div>
      <div class="settings-section">
        <h4>📋 সব Tokens (<?=count($token_list)?>)</h4>
        <div id="tokenList">
        <?php if(empty($token_list)):?>
          <div style="text-align:center;padding:20px;color:var(--muted);font-size:.84em;">কোনো token নেই।</div>
        <?php else: foreach($token_list as $tok):
          $tActive=(int)($tok['is_active']??1);
          $tDate=!empty($tok['created_at'])?date('d M Y',strtotime($tok['created_at'])):'—';
          $tLast=!empty($tok['last_used'])?date('d M, h:i A',strtotime($tok['last_used'])):'কখনো না';
        ?>
          <div class="token-row" id="tokrow<?=$tok['id']?>">
            <span class="tok-name"><?=esc($tok['token_name'])?></span>
            <span class="tok-val"><?=esc($tok['token_value'])?></span>
            <span class="tok-status <?=$tActive?'tok-active':'tok-inactive'?>" id="tok-status-<?=$tok['id']?>"><?=$tActive?'✅ Active':'⛔ Inactive'?></span>
            <span class="tok-date">📅 <?=$tDate?></span>
            <button class="tok-copy" onclick="copyToken('<?=esc($tok['token_value'])?>', this)">📋 Copy</button>
            <button class="tok-toggle <?=$tActive?'active-btn':'inactive-btn'?>" id="tok-toggle-<?=$tok['id']?>" onclick="toggleToken(<?=$tok['id']?>)">
              <?=$tActive?'⏸ Disable':'▶ Enable'?>
            </button>
            <?php if($is_super):?><button class="tok-del" onclick="delToken(<?=$tok['id']?>)">🗑</button><?php endif;?>
          </div>
        <?php endforeach; endif;?>
        </div>
      </div>
    </div>

    <!-- ══ ADVANCED SEARCH ══ -->
    <div class="tab" id="tab-advsearch">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">🔍 Advanced Donor Search<button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="tbox">
        <div class="adv-grid">
          <div><label>নাম</label><input type="text" id="as_name" placeholder="যেকোনো নাম..."></div>
          <div><label>ফোন নম্বর</label><input type="text" id="as_phone" placeholder="+8801..."></div>
          <div><label>Blood Group</label>
            <select id="as_group">
              <option value="All">All Groups</option>
              <?php foreach(["A+","A-","B+","B-","AB+","AB-","O+","O-"] as $g) echo "<option>$g</option>"; ?>
            </select>
          </div>
          <div><label>Area / Location</label><input type="text" id="as_loc" placeholder="Mirpur, Dhaka..."></div>
          <div><label>Status</label>
            <select id="as_status">
              <option value="All">All Status</option>
              <option>Available</option><option>Not Available</option><option>Not Willing</option>
            </select>
          </div>
          <div><label>Badge Level</label>
            <select id="as_badge">
              <option value="All">All Badges</option>
              <option>New</option><option>Active</option><option>Hero</option><option>Legend</option>
            </select>
          </div>
          <div><label>Joined From</label><input type="date" id="as_from"></div>
          <div><label>Joined To</label><input type="date" id="as_to"></div>
        </div>
        <div class="adv-actions">
          <button class="adv-run" onclick="runAdvSearch()">🔍 Search</button>
          <button class="adv-clear" onclick="clearAdvSearch()">✕ Clear</button>
          <button class="adv-export" onclick="exportAdvCSV()" id="advExportBtn" style="display:none;">⬇️ CSV Export</button>
          <span class="adv-count" id="advCount"></span>
        </div>
        <div id="advResults">
          <div style="text-align:center;padding:40px;color:var(--muted);">
            <div style="font-size:2.5rem;margin-bottom:10px;">🔍</div>
            <p>উপরের filter দিয়ে donor খুঁজুন</p>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ AUDIT LOG ══ -->
    <div class="tab" id="tab-audit">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">📋 Audit Log<button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <div class="tbox">
        <div class="tbar"><h3>Admin Activity (Latest 50)</h3></div>
        <div class="ow"><table>
          <thead><tr><th>#</th><th>Event</th><th>IP</th><th>Detail</th><th>Time</th></tr></thead>
          <tbody>
          <?php foreach($audit_log as $i=>$a):
            $ev=strtolower($a['event']??'');
            $cls=str_contains($ev,'fail')||str_contains($ev,'bot')?'fail':(str_contains($ev,'success')||str_contains($ev,'login_s')?'success':'');
            $tm=!empty($a['created_at'])?date('d M, h:i A',strtotime($a['created_at'])):'—';
          ?><tr>
            <td style="color:var(--muted);"><?=$i+1?></td>
            <td><span class="audit-ev <?=$cls?>"><?=esc($a['event']??'')?></span></td>
            <td style="font-family:monospace;font-size:.8em;"><?=esc($a['ip']??'')?></td>
            <td style="font-size:.79em;color:var(--muted);"><?=esc($a['detail']??'')?></td>
            <td style="font-size:.77em;color:var(--muted);white-space:nowrap;"><?=$tm?></td>
          </tr><?php endforeach; if(empty($audit_log)):?><tr><td colspan="5" class="empty">কোনো log নেই</td></tr><?php endif;?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- ══ SETTINGS ══ -->
    <div class="tab" id="tab-settings">
      <div class="stitle" style="display:flex;align-items:center;justify-content:space-between;">⚙️ Settings<button class="tab-refresh-btn" onclick="location.reload()" title="Page reload করুন">🔄</button></div>
      <?php if($is_moderator):?>
      <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.25);border-radius:12px;padding:14px 18px;margin-bottom:16px;font-size:.85em;color:var(--blue);">
        🛡️ আপনি Moderator হিসেবে logged in। Settings পরিবর্তন শুধুমাত্র Super Admin করতে পারবে।
      </div>
      <?php endif;?>

      <!-- Browser Notifications (available to all roles) -->
      <div class="settings-section">
        <h4>🔔 Admin Browser Notifications</h4>
        <p style="font-size:.82em;color:var(--muted);margin-bottom:12px;">নতুন <strong>Inbox message</strong> বা <strong>Secret Code Request</strong> এলে browser notification আসবে — tab background এ থাকলেও কাজ করবে।</p>
        <div id="adminNotifStatus" style="margin-bottom:12px;padding:10px 14px;border-radius:10px;font-size:.83em;background:rgba(107,114,128,.1);border:1px solid var(--bdr);">
          ⏳ Notification status check করা হচ্ছে...
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <button id="adminNotifEnableBtn" onclick="adminEnableNotif()" style="padding:8px 18px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-size:.84em;font-weight:700;cursor:pointer;">🔔 Enable Notifications</button>
          <button id="adminNotifTestBtn" onclick="adminTestNotif()" style="padding:8px 14px;background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.3);border-radius:8px;font-size:.84em;cursor:pointer;display:none;">🧪 Test</button>
        </div>
        <div style="margin-top:12px;display:flex;align-items:center;gap:10px;">
          <span style="font-size:.82em;color:var(--muted);">Auto-polling</span>
          <button class="toggle-btn" id="adminPollToggle" onclick="toggleAdminPoll(this)" title="Polling on/off"></button>
          <span style="font-size:.76em;color:var(--muted);" id="adminPollStatus">প্রতি ৩০s এ check করে</span>
        </div>
      </div>

      <!-- IP Whitelist -->
      <div class="settings-section" <?php if($is_moderator) echo 'style="opacity:.55;pointer-events:none;"'; ?>>
        <h4>🔒 IP Whitelist <?php if($is_moderator) echo '<span style="font-size:.72em;color:var(--muted);">(Super Admin only)</span>'; ?></h4>
        <p>শুধুমাত্র whitelist করা IP গুলো এই admin panel access করতে পারবে। <strong style="color:var(--red);">⚠️ Enable করার আগে অবশ্যই নিজের IP add করুন।</strong></p>
        <div class="ip-mine">
          আপনার বর্তমান IP: <code><?=esc($currentIP)?></code>
          <button onclick="addMyIP()" style="margin-left:10px;padding:3px 10px;background:rgba(59,130,246,.15);color:var(--blue);border:1px solid rgba(59,130,246,.3);border-radius:6px;font-size:.8em;cursor:pointer;">➕ আমার IP Add করুন</button>
        </div>
        <div class="toggle-row">
          <span>IP Whitelist <?=$ip_whitelist_enabled?'<span style="color:var(--green);font-size:.8em;margin-left:6px;">● Enabled</span>':'<span style="color:var(--muted);font-size:.8em;margin-left:6px;">● Disabled</span>'?></span>
          <button class="toggle-btn <?=$ip_whitelist_enabled?'on':''?>" id="ipWlToggleBtn" onclick="toggleIpWhitelist(this)"></button>
        </div>
        <div class="ip-add-row">
          <input type="text" id="newIpAddr" placeholder="IP Address (যেমন: 192.168.1.1)">
          <input type="text" id="newIpLabel" placeholder="Label (যেমন: Office PC)" style="max-width:200px;">
          <button onclick="addIp()">➕ IP Add করুন</button>
        </div>
        <div id="ipList">
          <?php if(empty($ip_list)):?>
            <div style="text-align:center;padding:16px;color:var(--muted);font-size:.84em;">কোনো IP whitelist করা নেই।</div>
          <?php else: foreach($ip_list as $ip): ?>
            <div class="ip-tag" id="iptag<?=$ip['id']?>">
              <span class="ip-addr"><?=esc($ip['ip'])?></span>
              <span class="ip-label"><?=esc($ip['label']??'')?></span>
              <span class="ip-badge <?=($ip['is_active']??1)?'active':'inactive'?>"><?=($ip['is_active']??1)?'✅ Active':'⛔ Off'?></span>
              <?php if($is_super):?><button class="ip-del-btn" onclick="delIp(<?=$ip['id']?>)">🗑 Remove</button><?php endif;?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Password Change -->
      <?php if($is_super):?>
      <div class="settings-section">
        <h4>🔑 Admin Password পরিবর্তন</h4>
        <p>এখান থেকে admin panel এর password পরিবর্তন করুন। নতুন password কমপক্ষে ৮ অক্ষরের হতে হবে।</p>
        <div class="pw-form" style="max-width:400px;">
          <div>
            <label>বর্তমান Password</label>
            <input type="password" id="pw_current" placeholder="বর্তমান password লিখুন" autocomplete="current-password">
          </div>
          <div>
            <label>নতুন Password</label>
            <input type="password" id="pw_new" placeholder="নতুন password (min 8 chars)" autocomplete="new-password">
          </div>
          <div>
            <label>নতুন Password নিশ্চিত করুন</label>
            <input type="password" id="pw_confirm" placeholder="আবার নতুন password লিখুন" autocomplete="new-password">
          </div>
          <button class="pw-save-btn" id="pwSaveBtn" onclick="changePassword()">🔑 Password পরিবর্তন করুন</button>
          <div class="pw-result" id="pwResult"></div>
        </div>
      </div>
      <?php endif;?>
    </div>

    <div class="admin-footer"><span>🩸 © 2026 Siam Innovatives — All Rights Reserved.</span></div>

  </main>
</div><!-- /.layout -->

<!-- ══ EDIT DONOR MODAL ══ -->
<div class="modal-overlay" id="editDonorModal" style="display:none;" onclick="if(event.target===this)closeEditModal()">
  <div class="edit-modal">
    <h3>✏️ Edit Donor Info</h3>
    <input type="hidden" id="edit_donor_id" value="">
    <input type="hidden" id="edit_reg_geo" value="">
    <div class="ef-row">
      <div>
        <label class="ef-label">Full Name</label>
        <input type="text" class="ef-input" id="edit_name" placeholder="নাম লিখুন">
      </div>
      <div>
        <label class="ef-label">Phone</label>
        <input type="text" class="ef-input" id="edit_phone" placeholder="+880XXXXXXXXXX">
      </div>
    </div>
    <div class="ef-row">
      <div>
        <label class="ef-label">Blood Group</label>
        <select class="ef-input" id="edit_blood_group">
          <option>A+</option><option>A-</option><option>B+</option><option>B-</option>
          <option>AB+</option><option>AB-</option><option>O+</option><option>O-</option>
        </select>
      </div>
      <div>
        <label class="ef-label">Total Donations</label>
        <input type="number" class="ef-input" id="edit_total_donations" min="0" max="999" placeholder="0" oninput="updateEditBadgePreview(this.value)">
        <div id="editBadgePreview" style="margin-top:5px;font-size:.78em;font-weight:700;color:var(--muted);"></div>
      </div>
    </div>
    <div class="ef-row full">
      <div>
        <label class="ef-label">Location</label>
        <div style="display:flex;gap:6px;align-items:center;">
          <input type="text" class="ef-input" id="edit_location" placeholder="এলাকা / ঠিকানা" style="margin:0;flex:1;">
          <button class="ef-map-btn" onclick="openEditMapPicker()" title="Map থেকে বেছে নিন">🗺️</button>
        </div>
        <div class="ef-geo-status" id="editGeoStatus">📍 Geo location saved</div>
      </div>
    </div>
    <div class="ef-row">
      <div>
        <label class="ef-label">Last Donation Date</label>
        <div style="display:flex;gap:6px;align-items:center;">
          <input type="date" class="ef-input" id="edit_last_date" style="flex:1;" onchange="syncEditDate(this.value)">
          <button onclick="clearEditDate()" style="padding:7px 10px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,38,.3);color:var(--red);border-radius:8px;cursor:pointer;font-size:.8em;flex-shrink:0;white-space:nowrap;">✕ Never</button>
        </div>
        <input type="hidden" id="edit_last_donation" value="no">
        <div style="font-size:.7em;color:var(--muted);margin-top:2px;" id="editDateDisplay">Never donated</div>
      </div>
      <div>
        <label class="ef-label">রক্ত দিতে ইচ্ছুক?</label>
        <div class="ef-toggle">
          <button id="ef_yes_btn" class="on-yes" onclick="setEditWilling('yes')">✅ হ্যাঁ</button>
          <button id="ef_no_btn"  onclick="setEditWilling('no')">⛔ না</button>
        </div>
        <input type="hidden" id="edit_willing" value="yes">
      </div>
    </div>
    <button class="ef-save" id="editSaveBtn" onclick="saveEditDonor()">💾 Save Changes</button>
    <button class="ef-cancel" onclick="closeEditModal()">Cancel</button>
  </div>
</div>

<script>
const CSRF = '<?=esc($CSRF)?>';
const IDLE_LIMIT = <?=SESSION_IDLE_LIMIT?>;
const IS_SUPER = <?=$is_super?'true':'false'?>;
const IS_MODERATOR = <?=$is_moderator?'true':'false'?>;
let lastActivity = Date.now();
let _advRows = [];

// Client-side guard — belt + suspenders on top of server-side check
function guardSuper(label) {
    if (!IS_SUPER) {
        alert('🚫 ' + (label||'এই কাজটি') + ' শুধুমাত্র Super Admin করতে পারবে।');
        return false;
    }
    return true;
}

// ── Tab switch ──────────────────────────────────────────
function go(name, el) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('on'));
    document.querySelectorAll('.ni, .mt').forEach(n => n.classList.remove('on'));
    const t = document.getElementById('tab-' + name);
    if(t) t.classList.add('on');
    if(el) el.classList.add('on');
    document.querySelectorAll(`.ni[onclick*="'${name}'"], .mt[onclick*="'${name}'"]`).forEach(n => n.classList.add('on'));
}

// ── Table text filter ───────────────────────────────────
function ft(id, q) {
    const tb = document.getElementById(id); if(!tb) return;
    q = q.toLowerCase();
    tb.querySelectorAll('tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

// ── Toast notification ──────────────────────────────────
function showToast(msg, color='#10b981'){
    const t=document.createElement('div');
    t.style.cssText=`position:fixed;top:60px;right:16px;background:${color};color:#fff;padding:10px 18px;border-radius:10px;font-weight:700;font-size:.88em;z-index:9999;box-shadow:0 4px 15px rgba(0,0,0,.4);`;
    t.textContent=msg; document.body.appendChild(t); setTimeout(()=>t.remove(),3000);
}

// ── CRUD AJAX ───────────────────────────────────────────
function act(action, id, btn, rowId) {
    const msgs = {
        del_donor:   '⚠️ এই Donor permanently delete হবে। নিশ্চিত?',
        del_req:     '🗑 এই Request permanently delete করবেন?',
        del_report:  '🗑 এই Report permanently delete করবেন?',
        del_call:    '🗑 এই Call Log permanently delete করবেন?',
        fulfill_req: '✅ Fulfilled mark করবেন?'
    };
    if(!confirm(msgs[action]||'Delete করবেন?')) return;
    btn.disabled=true; btn.textContent='⏳';
    const fd=new FormData();
    fd.append('act',action); fd.append('id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(d=>{
        if(d.ok){
            const row=document.getElementById(rowId)||btn.closest('tr');
            if(row){row.style.opacity='.3';row.style.transition='opacity .3s';setTimeout(()=>row.remove(),300);}
        } else {
            btn.disabled=false;
            btn.textContent=action.includes('del')?'🗑':'✅';
            alert('❌ '+(d.msg||'Action failed'));
        }
    }).catch(e=>{btn.disabled=false;btn.textContent='❌';alert('Network error: '+e.message);});
}

// ── Checkbox / Bulk Delete ──────────────────────────────
function toggleAll(table, checked) {
    document.querySelectorAll(`.${table}-cb`).forEach(cb => cb.checked = checked);
    updateBulkBar(table);
}

function onRowCbChange(table){
    updateBulkBar(table);
    const all = document.querySelectorAll(`.${table}-cb`);
    const checked = document.querySelectorAll(`.${table}-cb:checked`);
    const masterCb = document.getElementById(`cb-all-${table}`);
    if(masterCb) masterCb.indeterminate = checked.length > 0 && checked.length < all.length;
    if(masterCb) masterCb.checked = all.length > 0 && checked.length === all.length;
}

function updateBulkBar(table){
    const count = document.querySelectorAll(`.${table}-cb:checked`).length;
    const bar = document.getElementById(`bulk-bar-${table}`);
    const countEl = document.getElementById(`${table}-sel-count`);
    if(bar) bar.classList.toggle('show', count > 0);
    if(countEl) countEl.textContent = count;
}

function clearSelection(table){
    document.querySelectorAll(`.${table}-cb`).forEach(cb => cb.checked = false);
    const masterCb = document.getElementById(`cb-all-${table}`);
    if(masterCb){ masterCb.checked = false; masterCb.indeterminate = false; }
    updateBulkBar(table);
}

function bulkDelete(table){
    if(!guardSuper("Bulk Delete")) return;
    const checked = document.querySelectorAll(`.${table}-cb:checked`);
    if(!checked.length){ alert('কোনো row select করা হয়নি।'); return; }
    const ids = Array.from(checked).map(cb => cb.value);
    if(!confirm(`⚠️ ${ids.length} টি item permanently delete হবে। নিশ্চিত?`)) return;

    const fd = new FormData();
    fd.append('act','del_multiple'); fd.append('table',table);
    fd.append('ids',ids.join(',')); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            // Remove rows
            const rowPrefixes = {donors:'drow',requests:'rr',reports:'pr',calls:'crow'};
            ids.forEach(id=>{
                const row = document.getElementById((rowPrefixes[table]||table)+id);
                if(row){ row.style.opacity='.2'; setTimeout(()=>row.remove(),250); }
            });
            clearSelection(table);
            showToast(`✅ ${d.deleted} টি item delete হয়েছে।`);
        } else { alert('❌ '+(d.msg||'Bulk delete failed')); }
    }).catch(e=>alert('Network error: '+e.message));
}

// ── SECRET CODE: Client-side filter ─────────────────────
function filterSecrets() {
    const name=document.getElementById('scName').value.toLowerCase();
    const phone=document.getElementById('scPhone').value.toLowerCase();
    const group=document.getElementById('scGroup').value;
    const badge=document.getElementById('scBadge').value;
    let visible=0;
    document.querySelectorAll('.sc-row').forEach(tr=>{
        const ok=(!name||tr.dataset.name.includes(name))&&(!phone||tr.dataset.phone.includes(phone))&&(!group||tr.dataset.group===group)&&(!badge||tr.dataset.badge===badge);
        tr.style.display=ok?'':'none'; if(ok) visible++;
    });
    const cnt=document.getElementById('scCount'); if(cnt) cnt.textContent=visible+' টি দেখাচ্ছে';
}

// ── INBOX ─────────────────────────────────────────────────
var _inboxFilter='all';
function loadInbox(filter){
    _inboxFilter=filter||'all';
    ['all','unread','replied'].forEach(function(f){
        var btn=document.getElementById('ibf_'+f);
        if(!btn) return;
        if(f===_inboxFilter){
            btn.style.background='rgba(59,130,246,.2)'; btn.style.color='var(--blue)'; btn.style.borderColor='rgba(59,130,246,.5)';
        } else {
            btn.style.background='var(--inp)'; btn.style.color='var(--muted)'; btn.style.borderColor='var(--bdr)';
        }
    });
    const list=document.getElementById('inboxList');
    if(!list) return;
    list.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted);">⏳ লোড হচ্ছে...</div>';
    const fd=new FormData(); fd.append('act','get_inbox'); fd.append('filter',_inboxFilter); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok){ list.innerHTML='<div style="text-align:center;padding:30px;color:var(--red);">❌ Load করতে সমস্যা।</div>'; return; }
        if(!d.rows.length){ list.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted);font-size:.85em;">📭 কোনো message নেই</div>'; return; }

        // Clear all button
        var clearHtml = `<div style="display:flex;justify-content:flex-end;margin-bottom:10px;">
          <button onclick="clearInbox('${_inboxFilter}')" style="padding:5px 14px;background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.25);border-radius:20px;font-size:.78em;font-weight:700;cursor:pointer;">🗑 সব মুছুন</button>
        </div>`;

        list.innerHTML = clearHtml + d.rows.map(function(m){
            const isUnread=!m.is_read && !m.admin_reply;
            const bdr=isUnread?'rgba(59,130,246,.4)':'var(--bdr)';
            const bg=isUnread?'rgba(59,130,246,.05)':'var(--card)';
            const tm=m.created_at||'—';
            const replyHtml=m.admin_reply
                ?`<div style="margin-top:8px;padding:8px 12px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:8px;font-size:.8em;color:var(--green);">✅ Reply: ${escHtml(m.admin_reply)}<span style="color:var(--muted);margin-left:8px;font-size:.85em;">${m.replied_at||''}</span></div>`
                :'';
            const replyFormHtml=!m.admin_reply?`
                <div style="margin-top:10px;display:flex;gap:6px;" id="replyform_${m.id}">
                  <textarea id="reply_${m.id}" rows="2" placeholder="Reply লিখুন..." style="flex:1;padding:8px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.83em;resize:none;font-family:sans-serif;"></textarea>
                  <div style="display:flex;flex-direction:column;gap:4px;">
                    <button onclick="sendInboxReply(${m.id})" style="padding:6px 12px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-size:.78em;font-weight:700;cursor:pointer;white-space:nowrap;">📤 Reply</button>
                    <button onclick="markInboxRead(${m.id},this)" style="padding:6px 12px;background:var(--inp);color:var(--muted);border:1px solid var(--bdr);border-radius:8px;font-size:.76em;cursor:pointer;white-space:nowrap;">✓ Read</button>
                  </div>
                </div>`:'';
            return `<div id="inbox_${m.id}" style="margin-bottom:10px;padding:14px 16px;background:${bg};border:1px solid ${bdr};border-radius:12px;">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                <div>
                  <span style="font-weight:700;font-size:.9em;">${escHtml(m.sender_name)}</span>
                  <span style="font-family:monospace;font-size:.78em;color:var(--muted);margin-left:8px;">${escHtml(m.sender_phone)}</span>
                  ${isUnread?'<span style="margin-left:6px;font-size:.7em;background:rgba(59,130,246,.2);color:var(--blue);padding:1px 7px;border-radius:20px;font-weight:700;">NEW</span>':''}
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                  <span style="font-size:.74em;color:var(--muted);white-space:nowrap;">${tm}</span>
                  <button onclick="delInboxMsg(${m.id})" title="Delete" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:var(--red);border-radius:8px;padding:3px 8px;font-size:.76em;cursor:pointer;min-height:unset;box-shadow:none;margin:0;">🗑</button>
                </div>
              </div>
              <div style="margin-top:8px;font-size:.85em;color:var(--text);line-height:1.55;white-space:pre-wrap;">${escHtml(m.message)}</div>
              ${replyHtml}
              ${replyFormHtml}
            </div>`;
        }).join('');

        // Update sidebar badge
        const sideEl=document.querySelector('.ni[onclick*="inbox"] .cnt');
        if(d.unread>0){
            if(sideEl) sideEl.textContent=d.unread;
        } else {
            if(sideEl) sideEl.remove();
        }
    }).catch(function(){ list.innerHTML='<div style="text-align:center;padding:30px;color:var(--red);">❌ Network error।</div>'; });
}

function sendInboxReply(msgId){
    const txt=(document.getElementById('reply_'+msgId)||{}).value||'';
    if(!txt.trim()){ alert('Reply লিখুন।'); return; }
    const fd=new FormData(); fd.append('act','reply_inbox_msg'); fd.append('id',msgId); fd.append('reply',txt.trim()); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){ showToast(d.msg,'#10b981'); loadInbox(_inboxFilter); }
        else { alert('❌ '+(d.msg||'Error')); }
    }).catch(()=>alert('Network error'));
}

function delInboxMsg(id){
    if(!confirm('🗑 এই message টি মুছে ফেলবেন?')) return;
    const row=document.getElementById('inbox_'+id);
    if(row){ row.style.opacity='0.4'; row.style.pointerEvents='none'; }
    const fd=new FormData(); fd.append('act','del_inbox_msg'); fd.append('id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            if(row){ row.style.transition='all .3s'; row.style.maxHeight='0'; row.style.overflow='hidden'; row.style.margin='0'; row.style.padding='0';
                setTimeout(()=>row.remove(),300); }
            showToast('✅ Message মুছে ফেলা হয়েছে।');
        } else {
            if(row){ row.style.opacity='1'; row.style.pointerEvents=''; }
            alert('❌ '+(d.msg||'Error'));
        }
    }).catch(()=>{ if(row){row.style.opacity='1';row.style.pointerEvents='';} alert('Network error'); });
}

function clearInbox(filter){
    const filterLabel = filter==='all'?'সব message':'এই filter এর সব message';
    if(!confirm('🗑 '+filterLabel+' মুছে ফেলবেন? এটি undo করা যাবে না।')) return;
    const fd=new FormData(); fd.append('act','clear_inbox'); fd.append('filter',filter||'all'); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){ showToast(d.msg,'#10b981'); loadInbox(_inboxFilter); }
        else { alert('❌ '+(d.msg||'Error')); }
    }).catch(()=>alert('Network error'));
}

function markInboxRead(msgId,btn){
    const fd=new FormData(); fd.append('act','mark_inbox_read'); fd.append('id',msgId); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            const row=document.getElementById('inbox_'+msgId);
            if(row){ row.style.border='1px solid var(--bdr)'; row.style.background='var(--card)'; }
            const form=document.getElementById('replyform_'+msgId);
            if(form) form.remove();
            if(btn) btn.remove();
        }
    }).catch(()=>{});
}

// ── MODERATOR MANAGEMENT ──────────────────────────────────
function addModerator(){
    const uname=(document.getElementById('mod_uname').value||'').trim();
    const pass=(document.getElementById('mod_pass').value||'').trim();
    const role=document.getElementById('mod_role').value;
    const res=document.getElementById('mod_result');
    if(!uname||!pass){ alert('Username ও Password দিন।'); return; }
    const fd=new FormData(); fd.append('act','add_moderator'); fd.append('uname',uname); fd.append('pass',pass); fd.append('role',role); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        res.style.display='block';
        if(d.ok){
            res.style.background='rgba(16,185,129,.12)'; res.style.color='var(--green)';
            res.textContent='✅ '+uname+' ('+role+') যোগ করা হয়েছে।';
            document.getElementById('mod_uname').value=''; document.getElementById('mod_pass').value='';
            // Add row to list
            const list=document.getElementById('modList');
            const empty=list.querySelector('div[style*="text-align:center"]');
            if(empty) empty.remove();
            const roleClr=d.role==='super_admin'?'var(--red)':'var(--blue)';
            const roleIcon=d.role==='super_admin'?'👑':'🛡️';
            const row=document.createElement('div');
            row.className='token-row'; row.id='modrow'+d.id;
            row.style.alignItems='center';
            row.innerHTML=`<span style="font-weight:700;min-width:120px;">${escHtml(d.username)}</span>
              <span style="font-size:.8em;padding:2px 9px;border-radius:20px;background:rgba(255,255,255,.07);color:${roleClr};border:1px solid ${roleClr}33;">${roleIcon} ${escHtml(d.role)}</span>
              <span class="tok-date">📅 ${d.created_at||'—'}</span>
              <span style="font-size:.76em;padding:2px 8px;border-radius:20px;background:rgba(16,185,129,.15);color:var(--green);">✅ Active</span>
              <button class="tok-del" onclick="delModerator(${d.id},this,'${escHtml(d.username)}')">🗑 Remove</button>`;
            list.prepend(row);
            showToast('✅ '+d.username+' যোগ হয়েছে!');
        } else {
            res.style.background='rgba(239,68,68,.12)'; res.style.color='var(--red)';
            res.textContent='❌ '+(d.msg||'Error');
        }
    }).catch(()=>{ res.style.display='block'; res.textContent='❌ Network error'; });
}

function delModerator(id,btn,uname){
    if(!confirm('🗑 "'+uname+'" কে remove করবেন?')) return;
    const fd=new FormData(); fd.append('act','del_moderator'); fd.append('id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){ const row=document.getElementById('modrow'+id); if(row) row.remove(); showToast('✅ Remove হয়েছে।'); }
        else { alert('❌ '+(d.msg||'Error')); }
    }).catch(()=>alert('Network error'));
}

// ── Auto-load inbox when tab opened ──────────────────────
(function(){
    const orig=window.go;
    window.go=function(tab,el){
        orig(tab,el);
        if(tab==='inbox' && IS_SUPER) loadInbox(_inboxFilter);
    };
})();

function resetSecretFilters(){
    ['scName','scPhone'].forEach(id=>document.getElementById(id).value='');
    ['scGroup','scBadge'].forEach(id=>document.getElementById(id).value='');
    filterSecrets();
}

// ── SECRET CODE: Reveal ──────────────────────────────────
function revealSecret(id, btn){
    btn.disabled=true; btn.textContent='⏳';
    const fd=new FormData(); fd.append('act','reveal_secret'); fd.append('id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false; btn.innerHTML='👁 Reveal';
        if(d.ok){
            document.getElementById('modalTitle').textContent='🔑 Secret Code — Revealed';
            document.getElementById('modalCode').textContent=d.code;
            document.getElementById('modalDonor').textContent='Donor: '+d.name;
            document.getElementById('codeModal').style.display='flex';
            const span=document.getElementById('code-'+id);
            if(span){span.className='code-visible';span.textContent=d.code;}
        } else {alert(d.msg||'Error');}
    }).catch(()=>{btn.disabled=false;btn.innerHTML='👁 Reveal';alert('Network error');});
}

// ── SECRET CODE: Reset ───────────────────────────────────
function resetSecret(id,btn,donorName){
    if(!confirm('🔄 '+donorName+' এর Secret Code reset করবেন? পুরনো code আর কাজ করবে না!')) return;
    btn.disabled=true; btn.textContent='⏳';
    const fd=new FormData(); fd.append('act','reset_secret'); fd.append('id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false; btn.innerHTML='🔄 Reset';
        if(d.ok){
            document.getElementById('modalTitle').textContent='✅ নতুন Secret Code';
            document.getElementById('modalCode').textContent=d.code;
            const notifNote = d.auto_notified
                ? '✅ Donor এর Services notification এ পাঠানো হয়েছে।'
                : 'Donor: '+donorName+' — নতুন code পাঠিয়ে দিন';
            document.getElementById('modalDonor').textContent=notifNote;
            document.getElementById('codeModal').style.display='flex';
            const span=document.getElementById('code-'+id);
            if(span){span.className='code-visible';span.textContent=d.code;}
            if(d.auto_notified) showToast('🔔 Donor কে auto-notification পাঠানো হয়েছে!','#10b981');
        } else {alert(d.msg||'Error');}
    }).catch(()=>{btn.disabled=false;btn.innerHTML='🔄 Reset';alert('Network error');});
}

// ── SECRET CODE REQUESTS TAB ──────────────────────────────
function loadSecretReqs(){
    const tb=document.getElementById('scReqTable');
    tb.innerHTML='<tr><td colspan="9" class="empty">⏳ লোড হচ্ছে...</td></tr>';
    const fd=new FormData(); fd.append('act','get_secret_requests'); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok||!d.rows.length){ tb.innerHTML='<tr><td colspan="9" class="empty">কোনো request নেই</td></tr>'; return; }
        const statusColor={'pending':'#f59e0b','approved':'#10b981','denied':'#ef4444','ref_expired':'#6b7280'};
        tb.innerHTML=d.rows.map((r,i)=>`
            <tr id="scr_${r.id}">
              <td style="color:var(--muted);">${i+1}</td>
              <td style="font-weight:600;">${escHtml(r.donor_name||'—')}</td>
              <td style="font-family:monospace;font-size:.8em;">${escHtml(r.donor_number)}</td>
              <td style="font-family:monospace;font-size:.8em;color:var(--cyan);">${escHtml(r.ref_code)}</td>
              <td style="font-family:monospace;font-size:.72em;color:var(--muted);max-width:120px;overflow:hidden;text-overflow:ellipsis;" title="${escHtml(r.device_id)}">${escHtml(r.device_id.substr(0,18))}…</td>
              <td style="font-family:monospace;font-size:.74em;color:var(--muted);">${escHtml(r.req_ip||'—')}</td>
              <td>
                <span style="padding:2px 9px;border-radius:20px;font-size:.76em;font-weight:700;background:${statusColor[r.status]||'#888'}22;color:${statusColor[r.status]||'#888'};">${r.status}</span>
                ${r.status==='approved'?`<span style="font-size:.7em;color:var(--muted);display:block;margin-top:2px;">👁 ${r.view_count||0}/3 views</span>`:''}
                ${r.status==='ref_expired'?`<span style="font-size:.7em;color:var(--muted);display:block;margin-top:2px;">⛔ 3/3 used</span>`:''}
              </td>
              <td style="font-size:.75em;color:var(--muted);white-space:nowrap;">${r.created_at||'—'}</td>
              <td>
                ${r.status==='pending'?`
                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                  <button class="btn bo" onclick="resolveSecretReq(${r.id},'approve')" title="Approve">✅ Approve</button>
                  <button class="btn bd" onclick="resolveSecretReq(${r.id},'deny')" title="Deny">❌ Deny</button>
                </div>`:`
                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                  <span style="font-size:.75em;color:var(--muted);">Processed</span>
                  <button class="btn bd" style="padding:3px 8px;font-size:.72em;" onclick="deleteSecretReq(${r.id})" title="Delete">🗑</button>
                </div>`}
              </td>
            </tr>`).join('');
    }).catch(()=>{ tb.innerHTML='<tr><td colspan="9" class="empty">❌ Load করতে সমস্যা হয়েছে।</td></tr>'; });
}

function resolveSecretReq(reqId, action){
    const label = action==='approve'?'Approve করে নতুন code দেবেন?':'Deny করবেন?';
    if(!confirm('⚠️ '+label+' Donor কে notification যাবে।')) return;
    const note = action==='deny' ? (prompt('Admin Note (optional):')||'') : '';
    const fd=new FormData();
    fd.append('act','resolve_secret_request');
    fd.append('req_id',reqId);
    fd.append('action',action);
    fd.append('admin_note',note);
    fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            showToast(d.msg,'#10b981');
            if(action==='approve'&&d.new_code){
                document.getElementById('modalTitle').textContent='✅ নতুন Secret Code';
                document.getElementById('modalCode').textContent=d.new_code;
                document.getElementById('modalDonor').textContent='Donor notification পাঠানো হয়েছে।';
                document.getElementById('codeModal').style.display='flex';
            }
            loadSecretReqs();
        } else { alert('❌ '+(d.msg||'Error')); }
    }).catch(()=>alert('Network error'));
}

function deleteSecretReq(reqId){
    if(!confirm('এই processed request মুছে ফেলবেন?')) return;
    const fd=new FormData();
    fd.append('act','delete_secret_request');
    fd.append('req_id',reqId);
    fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){ showToast(d.msg,'#10b981'); loadSecretReqs(); }
        else { showToast(d.msg||'❌ Failed','#ef4444'); }
    }).catch(()=>showToast('❌ Network error','#ef4444'));
}

function deleteAllProcessedSecretReqs(){
    if(!confirm('⚠️ সব Approved / Denied / Expired request মুছে ফেলবেন?\n\nPending requests মুছবে না।')) return;
    const fd=new FormData();
    fd.append('act','delete_all_processed_secret_requests');
    fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){ showToast(d.msg,'#10b981'); loadSecretReqs(); }
        else { showToast(d.msg||'❌ Failed','#ef4444'); }
    }).catch(()=>showToast('❌ Network error','#ef4444'));
}

// ── NOTIFICATIONS ─────────────────────────────────────────
function triggerReminderNow(){
    var res = document.getElementById('reminder_result');
    res.style.display='block'; res.textContent='⏳ পাঠানো হচ্ছে...'; res.style.color='var(--muted)'; res.style.background='var(--inp)';
    // Force reset last run time so it runs immediately
    var fd=new FormData(); fd.append('act','run_auto_reminder'); fd.append('force','1'); fd.append('csrf',CSRF);
    // Temporarily patch: we'll handle force via a special flag approach
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(function(d){
        res.style.background=d.ok?'rgba(16,185,129,.12)':'rgba(239,68,68,.12)';
        res.style.color=d.ok?'var(--green)':'var(--red)';
        res.textContent=d.msg||(d.ok?'Done':'Error');
    }).catch(function(){res.textContent='❌ Network error';});
}

function sendBulkNotif(){
    const type=document.getElementById('bulk_notif_type').value;
    const msg=document.getElementById('bulk_notif_msg').value.trim();
    const res=document.getElementById('bulk_notif_result');
    if(!msg){alert('Message লিখুন।');return;}
    if(!confirm('📢 সব device এ notification পাঠাবেন?')) return;
    const fd=new FormData();
    fd.append('act','send_notif_bulk');
    fd.append('notif_type',type);
    fd.append('message',msg);
    fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        res.style.display='block';
        res.style.background=d.ok?'rgba(16,185,129,.12)':'rgba(239,68,68,.12)';
        res.style.color=d.ok?'var(--green)':'var(--red)';
        res.textContent=d.msg||'Done';
        if(d.ok) document.getElementById('bulk_notif_msg').value='';
    }).catch(()=>{res.style.display='block';res.textContent='❌ Network error';});
}

function sendDonorNotif(){
    const phone=document.getElementById('donor_notif_phone').value.trim();
    const type=document.getElementById('donor_notif_type').value;
    const msg=document.getElementById('donor_notif_msg').value.trim();
    const res=document.getElementById('donor_notif_result');
    if(!phone||!msg){alert('Phone ও message দিন।');return;}
    const fd=new FormData();
    fd.append('act','send_notif_donor');
    fd.append('donor_phone',phone);
    fd.append('notif_type',type);
    fd.append('message',msg);
    fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        res.style.display='block';
        res.style.background=d.ok?'rgba(16,185,129,.12)':'rgba(239,68,68,.12)';
        res.style.color=d.ok?'var(--green)':'var(--red)';
        res.textContent=d.msg||'Done';
        if(d.ok){ document.getElementById('donor_notif_msg').value=''; document.getElementById('donor_notif_phone').value=''; }
    }).catch(()=>{res.style.display='block';res.textContent='❌ Network error';});
}


// ════════════════════════════════════════════════════
// SCHEDULED NOTIFICATIONS JS
// ════════════════════════════════════════════════════
// Set min datetime to now
(function(){
    var el = document.getElementById('sch_run_at');
    if(el){
        var now = new Date();
        now.setMinutes(now.getMinutes()-now.getTimezoneOffset());
        el.min = now.toISOString().slice(0,16);
        el.value = now.toISOString().slice(0,16);
    }
    // Message char counter
    var msgEl = document.getElementById('sch_message');
    var cntEl = document.getElementById('sch_msg_count');
    if(msgEl && cntEl){
        msgEl.addEventListener('input', function(){ cntEl.textContent = this.value.length; });
    }
})();

function toggleSchPhone(){
    var t = document.getElementById('sch_target').value;
    document.getElementById('sch_phone_wrap').style.display = (t==='donor') ? 'block' : 'none';
    document.getElementById('sch_bg_wrap').style.display = (t==='not_willing') ? 'block' : 'none';
}

function saveSchedule(){
    var title   = document.getElementById('sch_title').value.trim();
    var type    = document.getElementById('sch_type').value;
    var target  = document.getElementById('sch_target').value;
    var phone   = (document.getElementById('sch_donor_phone')||{}).value||'';
    var repeat  = document.getElementById('sch_repeat').value;
    var run_at  = document.getElementById('sch_run_at').value;
    var msg     = document.getElementById('sch_message').value.trim();
    var resEl   = document.getElementById('sch_save_result');

    if(!title||!msg||!run_at){ resEl.style.color='var(--red)'; resEl.textContent='❌ Title, message ও সময় দিন।'; return; }

    var fd = new FormData();
    fd.append('act','save_schedule');
    fd.append('title',title);
    fd.append('message',msg);
    fd.append('notif_type',type);
    fd.append('target',target);
    fd.append('donor_phone',phone.trim());
    fd.append('blood_group_filter', (document.getElementById('sch_blood_group')||{value:'All'}).value || 'All');
    fd.append('repeat_type',repeat);
    fd.append('run_at',run_at);
    fd.append('csrf',CSRF);

    resEl.style.color='var(--muted)'; resEl.textContent='⏳ সংরক্ষণ হচ্ছে...';
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        resEl.style.color = d.ok ? 'var(--green)' : 'var(--red)';
        resEl.textContent = d.msg || (d.ok?'✅ Done':'❌ Failed');
        if(d.ok){
            // Reset form
            document.getElementById('sch_title').value='';
            document.getElementById('sch_message').value='';
            document.getElementById('sch_msg_count').textContent='0';
            loadSchedules();
        }
    }).catch(()=>{ resEl.style.color='var(--red)'; resEl.textContent='❌ Network error'; });
}

function loadSchedules(){
    var wrap = document.getElementById('sch_list_wrap');
    if(!wrap) return;
    wrap.innerHTML='<div style="text-align:center;padding:16px;color:var(--muted);font-size:.83em;">⏳ লোড হচ্ছে...</div>';
    var fd=new FormData(); fd.append('act','get_schedules'); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok||!d.schedules.length){
            wrap.innerHTML='<div style="text-align:center;padding:16px;color:var(--muted);font-size:.83em;">কোনো schedule নেই।</div>';
            return;
        }
        var repeatMap={'once':'একবার','daily':'প্রতিদিন','weekly':'প্রতি সপ্তাহ','monthly':'প্রতি মাস'};
        var typeIcon={'info':'ℹ️','warning':'⚠️','location_on':'📍','notif_on':'🔔','secret_reset':'🔑','secret_code_ready':'✅'};
        wrap.innerHTML = d.schedules.map(function(s){
            var active = s.is_active==='1'||s.is_active===1;
            var dt = new Date(s.next_run.replace(' ','T'));
            var dtStr = dt.toLocaleString('bn-BD',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
            var lastStr = s.last_run ? new Date(s.last_run.replace(' ','T')).toLocaleString('bn-BD',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
            return '<div style="display:flex;gap:12px;align-items:flex-start;padding:12px;border-radius:10px;border:1px solid var(--bdr);background:var(--card);margin-bottom:8px;">'
                +  '<div style="font-size:1.4em;flex-shrink:0;">'+(typeIcon[s.notif_type]||'ℹ️')+'</div>'
                +  '<div style="flex:1;min-width:0;">'
                +    '<div style="font-size:.88em;font-weight:700;color:var(--text);margin-bottom:3px;">'+escH(s.title)+'</div>'
                +    '<div style="font-size:.78em;color:var(--muted);line-height:1.5;">'+escH(s.message)+'</div>'
                +    '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:7px;">'
                +      '<span style="font-size:.7em;padding:2px 8px;border-radius:12px;background:rgba(59,130,246,.12);color:var(--blue);">🗓️ '+(active?'Next':'Was')+': '+dtStr+'</span>'
                +      '<span style="font-size:.7em;padding:2px 8px;border-radius:12px;background:var(--inp);color:var(--muted);">🔁 '+(repeatMap[s.repeat_type]||s.repeat_type)+'</span>'
                +      '<span style="font-size:.7em;padding:2px 8px;border-radius:12px;background:var(--inp);color:var(--muted);">'
                +        (s.target==='all' ? '📢 সব device'
                          : s.target==='not_willing' ? ('⛔ Not-Willing' + (s.donor_phone&&s.donor_phone.startsWith('BG:')&&s.donor_phone!=='BG:All' ? ' · '+s.donor_phone.substr(3) : ''))
                          : '👤 '+escH(s.donor_phone))
                +      '</span>'
                +      '<span style="font-size:.7em;padding:2px 8px;border-radius:12px;background:var(--inp);color:var(--muted);">▶️ '+s.run_count+'x চলেছে</span>'
                +      '<span style="font-size:.7em;padding:2px 8px;border-radius:12px;'+(active?'background:rgba(16,185,129,.12);color:var(--green)':'background:rgba(239,68,68,.08);color:var(--red)')+'">'+(active?'✅ Active':'⏸ Inactive')+'</span>'
                +    '</div>'
                +  '</div>'
                +  '<div style="display:flex;flex-direction:column;gap:5px;flex-shrink:0;">'
                +    '<button onclick="toggleSchedule('+s.id+')" style="padding:4px 10px;font-size:.72em;font-weight:700;border-radius:7px;cursor:pointer;border:1px solid var(--bdr);background:var(--inp);color:var(--muted);">'+(active?'⏸ Pause':'▶️ Resume')+'</button>'
                +    '<button onclick="deleteSchedule('+s.id+')" style="padding:4px 10px;font-size:.72em;font-weight:700;border-radius:7px;cursor:pointer;border:1px solid rgba(220,38,38,.3);background:rgba(220,38,38,.08);color:var(--red);">🗑 Delete</button>'
                +  '</div>'
                +'</div>';
        }).join('');
    }).catch(function(){ wrap.innerHTML='<div style="color:var(--red);font-size:.82em;padding:10px;">❌ লোড করা যায়নি।</div>'; });
}

function deleteSchedule(id){
    if(!confirm('এই schedule মুছে ফেলবেন?')) return;
    var fd=new FormData(); fd.append('act','delete_schedule'); fd.append('schedule_id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(function(d){ if(d.ok) loadSchedules(); else alert(d.msg||'Failed'); });
}

function toggleSchedule(id){
    var fd=new FormData(); fd.append('act','toggle_schedule'); fd.append('schedule_id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(function(d){ if(d.ok) loadSchedules(); });
}

function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Load schedules on Notifications tab open
(function(){
    document.querySelectorAll('.tab-btn, [onclick*="tab-notifications"], [data-tab="notifications"]').forEach(function(el){
        el.addEventListener('click', function(){ setTimeout(loadSchedules, 200); });
    });
    // Also load after page ready if notifications tab is active
    window.addEventListener('load', function(){
        var notifTab = document.getElementById('tab-notifications');
        if(notifTab && (notifTab.classList.contains('active') || notifTab.style.display!=='none')){
            loadSchedules();
        }
    });
})();

// ── Poll due schedules every 60s (admin panel open থাকলে চলে) ──
(function(){
    function runDueSchedules(){
        var fd=new FormData(); fd.append('act','run_due_schedules'); fd.append('csrf',CSRF);
        fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json()).then(function(d){
            if(d.fired>0) loadSchedules(); // refresh list if any fired
        }).catch(function(){});
    }
    runDueSchedules(); // run immediately on load
    setInterval(runDueSchedules, 60000); // then every 60s
})();

// ── NOTIFY SELECTED DONORS ───────────────────────────────
function openNotifySelectedModal() {
    var cbs = document.querySelectorAll('.donors-cb:checked');
    if (!cbs.length) { alert('কোনো donor select করা হয়নি।'); return; }
    var modal = document.getElementById('notifySelModal');
    var countEl = document.getElementById('notifSelCount');
    if (countEl) countEl.textContent = '✅ ' + cbs.length + ' জন donor selected';
    var res = document.getElementById('sel_notif_result');
    if (res) res.style.display = 'none';
    var msgEl = document.getElementById('sel_notif_msg');
    if (msgEl) msgEl.value = '';
    modal.style.display = 'flex';
}

function closeNotifySelectedModal() {
    document.getElementById('notifySelModal').style.display = 'none';
}

function sendNotifySelected() {
    var cbs = document.querySelectorAll('.donors-cb:checked');
    if (!cbs.length) { alert('কোনো donor select করা হয়নি।'); return; }
    var ids = Array.from(cbs).map(cb => cb.value).join(',');
    var type = document.getElementById('sel_notif_type').value;
    var msg = (document.getElementById('sel_notif_msg').value||'').trim();
    var res = document.getElementById('sel_notif_result');
    if (!msg) { alert('Message লিখুন।'); return; }
    var fd = new FormData();
    fd.append('act', 'send_notif_selected_donors');
    fd.append('donor_ids', ids);
    fd.append('notif_type', type);
    fd.append('message', msg);
    fd.append('csrf', CSRF);
    fetch(window.location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(function(d){
        res.style.display = 'block';
        res.style.background = d.ok ? 'rgba(16,185,129,.12)' : 'rgba(239,68,68,.12)';
        res.style.color = d.ok ? 'var(--green)' : 'var(--red)';
        res.textContent = d.msg || 'Done';
        if (d.ok) {
            showToast(d.msg, '#10b981');
            setTimeout(closeNotifySelectedModal, 2000);
        }
    }).catch(function(){ res.style.display='block'; res.textContent='❌ Network error'; });
}

// ── Auto-load Secret Requests when tab opened ─────────────
const _origGo = window.go;
window.go = function(tab, el) {
    _origGo(tab, el);
    if(tab==='secretreqs') loadSecretReqs();
};

// ── MODAL helpers ────────────────────────────────────────
function closeModal(){ document.getElementById('codeModal').style.display='none'; }
function copyModalCode(){
    const code=document.getElementById('modalCode').textContent;
    navigator.clipboard.writeText(code).then(()=>{
        const b=document.getElementById('copyModalBtn');
        b.textContent='✅ Copied!'; setTimeout(()=>b.textContent='📋 Copy',1800);
    });
}

// ── TOKEN MANAGER ─────────────────────────────────────────
function addToken(){
    if(!guardSuper("Token Add")) return;
    const name=(document.getElementById('newTokenName').value||'').trim();
    if(!name){alert('Token এর নাম দিন।');return;}
    const fd=new FormData(); fd.append('act','add_token'); fd.append('token_name',name); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            document.getElementById('newTokenName').value='';
            const list=document.getElementById('tokenList');
            // Remove "no tokens" placeholder if present
            const empty=list.querySelector('div[style*="text-align"]');
            if(empty) empty.remove();
            const row=document.createElement('div');
            row.className='token-row'; row.id='tokrow'+d.id;
            row.innerHTML=`
                <span class="tok-name">${escHtml(d.token_name)}</span>
                <span class="tok-val">${escHtml(d.token_value)}</span>
                <span class="tok-status tok-active" id="tok-status-${d.id}">✅ Active</span>
                <span class="tok-date">📅 ${escHtml(d.created_at)}</span>
                <button class="tok-copy" onclick="copyToken('${escHtml(d.token_value)}',this)">📋 Copy</button>
                <button class="tok-toggle active-btn" id="tok-toggle-${d.id}" onclick="toggleToken(${d.id})">⏸ Disable</button>
                <button class="tok-del" onclick="delToken(${d.id})">🗑</button>
            `;
            list.prepend(row);
            showToast('✅ Token তৈরি হয়েছে!');
        } else {alert('❌ '+(d.msg||'Token create failed'));}
    }).catch(e=>alert('Network error: '+e.message));
}

function copyToken(val, btn){
    navigator.clipboard.writeText(val).then(()=>{
        const old=btn.textContent; btn.textContent='✅ Copied!';
        setTimeout(()=>btn.textContent=old,1800);
    });
}

function toggleToken(id){
    const fd=new FormData(); fd.append('act','toggle_token'); fd.append('id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            const statusEl=document.getElementById('tok-status-'+id);
            const toggleEl=document.getElementById('tok-toggle-'+id);
            if(d.is_active){
                if(statusEl){statusEl.className='tok-status tok-active';statusEl.textContent='✅ Active';}
                if(toggleEl){toggleEl.className='tok-toggle active-btn';toggleEl.textContent='⏸ Disable';}
            } else {
                if(statusEl){statusEl.className='tok-status tok-inactive';statusEl.textContent='⛔ Inactive';}
                if(toggleEl){toggleEl.className='tok-toggle inactive-btn';toggleEl.textContent='▶ Enable';}
            }
        }
    }).catch(e=>alert('Network error: '+e.message));
}

function delToken(id){
    if(!guardSuper("Token Delete")) return;
    if(!confirm('🗑 এই Token delete করবেন?')) return;
    const fd=new FormData(); fd.append('act','del_token'); fd.append('id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            const row=document.getElementById('tokrow'+id);
            if(row){row.style.opacity='.2';setTimeout(()=>row.remove(),300);}
            showToast('✅ Token delete হয়েছে।');
        } else {alert('❌ '+(d.msg||'Delete failed'));}
    }).catch(e=>alert('Network error: '+e.message));
}

// ── IP WHITELIST ──────────────────────────────────────────
function toggleIpWhitelist(btn){
    if(btn.classList.contains('on')){
        if(!confirm('⚠️ IP Whitelist বন্ধ করলে যেকেউ panel access করতে পারবে। নিশ্চিত?')) return;
    } else {
        const ips=document.querySelectorAll('.ip-tag');
        if(ips.length===0){ alert('⚠️ আগে কমপক্ষে একটি IP add করুন। নইলে আপনি নিজেও panel access করতে পারবেন না!'); return; }
    }
    const fd=new FormData(); fd.append('act','toggle_ip_whitelist'); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            btn.classList.toggle('on',d.enabled);
            showToast(d.enabled?'🔒 IP Whitelist চালু হয়েছে':'🔓 IP Whitelist বন্ধ হয়েছে', d.enabled?'#10b981':'#f59e0b');
        }
    }).catch(e=>alert('Network error: '+e.message));
}

function addMyIP(){
    const myIP='<?=esc($currentIP)?>';
    document.getElementById('newIpAddr').value=myIP;
    document.getElementById('newIpLabel').value='My IP';
    addIp();
}

function addIp(){
    if(!guardSuper("IP Add")) return;
    const ip=(document.getElementById('newIpAddr').value||'').trim();
    const label=(document.getElementById('newIpLabel').value||'').trim();
    if(!ip){alert('IP address দিন।');return;}
    const fd=new FormData(); fd.append('act','add_ip'); fd.append('ip_addr',ip); fd.append('ip_label',label); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            document.getElementById('newIpAddr').value='';
            document.getElementById('newIpLabel').value='';
            const list=document.getElementById('ipList');
            const empty=list.querySelector('div[style*="text-align"]');
            if(empty) empty.remove();
            const tag=document.createElement('div');
            tag.className='ip-tag'; tag.id='iptag'+d.id;
            tag.innerHTML=`<span class="ip-addr">${escHtml(d.ip)}</span><span class="ip-label">${escHtml(d.label)}</span><span class="ip-badge active">✅ Active</span><button class="ip-del-btn" onclick="delIp(${d.id})">🗑 Remove</button>`;
            list.prepend(tag);
            showToast('✅ IP whitelist এ add হয়েছে।');
        } else {alert('❌ '+(d.msg||'Add IP failed'));}
    }).catch(e=>alert('Network error: '+e.message));
}

function delIp(id){
    if(!guardSuper("IP Remove")) return;
    if(!confirm('🗑 এই IP whitelist থেকে remove করবেন?')) return;
    const fd=new FormData(); fd.append('act','del_ip'); fd.append('id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            const tag=document.getElementById('iptag'+id);
            if(tag){tag.style.opacity='.2';setTimeout(()=>tag.remove(),300);}
            showToast('✅ IP remove হয়েছে।','#f59e0b');
        } else {alert('❌ '+(d.msg||'Remove failed'));}
    }).catch(e=>alert('Network error: '+e.message));
}

// ── CHANGE PASSWORD ───────────────────────────────────────
function changePassword(){
    if(!guardSuper("Password Change")) return;
    const cur=document.getElementById('pw_current').value;
    const nw=document.getElementById('pw_new').value;
    const cf=document.getElementById('pw_confirm').value;
    const btn=document.getElementById('pwSaveBtn');
    const res=document.getElementById('pwResult');
    if(!cur||!nw||!cf){res.className='pw-result err';res.style.display='block';res.textContent='সব field পূরণ করুন।';return;}
    btn.disabled=true; btn.textContent='⏳ সংরক্ষণ হচ্ছে...';
    const fd=new FormData(); fd.append('act','change_password');
    fd.append('current_pass',cur); fd.append('new_pass',nw); fd.append('confirm_pass',cf); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false; btn.textContent='🔑 Password পরিবর্তন করুন';
        res.style.display='block';
        if(d.ok){
            res.className='pw-result ok'; res.textContent=d.msg||'Password পরিবর্তন হয়েছে!';
            ['pw_current','pw_new','pw_confirm'].forEach(id=>document.getElementById(id).value='');
        } else { res.className='pw-result err'; res.textContent=d.msg||'Failed'; }
    }).catch(e=>{btn.disabled=false;btn.textContent='🔑 Password পরিবর্তন করুন';res.className='pw-result err';res.style.display='block';res.textContent='Network error: '+e.message;});
}

// ── ADVANCED SEARCH ──────────────────────────────────────
function runAdvSearch(){
    const btn=document.querySelector('.adv-run'); const resEl=document.getElementById('advResults'); const countEl=document.getElementById('advCount');
    btn.disabled=true; btn.innerHTML='<span class="loading-spin"></span> Searching...';
    resEl.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted);">⏳ খোঁজা হচ্ছে...</div>';
    countEl.textContent='';
    const fd=new FormData(); fd.append('act','adv_search'); fd.append('csrf',CSRF);
    fd.append('s_name',document.getElementById('as_name').value.trim());
    fd.append('s_phone',document.getElementById('as_phone').value.trim());
    fd.append('s_group',document.getElementById('as_group').value);
    fd.append('s_loc',document.getElementById('as_loc').value.trim());
    fd.append('s_status',document.getElementById('as_status').value);
    fd.append('s_badge',document.getElementById('as_badge').value);
    fd.append('s_from',document.getElementById('as_from').value);
    fd.append('s_to',document.getElementById('as_to').value);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false; btn.innerHTML='🔍 Search';
        if(!d.ok){resEl.innerHTML='<div class="empty">❌ Error: '+(d.msg||'Unknown')+'</div>';return;}
        _advRows=d.rows; countEl.textContent=d.count+' টি পাওয়া গেছে';
        document.getElementById('advExportBtn').style.display=d.count?'inline-block':'none';
        if(!d.rows.length){resEl.innerHTML='<div class="empty">🔍 কোনো donor পাওয়া যায়নি।</div>';return;}
        const stCls={Available:'st-av','Not Available':'st-na','Not Willing':'st-nw'};
        const stIco={Available:'✔','Not Available':'✖','Not Willing':'⛔'};
        const badgeIcons={New:'🌱',Active:'⭐',Hero:'🦸',Legend:'👑'};
        let html='<div class="ow"><table><thead><tr><th>#</th><th>Name</th><th>Group</th><th>Phone</th><th>Location</th><th>Status</th><th>Badge</th><th>Donations</th><th>Last Donation</th><th>Joined</th></tr></thead><tbody>';
        d.rows.forEach((r,i)=>{
            const st=r._status||'Available';
            const jn=r.created_at?r.created_at.substring(0,10):'—';
            const ld=r.last_donation==='no'||!r.last_donation?'Never':r.last_donation;
            const bl=r.badge_level||'New';
            html+=`<tr><td style="color:var(--muted);">${i+1}</td><td style="font-weight:600;">${escHtml(r.name||'')}</td><td><span class="bg">${escHtml(r.blood_group||'')}</span></td><td style="font-family:monospace;font-size:.79em;">${escHtml(r.phone||'')}</td><td style="font-size:.79em;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(r.location||'')}</td><td class="${stCls[st]||''}" style="white-space:nowrap;">${stIco[st]||''} ${escHtml(st)}</td><td><span class="badge-pill bp-${bl.toLowerCase()}">${badgeIcons[bl]||'🌱'} ${escHtml(bl)}</span></td><td style="text-align:center;">${parseInt(r.total_donations)||0}</td><td style="font-size:.78em;color:var(--muted);">${escHtml(ld)}</td><td style="font-size:.77em;color:var(--muted);">${escHtml(jn)}</td></tr>`;
        });
        html+='</tbody></table></div>'; resEl.innerHTML=html;
    }).catch(()=>{btn.disabled=false;btn.innerHTML='🔍 Search';resEl.innerHTML='<div class="empty">❌ Network error. আবার চেষ্টা করুন।</div>';});
}

function clearAdvSearch(){
    ['as_name','as_phone','as_loc','as_from','as_to'].forEach(id=>document.getElementById(id).value='');
    ['as_group','as_status','as_badge'].forEach(id=>document.getElementById(id).value='All');
    document.getElementById('advResults').innerHTML='<div style="text-align:center;padding:40px;color:var(--muted);"><div style="font-size:2.5rem;margin-bottom:10px;">🔍</div><p>উপরের filter দিয়ে donor খুঁজুন</p></div>';
    document.getElementById('advCount').textContent='';
    document.getElementById('advExportBtn').style.display='none';
    _advRows=[];
}

function exportAdvCSV(){
    if(!_advRows.length){alert('কোনো data নেই।');return;}
    let csv='#,Name,Phone,Blood Group,Location,Status,Badge,Donations,Last Donation,Joined\n';
    _advRows.forEach((r,i)=>{
        const ld=r.last_donation==='no'||!r.last_donation?'Never':r.last_donation;
        const jn=r.created_at?r.created_at.substring(0,10):'';
        csv+=`${i+1},"${(r.name||'').replace(/"/g,'""')}","${r.phone||''}","${r.blood_group||''}","${(r.location||'').replace(/"/g,'""')}","${r._status||''}","${r.badge_level||''}",${parseInt(r.total_donations)||0},"${ld}","${jn}"\n`;
    });
    const blob=new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8;'});
    const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
    a.download='bloodarena_search_'+new Date().toISOString().substring(0,10)+'.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

// ── EDIT DONOR MODAL ─────────────────────────────────────
let _editMapPickerMap=null, _editMapPickerMarker=null;

function openEditDonor(id,btn){
    btn.disabled=true; btn.textContent='⏳';
    const fd=new FormData(); fd.append('act','get_donor'); fd.append('id',id); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false; btn.textContent='✏️';
        if(!d.ok){alert(d.msg||'Error loading donor');return;}
        const donor=d.donor;
        document.getElementById('edit_donor_id').value=donor.id;
        document.getElementById('edit_name').value=donor.name||'';
        document.getElementById('edit_phone').value=donor.phone||'';
        document.getElementById('edit_total_donations').value=donor.total_donations||0;
        updateEditBadgePreview(donor.total_donations||0);
        document.getElementById('edit_location').value=donor.location||'';
        document.getElementById('edit_reg_geo').value='';
        const bgSel=document.getElementById('edit_blood_group');
        for(let i=0;i<bgSel.options.length;i++){if(bgSel.options[i].value===donor.blood_group){bgSel.selectedIndex=i;break;}}
        if(!donor.last_donation_fmt||donor.last_donation_fmt==='no'){clearEditDate();}
        else {
            const p=donor.last_donation_fmt.split('/');
            if(p.length===3){
                const iso=p[2]+'-'+p[1]+'-'+p[0];
                document.getElementById('edit_last_date').value=iso;
                document.getElementById('edit_last_donation').value=donor.last_donation_fmt;
                document.getElementById('editDateDisplay').textContent=donor.last_donation_fmt;
            }
        }
        setEditWilling(donor.willing_to_donate==='no'?'no':'yes');
        const geoSt=document.getElementById('editGeoStatus');
        if(donor.geo_lat&&donor.geo_lng){geoSt.style.display='block';geoSt.textContent='📍 Existing: '+donor.geo_lat+', '+donor.geo_lng;}
        else{geoSt.style.display='none';}
        document.getElementById('editDonorModal').style.display='flex';
    }).catch(e=>{btn.disabled=false;btn.textContent='✏️';alert('Network error: '+e.message);});
}

function closeEditModal(){ document.getElementById('editDonorModal').style.display='none'; }

function updateEditBadgePreview(n){
    n=parseInt(n)||0;
    var icon,label;
    if(n>=10){icon='👑';label='Legend';}else if(n>=5){icon='🦸';label='Hero';}else if(n>=2){icon='⭐';label='Active';}else{icon='🌱';label='New';}
    var el=document.getElementById('editBadgePreview'); if(el) el.textContent=icon+' '+label+' Donor হবেন';
}

function setEditWilling(val){
    document.getElementById('edit_willing').value=val;
    document.getElementById('ef_yes_btn').className=val==='yes'?'on-yes':'';
    document.getElementById('ef_no_btn').className=val==='no'?'on-no':'';
}

function syncEditDate(val){
    if(!val){clearEditDate();return;}
    const p=val.split('-');
    if(p.length===3){
        const ddmmyyyy=p[2]+'/'+p[1]+'/'+p[0];
        document.getElementById('edit_last_donation').value=ddmmyyyy;
        document.getElementById('editDateDisplay').textContent=ddmmyyyy;
    }
}

function clearEditDate(){
    document.getElementById('edit_last_date').value='';
    document.getElementById('edit_last_donation').value='no';
    document.getElementById('editDateDisplay').textContent='Never donated';
}

function saveEditDonor(){
    const id=document.getElementById('edit_donor_id').value;
    const name=document.getElementById('edit_name').value.trim();
    const phone=document.getElementById('edit_phone').value.trim();
    const bg=document.getElementById('edit_blood_group').value;
    const loc=document.getElementById('edit_location').value.trim();
    const last=document.getElementById('edit_last_donation').value;
    const willing=document.getElementById('edit_willing').value;
    const total=parseInt(document.getElementById('edit_total_donations').value)||0;
    const regGeo=document.getElementById('edit_reg_geo').value.trim();
    if(!name) return alert('নাম দিন।');
    if(!phone) return alert('Phone দিন।');
    if(!loc) return alert('Location দিন।');
    const btn=document.getElementById('editSaveBtn'); btn.disabled=true; btn.textContent='⏳ Saving...';
    const fd=new FormData(); fd.append('act','edit_donor'); fd.append('id',id);
    fd.append('name',name); fd.append('phone',phone); fd.append('blood_group',bg); fd.append('location',loc);
    fd.append('last_donation',last); fd.append('willing_to_donate',willing); fd.append('total_donations',total);
    if(regGeo) fd.append('reg_geo',regGeo); fd.append('csrf',CSRF);
    fetch(window.location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false; btn.textContent='💾 Save Changes';
        if(d.ok){
            closeEditModal();
            const row=document.getElementById('drow'+id);
            if(row){
                const badgeMap=total>=10?'Legend':total>=5?'Hero':total>=2?'Active':'New';
                const badgeIcon=total>=10?'👑':total>=5?'🦸':total>=2?'⭐':'🌱';
                const tds=row.querySelectorAll('td');
                if(tds[2]) tds[2].innerHTML='<span style="font-weight:600">'+escHtml(name)+' '+badgeIcon+'</span>';
                if(tds[3]) tds[3].innerHTML='<span class="bg">'+escHtml(bg)+'</span>';
                if(tds[4]) tds[4].textContent=phone;
                if(tds[7]) tds[7].innerHTML=badgeIcon+' '+badgeMap;
                if(tds[8]) tds[8].textContent=total;
                row.style.background='rgba(59,130,246,.08)'; setTimeout(()=>row.style.background='',2000);
            }
            showToast('✅ '+name+' updated!');
        } else {alert('❌ '+(d.msg||'Update failed'));}
    }).catch(e=>{btn.disabled=false;btn.textContent='💾 Save Changes';alert('Network error: '+e.message);});
}

// ── Map Picker ───────────────────────────────────────────
function openEditMapPicker(){
    if(document.getElementById('adminMapOverlay')){
        document.getElementById('adminMapOverlay').style.display='flex';
        setTimeout(()=>{if(_editMapPickerMap)_editMapPickerMap.invalidateSize();},300);return;
    }
    const overlay=document.createElement('div'); overlay.id='adminMapOverlay';
    overlay.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:5000;display:flex;flex-direction:column;';
    overlay.innerHTML=`<div style="background:#141720;padding:10px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid rgba(255,255,255,.1);">
        <input id="adminMapSearch" type="text" placeholder="🔍 এলাকার নাম লিখুন..." style="flex:1;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:7px 11px;color:#fff;font-size:.88em;outline:none;">
        <button onclick="doAdminMapSearch()" style="padding:7px 14px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:.84em;font-weight:700;cursor:pointer;">🔍</button>
        <button onclick="adminMapMyLoc()" style="padding:7px 11px;background:rgba(16,185,129,.2);border:1px solid rgba(16,185,129,.4);color:#10b981;border-radius:8px;cursor:pointer;">📍</button>
        <button onclick="confirmAdminMapLocation()" style="padding:7px 14px;background:#10b981;color:#fff;border:none;border-radius:8px;font-size:.84em;font-weight:700;cursor:pointer;">✅ ব্যবহার করুন</button>
        <button onclick="closeAdminMapPicker()" style="padding:7px 11px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#ef4444;border-radius:8px;cursor:pointer;font-size:.88em;">✕</button>
    </div>
    <div id="adminMapResult" style="background:#0d1117;padding:5px 16px;font-size:.78em;color:#6ee7b7;min-height:24px;"></div>
    <div id="adminLeafletMap" style="flex:1;"></div>`;
    document.body.appendChild(overlay);
    if(!document.getElementById('leaflet-css')){
        const lc=document.createElement('link'); lc.id='leaflet-css'; lc.rel='stylesheet';
        lc.href='https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(lc);
    }
    function initAdminMap(){
        if(typeof L==='undefined'){setTimeout(initAdminMap,400);return;}
        _editMapPickerMap=L.map('adminLeafletMap',{zoomControl:true}).setView([23.7735,90.3742],13);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',{attribution:'© OpenStreetMap © CARTO',subdomains:'abcd',maxZoom:19}).addTo(_editMapPickerMap);
        _editMapPickerMap.on('click',function(e){
            const lat=e.latlng.lat.toFixed(6),lng=e.latlng.lng.toFixed(6);
            if(_editMapPickerMarker)_editMapPickerMarker.setLatLng(e.latlng);
            else{_editMapPickerMarker=L.marker(e.latlng,{draggable:true}).addTo(_editMapPickerMap);_editMapPickerMarker.on('dragend',function(){const p=_editMapPickerMarker.getLatLng();doAdminReverseGeocode(p.lat.toFixed(6),p.lng.toFixed(6));});}
            doAdminReverseGeocode(lat,lng);
        });
        if(navigator.geolocation){navigator.geolocation.getCurrentPosition(p=>{_editMapPickerMap.setView([p.coords.latitude,p.coords.longitude],15);},null,{timeout:5000});}
    }
    if(!document.getElementById('leaflet-js')){
        const ls=document.createElement('script'); ls.id='leaflet-js';
        ls.src='https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; ls.onload=initAdminMap; document.head.appendChild(ls);
    } else {setTimeout(initAdminMap,200);}
}

function doAdminReverseGeocode(lat,lng){
    const res=document.getElementById('adminMapResult'); if(res) res.textContent='⏳ লোড হচ্ছে...';
    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}&accept-language=en`,{headers:{'Accept-Language':'en'}})
    .then(r=>r.json()).then(d=>{
        const addr=d.address||{};
        const parts=[addr.road||addr.neighbourhood||addr.suburb,addr.city_district||addr.suburb||addr.town||addr.city,addr.city||addr.county].filter(Boolean);
        const readable=parts.length?parts.join(', '):d.display_name;
        if(res) res.textContent='📍 '+readable;
        if(_editMapPickerMarker) _editMapPickerMarker.bindPopup('📍 '+readable).openPopup();
        const ol=document.getElementById('adminMapOverlay'); if(ol){ol.dataset.lat=lat;ol.dataset.lng=lng;ol.dataset.label=readable;}
    }).catch(()=>{
        if(res) res.textContent='Lat: '+lat+', Lon: '+lng;
        const ol=document.getElementById('adminMapOverlay'); if(ol){ol.dataset.lat=lat;ol.dataset.lng=lng;ol.dataset.label='Lat:'+lat+',Lon:'+lng;}
    });
}

function doAdminMapSearch(){
    const q=(document.getElementById('adminMapSearch')||{}).value; if(!q||!q.trim()) return;
    fetch('https://nominatim.openstreetmap.org/search?format=json&q='+encodeURIComponent(q.trim()+', Bangladesh')+'&limit=1&accept-language=en')
    .then(r=>r.json()).then(results=>{
        if(!results||!results.length){alert('এলাকা খুঁজে পাওয়া যায়নি।');return;}
        const r=results[0]; const lat=parseFloat(r.lat),lng=parseFloat(r.lon);
        _editMapPickerMap.setView([lat,lng],16);
        const latlng=L.latLng(lat,lng);
        if(_editMapPickerMarker)_editMapPickerMarker.setLatLng(latlng);
        else{_editMapPickerMarker=L.marker(latlng,{draggable:true}).addTo(_editMapPickerMap);_editMapPickerMarker.on('dragend',function(){const p=_editMapPickerMarker.getLatLng();doAdminReverseGeocode(p.lat.toFixed(6),p.lng.toFixed(6));});}
        doAdminReverseGeocode(lat.toFixed(6),lng.toFixed(6));
    }).catch(()=>alert('Search কাজ করছে না।'));
}

function adminMapMyLoc(){
    if(!navigator.geolocation||!_editMapPickerMap) return;
    navigator.geolocation.getCurrentPosition(p=>{
        const lat=p.coords.latitude,lng=p.coords.longitude; _editMapPickerMap.setView([lat,lng],16);
        const latlng=L.latLng(lat,lng);
        if(_editMapPickerMarker)_editMapPickerMarker.setLatLng(latlng);
        else{_editMapPickerMarker=L.marker(latlng,{draggable:true}).addTo(_editMapPickerMap);_editMapPickerMarker.on('dragend',function(){const p2=_editMapPickerMarker.getLatLng();doAdminReverseGeocode(p2.lat.toFixed(6),p2.lng.toFixed(6));});}
        doAdminReverseGeocode(lat.toFixed(6),lng.toFixed(6));
    },null,{timeout:8000,enableHighAccuracy:true});
}

function confirmAdminMapLocation(){
    const ol=document.getElementById('adminMapOverlay'); if(!ol||!ol.dataset.lat){alert('Map এ ক্লিক করে location বেছে নিন।');return;}
    const label=ol.dataset.label||('Lat:'+ol.dataset.lat+',Lon:'+ol.dataset.lng);
    document.getElementById('edit_location').value=label;
    document.getElementById('edit_reg_geo').value='Lat: '+ol.dataset.lat+', Lon: '+ol.dataset.lng;
    const geoSt=document.getElementById('editGeoStatus'); geoSt.style.display='block';
    geoSt.textContent='📍 '+ol.dataset.lat+', '+ol.dataset.lng; closeAdminMapPicker();
}

function closeAdminMapPicker(){
    const ol=document.getElementById('adminMapOverlay'); if(ol) ol.style.display='none';
    if(_editMapPickerMap) setTimeout(()=>_editMapPickerMap.invalidateSize(),300);
}

// ── Utility ──────────────────────────────────────────────
function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Session timer & idle ─────────────────────────────────
let sessionStart=Date.now();
document.addEventListener('mousemove',()=>lastActivity=Date.now());
document.addEventListener('keydown',  ()=>lastActivity=Date.now());
document.addEventListener('touchstart',()=>lastActivity=Date.now());
function formatTime(s){const m=Math.floor(s/60),ss=s%60;return m+':'+(ss<10?'0':'')+ss;}
setInterval(()=>{
    const el=document.getElementById('sClock'); if(el) el.textContent=formatTime(Math.floor((Date.now()-sessionStart)/1000));
    const idleS=Math.floor((Date.now()-lastActivity)/1000); const rem=IDLE_LIMIT-idleS;
    const warn=document.getElementById('idleWarn'); const sEl=document.getElementById('idleSecs');
    if(rem<=60&&rem>0){if(warn){warn.style.display='block';if(sEl)sEl.textContent=rem;}}
    else if(rem<=0){window.location.href='admin.php?logout=1';}
    else{if(warn)warn.style.display='none';}
},1000);

// ── Init ─────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded',function(){
    filterSecrets();
    document.querySelectorAll('#tab-advsearch input, #tab-advsearch select').forEach(el=>{
        el.addEventListener('keydown',function(e){if(e.key==='Enter') runAdvSearch();});
    });
    // Init admin notifications
    adminNotifInit();
});

document.addEventListener('contextmenu',e=>e.preventDefault());
document.addEventListener('keydown',e=>{if(e.ctrlKey&&(e.key==='u'||e.key==='U'||e.key==='s'||e.key==='S'))e.preventDefault();});

// ============================================================
// ADMIN BROWSER NOTIFICATIONS — SW polling system
// ============================================================
var _adminSWReg      = null;   // SW registration
var _adminPollTimer  = null;   // setInterval handle
var _adminLastTs     = 0;      // last seen unix timestamp
var _adminPollOn     = localStorage.getItem('adm_poll_on') !== '0'; // default on
var _adminNotifEnabled = false;

function adminNotifInit() {
    _adminLastTs = Math.floor(Date.now()/1000) - 5; // start from now minus buffer
    _updatePollToggleUI();
    _checkNotifStatus();
    // Register SW (same sw.js as index.php)
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js', {scope:'/'})
        .then(function(reg) {
            _adminSWReg = reg;
            _checkNotifStatus();
        }).catch(function(err){
            console.warn('[Admin SW]', err);
        });
    }
    // Start polling if enabled
    if (_adminPollOn) _startAdminPoll();
}

function _checkNotifStatus() {
    var statusEl = document.getElementById('adminNotifStatus');
    var enableBtn = document.getElementById('adminNotifEnableBtn');
    var testBtn  = document.getElementById('adminNotifTestBtn');
    if (!statusEl) return;

    if (!('Notification' in window)) {
        statusEl.textContent = '❌ এই browser এ Notification support নেই।';
        statusEl.style.background = 'rgba(239,68,68,.1)';
        statusEl.style.borderColor = 'rgba(239,68,68,.3)';
        if (enableBtn) enableBtn.style.display = 'none';
        return;
    }
    var perm = Notification.permission;
    if (perm === 'granted') {
        statusEl.innerHTML = '✅ <strong style="color:var(--green);">Notifications চালু আছে</strong> — নতুন Inbox message বা Secret Code Request এলে notification আসবে।';
        statusEl.style.background = 'rgba(16,185,129,.08)';
        statusEl.style.borderColor = 'rgba(16,185,129,.3)';
        if (enableBtn) enableBtn.textContent = '🔕 Disable করুন';
        if (testBtn) testBtn.style.display = '';
        _adminNotifEnabled = true;
        var dot = document.getElementById('adminNotifDot');
        if (dot) dot.style.display = '';
    } else if (perm === 'denied') {
        statusEl.innerHTML = '🚫 <strong style="color:var(--red);">Notifications Blocked</strong> — Browser settings থেকে manually allow করুন।';
        statusEl.style.background = 'rgba(239,68,68,.1)';
        statusEl.style.borderColor = 'rgba(239,68,68,.3)';
        if (enableBtn) enableBtn.textContent = '🔒 Browser-এ Blocked';
        _adminNotifEnabled = false;
    } else {
        statusEl.innerHTML = '⚠️ Notifications এখনো allow করা হয়নি।';
        statusEl.style.background = 'rgba(245,158,11,.08)';
        statusEl.style.borderColor = 'rgba(245,158,11,.3)';
        if (enableBtn) enableBtn.textContent = '🔔 Enable Notifications';
        _adminNotifEnabled = false;
    }
}

function adminEnableNotif() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') {
        // Toggle off — just update UI note (can't programmatically remove)
        showToast('Browser settings থেকে manually block করুন।', '#f59e0b');
        return;
    }
    Notification.requestPermission().then(function(perm){
        _checkNotifStatus();
        if (perm === 'granted') {
            showToast('✅ Notifications চালু হয়েছে!');
            if (!_adminPollOn) toggleAdminPoll(document.getElementById('adminPollToggle'));
        }
    });
}

function adminTestNotif() {
    _showAdminNotif('🧪 Test Notification', 'Admin notifications ঠিকমতো কাজ করছে!', '/admin.php');
    showToast('✅ Test notification পাঠানো হয়েছে।');
}

function _showAdminNotif(title, body, url) {
    if (Notification.permission !== 'granted') return;
    var opts = {
        body: body,
        icon: '/icon.png',
        badge: '/?badge_icon=1',
        tag: 'admin-' + Date.now(),
        renotify: true,
        vibrate: [200, 100, 200],
        data: { url: url || '/admin.php' }
    };
    if (_adminSWReg) {
        _adminSWReg.showNotification(title, opts).catch(function(){
            new Notification(title, opts);
        });
    } else {
        new Notification(title, opts);
    }
}

// ── Polling ───────────────────────────────────────────────
function _startAdminPoll() {
    if (_adminPollTimer) return;
    _pollAdminCounts(); // immediate first poll
    _adminPollTimer = setInterval(function(){
        if (!document.hidden) _pollAdminCounts();
    }, 30000);
    document.addEventListener('visibilitychange', function(){
        if (!document.hidden) _pollAdminCounts();
    });
}

function _stopAdminPoll() {
    if (_adminPollTimer) { clearInterval(_adminPollTimer); _adminPollTimer = null; }
}

function toggleAdminPoll(btn) {
    _adminPollOn = !_adminPollOn;
    localStorage.setItem('adm_poll_on', _adminPollOn ? '1' : '0');
    _updatePollToggleUI();
    if (_adminPollOn) { _startAdminPoll(); showToast('✅ Auto-polling চালু হয়েছে।'); }
    else { _stopAdminPoll(); showToast('⏸ Auto-polling বন্ধ।', '#f59e0b'); }
}

function _updatePollToggleUI() {
    var btn = document.getElementById('adminPollToggle');
    var lbl = document.getElementById('adminPollStatus');
    if (btn) btn.className = 'toggle-btn' + (_adminPollOn ? ' on' : '');
    if (lbl) lbl.textContent = _adminPollOn ? '✅ প্রতি ৩০s এ check করছে' : '⏸ বন্ধ';
}

var _lastKnownInboxCount = -1;
function _pollAdminCounts() {
    var fd = new FormData();
    fd.append('act', 'get_admin_poll');
    fd.append('since', _adminLastTs);
    fd.append('csrf', CSRF);
    fetch(window.location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json())
    .then(function(d){
        if (!d.ok) return;

        // Update _adminLastTs to latest server time
        if (d.server_time) _adminLastTs = d.server_time;

        // Update sidebar badges
        _updateSidebarBadge('inbox', d.total_inbox);
        _updateSidebarBadge('secretreqs', d.total_scr);

        // ── Auto-refresh inbox if tab is open and count changed ──
        var inboxTab = document.getElementById('tab-inbox');
        var inboxVisible = inboxTab && inboxTab.classList.contains('on');
        if (d.total_inbox !== undefined && d.total_inbox !== _lastKnownInboxCount) {
            if (inboxVisible && typeof loadInbox === 'function') loadInbox(_inboxFilter || 'all');
            _lastKnownInboxCount = d.total_inbox;
        }

        // ── Trigger auto-reminder if due ──
        if (d.auto_reminder_due) {
            var rfd = new FormData();
            rfd.append('act', 'run_auto_reminder');
            rfd.append('csrf', CSRF);
            fetch(window.location.href, {method:'POST', body:rfd, headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(r=>r.json()).then(function(rd){
                if(rd.ok && !rd.skipped && rd.sent > 0){
                    showToast('🔔 Auto Reminder: ' + rd.sent + ' জন not-willing donor কে notification পাঠানো হয়েছে।', '#f59e0b');
                }
            }).catch(function(){});
        }

        // Fire browser notifications for NEW items
        if (_adminNotifEnabled) {
            if (d.new_inbox && d.new_inbox.length) {
                d.new_inbox.forEach(function(m){
                    _showAdminNotif(
                        '📬 নতুন Message: ' + (m.sender_name||''),
                        m.message ? m.message.substring(0,80) + (m.message.length>80?'…':'') : '',
                        '/admin.php'
                    );
                });
            }
            if (d.new_scr && d.new_scr.length) {
                d.new_scr.forEach(function(r){
                    _showAdminNotif(
                        '📩 নতুন Secret Code Request',
                        '📞 ' + (r.donor_number||'') + ' — approve বা deny করুন।',
                        '/admin.php'
                    );
                });
            }
        }
    }).catch(function(){});
}

function _updateSidebarBadge(tabName, count) {
    // Find .ni and .mt elements for this tab
    var niEls = document.querySelectorAll('.ni[onclick*="\''+tabName+'\'"], .mt[onclick*="\''+tabName+'\'"]');
    niEls.forEach(function(el){
        var cnt = el.querySelector('.cnt');
        if (count > 0) {
            if (cnt) { cnt.textContent = count; }
            else {
                var sp = document.createElement('span');
                sp.className = 'cnt'; sp.textContent = count;
                el.appendChild(sp);
            }
        } else {
            if (cnt) cnt.remove();
        }
    });
};
</script>
<?php endif; ?>
<!-- ══ NOTIFY SELECTED DONORS MODAL ══ -->
<div id="notifySelModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:var(--card);border:1px solid var(--bdr);border-radius:16px;padding:24px;max-width:460px;width:92%;box-shadow:0 20px 60px rgba(0,0,0,.5);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 style="margin:0;font-size:1rem;">📢 Selected Donors কে Notify করুন</h3>
      <button onclick="closeNotifySelectedModal()" style="background:none;border:none;color:var(--muted);font-size:1.3rem;cursor:pointer;padding:0 4px;">✕</button>
    </div>
    <p id="notifSelCount" style="font-size:.82em;color:var(--blue);margin-bottom:12px;"></p>
    <div style="display:grid;gap:10px;">
      <div>
        <label style="font-size:.78em;color:var(--muted);display:block;margin-bottom:4px;">Notification Type</label>
        <select id="sel_notif_type" style="padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;width:100%;">
          <option value="info">ℹ️ Info (General)</option>
          <option value="warning">⚠️ Warning</option>
          <option value="location_on">📍 Location চালু করুন</option>
          <option value="notif_on">🔔 Notification চালু করুন</option>
          <option value="secret_reset">🔑 Secret Code Reset</option>
        </select>
      </div>
      <div>
        <label style="font-size:.78em;color:var(--muted);display:block;margin-bottom:4px;">Message</label>
        <textarea id="sel_notif_msg" rows="3" placeholder="Notification message লিখুন..." style="width:100%;padding:9px;background:var(--inp);border:1px solid var(--bdr);border-radius:8px;color:var(--text);font-size:.85em;resize:vertical;box-sizing:border-box;"></textarea>
      </div>
      <div id="sel_notif_result" style="display:none;font-size:.83em;padding:8px 12px;border-radius:8px;"></div>
      <div style="display:flex;gap:8px;">
        <button onclick="sendNotifySelected()" style="flex:1;padding:10px;background:var(--blue);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:.88em;">📤 পাঠান</button>
        <button onclick="closeNotifySelectedModal()" style="padding:10px 18px;background:var(--inp);color:var(--muted);border:1px solid var(--bdr);border-radius:8px;cursor:pointer;font-size:.88em;">বাতিল</button>
      </div>
    </div>
  </div>
</div>
</body>
</html>
<?php ob_end_flush(); ?>
