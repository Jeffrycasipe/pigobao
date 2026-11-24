<?php
session_start();
require '../db.php';

/* ===== Farmer profile photo helpers =====
   Auto-detects which column of `beneficiaries` holds the farmer's picture and
   which uploads folder it lives in. If your column/folder has an unusual name,
   add it to the two lists below. */
if (!function_exists('pg_farmer_photo_col')) {
    function pg_farmer_photo_col(mysqli $conn): ?string {
        static $col = false;
        if ($col !== false) return $col;
        $col = null; $cols = [];
        if ($res = $conn->query("SHOW COLUMNS FROM beneficiaries")) {
            while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = $r['Field'];
        }
        $candidates = ['photo','picture','profile_picture','profile_pic','profile_photo','profile_image',
                       'image','img','avatar','farmer_photo','farmer_image','farmer_picture','pic',
                       'photo_path','image_path','picture_path'];
        foreach ($candidates as $c) {
            if (isset($cols[$c])) { $col = $cols[$c]; break; }
        }
        return $col;
    }
}
if (!function_exists('pg_farmer_photo_sql')) {
    /* Returns ", b.`col` AS farmer_photo" (or ", NULL AS farmer_photo") to drop into a SELECT. */
    function pg_farmer_photo_sql(mysqli $conn, string $alias = 'b'): string {
        $c = pg_farmer_photo_col($conn);
        return $c ? ", $alias.`$c` AS farmer_photo" : ", NULL AS farmer_photo";
    }
}
if (!function_exists('pg_farmer_photo_url')) {
    function pg_farmer_photo_url($file): ?string {
        $file = trim((string)$file);
        if ($file === '') return null;
        if (preg_match('#^(https?:)?//#i', $file) || strpos($file, 'data:') === 0) return $file;
        $base  = basename($file);
        $tries = [];
        if (strpos($file, '/') !== false) { $tries[] = ltrim($file, '/'); $tries[] = '../' . ltrim($file, '/'); }
        foreach (['../uploads/','../uploads/beneficiaries/','../uploads/farmers/','../uploads/profiles/','../uploads/profile/','uploads/'] as $d) {
            $tries[] = $d . $base;
        }
        foreach ($tries as $t) {
            if (is_file(__DIR__ . '/' . $t)) return $t;
        }
        return '../uploads/' . $base;   // best guess; the initials show if it fails to load
    }
}
if (!function_exists('pg_farmer_avatar')) {
    /* Round profile picture with an initial as fallback (no photo / broken image). */
    function pg_farmer_avatar($photo, string $name, string $extraClass = ''): string {
        $initial = mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: '?';
        $url  = pg_farmer_photo_url($photo);
        $html = '<span class="farmer-avatar ' . htmlspecialchars($extraClass) . '">';
        $html .= '<span class="fa-init">' . htmlspecialchars($initial) . '</span>';
        if ($url) {
            $html .= '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($name) . '" loading="lazy" onerror="this.remove()">';
        }
        return $html . '</span>';
    }
}
$farmerPhotoCol = pg_farmer_photo_col($conn);
$photoSql       = pg_farmer_photo_sql($conn);


if(!isset($_SESSION['technician_id'])) {
    header("Location: ../technicianindex.php");
    exit();
}

$tech_id = $_SESSION['technician_id'];
$search = "";
if(isset($_GET['search'])){
    $search = trim($_GET['search']);
}

// Fetch barangays assigned to technician
$sql = "
    SELECT br.barangay_id, br.barangay_name 
    FROM technician_barangay tb
    JOIN barangays br ON tb.barangay_id=br.barangay_id
    WHERE tb.technician_id=?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i",$tech_id);
$stmt->execute();
$barangays = $stmt->get_result();

// Pull all barangay rows into an array so we can loop twice (tabs + panels)
$barangayList = [];
while($row = $barangays->fetch_assoc()){
    $barangayList[] = $row;
}

// Count beneficiaries per barangay (respecting the current search) for the tab badges and summary
$like = "%".$search."%";
$barangayCounts = [];
$totalBeneficiaries = 0;

