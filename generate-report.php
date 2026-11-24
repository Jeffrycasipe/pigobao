<?php
session_start();
require '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

/* =========================================================
   FETCH ALL DISTRIBUTIONS
========================================================= */
$distributedRows = [];

$distributed = $conn->query("
    SELECT
        d.*,
        b.firstname,
        b.lastname,
        br.barangay_name,
        l.kind,
        t.name AS technician_name
    FROM distributions d
    JOIN beneficiaries b ON d.beneficiary_id = b.beneficiary_id
    JOIN barangays br ON b.barangay_id = br.barangay_id
    JOIN livestock l ON d.livestock_id = l.livestock_id
    LEFT JOIN technicians t ON d.technician_id = t.technician_id
    ORDER BY d.distribution_date DESC
");

if ($distributed) {
    while ($row = $distributed->fetch_assoc()) {
        $distributedRows[] = $row;
    }
}

/* =========================================================
   REPORT SUMMARY
========================================================= */
$totalRecords = count($distributedRows);
$totalQuantity = 0;
$beneficiarySet = [];
$barangaySet = [];

foreach ($distributedRows as $row) {
    $totalQuantity += (int)($row['quantity'] ?? 0);
    $beneficiarySet[$row['beneficiary_id']] = true;
    $barangaySet[$row['barangay_name']] = true;
}

$totalBeneficiaries = count($beneficiarySet);
$totalBarangays = count($barangaySet);
$generatedDate = date('F d, Y');

/* =========================================================
   REPORT FILTERS (Monthly / Annual)
========================================================= */
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Livestock kinds available (used for the Annual filter)
$kindsList = [];
$kindsResult = $conn->query("SELECT DISTINCT kind FROM livestock WHERE kind IS NOT NULL AND kind <> '' ORDER BY kind ASC");
if ($kindsResult) {
    while ($row = $kindsResult->fetch_assoc()) {
        $kindsList[] = $row['kind'];
    }
}

// Always offer Pig / Goat / Carabao, even before any of them has been distributed
foreach (['Pig', 'Goat', 'Carabao'] as $defKind) {
    $kindExists = false;
    foreach ($kindsList as $k) {
        if (strcasecmp($k, $defKind) === 0) { $kindExists = true; break; }
    }
    if (!$kindExists) { $kindsList[] = $defKind; }
}
sort($kindsList, SORT_FLAG_CASE | SORT_STRING);

// Years available (built from actual distribution dates)
$yearsSet = [(int)date('Y') => true];
foreach ($distributedRows as $row) {
    if (!empty($row['distribution_date'])) {
        $yearsSet[(int)date('Y', strtotime($row['distribution_date']))] = true;
    }
}
$yearsList = array_keys($yearsSet);
rsort($yearsList);

// Selected filter values (validated, with sensible defaults)
$reportType = (($_GET['report_type'] ?? 'monthly') === 'annual') ? 'annual' : 'monthly';

$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if (!in_array($selectedYear, $yearsList, true)) {
    $yearsList[] = $selectedYear;
    rsort($yearsList);
}

$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)date('n');
}

// Livestock kind filter: '' = All kinds (default), otherwise one specific kind
$selectedKind = trim($_GET['kind'] ?? '');
if ($selectedKind !== '') {
    $matchedKind = '';
    foreach ($kindsList as $k) {
        if (strcasecmp($k, $selectedKind) === 0) { $matchedKind = $k; break; }
    }
    $selectedKind = $matchedKind;
}
$kindLabel  = $selectedKind !== '' ? ucfirst(strtolower($selectedKind)) : 'All Kinds';
$kindPhrase = $selectedKind !== '' ? ucfirst(strtolower($selectedKind)) : 'All livestock';

/* ---------------------------------------------------------
   MONTHLY REPORT
   -> which beneficiaries received livestock in the chosen
      month/year (e.g. "August 2026")
--------------------------------------------------------- */
$monthlyRows = [];
if ($reportType === 'monthly') {
    foreach ($distributedRows as $row) {
        if (empty($row['distribution_date'])) continue;
        $ts = strtotime($row['distribution_date']);
        if ((int)date('Y', $ts) === $selectedYear && (int)date('n', $ts) === $selectedMonth
            && ($selectedKind === '' || strcasecmp($row['kind'], $selectedKind) === 0)) {
            $monthlyRows[] = $row;
        }
    }
}

