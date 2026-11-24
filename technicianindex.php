<?php
session_start();
require 'db.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Please enter your username and password.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM technicians WHERE username = ? LIMIT 1");

        if (!$stmt) {
            $error = "Database error: " . $conn->error;
        } else {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($tech = $result->fetch_assoc()) {
                $storedPassword = (string)($tech['password'] ?? '');
                $passwordCorrect = false;

                // Check hashed password
                if ($storedPassword !== '' && password_verify($password, $storedPassword)) {
                    $passwordCorrect = true;
                }

                // Support existing plain-text passwords
                if (!$passwordCorrect && $storedPassword !== '' && hash_equals($storedPassword, $password)) {
                    $passwordCorrect = true;
                }

                if ($passwordCorrect) {
                    if (isset($tech['status']) && strtolower(trim($tech['status'])) !== 'active') {
                        $error = "Your technician account is inactive!";
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['technician_id'] = (int)$tech['technician_id'];
                        $_SESSION['username'] = $tech['username'];
                        $_SESSION['name'] = $tech['name'];

                        header("Location: technician_dashboard.php");
                        exit();
                    }
                } else {
                    $error = "Incorrect password!";
                }
            } else {
                $error = "Technician not found!";
            }

            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Technician Login - PIGOBAO</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
--forest-900:#123c16;--forest-800:#1b5e20;--forest-600:#2e7d32;--forest-100:#e8f5e9;
--canvas:#f3f6f4;--ink:#1f2b22;--ink-soft:#4f5d53;--line:#dce5de;
--gov-navy:#0b2f5b;--gov-gold:#c9a227;--gov-red:#b3262e;
--shadow:0 1px 2px rgba(18,60,22,.05),0 14px 34px -16px rgba(18,60,22,.28);
--strip-h:32px;--header-h:70px;
}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
html{overflow-x:hidden;}
img{max-width:100%;height:auto;}
body{min-height:100vh;display:flex;flex-direction:column;background:var(--canvas);color:var(--ink);
background-image:linear-gradient(180deg,rgba(243,246,244,.96),rgba(232,240,235,.96));}

