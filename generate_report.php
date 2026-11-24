<?php
session_start();
require '../db.php';

if (!isset($_SESSION['technician_id'])) {
    header("Location: ../technicianindex.php");
    exit();
}

$tech_id = (int)$_SESSION['technician_id'];

/* =========================================================
   FILTERS (optional, via GET)
   "status" here is the farmer's OUTCOME status:
   - active   : livestock still with the farmer, no issues
   - returned : livestock returned/transferred (successful)
   - dead     : monitoring recorded a mortality for this
                distribution (same rule as the dashboard's
                mortality count: action='death' OR
                health_status='Dead')
========================================================= */
$filterBarangay = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;
$filterStatus   = isset($_GET['status']) ? trim($_GET['status']) : '';
$filterFrom     = isset($_GET['from']) ? trim($_GET['from']) : '';
$filterTo       = isset($_GET['to']) ? trim($_GET['to']) : '';
$filterKind     = isset($_GET['kind']) ? trim($_GET['kind']) : '';

$allowedStatuses = ['active', 'returned', 'dead'];
if ($filterStatus !== '' && !in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}

/* =========================================================
   BARANGAYS ASSIGNED TO THIS TECHNICIAN
   (technician_barangay is the authoritative assignment table)
========================================================= */
$assignedBarangays = [];