/* ---------------------------------------------------------
   ANNUAL REPORT
   -> month-by-month totals for one livestock kind across a
      whole year (e.g. how many pigs were distributed each
      month of 2026)
--------------------------------------------------------- */
$annualBreakdown = [];
for ($m = 1; $m <= 12; $m++) {
    $annualBreakdown[$m] = [
        'quantity'      => 0,
        'records'       => 0,
        'beneficiaries' => [],
        'barangays'     => [],
    ];
}
if ($reportType === 'annual') {
    foreach ($distributedRows as $row) {
        if (empty($row['distribution_date'])) continue;
        $ts = strtotime($row['distribution_date']);
        if ((int)date('Y', $ts) === $selectedYear && ($selectedKind === '' || strcasecmp($row['kind'], $selectedKind) === 0)) {
            $m = (int)date('n', $ts);
            $annualBreakdown[$m]['quantity']     += (int)($row['quantity'] ?? 0);
            $annualBreakdown[$m]['records']      += 1;
            $annualBreakdown[$m]['beneficiaries'][$row['beneficiary_id']] = true;
            $annualBreakdown[$m]['barangays'][$row['barangay_name']] = true;
        }
    }
}

/* ---------------------------------------------------------
   SUMMARY NUMBERS FOR THE ACTIVE FILTER
--------------------------------------------------------- */
if ($reportType === 'monthly') {
    $reportRows = $monthlyRows;
    $reportTotalRecords = count($reportRows);
    $reportTotalQuantity = 0;
    $reportBeneficiarySet = [];
    $reportBarangaySet = [];
    foreach ($reportRows as $row) {
        $reportTotalQuantity += (int)($row['quantity'] ?? 0);
        $reportBeneficiarySet[$row['beneficiary_id']] = true;
        $reportBarangaySet[$row['barangay_name']] = true;
    }
    $reportTotalBeneficiaries = count($reportBeneficiarySet);
    $reportTotalBarangays = count($reportBarangaySet);
} else {
    $reportTotalQuantity = 0;
    $reportTotalRecords = 0;
    $annualBeneficiarySet = [];
    $annualBarangaySet = [];
    foreach ($annualBreakdown as $m => $data) {
        $reportTotalQuantity += $data['quantity'];
        $reportTotalRecords  += $data['records'];
        foreach ($data['beneficiaries'] as $bid => $v) {
            $annualBeneficiarySet[$bid] = true;
        }
        foreach ($data['barangays'] as $bname => $v) {
            $annualBarangaySet[$bname] = true;
        }
    }
    $reportTotalBeneficiaries = count($annualBeneficiarySet);
    $reportTotalBarangays = count($annualBarangaySet);
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
:root {
    --pg-forest-900: #123c16;
    --pg-forest-800: #1b5e20;
    --pg-forest-600: #2e7d32;
    --pg-forest-300: #a5d6a7;
    --pg-forest-100: #e8f5e9;
    --pg-canvas: #f3f6f4;
    --pg-ink: #233126;
    --pg-ink-soft: #526056;
    --pg-line: #e2e9e3;
    --pg-amber: #c98a1f;
    --pg-sky: #3f7cac;
    --pg-clay: #c1543f;
    --shadow-soft: 0 1px 2px rgba(18,60,22,.04), 0 8px 24px -12px rgba(18,60,22,.16);
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: 'Poppins', sans-serif;
}

body {
    min-height: 100vh;
    font-size: 15px;
    line-height: 1.55;
    -webkit-font-smoothing: antialiased;
    background: var(--pg-canvas);
    background-image:
        radial-gradient(1100px 480px at 100% -10%, rgba(46,125,50,.07), transparent 60%),
        radial-gradient(700px 380px at -5% 100%, rgba(27,94,32,.05), transparent 55%);
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
    box-shadow: 0 2px 14px rgba(10,40,12,.25);
}

header .brand {
    display: flex;
    align-items: center;
    gap: 12px;
}

header .brand .mark {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    background: rgba(255,255,255,.14);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #eafbea;
    border: 1px solid rgba(255,255,255,.22);
}

header h1 {
    font-size: 19px;
    font-weight: 600;
    letter-spacing: .2px;
    margin: 0;
}

header .brand small {
    display: block;
    font-size: 12px;
    color: rgba(255,255,255,.65);
    text-transform: uppercase;
    letter-spacing: .4px;
}

header .logout {
    color: var(--pg-forest-800);
    background: #fff;
    padding: 8px 20px;
    border-radius: 20px;
    font-weight: 600;
    text-decoration: none;
    font-size: 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,.12);
    transition: .2s ease;
}

header .logout:hover {
    background: var(--pg-forest-100);
    transform: translateY(-1px);
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
    z-index: 1000;
    box-shadow: inset -1px 0 0 rgba(255,255,255,.06);
}

