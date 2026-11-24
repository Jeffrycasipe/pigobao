<?php
session_start();

/* If already signed in, skip the portal and go straight to the dashboard */
if (isset($_SESSION['admin_id'])) {
    header("Location: admin_dashboard.php");
    exit();
}
if (isset($_SESSION['technician_id'])) {
    header("Location: technician_dashboard.php");
    exit();
}

/* Login page file names (change here if your files are named differently) */
$adminLogin      = "adminindex.php";
$technicianLogin = "technicianindex.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PIGOBAO - Select Portal</title>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#123c16">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
--forest-900:#123c16;--forest-800:#1b5e20;--forest-600:#2e7d32;--forest-100:#e8f5e9;
--canvas:#f3f6f4;--ink:#1f2b22;--ink-soft:#4f5d53;--line:#dce5de;
--gov-navy:#0b2f5b;--gov-gold:#c9a227;--gov-red:#b3262e;
--shadow:0 1px 2px rgba(18,60,22,.05),0 14px 34px -16px rgba(18,60,22,.28);
}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;-webkit-tap-highlight-color:transparent;}
html{overflow-x:hidden;}
img{max-width:100%;height:auto;}
body{min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;color:var(--ink);
background:linear-gradient(180deg,#f3f6f4,#e4eee8);}

/* top hero */
.hero{background:linear-gradient(135deg,var(--forest-900),var(--forest-800) 60%,var(--forest-600));
color:#fff;text-align:center;padding:calc(28px + env(safe-area-inset-top,0px)) 24px 70px;position:relative;}
.hero::after{content:"";position:absolute;left:0;right:0;bottom:0;height:5px;
background:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%);}
.seals{display:flex;justify-content:center;gap:12px;margin-bottom:14px;}
.seal{width:62px;height:62px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px rgba(255,255,255,.35);}
.seal img{width:100%;height:100%;object-fit:contain;padding:5px;}
.republic{font-size:11px;font-weight:500;letter-spacing:.4px;color:rgba(255,255,255,.8);line-height:1.5;}
.hero h1{font-family:'Merriweather',serif;font-size:32px;letter-spacing:2px;margin:8px 0 2px;}
.hero p.sub{font-size:13.5px;font-weight:500;color:rgba(255,255,255,.9);}

/* main */
main{flex:1;width:100%;max-width:460px;margin:-42px auto 0;padding:0 18px 20px;position:relative;z-index:2;}
.panel{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:22px 18px 18px;}
.panel h2{font-family:'Merriweather',serif;font-size:18px;color:var(--forest-900);text-align:center;}
.panel .hint{text-align:center;font-size:12.5px;color:var(--ink-soft);margin:4px 0 18px;}

/* role buttons */
.role{display:flex;align-items:center;gap:14px;width:100%;min-height:84px;padding:14px 16px;margin-bottom:12px;
border-radius:14px;text-decoration:none;color:inherit;border:1px solid var(--line);background:#fafcfb;
transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;}
.role:active{transform:scale(.98);}
.role:hover{border-color:var(--forest-600);box-shadow:0 8px 20px -10px rgba(27,94,32,.4);}
.role:focus-visible{outline:3px solid var(--gov-gold);outline-offset:2px;}
.role .ico{flex:0 0 54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:23px;color:#fff;}
.role.admin .ico{background:linear-gradient(145deg,var(--gov-navy),#14498a);}
.role.tech .ico{background:linear-gradient(145deg,var(--forest-600),var(--forest-900));}
.role .txt{flex:1;min-width:0;}
.role .txt strong{display:block;font-size:16px;font-weight:600;color:var(--forest-900);}
.role .txt span{display:block;font-size:12px;color:var(--ink-soft);line-height:1.4;margin-top:2px;}
.role .go{color:var(--forest-600);font-size:16px;}
.role.admin{border-left:5px solid var(--gov-navy);}
.role.tech{border-left:5px solid var(--forest-600);}

.note{margin-top:6px;padding-top:14px;border-top:1px solid var(--line);text-align:center;font-size:11.5px;color:var(--ink-soft);line-height:1.6;}
.note i{color:var(--forest-600);margin-right:5px;}

/* footer */
footer{background:#0a1f3d;color:#c5d3e6;font-size:11.5px;text-align:center;line-height:1.6;
padding:14px 16px calc(14px + env(safe-area-inset-bottom,0px));border-top:3px solid var(--gov-gold);}

@media(min-width:700px){
.hero{padding-bottom:80px;}
main{max-width:500px;}
}
@media(prefers-reduced-motion:reduce){*{transition:none!important;}}
</style>
</head>
<body>

<section class="hero">
    <div class="seals">
        <div class="seal"><img src="img/CABATUAN.png" alt="Municipality of Cabatuan seal"></div>
        <div class="seal"><img src="img/DA.png" alt="Department of Agriculture seal"></div>
    </div>
    <p class="republic">Republic of the Philippines<br>Province of Iloilo &bull; Municipality of Cabatuan</p>
    <h1>PIGOBAO</h1>
    <p class="sub"> Development of a Web-Based Livestock Distribution and Monitoring System with Data Analytics
</p>
</section>

<main>
    <div class="panel">
        <h2>Welcome</h2>
        <p class="hint">Choose how you want to sign in.</p>

        <a class="role admin" href="<?=htmlspecialchars($adminLogin)?>">
            <span class="ico"><i class="fas fa-shield-halved"></i></span>
            <span class="txt">
                <strong>Admin</strong>
                <span>Manage beneficiaries, distribution and reports</span>
            </span>
            <i class="fas fa-chevron-right go"></i>
        </a>

        <a class="role tech" href="<?=htmlspecialchars($technicianLogin)?>">
            <span class="ico"><i class="fas fa-user-shield"></i></span>
            <span class="txt">
                <strong>Technician</strong>
                <span>Record field activities and livestock updates</span>
            </span>
            <i class="fas fa-chevron-right go"></i>
        </a>

        <p class="note"><i class="fas fa-lock"></i>Unauthorized access is prohibited. Contact the Municipal Agriculture Office for account assistance.</p>
    </div>
</main>

<footer>
    PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo<br>
    &copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.
</footer>

</body>
</html>