$barangayQuery = $conn->query("
    SELECT br.barangay_id, br.barangay_name
    FROM technician_barangay tb
    INNER JOIN barangays br
        ON tb.barangay_id = br.barangay_id
    WHERE tb.technician_id = $tech_id
    ORDER BY br.barangay_name ASC
");

if ($barangayQuery) {
    while ($row = $barangayQuery->fetch_assoc()) {
        $assignedBarangays[] = $row;
    }
}

$assignedBarangayIds = array_map('intval', array_column($assignedBarangays, 'barangay_id'));

/* =========================================================
   LIVESTOCK KINDS FOR THE FILTER
   Always offers Pig / Goat / Carabao, plus any other kind that
   already exists in this technician's distribution records.
========================================================= */
$kindOptions = ['pig' => 'Pig', 'goat' => 'Goat', 'carabao' => 'Carabao'];

$kindQuery = $conn->query("
    SELECT DISTINCT l.kind
    FROM distributions d
    INNER JOIN livestock l ON d.livestock_id = l.livestock_id
    WHERE d.technician_id = $tech_id
      AND l.kind IS NOT NULL AND l.kind <> ''
");
if ($kindQuery) {
    while ($kr = $kindQuery->fetch_assoc()) {
        $k = strtolower(trim($kr['kind']));
        if ($k !== '' && !isset($kindOptions[$k])) {
            $kindOptions[$k] = ucfirst($k);
        }
    }
}

$filterKind = strtolower($filterKind);
if ($filterKind !== '' && !isset($kindOptions[$filterKind])) {
    $filterKind = '';
}

if ($filterBarangay > 0 && !in_array($filterBarangay, $assignedBarangayIds, true)) {
    $filterBarangay = 0;
}

/* =========================================================
   BENEFICIARY / DISTRIBUTION REPORT RECORDS
========================================================= */
/* =========================================================
   MONITORING COLUMNS (last monitored date + offspring count)
   Auto-detects the column names in the `monitoring` table so the
   page does not break if the names differ. If your column names
   are different, add them to the candidate lists below.
========================================================= */
$monCols = [];
$colRes = $conn->query("SHOW COLUMNS FROM monitoring");
if ($colRes) {
    while ($c = $colRes->fetch_assoc()) {
        $monCols[strtolower($c['Field'])] = $c['Field'];
    }
}

$dateCandidates      = ['monitoring_date', 'date_monitored', 'monitored_at', 'monitor_date', 'date', 'created_at'];
$offspringCandidates = ['offspring', 'offspring_count', 'no_of_offspring', 'number_of_offspring', 'num_offspring', 'piglets', 'litter_size'];

$monDateCol = null;
foreach ($dateCandidates as $cand) {
    if (isset($monCols[$cand])) { $monDateCol = $monCols[$cand]; break; }
}
$monOffspringCol = null;
foreach ($offspringCandidates as $cand) {
    if (isset($monCols[$cand])) { $monOffspringCol = $monCols[$cand]; break; }
}

$lastMonSql  = $monDateCol      ? "MAX(`$monDateCol`)"              : "NULL";
$offspringSql = $monOffspringCol ? "SUM(`$monOffspringCol`)"         : "NULL";

$monSubquery = "
    SELECT
        distribution_id,
        $lastMonSql AS last_monitored,
        $offspringSql AS total_offspring
    FROM monitoring
    GROUP BY distribution_id
";

$records = [];

$statusCounts = [
    'active'   => 0,
    'returned' => 0,
    'dead'     => 0
];

$where = ["d.technician_id = $tech_id"];

if ($filterBarangay > 0) {
    $where[] = "b.barangay_id = $filterBarangay";
}

if ($filterKind !== '') {
    $safeKind = $conn->real_escape_string($filterKind);
    $where[] = "LOWER(TRIM(l.kind)) = '$safeKind'";
}

if ($filterFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $safeFrom = $conn->real_escape_string($filterFrom);
    $where[] = "d.distribution_date >= '$safeFrom'";
}

if ($filterTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $safeTo = $conn->real_escape_string($filterTo);
    $where[] = "d.distribution_date <= '$safeTo'";
}

if ($filterStatus === 'dead') {
    $where[] = "mort.distribution_id IS NOT NULL";
} elseif ($filterStatus === 'returned') {
    $where[] = "d.status = 'returned'";
    $where[] = "mort.distribution_id IS NULL";
} elseif ($filterStatus === 'active') {
    $where[] = "d.status = 'active'";
    $where[] = "mort.distribution_id IS NULL";
}

$whereSql = implode(' AND ', $where);

$listQuery = $conn->query("
    SELECT
        d.distribution_id,
        d.beneficiary_id,
        d.livestock_id,
        d.quantity,
        d.distribution_date,
        d.distribution_location,
        d.batch_no AS distribution_batch_no,
        d.remarks,
        d.status,

        b.firstname,
        b.middlename,
        b.lastname,
        b.contact,
        b.gender,
        b.barangay_id,

        br.barangay_name,

        l.kind,
        l.breed,
        TIMESTAMPDIFF(YEAR, l.birth_date, CURDATE()) AS age,
        l.status AS livestock_status,
        l.batch_no AS livestock_batch_no,

        mon.last_monitored,
        mon.total_offspring,

        (mort.distribution_id IS NOT NULL) AS is_dead

    FROM distributions d

    INNER JOIN beneficiaries b
        ON d.beneficiary_id = b.beneficiary_id

    INNER JOIN barangays br
        ON b.barangay_id = br.barangay_id

    INNER JOIN livestock l
        ON d.livestock_id = l.livestock_id

    LEFT JOIN (
        SELECT DISTINCT distribution_id
        FROM monitoring
        WHERE action = 'death' OR health_status = 'Dead'
    ) mort ON mort.distribution_id = d.distribution_id

    LEFT JOIN ($monSubquery) mon ON mon.distribution_id = d.distribution_id

    WHERE $whereSql

    ORDER BY d.distribution_date DESC, d.distribution_id DESC
");

if ($listQuery) {
    while ($row = $listQuery->fetch_assoc()) {
        $isDead = !empty($row['is_dead']);

        if ($isDead) {
            $row['outcome_status'] = 'dead';
        } elseif ($row['status'] === 'returned') {
            $row['outcome_status'] = 'returned';
        } else {
            $row['outcome_status'] = 'active';
        }

        if (isset($statusCounts[$row['outcome_status']])) {
            $statusCounts[$row['outcome_status']]++;
        }

        $records[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Generate Report - PIGOBAO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<style>
:root{
    --forest-900:#123c16;
    --forest-800:#1b5e20;
    --forest-600:#2e7d32;
    --forest-300:#a5d6a7;
    --forest-100:#e8f5e9;
    --canvas:#f3f6f4;
    --ink:#233126;
    --muted:#55625a;
    --line:#e1e9e2;
    --amber:#c98a1f;
    --blue:#3f7cac;
    --red:#c1543f;
    --shadow:0 8px 24px -12px rgba(18,60,22,.18);
}

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
    font-family:'Poppins',sans-serif;
}

body{
    min-height:100vh;
    font-size:15px;
    line-height:1.55;
    -webkit-font-smoothing:antialiased;
    background:var(--canvas);
    color:var(--ink);
}

.topbar{
    position:fixed;
    top:0;
    left:0;
    right:0;
    height:65px;
    background:linear-gradient(120deg,var(--forest-900),var(--forest-800) 55%,var(--forest-600));
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 28px;
    z-index:1000;
    box-shadow:0 3px 15px rgba(0,0,0,.18);
}

.brand{
    display:flex;
    align-items:center;
    gap:11px;
}

.brand-icon{
    width:35px;
    height:35px;
    border-radius:9px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.2);
    display:flex;
    align-items:center;
    justify-content:center;
}

.brand h1{
    font-size:19px;
    margin:0;
    font-weight:700;
}

.brand small{
    display:block;
    font-size:11.5px;
    color:rgba(255,255,255,.7);
}

.logout{
    color:var(--forest-800);
    background:#fff;
    padding:8px 18px;
    border-radius:20px;
    text-decoration:none;
    font-size:13.5px;
    font-weight:600;
}

.sidebar{
    position:fixed;
    top:65px;
    left:0;
    bottom:0;
    width:225px;
    background:linear-gradient(195deg,var(--forest-800),var(--forest-600) 120%);
    padding-top:18px;
    z-index:900;
}

.sidebar a{
    display:flex;
    align-items:center;
    gap:11px;
    color:rgba(255,255,255,.88);
    padding:12px 20px;
    text-decoration:none;
    margin:2px 10px 3px;
    border-radius:9px;
    font-size:15px;
    font-weight:500;
}

.sidebar a:hover{
    background:rgba(255,255,255,.12);
}

.sidebar a.active{
    background:var(--forest-300);
    color:var(--forest-900);
    font-weight:600;
}

.main{
    margin-left:225px;
    padding:90px 24px 40px;
    max-width:none;
    width:calc(100% - 225px);
}

.page-header{
    background:#fff;
    border:1px solid var(--line);
    border-radius:15px;
    padding:20px 22px;
    display:flex;
    align-items:center;
    gap:16px;
    box-shadow:var(--shadow);
    margin-bottom:18px;
}

.page-icon{
    width:47px;
    height:47px;
    border-radius:12px;
    background:linear-gradient(145deg,var(--forest-600),var(--forest-900));
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
}

.page-header h2{
    font-family:'Space Grotesk',sans-serif;
    color:var(--forest-900);
    font-size:24px;
    margin:0 0 3px;
    font-weight:700;
}

.page-header p{
    color:var(--muted);
    font-size:14px;
    margin:0;
}

.status-summary{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-bottom:18px;
}

.status-chip{
    display:flex;
    align-items:center;
    gap:8px;
    padding:9px 15px;
    border-radius:10px;
    font-size:14px;
    font-weight:600;
    border:1px solid var(--line);
    background:#fff;
}

.chip-active{
    color:#5d6b60;
}

.chip-active i{
    color:#5d6b60;
}

.chip-returned{
    color:#1e7e34;
    background:#e6f5e8;
    border-color:#c9e8cd;
}

.chip-dead{
    color:#b3402c;
    background:#fbe9e7;
    border-color:#f4cec9;
}

.filter-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:15px;
    padding:16px 18px;
    box-shadow:var(--shadow);
    margin-bottom:18px;
    display:flex;
    flex-wrap:wrap;
    align-items:flex-end;
    gap:14px;
}

.filter-field{
    display:flex;
    flex-direction:column;
    gap:5px;
}

.filter-field label{
    font-size:12px;
    font-weight:600;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.3px;
}

.filter-field select,
.filter-field input{
    border:1px solid var(--line);
    border-radius:8px;
    padding:10px 12px;
    font-family:'Poppins',sans-serif;
    font-size:14px;
    color:var(--ink);
    background:var(--canvas);
    min-width:150px;
}

.filter-actions{
    display:flex;
    gap:8px;
    margin-left:auto;
}

.btn-filter,
.btn-reset{
    border:0;
    border-radius:8px;
    padding:9px 16px;
    font-size:14px;
    font-weight:600;
    cursor:pointer;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    gap:6px;
}

.btn-filter{
    background:linear-gradient(120deg,var(--forest-800),var(--forest-600));
    color:#fff;
}

.btn-reset{
    background:var(--forest-100);
    color:var(--forest-800);
}

.report-header{
    background:#fff;
    border:1px solid var(--line);
    border-radius:15px 15px 0 0;
    padding:15px 18px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    box-shadow:var(--shadow);
}

.report-header h3{
    font-family:'Space Grotesk',sans-serif;
    font-size:17px;
    color:var(--forest-900);
    margin:0;
}

.report-header p{
    margin:3px 0 0;
    color:var(--muted);
    font-size:12.5px;
}

.generate-btn{
    border:0;
    border-radius:8px;
    background:linear-gradient(120deg,var(--forest-800),var(--forest-600));
    color:#fff;
    padding:9px 14px;
    font-size:13.5px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    gap:6px;
    cursor:pointer;
}

.table-wrap{
    background:#fff;
    border:1px solid var(--line);
    border-top:0;
    border-radius:0 0 15px 15px;
    overflow-x:auto;
    box-shadow:var(--shadow);
}

.report-table{
    width:100%;
    border-collapse:collapse;
    min-width:1800px;
    font-size:13.5px;
}

.report-table th{
    background:var(--forest-900);
    color:#fff;
    padding:14px 14px;
    text-align:left;
    white-space:nowrap;
    font-family:'Space Grotesk',sans-serif;
    font-size:11.5px;
    text-transform:uppercase;
    letter-spacing:.4px;
}

.report-table td{
    padding:13px 14px;
    border-bottom:1px solid var(--line);
    white-space:nowrap;
    vertical-align:middle;
}

.report-table tbody tr:hover{
    background:var(--forest-100);
}

.badge-kind{
    background:var(--forest-100);
    color:var(--forest-800);
    border-radius:999px;
    padding:5px 11px;
    font-size:11.5px;
    font-weight:700;
    text-transform:capitalize;
}

.badge-status{
    background:#eef1ef;
    color:#5d6b60;
    border-radius:999px;
    padding:5px 11px;
    font-size:11.5px;
    font-weight:600;
    text-transform:capitalize;
    white-space:nowrap;
}

.badge-status.status-active{
    background:#eef1ef;
    color:#5d6b60;
}

.badge-status.status-returned{
    background:#e6f5e8;
    color:#1e7e34;
}

.badge-status.status-dead{
    background:#fbe9e7;
    color:#b3402c;
}

.empty{
    text-align:center;
    padding:45px !important;
    color:var(--muted);
}

.print-tip{
    background:var(--forest-100);
    color:var(--forest-900);
    border:1px solid var(--forest-300);
    border-left:4px solid var(--forest-600);
    border-radius:10px;
    padding:12px 16px;
    margin:14px 0 0;
    font-size:14px;
    display:flex;
    align-items:flex-start;
    gap:10px;
    line-height:1.5;
}
.print-tip i{ margin-top:2px; }

.print-only{
    display:none;
}

@media(max-width:1100px){
    .filter-actions{
        margin-left:0;
        width:100%;
    }
}

@media(max-width:768px){
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
    .sidebar a{
        margin:2px;
        padding:9px 10px;
        font-size:12.5px;
    }

    .main{
        margin-left:0;
        padding:20px 15px 25px;
    }

    .topbar{
        padding:0 15px;
    }

    .brand h1{
        font-size:16px;
    }

    .page-header{
        padding:16px;
    }

    .report-header{
        align-items:flex-start;
        flex-direction:column;
    }

    .generate-btn{
        width:100%;
        justify-content:center;
    }
}

/* Wider table support */
.table-wrap{ scrollbar-width:thin; scrollbar-color:var(--forest-600) var(--forest-100); }
.table-wrap::-webkit-scrollbar{ height:10px; }
.table-wrap::-webkit-scrollbar-track{ background:var(--forest-100); }
.table-wrap::-webkit-scrollbar-thumb{ background:var(--forest-600); border-radius:10px; }
.report-table th:first-child,
.report-table td:first-child{
    position:sticky;
    left:0;
    z-index:2;
    box-shadow:1px 0 0 var(--line);
}
.report-table td:first-child{ background:#fff; font-weight:600; }
.report-table tbody tr:hover td:first-child{ background:var(--forest-100); }
.report-table th:last-child,
.report-table td:last-child{ padding-right:22px; }
@media(max-width:768px){
    .main{ width:100%; }
}

@media print{
    .report-table th:first-child,
    .report-table td:first-child{
        position:static !important;
        box-shadow:none !important;
    }

    @page{
        size:A4 landscape;
        margin:8mm;
    }

    body{
        background:#fff !important;
    }

    .topbar,
    .sidebar,
    .page-header,
    .filter-card,
    .generate-btn,
    .print-tip{
        display:none !important;
    }

    .main{
        margin:0 !important;
        padding:0 !important;
        max-width:none !important;
        width:100% !important;
    }

    .report-header{
        border:0;
        border-radius:0;
        box-shadow:none;
        padding:0 0 10px;
    }

    .report-header p{
        display:none;
    }

    .table-wrap{
        border:1px solid #ccc;
        border-radius:0;
        box-shadow:none;
        overflow:visible;
    }

    .report-table{
        min-width:0;
        width:100%;
        font-size:10.5px;
        line-height:1.35;
        table-layout:fixed;
        border-collapse:collapse;
    }

    .report-table th,
    .report-table td{
        padding:6px 5px;
        white-space:normal !important;
        word-break:break-word;
        vertical-align:top;
        border:1px solid #bbb;
    }

    .report-table th{
        background:#123c16 !important;
        color:#fff !important;
        font-size:9.5px;
        text-align:center;
        vertical-align:middle;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }

    .report-table thead{ display:table-header-group; }
    .report-table tr{ page-break-inside:avoid; break-inside:avoid; }
    .report-table tbody tr:nth-child(even) td{
        background:#f4f7f4 !important;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }

    /* column widths (14 columns, total 100%) */
    .report-table th:nth-child(1){ width:10.5%; }  /* Beneficiary */
    .report-table th:nth-child(2){ width:5%; }   /* Gender */
    .report-table th:nth-child(3){ width:7%; } /* Contact */
    .report-table th:nth-child(4){ width:7.5%; }   /* Barangay */
    .report-table th:nth-child(5){ width:6%; }   /* Type */
    .report-table th:nth-child(6){ width:6.5%; }   /* Breed */
    .report-table th:nth-child(7){ width:4%; }   /* Age */
    .report-table th:nth-child(8){ width:6.5%; }   /* Eartag */
    .report-table th:nth-child(9){ width:7.5%; } /* Dist. date */
    .report-table th:nth-child(10){ width:8.5%; }  /* Location */
    .report-table th:nth-child(11){ width:7.5%; }/* Last monitored */
    .report-table th:nth-child(12){ width:6%; }  /* Offspring */
    .report-table th:nth-child(13){ width:10%; }  /* Status */
    .report-table th:nth-child(14){ width:7.5%; }/* Remarks */

    .report-table td:nth-child(2),
    .report-table td:nth-child(7),
    .report-table td:nth-child(12){ text-align:center; }

    .badge-kind,
    .badge-status{
        font-size:9.5px !important;
        padding:2px 6px !important;
        display:inline-block;
    }

    .badge-status{
        white-space:normal !important;
        word-break:normal !important;
        overflow-wrap:break-word;
        border-radius:6px !important;
        text-align:center;
        line-height:1.25;
        max-width:100%;
        box-sizing:border-box;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }

    .report-table td:nth-child(13){ text-align:center; overflow:hidden; }

    .print-only{
        display:block;
        margin-bottom:12px;
    }

    .print-only h1{
        font-size:16px;
        margin:0;
    }

    .print-only h2{
        font-size:19px;
        margin:2px 0 0;
    }

    .print-only p{
        font-size:11px;
        margin:2px 0;
    }

    .print-letterhead{
        display:flex;
        align-items:center;
        justify-content:center;
        gap:18px;
        text-align:center;
        padding-bottom:8px;
    }

    .print-letterhead img{
        width:65px;
        height:65px;
        object-fit:contain;
        flex-shrink:0;
    }

    .print-letterhead-text{
        flex:1;
    }

    .print-letterhead-divider{
        border:none;
        border-top:2px solid #123c16;
        margin:0 0 10px;
    }
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
header.topbar{
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
header.topbar{padding:0 14px;}
.brand .titles h1{font-size:15px;letter-spacing:.8px;}
.brand .titles small{font-size:10px;}
.seal{width:38px;height:38px;}
.brand .titles{padding-left:10px;}
header a.logout{padding:8px 14px;font-size:12.5px;}
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

/* --- generate_report specifics --- */
@media screen{
.main{width:auto;min-width:0;}
.status-chip{border-radius:8px;}
.filter-field input,.filter-field select{border-radius:8px;}
.btn-reset{border-radius:8px;}
.report-header{border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px 12px 0 0;box-shadow:var(--gv-shadow);}
.table-wrap{border:1px solid var(--gv-line);border-top:0;border-radius:0 0 12px 12px;box-shadow:var(--gv-shadow);}
.report-table th{background:#123c16;letter-spacing:.6px;}
.report-table tbody tr:hover td{background:#e8f5e9;}
.generate-btn{border-radius:8px;background:linear-gradient(120deg,#123c16,#1b5e20);}
.generate-btn i{color:var(--gov-gold);}
.report-table th:nth-child(13),.report-table td:nth-child(13){min-width:170px;}
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

<header class="topbar">
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

<aside class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../technician_dashboard.php">
        <i class="fas fa-home"></i> Dashboard
    </a>

    <a href="assign_beneficiaries.php">
        <i class="fas fa-user-check"></i> Assign Beneficiaries
    </a>

    <a href="monitoring.php">
        <i class="fas fa-stethoscope"></i> Monitoring
    </a>

    <a href="monitoring_history.php">
        <i class="fas fa-history"></i> Monitoring History
    </a>

    <a href="farmer_success_rate.php" >
        <i class="fas fa-chart-pie"></i>
        Farmer Success Rate
    </a>

    <a href="mortality_cases.php">
        <i class="fas fa-skull-crossbones"></i> Mortality Cases
    </a>

    <a href="livestock_return.php">
        <i class="fas fa-undo"></i> Livestock Return
    </a>

    <a href="tech_reports.php">
        <i class="fas fa-file-alt"></i> Reports
    </a>

    <a href="generate_report.php" class="active">
        <i class="fas fa-file-pdf"></i> Generate Report
    </a>
</aside>

<main class="main">

    <div class="page-header page-card">
        <div class="page-icon"><i class="fas fa-file-pdf"></i></div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Generate Report</h2>
            <p>Filter and print a complete beneficiary and livestock distribution report.</p>
        </div>
        <span class="role-pill"><i class="fas fa-user-gear"></i> Technician</span>
    </div>

    <form class="filter-card" method="GET" id="filterForm">
        <div class="filter-field">
            <label for="barangay_id">Barangay</label>
            <select name="barangay_id" id="barangay_id">
                <option value="0">All assigned barangays</option>
                <?php foreach ($assignedBarangays as $b): ?>
                    <option value="<?= (int)$b['barangay_id'] ?>" <?= $filterBarangay === (int)$b['barangay_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['barangay_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field">
            <label for="kind">Livestock Kind</label>
            <select name="kind" id="kind">
                <option value="">All kinds</option>
                <?php foreach ($kindOptions as $kVal => $kLabel): ?>
                    <option value="<?= htmlspecialchars($kVal) ?>" <?= $filterKind === $kVal ? 'selected' : '' ?>>
                        <?= htmlspecialchars($kLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field">
            <label for="status">Outcome Status</label>
            <select name="status" id="status">
                <option value="">All statuses</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="returned" <?= $filterStatus === 'returned' ? 'selected' : '' ?>>Returned (Successful)</option>
                <option value="dead" <?= $filterStatus === 'dead' ? 'selected' : '' ?>>Dead / Mortality</option>
            </select>
        </div>
    </form>

    <div class="print-only">
        <div class="print-letterhead">
            <img src="../img/DA.png" alt="Department of Agriculture Logo">

            <div class="print-letterhead-text">
                <h1>Republic of the Philippines</h1>
                <p>Department of Agriculture &mdash; Municipality of Cabatuan</p>
                <h2>PIGOBAO - Technician Distribution Report</h2>
                <p>Complete livestock distribution and beneficiary records</p>
                <?php if ($filterKind !== ''): ?>
                <p>Livestock kind: <?= htmlspecialchars($kindOptions[$filterKind]) ?></p>
                <?php endif; ?>
                <p>Generated: <?= date('F d, Y h:i A') ?></p>
            </div>

            <img src="../img/CABATUAN.png" alt="Municipality of Cabatuan Logo">
        </div>
        <hr class="print-letterhead-divider">
    </div>

    <div class="report-header">
        <div>
            <h3>
                <i class="fas fa-table"></i>
                Beneficiary & Livestock Records
            </h3>
            <p><?= number_format(count($records)) ?> <?= $filterKind !== '' ? htmlspecialchars($kindOptions[$filterKind]) . ' ' : '' ?>distribution records found.</p>
        </div>

        <button type="button" class="generate-btn" onclick="window.print()">
            <i class="fas fa-file-pdf"></i>
            Generate Report
        </button>
    </div>

    <div class="table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Beneficiary</th>
                    <th>Gender</th>
                    <th>Contact</th>
                    <th>Barangay</th>
                    <th>Livestock Type</th>
                    <th>Breed</th>
                    <th>Age</th>
                    <th>Eartag No.</th>
                    <th>Distribution Date</th>
                    <th>Location</th>
                    <th>Last Monitored</th>
                    <th>No. of Offspring</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>

            <tbody>
                <?php if (count($records) > 0): ?>

                    <?php foreach ($records as $d): ?>

                        <?php
                        $fullname = trim(
                            ($d['firstname'] ?? '') . ' ' .
                            ($d['middlename'] ?? '') . ' ' .
                            ($d['lastname'] ?? '')
                        );

                        $livestockType = $d['kind'];

                        $batchNo = $d['distribution_batch_no']
                            ?: $d['livestock_batch_no']
                            ?: 'N/A';

                        $outcomeMap = [
                            'active'   => ['label' => 'Active',              'class' => 'status-active'],
                            'returned' => ['label' => 'Returned (Successful)', 'class' => 'status-returned'],
                            'dead'     => ['label' => 'Dead / Mortality',    'class' => 'status-dead']
                        ];

                        $outcome = $outcomeMap[$d['outcome_status']] ?? ['label' => 'N/A', 'class' => ''];
                        ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= htmlspecialchars($fullname) ?>
                                </strong>
                            </td>

                            <td>
                                <?= htmlspecialchars($d['gender'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($d['contact'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($d['barangay_name'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <span class="badge-kind">
                                    <?= htmlspecialchars(ucfirst($livestockType ?? 'N/A')) ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars($d['breed'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <?= $d['age'] !== null
                                    ? htmlspecialchars($d['age']) . ' yrs'
                                    : 'N/A'
                                ?>
                            </td>

                            <td>
                                <strong>
                                    <?= htmlspecialchars($batchNo) ?>
                                </strong>
                            </td>

                            <td>
                                <?= htmlspecialchars($d['distribution_date']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($d['distribution_location'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <?= !empty($d['last_monitored'])
                                    ? htmlspecialchars(date('M d, Y', strtotime($d['last_monitored'])))
                                    : 'Not yet monitored'
                                ?>
                            </td>

                            <td>
                                <?= $d['total_offspring'] !== null
                                    ? (int)$d['total_offspring']
                                    : 'N/A'
                                ?>
                            </td>

                            <td>
                                <span class="badge-status <?= $outcome['class'] ?>">
                                    <?= htmlspecialchars($outcome['label']) ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars($d['remarks'] ?? 'N/A') ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="14" class="empty">
                            <i class="fas fa-folder-open fa-2x mb-2"></i>
                            <br>
                            No distribution records found for the selected filters.
                        </td>
                    </tr>

                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="gov-footer">
        <div class="inner">
            <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
            <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var filterForm = document.getElementById('filterForm');
    if (!filterForm) return;

    ['barangay_id', 'kind', 'status'].forEach(function (id) {
        var field = document.getElementById(id);
        if (field) {
            field.addEventListener('change', function () {
                filterForm.submit();
            });
        }
    });
});

// Suppress the browser's default print header text (the page title that
// otherwise prints at the top of every page). The URL footer can't be
// removed this way -- that lives in the browser's own "Headers and
// footers" print-dialog toggle -- but blanking the title at least removes
// the "Generate Report - PIGOBAO" line up top.
(function () {
    var originalTitle = document.title;
    window.addEventListener('beforeprint', function () {
        document.title = '\u00A0';
    });
    window.addEventListener('afterprint', function () {
        document.title = originalTitle;
    });
})();
</script>
</body>
</html>