.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    color: rgba(255,255,255,.88);
    padding: 13px 22px;
    text-decoration: none;
    margin: 2px 12px 4px;
    border-radius: 9px;
    border-left: 3px solid transparent;
    font-size: 15.5px;
    font-weight: 500;
    transition: .2s ease;
}

.sidebar a i {
    width: 18px;
    text-align: center;
    font-size: 15px;
}

.sidebar a:hover {
    background: rgba(255,255,255,.12);
    transform: translateX(2px);
}

.sidebar a.active {
    background: var(--pg-forest-300);
    color: var(--pg-forest-900);
    border-left: 3px solid var(--pg-forest-900);
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0,0,0,.18);
}

/* MAIN CONTENT */
.main-content {
    margin-left: 240px;
    padding: 88px 38px 30px;
    min-height: 100vh;
    max-width: none;
    width: calc(100% - 240px);
}

/* PAGE HEADER */
.page-card {
    background: #fff;
    border-radius: 15px;
    padding: 18px 23px;
    box-shadow: var(--shadow-soft);
    border: 1px solid var(--pg-line);
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 18px;
    position: relative;
    overflow: hidden;
}

.page-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(90deg, var(--pg-forest-800), var(--pg-forest-300));
}

.page-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    flex: none;
    background: linear-gradient(145deg, var(--pg-forest-600), var(--pg-forest-900));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 17px;
}

.page-info h2 {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 24px;
    font-weight: 700;
    color: var(--pg-forest-900);
    margin: 0 0 2px;
}

.page-info p {
    font-size: 14.5px;
    color: var(--pg-ink-soft);
    margin: 0;
}

/* SECTION LABEL */
.section-label {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .5px;
    text-transform: uppercase;
    color: var(--pg-ink-soft);
    margin: 0 0 8px 2px;
}

/* CHART GRID */
.chart-grid {
    display: grid;
    grid-template-columns: minmax(300px, .85fr) minmax(450px, 1.5fr);
    gap: 16px;
    margin-bottom: 19px;
}

.chart-card.full-width {
    margin-bottom: 19px;
}

.chart-card {
    background: #fff;
    border: 1px solid var(--pg-line);
    border-radius: 15px;
    box-shadow: var(--shadow-soft);
    padding: 17px;
    position: relative;
    min-width: 0;
}

.chart-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    border-radius: 15px 15px 0 0;
    background: linear-gradient(90deg, var(--pg-forest-800), var(--pg-forest-300));
}

.chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
    padding-top: 2px;
}

.chart-title {
    display: flex;
    align-items: center;
    gap: 9px;
}

.chart-title-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--pg-forest-100);
    color: var(--pg-forest-800);
    font-size: 13px;
}

.chart-header h3 {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: var(--pg-forest-900);
    margin: 0;
}

.chart-header span {
    display: block;
    font-size: 12.5px;
    color: var(--pg-ink-soft);
    margin-top: 1px;
}

.chart-container {
    position: relative;
    width: 100%;
    height: 275px;
}

.chart-container.small {
    height: 275px;
}

/* HEATMAP (Year x Month) */
.heatmap-wrap {
    width: 100%;
    overflow-x: auto;
}

.heatmap-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 4px;
    font-size: 14px;
}

.heatmap-table thead th {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--pg-ink-soft);
    text-transform: uppercase;
    letter-spacing: .3px;
    padding: 4px 2px;
    text-align: center;
}

.heatmap-year-col {
    text-align: left !important;
    white-space: nowrap;
    padding-right: 10px !important;
    font-size: 15px !important;
    font-weight: 700;
    color: var(--pg-ink);
}

.heatmap-year-col small {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--pg-ink-soft);
    text-transform: none;
    letter-spacing: 0;
}

.heatmap-cell {
    position: relative;
    min-width: 30px;
    height: 34px;
    border-radius: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 13.5px;
    transition: transform .15s ease;
}

.heatmap-cell:hover {
    transform: scale(1.08);
}

.heatmap-peak {
    box-shadow: 0 0 0 2px var(--pg-amber);
}

.heatmap-star {
    position: absolute;
    top: -6px;
    right: -6px;
    font-size: 9px;
    color: var(--pg-amber);
    background: #fff;
    border-radius: 50%;
    width: 14px;
    height: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}

.heatmap-empty {
    padding: 30px 0;
    text-align: center;
    color: var(--pg-ink-soft);
    font-size: 15px;
}