foreach($barangayList as $br){
    $cntStmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM beneficiaries
        WHERE barangay_id=?
        AND (firstname LIKE ? OR lastname LIKE ? OR ?='')
    ");
    $cntStmt->bind_param("isss", $br['barangay_id'], $like, $like, $search);
    $cntStmt->execute();
    $cnt = (int)($cntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $barangayCounts[$br['barangay_id']] = $cnt;
    $totalBeneficiaries += $cnt;
    $cntStmt->close();
}

$totalBarangays = count($barangayList);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Assign Beneficiaries - PIGOBAO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<style>
:root{
  --pg-forest-900:#123c16;
  --pg-forest-800:#1b5e20;
  --pg-forest-600:#2e7d32;
  --pg-forest-300:#a5d6a7;
  --pg-forest-100:#e8f5e9;
  --pg-canvas:#f3f6f4;
  --pg-ink:#233126;
  --pg-ink-soft:#5d6b60;
  --pg-line:#e2e9e3;
  --pg-amber:#e8a33d;
  --pg-sky:#3f7cac;
  --pg-clay:#c1543f;
  --shadow-soft:0 1px 2px rgba(18,60,22,0.04), 0 8px 24px -12px rgba(18,60,22,0.16);
  --shadow-lift:0 12px 28px -14px rgba(18,60,22,0.28);
}

*{box-sizing:border-box;}

body{
  min-height:100vh;
  display:flex;
  background:var(--pg-canvas);
  background-image:
    radial-gradient(1100px 480px at 100% -10%, rgba(46,125,50,0.07), transparent 60%),
    radial-gradient(700px 380px at -5% 100%, rgba(27,94,32,0.05), transparent 55%);
  font-family:'Poppins',sans-serif;
  color:var(--pg-ink);
}

header{
  position:fixed;top:0;left:0;width:100%;height:65px;
  background:linear-gradient(120deg, var(--pg-forest-900), var(--pg-forest-800) 55%, var(--pg-forest-600));
  color:#fff;display:flex;justify-content:space-between;align-items:center;
  padding:0 30px;z-index:1001;
  box-shadow:0 2px 14px rgba(10,40,12,0.25);
}
header .brand{display:flex;align-items:center;gap:12px;}
header .brand .mark{
  width:34px;height:34px;border-radius:9px;
  background:rgba(255,255,255,0.14);
  display:flex;align-items:center;justify-content:center;
  font-size:15px;color:#eafbea;
  border:1px solid rgba(255,255,255,0.22);
}
header h1{font-size:17px;font-weight:600;letter-spacing:.2px;margin:0;}
header .brand small{display:block;font-size:11px;font-weight:400;color:rgba(255,255,255,0.65);letter-spacing:.4px;text-transform:uppercase;}
header a.logout{
  color:var(--pg-forest-800);background:#fff;padding:8px 20px;border-radius:20px;
  font-weight:600;text-decoration:none;font-size:14px;
  transition:transform .15s ease, box-shadow .15s ease, background .15s ease;
  box-shadow:0 2px 8px rgba(0,0,0,0.12);
}
header a.logout:hover{background:var(--pg-forest-100);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,0.16);}

.sidebar{
  position:fixed;top:65px;left:0;width:230px;height:calc(100% - 65px);
  background:linear-gradient(195deg, var(--pg-forest-800), var(--pg-forest-600) 120%);
  padding-top:22px;
  box-shadow:inset -1px 0 0 rgba(255,255,255,0.06);
}
.sidebar a{
  display:flex;align-items:center;gap:12px;color:rgba(255,255,255,0.88);
  padding:13px 22px;text-decoration:none;margin:2px 12px;border-radius:9px;
  font-size:14.5px;font-weight:500;
  transition:background .15s ease, color .15s ease, transform .15s ease;
  position:relative;
}
.sidebar a i{width:18px;text-align:center;font-size:15px;opacity:.9;}
.sidebar a:hover{background:rgba(255,255,255,0.12);transform:translateX(2px);}
.sidebar a.active{
  background:var(--pg-forest-300);color:var(--pg-forest-900);font-weight:600;
  box-shadow:0 4px 12px rgba(0,0,0,0.18);
}
.sidebar a.active i{opacity:1;}

.main-content{
  margin-left:230px;padding:98px 38px 40px;flex:1;
  width:auto;
}

.page-head{
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;
  margin-bottom:22px;
}
.page-head h2{
  font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:22px;
  color:var(--pg-forest-900);margin:0;
}
.page-head p{margin:4px 0 0;font-size:13.5px;color:var(--pg-ink-soft);}

