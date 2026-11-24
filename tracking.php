<?php
session_start();
require '../db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

/* ---------- FILTER INPUTS ---------- */
$search = isset($_GET['search']) ? trim($_GET['search']) : "";

$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : "";
if(!in_array($statusFilter, ['distributed','death','returned'], true)){
    $statusFilter = "";
}

$ownerFilter = isset($_GET['owner']) ? trim($_GET['owner']) : "";
if(!in_array($ownerFilter, ['current','previous'], true)){
    $ownerFilter = "";
}

/* Build a tab link that keeps the other filters */
function tabUrl(array $override){
    global $search, $statusFilter, $ownerFilter;
    $q = array_merge(['search'=>$search,'status'=>$statusFilter,'owner'=>$ownerFilter], $override);
    return '?' . http_build_query($q);
}

/* =========================================================
   FETCH ALL DISTRIBUTION RECORDS (full ownership trail)
   Ordered per animal, oldest -> newest, so the LAST record of
   each animal is its current owner and the earlier ones are
   previous owners. Filters are applied afterwards so the
   ownership history is always complete.
========================================================= */
$sql = "
    SELECT d.*, b.firstname, b.lastname, br.barangay_name,
           l.batch_no AS livestock_name, l.kind, l.breed, t.name AS technician_name
    FROM distributions d
    JOIN beneficiaries b ON d.beneficiary_id=b.beneficiary_id
    JOIN barangays br ON b.barangay_id=br.barangay_id
    JOIN livestock l ON d.livestock_id=l.livestock_id
    LEFT JOIN technicians t ON d.technician_id=t.technician_id
    ORDER BY d.livestock_id ASC, d.distribution_date ASC, d.distribution_id ASC
";
$result = $conn->query($sql);

$allRows = [];
if($result){
    while($row = $result->fetch_assoc()){
        $allRows[] = $row;
    }
}

/* ---------- GROUP INTO OWNERSHIP HISTORY PER ANIMAL ---------- */
$historyByLivestock = [];
foreach($allRows as $row){
    $historyByLivestock[$row['livestock_id']][] = $row;
}

/* Mark current owner + count transfers, and prepare data for the History modal */
$historyForJs = [];
$records = [];
foreach($historyByLivestock as $lid => $list){
    $lastIdx = count($list) - 1;
    $historyForJs[$lid] = [];
    foreach($list as $i => $r){
        $r['is_current'] = ($i === $lastIdx);
        $r['owner_count'] = count($list);
        $records[] = $r;

        $historyForJs[$lid][] = [
            'name'   => $r['firstname'].' '.$r['lastname'],
            'barangay' => $r['barangay_name'],
            'date'   => $r['distribution_date'],
            'technician' => $r['technician_name'],
            'status' => ($r['status'] === 'active') ? 'distributed' : $r['status'],
            'return_date' => $r['return_date'],
            'death_date'  => $r['death_date'],
            'death_cause' => $r['death_cause'],
        ];
    }
}

/* ---------- SUMMARY NUMBERS (before filters) ---------- */
$totalAnimals      = count($historyByLivestock);
$totalTransferred  = 0;
foreach($historyByLivestock as $list){
    if(count($list) > 1) $totalTransferred++;
}
$totalRecords = count($records);

/* ---------- APPLY FILTERS ---------- */
$dbStatus = ($statusFilter === 'distributed') ? 'active' : $statusFilter;
$needle = mb_strtolower($search);

$distributed = array_values(array_filter($records, function($r) use ($needle, $dbStatus, $ownerFilter){
    if($dbStatus !== "" && $r['status'] !== $dbStatus) return false;
    if($ownerFilter === 'current'  && !$r['is_current']) return false;
    if($ownerFilter === 'previous' &&  $r['is_current']) return false;

    if($needle !== ""){
        $hay = mb_strtolower(implode(' ', [
            $r['firstname'].' '.$r['lastname'],
            $r['barangay_name'],
            $r['livestock_name'],
            $r['kind'],
            $r['breed'] ?? '',
            $r['technician_name'] ?? '',
        ]));
        if(mb_strpos($hay, $needle) === false) return false;
    }
    return true;
}));

