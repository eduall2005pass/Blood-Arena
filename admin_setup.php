<?php
/**
 * BloodArena — Admin Password Setup
 * একবার use করার পর DELETE করুন!
 */

$config_file = __DIR__ . '/admin_config.php';
$already_set = file_exists($config_file);
$msg = ''; $msg_type = ''; $setup_done = false;

if(isset($_POST['setup'])){
    $pass  = $_POST['new_pass']     ?? '';
    $pass2 = $_POST['confirm_pass'] ?? '';
    if(strlen($pass) < 10)                        { $msg='❌ কমপক্ষে ১০ character দিন।'; $msg_type='err'; }
    elseif(!preg_match('/[A-Z]/', $pass))         { $msg='❌ একটি বড় হাতের অক্ষর (A-Z) লাগবে।'; $msg_type='err'; }
    elseif(!preg_match('/[0-9]/', $pass))         { $msg='❌ একটি সংখ্যা লাগবে।'; $msg_type='err'; }
    elseif(!preg_match('/[\W_]/', $pass))         { $msg='❌ একটি চিহ্ন (@#$!) লাগবে।'; $msg_type='err'; }
    elseif($pass !== $pass2)                      { $msg='❌ দুটো password মিলছে না।'; $msg_type='err'; }
    else {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
        $content = "<?php\n// BloodArena Admin Password Config\n// এই file কাউকে দেখাবেন না।\ndefine('ADMIN_HASH', ".var_export($hash,true).");\n";
        if(file_put_contents($config_file, $content) !== false){
            $setup_done = true;
            $msg = '✅ Password সফলভাবে set হয়েছে!'; $msg_type='ok';
        } else { $msg='❌ File লেখা যায়নি। Write permission চেক করুন।'; $msg_type='err'; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Admin Setup</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:#0f1115;color:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;}
.box{background:#1a1d24;border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:36px;width:100%;max-width:400px;}
h1{color:#dc2626;font-size:1.45rem;margin-bottom:4px;text-align:center;}
.sub{color:#9ca3af;font-size:.85em;text-align:center;margin-bottom:22px;}
label{font-size:.82em;color:#9ca3af;display:block;margin-bottom:4px;}
input{width:100%;padding:12px;background:rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.08);border-radius:10px;color:#f3f4f6;font-size:.95rem;margin-bottom:14px;outline:none;}
input:focus{border-color:#dc2626;}
button{width:100%;padding:13px;background:#dc2626;color:#fff;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;}
button:hover{background:#b91c1c;}
.msg{border-radius:10px;padding:12px;margin-bottom:14px;font-size:.87em;font-weight:600;}
.ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#10b981;}
.err{background:rgba(220,38,38,.12);border:1px solid rgba(220,38,38,.3);color:#ef4444;}
.warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:#f59e0b;border-radius:10px;padding:11px;font-size:.83em;margin-bottom:16px;}
.rules{background:rgba(255,255,255,.03);border-radius:8px;padding:12px;margin-bottom:14px;font-size:.81em;color:#9ca3af;line-height:1.9;}
.rules span{color:#10b981;}
.done{text-align:center;} .done .ic{font-size:3rem;margin-bottom:10px;}
.done h2{color:#10b981;margin-bottom:8px;} .done p{color:#9ca3af;font-size:.87em;margin-bottom:5px;}
.del-warn{background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.3);color:#ef4444;border-radius:8px;padding:12px;margin:14px 0;font-size:.84em;font-weight:600;}
a.btn{display:block;padding:12px;background:#dc2626;color:#fff;border-radius:10px;text-decoration:none;font-weight:700;margin-top:10px;text-align:center;}
</style>
</head>
<body><div class="box">
<?php if($setup_done): ?>
<div class="done">
  <div class="ic">✅</div>
  <h2>Password Set হয়েছে!</h2>
  <p><code>admin_config.php</code> তৈরি হয়েছে।</p>
  <div class="del-warn">⚠️ <strong>এখনই admin_setup.php DELETE করুন!</strong><br>না করলে যে কেউ password বদলাতে পারবে।</div>
  <a href="admin.php" class="btn">🔐 Admin Login করুন</a>
</div>
<?php elseif($already_set): ?>
<div class="done">
  <div class="ic">🔒</div>
  <h2>Password আগেই Set আছে</h2>
  <p>Change করতে প্রথমে <code>admin_config.php</code> delete করুন, তারপর reload করুন।</p>
  <a href="admin.php" class="btn">← Admin Panel</a>
</div>
<?php else: ?>
<div style="font-size:2rem;text-align:center;margin-bottom:8px;">🔑</div>
<h1>Admin Password Setup</h1>
<p class="sub">একবারই করতে হবে</p>
<div class="warn">⚠️ Setup শেষে এই file <strong>delete</strong> করুন।</div>
<?php if($msg): ?><div class="msg <?=$msg_type?>"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div class="rules">
  Password-এ অবশ্যই থাকতে হবে:<br>
  <span>✔</span> কমপক্ষে ১০ character<br>
  <span>✔</span> একটি বড় হাতের অক্ষর (A-Z)<br>
  <span>✔</span> একটি সংখ্যা (0-9)<br>
  <span>✔</span> একটি চিহ্ন (@#$! ইত্যাদি)
</div>
<form method="POST">
  <label>নতুন Password</label>
  <input type="password" name="new_pass" placeholder="নতুন password" required autocomplete="new-password">
  <label>আবার লিখুন</label>
  <input type="password" name="confirm_pass" placeholder="confirm password" required autocomplete="new-password">
  <button type="submit" name="setup">🔐 Password Set করুন</button>
</form>
<?php endif; ?>
</div></body></html>
