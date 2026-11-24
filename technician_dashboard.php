<?php
session_start();
require 'db.php';

if(!isset($_SESSION['technician_id'])){
    header("Location: technicianindex.php");
    exit();
}

$tech_id=(int)$_SESSION['technician_id'];

/* =========================================================
   TECHNICIAN INFORMATION
========================================================= */
$tech=[];
$result=$conn->query("
    SELECT *
    FROM technicians
    WHERE technician_id=$tech_id
    LIMIT 1
");
if($result){
    $tech=$result->fetch_assoc()??[];
}

/* =========================================================
   EDIT PROFILE / CHANGE PASSWORD (modal form handler)
========================================================= */
if(empty($_SESSION['csrf_token'])){
    $_SESSION['csrf_token']=bin2hex(random_bytes(32));
}
$flash=$_SESSION['flash']??null;
unset($_SESSION['flash']);

$formErrors=[];
$openModal='';      /* '' | 'profile' | 'password' */

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['form_action'])){
    $action=$_POST['form_action'];

    if(!hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))){
        $formErrors[]='Invalid request. Please refresh the page and try again.';
        $openModal=($action==='change_password')?'password':'profile';
    }

    /* ---------- UPDATE PROFILE ---------- */
    elseif($action==='update_profile'){
        $openModal='profile';
        $newName=trim($_POST['name']??'');
        $newEmail=trim($_POST['email']??'');
        $newContact=trim($_POST['contact']??'');

        if($newName===''||mb_strlen($newName)>100){
            $formErrors[]='Please enter your full name (max 100 characters).';
        }
        if(!filter_var($newEmail,FILTER_VALIDATE_EMAIL)){
            $formErrors[]='Please enter a valid email address.';
        }
        if($newContact!==''&&!preg_match('/^[0-9+\-\s()]{7,20}$/',$newContact)){
            $formErrors[]='Contact number may only contain digits, spaces, + - ( ).';
        }

        if(empty($formErrors)){
            $stmt=$conn->prepare("SELECT technician_id FROM technicians WHERE email=? AND technician_id<>? LIMIT 1");
            $stmt->bind_param('si',$newEmail,$tech_id);
            $stmt->execute();
            $stmt->store_result();
            if($stmt->num_rows>0){
                $formErrors[]='That email address is already used by another account.';
            }
            $stmt->close();
        }

        if(empty($formErrors)){
            $stmt=$conn->prepare("UPDATE technicians SET name=?,email=?,contact=? WHERE technician_id=?");
            $stmt->bind_param('sssi',$newName,$newEmail,$newContact,$tech_id);
            if($stmt->execute()){
                $_SESSION['flash']=['type'=>'success','msg'=>'Your profile was updated successfully.'];
                $stmt->close();
                header("Location: ".strtok($_SERVER['REQUEST_URI'],'?').(isset($_GET['year'])?'?year='.urlencode($_GET['year']):''));
                exit();
            }
            $formErrors[]='Could not save your changes. Please try again.';
            $stmt->close();
        }
    }

    /* ---------- CHANGE PASSWORD ---------- */
    elseif($action==='change_password'){
        $openModal='password';
        $current=(string)($_POST['current_password']??'');
        $new=(string)($_POST['new_password']??'');
        $confirm=(string)($_POST['confirm_password']??'');

        $stmt=$conn->prepare("SELECT password FROM technicians WHERE technician_id=? LIMIT 1");
        $stmt->bind_param('i',$tech_id);
        $stmt->execute();
        $stmt->bind_result($storedPass);
        $stmt->fetch();
        $stmt->close();
        $storedPass=(string)$storedPass;

        /* works with hashed passwords (password_hash) AND old plain-text ones */
        $isHashed=password_get_info($storedPass)['algo']!==null;
        $currentOk=$isHashed?password_verify($current,$storedPass):hash_equals($storedPass,$current);

        if(!$currentOk){
            $formErrors[]='Your current password is incorrect.';
        }
        if(strlen($new)<8){
            $formErrors[]='New password must be at least 8 characters.';
        }
        if($new!==$confirm){
            $formErrors[]='New password and confirmation do not match.';
        }
        if($currentOk&&$new===$current){
            $formErrors[]='New password must be different from your current password.';
        }

        if(empty($formErrors)){
            /* keep the same storage style your login page already uses */
            $toStore=$isHashed?password_hash($new,PASSWORD_DEFAULT):$new;
            $stmt=$conn->prepare("UPDATE technicians SET password=? WHERE technician_id=?");
            $stmt->bind_param('si',$toStore,$tech_id);
            if($stmt->execute()){
                $_SESSION['flash']=['type'=>'success','msg'=>'Your password was changed successfully.'];
                $stmt->close();
                header("Location: ".strtok($_SERVER['REQUEST_URI'],'?').(isset($_GET['year'])?'?year='.urlencode($_GET['year']):''));
                exit();
            }
            $formErrors[]='Could not change your password. Please try again.';
            $stmt->close();
        }
    }
}