/* REPORT TOOLBAR */
.report-toolbar {
    background: #fff;
    border: 1px solid var(--pg-line);
    border-radius: 15px;
    box-shadow: var(--shadow-soft);
    padding: 13px 17px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.toolbar-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.toolbar-icon {
    width: 35px;
    height: 35px;
    border-radius: 9px;
    background: var(--pg-forest-100);
    color: var(--pg-forest-800);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.toolbar-info strong {
    display: block;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 16px;
    color: var(--pg-forest-900);
}

.toolbar-info small {
    display: block;
    font-size: 12.5px;
    color: var(--pg-ink-soft);
    margin-top: 1px;
}

.toolbar-buttons {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

/* FILTER CARD */
.filter-card {
    background: #fff;
    border: 1px solid var(--pg-line);
    border-radius: 15px;
    box-shadow: var(--shadow-soft);
    padding: 16px 20px;
    margin-bottom: 12px;
}

.filter-row {
    display: flex;
    align-items: flex-end;
    gap: 14px;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 170px;
}

.filter-group label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--pg-forest-900);
    text-transform: uppercase;
    letter-spacing: .3px;
}

.filter-group select {
    padding: 10px 14px;
    border: 1px solid var(--pg-line);
    border-radius: 8px;
    font-size: 15px;
    color: var(--pg-ink);
    background: var(--pg-canvas);
    font-family: 'Poppins', sans-serif;
    outline: none;
    transition: .2s ease;
}

.filter-group select:focus {
    border-color: var(--pg-forest-600);
    background: #fff;
}

.filter-submit {
    min-width: auto;
}

.filter-submit .pg-btn {
    padding: 10px 20px;
    font-size: 13.5px;
}

.report-type-note {
    font-size: 13px;
    color: var(--pg-ink-soft);
    margin-top: 10px;
}

/* BUTTONS */
.pg-btn {
    border: none;
    border-radius: 8px;
    padding: 8px 13px;
    font-size: 11px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    cursor: pointer;
    transition: .2s ease;
    text-decoration: none;
}

.pg-btn-primary {
    background: linear-gradient(120deg, var(--pg-forest-800), var(--pg-forest-600));
    color: #fff;
}

.pg-btn-primary:hover {
    color: #fff;
    transform: translateY(-1px);
}

.pg-btn-pdf {
    background: linear-gradient(120deg, #a63d2d, var(--pg-clay));
    color: #fff;
}

.pg-btn-pdf:hover {
    color: #fff;
    transform: translateY(-1px);
}

/* REPORT CARD */
.report-card {
    background: #fff;
    border: 1px solid var(--pg-line);
    border-radius: 15px;
    box-shadow: var(--shadow-soft);
    overflow: hidden;
}

.report-card-header {
    padding: 13px 17px;
    border-bottom: 1px solid var(--pg-line);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.report-card-header h3 {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 17px;
    font-weight: 700;
    color: var(--pg-forest-900);
    margin: 0;
}

.report-card-header p {
    font-size: 12.5px;
    color: var(--pg-ink-soft);
    margin: 2px 0 0;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.report-table thead th {
    background: var(--pg-forest-900);
    color: #eafbea;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 14px 14px;
    text-align: left;
    white-space: nowrap;
}

.report-table tbody td {
    padding: 13px 14px;
    border-bottom: 1px solid var(--pg-line);
    color: var(--pg-ink);
    white-space: nowrap;
    vertical-align: middle;
}

.report-table tbody tr:hover {
    background: var(--pg-forest-100);
}

.report-table tbody tr:last-child td {
    border-bottom: none;
}

/* BADGES */
.id-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 27px;
    padding: 4px 8px;
    border-radius: 6px;
    background: var(--pg-forest-100);
    color: var(--pg-forest-800);
    font-size: 11.5px;
    font-weight: 700;
}

.kind-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 11px;
    border-radius: 999px;
    background: #fdf3e3;
    color: var(--pg-amber);
    font-size: 12px;
    font-weight: 600;
}

.quantity-badge {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    color: var(--pg-forest-900);
}

.empty-report {
    padding: 35px 20px !important;
    text-align: center;
    color: var(--pg-ink-soft);
    font-style: italic;
}

/* RESPONSIVE */
@media (max-width: 1100px) {
    .chart-grid {
        grid-template-columns: 1fr;
    }
}

@media(max-width:900px){
    .sidebar{ width:200px; }
    .main-content{ margin-left:200px; width:calc(100% - 200px); padding:85px 24px 25px; }
}

@media (max-width: 768px) {
    header {
        padding: 0 18px;
    }

    header .brand small {
        display: none;
    }

    header .logout {
        padding: 7px 14px;
        font-size: 13.5px;
    }

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
    .sidebar a{ margin:2px; padding:9px 12px; font-size: 12.5px; }
    .sidebar a:hover{ transform:none; }

    .main-content {
        margin-left: 0;
        width: 100%;
        padding: 20px 16px 25px;
    }

    .page-card {
        padding: 16px;
    }

    .chart-card {
        padding: 15px;
    }

    .chart-container,
    .chart-container.small {
        height: 250px;
    }

    .report-toolbar {
        align-items: flex-start;
    }

    .toolbar-buttons {
        width: 100%;
    }

    .toolbar-buttons .pg-btn {
        flex: 1;
    }

    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-group {
        width: 100%;
    }

    .filter-submit .pg-btn {
        width: 100%;
    }
}


/* PRINT DOCUMENT DESIGN */
.print-tip{
    background:var(--pg-forest-100); color:var(--pg-forest-900);
    border:1px solid var(--pg-forest-300); border-left:4px solid var(--pg-forest-600);
    border-radius:10px; padding:12px 16px; margin:14px 0 0; font-size:13px;
    display:flex; align-items:flex-start; gap:10px; line-height:1.5;
}
.print-tip i{ margin-top:2px; }

.print-report-header,
.print-title-block,
.print-meta,
.print-summary,
.print-certification,
.print-footer {
    display: none;
}

@media print {
    @page {
        size: A4 portrait;
        margin: 12mm;
    }

    html,
    body {
        width: 100%;
        min-height: 100%;
        background: #fff !important;
        color: #111 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    body {
        font-family: Arial, Helvetica, sans-serif !important;
    }

    header,
    .sidebar,
    .page-card,
    .chart-grid,
    .report-toolbar,
    .filter-card,
    .section-label,
    .print-tip {
        display: none !important;
    }

    .main-content {
        margin: 0 !important;
        padding: 0 !important;
        max-width: none !important;
        width: 100% !important;
    }

    #reportTable {
        display: block !important;
        width: 100% !important;
    }

    .print-report-header {
        display: grid !important;
        grid-template-columns: 78px 1fr 78px;
        align-items: center;
        gap: 12px;
        width: 100%;
        padding-bottom: 8px;
        border-bottom: 2px solid #123c16;
    }

    .print-logo-box {
        width: 82px;
        height: 82px;
        display: flex !important;
        align-items: center;
        justify-content: center;
        margin: auto;
        background: transparent !important;
        border: 0 !important;
    }

    .print-logo-box img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
        object-position: center;
    }

    .print-logo-box.municipality-logo img {
        width: 78px;
        height: 78px;
    }

    .print-logo-box.da-logo {
        width: 74px;
        height: 66px;
    }

    .print-logo-box.da-logo img {
        width: 74px;
        height: 66px;
    }

    .print-government {
        text-align: center;
        line-height: 1.35;
        color: #111;
        font-size: 10px;
        letter-spacing: .2px;
    }

    .print-government strong {
        display: block;
        font-size: 12px;
        margin-top: 2px;
    }

    .print-government b {
        display: block;
        font-size: 17px;
        letter-spacing: 1px;
        color: #123c16;
        margin-top: 3px;
    }

    .print-government small {
        display: block;
        font-size: 8px;
        color: #555;
        margin-top: 1px;
    }

    .print-title-block {
        display: block !important;
        text-align: center;
        padding: 10px 0 7px;
    }

    .print-title-block h1 {
        margin: 0;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 17px;
        font-weight: 700;
        letter-spacing: .8px;
        color: #123c16;
    }

    .print-title-block p {
        margin: 2px 0 0;
        font-size: 9px;
        color: #555;
    }

    .print-meta {
        display: grid !important;
        grid-template-columns: 1.5fr 1fr 1fr;
        border: 1px solid #bfc8c1;
        margin-bottom: 8px;
        font-size: 9px;
    }

    .print-meta div {
        padding: 6px 8px;
        border-right: 1px solid #bfc8c1;
    }

    .print-meta div:last-child {
        border-right: 0;
    }

    .print-meta.four {
        grid-template-columns: 1.4fr 1fr 1fr 1fr;
    }

    .print-summary {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr);
        gap: 7px;
        margin-bottom: 9px;
    }

    .print-summary > div {
        border: 1px solid #bfc8c1;
        text-align: center;
        padding: 6px 5px;
        background: #f7faf7 !important;
    }

    .print-summary span {
        display: block;
        font-size: 7.5px;
        text-transform: uppercase;
        color: #555;
        letter-spacing: .2px;
    }

    .print-summary strong {
        display: block;
        margin-top: 2px;
        font-size: 14px;
        color: #123c16;
    }

    .report-card {
        display: block !important;
        width: 100% !important;
        box-shadow: none !important;
        border: 1px solid #777 !important;
        border-radius: 0 !important;
        overflow: visible !important;
    }

    .report-card-header {
        display: block !important;
        padding: 7px 9px !important;
        background: #123c16 !important;
        color: #fff !important;
        border-bottom: 0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .report-card-header h3 {
        color: #fff !important;
        font-size: 11px !important;
        text-transform: uppercase;
        margin: 0 !important;
    }

    .report-card-header p,
    .report-card-header .kind-badge {
        display: none !important;
    }

    .table-responsive {
        overflow: visible !important;
        width: 100% !important;
    }

    .report-table {
        width: 100% !important;
        font-size: 7.5px !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
    }

    .report-table thead th {
        background: #123c16 !important;
        color: #fff !important;
        font-size: 7px !important;
        padding: 4px 4px !important;
        border: 1px solid #49604d !important;
        white-space: normal !important;
        word-break: break-word !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .report-table tbody td {
        padding: 4px 4px !important;
        border: 1px solid #9aa39d !important;
        color: #111 !important;
        white-space: normal !important;
        word-break: break-word !important;
        vertical-align: middle !important;
    }

    .report-table tbody tr:nth-child(even) {
        background: #f7f9f7 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .kind-badge {
        background: transparent !important;
        color: #111 !important;
        padding: 0 !important;
        border-radius: 0 !important;
        font-size: 7.5px !important;
        font-weight: 600 !important;
    }

    .quantity-badge {
        font-size: 7.5px !important;
        color: #111 !important;
    }

    .empty-report {
        padding: 20px !important;
    }

    .print-certification {
        display: block !important;
        margin-top: 12px;
        border-top: 1px solid #777;
        padding-top: 8px;
        font-size: 8.5px;
        line-height: 1.4;
    }

    .print-certification > p {
        margin: 0 0 4px;
    }

    .signature-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr;
        gap: 80px;
        margin-top: 25px;
    }

    .signature-grid > div {
        text-align: center;
        font-size: 8.5px;
    }

    .signature-line {
        width: 75%;
        margin: 0 auto 4px;
        border-top: 1px solid #222;
    }

    .signature-grid strong,
    .signature-grid span {
        display: block;
    }

    .signature-grid strong {
        font-size: 9px;
    }

    .signature-grid span {
        color: #555;
    }

    .print-footer {
        display: flex !important;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid #bfc8c1;
        margin-top: 12px;
        padding-top: 5px;
        font-size: 7.5px;
        color: #555;
    }

    .pg-btn,
    button,
    a {
     -webkit-print-color-adjust: exact;
    }

    .report-table tr {
        page-break-inside: avoid;
    }
}

