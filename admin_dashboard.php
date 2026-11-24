<?php
session_start();
require 'db.php';

if(!isset($_SESSION['admin_id'])){
    header("Location: adminindex.php");
    exit();
}

$admin_id=$_SESSION['admin_id'];

$stmt=$conn->prepare("SELECT * FROM admins WHERE admin_id=?");
$stmt->bind_param("i",$admin_id);
$stmt->execute();
$admin=$stmt->get_result()->fetch_assoc();

/* =========================
   YEAR FILTER
   ========================= */

/* figure out which years actually have data, for the dropdown */
$yearRows=$conn->query("
    SELECT DISTINCT yr FROM (
        SELECT YEAR(created_at)    AS yr FROM technicians
        UNION SELECT YEAR(registered_at) FROM beneficiaries
        UNION SELECT YEAR(created_at)    FROM livestock
        UNION SELECT YEAR(distribution_date) FROM distributions
    ) t
    WHERE yr IS NOT NULL
    ORDER BY yr DESC
");
$availableYears=[];
if($yearRows){
    while($yr=$yearRows->fetch_assoc()){
        $availableYears[]=(int)$yr['yr'];
    }
}
if(empty($availableYears)){
    $availableYears[]=(int)date('Y');
}
if(!in_array((int)date('Y'),$availableYears,true)){
    array_unshift($availableYears,(int)date('Y'));
}

$yearParam=$_GET['year']??date('Y');

if($yearParam==='all'){
    $selectedYear='all';
}elseif(preg_match('/^\d{4}$/',$yearParam)){
    $selectedYear=(int)$yearParam;
}else{
    $selectedYear=(int)date('Y');
}

/* SQL snippet: "" when showing all-time, "AND YEAR(col)=1234" otherwise */
function yearFilter($column,$selectedYear){
    if($selectedYear==='all'){
        return '';
    }
    return "AND YEAR($column)=".(int)$selectedYear;
}

/* DASHBOARD COUNTS
   ========================= */

/* ACTIVE TECHNICIANS */
$total_tech=0;
$result=$conn->query("
    SELECT COUNT(*) AS total
    FROM technicians
    WHERE status='active'
    ".yearFilter('created_at',$selectedYear)."
");

if($result && $row=$result->fetch_assoc()){
    $total_tech=(int)$row['total'];
}

/* ACTIVE BENEFICIARIES */
$total_beneficiaries=0;
$result=$conn->query("
    SELECT COUNT(*) AS total
    FROM beneficiaries
    WHERE status='active'
    ".yearFilter('registered_at',$selectedYear)."
");

if($result && $row=$result->fetch_assoc()){
    $total_beneficiaries=(int)$row['total'];
}

/* TOTAL LIVESTOCK RECORDS */
$total_livestock=0;
$result=$conn->query("
    SELECT COUNT(*) AS total
    FROM livestock
    WHERE 1=1
    ".yearFilter('created_at',$selectedYear)."
");

if($result && $row=$result->fetch_assoc()){
    $total_livestock=(int)$row['total'];
}

/* TOTAL LIVESTOCK DISTRIBUTED */
$total_distributed=0;
$result=$conn->query("
    SELECT COALESCE(SUM(quantity),0) AS total
    FROM distributions
    WHERE 1=1
    ".yearFilter('distribution_date',$selectedYear)."
");

if($result && $row=$result->fetch_assoc()){
    $total_distributed=(int)$row['total'];
}

/* RETURNED LIVESTOCK */
$returned_qty=0;
$result=$conn->query("
    SELECT COALESCE(SUM(quantity),0) AS total
    FROM distributions
    WHERE status='returned'
    ".yearFilter('return_date',$selectedYear)."
");

if($result && $row=$result->fetch_assoc()){
    $returned_qty=(int)$row['total'];
}

/* DECEASED LIVESTOCK */
$death_qty=0;
$result=$conn->query("
    SELECT COALESCE(SUM(quantity),0) AS total
    FROM distributions
    WHERE status='death'
    ".yearFilter('death_date',$selectedYear)."
");

if($result && $row=$result->fetch_assoc()){
    $death_qty=(int)$row['total'];
}

/* ADMIN INITIALS */
$emailLocalPart=explode('@',$admin['email']??'admin')[0];
$emailPieces=preg_split('/[._\-]+/',$emailLocalPart);
$initials='';

foreach(array_slice($emailPieces,0,2) as $p){
    if($p!==''){
        $initials.=mb_strtoupper(mb_substr($p,0,1));
    }
}

if($initials===''){
    $initials='A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard - PIGOBAO</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<style>
:root{
--pg-forest-900:#123c16;
--pg-forest-800:#1b5e20;
--pg-forest-600:#2e7d32;
--pg-forest-300:#a5d6a7;
--pg-forest-100:#e8f5e9;
--pg-canvas:#f3f6f4;
--pg-ink:#1f2b22;
--pg-ink-soft:#4f5d53;
--pg-line:#dce5de;
--gov-navy:#0b2f5b;
--gov-gold:#c9a227;
--gov-red:#b3262e;
--shadow-soft:0 1px 2px rgba(18,60,22,.05),0 10px 26px -14px rgba(18,60,22,.22);
--strip-h:32px;
--header-h:70px;
--top-offset:calc(var(--strip-h) + var(--header-h));
}

*{
box-sizing:border-box;
margin:0;
padding:0;
font-family:'Poppins',sans-serif;
}

body{
min-height:100vh;
display:flex;
background:var(--pg-canvas);
color:var(--pg-ink);
font-size:15px;
line-height:1.5;
-webkit-font-smoothing:antialiased;
}

/* rice-field photo kept, but softened so the page reads as an official portal */
body::before{
content:"";
position:fixed;
inset:0;
z-index:-2;
background-image:url('img/rice field.jpg');
background-size:cover;
background-position:center 40%;
background-repeat:no-repeat;
background-attachment:fixed;
}
body::after{
content:"";
position:fixed;
inset:0;
z-index:-1;
background:linear-gradient(180deg,rgba(243,246,244,.95),rgba(236,242,238,.90));
}

/* =========================
   OFFICIAL GOVERNMENT STRIP
   ========================= */
.gov-strip{
position:fixed;
top:0;left:0;right:0;
height:var(--strip-h);
background:#0a1f3d;
color:#dbe5f3;
display:flex;
align-items:center;
justify-content:space-between;
padding:0 30px;
font-size:12px;
letter-spacing:.3px;
z-index:1003;
border-bottom:3px solid transparent;
border-image:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%) 1;
}
.gov-strip .left{display:flex;align-items:center;gap:8px;}
.gov-strip .left i{color:var(--gov-gold);}
.gov-strip .right{opacity:.85;}
.gov-strip strong{color:#fff;font-weight:600;}

/* =========================
   HEADER
   ========================= */
header{
position:fixed;
top:var(--strip-h);
left:0;
width:100%;
height:var(--header-h);
background:linear-gradient(120deg,var(--pg-forest-900),var(--pg-forest-800) 60%,var(--pg-forest-600));
color:#fff;
display:flex;
justify-content:space-between;
align-items:center;
padding:0 30px;
z-index:1002;
box-shadow:0 4px 16px rgba(10,40,12,.28);
}

header .brand{
display:flex;
align-items:center;
gap:14px;
}

.seal{
width:46px;height:46px;
border-radius:50%;
background:#fff;
display:flex;align-items:center;justify-content:center;
overflow:hidden;
flex:none;
box-shadow:0 0 0 2px rgba(255,255,255,.35);
}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.seal.fallback{font-size:18px;color:var(--pg-forest-800);}

header .brand .titles{
padding-left:14px;
border-left:1px solid rgba(255,255,255,.28);
}
header h1{
font-family:'Merriweather',serif;
font-size:19px;
font-weight:700;
letter-spacing:1.2px;
line-height:1.15;
}
header .brand small{
display:block;
font-size:12px;
font-weight:400;
color:rgba(255,255,255,.78);
letter-spacing:.5px;
text-transform:uppercase;
margin-top:2px;
}

header a.logout{
color:var(--pg-forest-900);
background:#fff;
padding:9px 22px;
border-radius:8px;
font-weight:600;
text-decoration:none;
font-size:14px;
display:inline-flex;align-items:center;gap:8px;
box-shadow:0 2px 8px rgba(0,0,0,.14);
transition:.2s ease;
}
header a.logout:hover{
background:var(--pg-forest-100);
transform:translateY(-1px);
}

/* =========================
   SIDEBAR
   ========================= */
.sidebar{
position:fixed;
top:var(--top-offset);
left:0;
width:250px;
height:calc(100% - var(--top-offset));
background:linear-gradient(195deg,#164f1b,var(--pg-forest-800) 45%,var(--pg-forest-600) 130%);
padding-top:14px;
box-shadow:inset -1px 0 0 rgba(255,255,255,.06);
overflow-y:auto;
z-index:1000;
}

.sidebar .menu-title{
font-size:11.5px;
font-weight:600;
letter-spacing:1.4px;
text-transform:uppercase;
color:rgba(255,255,255,.55);
padding:6px 26px 8px;
}

.sidebar a{
display:flex;
align-items:center;
gap:13px;
color:rgba(255,255,255,.92);
padding:12px 20px;
text-decoration:none;
margin:2px 12px 3px;
border-radius:8px;
border-left:4px solid transparent;
font-size:15px;
font-weight:500;
transition:.2s ease;
position:relative;
}
.sidebar a i{
width:20px;
text-align:center;
font-size:15.5px;
opacity:.92;
}
.sidebar a:hover{
background:rgba(255,255,255,.12);
transform:translateX(2px);
}
.sidebar a.active{
background:#fff;
color:var(--pg-forest-900);
border-left:4px solid var(--gov-gold);
font-weight:600;
box-shadow:0 6px 16px rgba(0,0,0,.2);
}

/* =========================
   MAIN CONTENT
   ========================= */
.main-content{
margin-left:250px;
padding:calc(var(--top-offset) + 26px) 34px 20px;
flex:1;
width:calc(100% - 250px);
min-width:0;
display:flex;
flex-direction:column;
min-height:100vh;
}

/* =========================
   WELCOME BANNER
   ========================= */
.profile-card{
background:#fff;
border-radius:12px;
padding:22px 26px;
box-shadow:var(--shadow-soft);
border:1px solid var(--pg-line);
border-left:6px solid var(--pg-forest-800);
display:flex;
align-items:center;
gap:18px;
flex-wrap:wrap;
margin-bottom:26px;
position:relative;
overflow:hidden;
}
.profile-card::after{
content:"\f0c0";
font-family:"Font Awesome 6 Free";
font-weight:900;
position:absolute;
right:-14px;bottom:-34px;
font-size:130px;
color:var(--pg-forest-100);
z-index:0;
pointer-events:none;
}
.profile-card > *{position:relative;z-index:1;}

.avatar-badge{
width:60px;
height:60px;
border-radius:50%;
flex:none;
background:linear-gradient(145deg,var(--pg-forest-600),var(--pg-forest-900));
display:flex;
align-items:center;
justify-content:center;
color:#fff;
font-family:'Space Grotesk',sans-serif;
font-weight:700;
font-size:21px;
box-shadow:0 0 0 4px var(--pg-forest-100);
}
.profile-info .date{
font-size:12.5px;
font-weight:600;
letter-spacing:.6px;
text-transform:uppercase;
color:var(--gov-navy);
margin-bottom:2px;
display:flex;align-items:center;gap:7px;
}
.profile-info h2{
font-family:'Space Grotesk',sans-serif;
font-weight:700;
font-size:23px;
margin:0 0 3px;
color:var(--pg-forest-900);
word-break:break-word;
}
.profile-info p{
font-size:14px;
color:var(--pg-ink-soft);
margin:0;
max-width:640px;
}
.role-pill{
margin-left:auto;
padding:8px 16px;
border-radius:8px;
font-size:13px;
font-weight:600;
background:var(--pg-forest-900);
color:#fff;
display:inline-flex;
align-items:center;
gap:8px;
letter-spacing:.3px;
}
.role-pill i{color:var(--gov-gold);}

/* =========================
   SECTION HEADINGS
   ========================= */
.section-label{
font-family:'Space Grotesk',sans-serif;
font-size:14px;
font-weight:700;
letter-spacing:.9px;
text-transform:uppercase;
color:var(--pg-forest-900);
display:flex;
align-items:center;
gap:10px;
}
.section-label::before{
content:"";
width:5px;height:20px;
border-radius:3px;
background:linear-gradient(180deg,var(--gov-gold),var(--pg-forest-600));
}

.overview-bar{
display:flex;
align-items:flex-end;
justify-content:space-between;
flex-wrap:wrap;
gap:10px;
margin:0 0 14px;
}
.overview-bar .period{
font-size:13px;
color:var(--pg-ink-soft);
margin-top:3px;
padding-left:15px;
}
.overview-bar .period strong{color:var(--pg-forest-900);}

.year-filter{
display:flex;
align-items:center;
gap:10px;
background:#fff;
border:1px solid var(--pg-line);
border-radius:10px;
padding:6px 8px 6px 14px;
box-shadow:var(--shadow-soft);
}
.year-filter label{
font-size:13px;
font-weight:600;
color:var(--pg-ink-soft);
display:flex;align-items:center;gap:7px;
}
.year-filter select{
font-family:'Poppins',sans-serif;
font-size:14px;
font-weight:600;
color:var(--pg-forest-900);
background:var(--pg-canvas);
border:1px solid var(--pg-line);
border-radius:7px;
padding:7px 12px;
cursor:pointer;
}
.year-filter select:focus{outline:2px solid var(--pg-forest-300);}

/* =========================
   KPI CARDS
   ========================= */
.dashboard-cards{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:18px;
}

.card{
--accent:#1b5e20;
--accent-soft:#e8f5e9;
position:relative;
padding:22px 24px 18px;
border:1px solid var(--pg-line);
border-top:5px solid var(--accent);
border-radius:12px;
background:#fff;
box-shadow:var(--shadow-soft);
display:flex;
flex-direction:column;
gap:6px;
transition:transform .2s ease,box-shadow .2s ease;
}
.card:hover{
transform:translateY(-3px);
box-shadow:0 16px 30px -16px rgba(18,60,22,.38);
}

.card-top{
display:flex;
align-items:center;
justify-content:space-between;
gap:12px;
}
.card-label{
font-family:'Space Grotesk',sans-serif;
font-size:13.5px;
font-weight:700;
letter-spacing:.7px;
text-transform:uppercase;
color:var(--pg-ink-soft);
}
.card-icon{
width:48px;height:48px;
border-radius:12px;
display:flex;align-items:center;justify-content:center;
font-size:21px;
flex:none;
background:var(--accent-soft);
color:var(--accent);
}
.card .number{
font-family:'Space Grotesk',sans-serif;
font-size:52px;
line-height:1.05;
font-weight:700;
color:var(--accent);
margin-top:4px;
}
.card .description{
font-size:14px;
font-weight:500;
color:var(--pg-ink-soft);
}
.card .card-foot{
margin-top:10px;
padding-top:11px;
border-top:1px dashed var(--pg-line);
font-size:12.5px;
color:var(--pg-ink-soft);
display:flex;align-items:center;gap:7px;
}
.card .card-foot i{color:var(--accent);}

.card.-tech{--accent:#0b2f5b;--accent-soft:#e6eef8;}
.card.-beneficiaries{--accent:#1b5e20;--accent-soft:#e8f5e9;}
.card.-livestock{--accent:#b7791f;--accent-soft:#fbf1dd;}
.card.-distributions{--accent:#4a3f8f;--accent-soft:#ece9f8;}
.card.-returned{--accent:#00707a;--accent-soft:#dff3f4;}
.card.-records{--accent:#9b2c2c;--accent-soft:#f9e6e6;}

/* =========================
   QUICK ACCESS
   ========================= */
.quick-access{margin-top:30px;}
.quick-grid{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:14px;
margin-top:14px;
}
.quick-link{
display:flex;
align-items:center;
gap:14px;
padding:16px 18px;
background:#fff;
border:1px solid var(--pg-line);
border-radius:12px;
text-decoration:none;
color:var(--pg-ink);
box-shadow:var(--shadow-soft);
transition:.2s ease;
}
.quick-link .q-icon{
width:44px;height:44px;border-radius:10px;flex:none;
display:flex;align-items:center;justify-content:center;
background:var(--pg-forest-100);color:var(--pg-forest-800);font-size:18px;
transition:.2s ease;
}
.quick-link .q-text{font-weight:600;font-size:14.5px;line-height:1.25;flex:1;}
.quick-link .q-text small{display:block;font-weight:400;font-size:12.5px;color:var(--pg-ink-soft);margin-top:2px;}
.quick-link > .fa-arrow-right{color:var(--pg-forest-600);font-size:13px;transition:.2s ease;}
.quick-link:hover{
border-color:var(--pg-forest-600);
transform:translateY(-2px);
box-shadow:0 14px 26px -16px rgba(18,60,22,.4);
}
.quick-link:hover .q-icon{background:var(--pg-forest-800);color:#fff;}
.quick-link:hover > .fa-arrow-right{transform:translateX(4px);}

/* =========================
   FOOTER
   ========================= */
.gov-footer{
margin-top:auto;
padding-top:30px;
}
.gov-footer .inner{
border-top:1px solid var(--pg-line);
padding-top:14px;
display:flex;
justify-content:space-between;
flex-wrap:wrap;
gap:8px;
font-size:12.5px;
color:var(--pg-ink-soft);
}

/* =========================
   RESPONSIVE
   ========================= */
@media(max-width:1200px){
.quick-grid{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:1000px){
.dashboard-cards{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:900px){
.sidebar{width:215px;}
.main-content{margin-left:215px;width:calc(100% - 215px);padding:calc(var(--top-offset) + 20px) 20px 18px;}
}
@media(max-width:768px){
:root{--strip-h:30px;--header-h:62px;}
.gov-strip{padding:0 14px;font-size:11px;}
.gov-strip .right{display:none;}
header{padding:0 14px;}
header h1{font-size:15px;letter-spacing:.8px;}
header .brand small{font-size:10px;}
.seal{width:38px;height:38px;}
header .brand .titles{padding-left:10px;}
header a.logout{padding:8px 14px;font-size:12.5px;}
.sidebar{
position:static;
width:100%;
height:auto;
margin-top:var(--top-offset);
padding:8px;
display:flex;
flex-wrap:wrap;
gap:3px;
}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
body{flex-direction:column;}
.main-content{margin-left:0;width:100%;padding:18px 15px;min-height:auto;}
.profile-card{padding:18px;margin-bottom:20px;}
.profile-info h2{font-size:19px;}
.role-pill{margin-left:0;}
.dashboard-cards{gap:12px;}
.card{padding:16px 16px 14px;}
.card .number{font-size:38px;}
.card-label{font-size:12px;}
.card-icon{width:40px;height:40px;font-size:17px;}
.quick-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:520px){
.dashboard-cards{grid-template-columns:1fr;}
.quick-grid{grid-template-columns:1fr;}
.overview-bar{align-items:flex-start;}
}
</style>
</head>
<body>

<!-- =========================
     OFFICIAL GOVERNMENT STRIP
     ========================= -->
<div class="gov-strip">
<div class="left">
<i class="fas fa-landmark"></i>
<span>An official website of the <strong>Municipality of Cabatuan, Iloilo</strong></span>
</div>
<div class="right">Republic of the Philippines &bull; Municipal Agriculture Office</div>
</div>

<!-- =========================
     HEADER
     ========================= -->
<header>
<div class="brand">
<div class="seal">
<img src="img/CABATUAN.png" alt="Municipality of Cabatuan seal" onerror="this.parentNode.classList.add('fallback');this.outerHTML='<i class=&quot;fas fa-paw&quot;></i>'">
</div>
<div class="seal">
<img src="img/DA.png" alt="Department of Agriculture seal" onerror="this.parentNode.style.display='none'">
</div>
<div class="titles">
<h1>PIGOBAO</h1>
<small>Admin Dashboard &bull; Livestock Distribution Program</small>
</div>
</div>

<a href="adminlog_out.php" class="logout">
<i class="fas fa-right-from-bracket"></i>
Logout
</a>
</header>

<!-- =========================
     SIDEBAR
     ========================= -->
<div class="sidebar">
<div class="menu-title">Main Menu</div>

<a href="admin_dashboard.php" class="active">
<i class="fas fa-home"></i>
Dashboard
</a>

<a href="./adminside/add-technician.php">
<i class="fas fa-user-cog"></i>
Technicians
</a>

<a href="./adminside/farmer-beneficiary.php">
<i class="fas fa-id-card"></i>
Farmer Beneficiary
</a>

<a href="./adminside/add-livestock.php">
<i class="fas fa-drumstick-bite"></i>
Livestock
</a>

<a href="./adminside/tracking.php">
<i class="fas fa-eye"></i>
Livestock Tracking
</a>

<a href="./adminside/dispersal.php">
<i class="fas fa-people-carry-box"></i>
Dispersal
</a>

<a href="./adminside/farmer-success-rate.php">
<i class="fas fa-chart-pie"></i>
Farmer Success Rate
</a>

<a href="./adminside/reports.php">
<i class="fas fa-chart-line"></i>
Reports
</a>

<a href="./adminside/generate-report.php">
<i class="fas fa-file-alt"></i>
Generate Report
</a>
</div>

<!-- =========================
     MAIN CONTENT
     ========================= -->
<div class="main-content">

<!-- WELCOME -->
<div class="profile-card">
<div class="avatar-badge">
<?=htmlspecialchars($initials)?>
</div>

<div class="profile-info">
<div class="date">
<i class="fas fa-calendar-day"></i>
<?=date('l, F j, Y')?>
</div>
<h2>
Welcome, <?=htmlspecialchars($admin['email'])?>
</h2>
<p>
Manage technicians, beneficiaries, livestock, and distributions with transparency and efficiency.
</p>
</div>

<span class="role-pill">
<i class="fas fa-shield-halved"></i>
Administrator
</span>
</div>

<!-- OVERVIEW -->
<div class="overview-bar">
<div>
<p class="section-label">Program Overview</p>
<p class="period">
Reporting period:
<strong><?=$selectedYear==='all'?'All Time':(int)$selectedYear?></strong>
</p>
</div>

<form class="year-filter" method="GET">
<label for="year">
<i class="fas fa-calendar-days"></i>
Year
</label>
<select name="year" id="year" onchange="this.form.submit()">
<option value="all" <?=$selectedYear==='all'?'selected':''?>>All Time</option>
<?php foreach($availableYears as $yr): ?>
<option value="<?=$yr?>" <?=($selectedYear===$yr)?'selected':''?>><?=$yr?></option>
<?php endforeach; ?>
</select>
</form>
</div>

<?php $periodText=$selectedYear==='all'?'All Time':(string)(int)$selectedYear; ?>

<!-- =========================
     DASHBOARD CARDS
     ========================= -->
<div class="dashboard-cards">

<!-- ACTIVE TECHNICIANS -->
<div class="card -tech">
<div class="card-top">
<span class="card-label">Active Technicians</span>
<div class="card-icon"><i class="fas fa-user-cog"></i></div>
</div>
<div class="number"><?=number_format($total_tech)?></div>
<div class="description">Technicians with active accounts</div>
<div class="card-foot"><i class="fas fa-calendar-check"></i> Registered: <?=$periodText?></div>
</div>

<!-- TOTAL BENEFICIARIES -->
<div class="card -beneficiaries">
<div class="card-top">
<span class="card-label">Total Beneficiaries</span>
<div class="card-icon"><i class="fas fa-users"></i></div>
</div>
<div class="number"><?=number_format($total_beneficiaries)?></div>
<div class="description">Registered farmers</div>
<div class="card-foot"><i class="fas fa-calendar-check"></i> Registered: <?=$periodText?></div>
</div>

<!-- TOTAL LIVESTOCK -->
<div class="card -livestock">
<div class="card-top">
<span class="card-label">Total Livestock</span>
<div class="card-icon"><i class="fas fa-cow"></i></div>
</div>
<div class="number"><?=number_format($total_livestock)?></div>
<div class="description">Animals in records</div>
<div class="card-foot"><i class="fas fa-calendar-check"></i> Recorded: <?=$periodText?></div>
</div>

<!-- LIVESTOCK DISTRIBUTED -->
<div class="card -distributions">
<div class="card-top">
<span class="card-label">Livestock Distributed</span>
<div class="card-icon"><i class="fas fa-truck"></i></div>
</div>
<div class="number"><?=number_format($total_distributed)?></div>
<div class="description">Animals distributed to farmers</div>
<div class="card-foot"><i class="fas fa-calendar-check"></i> Distributed: <?=$periodText?></div>
</div>

<!-- RETURNED LIVESTOCK -->
<div class="card -returned">
<div class="card-top">
<span class="card-label">Returned Livestock</span>
<div class="card-icon"><i class="fas fa-rotate-left"></i></div>
</div>
<div class="number"><?=number_format($returned_qty)?></div>
<div class="description">Animals returned</div>
<div class="card-foot"><i class="fas fa-calendar-check"></i> Returned: <?=$periodText?></div>
</div>

<!-- DECEASED LIVESTOCK -->
<div class="card -records">
<div class="card-top">
<span class="card-label">Deceased Livestock</span>
<div class="card-icon"><i class="fas fa-skull-crossbones"></i></div>
</div>
<div class="number"><?=number_format($death_qty)?></div>
<div class="description">Animals reported deceased</div>
<div class="card-foot"><i class="fas fa-calendar-check"></i> Reported: <?=$periodText?></div>
</div>

</div>

<!-- =========================
     QUICK ACCESS
     ========================= -->
<div class="quick-access">
<p class="section-label">Quick Access</p>

<div class="quick-grid">
<a class="quick-link" href="./adminside/add-technician.php">
<span class="q-icon"><i class="fas fa-user-cog"></i></span>
<span class="q-text">Technicians<small>Manage accounts</small></span>
<i class="fas fa-arrow-right"></i>
</a>

<a class="quick-link" href="./adminside/farmer-beneficiary.php">
<span class="q-icon"><i class="fas fa-id-card"></i></span>
<span class="q-text">Farmer Beneficiary<small>View farmer records</small></span>
<i class="fas fa-arrow-right"></i>
</a>

<a class="quick-link" href="./adminside/tracking.php">
<span class="q-icon"><i class="fas fa-eye"></i></span>
<span class="q-text">Livestock Tracking<small>Monitor distributions</small></span>
<i class="fas fa-arrow-right"></i>
</a>

<a class="quick-link" href="./adminside/dispersal.php">
<span class="q-icon"><i class="fas fa-people-carry-box"></i></span>
<span class="q-text">Dispersal<small>Return, transfer &amp; DA</small></span>
<i class="fas fa-arrow-right"></i>
</a>

<a class="quick-link" href="./adminside/generate-report.php">
<span class="q-icon"><i class="fas fa-file-alt"></i></span>
<span class="q-text">Generate Report<small>Print official reports</small></span>
<i class="fas fa-arrow-right"></i>
</a>
</div>
</div>

<!-- =========================
     FOOTER
     ========================= -->
<div class="gov-footer">
<div class="inner">
<span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
<span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
</div>
</div>

</div>
</body>
</html>