/* values shown in the edit form (keep what the user typed if there was an error) */
$formName=$_POST['name']??($tech['name']??'');
$formEmail=$_POST['email']??($tech['email']??'');
$formContact=$_POST['contact']??($tech['contact']??'');

/* =========================================================
   YEAR FILTER
========================================================= */
$yearRows=$conn->query("
    SELECT DISTINCT yr FROM (
        SELECT YEAR(registered_at)      AS yr FROM beneficiaries
        UNION SELECT YEAR(date_recorded)     FROM monitoring
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

/* =========================================================
   TOTAL ASSIGNED BENEFICIARIES
========================================================= */
$total_beneficiaries=0;

$result=$conn->query("
    SELECT COUNT(*) AS cnt
    FROM beneficiaries
    WHERE barangay_id IN(
        SELECT barangay_id
        FROM technician_barangay
        WHERE technician_id=$tech_id
    )
    ".yearFilter('registered_at',$selectedYear)."
");

if($result){
    $row=$result->fetch_assoc();
    $total_beneficiaries=(int)($row['cnt']??0);
}

/* =========================================================
   TOTAL MONITORED
========================================================= */
$total_monitoring=0;

$result=$conn->query("
    SELECT COUNT(*) AS cnt
    FROM monitoring
    WHERE created_by=$tech_id
    ".yearFilter('date_recorded',$selectedYear)."
");

if($result){
    $row=$result->fetch_assoc();
    $total_monitoring=(int)($row['cnt']??0);
}

/* =========================================================
   TOTAL MORTALITY CASES
========================================================= */
$total_mortality=0;

$result=$conn->query("
    SELECT COUNT(*) AS cnt
    FROM monitoring m
    INNER JOIN distributions d
        ON m.distribution_id=d.distribution_id
    WHERE m.created_by=$tech_id
    AND (m.action='death' OR m.health_status='Dead')
    ".yearFilter('m.date_recorded',$selectedYear)."
");

if($result){
    $row=$result->fetch_assoc();
    $total_mortality=(int)($row['cnt']??0);
}

/* =========================================================
   PENDING VISITS
========================================================= */
$total_pending_visits=0;

$result=$conn->query("
    SELECT COUNT(*) AS cnt
    FROM distributions d
    WHERE d.technician_id=$tech_id
    AND d.status='active'
    AND NOT EXISTS(
        SELECT 1 FROM monitoring m
        WHERE m.distribution_id=d.distribution_id
    )
    ".yearFilter('d.distribution_date',$selectedYear)."
");

if($result){
    $row=$result->fetch_assoc();
    $total_pending_visits=(int)($row['cnt']??0);
}

/* =========================================================
   LIVESTOCK STATUS (Healthy / Sick / Critical)
========================================================= */
$livestock_status=['Healthy'=>0,'Sick'=>0,'Critical'=>0];

$result=$conn->query("
    SELECT m.health_status, COUNT(*) AS cnt
    FROM monitoring m
    INNER JOIN(
        SELECT distribution_id, MAX(monitoring_id) AS latest_id
        FROM monitoring
        GROUP BY distribution_id
    ) latest ON m.monitoring_id=latest.latest_id
    INNER JOIN distributions d ON d.distribution_id=m.distribution_id
    WHERE d.technician_id=$tech_id
    AND d.status='active'
    AND m.health_status IN('Healthy','Sick','Critical')
    ".yearFilter('m.date_recorded',$selectedYear)."
    GROUP BY m.health_status
");

if($result){
    while($row=$result->fetch_assoc()){
        $livestock_status[$row['health_status']]=(int)$row['cnt'];
    }
}

$livestock_status_total=array_sum($livestock_status);

/* =========================================================
   MORTALITY BREAKDOWN (by death_type)
========================================================= */
$mortality_labels=[
    'disease'=>'Disease',
    'natural'=>'Natural Causes',
    'accident'=>'Accident',
    'unknown'=>'Unknown',
    'other'=>'Other Causes',
];
$mortality_breakdown=[];

$result=$conn->query("
    SELECT COALESCE(m.death_type,'other') AS death_type, COUNT(*) AS cnt
    FROM monitoring m
    INNER JOIN distributions d ON m.distribution_id=d.distribution_id
    WHERE m.created_by=$tech_id
    AND(m.action='death' OR m.health_status='Dead')
    ".yearFilter('m.date_recorded',$selectedYear)."
    GROUP BY death_type
    ORDER BY cnt DESC
");

if($result){
    while($row=$result->fetch_assoc()){
        $key=$row['death_type'];
        $label=$mortality_labels[$key]??ucfirst($key);
        $mortality_breakdown[]=['label'=>$label,'cnt'=>(int)$row['cnt']];
    }
}

/* =========================================================
   LIVESTOCK RETURNS (by kind: goat / pig / carabao)
========================================================= */
$returns_breakdown=[];
$total_returns=0;

$result=$conn->query("
    SELECT l.kind, COUNT(*) AS cnt
    FROM distributions d
    INNER JOIN livestock l ON d.livestock_id=l.livestock_id
    WHERE d.technician_id=$tech_id
    AND d.status='returned'
    ".yearFilter('d.return_date',$selectedYear)."
    GROUP BY l.kind
    ORDER BY cnt DESC
");

if($result){
    while($row=$result->fetch_assoc()){
        $returns_breakdown[]=['label'=>ucfirst($row['kind']),'cnt'=>(int)$row['cnt']];
        $total_returns+=(int)$row['cnt'];
    }
}

/* Icons per livestock kind (Font Awesome 6.4 free) */
$kindIcons=[
    'carabao'=>'fa-paw',
    'cow'=>'fa-paw',
    'goat'=>'fa-paw',
    'pig'=>'fa-piggy-bank',
    'chicken'=>'fa-egg',
];

/* =========================================================
   TECHNICIAN INITIALS
========================================================= */
$nameParts=preg_split('/\s+/',trim($tech['name']??''));
$initials='';

foreach(array_slice($nameParts,0,2) as $part){
    if($part!==''){
        $initials.=mb_strtoupper(mb_substr($part,0,1));
    }
}

if($initials===''){
    $initials='T';
}

/* =========================================================
   TECHNICIAN STATUS
========================================================= */
$periodText=$selectedYear==='all'?'All Time':(string)(int)$selectedYear;

$statusRaw=strtolower(trim($tech['status']??''));
$statusClass='status-neutral';

if(in_array($statusRaw,['active','approved'],true)){
    $statusClass='status-active';
}elseif(in_array($statusRaw,['inactive','disabled','suspended'],true)){
    $statusClass='status-inactive';
}elseif($statusRaw==='pending'){
    $statusClass='status-pending';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Technician Dashboard - PIGOBAO</title>
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

/* background photo kept, softened so the page reads as an official portal */
body::before{
content:"";
position:fixed;
inset:0;
z-index:-2;
background-image:url('img/tech.jpg');
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
header .brand{display:flex;align-items:center;gap:14px;}

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
.logout:focus-visible,
.sidebar a:focus-visible,
.quick-link:focus-visible,
.year-filter select:focus-visible{
outline:2px solid var(--gov-gold);
outline-offset:2px;
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
margin:0 0 4px;
color:var(--pg-forest-900);
word-break:break-word;
}
.profile-meta{
display:flex;
flex-wrap:wrap;
gap:4px 22px;
font-size:13.5px;
color:var(--pg-ink-soft);
}
.profile-meta span{display:inline-flex;align-items:center;gap:7px;}
.profile-meta i{color:var(--pg-forest-600);font-size:13px;width:14px;text-align:center;}

.profile-badges{
margin-left:auto;
display:flex;
align-items:center;
gap:10px;
flex-wrap:wrap;
}
.role-pill{
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

.status-pill{
padding:7px 14px;
border-radius:8px;
font-size:12.5px;
font-weight:700;
letter-spacing:.3px;
text-transform:capitalize;
display:inline-flex;
align-items:center;
gap:7px;
border:1px solid currentColor;
}
.status-pill::before{
content:"";
width:7px;height:7px;
border-radius:50%;
background:currentColor;
}
.status-active{background:#e6f5e8;color:#1e7e34;}
.status-inactive{background:#fbe9e7;color:#b3402c;}
.status-pending{background:#fff5e0;color:#a86c00;}
.status-neutral{background:#eef1ef;color:#5d6b60;}

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

/* =========================
   KPI CARDS
   ========================= */
.dashboard-cards{
display:grid;
grid-template-columns:repeat(4,1fr);
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

.card.-beneficiaries{--accent:#1b5e20;--accent-soft:#e8f5e9;}
.card.-monitoring{--accent:#0b2f5b;--accent-soft:#e6eef8;}
.card.-mortality{--accent:#9b2c2c;--accent-soft:#f9e6e6;}
.card.-pending{--accent:#b7791f;--accent-soft:#fbf1dd;}

/* =========================
   PANELS
   ========================= */
.panel-row{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:18px;
margin-top:26px;
align-items:stretch;
}
.panel{
background:#fff;
border-radius:12px;
padding:20px 22px 22px;
border:1px solid var(--pg-line);
border-top:5px solid var(--gov-navy);
box-shadow:var(--shadow-soft);
transition:box-shadow .2s ease;
}
.panel:hover{box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}

.panel-title{
display:flex;
align-items:center;
gap:10px;
font-family:'Space Grotesk',sans-serif;
font-size:13.5px;
font-weight:700;
letter-spacing:.7px;
text-transform:uppercase;
color:var(--pg-forest-900);
margin-bottom:16px;
padding-bottom:12px;
border-bottom:1px dashed var(--pg-line);
}
.panel-title i{
color:var(--gov-navy);
background:#e6eef8;
width:32px;height:32px;
border-radius:9px;
display:inline-flex;
align-items:center;
justify-content:center;
font-size:14px;
flex:none;
}
.panel-total{
font-family:'Poppins',sans-serif;
font-size:11.5px;
letter-spacing:.2px;
text-transform:none;
color:var(--pg-ink-soft);
font-weight:600;
margin-left:auto;
background:var(--pg-canvas);
border:1px solid var(--pg-line);
padding:3px 10px;
border-radius:6px;
}

.panel-empty{
display:flex;
align-items:center;
gap:10px;
color:var(--pg-ink-soft);
font-size:13px;
font-weight:500;
padding:16px;
border:1px dashed var(--pg-line);
border-radius:10px;
background:#fafcfa;
}
.panel-empty::before{
content:"\f05a";
font-family:"Font Awesome 6 Free";
font-weight:900;
color:var(--pg-forest-300);
font-size:16px;
}

/* Livestock status meters */
.meter{margin-bottom:16px;}
.meter:last-child{margin-bottom:0;}
.meter-head{
display:flex;
justify-content:space-between;
align-items:center;
font-size:13.5px;
font-weight:600;
margin-bottom:6px;
}
.meter-head span:first-child{display:flex;align-items:center;gap:8px;}
.meter-head i{color:var(--c);}
.meter-head strong{
font-family:'Space Grotesk',sans-serif;
font-size:18px;
color:var(--c);
}
.meter-track{
height:9px;
border-radius:6px;
background:#edf2ee;
overflow:hidden;
}
.meter-fill{
height:100%;
border-radius:6px;
background:var(--c);
}
.meter.-healthy{--c:#1b7f2a;}
.meter.-sick{--c:#c88a00;}
.meter.-critical{--c:#b3262e;}

/* Mortality rows */
.breakdown-row{
display:flex;
align-items:center;
gap:10px;
padding:11px 4px;
border-bottom:1px dashed var(--pg-line);
font-size:13.5px;
}
.breakdown-row:last-child{border-bottom:none;}
.breakdown-dot{
width:9px;height:9px;
border-radius:50%;
background:#9b2c2c;
flex:none;
}
.breakdown-row:nth-child(2) .breakdown-dot{background:#c1543f;}
.breakdown-row:nth-child(3) .breakdown-dot{background:#d98c2b;}
.breakdown-row:nth-child(4) .breakdown-dot{background:#4a3f8f;}
.breakdown-row:nth-child(5) .breakdown-dot{background:#5d6b60;}
.breakdown-count{
font-family:'Space Grotesk',sans-serif;
font-weight:700;
font-size:16px;
color:var(--pg-ink);
min-width:28px;
}
.breakdown-label{color:var(--pg-ink);font-weight:500;}

/* Returns */
.returns-grid{
display:grid;
grid-template-columns:1fr 1fr;
gap:12px;
}
.returns-item{
display:flex;
align-items:center;
gap:12px;
font-size:13.5px;
background:var(--pg-canvas);
border:1px solid var(--pg-line);
border-radius:10px;
padding:12px 14px;
transition:.2s ease;
}
.returns-item:hover{border-color:var(--pg-forest-600);background:var(--pg-forest-100);}
.returns-item i{
font-size:16px;
width:36px;height:36px;
flex:none;
border-radius:9px;
background:var(--pg-forest-100);
color:var(--pg-forest-800);
display:flex;align-items:center;justify-content:center;
}
.returns-item strong{
font-size:20px;
font-family:'Space Grotesk',sans-serif;
font-weight:700;
color:var(--gov-navy);
}
.returns-item span{color:var(--pg-ink-soft);font-weight:600;}

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
.gov-footer{margin-top:auto;padding-top:30px;}
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
@media(max-width:1300px){
.dashboard-cards{grid-template-columns:repeat(2,1fr);}
.panel-row{grid-template-columns:repeat(2,1fr);}
.quick-grid{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:1000px){
.panel-row{grid-template-columns:1fr;}
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
.profile-badges{margin-left:0;}
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
.returns-grid{grid-template-columns:1fr;}
.overview-bar{align-items:flex-start;}
.year-filter{width:100%;justify-content:space-between;}
}

/* =========================
   EDIT PROFILE BUTTON + MODAL
   ========================= */
.edit-btn{
border:1px solid var(--pg-forest-800);
background:#fff;
color:var(--pg-forest-800);
padding:7px 14px;
border-radius:8px;
font-size:12.5px;
font-weight:700;
letter-spacing:.3px;
display:inline-flex;
align-items:center;
gap:7px;
cursor:pointer;
transition:.2s ease;
}
.edit-btn:hover{background:var(--pg-forest-800);color:#fff;transform:translateY(-1px);}
.edit-btn:focus-visible,.modal-close:focus-visible,.tab-btn:focus-visible{outline:2px solid var(--gov-gold);outline-offset:2px;}

.flash{
display:flex;align-items:center;gap:10px;
padding:12px 16px;
border-radius:10px;
margin-bottom:16px;
font-size:14px;font-weight:500;
}
.flash.success{background:#e6f5e8;color:#1e7e34;border:1px solid #b7dfbd;}

.modal-backdrop{
position:fixed;inset:0;
background:rgba(10,31,61,.55);
display:none;
align-items:center;justify-content:center;
padding:16px;
z-index:2000;
}
.modal-backdrop.open{display:flex;}
.modal{
background:#fff;
width:100%;max-width:460px;
max-height:calc(100vh - 32px);
overflow-y:auto;
border-radius:14px;
box-shadow:0 24px 60px -12px rgba(0,0,0,.4);
border-top:5px solid var(--pg-forest-800);
}
.modal-head{
display:flex;align-items:center;justify-content:space-between;
padding:18px 22px 6px;
}
.modal-head h3{
font-family:'Space Grotesk',sans-serif;
font-size:17px;color:var(--pg-forest-900);
display:flex;align-items:center;gap:9px;
}
.modal-close{
border:none;background:transparent;
font-size:18px;color:var(--pg-ink-soft);
cursor:pointer;padding:6px 8px;border-radius:6px;
}
.modal-close:hover{background:var(--pg-forest-100);}
.modal-tabs{display:flex;gap:6px;padding:8px 22px 0;border-bottom:1px solid var(--pg-line);}
.tab-btn{
border:none;background:transparent;
padding:9px 14px;
font-size:13.5px;font-weight:600;
color:var(--pg-ink-soft);
cursor:pointer;
border-bottom:3px solid transparent;
margin-bottom:-1px;
}
.tab-btn.active{color:var(--pg-forest-800);border-bottom-color:var(--gov-gold);}
.tab-panel{display:none;padding:18px 22px 22px;}
.tab-panel.active{display:block;}
.field{margin-bottom:14px;}
.field label{display:block;font-size:12.5px;font-weight:600;color:var(--pg-ink-soft);margin-bottom:5px;}
.field input{
width:100%;
padding:10px 12px;
border:1px solid var(--pg-line);
border-radius:8px;
font-size:14px;
background:#fafcfb;
transition:.15s ease;
}
.field input:focus{outline:none;border-color:var(--pg-forest-600);box-shadow:0 0 0 3px rgba(46,125,50,.15);background:#fff;}
.field small{display:block;margin-top:4px;font-size:11.5px;color:var(--pg-ink-soft);}
.form-errors{
background:#fbe9e7;color:#b3402c;
border:1px solid #f1c3bb;
border-radius:8px;
padding:10px 14px;margin-bottom:14px;
font-size:13px;
}
.form-errors ul{margin-left:18px;}
.modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:6px;}
.btn{
border:none;border-radius:8px;
padding:10px 20px;
font-size:13.5px;font-weight:600;
cursor:pointer;transition:.2s ease;
display:inline-flex;align-items:center;gap:8px;
}
.btn-primary{background:var(--pg-forest-800);color:#fff;}
.btn-primary:hover{background:var(--pg-forest-900);}
.btn-ghost{background:#eef1ef;color:var(--pg-ink);}
.btn-ghost:hover{background:var(--pg-line);}
</style>
</head>
<body>

<!-- OFFICIAL GOVERNMENT STRIP -->
<div class="gov-strip">
    <div class="left">
        <i class="fas fa-landmark"></i>
        <span>An official website of the <strong>Municipality of Cabatuan, Iloilo</strong></span>
    </div>
    <div class="right">Republic of the Philippines &bull; Municipal Agriculture Office</div>
</div>

<!-- HEADER -->
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
            <small>Technician Dashboard &bull; Livestock Distribution Program</small>
        </div>
    </div>

    <a href="tech_logout.php" class="logout">
        <i class="fas fa-right-from-bracket"></i>
        Logout
    </a>
</header>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="menu-title">Main Menu</div>

    <a href="technician_dashboard.php" class="active">
        <i class="fas fa-home"></i>
        Dashboard
    </a>
    <a href="technicianside/assign_beneficiaries.php">
        <i class="fas fa-user-check"></i>
        Assign Beneficiaries
    </a>
    <a href="technicianside/monitoring.php">
        <i class="fas fa-stethoscope"></i>
        Monitoring
    </a>
    <a href="technicianside/monitoring_history.php">
        <i class="fas fa-history"></i>
        Monitoring History
    </a>
    <a href="technicianside/farmer_success_rate.php">
        <i class="fas fa-chart-pie"></i>
        Farmer Success Rate
    </a>
    <a href="technicianside/mortality_cases.php">
        <i class="fas fa-skull-crossbones"></i>
        Mortality Cases
    </a>
    <a href="technicianside/livestock_return.php">
        <i class="fas fa-undo"></i>
        Livestock Return
    </a>
    <a href="technicianside/tech_reports.php">
        <i class="fas fa-file-alt"></i>
        Reports
    </a>
    <a href="technicianside/generate_report.php">
        <i class="fas fa-file-pdf"></i>
        Generate Report
    </a>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">

    <?php if($flash): ?>
        <div class="flash <?=htmlspecialchars($flash['type'])?>" role="status">
            <i class="fas fa-circle-check"></i>
            <?=htmlspecialchars($flash['msg'])?>
        </div>
    <?php endif; ?>

    <!-- WELCOME -->
    <div class="profile-card">
        <div class="avatar-badge"><?=htmlspecialchars($initials)?></div>

        <div class="profile-info">
            <div class="date">
                <i class="fas fa-calendar-day"></i>
                <?=date('l, F j, Y')?>
            </div>
            <h2>Welcome, <?=htmlspecialchars($tech['name']??'Technician')?></h2>

            <div class="profile-meta">
                <span><i class="fas fa-envelope"></i> <?=htmlspecialchars($tech['email']??'')?></span>
                <span><i class="fas fa-phone"></i> <?=htmlspecialchars($tech['contact']??'')?></span>
            </div>
        </div>

        <div class="profile-badges">
            <span class="status-pill <?=$statusClass?>"><?=htmlspecialchars($tech['status']??'Unknown')?></span>
            <button type="button" class="edit-btn" id="openEditModal">
                <i class="fas fa-pen-to-square"></i>
                Edit
            </button>
            <span class="role-pill">
                <i class="fas fa-user-gear"></i>
                Livestock Technician
            </span>
        </div>
    </div>

    <!-- OVERVIEW -->
    <div class="overview-bar">
        <div>
            <p class="section-label">My Program Overview</p>
            <p class="period">
                Reporting period:
                <strong><?=$periodText?></strong>
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

    <!-- KPI CARDS -->
    <div class="dashboard-cards">

        <div class="card -beneficiaries">
            <div class="card-top">
                <span class="card-label">Total Beneficiaries</span>
                <div class="card-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="number"><?=number_format($total_beneficiaries)?></div>
            <div class="description">Assigned farmers</div>
            <div class="card-foot"><i class="fas fa-calendar-check"></i> Registered: <?=$periodText?></div>
        </div>

        <div class="card -monitoring">
            <div class="card-top">
                <span class="card-label">Total Monitored</span>
                <div class="card-icon"><i class="fas fa-stethoscope"></i></div>
            </div>
            <div class="number"><?=number_format($total_monitoring)?></div>
            <div class="description">Livestock monitored</div>
            <div class="card-foot"><i class="fas fa-calendar-check"></i> Recorded: <?=$periodText?></div>
        </div>

        <div class="card -mortality">
            <div class="card-top">
                <span class="card-label">Total Mortality</span>
                <div class="card-icon"><i class="fas fa-skull-crossbones"></i></div>
            </div>
            <div class="number"><?=number_format($total_mortality)?></div>
            <div class="description">Mortality cases reported</div>
            <div class="card-foot"><i class="fas fa-calendar-check"></i> Reported: <?=$periodText?></div>
        </div>

        <div class="card -pending">
            <div class="card-top">
                <span class="card-label">Pending Visits</span>
                <div class="card-icon"><i class="fas fa-clipboard-check"></i></div>
            </div>
            <div class="number"><?=number_format($total_pending_visits)?></div>
            <div class="description">Visits still to be made</div>
            <div class="card-foot"><i class="fas fa-calendar-check"></i> Distributed: <?=$periodText?></div>
        </div>

    </div>

    <!-- PANELS -->
    <div class="panel-row">

        <!-- LIVESTOCK STATUS -->
        <div class="panel">
            <p class="panel-title">
                <i class="fas fa-heart-pulse"></i>
                Livestock Status
                <span class="panel-total"><?=$livestock_status_total?> total</span>
            </p>

            <?php if($livestock_status_total===0): ?>
                <p class="panel-empty">No active livestock health records yet.</p>
            <?php else: ?>
                <?php
                $meters=[
                    ['Healthy','-healthy','fa-circle-check'],
                    ['Sick','-sick','fa-circle-exclamation'],
                    ['Critical','-critical','fa-triangle-exclamation'],
                ];
                foreach($meters as $m):
                    $count=$livestock_status[$m[0]];
                    $pct=round(($count/$livestock_status_total)*100);
                ?>
                <div class="meter <?=$m[1]?>">
                    <div class="meter-head">
                        <span><i class="fas <?=$m[2]?>"></i><?=$m[0]?></span>
                        <strong><?=$count?></strong>
                    </div>
                    <div class="meter-track"><div class="meter-fill" style="width:<?=$pct?>%"></div></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- MORTALITY CASES -->
        <div class="panel">
            <p class="panel-title">
                <i class="fas fa-skull-crossbones"></i>
                Mortality Cases
                <span class="panel-total"><?=$total_mortality?> total</span>
            </p>

            <?php if(empty($mortality_breakdown)): ?>
                <p class="panel-empty">No mortality cases recorded yet.</p>
            <?php else: ?>
                <?php foreach($mortality_breakdown as $row): ?>
                    <div class="breakdown-row">
                        <span class="breakdown-dot"></span>
                        <span class="breakdown-count"><?=$row['cnt']?></span>
                        <span class="breakdown-label"><?=htmlspecialchars($row['label'])?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- LIVESTOCK RETURNS -->
        <div class="panel">
            <p class="panel-title">
                <i class="fas fa-rotate-left"></i>
                Livestock Returns
                <span class="panel-total"><?=$total_returns?> total</span>
            </p>

            <?php if(empty($returns_breakdown)): ?>
                <p class="panel-empty">No livestock returns recorded yet.</p>
            <?php else: ?>
                <div class="returns-grid">
                    <?php foreach($returns_breakdown as $row):
                        $icon=$kindIcons[strtolower($row['label'])]??'fa-paw'; ?>
                        <div class="returns-item">
                            <i class="fas <?=$icon?>"></i>
                            <strong><?=$row['cnt']?></strong>
                            <span><?=htmlspecialchars($row['label'])?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- QUICK ACCESS -->
    <div class="quick-access">
        <p class="section-label">Quick Access</p>

        <div class="quick-grid">
            <a class="quick-link" href="technicianside/monitoring.php">
                <span class="q-icon"><i class="fas fa-stethoscope"></i></span>
                <span class="q-text">Monitoring<small>Record a livestock visit</small></span>
                <i class="fas fa-arrow-right"></i>
            </a>

            <a class="quick-link" href="technicianside/assign_beneficiaries.php">
                <span class="q-icon"><i class="fas fa-user-check"></i></span>
                <span class="q-text">Assign Beneficiaries<small>Manage your farmers</small></span>
                <i class="fas fa-arrow-right"></i>
            </a>

            <a class="quick-link" href="technicianside/livestock_return.php">
                <span class="q-icon"><i class="fas fa-undo"></i></span>
                <span class="q-text">Livestock Return<small>Process returned animals</small></span>
                <i class="fas fa-arrow-right"></i>
            </a>

            <a class="quick-link" href="technicianside/generate_report.php">
                <span class="q-icon"><i class="fas fa-file-pdf"></i></span>
                <span class="q-text">Generate Report<small>Print official reports</small></span>
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="gov-footer">
        <div class="inner">
            <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
            <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
        </div>
    </div>

</div>

<!-- EDIT ACCOUNT MODAL -->
<div class="modal-backdrop <?=$openModal!==''?'open':''?>" id="editModal" aria-hidden="<?=$openModal!==''?'false':'true'?>">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="modal-head">
            <h3 id="editModalTitle"><i class="fas fa-user-gear"></i> My Account</h3>
            <button type="button" class="modal-close" id="closeEditModal" aria-label="Close">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <div class="modal-tabs">
            <button type="button" class="tab-btn <?=$openModal!=='password'?'active':''?>" data-tab="profile">Edit Profile</button>
            <button type="button" class="tab-btn <?=$openModal==='password'?'active':''?>" data-tab="password">Change Password</button>
        </div>

        <!-- PROFILE TAB -->
        <form method="POST" class="tab-panel <?=$openModal!=='password'?'active':''?>" id="tab-profile" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
            <input type="hidden" name="form_action" value="update_profile">

            <?php if($openModal==='profile' && $formErrors): ?>
                <div class="form-errors"><ul>
                    <?php foreach($formErrors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>

            <div class="field">
                <label for="f_name">Full Name</label>
                <input type="text" id="f_name" name="name" maxlength="100" required value="<?=htmlspecialchars($formName)?>">
            </div>
            <div class="field">
                <label for="f_email">Email</label>
                <input type="email" id="f_email" name="email" required value="<?=htmlspecialchars($formEmail)?>">
            </div>
            <div class="field">
                <label for="f_contact">Contact Number</label>
                <input type="text" id="f_contact" name="contact" maxlength="20" value="<?=htmlspecialchars($formContact)?>">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Changes</button>
            </div>
        </form>

        <!-- PASSWORD TAB -->
        <form method="POST" class="tab-panel <?=$openModal==='password'?'active':''?>" id="tab-password" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
            <input type="hidden" name="form_action" value="change_password">

            <?php if($openModal==='password' && $formErrors): ?>
                <div class="form-errors"><ul>
                    <?php foreach($formErrors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>

            <div class="field">
                <label for="f_current">Current Password</label>
                <input type="password" id="f_current" name="current_password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label for="f_new">New Password</label>
                <input type="password" id="f_new" name="new_password" minlength="8" required autocomplete="new-password">
                <small>At least 8 characters.</small>
            </div>
            <div class="field">
                <label for="f_confirm">Confirm New Password</label>
                <input type="password" id="f_confirm" name="confirm_password" minlength="8" required autocomplete="new-password">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Change Password</button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    var modal=document.getElementById('editModal');
    var tabs=modal.querySelectorAll('.tab-btn');
    var panels=modal.querySelectorAll('.tab-panel');

    function openModal(){modal.classList.add('open');modal.setAttribute('aria-hidden','false');}
    function closeModal(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');}
    function showTab(name){
        tabs.forEach(function(t){t.classList.toggle('active',t.dataset.tab===name);});
        panels.forEach(function(p){p.classList.toggle('active',p.id==='tab-'+name);});
    }

    document.getElementById('openEditModal').addEventListener('click',function(){showTab('profile');openModal();});
    document.getElementById('closeEditModal').addEventListener('click',closeModal);
    modal.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',closeModal);});
    tabs.forEach(function(t){t.addEventListener('click',function(){showTab(t.dataset.tab);});});
    modal.addEventListener('click',function(e){if(e.target===modal)closeModal();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal();});
})();
</script>
</body>
</html>