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


// Redirect if not logged in
if(!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

/*
 * Based on pigo.sql:
 *   beneficiaries(beneficiary_id, firstname, middlename, lastname, extension_name, ...)
 *   distributions(distribution_id, beneficiary_id, livestock_id, quantity, status, ...)
 *   livestock(livestock_id, kind, breed, sex, status, ...)
 *   monitoring(monitoring_id, distribution_id, health_status, offspring_count, date_recorded, ...)
 *
 * A beneficiary's livestock health/offspring info lives in `monitoring`, keyed by
 * distribution_id, with one row per visit. We take the LATEST monitoring row
 * (highest monitoring_id) per distribution as the current status.
 *
 * Success Rate (%) = milestone score: health 30 + offspring alive 30 + returned 40 (see pg_success_rate)
 * If no monitoring record exists yet for a distribution, health status defaults
 * to 'Healthy' (matching the monitoring table's own default) and offspring to 0.
 *
 * Summary cards (Total Distribution, Success Rate, Active, Returned, Death) use the SQL views:
 *   q_atotal_distribution(year, total_distribution)
 *   q_success_rate_year(year, status[active|returned|death], total)
 * Year filter (?year=YYYY) applies to the cards and the table. 0 / empty = All Years.
 * The dropdown lists the last 10 years (even with no data) plus any year found in the views.
 * Success = distributions that did NOT end in death (active + returned).
 */

$admin_id = $_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT * FROM admins WHERE admin_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

$emailLocalPart = explode('@', $admin['email'] ?? 'admin')[0];
$emailPieces = preg_split('/[._\-]+/', $emailLocalPart);
$initials = '';
foreach(array_slice($emailPieces, 0, 2) as $p){ if($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1)); }
if($initials === ''){ $initials = 'A'; }

// ---------- YEAR FILTER + SUMMARY (from views) ----------
$yearTotals = [];   // year => total_distribution  (q_atotal_distribution)
$res = $conn->query("SELECT `year`, total_distribution FROM q_atotal_distribution ORDER BY `year` DESC");
if ($res) {
    while ($r = $res->fetch_assoc()) { $yearTotals[(int)$r['year']] = (int)$r['total_distribution']; }
}

// Year dropdown: current year back 10 years (past years show even with no data),
// plus any year that exists in the data. Change 9 to show more/fewer past years.
$currentYear = (int)date('Y');
$yearOptions = range($currentYear, $currentYear - 9);
foreach (array_keys($yearTotals) as $y) { if (!in_array($y, $yearOptions, true)) { $yearOptions[] = $y; } }
rsort($yearOptions);

$selYear = (isset($_GET['year']) && ctype_digit($_GET['year'])) ? (int)$_GET['year'] : 0;
if ($selYear && !in_array($selYear, $yearOptions, true)) { $selYear = 0; }   // unknown year -> All Years

$totalDistribution = $selYear ? ($yearTotals[$selYear] ?? 0) : array_sum($yearTotals);

// status counts (q_success_rate_year) - only active / returned / death
$countByStatus = ['active' => 0, 'returned' => 0, 'death' => 0];
$sqlStatus = "SELECT s.status, SUM(s.total) AS total
              FROM q_success_rate_year s"
           . ($selYear ? " WHERE s.`year` = " . (int)$selYear : "")
           . " GROUP BY s.status";
$res = $conn->query($sqlStatus);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if (isset($countByStatus[$r['status']])) { $countByStatus[$r['status']] = (int)$r['total']; }
    }
}

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

// Fetch beneficiary + livestock + latest monitoring records (filtered by year)
$yearWhere = $selYear ? "WHERE YEAR(d.distribution_date) = " . (int)$selYear : "";
$rows = [];
$sql = "SELECT
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
            m.pregnancy_status,
            m.date_recorded,
            m.monitoring_id
        FROM distributions d
        JOIN beneficiaries b ON b.beneficiary_id = d.beneficiary_id
        JOIN livestock l ON l.livestock_id = d.livestock_id
        LEFT JOIN (
            SELECT m1.distribution_id, m1.health_status, m1.offspring_count,
                   m1.offspring_alive, m1.offspring_dead, m1.pregnancy_status,
                   m1.date_recorded, m1.monitoring_id
            FROM monitoring m1
            INNER JOIN (
                SELECT distribution_id, MAX(monitoring_id) AS latest_id
                FROM monitoring
                GROUP BY distribution_id
            ) latest ON latest.latest_id = m1.monitoring_id
        ) m ON m.distribution_id = d.distribution_id
        $yearWhere
        ORDER BY m.date_recorded DESC, m.monitoring_id DESC, d.distribution_id DESC";