/* Newest distributions first */
usort($distributed, function($a, $b){
    return strtotime($b['distribution_date'] ?? '0') <=> strtotime($a['distribution_date'] ?? '0');
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Livestock Tracking - PIGOBAO</title>
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

/* HEADER */
header {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 65px;
    background: linear-gradient(120deg, var(--pg-forest-900), var(--pg-forest-800) 55%, var(--pg-forest-600));
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 30px;
    z-index: 1001;
    box-shadow: 0 2px 14px rgba(10,40,12,0.25);
}
header .brand{display:flex;align-items:center;gap:12px;}
header .brand .mark{
    width:34px;height:34px;border-radius:9px;
    background:rgba(255,255,255,0.14);
    display:flex;align-items:center;justify-content:center;
    font-size:15px;color:#eafbea;
    border:1px solid rgba(255,255,255,0.22);
}
header h1 { font-size: 17px; font-weight: 600; letter-spacing:.2px; }
header .brand small{display:block;font-size:11px;font-weight:400;color:rgba(255,255,255,0.65);letter-spacing:.4px;text-transform:uppercase;}
header a.logout {
    color: var(--pg-forest-800);
    background: #fff;
    padding: 8px 20px;
    border-radius: 20px;
    font-weight: 600;
    text-decoration: none;
    font-size: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
    transition: 0.2s ease;
}
header a.logout:hover {
    background: var(--pg-forest-100);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.16);
}

/* SIDEBAR */
.sidebar {
    position: fixed;
    top: 65px;
    left: 0;
    width: 240px;
    height: calc(100% - 65px);
    background: linear-gradient(195deg, var(--pg-forest-800), var(--pg-forest-600) 120%);
    padding-top: 22px;
    box-shadow: inset -1px 0 0 rgba(255,255,255,0.06);
}
.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    color: rgba(255,255,255,0.88);
    padding: 13px 22px;
    text-decoration: none;
    margin: 2px 12px 4px;
    border-radius: 9px;
    border-left: 3px solid transparent;
    font-size: 14.5px;
    font-weight: 500;
    transition: 0.2s ease;
    position: relative;
}
.sidebar a i{width:18px;text-align:center;font-size:15px;opacity:.9;}
.sidebar a:hover {
    background: rgba(255,255,255,0.12);
    transform: translateX(2px);
}
.sidebar a.active {
    background: var(--pg-forest-300);
    color: var(--pg-forest-900);
    border-left: 3px solid var(--pg-forest-900);
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0,0,0,0.18);
}
.sidebar a.active i{opacity:1;}

/* MAIN */
.main-content {
    margin-left: 240px;
    padding: 100px 38px 40px;
    flex: 1;
    width: auto;
}

/* PAGE HEADER STRIP */
.page-card{
    background:#fff;border-radius:16px;padding:26px 30px;
    box-shadow:var(--shadow-soft);border:1px solid var(--pg-line);
    display:flex;align-items:center;gap:22px;flex-wrap:wrap;margin-bottom:28px;
    position:relative;overflow:hidden;
}
.page-card::before{
    content:"";position:absolute;top:0;left:0;height:5px;width:100%;
    background:linear-gradient(90deg, var(--pg-forest-800), var(--pg-forest-300));
}
.page-icon{
    width:60px;height:60px;border-radius:16px;flex:none;
    background:linear-gradient(145deg, var(--pg-forest-600), var(--pg-forest-900));
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:22px;
    box-shadow:0 8px 18px -6px rgba(18,60,22,0.5);
}
.page-info h2{
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:21px;
    margin:0 0 4px;color:var(--pg-forest-900);
}
.page-info p{
    font-size:13.5px;color:var(--pg-ink-soft);margin:0;
}