/* PRINT */
@media print {
    /* Main print layout is defined in the PRINT DOCUMENT DESIGN block above. */
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
            <small>Generate Report &bull; Livestock Distribution Program</small>
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
    <a href="./tracking.php"><i class="fas fa-eye"></i> Livestock Tracking</a>
    <a href="./dispersal.php"><i class="fas fa-people-carry-box"></i> Dispersal</a>
    <a href="./farmer-success-rate.php" ><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="./reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="./generate-report.php"class="active"><i class="fas fa-file-alt"></i> Generate Report</a>
</div>

<div class="main-content">

    <div class="page-card">
        <div class="page-icon"><i class="fas fa-file-alt"></i></div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Generate Report</h2>
            <p>Review, print, or export the complete livestock distribution report.</p>
        </div>
        <span class="role-pill"><i class="fas fa-shield-halved"></i> Administrator</span>
    </div>

    <form method="GET" class="filter-card" id="reportFilterForm">
        <div class="filter-row">
            <div class="filter-group">
                <label for="reportTypeSelect">Report Type</label>
                <select name="report_type" id="reportTypeSelect" onchange="toggleReportFilters()">
                    <option value="monthly" <?= $reportType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="annual" <?= $reportType === 'annual' ? 'selected' : '' ?>>Annual</option>
                </select>
            </div>

            <div class="filter-group" id="monthFilterGroup">
                <label for="monthSelect">Month</label>
                <select name="month" id="monthSelect">
                    <?php foreach ($monthNames as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $selectedMonth == $num ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="yearSelect">Year</label>
                <select name="year" id="yearSelect">
                    <?php foreach ($yearsList as $y): ?>
                        <option value="<?= $y ?>" <?= $selectedYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group" id="kindFilterGroup">
                <label for="kindSelect">Livestock Kind</label>
                <select name="kind" id="kindSelect">
                    <option value="">All kinds</option>
                    <?php foreach ($kindsList as $k): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $selectedKind === $k ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(strtolower($k))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group filter-submit">
                <button type="submit" class="pg-btn pg-btn-primary">
                    <i class="fas fa-filter"></i>Generate
                </button>
            </div>
        </div>

        <p class="report-type-note">
            <?php if ($reportType === 'monthly'): ?>
                Showing beneficiaries who received <?= $selectedKind !== '' ? '<strong>' . htmlspecialchars($kindLabel) . '</strong>' : 'livestock' ?> in <strong><?= $monthNames[$selectedMonth] . ' ' . $selectedYear ?></strong>.
            <?php else: ?>
                Showing <strong><?= htmlspecialchars($kindPhrase) ?></strong> distributed each month of <strong><?= $selectedYear ?></strong>.
            <?php endif; ?>
        </p>
    </form>

    <div class="report-toolbar">
        <div class="toolbar-info">
            <div class="toolbar-icon"><i class="fas fa-file-lines"></i></div>
            <div>
                <strong>Distribution Records</strong>
                <small>Complete distribution report, ready to print or export.</small>
            </div>
        </div>

        <div class="toolbar-buttons">
            <button type="button"
                    class="pg-btn pg-btn-pdf"
                    onclick="window.print()">
                <i class="fas fa-file-pdf"></i>Export to PDF
            </button>
        </div>
    </div>

    <div id="reportTable">
        <div class="print-report-header">
            <div class="print-logo-box municipality-logo">
                <img src="../img/CABATUAN.png" alt="Municipality of Cabatuan Logo">
            </div>
            <div class="print-government">
                <div>REPUBLIC OF THE PHILIPPINES</div>
                <div>PROVINCE OF ILOILO</div>
                <div>MUNICIPALITY OF CABATUAN</div>
                <strong>MUNICIPAL AGRICULTURE OFFICE</strong>
            </div>
            <div class="print-logo-box da-logo">
                <img src="../img/DA.png" alt="Department of Agriculture Western Visayas Logo">
            </div>
        </div>

        <div class="print-title-block">
            <h1>LIVESTOCK DISTRIBUTION REPORT</h1>
            <?php if ($reportType === 'monthly'): ?>
                <p>Monthly Report — <?= htmlspecialchars($monthNames[$selectedMonth] . ' ' . $selectedYear) ?><?= $selectedKind !== '' ? ' • ' . htmlspecialchars($kindLabel) : '' ?></p>
            <?php else: ?>
                <p>Annual Report — <?= htmlspecialchars($kindLabel) ?> Distribution (<?= $selectedYear ?>)</p>
            <?php endif; ?>
        </div>

        <div class="print-meta<?= $reportType === 'monthly' ? ' four' : '' ?>">
            <?php if ($reportType === 'monthly'): ?>
                <div><strong>Reporting Period:</strong> <?= htmlspecialchars($monthNames[$selectedMonth] . ' ' . $selectedYear) ?></div>
                <div><strong>Barangay:</strong> All Barangays</div>
                <div><strong>Livestock Kind:</strong> <?= htmlspecialchars($kindLabel) ?></div>
            <?php else: ?>
                <div><strong>Reporting Period:</strong> January 1, <?= $selectedYear ?> – December 31, <?= $selectedYear ?></div>
                <div><strong>Livestock Kind:</strong> <?= htmlspecialchars($kindLabel) ?></div>
            <?php endif; ?>
            <div><strong>Date Generated:</strong> <?= $generatedDate ?></div>
        </div>

        <div class="print-summary">
            <div>
                <span>Total Distribution Records</span>
                <strong><?= $reportTotalRecords ?></strong>
            </div>
            <div>
                <span>Total Livestock Distributed</span>
                <strong><?= $reportTotalQuantity ?></strong>
            </div>
            <div>
                <span>Total Beneficiaries</span>
                <strong><?= $reportTotalBeneficiaries ?></strong>
            </div>
            <div>
                <span>Barangays Covered</span>
                <strong><?= $reportTotalBarangays ?></strong>
            </div>
        </div>

        <?php if ($reportType === 'monthly'): ?>
        <div class="report-card">

            <div class="report-card-header">
                <div>
                    <h3>Distribution Record</h3>
                    <p>Beneficiaries who received livestock in <?= htmlspecialchars($monthNames[$selectedMonth] . ' ' . $selectedYear) ?>.</p>
                </div>

                <span class="kind-badge">
                    <?= count($reportRows) ?> Records
                </span>
            </div>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Beneficiary</th>
                            <th>Kind</th>
                            <th>Quantity</th>
                            <th>Date</th>
                            <th>Technician</th>
                            <th>Location</th>
                            <th>Eartag No</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (count($reportRows) > 0): ?>

                        <?php foreach ($reportRows as $d): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars(
                                        $d['firstname'] . ' ' . $d['lastname']
                                    ) ?>
                                </td>

                                <td>
                                    <span class="kind-badge">
                                        <?= htmlspecialchars($d['kind']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="quantity-badge">
                                        <?= (int)$d['quantity'] ?>
                                    </span>
                                </td>

                                <td>
                                    <?= htmlspecialchars($d['distribution_date']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($d['technician_name'] ?? 'N/A') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($d['distribution_location'] ?? '') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($d['batch_no'] ?? '') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="7" class="empty-report">
                                <i class="fas fa-folder-open"
                                   style="font-size:26px;display:block;margin-bottom:8px;"></i>
                                No distribution records found for <?= htmlspecialchars($monthNames[$selectedMonth] . ' ' . $selectedYear) ?>.
                            </td>
                        </tr>

                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="report-card">

            <div class="report-card-header">
                <div>
                    <h3>Annual Distribution Summary</h3>
                    <p><?= htmlspecialchars($kindPhrase) ?> distributed per month, <?= $selectedYear ?>.</p>
                </div>

                <span class="kind-badge">
                    <?= $reportTotalRecords ?> Records
                </span>
            </div>

            <div class="table-responsive">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Total Livestock</th>
                            <th>Barangays Covered</th>
                            <th>Beneficiaries Served</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($kindsList)): ?>

                        <tr>
                            <td colspan="4" class="empty-report">
                                <i class="fas fa-folder-open"
                                   style="font-size:26px;display:block;margin-bottom:8px;"></i>
                                No livestock kinds found in the system.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($annualBreakdown as $m => $data): ?>
                            <tr>
                                <td><?= htmlspecialchars($monthNames[$m]) ?></td>
                                <td>
                                    <span class="quantity-badge">
                                        <?= (int)$data['quantity'] ?>
                                    </span>
                                </td>
                                <td><?= count($data['barangays']) ?></td>
                                <td><?= count($data['beneficiaries']) ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr>
                            <td><strong>Total</strong></td>
                            <td><strong><?= $reportTotalQuantity ?></strong></td>
                            <td><strong><?= $reportTotalBarangays ?></strong></td>
                            <td><strong><?= $reportTotalBeneficiaries ?></strong></td>
                        </tr>

                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="print-certification">
            <p><strong>CERTIFICATION</strong></p>
            <p>
                This is to certify that the information contained in this report is a true
                and accurate record of livestock distributions recorded in the PIGOBAO system.
            </p>

            <div class="signature-grid">
                <div>
                    <div class="signature-line"></div>
                    <strong>Prepared by</strong>
                    <span>Authorized Personnel / Technician</span>
                    <span>Date: ____________________</span>
                </div>
                <div>
                    <div class="signature-line"></div>
                    <strong>Checked / Verified by</strong>
                    <span>Municipal Agriculture Office</span>
                    <span>Date: ____________________</span>
                </div>
            </div>
        </div>

        <div class="print-footer">
            <span>PIGOBAO • Municipal Agriculture Office • Cabatuan, Iloilo</span>
            <span>Page 1 of 1</span>
        </div>
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
function toggleReportFilters() {
    var type = document.getElementById('reportTypeSelect').value;
    var monthGroup = document.getElementById('monthFilterGroup');
    var kindGroup = document.getElementById('kindFilterGroup');

    monthGroup.style.display = (type === 'monthly') ? 'flex' : 'none';
    kindGroup.style.display = 'flex';   // kind filter applies to both Monthly and Annual
}

document.addEventListener('DOMContentLoaded', toggleReportFilters);

// Suppress the browser's default print header text (the page title that
// otherwise prints at the top of every page). We can't remove the URL
// footer this way -- that one lives in the browser's own "Headers and
// footers" print-dialog toggle -- but blanking the title at least removes
// the "Generate Report - PIGOBAO" line up top.
(function () {
    var originalTitle = document.title;
    window.addEventListener('beforeprint', function () {
        document.title = '\u00A0'; // non-breaking space; most browsers reject a fully empty title
    });
    window.addEventListener('afterprint', function () {
        document.title = originalTitle;
    });
})();
</script>

</body>
</html>