/* =========================================================
   SUCCESS RATE PER DISTRIBUTION (milestone based, max 100%)

     Health      30 pts  latest monitoring: Healthy 30 / Sick 15 / Critical 5
     Offspring   30 pts  alive offspring vs. quantity
                         (alive >= quantity = 30; pregnant, not yet born = 10)
     Returned    40 pts  distribution status = 'returned'

   Dead livestock = 0%. 100% only when healthy, with enough living
   offspring AND already returned.
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

    if ($alive > 0)               $o = min(1, $alive / $qty) * 30;
    elseif ($preg === 'pregnant') $o = 10;
    else                          $o = 0;

    $r = ($status === 'returned') ? 40 : 0;

    $breakdown = ['health' => $h, 'offspring' => round($o, 1), 'returned' => $r];
    return min(100, $h + $o + $r);
}

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['rate'] = pg_success_rate($row, $row['breakdown']);
        $rows[] = $row;
    }
}

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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
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
  --pg-amber:#c98a1f;
  --pg-sky:#3f7cac;
  --pg-clay:#c1543f;
  --pg-plum:#7c5cbf;
  --shadow-soft:0 1px 2px rgba(18,60,22,0.04), 0 8px 24px -12px rgba(18,60,22,0.16);
  --shadow-lift:0 14px 30px -14px rgba(18,60,22,0.30);
}

* { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }

body {
    min-height: 100vh;
    display: flex;
    background: var(--pg-canvas);
    background-image:
        radial-gradient(1100px 480px at 100% -10%, rgba(46,125,50,0.07), transparent 60%),
        radial-gradient(700px 380px at -5% 100%, rgba(27,94,32,0.05), transparent 55%);
    color: var(--pg-ink);
}

header {
    position: fixed; top: 0; left: 0; width: 100%; height: 65px;
    background: linear-gradient(120deg, var(--pg-forest-900), var(--pg-forest-800) 55%, var(--pg-forest-600));
    color: #fff; display: flex; justify-content: space-between; align-items: center;
    padding: 0 30px; z-index: 1001; box-shadow: 0 2px 14px rgba(10,40,12,0.25);
}
header .brand{display:flex;align-items:center;gap:12px;}
header .brand .mark{
    width:34px;height:34px;border-radius:9px; background:rgba(255,255,255,0.14);
    display:flex;align-items:center;justify-content:center; font-size:15px;color:#eafbea;
    border:1px solid rgba(255,255,255,0.22);
}
header h1 { font-size: 17px; font-weight: 600; letter-spacing:.2px; }
header .brand small{display:block;font-size:11px;font-weight:400;color:rgba(255,255,255,0.65);letter-spacing:.4px;text-transform:uppercase;}
header a.logout {
    color: var(--pg-forest-800); background: #fff; padding: 8px 20px; border-radius: 20px;
    font-weight: 600; text-decoration: none; font-size: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); transition: 0.2s ease;
}
header a.logout:hover { background: var(--pg-forest-100); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.16); }

.sidebar {
    position: fixed; top: 65px; left: 0; width: 240px; height: calc(100% - 65px);
    background: linear-gradient(195deg, var(--pg-forest-800), var(--pg-forest-600) 120%);
    padding-top: 22px; box-shadow: inset -1px 0 0 rgba(255,255,255,0.06);
}
.sidebar a {
    display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.88);
    padding: 13px 22px; text-decoration: none; margin: 2px 12px 4px; border-radius: 9px;
    border-left: 3px solid transparent; font-size: 14.5px; font-weight: 500; transition: 0.2s ease; position: relative;
}
.sidebar a i{width:18px;text-align:center;font-size:15px;opacity:.9;}
.sidebar a:hover { background: rgba(255,255,255,0.12); transform: translateX(2px); }
.sidebar a.active {
    background: var(--pg-forest-300); color: var(--pg-forest-900); border-left: 3px solid var(--pg-forest-900);
    font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.18);
}
.sidebar a.active i{opacity:1;}

.main-content { margin-left: 240px; padding: 100px 38px 40px; flex: 1; width: auto; }

.profile-card{
    background:#fff;border-radius:16px;padding:26px 30px;
    box-shadow:var(--shadow-soft);border:1px solid var(--pg-line);
    display:flex;align-items:center;gap:22px;flex-wrap:wrap;margin-bottom:28px;
    position:relative;overflow:hidden;
}
.profile-card::before{
    content:"";position:absolute;top:0;left:0;height:5px;width:100%;
    background:linear-gradient(90deg, var(--pg-forest-800), var(--pg-forest-300));
}
.avatar-badge{
    width:60px;height:60px;border-radius:16px;flex:none;
    background:linear-gradient(145deg, var(--pg-forest-600), var(--pg-forest-900));
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:20px;
    letter-spacing:.5px; box-shadow:0 8px 18px -6px rgba(18,60,22,0.5);
}
.profile-info h2{ font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:21px; margin:0 0 4px;color:var(--pg-forest-900); }
.profile-info p{ font-size:13.5px;color:var(--pg-ink-soft);margin:0; }
.role-pill{
    margin-left:auto;padding:6px 14px;border-radius:999px;font-size:12.5px;font-weight:600;
    letter-spacing:.3px;background:var(--pg-forest-100);color:var(--pg-forest-800);
    display:inline-flex;align-items:center;gap:7px;
}
.role-pill::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;}

.section-label{
    font-family:'Space Grotesk',sans-serif;font-size:13px;font-weight:600;
    letter-spacing:.6px;text-transform:uppercase;color:var(--pg-ink-soft);
    margin:0 0 14px 2px;
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
.stat-card.total{    --accent:var(--pg-plum);       --accent-soft:#efe9fa; }
.stat-card.success{  --accent:var(--pg-forest-800); --accent-soft:var(--pg-forest-100); }
.stat-card.active{   --accent:#43a047;              --accent-soft:#e8f5e9; }
.stat-card.returned{ --accent:var(--pg-sky);        --accent-soft:#e6f0f8; }
.stat-card.death{    --accent:var(--pg-clay);       --accent-soft:#fbeae7; }

/* TABLE CARD */
.table-card{
    background:#fff;border:1px solid var(--pg-line);border-radius:16px;
    box-shadow:var(--shadow-soft);overflow:hidden;
}
table.success-table{ width:100%; border-collapse:collapse; }
table.success-table thead th{
    background:var(--pg-forest-100); color:var(--pg-forest-900);
    font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px;
    text-align:left; padding:14px 18px; border-bottom:1px solid var(--pg-line);
}
table.success-table tbody td{
    padding:14px 18px; font-size:14px; color:var(--pg-ink); border-bottom:1px solid var(--pg-line);
}
table.success-table tbody tr:last-child td{ border-bottom:none; }
table.success-table tbody tr:hover{ background:var(--pg-canvas); }

