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
$search=trim($_GET['search']??'');
$barangay_id=(int)($_GET['barangay_id']??0);

$flashError=$_SESSION['error']??null;
$flashMessage=$_SESSION['message']??null;
unset($_SESSION['error'],$_SESSION['message']);

/* =========================================================
   BARANGAYS ASSIGNED TO THIS TECHNICIAN (for filter chips)
========================================================= */

$barangayStmt=$conn->prepare("
    SELECT br.barangay_id, br.barangay_name
    FROM technician_barangay tb
    JOIN barangays br ON br.barangay_id=tb.barangay_id
    WHERE tb.technician_id=?
    ORDER BY br.barangay_name
");
$barangayStmt->bind_param("i",$tech_id);
$barangayStmt->execute();
$barangayResult=$barangayStmt->get_result();

$myBarangays=[];
while($row=$barangayResult->fetch_assoc()){
    $myBarangays[]=$row;
}
$barangayStmt->close();

$sql="
SELECT
    b.*,
    br.barangay_name,
    d.distribution_id,
    l.livestock_id,
    l.batch_no,
    l.kind,
    l.breed,
    l.sex,
    l.birth_date,
    TIMESTAMPDIFF(YEAR,l.birth_date,CURDATE()) AS age
FROM beneficiaries b
JOIN barangays br ON b.barangay_id=br.barangay_id
LEFT JOIN distributions d
    ON d.beneficiary_id=b.beneficiary_id
    AND d.status='active'
LEFT JOIN livestock l ON d.livestock_id=l.livestock_id
WHERE b.barangay_id IN(
    SELECT barangay_id
    FROM technician_barangay
    WHERE technician_id=?
)
AND(
    b.firstname LIKE ?
    OR b.lastname LIKE ?
    OR br.barangay_name LIKE ?
    OR ?=''
)
AND(
    b.barangay_id=?
    OR ?=0
)
ORDER BY br.barangay_name,b.lastname
";

$stmt=$conn->prepare($sql);

if(!$stmt){
    die("Database error: ".$conn->error);
}

$like="%".$search."%";

$stmt->bind_param(
    "issssii",
    $tech_id,
    $like,
    $like,
    $like,
    $search,
    $barangay_id,
    $barangay_id
);

$stmt->execute();
$result=$stmt->get_result();

$beneficiaries=[];

while($row=$result->fetch_assoc()){
    $beneficiaries[]=$row;
}

$stmt->close();

/* =========================================================
   SCHEDULE INFO (latest monitoring_history record per
   distribution, plus a computed pregnancy/visit schedule)
========================================================= */

// Typical gestation length per kind, in days — used only to
// estimate an expected birth date / next check-up date.
function gestationDays($kind) {
    $map = [
        'goat'    => 150,
        'pig'     => 114,
        'carabao' => 310,
    ];
    $key = strtolower(trim($kind ?? ''));
    return $map[$key] ?? 150;
}

$distIds = [];
foreach($beneficiaries as $b) {
    if(!empty($b['distribution_id'])) {
        $distIds[] = (int)$b['distribution_id'];
    }
}
$distIds = array_values(array_unique($distIds));

$latestMonitoring = []; // distribution_id => monitoring_history row

if(count($distIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($distIds), '?'));
    $types = str_repeat('i', count($distIds));

    // "Latest per distribution_id" without assuming the PK column
    // name: a row qualifies if no later-dated row exists for the
    // same distribution.
    $monSql = "
        SELECT mh1.*
        FROM monitoring mh1
        WHERE mh1.distribution_id IN ($placeholders)
        AND NOT EXISTS (
            SELECT 1 FROM monitoring mh2
            WHERE mh2.distribution_id = mh1.distribution_id
              AND (
                  mh2.date_recorded > mh1.date_recorded
                  OR (mh2.date_recorded = mh1.date_recorded AND mh2.monitoring_id > mh1.monitoring_id)
              )
        )
    ";

    $monStmt = $conn->prepare($monSql);
    if($monStmt) {
        $monStmt->bind_param($types, ...$distIds);
        $monStmt->execute();
        $monResult = $monStmt->get_result();
        while($m = $monResult->fetch_assoc()) {
            // if two rows still tie on the same date, keep the last one seen
            $latestMonitoring[(int)$m['distribution_id']] = $m;
        }
        $monStmt->close();
    }
}

// Attach computed schedule fields onto each beneficiary row
foreach($beneficiaries as &$b) {
    $b['sched_has_record'] = false;

    $distId = (int)($b['distribution_id'] ?? 0);
    if($distId === 0 || !isset($latestMonitoring[$distId])) {
        continue;
    }

    $m = $latestMonitoring[$distId];
    $b['sched_has_record']  = true;
    $b['sched_monitor_date'] = $m['date_recorded'] ?? null;
    $b['sched_health']       = $m['health_status'] ?? null;
    $b['sched_pregnancy']    = $m['pregnancy_status'] ?? null;
    $b['sched_offspring']    = $m['offspring_count'] ?? null;
    $b['sched_offspring_alive'] = $m['offspring_alive'] ?? null;
    $b['sched_offspring_dead']  = $m['offspring_dead'] ?? null;
    $b['sched_remarks']      = $m['notes'] ?? null;
    $b['sched_livestock_status'] = $m['action'] ?? null;

    if($b['sched_pregnancy'] === 'pregnant' && !empty($b['sched_monitor_date'])) {
        $gDays = gestationDays($b['kind'] ?? '');
        $expectedBirth = date('Y-m-d', strtotime($b['sched_monitor_date']." +$gDays days"));
        $nextVisit     = date('Y-m-d', strtotime($b['sched_monitor_date']." +30 days"));
        // don't recommend a check-up date past the expected birth date
        if($nextVisit > $expectedBirth) {
            $nextVisit = $expectedBirth;
        }
        $b['sched_expected_birth'] = $expectedBirth;
        $b['sched_next_visit']     = $nextVisit;
    }
}
unset($b);

