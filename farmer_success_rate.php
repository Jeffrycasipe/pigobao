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


if(!isset($_SESSION['technician_id'])){
    header("Location: ../technicianindex.php");
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
   FARMER SUCCESS RATE
   Scoped to beneficiaries assigned to this technician, i.e.
   beneficiaries whose barangay_id is in technician_barangay
   for this technician_id.

   Relationships (per pigo.sql):
     beneficiaries(beneficiary_id, firstname, middlename, lastname, barangay_id, ...)
     technician_barangay(technician_id, barangay_id)
     distributions(distribution_id, beneficiary_id, livestock_id, quantity, status, ...)
     livestock(livestock_id, kind, breed, ...)
     monitoring(monitoring_id, distribution_id, health_status, offspring_count, ...)

   The latest monitoring row (highest monitoring_id) per distribution is
   used as the current health status / offspring count.

   Success Rate (%) = milestone score: health 30 + offspring alive 30 + returned 40 (see pg_success_rate)

   Summary cards (Total Distribution, Success Rate, Active, Returned, Death): same numbers as the admin page
   (views q_atotal_distribution / q_success_rate_year) but calculated only for this
   technician's assigned beneficiaries. Year filter: ?year=YYYY (0 = All Years).
   Success = distributions that did NOT end in death (active + returned).
========================================================= */
// Scope: only beneficiaries in barangays assigned to this technician
$scope = "b.barangay_id IN (SELECT barangay_id FROM technician_barangay WHERE technician_id=$tech_id)";

// ---------- YEAR FILTER ----------
// Years that have data for this technician's beneficiaries
$dataYears = [];
$res = $conn->query("
    SELECT DISTINCT YEAR(d.distribution_date) AS yr
    FROM distributions d
    JOIN beneficiaries b ON b.beneficiary_id = d.beneficiary_id
    WHERE $scope
");
if ($res) { while ($r = $res->fetch_assoc()) { $dataYears[] = (int)$r['yr']; } }

// Dropdown: current year back 10 years (past years show even with no data),
// plus any year that exists in the data. Change 9 to show more/fewer past years.
$currentYear = (int)date('Y');
$yearOptions = range($currentYear, $currentYear - 9);
foreach ($dataYears as $y) { if (!in_array($y, $yearOptions, true)) { $yearOptions[] = $y; } }
rsort($yearOptions);

$selYear = (isset($_GET['year']) && ctype_digit($_GET['year'])) ? (int)$_GET['year'] : 0;
if ($selYear && !in_array($selYear, $yearOptions, true)) { $selYear = 0; }   // unknown year -> All Years
$yearAnd = $selYear ? " AND YEAR(d.distribution_date) = " . (int)$selYear : "";

// ---------- SUMMARY (same logic as q_atotal_distribution / q_success_rate_year, but scoped) ----------
$countByStatus = ['active' => 0, 'returned' => 0, 'death' => 0];
$res = $conn->query("
    SELECT d.status, COUNT(d.distribution_id) AS total
    FROM distributions d
    JOIN beneficiaries b ON b.beneficiary_id = d.beneficiary_id
    WHERE $scope $yearAnd
    GROUP BY d.status
");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if (isset($countByStatus[$r['status']])) { $countByStatus[$r['status']] = (int)$r['total']; }
    }
}

$totalDistribution = array_sum($countByStatus);

$activeCount   = $countByStatus['active'];
$returnedCount = $countByStatus['returned'];
$deathCount    = $countByStatus['death'];
$successCount  = $activeCount + $returnedCount;   // success = not lost to death

// each as a % of the total distribution
$successRate  = $totalDistribution > 0 ? ($successCount  / $totalDistribution) * 100 : 0;
$activeRate   = $totalDistribution > 0 ? ($activeCount   / $totalDistribution) * 100 : 0;
$returnedRate = $totalDistribution > 0 ? ($returnedCount / $totalDistribution) * 100 : 0;
$deathRate    = $totalDistribution > 0 ? ($deathCount    / $totalDistribution) * 100 : 0;
$periodLabel  = $selYear ? (string)$selYear : 'All Years';

/* =========================================================
   SUCCESS RATE PER DISTRIBUTION (milestone based, max 100%)

     Health      30 pts  latest monitoring: Healthy 30 / Sick 15 / Critical 5
     Offspring   30 pts  alive offspring vs. quantity distributed
                         (alive >= quantity = 30; pregnant, not yet born = 10)
     Returned    40 pts  distribution status = 'returned'

   Dead livestock = 0%.
   100% is only reached when the livestock is healthy, has enough
   living offspring AND has already been returned.
========================================================= */
function pg_success_rate(array $row, &$breakdown = null) {
    $status = strtolower(trim($row['status'] ?? ''));
    $qty    = max(1, (int)($row['quantity'] ?? 0));
    $health = strtolower(trim($row['health_status'] ?? ''));
    $preg   = strtolower(trim($row['pregnancy_status'] ?? ''));
    $alive  = (int)($row['offspring_alive'] ?? 0);

    if ($status === 'death' || $health === 'dead') {
        $breakdown = ['health' => 0, 'offspring' => 0, 'returned' => 0];
        return 0;
    }

    $hMap = ['healthy' => 30, 'sick' => 15, 'critical' => 5];
    $h = $hMap[$health] ?? 0;

    if ($alive > 0)              $o = min(1, $alive / $qty) * 30;
    elseif ($preg === 'pregnant') $o = 10;
    else                          $o = 0;

    $r = ($status === 'returned') ? 40 : 0;

    $breakdown = ['health' => $h, 'offspring' => round($o, 1), 'returned' => $r];
    return min(100, $h + $o + $r);
}

// ---------- BENEFICIARY LIVESTOCK TABLE ----------
$rows=[];
$sql="
    SELECT
        d.distribution_id,
        CONCAT_WS(' ', b.firstname, NULLIF(b.middlename,''), b.lastname,
            NULLIF(NULLIF(b.extension_name,'n/a'), '')) AS beneficiary_name{$photoSql},
        d.quantity,
        l.kind AS livestock_type,
        l.breed,
        d.status AS status,
        COALESCE(m.offspring_count, 0) AS offspring_count,
        COALESCE(m.offspring_alive, 0) AS offspring_alive,
        COALESCE(m.offspring_dead, 0) AS offspring_dead,
        m.health_status,
        m.pregnancy_status
    FROM distributions d
    JOIN beneficiaries b ON b.beneficiary_id = d.beneficiary_id
    JOIN livestock l ON l.livestock_id = d.livestock_id
    LEFT JOIN (
        SELECT m1.distribution_id, m1.health_status, m1.offspring_count, m1.offspring_alive, m1.offspring_dead, m1.pregnancy_status
        FROM monitoring m1
        INNER JOIN (
            SELECT distribution_id, MAX(monitoring_id) AS latest_id
            FROM monitoring
            GROUP BY distribution_id
        ) latest ON latest.latest_id = m1.monitoring_id
    ) m ON m.distribution_id = d.distribution_id
    WHERE $scope $yearAnd
    ORDER BY b.lastname ASC, b.firstname ASC
";

$result=$conn->query($sql);
if($result){
    while($row=$result->fetch_assoc()){
        $row['rate']=pg_success_rate($row, $row['breakdown']);
        $rows[]=$row;
    }
}

usort($rows, function($a, $b){
    return $b['rate'] <=> $a['rate'];
});

function distStatusBadge($status) {
    $status = strtolower(trim($status ?? ''));
    $map = [
        'active'   => ['bg' => '#e8f5e9', 'fg' => '#1b5e20', 'icon' => 'fa-circle-check',  'label' => 'Active'],
        'returned' => ['bg' => '#e6f0f8', 'fg' => '#3f7cac', 'icon' => 'fa-rotate-left',   'label' => 'Returned'],
        'death'    => ['bg' => '#fbeae7', 'fg' => '#c1543f', 'icon' => 'fa-skull',         'label' => 'Dead'],
    ];
    $s = $map[$status] ?? ['bg' => '#eceff1', 'fg' => '#5d6b60', 'icon' => 'fa-circle-question', 'label' => ucfirst($status)];
    return '<span class="status-badge" style="background:' . $s['bg'] . ';color:' . $s['fg'] . ';">'
         . '<i class="fas ' . $s['icon'] . '"></i>&nbsp;' . htmlspecialchars($s['label']) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Farmer Success Rate - PIGOBAO</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
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
    --pg-sky:#3f7cac;
    --pg-clay:#c1543f;
    --shadow-soft:0 1px 2px rgba(18,60,22,.04),0 8px 24px -12px rgba(18,60,22,.16);
    --shadow-lift:0 12px 28px -14px rgba(18,60,22,.28);
}

*{ box-sizing:border-box; margin:0; padding:0; }

body{
    min-height:100vh;
    background:var(--pg-canvas);
    background-image:
        radial-gradient(1100px 480px at 100% -10%,rgba(46,125,50,.07),transparent 60%),
        radial-gradient(700px 380px at -5% 100%,rgba(27,94,32,.05),transparent 55%);
    font-family:'Poppins',sans-serif;
    color:var(--pg-ink);
}

header{
    position:fixed; top:0; left:0; width:100%; height:65px;
    background:linear-gradient(120deg,var(--pg-forest-900),var(--pg-forest-800) 55%,var(--pg-forest-600));
    color:#fff; display:flex; justify-content:space-between; align-items:center;
    padding:0 30px; z-index:1001; box-shadow:0 2px 14px rgba(10,40,12,.25);
}
.brand{ display:flex; align-items:center; gap:12px; }
.brand-mark{
    width:34px; height:34px; border-radius:9px; background:rgba(255,255,255,.14);
    display:flex; align-items:center; justify-content:center; color:#eafbea;
    border:1px solid rgba(255,255,255,.22);
}
.brand h1{ font-size:17px; font-weight:600; letter-spacing:.2px; margin:0; }
.brand small{ display:block; font-size:11px; color:rgba(255,255,255,.65); text-transform:uppercase; letter-spacing:.4px; }
.logout{
    color:var(--pg-forest-800); background:#fff; padding:8px 20px; border-radius:20px;
    font-weight:600; text-decoration:none; font-size:14px; box-shadow:0 2px 8px rgba(0,0,0,.12); transition:.2s ease;
}
.logout:hover{ background:var(--pg-forest-100); color:var(--pg-forest-900); transform:translateY(-1px); }

.sidebar{
    position:fixed; top:65px; left:0; width:230px; height:calc(100% - 65px);
    background:linear-gradient(195deg,var(--pg-forest-800),var(--pg-forest-600) 120%);
    padding-top:22px; z-index:1000; box-shadow:inset -1px 0 0 rgba(255,255,255,.06);
}
.sidebar a{
    display:flex; align-items:center; gap:12px; color:rgba(255,255,255,.88);
    padding:13px 22px; text-decoration:none; margin:2px 12px 4px; border-radius:9px;
    border-left:3px solid transparent; font-size:14.5px; font-weight:500; transition:.2s ease;
}
.sidebar a i{ width:18px; text-align:center; font-size:15px; }
.sidebar a:hover{ background:rgba(255,255,255,.12); transform:translateX(2px); }
.sidebar a.active{
    background:var(--pg-forest-300); color:var(--pg-forest-900); border-left:3px solid var(--pg-forest-900);
    font-weight:600; box-shadow:0 4px 12px rgba(0,0,0,.18);
}

.main-content{ margin-left:230px; padding:94px 38px 40px; min-height:100vh; width:auto; }

.profile-card{
    background:#fff; border-radius:16px; padding:26px 30px; box-shadow:var(--shadow-soft);
    border:1px solid var(--pg-line); display:flex; align-items:center; gap:22px;
    flex-wrap:wrap; margin-bottom:28px; position:relative; overflow:hidden;
}
.profile-card::before{
    content:""; position:absolute; top:0; left:0; width:100%; height:5px;
    background:linear-gradient(90deg,var(--pg-forest-800),var(--pg-forest-300));
}
.avatar-badge{
    width:60px; height:60px; border-radius:16px; flex:none;
    background:linear-gradient(145deg,var(--pg-forest-600),var(--pg-forest-900));
    display:flex; align-items:center; justify-content:center; color:#fff;
    font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:20px;
    letter-spacing:.5px; box-shadow:0 8px 18px -6px rgba(18,60,22,.5);
}
.profile-info h2{ font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:21px; margin:0 0 4px; color:var(--pg-forest-900); }
.profile-info p{ font-size:13.5px; color:var(--pg-ink-soft); margin:0; }
.role-pill{
    margin-left:auto; padding:6px 14px; border-radius:999px; font-size:12.5px; font-weight:600;
    letter-spacing:.3px; background:var(--pg-forest-100); color:var(--pg-forest-800);
    display:inline-flex; align-items:center; gap:7px;
}
.role-pill::before{ content:""; width:7px; height:7px; border-radius:50%; background:currentColor; }

.section-label{
    font-family:'Space Grotesk',sans-serif; font-size:13px; font-weight:600;
    letter-spacing:.6px; text-transform:uppercase; color:var(--pg-ink-soft); margin:0 0 14px 2px;
}

/* FILTER BAR */
.toolbar{ display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
.toolbar .section-label{ margin:0; }
.filter-bar{ display:flex; align-items:center; gap:10px; background:#fff; border:1px solid var(--pg-line);
    border-radius:12px; padding:8px 14px; box-shadow:var(--shadow-soft); }
.filter-bar label{ font-size:12.5px; font-weight:600; color:var(--pg-ink-soft); text-transform:uppercase; letter-spacing:.4px; margin:0; }
.filter-bar select{ border:none; outline:none; background:transparent; font-size:14px; font-weight:600;
    color:var(--pg-forest-900); cursor:pointer; padding:2px 4px; }

/* STAT CARDS */
/* 6-column grid: Total + Success Rate on the first row (3 cols each),
   Active / Returned / Death on the second row (2 cols each) */
.stat-grid{ display:grid; grid-template-columns:repeat(6, 1fr); gap:18px; margin-bottom:28px; }
.stat-card.wide{ grid-column:span 3; }
.stat-card.narrow{ grid-column:span 2; }
.stat-card{
    background:#fff; border:1px solid var(--pg-line); border-radius:16px; box-shadow:var(--shadow-soft);
    padding:22px 24px; display:flex; align-items:center; gap:18px; position:relative; overflow:hidden;
    transition:.2s ease;
}
.stat-card:hover{ box-shadow:var(--shadow-lift); transform:translateY(-2px); }
.stat-card::before{ content:""; position:absolute; left:0; top:0; bottom:0; width:5px; background:var(--accent); }
.stat-icon{
    width:52px; height:52px; border-radius:14px; flex:none; display:flex; align-items:center; justify-content:center;
    font-size:20px; color:var(--accent); background:var(--accent-soft);
}
.stat-body h3{ font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--pg-ink-soft); margin:0 0 4px; }
.stat-body .value{ font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:30px; line-height:1.1; color:var(--pg-forest-900); }
.stat-body p{ font-size:12.5px; color:var(--pg-ink-soft); margin:4px 0 0; }
.stat-card.total{    --accent:#7c5cbf;              --accent-soft:#efe9fa; }
.stat-card.success{  --accent:var(--pg-forest-800); --accent-soft:var(--pg-forest-100); }
.stat-card.active{   --accent:#43a047;              --accent-soft:#e8f5e9; }
.stat-card.returned{ --accent:var(--pg-sky);        --accent-soft:#e6f0f8; }
.stat-card.death{    --accent:var(--pg-clay);       --accent-soft:#fbeae7; }

.table-card{ background:#fff; border:1px solid var(--pg-line); border-radius:16px; box-shadow:var(--shadow-soft); overflow:hidden; }
table.success-table{ width:100%; border-collapse:collapse; }
table.success-table thead th{
    background:var(--pg-forest-100); color:var(--pg-forest-900); font-size:12px; font-weight:600;
    text-transform:uppercase; letter-spacing:.4px; text-align:left; padding:14px 18px; border-bottom:1px solid var(--pg-line);
}
table.success-table tbody td{ padding:14px 18px; font-size:14px; color:var(--pg-ink); border-bottom:1px solid var(--pg-line); }
table.success-table tbody tr:last-child td{ border-bottom:none; }
table.success-table tbody tr:hover{ background:var(--pg-canvas); }

.status-badge{ display:inline-flex; align-items:center; padding:5px 12px; border-radius:999px; font-size:12.5px; font-weight:600; }

.rate-cell{ display:flex; align-items:center; gap:10px; min-width:140px; }
.rate-bar{ flex:1; height:8px; border-radius:6px; background:var(--pg-line); overflow:hidden; }
.rate-bar-fill{ height:100%; border-radius:6px; background:linear-gradient(90deg, var(--pg-forest-300), var(--pg-forest-600)); }
.rate-value{ font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:13.5px; color:var(--pg-forest-900); min-width:44px; text-align:right; }

.empty-state{ padding:50px 20px; text-align:center; color:var(--pg-ink-soft); }
.empty-state i{ font-size:34px; margin-bottom:12px; color:var(--pg-forest-300); }

@media(max-width:900px){
    .stat-grid{ grid-template-columns:repeat(2, 1fr); }
    .stat-card.wide, .stat-card.narrow{ grid-column:auto; }
    .sidebar{ width:200px; }
    .main-content{ margin-left:200px; padding:94px 20px 30px; }
    .role-pill{ margin-left:0; }
}
@media(max-width:600px){
    .stat-grid{ grid-template-columns:1fr; }
    header{ padding:0 15px; }
    .brand h1{ font-size:15px; }
    .brand small{ font-size:9px; }
    .logout{ padding:7px 14px; font-size:12px; }
    .sidebar{ width:100%; height:auto; top:65px; padding:8px; z-index:999; display:flex; flex-wrap:wrap; gap:2px; }
    .sidebar a{ margin:2px; padding:9px 10px; font-size:10px; }
    .main-content{ margin-left:0; padding:155px 14px 25px; }
    .profile-card{ padding:20px; gap:15px; }
    .profile-info h2{ font-size:18px; }
    .role-pill{ margin-left:0; width:100%; justify-content:center; }
    .table-card{ overflow-x:auto; }
    table.success-table{ min-width:820px; }
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
   GOVERNMENT PORTAL THEME (matches the dashboards)
   Screen only - PHP, JS and forms are untouched.
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

/* ---------- government strip ---------- */
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

/* ---------- header ---------- */
header{
top:var(--strip-h);height:var(--header-h);padding:0 30px;z-index:1002;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
box-shadow:0 4px 16px rgba(10,40,12,.28);
}
header .brand{display:flex;align-items:center;gap:14px;}
.seal{width:46px;height:46px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;box-shadow:0 0 0 2px rgba(255,255,255,.35);}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.seal.fallback{font-size:18px;color:#1b5e20;}
header .brand .titles{padding-left:14px;border-left:1px solid rgba(255,255,255,.28);}
header .brand .titles h1{font-family:'Merriweather',serif;font-size:19px;font-weight:700;letter-spacing:1.2px;line-height:1.15;margin:0;color:#fff;}
header .brand .titles small{display:block;font-size:12px;font-weight:400;color:rgba(255,255,255,.78);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;}
header a.logout{
color:#123c16;background:#fff;padding:9px 22px;border-radius:8px;font-weight:600;font-size:14px;
text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,.14);transition:.2s ease;
}
header a.logout:hover{background:#e8f5e9;transform:translateY(-1px);color:#123c16;}

/* ---------- sidebar ---------- */
.sidebar{
top:var(--top-offset);width:250px;height:calc(100% - var(--top-offset));
background:linear-gradient(195deg,#164f1b,#1b5e20 45%,#2e7d32 130%);
padding-top:14px;overflow-y:auto;z-index:1000;
}
.sidebar .menu-title{font-size:11.5px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:rgba(255,255,255,.55);padding:6px 26px 8px;}
.sidebar a{display:flex;align-items:center;margin:2px 12px 3px;padding:12px 20px;font-size:15px;gap:13px;border-radius:8px;border-left:4px solid transparent;color:rgba(255,255,255,.92);}
.sidebar a i{width:20px;text-align:center;font-size:15.5px;}
.sidebar a.active{background:#fff;color:#123c16;border-left:4px solid var(--gov-gold);font-weight:600;box-shadow:0 6px 16px rgba(0,0,0,.2);}
.sidebar a.active i{color:#123c16;}

/* ---------- full-width content ---------- */
.main-content{
margin-left:250px;
padding:calc(var(--top-offset) + 26px) 34px 20px;
flex:1;width:auto;min-width:0;min-height:100vh;
display:flex;flex-direction:column;
}

/* ---------- page banner ---------- */
.page-head,.profile-card{
background:#fff;border:1px solid var(--gv-line);border-left:6px solid #1b5e20;border-radius:12px;
padding:22px 26px;box-shadow:var(--gv-shadow);margin-bottom:26px;position:relative;overflow:hidden;
display:flex;align-items:center;gap:18px;flex-wrap:wrap;
}
.profile-card::before{display:none;}
.page-head-content{display:flex;align-items:center;gap:18px;flex:1;min-width:0;}
.page-icon,.profile-card .avatar-badge{
width:60px;height:60px;border-radius:50%;flex:none;font-size:22px;color:#fff;
display:flex;align-items:center;justify-content:center;
background:linear-gradient(145deg,#2e7d32,#123c16);box-shadow:0 0 0 4px #e8f5e9;
}
.page-date{font-size:12.5px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--gov-navy);margin-bottom:2px;display:flex;align-items:center;gap:7px;}
.page-head h2,.profile-info h2{font-family:'Space Grotesk',sans-serif;font-size:23px;font-weight:700;color:#123c16;margin:0 0 3px;}
.page-head p,.profile-info p{font-size:14px;color:var(--gv-soft);margin:0;max-width:700px;}
.page-text{flex:1;min-width:0;}
.role-pill{
margin-left:auto;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#123c16;color:#fff;
display:inline-flex;align-items:center;gap:8px;letter-spacing:.3px;
}
.role-pill::before{display:none;}
.role-pill i{color:var(--gov-gold);}

/* ---------- section label ---------- */
.section-label{
font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;
color:#123c16;display:flex;align-items:center;gap:10px;
}
.section-label::before{content:"";width:5px;height:20px;border-radius:3px;background:linear-gradient(180deg,var(--gov-gold),#2e7d32);}

/* ---------- alerts ---------- */
.success-banner,.alert-success-custom{border-radius:10px;border-left:5px solid #1b5e20;font-size:14px;}
.error-banner,.alert-error-custom{border-radius:10px;border-left:5px solid var(--gov-red);font-size:14px;}

/* ---------- stat cards ---------- */
.stat-row,.stats{gap:18px;margin-bottom:26px;}
.stat-row .stat-card,.stats .stat-card{
border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;
padding:20px 22px;box-shadow:var(--gv-shadow);
}
.stat-row .stat-card:hover,.stats .stat-card:hover{box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.stat-card.healthy,.stat-card.normal{border-top-color:#1b5e20;}
.stat-card.sick{border-top-color:#b7791f;}
.stat-card.neglected{border-top-color:#9b2c2c;}
.stat-card .num,.stat-value{font-size:32px;line-height:1.1;}
.stat-card .label,.stat-label{font-size:12.5px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;}
.stat-grid .stat-card{border-radius:12px;box-shadow:var(--gv-shadow);}
.stat-body h3{font-size:12.5px;font-family:'Space Grotesk',sans-serif;letter-spacing:.7px;font-weight:700;}
.stat-body .value{font-size:34px;}

/* ---------- filters / search ---------- */
.search-card,.table-card{
border:1px solid var(--gv-line);border-radius:12px;box-shadow:var(--gv-shadow);background:#fff;
}
.table-card{border-top:5px solid var(--gov-navy);}
.search-card{border-left:6px solid #1b5e20;}
.search-card input,.search-wrap input{font-size:14px;border-radius:8px;border:1.5px solid #d3ddd5;}
.search-card input:focus,.search-wrap input:focus{border-color:#2e7d32;box-shadow:0 0 0 3px rgba(46,125,50,.15);outline:none;}
.brgy-pill{font-size:13px;font-weight:600;border-radius:8px;padding:7px 14px;border:1.5px solid #cfd9d1;transition:.2s ease;}
.brgy-pill:hover{border-color:#1b5e20;color:#1b5e20;}
.brgy-pill.active{background:#123c16;border-color:#123c16;color:#fff;box-shadow:inset 0 -3px 0 var(--gov-gold);}
.filter-bar{border-radius:10px;border:1px solid var(--gv-line);}
.filter-bar label{font-size:12.5px;}
.filter-bar select{font-size:14px;background:#f3f6f4;border:1px solid var(--gv-line);border-radius:7px;padding:6px 10px;}

/* ---------- buttons ---------- */
.search-card button,.search-btn,.btn-save,.crud-modal .btn-save,
.btn-cancel,.btn-danger-solid,.crud-modal .btn-danger-solid,.crud-modal .btn-light,
.btn-act,.action-btn{
font-family:'Poppins',sans-serif;letter-spacing:.3px;
transition:background .2s ease,transform .2s ease,box-shadow .2s ease,border-color .2s ease,color .2s ease;
}
.search-card button:focus-visible,.search-btn:focus-visible,.btn-save:focus-visible,.btn-cancel:focus-visible,
.btn-danger-solid:focus-visible,.btn-act:focus-visible,.action-btn:focus-visible,.brgy-pill:focus-visible{
outline:2px solid var(--gov-gold);outline-offset:2px;
}
.search-card button,.search-btn{
border-radius:8px;padding:11px 24px;font-size:14px;font-weight:600;
background:linear-gradient(120deg,#123c16,#1b5e20);
box-shadow:0 4px 12px -4px rgba(18,60,22,.5);
}
.search-card button:hover,.search-btn:hover{transform:translateY(-1px);box-shadow:0 8px 16px -6px rgba(18,60,22,.55);}

/* row action icons (edit / delete) */
.btn-act{width:38px;height:38px;border-radius:8px;font-size:14px;border-width:1.5px;background:#fff;}
.btn-act.edit{color:var(--gov-navy);border-color:var(--gov-navy);}
.btn-act.edit:hover{background:var(--gov-navy);color:#fff;border-color:var(--gov-navy);transform:translateY(-1px);box-shadow:0 8px 14px -6px rgba(11,47,91,.5);}
.btn-act.del{color:var(--gov-red);border-color:var(--gov-red);}
.btn-act.del:hover{background:var(--gov-red);color:#fff;border-color:var(--gov-red);transform:translateY(-1px);box-shadow:0 8px 14px -6px rgba(179,38,46,.5);}

/* return / transfer row buttons */
.action-buttons{gap:8px;}
.action-btn{padding:9px 16px;font-size:12.5px;border-radius:8px;border:1.5px solid transparent;background:#fff;}
.return-btn{color:var(--gov-red);border-color:var(--gov-red);background:#fff;}
.return-btn:hover{background:var(--gov-red);color:#fff;transform:translateY(-1px);box-shadow:0 8px 14px -6px rgba(179,38,46,.5);}
.transfer-btn{color:var(--gov-navy);border-color:var(--gov-navy);background:#fff;}
.transfer-btn:hover{background:var(--gov-navy);color:#fff;transform:translateY(-1px);box-shadow:0 8px 14px -6px rgba(11,47,91,.5);}

/* modal footer buttons */
.btn-save,.crud-modal .btn-save{
border:0;border-radius:8px;padding:10px 24px;font-size:13.5px;font-weight:600;color:#fff;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
box-shadow:0 6px 14px -6px rgba(18,60,22,.6);
display:inline-flex;align-items:center;justify-content:center;gap:8px;
}
.btn-save:hover,.crud-modal .btn-save:hover{background:linear-gradient(120deg,#0d2e10,#123c16);color:#fff;transform:translateY(-1px);box-shadow:0 10px 18px -8px rgba(18,60,22,.65);}
.btn-save i{color:var(--gov-gold);}
.btn-danger-solid,.crud-modal .btn-danger-solid{
border:0;border-radius:8px;padding:10px 24px;font-size:13.5px;font-weight:600;color:#fff;
background:linear-gradient(120deg,#8f1c23,var(--gov-red));
box-shadow:0 6px 14px -6px rgba(179,38,46,.6);
display:inline-flex;align-items:center;justify-content:center;gap:8px;
}
.btn-danger-solid:hover,.crud-modal .btn-danger-solid:hover{background:linear-gradient(120deg,#761319,#8f1c23);color:#fff;transform:translateY(-1px);}
.btn-cancel,.crud-modal .btn-light{
border:1.5px solid #cfd9d1;border-radius:8px;background:#fff;color:#4f5d53;
padding:10px 22px;font-size:13.5px;font-weight:600;
}
.btn-cancel:hover,.crud-modal .btn-light:hover{background:#f3f6f4;border-color:#9fb0a3;color:#233126;}

/* ---------- tables ---------- */
table.history-table thead th,table.success-table thead th,.data-table thead th{
background:#123c16;color:#fff;font-family:'Space Grotesk',sans-serif;font-size:11.5px;font-weight:600;
letter-spacing:.6px;text-transform:uppercase;padding:13px 16px;white-space:nowrap;
}
table.history-table tbody td,table.success-table tbody td,.data-table tbody td{font-size:13.5px;padding:12px 16px;}
table.history-table tbody tr:nth-child(even):not(.just-saved),
table.success-table tbody tr:nth-child(even),
.data-table tbody tr:nth-child(even){background:#f8fbf8;}
table.history-table tbody tr:hover:not(.just-saved),
table.success-table tbody tr:hover,
.data-table tbody tr:hover{background:#e8f5e9;}
.table-card-head{background:#fbfdfb;border-bottom:1px dashed var(--gv-line);padding:16px 22px;}
.record-count{border-radius:6px;font-size:12px;}
.badge-barangay,.badge-health,.badge-pregnancy,.badge-death,.status-badge,.badge{font-size:11.5px;}
.remarks-cell,.cause-cell,.sub-text{font-size:12.5px;}
.empty-state,td.empty-row{font-size:14px;}

/* ---------- modals ---------- */
.modal-backdrop.show{opacity:.55;background:#0a1f3d;}
.modal-content{
border:1px solid #dce5de;border-radius:12px;overflow:hidden;
box-shadow:0 30px 70px -20px rgba(6,20,10,.55);
}
.crud-modal .modal-content{border:1px solid #dce5de;border-radius:12px;box-shadow:0 30px 70px -20px rgba(6,20,10,.55);overflow:hidden;}
.modal-header,.crud-modal .modal-header{
padding:18px 24px;border:0;border-radius:0;color:#fff;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
border-bottom:4px solid transparent;
border-image:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%) 1;
}
.modal-title,.crud-modal .modal-title{
display:flex;align-items:center;gap:12px;font-family:'Space Grotesk',sans-serif;
font-size:17px;font-weight:700;letter-spacing:.3px;
}
.modal-title i{
width:38px;height:38px;border-radius:50%;background:#fff;color:#1b5e20;flex:none;
display:inline-flex;align-items:center;justify-content:center;font-size:15px;
box-shadow:0 0 0 2px rgba(255,255,255,.35);
}
.modal-header .btn-close{opacity:.9;background-color:rgba(255,255,255,.14);border-radius:8px;padding:10px;margin:0;}
.modal-header .btn-close:hover{opacity:1;background-color:rgba(255,255,255,.28);}
.modal-body{padding:24px;}
.modal-footer,.crud-modal .modal-footer{padding:16px 24px;gap:10px;background:#f7faf7;border-top:1px dashed #cfd9d1 !important;}
.modal-body label,.form-label,.crud-modal label{
font-family:'Space Grotesk',sans-serif;font-size:11.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;
color:#123c16;margin-bottom:7px;
}
.form-control,.form-select,.crud-modal .form-control,.crud-modal .form-select{
font-size:13.5px;padding:10px 13px;border:1.5px solid #d3ddd5;border-radius:8px;min-height:42px;
transition:border-color .2s ease,box-shadow .2s ease;
}
.form-control:focus,.form-select:focus,.crud-modal .form-control:focus,.crud-modal .form-select:focus{
border-color:#2e7d32;box-shadow:0 0 0 3px rgba(46,125,50,.15);
}
.form-control[readonly]{background:#f3f6f4;color:#4f5d53;}
.crud-modal .who,.info-box{background:#f7faf7;border:1px solid #dce5de;border-left:5px solid var(--gov-navy);border-radius:10px;color:#123c16;font-size:13.5px;}
.info-box-title{font-size:12.5px;}
.info-box-text{font-size:12.5px;}
.selected-item{font-size:13px;}

/* ---------- footer ---------- */
.gov-footer{display:block;margin-top:auto;padding-top:30px;}
.gov-footer .inner{border-top:1px solid var(--gv-line);padding-top:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:12.5px;color:var(--gv-soft);}

/* ---------- responsive ---------- */
@media(max-width:1000px){
.stat-grid{grid-template-columns:repeat(2,1fr);}
.stat-grid .stat-card.wide,.stat-grid .stat-card.narrow{grid-column:auto;}
}
@media(max-width:900px){
.sidebar{width:215px;}
.main-content{margin-left:215px;padding:calc(var(--top-offset) + 20px) 20px 18px;}
}
@media(max-width:768px){
:root{--strip-h:30px;--header-h:62px;}
body{flex-direction:column;}
.gov-strip{padding:0 14px;font-size:11px;}
.gov-strip .right{display:none;}
header{padding:0 14px;}
header .brand .titles h1{font-size:15px;letter-spacing:.8px;}
header .brand .titles small{display:block;font-size:10px;}
.seal{width:38px;height:38px;}
header .brand .titles{padding-left:10px;}
header a.logout{padding:8px 14px;font-size:12.5px;}
.sidebar{position:static;width:100%;height:auto;margin-top:var(--top-offset);padding:8px;display:flex;flex-wrap:wrap;gap:3px;}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
.sidebar a span{display:inline !important;}
.sidebar a:hover{transform:none;}
.main-content{margin-left:0;width:100%;padding:18px 15px;min-height:auto;}
.page-head,.profile-card{padding:18px;margin-bottom:20px;}
.page-head h2,.profile-info h2{font-size:19px;}
.role-pill{margin-left:0;}
.modal-header,.crud-modal .modal-header{padding:14px 16px;}
.modal-title{font-size:15px;}
.modal-body{padding:16px;}
.modal-footer{padding:12px 16px;}
}
@media(max-width:520px){
.stat-grid{grid-template-columns:1fr;}
.modal-footer .btn-save,.modal-footer .btn-cancel,.modal-footer .btn-danger-solid,.modal-footer .btn-light{flex:1;}
}
}
@media print{
.gov-strip,.gov-footer,.role-pill,.page-date{display:none !important;}
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
            <small>Farmer Success Rate &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../tech_logout.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../technician_dashboard.php">
        <i class="fas fa-home"></i>
        Dashboard
    </a>
    <a href="assign_beneficiaries.php">
        <i class="fas fa-user-check"></i>
        Assign Beneficiaries
    </a>
    <a href="monitoring.php">
        <i class="fas fa-stethoscope"></i>
        Monitoring
    </a>
    <a href="monitoring_history.php">
        <i class="fas fa-history"></i>
        Monitoring History
    </a>
    <a href="farmer_success_rate.php" class="active">
        <i class="fas fa-chart-pie"></i>
        Farmer Success Rate
    </a>
    <a href="mortality_cases.php">
        <i class="fas fa-skull-crossbones"></i>
        Mortality Cases
    </a>
    <a href="livestock_return.php">
        <i class="fas fa-undo"></i>
        Livestock Return
    </a>
    <a href="tech_reports.php">
        <i class="fas fa-file-alt"></i>
        Reports
    </a>
    <a href="generate_report.php">
        <i class="fas fa-file-pdf"></i>
        Generate Report
    </a>
</div>

<div class="main-content">

    <div class="profile-card">
        <div class="avatar-badge"><?=htmlspecialchars($initials)?></div>
        <div class="profile-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?=date('l, F j, Y')?></div>
            <h2>Farmer Success Rate</h2>
            <p>Livestock performance for the beneficiaries assigned to you.</p>
        </div>
        <span class="role-pill"><i class="fas fa-user-check"></i>&nbsp;Technician</span>
    </div>

    <div class="toolbar">
        <p class="section-label">Overview &middot; <?= htmlspecialchars($periodLabel) ?></p>
        <form method="get" class="filter-bar">
            <label for="yearFilter"><i class="fas fa-calendar-days"></i>&nbsp;Year</label>
            <select name="year" id="yearFilter" onchange="this.form.submit()">
                <option value="0">All Years</option>
                <?php foreach ($yearOptions as $y): ?>
                    <option value="<?= $y ?>" <?= $y === $selYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="stat-grid">
        <div class="stat-card total wide">
            <div class="stat-icon"><i class="fas fa-boxes-stacked"></i></div>
            <div class="stat-body">
                <h3>Total Distribution</h3>
                <div class="value"><?= number_format($totalDistribution) ?></div>
                <p>To your beneficiaries &middot; <?= htmlspecialchars($periodLabel) ?></p>
            </div>
        </div>
        <div class="stat-card success wide">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-body">
                <h3>Success Rate</h3>
                <div class="value"><?= number_format($successRate, 1) ?>%</div>
                <p>Active + Returned: <?= number_format($successCount) ?> of <?= number_format($totalDistribution) ?> (not lost to death)</p>
            </div>
        </div>
        <div class="stat-card active narrow">
            <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
            <div class="stat-body">
                <h3>Active</h3>
                <div class="value"><?= number_format($activeCount) ?></div>
                <p><?= number_format($activeRate, 1) ?>% of total distribution</p>
            </div>
        </div>
        <div class="stat-card returned narrow">
            <div class="stat-icon"><i class="fas fa-rotate-left"></i></div>
            <div class="stat-body">
                <h3>Returned</h3>
                <div class="value"><?= number_format($returnedCount) ?></div>
                <p><?= number_format($returnedRate, 1) ?>% of total distribution</p>
            </div>
        </div>
        <div class="stat-card death narrow">
            <div class="stat-icon"><i class="fas fa-skull"></i></div>
            <div class="stat-body">
                <h3>Death</h3>
                <div class="value"><?= number_format($deathCount) ?></div>
                <p><?= number_format($deathRate, 1) ?>% of total distribution</p>
            </div>
        </div>
    </div>

    <p class="section-label">Beneficiary Livestock Performance</p>

    <div class="table-card">
        <?php if(count($rows)===0): ?>
            <div class="empty-state">
                <i class="fas fa-piggy-bank"></i>
                <p>No livestock records found for your assigned beneficiaries<?= $selYear ? ' in ' . $selYear : ' yet' ?>.</p>
            </div>
        <?php else: ?>
        <table class="success-table">
            <thead>
                <tr>
                    <th>Beneficiary</th>
                    <th>Type</th>
                    <th>Breed</th>
                    <th>Status</th>
                    <th>Total Offspring</th>
                    <th>Offspring Alive</th>
                    <th>Offspring Dead</th>
                    <th>Success Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rows as $r): ?>
                <tr>
                    <td>
                        <div class="farmer-cell">
                            <?= pg_farmer_avatar($r['farmer_photo'] ?? null, (string)($r['beneficiary_name'] ?? '')) ?>
                            <div><?=htmlspecialchars($r['beneficiary_name']??'—')?></div>
                        </div>
                    </td>
                    <td><?=htmlspecialchars($r['livestock_type']??'—')?></td>
                    <td><?=htmlspecialchars($r['breed']??'—')?></td>
                    <td><?=distStatusBadge($r['status']??'')?></td>
                    <td><strong><?= (int)($r['offspring_count'] ?? 0) ?></strong></td>
                    <td><span style="color:#1e7e34;font-weight:600;"><?= (int)($r['offspring_alive'] ?? 0) ?></span></td>
                    <td><span style="color:#b3262e;font-weight:600;"><?= (int)($r['offspring_dead'] ?? 0) ?></span></td>
                    <td title="Health <?= $r['breakdown']['health'] ?>/30 + Offspring <?= $r['breakdown']['offspring'] ?>/30 + Returned <?= $r['breakdown']['returned'] ?>/40">
                        <div class="rate-cell">
                            <div class="rate-bar"><div class="rate-bar-fill" style="width:<?= round($r['rate'] ?? 0, 1) ?>%;"></div></div>
                            <span class="rate-value"><?= number_format($r['rate'] ?? 0, 1) ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if(count($rows) > 0): $avgRate = array_sum(array_column($rows, 'rate')) / count($rows); ?>
            <tfoot>
                <tr>
                    <td colspan="7" style="text-align:right;font-weight:600;color:var(--pg-ink-soft);">Average Success Rate</td>
                    <td>
                        <div class="rate-cell">
                            <div class="rate-bar"><div class="rate-bar-fill" style="width:<?= round($avgRate, 1) ?>%;"></div></div>
                            <span class="rate-value"><?= number_format($avgRate, 1) ?>%</span>
                        </div>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        <p style="font-size:12.5px;color:var(--pg-ink-soft);margin:12px 4px 0;">
            <strong>How Success Rate is computed:</strong> Health (30%: Healthy 30 / Sick 15 / Critical 5)
            + Offspring alive vs. quantity (30%; pregnant = 10) + Returned (40%). Dead livestock = 0%.
            100% only when healthy, with enough living offspring, and already returned.
        </p>
        <?php endif; ?>
    </div>

<div class="gov-footer">
    <div class="inner">
        <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
        <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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