/* SEARCH */
.search-bar{
    display:flex;gap:10px;margin-bottom:24px;
}
.search-bar .search-input-wrap{
    position:relative;flex:1;max-width:420px;
}
.search-bar .search-input-wrap i{
    position:absolute;left:14px;top:50%;transform:translateY(-50%);
    color:var(--pg-ink-soft);font-size:13px;
}
.search-bar input[type="text"]{
    width:100%;border:1px solid var(--pg-line);border-radius:10px;
    padding:11px 14px 11px 36px;font-size:14px;background:#fff;
    box-shadow:var(--shadow-soft);
}
.search-bar input[type="text"]:focus{
    outline:none;border-color:var(--pg-forest-600);
    box-shadow:0 0 0 3px rgba(46,125,50,0.14);
}
.status-tabs{
    display:flex;gap:10px;margin-bottom:24px;
}
.status-tab{
    border:1px solid var(--pg-line);border-radius:10px;
    padding:9px 20px;font-size:13.5px;font-weight:600;
    background:#fff;color:var(--pg-ink-soft);text-decoration:none;
    box-shadow:var(--shadow-soft);transition:0.2s ease;
}
.status-tab:hover{background:var(--pg-forest-100);color:var(--pg-forest-900);}
.status-tab.active{
    background:linear-gradient(120deg, var(--pg-forest-800), var(--pg-forest-600));
    color:#fff;border-color:transparent;
}
.status-tab.death.active{
    background:linear-gradient(120deg, #8f3527, var(--pg-clay));
}
.status-tab.returned.active{
    background:linear-gradient(120deg, #4a3d80, var(--pg-plum));
}
.status-pill{
    padding:5px 13px;border-radius:999px;font-size:12px;font-weight:600;
    letter-spacing:.3px;text-transform:capitalize;
}
.status-pill.distributed{background:#e8f5e9;color:var(--pg-forest-800);}
.status-pill.death{background:#fdeceb;color:var(--pg-clay);}
.status-pill.returned{background:#efe9fb;color:var(--pg-plum);}
.search-bar button{
    background: linear-gradient(120deg, var(--pg-forest-800), var(--pg-forest-600));
    color:#fff;border:none;padding:0 22px;border-radius:10px;
    font-weight:600;font-size:14px;
    display:inline-flex;align-items:center;gap:8px;
    box-shadow:0 6px 16px -6px rgba(18,60,22,0.5);
    transition:0.2s ease;cursor:pointer;
}
.search-bar button:hover{transform:translateY(-1px);}

.section-label{
    font-family:'Space Grotesk',sans-serif;font-size:13px;font-weight:600;
    letter-spacing:.6px;text-transform:uppercase;color:var(--pg-ink-soft);
    margin:0 0 14px 2px;
}

/* TABLE CARD */
.table-card{
    background:#fff;border-radius:16px;border:1px solid var(--pg-line);
    box-shadow:var(--shadow-soft);overflow:hidden;
}
.table-responsive{width:100%;overflow-x:auto;}
table.pg-table{
    width:100%;border-collapse:collapse;font-size:14px;
}
table.pg-table thead th{
    background:var(--pg-forest-900);
    color:#eafbea;
    font-family:'Space Grotesk',sans-serif;
    font-weight:600;font-size:12.5px;
    text-transform:uppercase;letter-spacing:.4px;
    padding:15px 18px;text-align:left;
    border:none;
    white-space:nowrap;
}
table.pg-table tbody td{
    padding:14px 18px;
    border-bottom:1px solid var(--pg-line);
    color:var(--pg-ink);
    vertical-align:middle;
    white-space:nowrap;
}
table.pg-table tbody tr:hover{
    background:var(--pg-forest-100);
}
table.pg-table tbody tr:last-child td{border-bottom:none;}
table.pg-table tbody td.empty-row{
    white-space:normal;text-align:center;padding:30px 18px;
    color:var(--pg-ink-soft);font-style:italic;
}

.kind-pill{
    padding:5px 13px;border-radius:999px;font-size:12px;font-weight:600;
    letter-spacing:.3px;background:#fdf3e3;color:var(--pg-amber);text-transform:capitalize;
}

.action-btn{
    border:none;border-radius:8px;padding:7px 13px;font-size:12.5px;font-weight:600;
    display:inline-flex;align-items:center;gap:6px;text-decoration:none;
    transition:0.2s ease;cursor:pointer;
    background:#eaf2f8;color:var(--pg-sky);
}
.action-btn:hover{background:var(--pg-sky);color:#fff;}

/* MODALS */
.modal-content{
    border-radius:16px;border:none;overflow:hidden;
    box-shadow:var(--shadow-lift);
}
.modal-header{
    background:linear-gradient(120deg, var(--pg-forest-900), var(--pg-forest-800) 55%, var(--pg-forest-600));
    color:#fff;border:none;padding:18px 24px;
}
.modal-header .modal-title{
    font-family:'Space Grotesk',sans-serif;font-weight:600;font-size:16px;
    display:flex;align-items:center;gap:10px;
}
.modal-header .btn-close{filter:invert(1) brightness(2);}
.modal-body{padding:24px;background:var(--pg-canvas);}
.detail-grid{
    display:grid;grid-template-columns:1fr 1fr;gap:14px 22px;
}
.detail-grid .detail-item.full{grid-column:1 / -1;}
.detail-item .detail-label{
    font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;
    color:var(--pg-ink-soft);margin-bottom:4px;
}
.detail-item .detail-value{
    font-size:14.5px;color:var(--pg-ink);font-weight:500;
    background:#fff;border:1px solid var(--pg-line);border-radius:9px;
    padding:10px 13px;
}
.modal-footer{border:none;padding:16px 24px 22px;background:var(--pg-canvas);}
.modal-footer .btn-secondary{
    background:#fff;border:1px solid var(--pg-line);color:var(--pg-ink-soft);
    border-radius:9px;padding:10px 20px;font-weight:600;font-size:14px;
}
.modal-footer .btn-secondary:hover{background:var(--pg-forest-100);color:var(--pg-forest-900);}


/* STATS */
.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{
    background:#fff;border:1px solid var(--pg-line);border-radius:14px;
    padding:16px 18px;box-shadow:var(--shadow-soft);display:flex;align-items:center;gap:14px;
}
.stat-card .stat-ico{
    width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;
    font-size:16px;flex:none;
}
.stat-card .stat-ico.green{background:var(--pg-forest-100);color:var(--pg-forest-800);}
.stat-card .stat-ico.amber{background:#fdf3e3;color:var(--pg-amber);}
.stat-card .stat-ico.clay{background:#fdeceb;color:var(--pg-clay);}
.stat-card .stat-num{font-family:'Space Grotesk',sans-serif;font-size:22px;font-weight:700;color:var(--pg-forest-900);line-height:1.1;}
.stat-card .stat-lbl{font-size:12px;color:var(--pg-ink-soft);}

/* FILTER GROUPS */
.filter-row{display:flex;flex-wrap:wrap;gap:24px;margin-bottom:24px;align-items:center;}
.filter-group{display:flex;flex-wrap:wrap;gap:10px;align-items:center;}
.filter-group .filter-title{
    font-size:11.5px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:var(--pg-ink-soft);
}
.status-tabs{margin-bottom:0;}

/* OWNERSHIP */
.own-flag{
    display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;
    padding:4px 11px;border-radius:999px;
}
.own-flag.current{background:var(--pg-forest-100);color:var(--pg-forest-800);}
.own-flag.last{background:#f0f0f0;color:var(--pg-ink-soft);}
.own-flag.previous{background:rgba(193,84,63,0.1);color:var(--pg-clay);}

.pg-btn-view{
    background:var(--pg-forest-600);color:#fff;border:none;padding:7px 13px;border-radius:8px;
    font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:.2s ease;
}
.pg-btn-view:hover{background:var(--pg-forest-800);}
.pg-btn-view .count{
    background:rgba(255,255,255,0.25);border-radius:999px;padding:0 7px;font-size:11px;
}
.result-count{font-size:12.5px;color:var(--pg-ink-soft);margin:-6px 0 14px 2px;}

/* HISTORY MODAL */
.modal-close-x{
    background:rgba(255,255,255,0.16);border:1px solid rgba(255,255,255,0.3);color:#fff;
    width:32px;height:32px;min-width:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-size:20px;line-height:1;cursor:pointer;transition:.2s ease;flex:none;
}
.modal-close-x:hover{background:rgba(255,255,255,0.32);transform:rotate(90deg);}
.history-summary{
    display:flex;flex-wrap:wrap;gap:10px 22px;margin-bottom:16px;font-size:13px;color:var(--pg-ink-soft);
}
.history-summary strong{color:var(--pg-forest-900);}
.history-table-wrap{overflow-x:auto;background:#fff;border:1px solid var(--pg-line);border-radius:12px;}
.history-table{width:100%;border-collapse:collapse;font-size:13px;}
.history-table thead th{
    text-align:left;padding:10px 12px;background:#fff;color:var(--pg-ink-soft);font-size:11px;font-weight:600;
    text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid var(--pg-line);white-space:nowrap;
}
.history-table tbody td{padding:11px 12px;border-bottom:1px solid var(--pg-line);color:var(--pg-ink);vertical-align:middle;white-space:nowrap;}
.history-table tbody tr:last-child td{border-bottom:none;}
.history-table tbody tr.is-current{background:var(--pg-forest-100);}
.history-table tbody tr.is-current td:first-child{font-weight:600;color:var(--pg-forest-900);}
.return-pending{
    display:inline-block;font-size:11px;font-weight:600;color:var(--pg-forest-800);
    background:#fff;padding:3px 9px;border-radius:999px;
}
.history-note{
    margin-top:16px;font-size:12.5px;color:var(--pg-ink-soft);background:#fff;
    border:1px solid var(--pg-line);border-radius:8px;padding:10px 14px;
}

/* RESPONSIVE */
@media(max-width:900px){
    .sidebar{ width:200px; }
    .main-content{ margin-left:200px; padding:98px 24px 30px; }
}

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
    .search-bar{flex-direction:column;}
    .filter-row{gap:14px;}
    .search-bar .search-input-wrap{max-width:none;}
    .detail-grid{grid-template-columns:1fr;}
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
            <small>Livestock Tracking &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../adminlog_out.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="./add-technician.php"><i class="fas fa-user-cog"></i> Technicians</a>
    <a href="./farmer-beneficiary.php"><i class="fas fa-id-card"></i> Farmer Beneficiary</a>
    <a href="./add-livestock.php"><i class="fas fa-drumstick-bite"></i> Livestock</a>
    <a href="./tracking.php" class="active"><i class="fas fa-eye"></i> Livestock Tracking</a>
    <a href="./dispersal.php"><i class="fas fa-people-carry-box"></i> Dispersal</a>
    <a href="./farmer-success-rate.php"><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="./reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="./generate-report.php"><i class="fas fa-file-alt"></i> Generate Report</a>
</div>

<div class="main-content">

    <div class="page-card">
        <div class="page-icon"><i class="fas fa-eye"></i></div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Livestock Tracking &amp; Ownership</h2>
            <p>Track distributed livestock, see who owns each animal now, and who owned it before.</p>
        </div>
        <span class="role-pill"><i class="fas fa-shield-halved"></i> Administrator</span>
    </div>

    <div class="stat-row">
        <div class="stat-card">
            <div class="stat-ico green"><i class="fas fa-drumstick-bite"></i></div>
            <div><div class="stat-num"><?= $totalAnimals ?></div><div class="stat-lbl">Animals distributed</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-ico amber"><i class="fas fa-list-check"></i></div>
            <div><div class="stat-num"><?= $totalRecords ?></div><div class="stat-lbl">Distribution records</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-ico clay"><i class="fas fa-right-left"></i></div>
            <div><div class="stat-num"><?= $totalTransferred ?></div><div class="stat-lbl">Animals transferred</div></div>
        </div>
    </div>

    <form method="GET" class="search-bar">
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
        <input type="hidden" name="owner" value="<?= htmlspecialchars($ownerFilter) ?>">
        <div class="search-input-wrap">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Search name, barangay, eartag, kind or technician" value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit"><i class="fas fa-search"></i> Search</button>
    </form>

    <div class="filter-row">
        <div class="filter-group">
            <span class="filter-title">Status</span>
            <div class="status-tabs">
                <a href="<?= tabUrl(['status'=>'']) ?>" class="status-tab <?= $statusFilter === '' ? 'active' : '' ?>">All</a>
                <a href="<?= tabUrl(['status'=>'distributed']) ?>" class="status-tab distributed <?= $statusFilter === 'distributed' ? 'active' : '' ?>">Distributed</a>
                <a href="<?= tabUrl(['status'=>'death']) ?>" class="status-tab death <?= $statusFilter === 'death' ? 'active' : '' ?>">Death</a>
                <a href="<?= tabUrl(['status'=>'returned']) ?>" class="status-tab returned <?= $statusFilter === 'returned' ? 'active' : '' ?>">Returned</a>
            </div>
        </div>
        <div class="filter-group">
            <span class="filter-title">Owner</span>
            <div class="status-tabs">
                <a href="<?= tabUrl(['owner'=>'']) ?>" class="status-tab <?= $ownerFilter === '' ? 'active' : '' ?>">All</a>
                <a href="<?= tabUrl(['owner'=>'current']) ?>" class="status-tab <?= $ownerFilter === 'current' ? 'active' : '' ?>">Current</a>
                <a href="<?= tabUrl(['owner'=>'previous']) ?>" class="status-tab death <?= $ownerFilter === 'previous' ? 'active' : '' ?>">Previous</a>
            </div>
        </div>
    </div>

    <p class="section-label">Distribution &amp; Ownership Records</p>
    <p class="result-count"><?= count($distributed) ?> record<?= count($distributed) === 1 ? '' : 's' ?> shown &middot; click <strong>History</strong> to see every owner of an animal.</p>

    <div class="table-card">
        <div class="table-responsive">
            <table class="pg-table">
                <thead>
                    <tr>
                        <th>Eartag no</th>
                        <th>Kind</th>
                        <th>Breed</th>
                        <th>Beneficiary</th>
                        <th>Barangay</th>
                        <th>Date Distributed</th>
                        <th>Technician</th>
                        <th>Status</th>
                        <th>Ownership</th>
                        <th>History</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($distributed) > 0): ?>
                        <?php foreach($distributed as $d): ?>
                            <?php
                                $isActive  = ($d['status'] === 'active');
                                $pillClass = $isActive ? 'distributed' : htmlspecialchars($d['status']);
                                $pillLabel = $isActive ? 'Distributed' : htmlspecialchars($d['status']);

                                if(!$d['is_current']){
                                    $ownClass = 'previous'; $ownLabel = 'Previous Owner'; $ownIcon = 'fa-clock-rotate-left';
                                } elseif($isActive){
                                    $ownClass = 'current';  $ownLabel = 'Current Owner';  $ownIcon = 'fa-circle-check';
                                } else {
                                    $ownClass = 'last';     $ownLabel = 'Last Owner';     $ownIcon = 'fa-flag';
                                }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($d['livestock_name']) ?></td>
                                <td><span class="kind-pill"><?= htmlspecialchars($d['kind']) ?></span></td>
                                <td><?= htmlspecialchars($d['breed'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($d['firstname']." ".$d['lastname']) ?></td>
                                <td><?= htmlspecialchars($d['barangay_name']) ?></td>
                                <td><?= htmlspecialchars($d['distribution_date']) ?></td>
                                <td><?= htmlspecialchars($d['technician_name'] ?? '—') ?></td>
                                <td><span class="status-pill <?= $pillClass ?>"><?= $pillLabel ?></span></td>
                                <td><span class="own-flag <?= $ownClass ?>"><i class="fas <?= $ownIcon ?>"></i> <?= $ownLabel ?></span></td>
                                <td>
                                    <button type="button" class="pg-btn-view"
                                            onclick="showHistory(<?= (int)$d['livestock_id'] ?>)"
                                            data-tag="<?= htmlspecialchars($d['livestock_name']) ?>"
                                            data-kind="<?= htmlspecialchars($d['kind']) ?>"
                                            id="hist-btn-<?= (int)$d['livestock_id'] ?>-<?= (int)$d['is_current'] ?>">
                                        <i class="fas fa-clock-rotate-left"></i> History
                                        <?php if($d['owner_count'] > 1): ?><span class="count"><?= (int)$d['owner_count'] ?></span><?php endif; ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="empty-row"><em>No distributions found</em></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="gov-footer">
        <div class="inner">
            <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
            <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
        </div>
    </div>

</div>

<!-- HISTORY MODAL -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="historyModalTitle"><i class="fas fa-clock-rotate-left"></i> Ownership History</h5>
        <button type="button" class="modal-close-x" data-bs-dismiss="modal" aria-label="Close">&times;</button>
      </div>
      <div class="modal-body" id="historyModalBody"></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const OWNERSHIP_HISTORY = <?= json_encode($historyForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

function esc(v){
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function showHistory(livestockId){
    const list = OWNERSHIP_HISTORY[livestockId] || [];
    const btn  = document.querySelector('[id^="hist-btn-' + livestockId + '-"]');
    const tag  = btn ? btn.dataset.tag  : '';
    const kind = btn ? btn.dataset.kind : '';

    document.getElementById('historyModalTitle').innerHTML =
        '<i class="fas fa-clock-rotate-left"></i> Ownership History — ' + esc(kind) + (tag ? ' (' + esc(tag) + ')' : '');

    // Oldest first, so the chain reads in the order it happened
    const ordered = list.slice().sort((a, b) => new Date(a.date) - new Date(b.date));
    const current = ordered[ordered.length - 1];

    let html = '';
    if(current){
        const lastLabel = (current.status === 'death') ? 'Last owner (animal deceased)' : 'Latest owner';
        html += '<div class="history-summary">'
             +  '<span>' + lastLabel + ': <strong>' + esc(current.name) + '</strong></span>'
             +  '<span>Barangay: <strong>' + esc(current.barangay) + '</strong></span>'
             +  '<span>Total owners: <strong>' + ordered.length + '</strong></span>'
             +  '</div>';
    }

    html += '<div class="history-table-wrap"><table class="history-table"><thead><tr>'
         +  '<th>#</th><th>Owner</th><th>Barangay</th><th>Date Distributed</th><th>Technician</th><th>Date Transferred/Returned</th>'
         +  '</tr></thead><tbody>';

    ordered.forEach((rec, idx) => {
        const next = ordered[idx + 1];
        const isCurrent = !next;
        let endCell;
        if(rec.status === 'death'){
            endCell = '<span style="color:#c0392b;font-weight:600;">Died under this owner</span>'
                    + '<br><small>' + esc(rec.death_date || '—')
                    + (rec.death_cause ? ' · ' + esc(rec.death_cause) : '') + '</small>';
        } else if(rec.status === 'returned'){
            endCell = '<strong>Returned</strong><br><small>' + esc(rec.return_date || '—') + '</small>';
        } else if(!isCurrent){
            endCell = '<strong>Transferred</strong><br><small>' + esc(next.date) + '</small>';
        } else {
            endCell = '<span class="return-pending">Still with this owner</span>';
        }

        html += '<tr class="' + (isCurrent ? 'is-current' : '') + '">'
             +  '<td>' + (idx + 1) + '</td>'
             +  '<td>' + esc(rec.name) + '</td>'
             +  '<td>' + esc(rec.barangay) + '</td>'
             +  '<td>' + esc(rec.date) + '</td>'
             +  '<td>' + esc(rec.technician || '—') + '</td>'
             +  '<td>' + endCell + '</td>'
             +  '</tr>';
    });
    html += '</tbody></table></div>';

    if(ordered.length <= 1){
        html += '<p class="history-note">This is the first and only recorded recipient of this animal — no previous owner on file.</p>';
    }

    document.getElementById('historyModalBody').innerHTML = html;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('historyModal')).show();
}
</script>
</body>
</html>