// A beneficiary can have several active distributions (one row per livestock),
// so count unique beneficiaries instead of rows to match Assign Beneficiaries.
$totalBeneficiaries = count(array_unique(array_column($beneficiaries, 'beneficiary_id')));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PIGOBAO - Monitoring</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root{
    --green-900:#123c16;
    --green-800:#1b5e20;
    --green-700:#2e7d32;
    --green-600:#388e3c;
    --green-300:#a5d6a7;
    --green-100:#e8f5e9;
    --green-50:#f3faf4;
    --yellow:#f4c542;
    --bg:#f4f7f5;
    --white:#fff;
    --text:#243126;
    --muted:#6b776e;
    --border:#e1e8e2;
    --danger:#c62828;
    --danger-bg:#ffebee;
    --success:#2e7d32;
    --success-bg:#e8f5e9;
    --shadow:0 8px 25px rgba(18,60,22,.08);
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Poppins',sans-serif;
}

body{
    background:var(--bg);
    color:var(--text);
    min-height:100vh;
}

/* SITE HEADER (matches technician_dashboard.php) */
.site-header{
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:65px;
    background:linear-gradient(120deg,var(--green-900),var(--green-800) 55%,var(--green-700));
    color:#fff;
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:0 30px;
    z-index:1001;
    box-shadow:0 2px 14px rgba(10,40,12,.25);
}

.brand{
    display:flex;
    align-items:center;
    gap:12px;
}

.brand-mark{
    width:34px;
    height:34px;
    border-radius:9px;
    background:rgba(255,255,255,.14);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#eafbea;
    border:1px solid rgba(255,255,255,.22);
}

.brand h1{
    font-size:17px;
    font-weight:600;
    letter-spacing:.2px;
    margin:0;
}

.brand small{
    display:block;
    font-size:11px;
    color:rgba(255,255,255,.65);
    text-transform:uppercase;
    letter-spacing:.4px;
}

.site-logout{
    color:var(--green-800);
    background:#fff;
    padding:8px 20px;
    border-radius:20px;
    font-weight:600;
    text-decoration:none;
    font-size:14px;
    box-shadow:0 2px 8px rgba(0,0,0,.12);
    transition:.2s ease;
}

.site-logout:hover{
    background:var(--green-100);
    color:var(--green-900);
    transform:translateY(-1px);
}

/* SIDEBAR (matches technician_dashboard.php) */
.sidebar{
    position:fixed;
    top:65px;
    left:0;
    width:230px;
    height:calc(100% - 65px);
    background:linear-gradient(195deg,var(--green-800),var(--green-700) 120%);
    padding-top:22px;
    z-index:1000;
    box-shadow:inset -1px 0 0 rgba(255,255,255,.06);
    overflow-y:auto;
}

.sidebar a{
    display:flex;
    align-items:center;
    gap:12px;
    color:rgba(255,255,255,.88);
    padding:13px 22px;
    text-decoration:none;
    margin:2px 12px 4px;
    border-radius:9px;
    border-left:3px solid transparent;
    font-size:14.5px;
    font-weight:500;
    transition:.2s ease;
}

.sidebar a i{
    width:18px;
    text-align:center;
    font-size:15px;
}

.sidebar a:hover{
    background:rgba(255,255,255,.12);
    transform:translateX(2px);
}

.sidebar a.active{
    background:var(--green-300);
    color:var(--green-900);
    border-left:3px solid var(--green-900);
    font-weight:600;
    box-shadow:0 4px 12px rgba(0,0,0,.18);
}

/* MAIN */
.main{
    margin-left:230px;
    margin-top:65px;
    min-height:calc(100vh - 65px);
}

/* TOPBAR (page title bar, sits just below the fixed site header) */
.topbar{
    height:68px;
    background:#fff;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 30px;
    position:sticky;
    top:0;
    z-index:900;
}

.page-title{
    display:flex;
    align-items:center;
    gap:12px;
}

.page-title-icon{
    width:38px;
    height:38px;
    border-radius:10px;
    background:var(--green-100);
    color:var(--green-800);
    display:flex;
    align-items:center;
    justify-content:center;
}

.page-title h1{
    font-family:'Space Grotesk',sans-serif;
    font-size:19px;
}

.page-title p{
    font-size:10px;
    color:var(--muted);
}

.user-area{
    display:flex;
    align-items:center;
    gap:10px;
}

.user-avatar{
    width:36px;
    height:36px;
    border-radius:50%;
    background:var(--green-700);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:13px;
}

.user-info strong{
    display:block;
    font-size:11px;
}

.user-info span{
    display:block;
    font-size:9px;
    color:var(--muted);
}

/* CONTENT */
.content{
    padding:28px 30px 40px;
}

.intro{
    margin-bottom:22px;
}

.intro h2{
    font-family:'Space Grotesk',sans-serif;
    font-size:24px;
    margin-bottom:5px;
}

.intro p{
    color:var(--muted);
    font-size:12px;
}

/* ALERTS */
.alert{
    padding:12px 15px;
    border-radius:9px;
    margin-bottom:18px;
    display:flex;
    align-items:center;
    gap:10px;
    font-size:12px;
}

.alert-error{
    background:var(--danger-bg);
    color:var(--danger);
    border:1px solid #ffcdd2;
}

.alert-success{
    background:var(--success-bg);
    color:var(--success);
    border:1px solid #c8e6c9;
}

/* SEARCH */
.toolbar{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:15px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:20px;
    box-shadow:var(--shadow);
}

.search-box{
    position:relative;
    flex:1;
    max-width:450px;
}

.search-box i{
    position:absolute;
    left:13px;
    top:50%;
    transform:translateY(-50%);
    color:var(--muted);
    font-size:13px;
}

.search-box input{
    width:100%;
    height:40px;
    border:1px solid var(--border);
    border-radius:8px;
    padding:8px 12px 8px 38px;
    outline:none;
    font-size:12px;
}

.search-box input:focus{
    border-color:var(--green-600);
    box-shadow:0 0 0 3px rgba(46,125,50,.1);
}

.search-btn{
    height:40px;
    padding:0 18px;
    border:0;
    border-radius:8px;
    background:var(--green-800);
    color:#fff;
    cursor:pointer;
    font-size:12px;
    font-weight:600;
}

/* BARANGAY CHIPS */
.chip-row{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-bottom:20px;
}

.chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    height:32px;
    padding:0 14px;
    border-radius:20px;
    border:1px solid var(--border);
    background:#fff;
    color:var(--muted);
    font-size:12px;
    font-weight:500;
    text-decoration:none;
    transition:.15s ease;
}

.chip:hover{
    border-color:var(--green-600);
    color:var(--green-800);
}

.chip.active{
    background:var(--green-800);
    border-color:var(--green-800);
    color:#fff;
}

.chip .chip-count{
    font-size:10px;
    opacity:.75;
}