/* government strip */
.gov-strip{position:fixed;top:0;left:0;right:0;height:var(--strip-h);background:#0a1f3d;color:#dbe5f3;
display:flex;align-items:center;justify-content:space-between;padding:0 30px;font-size:12px;letter-spacing:.3px;z-index:1003;
border-bottom:3px solid transparent;
border-image:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%) 1;}
.gov-strip i{color:var(--gov-gold);margin-right:8px;}
.gov-strip strong{color:#fff;font-weight:600;}
.gov-strip .right{opacity:.85;}

/* header */
header{position:fixed;top:var(--strip-h);left:0;width:100%;height:var(--header-h);
background:linear-gradient(120deg,var(--forest-900),var(--forest-800) 60%,var(--forest-600));
color:#fff;display:flex;align-items:center;padding:0 30px;z-index:1002;box-shadow:0 4px 16px rgba(10,40,12,.28);}
.brand{display:flex;align-items:center;gap:14px;}
.seals{display:flex;gap:8px;}
.seal{width:46px;height:46px;border-radius:50%;background:#fff;overflow:hidden;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 2px rgba(255,255,255,.35);}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.titles{padding-left:14px;border-left:1px solid rgba(255,255,255,.28);}
.titles h1{font-family:'Merriweather',serif;font-size:19px;letter-spacing:1.2px;line-height:1.15;}
.titles small{display:block;font-size:12px;color:rgba(255,255,255,.78);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;}

/* layout */
.login-page{flex:1;padding:calc(var(--strip-h) + var(--header-h) + 36px) 30px 30px;display:flex;align-items:center;justify-content:center;}
.login-wrapper{width:100%;max-width:1060px;display:grid;grid-template-columns:1fr 420px;gap:46px;align-items:center;}

/* branding panel */
.branding-panel{text-align:center;padding:10px 20px;}
.government-logos{display:flex;justify-content:center;gap:28px;margin-bottom:18px;}
.government-logos img{height:100px;width:auto;object-fit:contain;}
.livestock-logo{width:220px;max-width:70%;margin-bottom:14px;}
.republic{font-size:11.5px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;color:var(--ink-soft);}
.branding-panel h2{font-family:'Merriweather',serif;color:var(--forest-900);font-size:30px;letter-spacing:1.5px;margin:6px 0 4px;}
.branding-panel .sub{color:var(--forest-800);font-weight:600;font-size:13.5px;margin-bottom:12px;}
.divider{width:90px;height:3px;margin:0 auto 14px;background:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%);}
.branding-panel p.desc{color:var(--ink-soft);font-size:13px;line-height:1.7;max-width:440px;margin:0 auto 18px;}
.notice{display:flex;gap:12px;text-align:left;max-width:440px;margin:0 auto;background:#fff;border:1px solid var(--line);
border-left:5px solid var(--gov-gold);border-radius:10px;padding:12px 16px;font-size:12.5px;color:var(--ink-soft);box-shadow:var(--shadow);}
.notice i{color:var(--gov-navy);font-size:18px;margin-top:2px;}
.notice strong{display:block;color:var(--forest-900);font-size:12.5px;margin-bottom:2px;}

/* login card */
.login-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:0 32px 26px;box-shadow:var(--shadow);overflow:hidden;}
.card-bar{height:6px;margin:0 -32px 26px;background:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%);}
.login-header{text-align:center;margin-bottom:22px;}
.login-icon{width:58px;height:58px;margin:0 auto 12px;border-radius:50%;background:linear-gradient(145deg,var(--forest-600),var(--forest-900));
color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 0 0 4px var(--forest-100),0 8px 18px -6px rgba(18,60,22,.5);}
.login-header h2{font-family:'Merriweather',serif;color:var(--forest-900);font-size:20px;margin-bottom:4px;}
.login-header p{color:var(--ink-soft);font-size:12.5px;}
.error{background:#fbe9e7;color:#b3402c;border:1px solid #f1c3bb;border-left:4px solid var(--gov-red);padding:10px 12px;border-radius:8px;font-size:12.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:12px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--forest-900);margin-bottom:7px;}
.input-wrapper{position:relative;}
.input-wrapper>i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--forest-600);font-size:14px;}
.login-card input{width:100%;height:46px;padding:10px 42px 10px 40px;border-radius:8px;border:1px solid #cfd9d2;background:#fafcfb;color:var(--ink);font-size:13.5px;outline:none;transition:.2s ease;}
.login-card input::placeholder{color:#9aa69d;}
.login-card input:focus{border-color:var(--forest-600);background:#fff;box-shadow:0 0 0 3px rgba(46,125,50,.15);}
.password-toggle{position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--ink-soft);cursor:pointer;font-size:14px;transition:.2s;}
.password-toggle:hover{color:var(--forest-800);}
.login-button{width:100%;height:47px;border:none;border-radius:8px;background:linear-gradient(120deg,var(--forest-900),var(--forest-800) 60%,var(--forest-600));
color:#fff;font-size:14px;font-weight:600;letter-spacing:.5px;cursor:pointer;transition:.2s ease;margin-top:4px;border-bottom:3px solid var(--gov-gold);box-shadow:0 6px 16px rgba(27,94,32,.25);}
.login-button:hover{transform:translateY(-1px);box-shadow:0 10px 20px rgba(27,94,32,.32);}
.login-button:focus-visible,.password-toggle:focus-visible{outline:2px solid var(--gov-gold);outline-offset:2px;}
.secure-note{margin-top:18px;padding-top:14px;border-top:1px solid var(--line);text-align:center;font-size:11.5px;color:var(--ink-soft);line-height:1.6;}
.secure-note i{color:var(--forest-600);margin-right:5px;}

/* footer */
.gov-footer{background:#0a1f3d;color:#c5d3e6;font-size:12px;padding:14px 30px;border-top:3px solid var(--gov-gold);}
.gov-footer .inner{max-width:1060px;margin:0 auto;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;}

@media(max-width:900px){
.login-wrapper{grid-template-columns:1fr;max-width:440px;gap:24px;}
.government-logos img{height:78px;}
.livestock-logo{width:160px;}
.branding-panel h2{font-size:24px;}
}
@media(max-width:600px){
:root{--strip-h:30px;--header-h:62px;}
.gov-strip{padding:0 14px;font-size:11px;}.gov-strip .right{display:none;}
header{padding:0 14px;}
.seal{width:38px;height:38px;}
.titles{padding-left:10px;}.titles h1{font-size:15px;letter-spacing:.8px;}.titles small{font-size:10px;}
.login-page{padding:calc(var(--strip-h) + var(--header-h) + 22px) 15px 22px;}
.login-card{padding:0 20px 22px;}.card-bar{margin:0 -20px 22px;}
.government-logos img{height:62px;}
.branding-panel p.desc{font-size:12px;}
.gov-footer{padding:12px 15px;}.gov-footer .inner{justify-content:center;text-align:center;}
}
</style>
</head>
<body>

<div class="gov-strip">
    <span class="left"><i class="fas fa-landmark"></i><strong>Republic of the Philippines</strong> &nbsp;|&nbsp; Province of Iloilo &bull; Municipality of Cabatuan</span>
    <span class="right">Official Government Portal</span>
</div>

<header>
    <div class="brand">
        <div class="seals">
            <div class="seal"><img src="img/CABATUAN.png" alt="Municipality of Cabatuan seal"></div>
            <div class="seal"><img src="img/DA.png" alt="Department of Agriculture seal"></div>
        </div>
        <div class="titles">
            <h1>PIGOBAO</h1>
            <small>Technician Portal &bull; Municipal Agriculture Office</small>
        </div>
    </div>
</header>

<main class="login-page">
    <div class="login-wrapper">
        <section class="branding-panel">
            <div class="government-logos">
                <img src="img/CABATUAN.png" alt="Municipality of Cabatuan">
                <img src="img/DA.png" alt="Department of Agriculture">
            </div>
            <img src="img/technician_logo.png" alt="PIGOBAO Technician Logo" class="livestock-logo">
            <p class="republic">Municipality of Cabatuan, Iloilo</p>
            <h2>PIGOBAO</h2>
            <p class="sub">Livestock Management System</p>
            <div class="divider"></div>
            <p class="desc">Official platform of the Municipal Agriculture Office for monitoring livestock distribution, beneficiaries, and reports.</p>
        </section>

        <section class="login-card">
            <div class="card-bar"></div>
            <div class="login-header">
                <div class="login-icon"><i class="fas fa-user-shield"></i></div>
                <h2>Technician Login</h2>
                <p>Sign in with your official technician account.</p>
            </div>

            <?php if($error): ?>
                <div class="error" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?=htmlspecialchars($error)?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" value="<?=htmlspecialchars($_POST['username'] ?? '')?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <span class="password-toggle" id="passwordToggle" title="Show password" role="button" tabindex="0"><i class="fas fa-eye"></i></span>
                    </div>
                </div>

                <button type="submit" name="login" class="login-button">
                    <i class="fas fa-right-to-bracket"></i>&nbsp; Sign In
                </button>
            </form>

            <p class="secure-note"><i class="fas fa-lock"></i>Unauthorized access is prohibited. Contact the Municipal Agriculture Office if you need account assistance.</p>
        </section>
    </div>
</main>

<footer class="gov-footer">
    <div class="inner">
        <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
        <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
    </div>
</footer>

<script>
const passwordInput=document.getElementById('password');
const passwordToggle=document.getElementById('passwordToggle');
function togglePassword(){
    const icon=passwordToggle.querySelector('i');
    if(passwordInput.type==='password'){
        passwordInput.type='text';
        icon.classList.replace('fa-eye','fa-eye-slash');
        passwordToggle.title='Hide password';
    }else{
        passwordInput.type='password';
        icon.classList.replace('fa-eye-slash','fa-eye');
        passwordToggle.title='Show password';
    }
}
passwordToggle.addEventListener('click',togglePassword);
passwordToggle.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();togglePassword();}});
</script>
</body>
</html>