.status-badge{
    display:inline-flex; align-items:center; padding:5px 12px; border-radius:999px;
    font-size:12.5px; font-weight:600;
}

.rate-cell{ display:flex; align-items:center; gap:10px; min-width:140px; }
.rate-bar{ flex:1; height:8px; border-radius:6px; background:var(--pg-line); overflow:hidden; }
.rate-bar-fill{ height:100%; border-radius:6px; background:linear-gradient(90deg, var(--pg-forest-300), var(--pg-forest-600)); }
.rate-value{ font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:13.5px; color:var(--pg-forest-900); min-width:44px; text-align:right; }

.empty-state{ padding:50px 20px; text-align:center; color:var(--pg-ink-soft); }
.empty-state i{ font-size:34px; margin-bottom:12px; color:var(--pg-forest-300); }

@media(max-width:900px) { .stat-grid{ grid-template-columns:repeat(2, 1fr); } .stat-card.wide, .stat-card.narrow{ grid-column:auto; } .sidebar{ width:200px; } .main-content{ margin-left:200px; padding:98px 24px 30px; } }
@media(max-width:560px) { .stat-grid{ grid-template-columns:1fr; } }
@media(max-width:768px) {
    header{ padding:0 16px; }
    header h1{ font-size:15px; }

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
    .sidebar a{ margin:2px; padding:9px 12px; font-size:11.5px; }
    .sidebar a:hover{ transform:none; }

    .main-content { margin-left: 0; padding: 20px 16px 30px; }
    .role-pill{margin-left:0;}
    .table-card{ overflow-x:auto; }
    table.success-table{ min-width:720px; }
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

/* --- farmer-success-rate specifics --- */
@media screen{
.profile-card{
background:#fff;border:1px solid var(--gv-line);border-left:6px solid #1b5e20;border-radius:12px;
padding:22px 26px;gap:18px;box-shadow:var(--gv-shadow);margin-bottom:26px;position:relative;overflow:hidden;
}
.profile-card::before{display:none;}
.profile-card::after{
content:"\f200";font-family:"Font Awesome 6 Free";font-weight:900;position:absolute;right:-8px;bottom:-30px;
font-size:120px;color:#e8f5e9;z-index:0;pointer-events:none;
}
.profile-card > *{position:relative;z-index:1;}
.avatar-badge{width:60px;height:60px;border-radius:50%;box-shadow:0 0 0 4px #e8f5e9;background:linear-gradient(145deg,#2e7d32,#123c16);}
.profile-info h2{font-size:23px;margin:0 0 3px;color:#123c16;}
.profile-info p{font-size:14px;color:var(--gv-soft);max-width:680px;}
.role-pill::before{display:none;}

.section-label{margin-left:0;color:#123c16;}
.toolbar .section-label{margin:0;}
.filter-bar{border-radius:8px;border:1px solid var(--gv-line);border-left:5px solid var(--gov-gold);box-shadow:var(--gv-shadow);}
.filter-bar label{color:var(--gv-soft);}
.filter-bar select{color:#123c16;}

.stat-grid{gap:18px;margin-bottom:26px;}
.stat-card{border-top:5px solid var(--accent);border-radius:12px;padding:22px 24px;gap:16px;}
.stat-card::before{display:none;}
.stat-card.total{--accent:var(--gov-navy);--accent-soft:#e6eef8;}
.stat-card.death{--accent:var(--gov-red);--accent-soft:#fbeae7;}
.stat-icon{border-radius:12px;}
.stat-body .value{color:var(--accent);font-size:34px;}
.stat-body h3{font-size:12.5px;letter-spacing:.6px;}

.table-card{border-radius:12px;}
table.success-table thead th{background:#123c16;color:#fff;font-size:12px;letter-spacing:.6px;padding:13px 16px;border-bottom:none;}
table.success-table tbody td{padding:12px 16px;}
table.success-table tbody tr:nth-child(even){background:#f8fbf8;}
table.success-table tbody tr:hover{background:#e8f5e9;}
.status-badge{border-radius:6px;}
.rate-bar-fill{background:linear-gradient(90deg,#c9a227,#2e7d32);}
}
@media screen and (max-width:900px){
.stat-grid{grid-template-columns:repeat(2,1fr);}
.stat-card.wide,.stat-card.narrow{grid-column:auto;}
}
@media screen and (max-width:768px){
.profile-card{padding:18px;margin-bottom:20px;}
.profile-info h2{font-size:19px;}
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
            <small>Farmer Success Rate &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../adminlog_out.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="./add-technician.php"><i class="fas fa-user-cog"></i> Technician</a>
    <a href="./farmer-beneficiary.php"><i class="fas fa-id-card"></i> Farmer Beneficiary</a>
    <a href="./add-livestock.php"><i class="fas fa-drumstick-bite"></i>Livestock</a>
    <a href="./tracking.php"><i class="fas fa-eye"></i> Livestock Tracking</a>
    <a href="./dispersal.php"><i class="fas fa-people-carry-box"></i> Dispersal</a>
    <a href="./farmer-success-rate.php" class="active"><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="./reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="./generate-report.php"><i class="fas fa-file-alt"></i> Generate Report</a>
</div>

<div class="main-content">

    <div class="profile-card">
        <div class="avatar-badge"><?= htmlspecialchars($initials) ?></div>
        <div class="profile-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Farmer Success Rate</h2>
            <p>Track how each beneficiary's livestock is performing — quantity, health status, offspring, and overall success rate.</p>
        </div>
        <span class="role-pill"><i class="fas fa-shield-halved"></i> Administrator</span>
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
                <p>Livestock distributed &middot; <?= htmlspecialchars($periodLabel) ?></p>
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
        <?php if (count($rows) === 0): ?>
            <div class="empty-state">
                <i class="fas fa-piggy-bank"></i>
                <p>No livestock records found<?= $selYear ? ' for ' . $selYear : ' yet' ?>.</p>
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
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <div class="farmer-cell">
                            <?= pg_farmer_avatar($r['farmer_photo'] ?? null, (string)($r['beneficiary_name'] ?? '')) ?>
                            <div><?= htmlspecialchars($r['beneficiary_name'] ?? '—') ?></div>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($r['livestock_type'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($r['breed'] ?? '—') ?></td>
                    <td><?= distStatusBadge($r['status'] ?? '') ?></td>
                    <td><strong><?= (int)($r['offspring_count'] ?? 0) ?></strong></td>
                    <td><span style="color:#1e7e34;font-weight:600;"><?= (int)($r['offspring_alive'] ?? 0) ?></span></td>
                    <td><span style="color:#b3262e;font-weight:600;"><?= (int)($r['offspring_dead'] ?? 0) ?></span></td>
                    <td title="Health <?= $r['breakdown']['health'] ?>/30 + Offspring <?= $r['breakdown']['offspring'] ?>/30 + Returned <?= $r['breakdown']['returned'] ?>/40">
                        <div class="rate-cell">
                            <div class="rate-bar"><div class="rate-bar-fill" style="width:<?= number_format($r['rate'], 1) ?>%;"></div></div>
                            <span class="rate-value"><?= number_format($r['rate'], 1) ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
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