/* STAT */
.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:16px 18px;
    display:flex;
    align-items:center;
    gap:13px;
    margin-bottom:20px;
    box-shadow:var(--shadow);
    width:220px;
}

.stat-icon{
    width:40px;
    height:40px;
    border-radius:10px;
    background:var(--green-100);
    color:var(--green-800);
    display:flex;
    align-items:center;
    justify-content:center;
}

.stat strong{
    display:block;
    font-size:20px;
    font-family:'Space Grotesk',sans-serif;
}

.stat span{
    color:var(--muted);
    font-size:10px;
}

/* TABLE */
.table-wrap{
    background:#fff;
    border:1px solid var(--border);
    border-radius:13px;
    box-shadow:var(--shadow);
    overflow:hidden;
}

.table-scroll{
    overflow-x:auto;
}

.b-table{
    width:100%;
    border-collapse:collapse;
    min-width:920px;
}

.b-table thead th{
    background:linear-gradient(120deg,var(--green-900),var(--green-800));
    color:#fff;
    text-align:left;
    font-size:10px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.4px;
    padding:13px 16px;
    white-space:nowrap;
}

.b-table tbody td{
    padding:12px 16px;
    font-size:11.5px;
    color:var(--text);
    border-bottom:1px solid var(--border);
    vertical-align:middle;
}

.b-table tbody tr:last-child td{
    border-bottom:0;
}

.b-table tbody tr:hover{
    background:var(--green-50);
}

.beneficiary-name{
    display:flex;
    align-items:center;
    gap:10px;
}

.beneficiary-avatar{
    flex:none;
    width:34px;
    height:34px;
    border-radius:50%;
    background:var(--green-100);
    color:var(--green-800);
    display:flex;
    align-items:center;
    justify-content:center;
}

.beneficiary-name strong{
    display:block;
    font-size:12px;
}

.beneficiary-name span{
    display:block;
    font-size:9.5px;
    color:var(--muted);
    margin-top:1px;
}

.status{
    display:inline-block;
    padding:5px 10px;
    border-radius:20px;
    font-size:9px;
    font-weight:600;
    background:var(--green-100);
    color:var(--green-800);
    white-space:nowrap;
}

.cell-muted{
    color:var(--muted);
    font-size:10.5px;
}

.livestock-cell strong{
    display:block;
    font-size:11.5px;
}

.livestock-cell span{
    display:block;
    color:var(--muted);
    font-size:9.5px;
    margin-top:2px;
}

.no-livestock{
    color:var(--muted);
    font-size:10.5px;
    font-style:italic;
}

.monitor-btn{
    border:0;
    background:var(--green-700);
    color:#fff;
    border-radius:7px;
    padding:8px 13px;
    font-size:10px;
    font-weight:600;
    cursor:pointer;
    white-space:nowrap;
}

.monitor-btn:hover{
    background:var(--green-800);
}

.schedule-btn{
    border:1px solid var(--green-700);
    background:#fff;
    color:var(--green-800);
    border-radius:7px;
    padding:8px 13px;
    font-size:10px;
    font-weight:600;
    cursor:pointer;
    white-space:nowrap;
    margin-top:6px;
}

.schedule-btn:hover{
    background:var(--green-100);
}

.action-cell{
    display:flex;
    flex-direction:column;
    align-items:flex-start;
}