/* Stat summary row */
.stat-row{
  display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;
}
.stat-card{
  background:#fff;border:1px solid var(--pg-line);border-radius:14px;
  padding:16px 20px;box-shadow:var(--shadow-soft);flex:1;min-width:180px;
  display:flex;align-items:center;gap:14px;
  transition:transform .15s ease, box-shadow .15s ease;
}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lift);}
.stat-card .icon{
  width:42px;height:42px;border-radius:11px;display:flex;align-items:center;
  justify-content:center;font-size:17px;flex-shrink:0;
  background:var(--pg-forest-100);color:var(--pg-forest-800);
}
.stat-card.beneficiaries .icon{background:#e8f0fb;color:var(--pg-sky);}
.stat-card .num{
  font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:22px;
  color:var(--pg-forest-900);line-height:1;
}
.stat-card .label{font-size:12px;color:var(--pg-ink-soft);margin-top:3px;}

/* Search card */
.search-card{
  background:#fff;border-radius:14px;padding:16px 18px;
  border:1px solid var(--pg-line);box-shadow:var(--shadow-soft);
  margin-bottom:24px;
}
.search-card form{display:flex;gap:10px;}
.search-card .input-wrap{
  position:relative;flex:1;
}
.search-card .input-wrap i{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  color:var(--pg-forest-600);font-size:14px;
}
.search-card input{
  width:100%;padding:11px 14px 11px 40px;border-radius:10px;
  border:1px solid var(--pg-line);font-size:14px;font-family:'Poppins',sans-serif;
  background:var(--pg-canvas);color:var(--pg-ink);
  transition:border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.search-card input:focus{
  outline:none;border-color:var(--pg-forest-600);background:#fff;
  box-shadow:0 0 0 3px rgba(46,125,50,0.15);
}
.search-card button{
  border:none;border-radius:10px;padding:11px 22px;font-weight:600;font-size:14px;
  background:linear-gradient(120deg, var(--pg-forest-800), var(--pg-forest-600));
  color:#fff;display:flex;align-items:center;gap:8px;cursor:pointer;
  box-shadow:0 8px 18px -8px rgba(18,60,22,0.55);
  transition:transform .15s ease, box-shadow .15s ease, filter .15s ease;
}
.search-card button:hover{transform:translateY(-2px);box-shadow:var(--shadow-lift);filter:brightness(1.05);}

/* Barangay column tabs */
.barangay-tabs{
  display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;margin-bottom:22px;
  scrollbar-width:thin;
}
.barangay-tabs::-webkit-scrollbar{height:6px;}
.barangay-tabs::-webkit-scrollbar-thumb{background:var(--pg-forest-300);border-radius:10px;}
.barangay-tab{
  flex:none;
  background:#fff;border:1px solid var(--pg-line);border-radius:12px;
  padding:12px 20px;font-size:14px;font-weight:600;color:var(--pg-ink-soft);
  cursor:pointer;white-space:nowrap;
  box-shadow:var(--shadow-soft);
  transition:background .15s ease, color .15s ease, border-color .15s ease, transform .15s ease;
  display:flex;align-items:center;gap:9px;
}
.barangay-tab i{font-size:13px;opacity:.85;}
.barangay-tab:hover{transform:translateY(-2px);border-color:var(--pg-forest-300);}
.barangay-tab.active{
  background:linear-gradient(120deg, var(--pg-forest-800), var(--pg-forest-600));
  color:#fff;border-color:transparent;
  box-shadow:0 8px 18px -8px rgba(18,60,22,0.5);
}
.barangay-tab .count-badge{
  background:rgba(0,0,0,0.08);border-radius:999px;padding:2px 8px;font-size:11.5px;
}
.barangay-tab.active .count-badge{background:rgba(255,255,255,0.22);}

/* Barangay panels */
.barangay-panel{
  display:none;
  background:#fff;border-radius:14px;border:1px solid var(--pg-line);
  box-shadow:var(--shadow-soft);overflow:hidden;
}
.barangay-panel.active{display:block;}
.barangay-panel .panel-head{
  padding:16px 20px;border-bottom:1px solid var(--pg-line);
  display:flex;align-items:center;justify-content:space-between;gap:10px;
}
.barangay-panel .panel-head h3{
  font-family:'Space Grotesk',sans-serif;font-size:16px;font-weight:600;
  color:var(--pg-forest-900);margin:0;
}

table.beneficiary-table{width:100%;border-collapse:collapse;font-size:13.5px;}
table.beneficiary-table thead th{
  background:var(--pg-forest-900);color:#fff;font-weight:600;
  text-align:left;padding:12px 16px;letter-spacing:.2px;
  text-transform:uppercase;font-size:11.5px;
}
table.beneficiary-table tbody td{
  padding:12px 16px;border-bottom:1px solid var(--pg-line);color:var(--pg-ink);
}
table.beneficiary-table tbody tr:last-child td{border-bottom:none;}
table.beneficiary-table tbody tr:hover{background:var(--pg-forest-100);}
table.beneficiary-table tbody tr:nth-child(even){background:#fafcfa;}
table.beneficiary-table tbody tr:nth-child(even):hover{background:var(--pg-forest-100);}
table.beneficiary-table td.empty-row{text-align:center;color:var(--pg-ink-soft);font-style:italic;padding:26px;}

@media (max-width: 900px){
  .sidebar{width:200px;}
  .main-content{margin-left:200px;padding:98px 20px 30px;}
  .search-card form{flex-direction:column;}
  .stat-row{flex-direction:column;}
}
@media (max-width: 600px){
  header{padding:0 15px;}
  .brand h1{font-size:15px;}
  .brand small{font-size:9px;}
  .logout{padding:7px 14px;font-size:12px;}
  .sidebar{
    position:static;
    width:100%;
    height:auto;
    margin-top:65px;
    padding:8px;
    display:flex;
    flex-wrap:wrap;
    gap:4px;
  }
  .sidebar a{margin:2px;padding:9px 10px;font-size:10.5px;}
  .main-content{margin-left:0;padding:20px 14px 25px;}
  .barangay-panel{overflow-x:auto;overflow-y:hidden;}
  table.beneficiary-table{min-width:600px;}
}

/* ===== FARMER PROFILE PHOTO ===== */
.farmer-cell{display:flex;align-items:center;gap:12px;}
.farmer-cell > div{min-width:0;}
.farmer-avatar{
    position:relative;display:inline-flex;align-items:center;justify-content:center;
    flex:none;width:42px;height:42px;border-radius:50%;overflow:hidden;
    background:linear-gradient(145deg,#2e7d32,#123c16);color:#fff;
    font-weight:700;font-size:15px;line-height:1;vertical-align:middle;
    box-shadow:0 0 0 2px #fff,0 4px 10px -4px rgba(18,60,22,.55);
}
.farmer-avatar img{
    position:absolute;inset:0;width:100%;height:100%;object-fit:cover;cursor:zoom-in;
    transition:transform .2s ease;
}
.farmer-avatar img:hover{transform:scale(1.08);}
.modal-farmer{display:flex;align-items:center;gap:10px;}
.modal-farmer input{flex:1;min-width:0;}
.pg-photo-view{
    position:fixed;inset:0;z-index:5000;background:rgba(10,30,12,.78);
    display:flex;align-items:center;justify-content:center;padding:20px;
    animation:pgFade .15s ease;cursor:zoom-out;
}
.pg-photo-view figure{position:relative;margin:0;text-align:center;}
.pg-photo-view img{
    max-width:min(90vw,520px);max-height:78vh;border-radius:16px;object-fit:contain;
    background:#fff;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);border:4px solid #fff;
}
.pg-photo-view figcaption{color:#fff;font-weight:600;margin-top:12px;font-size:15px;}
.pg-photo-view button{
    position:absolute;top:-14px;right:-14px;width:34px;height:34px;border-radius:50%;
    border:none;background:#fff;color:#123c16;font-size:20px;line-height:1;cursor:pointer;
    box-shadow:0 4px 12px rgba(0,0,0,.3);
}
@keyframes pgFade{from{opacity:0}to{opacity:1}}

/* =========================================================
   GOVERNMENT PORTAL THEME  (matches reports.php)
   Screen only - print layouts are untouched.
========================================================= */
.gov-strip,.gov-footer{display:none;}
@media screen{
:root{
--gov-navy:#0b2f5b;
--gov-gold:#c9a227;
--gov-red:#b3262e;
--gv-line:#dce5de;
--gv-ink:#1f2b22;
--gv-soft:#4f5d53;
--gv-shadow:0 1px 2px rgba(18,60,22,.05),0 10px 26px -14px rgba(18,60,22,.22);
--strip-h:32px;
--header-h:70px;
--top-offset:calc(var(--strip-h) + var(--header-h));
}
body{background:#f3f6f4;background-image:none;color:var(--gv-ink);font-size:15px;line-height:1.5;-webkit-font-smoothing:antialiased;}

/* government strip */
.gov-strip{
display:flex;position:fixed;top:0;left:0;right:0;height:var(--strip-h);
background:#0a1f3d;color:#dbe5f3;align-items:center;justify-content:space-between;
padding:0 30px;font-size:12px;letter-spacing:.3px;z-index:1003;
border-bottom:3px solid transparent;
border-image:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%) 1;
}
.gov-strip .left{display:flex;align-items:center;gap:8px;}
.gov-strip .left i{color:var(--gov-gold);}
.gov-strip .right{opacity:.85;}
.gov-strip strong{color:#fff;font-weight:600;}

/* header */
header{
top:var(--strip-h);height:var(--header-h);padding:0 30px;z-index:1002;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
box-shadow:0 4px 16px rgba(10,40,12,.28);
}
header .brand{display:flex;align-items:center;gap:14px;}
.seal{width:46px;height:46px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;box-shadow:0 0 0 2px rgba(255,255,255,.35);}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.seal.fallback{font-size:18px;color:#1b5e20;}
.brand .titles{padding-left:14px;border-left:1px solid rgba(255,255,255,.28);}
.brand .titles h1{font-family:'Merriweather',serif;font-size:19px;font-weight:700;letter-spacing:1.2px;line-height:1.15;margin:0;color:#fff;}
.brand .titles small{display:block;font-size:12px;font-weight:400;color:rgba(255,255,255,.78);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;}
header a.logout{
color:#123c16;background:#fff;padding:9px 22px;border-radius:8px;font-weight:600;font-size:14px;
text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,.14);transition:.2s ease;
}
header a.logout:hover{background:#e8f5e9;transform:translateY(-1px);}

/* sidebar */
.sidebar{
top:var(--top-offset);width:250px;height:calc(100% - var(--top-offset));
background:linear-gradient(195deg,#164f1b,#1b5e20 45%,#2e7d32 130%);
padding-top:14px;overflow-y:auto;z-index:1000;
}
.sidebar .menu-title{font-size:11.5px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:rgba(255,255,255,.55);padding:6px 26px 8px;}
.sidebar a{margin:2px 12px 3px;padding:12px 20px;font-size:15px;gap:13px;border-radius:8px;border-left:4px solid transparent;color:rgba(255,255,255,.92);}
.sidebar a i{width:20px;font-size:15.5px;}
.sidebar a.active{background:#fff;color:#123c16;border-left:4px solid var(--gov-gold);font-weight:600;box-shadow:0 6px 16px rgba(0,0,0,.2);}

/* full-width content area */
.main-content{
margin-left:250px;
padding:calc(var(--top-offset) + 26px) 34px 20px;
max-width:none;width:auto;min-height:100vh;
display:flex;flex-direction:column;
}

/* page banner */
.page-card,.page-head.page-card{
background:#fff;border:1px solid var(--gv-line);border-left:6px solid #1b5e20;border-radius:12px;
padding:22px 26px;gap:18px;box-shadow:var(--gv-shadow);margin-bottom:26px;position:relative;overflow:hidden;
display:flex;align-items:center;flex-wrap:wrap;justify-content:flex-start;
}
.page-card::before{display:none;}
.page-card::after{
content:"\f200";font-family:"Font Awesome 6 Free";font-weight:900;position:absolute;right:-8px;bottom:-30px;
font-size:120px;color:#e8f5e9;z-index:0;pointer-events:none;
}
.page-card > *{position:relative;z-index:1;}
.page-icon{display:flex;align-items:center;justify-content:center;flex:none;color:#fff;width:60px;height:60px;border-radius:50%;font-size:22px;box-shadow:0 0 0 4px #e8f5e9;background:linear-gradient(145deg,#2e7d32,#123c16);}
.page-date{font-size:12.5px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--gov-navy);margin-bottom:2px;display:flex;align-items:center;gap:7px;}
.page-info h2,.page-card h2{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:23px;margin:0 0 3px;color:#123c16;}
.page-info p,.page-card .page-info > p{font-size:14px;color:var(--gv-soft);max-width:680px;margin-bottom:0;}
.role-pill{
margin-left:auto;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#123c16;color:#fff;
display:inline-flex;align-items:center;gap:8px;letter-spacing:.3px;
}
.role-pill i{color:var(--gov-gold);}
.page-card .add-btn,.page-card .head-actions{margin-left:auto;}

/* section headings */
.section-label{
font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;
color:#123c16;display:flex;align-items:center;gap:10px;margin:0 0 14px;
}
.section-label::before{content:"";width:5px;height:20px;border-radius:3px;background:linear-gradient(180deg,var(--gov-gold),#2e7d32);}

/* cards, tables, panels */
.table-card,.profile-panel,.filter-card,.stat-card{
border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);background:#fff;
}
.profile-panel::before{display:none;}
.stat-card.inactive-stat{border-top-color:var(--gov-red);}
.stat-card:hover{box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.filter-card{padding:16px 22px;}
.stat-row{gap:18px;margin-bottom:26px;}
table.pg-table thead th,table.livestock-table thead th{
background:#123c16;color:#fff;font-size:12px;letter-spacing:.6px;padding:13px 16px;
}
table.pg-table tbody td,table.livestock-table tbody td{padding:12px 16px;}
table.pg-table tbody tr:nth-child(even),table.livestock-table tbody tr:nth-child(even){background:#f8fbf8;}
table.pg-table tbody tr:hover,table.livestock-table tbody tr:hover{background:#e8f5e9;}
.b-card{border:1px solid var(--gv-line);border-top:4px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);}
.b-card:hover{box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.total-badge{border-radius:8px;border:1px solid #cfdcee;background:#e6eef8;}
.total-badge i,.total-badge .num{color:var(--gov-navy);}
.back-link{border-radius:8px;border-color:var(--gv-line);}
.status-tab{border-radius:8px;}
.add-btn{border-radius:8px;}

/* modals */
.modal-content{border-radius:12px;border:1px solid var(--gv-line);border-top:5px solid var(--gov-gold);overflow:hidden;}
.pg-alert{border-radius:8px;border-left-width:5px;}

/* footer */
.gov-footer{display:block;margin-top:auto;padding-top:30px;}
.gov-footer .inner{border-top:1px solid var(--gv-line);padding-top:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:12.5px;color:var(--gv-soft);}
}
@media screen and (max-width:900px){
.sidebar{width:215px;}
.main-content{margin-left:215px;padding:calc(var(--top-offset) + 20px) 20px 18px;}
}
@media screen and (max-width:768px){
:root{--strip-h:30px;--header-h:62px;}
.gov-strip{padding:0 14px;font-size:11px;}
.gov-strip .right{display:none;}
header{padding:0 14px;}
.brand .titles h1{font-size:15px;letter-spacing:.8px;}
.brand .titles small{font-size:10px;}
.seal{width:38px;height:38px;}
.brand .titles{padding-left:10px;}
header a.logout{padding:8px 14px;font-size:12.5px;}
.sidebar{position:static;width:100%;height:auto;margin-top:var(--top-offset);padding:8px;display:flex;flex-wrap:wrap;gap:3px;}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
.sidebar a:hover{transform:none;}
.main-content{margin-left:0;padding:18px 15px;min-height:auto;}
.page-card{padding:18px;margin-bottom:20px;}
.page-info h2,.page-card h2{font-size:19px;}
.role-pill{margin-left:0;}
}
@media print{
.gov-strip,.gov-footer,.role-pill,.page-date{display:none !important;}
}

/* --- assign_beneficiaries specifics --- */
@media screen{
.search-card{border-radius:12px;border:1px solid var(--gv-line);border-left:5px solid var(--gov-gold);box-shadow:var(--gv-shadow);}
.search-card input{border-radius:8px;}
.search-card button{border-radius:8px;}
.barangay-tab{border-radius:8px;}
.barangay-tab.active{background:linear-gradient(120deg,#123c16,#1b5e20);border-bottom:3px solid var(--gov-gold);}
.barangay-panel{border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);}
.barangay-panel .panel-head{background:#fbfdfb;border-bottom:1px dashed var(--gv-line);}
table.beneficiary-table thead th{background:#123c16;color:#fff;}
}

/* =========================================================
   RESPONSIVE - same behaviour as admin_dashboard.php
   (menu moves to the top as a wrapped row of buttons)
========================================================= */
@media screen and (max-width:768px){
body{flex-direction:column;}
.sidebar{
position:static;width:100%;height:auto;margin-top:var(--top-offset);padding:8px;
display:flex;flex-wrap:wrap;gap:3px;overflow:visible;transform:none;
}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
.sidebar a:hover{transform:none;}
.main-content{margin-left:0;width:100%;padding:18px 15px;min-height:auto;}
header .brand{min-width:0;}
.brand .titles{min-width:0;}
.brand .titles small{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:46vw;}
header a.logout{flex:none;}
.gov-strip .left{min-width:0;white-space:nowrap;overflow:hidden;}
.gov-strip .left span{overflow:hidden;text-overflow:ellipsis;}
}
@media screen and (max-width:480px){
.brand .seal + .seal{display:none;}
.brand .titles small{display:none;}
header a.logout{padding:8px 12px;}
}
</style>
</head>
<body>

<div class="gov-strip">
    <div class="left">
        <i class="fas fa-landmark"></i>
        <span>An official website of the <strong>Municipality of Cabatuan, Iloilo</strong></span>
    </div>
    <div class="right">Republic of the Philippines &bull; Municipal Agriculture Office</div>
</div>

<header>
    <div class="brand">
        <div class="seal">
            <img src="../img/CABATUAN.png" alt="Municipality of Cabatuan seal" onerror="this.parentNode.classList.add('fallback');this.outerHTML='<i class=&quot;fas fa-paw&quot;></i>'">
        </div>
        <div class="seal">
            <img src="../img/DA.png" alt="Department of Agriculture seal" onerror="this.parentNode.style.display='none'">
        </div>
        <div class="titles">
            <h1>PIGOBAO</h1>
            <small>Technician Portal &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../tech_logout.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../technician_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="assign_beneficiaries.php" class="active"><i class="fas fa-user-check"></i> Assign Beneficiaries</a>
    <a href="monitoring.php"><i class="fas fa-stethoscope"></i> Monitoring</a>
    <a href="monitoring_history.php"><i class="fas fa-history"></i> Monitoring History</a>
    <a href="farmer_success_rate.php"><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="mortality_cases.php"><i class="fas fa-skull-crossbones"></i> Mortality Cases</a>
    <a href="livestock_return.php"><i class="fas fa-undo"></i> Livestock Return</a>
    <a href="tech_reports.php"><i class="fas fa-file-alt"></i> Reports</a>
    <a href="generate_report.php" ><i class="fas fa-file-pdf"></i> Generate Report</a>
</div>

<div class="main-content">

    <div class="page-card">
        <div class="page-icon"><i class="fas fa-user-check"></i></div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Assigned Beneficiaries</h2>
            <p>Browse beneficiaries grouped by your assigned barangays.</p>
        </div>
        <span class="role-pill"><i class="fas fa-user-gear"></i> Technician</span>
    </div>

    <div class="stat-row">
        <div class="stat-card">
            <div class="icon"><i class="fas fa-map-marker-alt"></i></div>
            <div>
                <div class="num"><?= $totalBarangays ?></div>
                <div class="label">Assigned Barangays</div>
            </div>
        </div>
        <div class="stat-card beneficiaries">
            <div class="icon"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="num"><?= $totalBeneficiaries ?></div>
                <div class="label">Total Beneficiaries<?= $search !== '' ? ' (filtered)' : '' ?></div>
            </div>
        </div>
    </div>

    <div class="search-card">
        <form method="GET">
            <div class="input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search beneficiary or barangay" value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit"><i class="fas fa-search"></i> Search</button>
        </form>
    </div>

    <?php if(count($barangayList) === 0): ?>
        <div class="barangay-panel active">
            <div class="panel-head"><h3>No barangays assigned</h3></div>
            <table class="beneficiary-table"><tbody><tr><td class="empty-row">You currently have no assigned barangays.</td></tr></tbody></table>
        </div>
    <?php else: ?>

    <!-- Barangays as horizontal column tabs -->
    <div class="barangay-tabs">
        <?php foreach($barangayList as $i => $br): ?>
            <button type="button" class="barangay-tab <?= $i === 0 ? 'active' : '' ?>" data-target="panel-<?= $br['barangay_id'] ?>" onclick="showBarangay('<?= $br['barangay_id'] ?>', this)">
                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($br['barangay_name']) ?>
                <span class="count-badge"><?= $barangayCounts[$br['barangay_id']] ?? 0 ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- One panel per barangay -->
    <?php foreach($barangayList as $i => $br): ?>
        <div class="barangay-panel <?= $i === 0 ? 'active' : '' ?>" id="panel-<?= $br['barangay_id'] ?>">
            <div class="panel-head">
                <h3><i class="fas fa-map-marker-alt" style="color:var(--pg-forest-600);margin-right:8px;"></i><?= htmlspecialchars($br['barangay_name']) ?></h3>
                <span class="count-badge" style="background:var(--pg-forest-100);color:var(--pg-forest-800);"><?= $barangayCounts[$br['barangay_id']] ?? 0 ?> beneficiaries</span>
            </div>
            <table class="beneficiary-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Gender</th>
                        <th>Contact</th>
                        <th>Civil Status</th>
                        <th>Status</th>
                        <th>Registered</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sqlB = "
                        SELECT * FROM beneficiaries 
                        WHERE barangay_id=? 
                        AND (firstname LIKE ? OR lastname LIKE ? OR ?='')
                    ";
                    $stmtB = $conn->prepare($sqlB);
                    $stmtB->bind_param("isss",$br['barangay_id'],$like,$like,$search);
                    $stmtB->execute();
                    $beneficiaries = $stmtB->get_result();
                    if($beneficiaries->num_rows>0):
                        while($b = $beneficiaries->fetch_assoc()):
                    ?>
                            <tr>
                                <td>
                                    <?php $bFull = trim($b['firstname'].' '.$b['lastname']); ?>
                                    <div class="farmer-cell">
                                        <?= pg_farmer_avatar($farmerPhotoCol ? ($b[$farmerPhotoCol] ?? null) : null, $bFull) ?>
                                        <div><?= htmlspecialchars($bFull) ?></div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($b['gender']) ?></td>
                                <td><?= htmlspecialchars($b['contact'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($b['civil_status'] ?: '—') ?></td>
                                <td>
                                    <span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;
                                    <?= ($b['status'] ?? 'active') === 'active' ? 'background:#e6f5e8;color:#1e7e34;' : 'background:#eef1ef;color:#5d6b60;' ?>">
                                        <?= htmlspecialchars(ucfirst($b['status'] ?? 'active')) ?>
                                    </span>
                                </td>
                                <td><?= !empty($b['registered_at']) ? htmlspecialchars(date('M j, Y', strtotime($b['registered_at']))) : '—' ?></td>
                            </tr>
                    <?php endwhile; else: ?>
                            <tr><td class="empty-row" colspan="6">No beneficiaries found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

    <?php endif; ?>

    <div class="gov-footer">
        <div class="inner">
            <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
            <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
        </div>
    </div>

</div>

<script>
function showBarangay(id, btn){
    document.querySelectorAll('.barangay-panel').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.barangay-tab').forEach(function(t){ t.classList.remove('active'); });
    document.getElementById('panel-' + id).classList.add('active');
    btn.classList.add('active');
}
</script>


<script>
/* Click a farmer's profile picture to view it larger */
document.addEventListener('click', function(e){
    var img = e.target.closest ? e.target.closest('.farmer-avatar img, .modal-farmer .farmer-avatar img') : null;
    if(!img || !img.getAttribute('src')) return;
    var ov = document.createElement('div'); ov.className = 'pg-photo-view';
    var fig = document.createElement('figure');
    var big = document.createElement('img'); big.src = img.src; big.alt = img.alt || '';
    var cap = document.createElement('figcaption'); cap.textContent = img.alt || '';
    var x = document.createElement('button'); x.type = 'button'; x.innerHTML = '&times;'; x.setAttribute('aria-label','Close');
    fig.appendChild(big); fig.appendChild(cap); fig.appendChild(x); ov.appendChild(fig);
    function close(){ ov.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(ev){ if(ev.key === 'Escape') close(); }
    ov.addEventListener('click', close); document.addEventListener('keydown', onKey);
    document.body.appendChild(ov);
});
</script>
</body>
</html>