.sched-pill{
    display:inline-block;
    padding:4px 10px;
    border-radius:20px;
    font-size:9.5px;
    font-weight:600;
    white-space:nowrap;
}
.sched-pill.pregnant{background:#fff3cd;color:#8a6100;}
.sched-pill.not_pregnant{background:var(--green-100);color:var(--green-800);}
.sched-pill.gave_birth{background:#e3f2fd;color:#1565c0;}
.sched-pill.none{background:#eee;color:var(--muted);}

.sched-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px 22px;
    margin-top:6px;
}
.sched-item{
    background:var(--green-50);
    border:1px solid var(--border);
    border-radius:10px;
    padding:11px 13px;
}
.sched-item.full{grid-column:1/-1;}
.sched-item .sched-label{
    font-size:9.5px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.4px;
    color:var(--muted);
    margin-bottom:4px;
}
.sched-item .sched-value{
    font-size:13px;
    font-weight:600;
    color:var(--text);
}
.sched-empty{
    text-align:center;
    padding:30px 15px;
    color:var(--muted);
    font-size:12px;
}
.sched-empty i{
    font-size:26px;
    color:#b7c5b9;
    display:block;
    margin-bottom:10px;
}

/* EMPTY */
.empty{
    background:#fff;
    border:1px solid var(--border);
    border-radius:13px;
    padding:55px 20px;
    text-align:center;
    box-shadow:var(--shadow);
}

.empty i{
    font-size:40px;
    color:#b7c5b9;
    margin-bottom:12px;
}

.empty h3{
    font-family:'Space Grotesk',sans-serif;
    font-size:17px;
    margin-bottom:5px;
}

.empty p{
    color:var(--muted);
    font-size:11px;
}

/* MODAL */
.modal{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(12,28,15,.55);
    z-index:2000;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.modal.show{
    display:flex;
}

.modal-box{
    background:#fff;
    width:100%;
    max-width:620px;
    max-height:90vh;
    overflow-y:auto;
    border-radius:14px;
    box-shadow:0 20px 60px rgba(0,0,0,.2);
}

.modal-head{
    background:linear-gradient(120deg,var(--green-900),var(--green-800));
    color:#fff;
    padding:17px 20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.modal-head h3{
    font-family:'Space Grotesk',sans-serif;
    font-size:16px;
}

.close{
    border:0;
    background:transparent;
    color:#fff;
    font-size:20px;
    cursor:pointer;
}

.modal-body{
    padding:20px;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:15px;
}

.form-group{
    margin-bottom:15px;
}

.form-group.full{
    grid-column:1/-1;
}

.form-group label{
    display:block;
    font-size:10px;
    font-weight:600;
    margin-bottom:6px;
}

.form-group input,
.form-group select,
.form-group textarea{
    width:100%;
    border:1px solid var(--border);
    border-radius:7px;
    padding:9px 11px;
    font-size:11px;
    outline:none;
    background:#fff;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
    border-color:var(--green-600);
    box-shadow:0 0 0 3px rgba(46,125,50,.08);
}

.form-group textarea{
    resize:vertical;
    min-height:80px;
}

.modal-footer{
    padding:14px 20px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.btn-cancel{
    border:1px solid var(--border);
    background:#fff;
    color:var(--muted);
    padding:9px 15px;
    border-radius:7px;
    cursor:pointer;
    font-size:10px;
}

.btn-save{
    border:0;
    background:var(--green-800);
    color:#fff;
    padding:9px 17px;
    border-radius:7px;
    cursor:pointer;
    font-size:10px;
    font-weight:600;
}

/* MOBILE */
@media(max-width:900px){
    .sidebar{
        width:200px;
    }

    .main{
        margin-left:200px;
    }
}

@media(max-width:650px){
    .site-header{
        padding:0 15px;
    }

    .brand h1{
        font-size:15px;
    }

    .brand small{
        font-size:9px;
    }

    .site-logout{
        padding:7px 14px;
        font-size:12px;
    }

    .sidebar{
        width:100%;
        height:auto;
        top:65px;
        padding:8px;
        z-index:999;
        display:flex;
        flex-wrap:wrap;
        gap:2px;
    }

    .sidebar a{
        margin:2px;
        padding:9px 10px;
        font-size:10px;
    }

    .main{
        margin-left:0;
        margin-top:140px;
    }

    .topbar{
        padding:0 15px;
    }

    .user-info{
        display:none;
    }

    .content{
        padding:20px 15px 30px;
    }

    .toolbar{
        flex-direction:column;
        align-items:stretch;
    }

    .search-box{
        max-width:none;
    }

    .stat{
        width:100%;
    }

    .b-table{
        min-width:760px;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }
}
#monitorForm .is-invalid{border-color:#c1543f !important;box-shadow:0 0 0 3px rgba(193,84,63,0.15);}

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
   GOVERNMENT PORTAL THEME - buttons + modals
   (matches admin / technician dashboards)
========================================================= */
:root{
    --gov-navy:#0b2f5b;
    --gov-gold:#c9a227;
    --gov-red:#b3262e;
}

/* ---------- BUTTONS ---------- */
.search-btn,
.monitor-btn,
.schedule-btn,
.btn-cancel,
.btn-save{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    font-family:'Poppins',sans-serif;
    letter-spacing:.3px;
    border-radius:8px;
    transition:background .2s ease,transform .2s ease,box-shadow .2s ease,border-color .2s ease,color .2s ease;
}
.search-btn:focus-visible,
.monitor-btn:focus-visible,
.schedule-btn:focus-visible,
.btn-cancel:focus-visible,
.btn-save:focus-visible,
.close:focus-visible{
    outline:2px solid var(--gov-gold);
    outline-offset:2px;
}

.search-btn{
    height:42px;
    padding:0 22px;
    font-size:13px;
    background:linear-gradient(120deg,var(--green-900),var(--green-800));
    box-shadow:0 4px 12px -4px rgba(18,60,22,.5);
}
.search-btn:hover{
    transform:translateY(-1px);
    box-shadow:0 8px 16px -6px rgba(18,60,22,.55);
}

/* primary row action */
.monitor-btn{
    min-width:112px;
    padding:9px 16px;
    font-size:12px;
    background:linear-gradient(120deg,var(--green-900),var(--green-800));
    box-shadow:0 4px 10px -5px rgba(18,60,22,.55);
}
.monitor-btn i{color:var(--gov-gold);}
.monitor-btn:hover{
    background:linear-gradient(120deg,#0d2e10,var(--green-900));
    transform:translateY(-1px);
    box-shadow:0 8px 14px -6px rgba(18,60,22,.6);
}
.monitor-btn:disabled{
    background:#e4e9e5;
    color:#7b877e;
    box-shadow:none;
    transform:none;
}

/* secondary row action */
.schedule-btn{
    min-width:112px;
    padding:8px 16px;
    font-size:12px;
    margin-top:7px;
    border:1.5px solid var(--gov-navy);
    color:var(--gov-navy);
    background:#fff;
}
.schedule-btn i{color:var(--gov-navy);}
.schedule-btn:hover{
    background:var(--gov-navy);
    color:#fff;
    transform:translateY(-1px);
    box-shadow:0 8px 14px -6px rgba(11,47,91,.5);
}
.schedule-btn:hover i{color:var(--gov-gold);}

/* modal footer buttons */
.btn-cancel{
    padding:10px 22px;
    font-size:13px;
    font-weight:600;
    border:1.5px solid #cfd9d1;
    color:#4f5d53;
    background:#fff;
}
.btn-cancel:hover{
    background:#f3f6f4;
    border-color:#9fb0a3;
    color:#233126;
}
.btn-save{
    padding:10px 24px;
    font-size:13px;
    background:linear-gradient(120deg,var(--green-900),var(--green-800) 60%,var(--green-700));
    box-shadow:0 6px 14px -6px rgba(18,60,22,.6);
}
.btn-save i{color:var(--gov-gold);}
.btn-save:hover{
    transform:translateY(-1px);
    box-shadow:0 10px 18px -8px rgba(18,60,22,.65);
}

/* ---------- MODALS ---------- */
.modal{
    background:rgba(10,31,61,.55);
    backdrop-filter:blur(2px);
    -webkit-backdrop-filter:blur(2px);
}
.modal.show .modal-box{animation:govModalIn .22s ease;}
@keyframes govModalIn{
    from{opacity:0;transform:translateY(14px) scale(.985);}
    to{opacity:1;transform:none;}
}

.modal-box{
    max-width:680px;
    border-radius:12px;
    border:1px solid #dce5de;
    box-shadow:0 30px 70px -20px rgba(6,20,10,.55);
}

.modal-head{
    padding:18px 24px;
    background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
    border-bottom:4px solid transparent;
    border-image:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%) 1;
    position:sticky;
    top:0;
    z-index:2;
}
.modal-head h3{
    display:flex;
    align-items:center;
    gap:12px;
    font-size:17px;
    font-weight:700;
    letter-spacing:.3px;
}
.modal-head h3 i{
    width:38px;
    height:38px;
    border-radius:50%;
    background:#fff;
    color:var(--green-800);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
    flex:none;
    box-shadow:0 0 0 2px rgba(255,255,255,.35);
}

.close{
    width:36px;
    height:36px;
    border-radius:8px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.25);
    font-size:22px;
    line-height:1;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    transition:.2s ease;
}
.close:hover{background:#fff;color:var(--green-900);}

.modal-body{
    padding:24px;
    background:#fff;
}

.form-grid{gap:16px 18px;}
.form-group{margin-bottom:0;}
.form-group label{
    font-family:'Space Grotesk',sans-serif;
    font-size:11.5px;
    font-weight:700;
    letter-spacing:.7px;
    text-transform:uppercase;
    color:var(--green-900);
    margin-bottom:7px;
}
.form-group input,
.form-group select,
.form-group textarea{
    font-size:13.5px;
    padding:11px 13px;
    border:1.5px solid #d3ddd5;
    border-radius:8px;
    color:var(--text);
    transition:border-color .2s ease,box-shadow .2s ease;
}
.form-group input[readonly]{
    background:#f3f6f4;
    color:#4f5d53;
    cursor:default;
}
.form-group input[type="file"]{padding:8px 10px;background:#fbfdfb;}
.form-group input[type="file"]::file-selector-button{
    border:0;
    background:var(--green-100);
    color:var(--green-800);
    font-family:'Poppins',sans-serif;
    font-weight:600;
    font-size:12px;
    padding:6px 12px;
    border-radius:6px;
    margin-right:10px;
    cursor:pointer;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
    border-color:var(--green-700);
    box-shadow:0 0 0 3px rgba(46,125,50,.15);
}
.form-group small{font-size:11.5px;display:block;margin-top:5px;}

.modal-footer{
    padding:16px 24px;
    gap:10px;
    background:#f7faf7;
    border-top:1px dashed #cfd9d1;
}

/* schedule modal content */
.sched-grid{gap:14px 18px;margin-top:0;}
.sched-item{
    background:#fff;
    border:1px solid #dce5de;
    border-left:5px solid var(--gov-navy);
    border-radius:10px;
    padding:14px 16px;
}
.sched-item .sched-label{
    font-family:'Space Grotesk',sans-serif;
    font-size:11.5px;
    letter-spacing:.7px;
    color:var(--gov-navy);
}
.sched-item .sched-value{font-size:15px;color:var(--green-900);}
.sched-pill{font-size:11.5px;padding:5px 12px;border-radius:8px;}
.sched-empty{font-size:14px;}
.sched-empty i{color:var(--gov-navy);opacity:.35;font-size:32px;}

@media(max-width:650px){
    .modal{padding:10px;}
    .modal-head{padding:14px 16px;}
    .modal-head h3{font-size:15px;}
    .modal-body{padding:16px;}
    .modal-footer{padding:12px 16px;}
    .btn-cancel,.btn-save{flex:1;}
}

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
header.site-header{
top:var(--strip-h);height:var(--header-h);padding:0 30px;z-index:1002;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
box-shadow:0 4px 16px rgba(10,40,12,.28);
}
.site-header .brand{display:flex;align-items:center;gap:14px;}
.seal{width:46px;height:46px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;box-shadow:0 0 0 2px rgba(255,255,255,.35);}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.seal.fallback{font-size:18px;color:#1b5e20;}
.brand .titles{padding-left:14px;border-left:1px solid rgba(255,255,255,.28);}
.brand .titles h1{font-family:'Merriweather',serif;font-size:19px;font-weight:700;letter-spacing:1.2px;line-height:1.15;margin:0;color:#fff;}
.brand .titles small{display:block;font-size:12px;font-weight:400;color:rgba(255,255,255,.78);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;}
.site-logout{
color:#123c16;background:#fff;padding:9px 22px;border-radius:8px;font-weight:600;font-size:14px;
text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,.14);transition:.2s ease;
}
.site-logout:hover{background:#e8f5e9;transform:translateY(-1px);}

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
.main{
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
.main{margin-left:215px;padding:calc(var(--top-offset) + 20px) 20px 18px;}
}
@media screen and (max-width:768px){
:root{--strip-h:30px;--header-h:62px;}
.gov-strip{padding:0 14px;font-size:11px;}
.gov-strip .right{display:none;}
header.site-header{padding:0 14px;}
.brand .titles h1{font-size:15px;letter-spacing:.8px;}
.brand .titles small{font-size:10px;}
.seal{width:38px;height:38px;}
.brand .titles{padding-left:10px;}
.site-logout{padding:8px 14px;font-size:12.5px;}
.sidebar{position:static;width:100%;height:auto;margin-top:var(--top-offset);padding:8px;display:flex;flex-wrap:wrap;gap:3px;}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
.sidebar a:hover{transform:none;}
.main{margin-left:0;padding:18px 15px;min-height:auto;}
.page-card{padding:18px;margin-bottom:20px;}
.page-info h2,.page-card h2{font-size:19px;}
.role-pill{margin-left:0;}
}
@media print{
.gov-strip,.gov-footer,.role-pill,.page-date{display:none !important;}
}

/* --- monitoring specifics --- */
@media screen{
.main{margin-top:var(--top-offset);padding:0;min-height:calc(100vh - var(--top-offset));}
.content{padding:26px 34px 20px;flex:1;display:flex;flex-direction:column;}
.toolbar{border:1px solid var(--gv-line);border-left:5px solid var(--gov-gold);border-radius:12px;box-shadow:var(--gv-shadow);}
.search-box input{border-radius:8px;}
.stat{border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);}
.stat-icon{background:#e6eef8;color:var(--gov-navy);border-radius:12px;}
.stat strong{color:var(--gov-navy);}
.chip{border-radius:8px;}
.chip.active{background:#123c16;border-color:#123c16;border-bottom:3px solid var(--gov-gold);}
.table-wrap{border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);}
.b-table thead th{background:#123c16;color:#fff;font-size:11px;letter-spacing:.6px;}
.b-table tbody tr:nth-child(even){background:#f8fbf8;}
.b-table tbody tr:hover{background:#e8f5e9;}
.empty{background:#fff;border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);}
}
@media screen and (max-width:900px){
.main{margin-left:215px;padding:0;}
.content{padding:calc(20px) 20px 18px;}
}
@media screen and (max-width:768px){
.main{margin-left:0;margin-top:0;padding:0;min-height:auto;}
.content{padding:18px 15px 20px;}
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

<header class="site-header">
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
    <a href="../tech_logout.php" class="site-logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<aside class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../technician_dashboard.php">
        <i class="fas fa-home"></i>
        Dashboard
    </a>
    <a href="assign_beneficiaries.php">
        <i class="fas fa-user-check"></i>
        Assign Beneficiaries
    </a>
    <a href="monitoring.php" class="active">
        <i class="fas fa-stethoscope"></i>
        Monitoring
    </a>
    <a href="monitoring_history.php">
        <i class="fas fa-history"></i>
        Monitoring History
    </a>

    <a href="farmer_success_rate.php">
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
    <a href="generate_report.php" >
        <i class="fas fa-file-pdf"></i>
         Generate Report</a>
</aside>

<main class="main">

<section class="content">

    <div class="page-card">
        <div class="page-icon"><i class="fas fa-stethoscope"></i></div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Livestock Monitoring</h2>
            <p>Monitor assigned farmer beneficiaries and livestock.</p>
        </div>
        <span class="role-pill"><i class="fas fa-user-gear"></i> <?=htmlspecialchars($_SESSION['name']??'Technician')?> &bull; Technician</span>
    </div>

    <?php if($flashError): ?>
        <div class="alert alert-error">
            <i class="fas fa-circle-exclamation"></i>
            <?=htmlspecialchars($flashError)?>
        </div>
    <?php endif; ?>

    <?php if($flashMessage): ?>
        <div class="alert alert-success">
            <i class="fas fa-circle-check"></i>
            <?=htmlspecialchars($flashMessage)?>
        </div>
    <?php endif; ?>

    <div class="stat">
        <div class="stat-icon">
            <i class="fas fa-users"></i>
        </div>
        <div>
            <strong><?=$totalBeneficiaries?></strong>
            <span>Assigned Beneficiaries</span>
        </div>
    </div>

    <form method="GET" class="toolbar">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input
                type="text"
                name="search"
                value="<?=htmlspecialchars($search)?>"
                placeholder="Search beneficiary or barangay..."
            >
        </div>

        <button type="submit" class="search-btn">
            <i class="fas fa-search"></i> Search
        </button>
    </form>

    <div class="chip-row">

        <a
            href="?search=<?=urlencode($search)?>&barangay_id=0"
            class="chip<?=$barangay_id===0 ? ' active' : ''?>"
        >
            <i class="fas fa-layer-group"></i>
            All Barangays
        </a>

        <?php foreach($myBarangays as $brgy): ?>

            <a
                href="?search=<?=urlencode($search)?>&barangay_id=<?=(int)$brgy['barangay_id']?>"
                class="chip<?=$barangay_id===(int)$brgy['barangay_id'] ? ' active' : ''?>"
            >
                <i class="fas fa-map-marker-alt"></i>
                <?=htmlspecialchars($brgy['barangay_name'])?>
            </a>

        <?php endforeach; ?>

    </div>

    <?php if(empty($beneficiaries)): ?>

        <div class="empty">
            <i class="fas fa-users-slash"></i>
            <h3>No Beneficiaries Found</h3>
            <p>No assigned beneficiaries match your search.</p>
        </div>

    <?php else: ?>

        <div class="table-wrap">
        <div class="table-scroll">
        <table class="b-table">

            <thead>
                <tr>
                    <th>Beneficiary</th>
                    <th>Status</th>
                    <th>Contact</th>
                    <th>Gender</th>
                    <th>Livestock</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach($beneficiaries as $b): ?>
                <?php $b['farmer_photo_url'] = pg_farmer_photo_url($farmerPhotoCol ? ($b[$farmerPhotoCol] ?? null) : null); ?>

                <tr>

                    <td>
                        <div class="beneficiary-name">
                            <?= pg_farmer_avatar(
                                $farmerPhotoCol ? ($b[$farmerPhotoCol] ?? null) : null,
                                trim(($b['firstname']??'').' '.($b['lastname']??''))
                            ) ?>

                            <div>
                                <strong>
                                    <?=htmlspecialchars(
                                        trim(
                                            ($b['firstname']??'').' '.
                                            ($b['middlename']??'').' '.
                                            ($b['lastname']??'')
                                        )
                                    )?>
                                </strong>

                                <span>
                                    <?=htmlspecialchars($b['barangay_name']??'No Barangay')?>
                                </span>
                            </div>
                        </div>
                    </td>

                    <td>
                        <span class="status">
                            <?=htmlspecialchars(ucfirst($b['status']??'Active'))?>
                        </span>
                    </td>

                    <td class="cell-muted">
                        <?=htmlspecialchars($b['contact']??'N/A')?>
                    </td>

                    <td class="cell-muted">
                        <?=htmlspecialchars($b['gender']??'N/A')?>
                    </td>

                    <td>

                        <?php if(!empty($b['livestock_id'])): ?>

                            <div class="livestock-cell">
                                <strong>
                                    <?=htmlspecialchars(ucfirst($b['kind']??'N/A'))?>
                                    <?php if(!empty($b['breed'])): ?>
                                        &ndash; <?=htmlspecialchars($b['breed'])?>
                                    <?php endif; ?>
                                </strong>
                                <span>
                                    Batch <?=htmlspecialchars($b['batch_no']??'N/A')?>
                                    &bull; <?=htmlspecialchars($b['age']??'N/A')?> yr
                                </span>
                            </div>

                        <?php else: ?>

                            <span class="no-livestock">No active livestock</span>

                        <?php endif; ?>

                    </td>

                    <td>

                        <?php if(!empty($b['distribution_id'])): ?>

                            <div class="action-cell">

                                <button
                                    type="button"
                                    class="monitor-btn"
                                    onclick='openMonitoring(<?=json_encode($b,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'
                                >
                                    <i class="fas fa-notes-medical"></i>
                                    Record
                                </button>

                                <button
                                    type="button"
                                    class="schedule-btn"
                                    onclick='openSchedule(<?=json_encode($b,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'
                                >
                                    <i class="fas fa-calendar-check"></i>
                                    Schedule
                                </button>

                            </div>

                        <?php else: ?>

                            <button
                                type="button"
                                class="monitor-btn"
                                disabled
                                style="opacity:.5;cursor:not-allowed;"
                            >
                                N/A
                            </button>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>
        </div>
        </div>

    <?php endif; ?>


    <div class="gov-footer">
        <div class="inner">
            <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
            <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
        </div>
    </div>

</section>
</main>

<!-- MONITORING MODAL -->
<div class="modal" id="monitorModal">

    <div class="modal-box">

        <div class="modal-head">
            <h3>
                <i class="fas fa-notes-medical"></i>
                Record Livestock Monitoring
            </h3>

            <button type="button" class="close" onclick="closeMonitoring()">
                &times;
            </button>
        </div>

        <form id="monitorForm" action="save_monitoring.php" method="POST" enctype="multipart/form-data">

            <div class="modal-body">

                <input type="hidden" name="distribution_id" id="distribution_id">

                <div class="form-grid">

                    <div class="form-group">
                        <label>Beneficiary</label>
                        <div class="modal-farmer">
                            <span class="farmer-avatar" id="modal_farmer_avatar">
                                <span class="fa-init" id="modal_farmer_init"></span>
                                <img id="modal_farmer_img" alt="" style="display:none;">
                            </span>
                            <input type="text" id="beneficiary_name" readonly>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Livestock</label>
                        <input type="text" id="livestock_name" readonly>
                    </div>

                    <div class="form-group">
                        <label>Monitoring Date</label>
                        <input type="date" name="monitor_date" value="<?=date('Y-m-d')?>" required>
                    </div>

                    <div class="form-group">
                        <label>Health Status</label>
                        <select name="health_status" required>
                            <option value="">-- Select Status --</option>
                            <option value="Healthy">Healthy</option>
                            <option value="Sick">Sick</option>
                            <option value="Critical">Critical</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Weight (kg)</label>
                        <input type="number" name="weight" step="0.01" min="0" placeholder="Enter weight" required>
                    </div>

                    <div class="form-group">
                        <label>Pregnancy Status</label>
                        <select name="pregnancy_status" id="pregnancy_status" onchange="toggleOffspringField()" required>
                            <option value="">-- Select --</option>
                            <option value="not_pregnant">Not Pregnant</option>
                            <option value="pregnant">Pregnant</option>
                            <option value="gave_birth">Gave Birth</option>
                        </select>
                        <small id="pregnancy_hint" style="display:none;color:var(--muted,#6b776e);">
                            Male livestock cannot have a pregnancy status.
                        </small>
                        <!-- disabled selects are never posted, so this keeps
                             pregnancy_status present (empty) for male entries -->
                        <input type="hidden" name="pregnancy_status" id="pregnancy_status_fallback" value="" disabled>
                    </div>

                    <div class="form-group">
                        <label>Offspring Alive</label>
                        <input type="number" name="offspring_alive" id="offspring_alive" min="0" step="1" value="0" required readonly oninput="updateOffspringTotal()">
                    </div>

                    <div class="form-group">
                        <label>Offspring Dead</label>
                        <input type="number" name="offspring_dead" id="offspring_dead" min="0" step="1" value="0" required readonly oninput="updateOffspringTotal()">
                    </div>

                    <div class="form-group">
                        <label>Total Offspring</label>
                        <input type="number" name="offspring" id="offspring" min="0" value="0" readonly>
                        <small style="color:var(--muted,#6b776e);">Auto-computed: alive + dead.</small>
                    </div>

                    <div class="form-group">
                        <label>Photo</label>
                        <input type="file" name="photo" accept="image/*" required>
                    </div>

                    <div class="form-group full">
                        <label>Remarks / Notes</label>
                        <textarea name="remarks" placeholder="Enter monitoring notes..." required></textarea>
                    </div>

                    <div class="form-group full">
                        <label>Mortality Case</label>
                        <select name="livestock_status" id="livestock_status" onchange="toggleDeathFields()" required>
                            <option value="alive">Livestock Alive</option>
                            <option value="death">Record as Death</option>
                        </select>
                    </div>

                    <div class="form-group death-field" style="display:none;">
                        <label>Cause of Death</label>
                        <input type="text" name="cause_of_death" placeholder="Enter cause of death">
                    </div>

                    <div class="form-group death-field" style="display:none;">
                        <label>Death Type</label>
                        <select name="death_type">
                            <option value="">-- Select --</option>
                            <option value="natural">Natural</option>
                            <option value="disease">Disease</option>
                            <option value="accident">Accident</option>
                            <option value="unknown">Unknown</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button type="button" class="btn-cancel" onclick="closeMonitoring()">
                    Cancel
                </button>

                <button type="submit" name="save_monitoring" value="1" class="btn-save">
                    <i class="fas fa-save"></i>
                    Save Monitoring
                </button>

            </div>

        </form>

    </div>

</div>

<!-- SCHEDULE MODAL -->
<div class="modal" id="scheduleModal">

    <div class="modal-box">

        <div class="modal-head">
            <h3>
                <i class="fas fa-calendar-check"></i>
                Monitoring Schedule
            </h3>

            <button type="button" class="close" onclick="closeSchedule()">
                &times;
            </button>
        </div>

        <div class="modal-body" id="scheduleBody">
            <!-- filled by openSchedule() -->
        </div>

        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeSchedule()">
                Close
            </button>
        </div>

    </div>

</div>

<script>
function openMonitoring(data){
    // start with a clean form every time (health status, weight, photo, etc. must be filled in again)
    document.getElementById('monitorForm').reset();
    toggleDeathFields();

    document.getElementById('distribution_id').value=data.distribution_id||'';

    const fullName=[
        data.firstname||'',
        data.middlename||'',
        data.lastname||''
    ].join(' ').replace(/\s+/g,' ').trim();

    document.getElementById('beneficiary_name').value=fullName;

    const mImg=document.getElementById('modal_farmer_img');
    document.getElementById('modal_farmer_init').textContent=(fullName.charAt(0)||'?').toUpperCase();
    mImg.alt=fullName;
    if(data.farmer_photo_url){
        mImg.onerror=function(){ this.style.display='none'; };
        mImg.style.display='';
        mImg.src=data.farmer_photo_url;
    }else{
        mImg.style.display='none';
        mImg.removeAttribute('src');
    }

    document.getElementById('livestock_name').value=[
        data.kind||'',
        data.breed?' - '+data.breed:'',
        data.batch_no?' ('+data.batch_no+')':''
    ].join('');

    const pregnancySelect=document.getElementById('pregnancy_status');
    const pregnancyHint=document.getElementById('pregnancy_hint');
    const isMale=(data.sex||'').toLowerCase()==='male';

    const pregnancyFallback=document.getElementById('pregnancy_status_fallback');

    if(isMale){
        pregnancySelect.value='';
        pregnancySelect.disabled=true;
        pregnancySelect.removeAttribute('required');
        pregnancyHint.style.display='block';
        pregnancyFallback.disabled=false;
    }else{
        pregnancySelect.disabled=false;
        pregnancySelect.setAttribute('required','required');
        pregnancyHint.style.display='none';
        pregnancyFallback.disabled=true;
    }

    toggleOffspringField();

    document.getElementById('monitorModal').classList.add('show');
}

function updateOffspringTotal(){
    const a=parseInt(document.getElementById('offspring_alive').value,10)||0;
    const d=parseInt(document.getElementById('offspring_dead').value,10)||0;
    document.getElementById('offspring').value=a+d;
}

function toggleOffspringField(){
    const pregnancySelect=document.getElementById('pregnancy_status');
    const alive=document.getElementById('offspring_alive');
    const dead=document.getElementById('offspring_dead');
    const canHaveOffspring=!pregnancySelect.disabled && pregnancySelect.value==='gave_birth';

    [alive,dead].forEach(function(el){
        if(canHaveOffspring){
            el.removeAttribute('readonly');
        }else{
            el.value=0;
            el.setAttribute('readonly','readonly');
        }
    });
    updateOffspringTotal();
}

function closeMonitoring(){
    document.getElementById('monitorModal').classList.remove('show');
}

function toggleDeathFields(){
    const status=document.getElementById('livestock_status').value;
    const fields=document.querySelectorAll('.death-field');

    fields.forEach(function(field){
        const isDeath = status==='death';
        field.style.display = isDeath ? 'block' : 'none';

        const input = field.querySelector('input, select');
        if(input){
            if(isDeath){
                input.setAttribute('required','required');
            }else{
                input.removeAttribute('required');
            }
        }
    });
}

// Block saving unless every required field is really filled in
document.getElementById('monitorForm').addEventListener('submit',function(e){
    const form=e.target;
    let firstInvalid=null;

    form.querySelectorAll('input[required], select[required], textarea[required]').forEach(function(el){
        // trim text so spaces-only doesn't count as filled
        if(el.tagName==='TEXTAREA' || el.type==='text'){
            el.value=el.value.trim();
        }
        if(!el.value || (el.type==='file' && el.files.length===0)){
            el.classList.add('is-invalid');
            if(!firstInvalid) firstInvalid=el;
        }else{
            el.classList.remove('is-invalid');
        }
    });

    if(!document.getElementById('distribution_id').value){
        e.preventDefault();
        alert('No livestock selected. Please close this form and try again.');
        return;
    }

    if(firstInvalid){
        e.preventDefault();
        alert('Please fill up all fields before saving the monitoring record.');
        firstInvalid.focus();
    }
});

document.getElementById('monitorModal').addEventListener('click',function(e){
    if(e.target===this){
        closeMonitoring();
    }
});

const PREGNANCY_LABELS={
    pregnant:'Pregnant',
    not_pregnant:'Not Pregnant',
    gave_birth:'Gave Birth'
};

function formatDate(d){
    if(!d) return '—';
    const dt=new Date(d+'T00:00:00');
    if(isNaN(dt)) return d;
    return dt.toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
}

function openSchedule(data){
    const fullName=[
        data.firstname||'',
        data.middlename||'',
        data.lastname||''
    ].join(' ').replace(/\s+/g,' ').trim();

    const livestockLabel=[
        data.kind||'',
        data.breed?' - '+data.breed:''
    ].join('');

    const body=document.getElementById('scheduleBody');

    if(!data.sched_has_record){
        body.innerHTML=`
            <div class="sched-empty">
                <i class="fas fa-calendar-xmark"></i>
                No monitoring records yet for <strong>${fullName}</strong>'s livestock.
                Record a monitoring visit first to build a schedule.
            </div>
        `;
        document.getElementById('scheduleModal').classList.add('show');
        return;
    }

    const pregnancy=data.sched_pregnancy||'';
    const pillClass=pregnancy||'none';
    const pillLabel=PREGNANCY_LABELS[pregnancy]||'No pregnancy data';

    let scheduleHtml='';

    if(pregnancy==='pregnant'){
        scheduleHtml=`
            <div class="sched-item">
                <div class="sched-label">Expected Birth Date</div>
                <div class="sched-value">${formatDate(data.sched_expected_birth)}</div>
            </div>
            <div class="sched-item">
                <div class="sched-label">Next Recommended Visit</div>
                <div class="sched-value">${formatDate(data.sched_next_visit)}</div>
            </div>
        `;
    } else if(pregnancy==='gave_birth'){
        scheduleHtml=`
            <div class="sched-item">
                <div class="sched-label">Gave Birth On</div>
                <div class="sched-value">${formatDate(data.sched_monitor_date)}</div>
            </div>
            <div class="sched-item">
                <div class="sched-label">Total Offspring</div>
                <div class="sched-value">${data.sched_offspring||0}</div>
            </div>
            <div class="sched-item">
                <div class="sched-label">Offspring Alive</div>
                <div class="sched-value">${data.sched_offspring_alive||0}</div>
            </div>
            <div class="sched-item">
                <div class="sched-label">Offspring Dead</div>
                <div class="sched-value">${data.sched_offspring_dead||0}</div>
            </div>
        `;
    } else {
        scheduleHtml=`
            <div class="sched-item full">
                <div class="sched-label">Schedule</div>
                <div class="sched-value">No upcoming visit or birth scheduled.</div>
            </div>
        `;
    }

    body.innerHTML=`
        <div class="sched-item full">
            <div class="sched-label">Beneficiary</div>
            <div class="sched-value">${fullName || '—'}</div>
        </div>
        <div class="sched-grid">
            <div class="sched-item full">
                <div class="sched-label">Livestock</div>
                <div class="sched-value">${livestockLabel || '—'}</div>
            </div>
            <div class="sched-item">
                <div class="sched-label">Last Monitored</div>
                <div class="sched-value">${formatDate(data.sched_monitor_date)}</div>
            </div>
            <div class="sched-item">
                <div class="sched-label">Health Status</div>
                <div class="sched-value">${data.sched_health||'—'}</div>
            </div>
            <div class="sched-item full">
                <div class="sched-label">Pregnancy Status</div>
                <div class="sched-value"><span class="sched-pill ${pillClass}">${pillLabel}</span></div>
            </div>
            ${scheduleHtml}
        </div>
    `;

    document.getElementById('scheduleModal').classList.add('show');
}

function closeSchedule(){
    document.getElementById('scheduleModal').classList.remove('show');
}

document.getElementById('scheduleModal').addEventListener('click',function(e){
    if(e.target===this){
        closeSchedule();
    }
});
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