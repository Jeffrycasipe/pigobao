<?php
session_start();
require '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

/* =========================================================
   PIE CHART DATA
   (livestock type/kind lives on the `livestock` table, not on
   `distributions` — so we join to it rather than selecting a
   `livestock_type` column that doesn't exist on distributions)

   Broken down PER YEAR as well, so the pie chart can follow
   whichever year is selected on the line chart's year filter.
========================================================= */
$dataCounts = [
    'goat' => 0,
    'pig' => 0,
    'carabao' => 0
];

$yearKindTotals = [];

$counts = $conn->query("
    SELECT
        YEAR(d.distribution_date) AS yr,
        LOWER(TRIM(l.kind)) AS livestock_type,
        COALESCE(SUM(d.quantity), 0) AS cnt
    FROM distributions d
    JOIN livestock l ON d.livestock_id = l.livestock_id
    GROUP BY YEAR(d.distribution_date), LOWER(TRIM(l.kind))
");

if ($counts) {
    while ($row = $counts->fetch_assoc()) {
        $yr = (string)(int)$row['yr'];
        $type = strtolower(trim($row['livestock_type']));
        $count = (int)$row['cnt'];

        if (!isset($dataCounts[$type])) {
            continue;
        }

        // running total across all years (used for the "All Years" view)
        $dataCounts[$type] += $count;

        if (!isset($yearKindTotals[$yr])) {
            $yearKindTotals[$yr] = ['goat' => 0, 'pig' => 0, 'carabao' => 0];
        }
        $yearKindTotals[$yr][$type] += $count;
    }
}

/* All-time total across every kind — shown in the pie chart's
   side box regardless of which year is selected. */
$allTimeTotalLivestock = array_sum($dataCounts);

/* Actual years that have distribution records (captured before the
   zero-padding loop below fills in every year from 2020 onward), so
   the All-Time Total box can explain *which* years it's adding up
   instead of just showing a flat, unchanging number. */
$allTimeDataYears = array_map('intval', array_keys($yearKindTotals));
sort($allTimeDataYears);
$allTimeFirstYear = !empty($allTimeDataYears) ? min($allTimeDataYears) : (int)date('Y');
$allTimeLastYear  = !empty($allTimeDataYears) ? max($allTimeDataYears) : (int)date('Y');
$allTimeYearRange = ($allTimeFirstYear === $allTimeLastYear)
    ? (string)$allTimeFirstYear
    : $allTimeFirstYear . '–' . $allTimeLastYear;

/* Share of the all-time total, in % (one decimal) */
function pctOf($n, $total) {
    return $total > 0 ? round(($n / $total) * 100, 1) : 0;
}

/* =========================================================
   LINE CHART DATA
   One line per livestock type (goat / pig / carabao), plotted
   across the 12 months, for every year — the JS then filters by
   the selected year / month.
========================================================= */
$yearMonthTotals = [];       // all kinds together (used for the year list)
$yearMonthKindTotals = [];   // year => kind => month => qty  (one line per livestock type)

function emptyKindMonths() {
    return [
        'goat'    => array_fill(1, 12, 0),
        'pig'     => array_fill(1, 12, 0),
        'carabao' => array_fill(1, 12, 0)
    ];
}

$lineData = $conn->query("
    SELECT
        YEAR(d.distribution_date) AS yr,
        MONTH(d.distribution_date) AS mn,
        LOWER(TRIM(l.kind)) AS kind,
        COALESCE(SUM(d.quantity), 0) AS cnt
    FROM distributions d
    JOIN livestock l ON d.livestock_id = l.livestock_id
    GROUP BY
        YEAR(d.distribution_date),
        MONTH(d.distribution_date),
        LOWER(TRIM(l.kind))
    ORDER BY YEAR(d.distribution_date), MONTH(d.distribution_date)
");

if ($lineData) {
    while ($row = $lineData->fetch_assoc()) {
        $yr = (string)(int)$row['yr'];
        $mn = (int)$row['mn'];
        $kind = strtolower(trim($row['kind']));
        $count = (int)$row['cnt'];

        if (!isset($yearMonthTotals[$yr])) {
            $yearMonthTotals[$yr] = array_fill(1, 12, 0);
        }
        $yearMonthTotals[$yr][$mn] += $count;

        if (isset($yearMonthKindTotals[$yr]) === false) {
            $yearMonthKindTotals[$yr] = emptyKindMonths();
        }
        if (isset($yearMonthKindTotals[$yr][$kind])) {
            $yearMonthKindTotals[$yr][$kind][$mn] += $count;
        }
    }
}

ksort($yearMonthTotals);

/* DAILY data (sparse): year => month => kind => day => qty.
   Used when BOTH a year and a month are picked, so the line graph
   shows the days of that month for that exact year only. */
$yearMonthDayKind = [];
$dayData = $conn->query("
    SELECT
        YEAR(d.distribution_date) AS yr,
        MONTH(d.distribution_date) AS mn,
        DAY(d.distribution_date) AS dy,
        LOWER(TRIM(l.kind)) AS kind,
        COALESCE(SUM(d.quantity), 0) AS cnt
    FROM distributions d
    JOIN livestock l ON d.livestock_id = l.livestock_id
    GROUP BY
        YEAR(d.distribution_date),
        MONTH(d.distribution_date),
        DAY(d.distribution_date),
        LOWER(TRIM(l.kind))
");
if ($dayData) {
    while ($row = $dayData->fetch_assoc()) {
        $k = strtolower(trim($row['kind']));
        if (!in_array($k, ['goat', 'pig', 'carabao'], true)) continue;
        $yearMonthDayKind[(string)(int)$row['yr']][(int)$row['mn']][$k][(int)$row['dy']] = (int)$row['cnt'];
    }
}

/* Make sure the year dropdown always starts at 2020, even for
   years that don't have any distribution records yet — those
   years just show up with all-zero months. */
$startYear = 2020;
$currentYear = (int)date('Y');
$latestDataYear = !empty($yearMonthTotals) ? max(array_map('intval', array_keys($yearMonthTotals))) : $currentYear;
$endYear = max($currentYear, $latestDataYear);

for ($yr = $startYear; $yr <= $endYear; $yr++) {
    $yrKey = (string)$yr;
    if (!isset($yearMonthTotals[$yrKey])) {
        $yearMonthTotals[$yrKey] = array_fill(1, 12, 0);
    }
    if (!isset($yearKindTotals[$yrKey])) {
        $yearKindTotals[$yrKey] = ['goat' => 0, 'pig' => 0, 'carabao' => 0];
    }
    if (!isset($yearMonthKindTotals[$yrKey])) {
        $yearMonthKindTotals[$yrKey] = emptyKindMonths();
    }
}

ksort($yearMonthTotals);
ksort($yearKindTotals);
ksort($yearMonthKindTotals);

$chartYears = array_keys($yearMonthTotals);

/* =========================================================
   STATUS CHART DATA (Success Rate vs Death), PER YEAR
   Grouped by the year of d.distribution_date, split by d.status:
     active   = still alive with the beneficiary (Buhi)
     returned = returned (contract completed)
     death    = dead (Napatay)
   Example: 2025 -> 5 distributed = 3 returned + 2 dead.
   Success rate = (active + returned) / total, i.e. not lost to death.
========================================================= */
$yearStatusTotals = [];

$statusData = $conn->query("
    SELECT
        YEAR(d.distribution_date) AS yr,
        d.status,
        COALESCE(SUM(d.quantity), 0) AS cnt
    FROM distributions d
    GROUP BY YEAR(d.distribution_date), d.status
");

if ($statusData) {
    while ($row = $statusData->fetch_assoc()) {
        $yr = (string)(int)$row['yr'];
        $count = (int)$row['cnt'];

        // anything that isn't 'returned' or 'death' counts as still active
        $bucket = in_array($row['status'], ['returned', 'death'], true) ? $row['status'] : 'active';

        if (!isset($yearStatusTotals[$yr])) {
            $yearStatusTotals[$yr] = ['active' => 0, 'returned' => 0, 'death' => 0];
        }
        $yearStatusTotals[$yr][$bucket] += $count;
    }
}

foreach ($chartYears as $yrKey) {
    if (!isset($yearStatusTotals[$yrKey])) {
        $yearStatusTotals[$yrKey] = ['active' => 0, 'returned' => 0, 'death' => 0];
    }
}

ksort($yearStatusTotals);

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
   PRINT REPORT SUMMARY
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reports - PIGOBAO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --pg-forest-900: #123c16;
    --pg-forest-800: #1b5e20;
    --pg-forest-600: #2e7d32;
    --pg-forest-300: #a5d6a7;
    --pg-forest-100: #e8f5e9;
    --pg-canvas: #f3f6f4;
    --pg-ink: #233126;
    --pg-ink-soft: #5d6b60;
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
    font-size: 17px;
    font-weight: 600;
    letter-spacing: .2px;
    margin: 0;
}

header .brand small {
    display: block;
    font-size: 11px;
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
    font-size: 14px;
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
    font-size: 14.5px;
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
    max-width: 1400px;
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
    font-size: 20px;
    font-weight: 700;
    color: var(--pg-forest-900);
    margin: 0 0 2px;
}

.page-info p {
    font-size: 12px;
    color: var(--pg-ink-soft);
    margin: 0;
}

/* SECTION LABEL */
.section-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}

.section-label {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 11px;
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

.chart-card {
    background: #fff;
    border: 1px solid var(--pg-line);
    border-radius: 15px;
    box-shadow: var(--shadow-soft);
    padding: 17px;
    position: relative;
    min-width: 0;
}

.chart-card.full-width {
    grid-column: 1 / -1;
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
    gap: 10px;
    flex-wrap: wrap;
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
    font-size: 14px;
    font-weight: 700;
    color: var(--pg-forest-900);
    margin: 0;
}

.chart-header span {
    display: block;
    font-size: 10px;
    color: var(--pg-ink-soft);
    margin-top: 1px;
}

/* YEAR FILTER (line chart) */
.chart-year-filter {
    display: flex;
    align-items: center;
    gap: 6px;
}

.chart-year-filter label {
    font-size: 10.5px;
    font-weight: 600;
    color: var(--pg-ink-soft);
    text-transform: uppercase;
    letter-spacing: .3px;
}

.chart-year-filter select {
    border: 1px solid var(--pg-line);
    background: var(--pg-forest-100);
    color: var(--pg-forest-900);
    font-family: 'Poppins', sans-serif;
    font-weight: 600;
    font-size: 12px;
    padding: 6px 10px;
    border-radius: 8px;
    cursor: pointer;
    outline: none;
    transition: .2s ease;
}

.chart-year-filter select:hover {
    background: var(--pg-forest-300);
}

/* Wraps the year + month filter side by side */
.chart-filters {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

/* PEAK MONTH BADGE (line chart) */
.peak-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    background: var(--pg-forest-100);
    color: var(--pg-forest-800);
    font-size: 11px;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 20px;
    white-space: nowrap;
}

.peak-badge i {
    font-size: 10px;
}

.chart-container {
    position: relative;
    width: 100%;
    height: 275px;
}

/* CHART DESCRIPTION — plain-language explanation under each chart */
.chart-desc {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    margin-top: 14px;
    padding: 11px 14px;
    background: var(--pg-forest-100);
    border: 1px solid var(--pg-line);
    border-radius: 10px;
    font-size: 12px;
    line-height: 1.55;
    color: var(--pg-ink-soft);
}

.chart-desc i {
    color: var(--pg-forest-800);
    font-size: 12px;
    margin-top: 2px;
    flex: none;
}

.chart-desc span {
    display: block;
}

.chart-container.small {
    height: 275px;
}

/* PIE CHART SIDE BOX */
.pie-chart-body {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.pie-chart-body .chart-container.small {
    flex: 1 1 190px;
    min-width: 170px;
}

.pie-total-box {
    flex: 1 1 140px;
    min-width: 132px;
    background: var(--pg-forest-100);
    border: 1px solid var(--pg-line);
    border-radius: 12px;
    padding: 14px 15px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.pie-total-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    color: var(--pg-ink-soft);
    display: flex;
    align-items: center;
    gap: 5px;
}

.info-tip {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: help;
    color: var(--pg-forest-600);
    font-size: 11px;
    line-height: 1;
}
.info-tip:focus { outline: none; }
.info-tip::after {
    content: attr(data-tip);
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    width: 220px;
    max-width: 60vw;
    background: var(--pg-forest-900);
    color: #fff;
    font-size: 11px;
    font-weight: 500;
    text-transform: none;
    letter-spacing: normal;
    line-height: 1.5;
    padding: 10px 12px;
    border-radius: 8px;
    box-shadow: var(--shadow-lift);
    opacity: 0;
    visibility: hidden;
    transition: opacity .15s ease, transform .15s ease;
    z-index: 20;
    pointer-events: none;
}
.info-tip::before {
    content: "";
    position: absolute;
    bottom: calc(100% + 3px);
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: var(--pg-forest-900);
    opacity: 0;
    visibility: hidden;
    transition: opacity .15s ease;
    z-index: 20;
}
.info-tip:hover::after, .info-tip:hover::before,
.info-tip:focus::after, .info-tip:focus::before {
    opacity: 1;
    visibility: visible;
}

.pie-total-value {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 25px;
    font-weight: 700;
    color: var(--pg-forest-900);
    line-height: 1.2;
}

.pie-total-sub {
    font-size: 10.5px;
    color: var(--pg-ink-soft);
    margin-bottom: 8px;
}

.pie-total-breakdown {
    display: flex;
    flex-direction: column;
    gap: 6px;
    border-top: 1px solid var(--pg-line);
    padding-top: 8px;
}

.pie-total-row {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: var(--pg-ink);
}

.pie-total-row .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    flex: none;
}

.pie-total-row strong {
    margin-left: auto;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    color: var(--pg-forest-900);
}

.pie-total-row strong small {
    font-family: 'Poppins', sans-serif;
    font-weight: 500;
    font-size: 10px;
    color: var(--pg-ink-soft);
    margin-left: 3px;
}

/* DISTRIBUTION TREND: livestock-type panel on the left of the graph */
.trend-body {
    display: flex;
    gap: 14px;
    align-items: stretch;
}

.trend-body .chart-container {
    flex: 1;
    min-width: 0;
}

.trend-legend {
    flex: 0 0 160px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 9px;
}

.trend-legend-item {
    appearance: none;
    text-align: left;
    background: #fff;
    border: 1px solid var(--pg-line);
    border-left: 4px solid var(--c);
    border-radius: 10px;
    padding: 9px 11px;
    cursor: pointer;
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    gap: 2px 6px;
    transition: .2s ease;
    color: var(--pg-ink);
}

.trend-legend-item:hover {
    box-shadow: var(--shadow-soft);
    transform: translateX(2px);
}

.trend-legend-item .t-name {
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.trend-legend-item .t-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--c);
    flex: none;
}

.trend-legend-item .t-total {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: 15px;
    color: var(--pg-forest-900);
}

.trend-legend-item .t-peak {
    grid-column: 1 / -1;
    font-size: 10px;
    color: var(--pg-ink-soft);
    display: flex;
    align-items: center;
    gap: 5px;
}

.trend-legend-item .t-peak i {
    color: var(--c);
    font-size: 9px;
}

.trend-legend-item.off {
    opacity: .45;
    border-left-color: var(--pg-line);
}

.trend-legend-item.off .t-name span:last-child {
    text-decoration: line-through;
}

.trend-hint {
    font-size: 9.5px;
    color: var(--pg-ink-soft);
    text-align: center;
    margin-top: 2px;
}

@media (max-width: 640px) {
    .trend-body {
        flex-direction: column;
    }
    .trend-legend {
        flex: none;
        flex-direction: row;
        flex-wrap: wrap;
    }
    .trend-legend-item {
        flex: 1 1 140px;
    }
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
    font-size: 13px;
    color: var(--pg-forest-900);
}

.toolbar-info small {
    display: block;
    font-size: 10px;
    color: var(--pg-ink-soft);
    margin-top: 1px;
}

.toolbar-buttons {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
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
    font-size: 14px;
    font-weight: 700;
    color: var(--pg-forest-900);
    margin: 0;
}

.report-card-header p {
    font-size: 10px;
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
    font-size: 11px;
}

.report-table thead th {
    background: var(--pg-forest-900);
    color: #eafbea;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 9.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .3px;
    padding: 10px 12px;
    text-align: left;
    white-space: nowrap;
}

.report-table tbody td {
    padding: 10px 12px;
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
    padding: 3px 6px;
    border-radius: 6px;
    background: var(--pg-forest-100);
    color: var(--pg-forest-800);
    font-size: 9px;
    font-weight: 700;
}

.kind-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 999px;
    background: #fdf3e3;
    color: var(--pg-amber);
    font-size: 9px;
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
    .main-content{ margin-left:200px; padding:85px 24px 25px; }
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
        font-size: 12px;
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
    .sidebar a{ margin:2px; padding:9px 12px; font-size:11.5px; }
    .sidebar a:hover{ transform:none; }

    .main-content {
        margin-left: 0;
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
}


/* PRINT DOCUMENT DESIGN */
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
    .section-label {
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
   GOVERNMENT PORTAL THEME  (matches admin / technician dashboards)
   Screen only - the printable report layout above is untouched.
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
header,.topbar{
top:var(--strip-h);height:var(--header-h);padding:0 30px;z-index:1002;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
box-shadow:0 4px 16px rgba(10,40,12,.28);
}
header .brand,.topbar .brand{display:flex;align-items:center;gap:14px;}
.seal{width:46px;height:46px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;box-shadow:0 0 0 2px rgba(255,255,255,.35);}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.seal.fallback{font-size:18px;color:#1b5e20;}
.brand .titles{padding-left:14px;border-left:1px solid rgba(255,255,255,.28);}
.brand .titles h1{font-family:'Merriweather',serif;font-size:19px;font-weight:700;letter-spacing:1.2px;line-height:1.15;margin:0;color:#fff;}
.brand .titles small{display:block;font-size:12px;font-weight:400;color:rgba(255,255,255,.78);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;}
header a.logout,.topbar a.logout{
color:#123c16;background:#fff;padding:9px 22px;border-radius:8px;font-weight:600;font-size:14px;
text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,.14);transition:.2s ease;
}
header a.logout:hover,.topbar a.logout:hover{background:#e8f5e9;transform:translateY(-1px);}

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
.main-content,.main{
margin-left:250px;
padding:calc(var(--top-offset) + 26px) 34px 20px;
max-width:none;width:auto;min-height:100vh;
display:flex;flex-direction:column;
}

/* page banner */
.page-card,.page-header{
background:#fff;border:1px solid var(--gv-line);border-left:6px solid #1b5e20;border-radius:12px;
padding:22px 26px;gap:18px;box-shadow:var(--gv-shadow);margin-bottom:26px;position:relative;overflow:hidden;
}
.page-card::before{display:none;}
.page-card::after,.page-header::after{
content:"\f200";font-family:"Font Awesome 6 Free";font-weight:900;position:absolute;right:-8px;bottom:-30px;
font-size:120px;color:#e8f5e9;z-index:0;pointer-events:none;
}
.page-card > *,.page-header > *{position:relative;z-index:1;}
.page-icon{width:60px;height:60px;border-radius:50%;font-size:22px;box-shadow:0 0 0 4px #e8f5e9;background:linear-gradient(145deg,#2e7d32,#123c16);}
.page-date{font-size:12.5px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--gov-navy);margin-bottom:2px;display:flex;align-items:center;gap:7px;}
.page-info h2,.page-header h2{font-size:23px;margin:0 0 3px;color:#123c16;}
.page-info p,.page-header p{font-size:14px;color:var(--gv-soft);max-width:680px;}
.role-pill{
margin-left:auto;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#123c16;color:#fff;
display:inline-flex;align-items:center;gap:8px;letter-spacing:.3px;
}
.role-pill i{color:var(--gov-gold);}

/* section headings */
.section-label,.section-title{
font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;
color:#123c16;display:flex;align-items:center;gap:10px;margin:0 0 14px;
}
.section-label::before,.section-title::before{content:"";width:5px;height:20px;border-radius:3px;background:linear-gradient(180deg,var(--gov-gold),#2e7d32);}
.section-label-row{margin-bottom:14px;}
.section-label-row .section-label{margin:0 !important;}

/* cards */
.chart-card,.card{
border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;
box-shadow:var(--gv-shadow);background:#fff;
}
.chart-card{padding:22px 24px;}
.chart-card::before{display:none;}
.chart-card:hover,.card:hover{box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.chart-grid{gap:18px;margin-bottom:26px;}
.chart-title-icon{width:38px;height:38px;border-radius:10px;font-size:16px;background:#e6eef8;color:var(--gov-navy);}
.chart-header h3,.card-head h3{font-size:15px;letter-spacing:.2px;color:#123c16;}
.chart-header span,.card-head small{font-size:12.5px;color:var(--gv-soft);}
.chart-header{padding-bottom:12px;margin-bottom:14px;border-bottom:1px dashed var(--gv-line);}
.chart-desc{font-size:13px;border-radius:8px;border-left:4px solid var(--gov-gold);background:#f7faf7;}
.peak-badge{font-size:12.5px;border-radius:8px;background:#e6eef8;color:var(--gov-navy);border:1px solid #cfdcee;}
.pie-total-box{border-radius:10px;background:#f7faf7;}
.pie-total-label{font-size:11.5px;}
.pie-total-sub,.pie-total-row{font-size:12.5px;}
.trend-legend-item{border-radius:8px;}
.trend-legend-item .t-name{font-size:13.5px;}
.trend-legend-item .t-peak,.trend-hint{font-size:11.5px;}

/* filters */
.chart-year-filter label,.year-filter label{font-size:12.5px;color:var(--gv-soft);}
.chart-year-filter select,.year-filter select{font-size:14px;padding:7px 12px;border-radius:7px;border:1px solid var(--gv-line);color:#123c16;}
.global-year-bar{
border:1px solid var(--gv-line);border-radius:12px;box-shadow:var(--gv-shadow);padding:12px 18px;margin-bottom:26px;
}
.global-year-bar select{background:#f3f6f4;color:#123c16;border:1px solid var(--gv-line);}
.global-year-bar small{font-size:13px;}

/* tech stat cards */
.stats{gap:18px;margin-bottom:26px;}
.stat-card{
--accent:#1b5e20;--accent-soft:#e8f5e9;
border:1px solid var(--gv-line);border-top:5px solid var(--accent);border-radius:12px;padding:22px 24px;gap:16px;
box-shadow:var(--gv-shadow);transition:transform .2s ease,box-shadow .2s ease;
}
.stat-card:nth-child(2){--accent:#0b2f5b;--accent-soft:#e6eef8;}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.stat-icon{width:52px;height:52px;border-radius:12px;font-size:22px;background:var(--accent-soft);color:var(--accent);flex:none;}
.stat-card strong{font-size:38px;line-height:1.05;color:var(--accent);}
.stat-card span{font-size:13px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:var(--gv-soft);}

/* tech card internals */
.card-head{padding:16px 22px;background:#fbfdfb;border-bottom:1px dashed var(--gv-line);}
.highest{font-size:13px;padding:14px 22px;background:#f7faf7;}
.highest.multi-stat .stat-desc{font-size:11.5px;}

/* toolbar + table (reports.php) */
.report-toolbar{border:1px solid var(--gv-line);border-left:6px solid #1b5e20;border-radius:12px;box-shadow:var(--gv-shadow);padding:16px 22px;}
.toolbar-icon{width:42px;height:42px;border-radius:10px;font-size:17px;}
.toolbar-info strong{font-size:15px;}
.toolbar-info small{font-size:12.5px;}
.pg-btn{font-size:13px;padding:10px 18px;border-radius:8px;}
.report-card{border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);}
.report-card-header{padding:16px 22px;background:#fbfdfb;border-bottom:1px dashed var(--gv-line);}
.report-card-header h3{font-size:15px;}
.report-card-header p{font-size:12.5px;}
.report-table{font-size:13px;}
.report-table thead th{font-size:11.5px;letter-spacing:.6px;padding:13px 16px;background:#123c16;}
.report-table tbody td{padding:12px 16px;}
.report-table tbody tr:nth-child(even){background:#f8fbf8;}
.report-table tbody tr:hover{background:#e8f5e9;}
.id-badge{font-size:11.5px;padding:3px 8px;}
.kind-badge{font-size:11.5px;padding:3px 10px;}

/* footer */
.gov-footer{display:block;margin-top:auto;padding-top:30px;}
.gov-footer .inner{border-top:1px solid var(--gv-line);padding-top:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:12.5px;color:var(--gv-soft);}

/* responsive */
@media(max-width:900px){
.sidebar{width:215px;}
.main-content,.main{margin-left:215px;padding:calc(var(--top-offset) + 20px) 20px 18px;}
}
@media(max-width:768px){
:root{--strip-h:30px;--header-h:62px;}
.gov-strip{padding:0 14px;font-size:11px;}
.gov-strip .right{display:none;}
header,.topbar{padding:0 14px;}
.brand .titles h1{font-size:15px;letter-spacing:.8px;}
.brand .titles small{display:block;font-size:10px;}
.seal{width:38px;height:38px;}
.brand .titles{padding-left:10px;}
header a.logout,.topbar a.logout{padding:8px 14px;font-size:12.5px;}
.sidebar{position:static;width:100%;height:auto;margin-top:var(--top-offset);padding:8px;display:flex;flex-wrap:wrap;gap:3px;}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
.sidebar a:hover{transform:none;}
.main-content,.main{margin-left:0;padding:18px 15px;min-height:auto;}
.page-card,.page-header{padding:18px;margin-bottom:20px;}
.page-info h2,.page-header h2{font-size:19px;}
.role-pill{margin-left:0;}
.chart-card{padding:16px;}
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
            <small>Admin Reports &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../adminlog_out.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="./add-technician.php"><i class="fas fa-user-cog"></i>Technicians</a>
    <a href="./farmer-beneficiary.php"><i class="fas fa-id-card"></i> Farmer Beneficiary</a>
    <a href="./add-livestock.php"><i class="fas fa-drumstick-bite"></i>Livestock</a>
    <a href="./tracking.php"><i class="fas fa-eye"></i> Livestock Tracking</a>
    <a href="./dispersal.php"><i class="fas fa-people-carry-box"></i> Dispersal</a>
    <a href="./farmer-success-rate.php" ><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="./reports.php"class="active"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="./generate-report.php"><i class="fas fa-file-alt"></i> Generate Report</a>
</div>

<div class="main-content">

    <div class="page-card">
        <div class="page-icon"><i class="fas fa-chart-line"></i></div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Reports &amp; Analytics</h2>
            <p>Monitor livestock distributions and review distribution activity across the system.</p>
        </div>
        <span class="role-pill"><i class="fas fa-shield-halved"></i> Administrator</span>
    </div>

    <div class="section-label-row">
        <p class="section-label" style="margin:0;">Distribution Analytics</p>

        <div class="chart-filters">
            <div class="chart-year-filter">
                <label for="analyticsYearFilter">Year</label>
                <select id="analyticsYearFilter">
                    <option value="all">All Years</option>
                    <?php foreach (array_reverse($chartYears) as $yr): ?>
                        <option value="<?= (int)$yr ?>"><?= (int)$yr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="chart-year-filter">
                <label for="analyticsMonthFilter">Month</label>
                <select id="analyticsMonthFilter">
                    <option value="all">All Months</option>
                    <option value="1">January</option>
                    <option value="2">February</option>
                    <option value="3">March</option>
                    <option value="4">April</option>
                    <option value="5">May</option>
                    <option value="6">June</option>
                    <option value="7">July</option>
                    <option value="8">August</option>
                    <option value="9">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
            </div>
        </div>
    </div>

    <div class="chart-grid">

        <!-- PIE CHART -->
        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-title-icon"><i class="fas fa-chart-pie"></i></div>
                    <div>
                        <h3>Livestock Distribution</h3>
                        <span id="pieChartSubtitle">Distribution share by type</span>
                    </div>
                </div>
            </div>

            <div class="pie-chart-body">
                <div class="chart-container small">
                    <canvas id="pieChart"></canvas>
                </div>

                <div class="pie-total-box">
                    <span class="pie-total-label">
                        All-Time Total
                        <span class="info-tip" tabindex="0" data-tip="This adds up every livestock distribution ever recorded, from <?= htmlspecialchars((string)$allTimeFirstYear) ?> to <?= htmlspecialchars((string)$allTimeLastYear) ?>. It stays the same no matter which year you pick above — use the pie chart and the breakdown below for a per-year view.">
                            <i class="fas fa-circle-info"></i>
                        </span>
                    </span>
                    <span class="pie-total-value"><?= number_format($allTimeTotalLivestock) ?></span>
                    <span class="pie-total-sub">livestock distributed &middot; <?= htmlspecialchars($allTimeYearRange) ?></span>

                    <div class="pie-total-breakdown">
                        <div class="pie-total-row">
                            <span class="dot" style="background:#2e7d32"></span> Goat
                            <strong><?= number_format($dataCounts['goat']) ?> <small>(<?= pctOf($dataCounts['goat'], $allTimeTotalLivestock) ?>%)</small></strong>
                        </div>
                        <div class="pie-total-row">
                            <span class="dot" style="background:#c98a1f"></span> Pig
                            <strong><?= number_format($dataCounts['pig']) ?> <small>(<?= pctOf($dataCounts['pig'], $allTimeTotalLivestock) ?>%)</small></strong>
                        </div>
                        <div class="pie-total-row">
                            <span class="dot" style="background:#3f7cac"></span> Carabao
                            <strong><?= number_format($dataCounts['carabao']) ?> <small>(<?= pctOf($dataCounts['carabao'], $allTimeTotalLivestock) ?>%)</small></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="chart-desc">
                <i class="fas fa-lightbulb"></i>
                <span id="pieChartDescText">Loading…</span>
            </div>
        </div>

        <!-- LINE CHART -->
        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-title-icon"><i class="fas fa-chart-line"></i></div>
                    <div>
                        <h3>Distribution Trend</h3>
                        <span id="lineChartSubtitle">By livestock type, by month</span>
                    </div>
                </div>

                <div class="peak-badge" id="peakMonthBadge" title="Month with the highest total for the animals currently shown">
                    <i class="fas fa-arrow-trend-up"></i>
                    <span id="peakMonthBadgeText">—</span>
                </div>
            </div>

            <div class="trend-body">
                <div class="trend-legend" id="trendLegend"></div>
                <div class="chart-container">
                    <canvas id="lineChart"></canvas>
                </div>
            </div>

            <div class="chart-desc">
                <i class="fas fa-lightbulb"></i>
                <span id="trendChartDescText">Loading…</span>
            </div>
        </div>

        <!-- STATUS CHART: Success Rate vs Death, per year -->
        <div class="chart-card full-width">
            <div class="chart-header">
                <div class="chart-title">
                    <div class="chart-title-icon"><i class="fas fa-heart-pulse"></i></div>
                    <div>
                        <h3>Success Rate vs Death</h3>
                        <span id="statusChartSubtitle">Yearly, by status — all years</span>
                    </div>
                </div>

                <div class="peak-badge" id="successBadge" title="Success = active + returned (not lost to death)">
                    <i class="fas fa-percent"></i>
                    <span id="successBadgeText">—</span>
                </div>
            </div>

            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>

            <div class="chart-desc">
                <i class="fas fa-lightbulb"></i>
                <span id="statusChartDescText">Loading…</span>
            </div>
        </div>

    </div>


    <div class="gov-footer">
        <div class="inner">
            <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
            <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
        </div>
    </div>

</div>

<script>
/* =========================================================
   PIE CHART
   Built per-year so it can follow whichever year is chosen
   in the shared year filter (same dropdown that drives the
   line chart below).
========================================================= */
const yearKindTotals = <?= json_encode($yearKindTotals) ?>;
const allTimeKindTotals = {
    goat: <?= (int)$dataCounts['goat'] ?>,
    pig: <?= (int)$dataCounts['pig'] ?>,
    carabao: <?= (int)$dataCounts['carabao'] ?>
};

function getPieDataForYear(selectedYear) {
    if (selectedYear === 'all') {
        return [allTimeKindTotals.goat, allTimeKindTotals.pig, allTimeKindTotals.carabao];
    }
    const yearData = yearKindTotals[String(selectedYear)] || { goat: 0, pig: 0, carabao: 0 };
    return [yearData.goat, yearData.pig, yearData.carabao];
}

/* Draws the current (year-filtered) distribution total in the
   middle of the donut, separate from the always-all-time total
   shown in the side box next to it. */
const pieCenterTextPlugin = {
    id: 'pieCenterText',
    afterDraw(chart) {
        if (chart.canvas.id !== 'pieChart') return;
        const { ctx, chartArea } = chart;
        if (!chartArea) return;
        const cx = (chartArea.left + chartArea.right) / 2;
        const cy = (chartArea.top + chartArea.bottom) / 2;
        const total = chart.$centerTotal || 0;

        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = "700 22px 'Space Grotesk', sans-serif";
        ctx.fillStyle = '#123c16';
        ctx.fillText(total.toLocaleString(), cx, cy - 9);
        ctx.font = "600 9.5px 'Poppins', sans-serif";
        ctx.fillStyle = '#5d6b60';
        ctx.fillText('TOTAL', cx, cy + 11);
        ctx.restore();
    }
};
Chart.register(pieCenterTextPlugin);

/* Percentage helper: value / total, one decimal, no trailing ".0" */
function pctText(value, total) {
    return total > 0 ? (Math.round((value / total) * 1000) / 10) + '%' : '0%';
}

/* Draws each slice's percentage on the donut itself
   (slices under 5% are skipped so the text doesn't overflow). */
const pieSliceLabelPlugin = {
    id: 'pieSliceLabels',
    afterDatasetsDraw(chart) {
        if (chart.canvas.id !== 'pieChart') return;
        const ds = chart.data.datasets[0];
        const total = pieDataSum(ds.data);
        if (total <= 0) return;

        const meta = chart.getDatasetMeta(0);
        const ctx = chart.ctx;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = "700 11px 'Poppins', sans-serif";
        ctx.fillStyle = '#ffffff';
        meta.data.forEach(function(arc, i) {
            const v = ds.data[i];
            if (!v || (v / total) < 0.05) return;
            const p = arc.getCenterPoint();
            ctx.fillText(pctText(v, total), p.x, p.y);
        });
        ctx.restore();
    }
};
Chart.register(pieSliceLabelPlugin);

function pieDataSum(data) {
    return data.reduce(function(sum, v) { return sum + v; }, 0);
}

/* Plain-language explanation of the pie chart for the selected year:
   which kind leads, by how much, and what the other two look like. */
function buildPieDescription(selectedYear) {
    const el = document.getElementById('pieChartDescText');
    if (!el) return;

    const labels = ['Goat', 'Pig', 'Carabao'];
    const data = getPieDataForYear(selectedYear);
    const total = pieDataSum(data);
    const periodPhrase = selectedYear === 'all' ? 'to date' : 'in ' + selectedYear;

    if (total <= 0) {
        el.textContent = 'No livestock has been distributed ' + periodPhrase + ' yet.';
        return;
    }

    const items = labels
        .map(function(label, i) { return { label: label, value: data[i] }; })
        .sort(function(a, b) { return b.value - a.value; });

    let sentence = 'Of the ' + total.toLocaleString() + ' livestock distributed ' + periodPhrase + ', ' +
        items[0].label + ' leads with ' + items[0].value.toLocaleString() + ' head(s) (' + pctText(items[0].value, total) + ')';

    if (items[1].value > 0 || items[2].value > 0) {
        sentence += ', followed by ' + items[1].label + ' (' + items[1].value.toLocaleString() + ', ' + pctText(items[1].value, total) + ')' +
            ' and ' + items[2].label + ' (' + items[2].value.toLocaleString() + ', ' + pctText(items[2].value, total) + ').';
    } else {
        sentence += ' — no ' + items[1].label.toLowerCase() + ' or ' + items[2].label.toLowerCase() + ' has been distributed ' + periodPhrase + '.';
    }

    el.textContent = sentence;
}

const pieCanvas = document.getElementById('pieChart');
const pieChartSubtitle = document.getElementById('pieChartSubtitle');
let pieChartInstance = null;

if (pieCanvas) {
    const initialPieData = getPieDataForYear('all');
    pieChartInstance = new Chart(pieCanvas, {
        type: 'doughnut',
        data: {
            labels: ['Goat', 'Pig', 'Carabao'],
            datasets: [{
                data: initialPieData,
                backgroundColor: [
                    '#2e7d32',
                    '#c98a1f',
                    '#3f7cac'
                ],
                borderColor: '#ffffff',
                borderWidth: 4,
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '58%',
            animation: {
                animateRotate: true,
                animateScale: true,
                duration: 800
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    // slices can't be hidden from the legend, so the percentages always add up to 100%
                    onClick: function() {},
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 16,
                        font: {
                            family: 'Poppins',
                            size: 11
                        },
                        generateLabels: function(chart) {
                            const ds = chart.data.datasets[0];
                            const total = pieDataSum(ds.data);
                            return chart.data.labels.map(function(label, i) {
                                return {
                                    text: label + ' \u00B7 ' + pctText(ds.data[i], total),
                                    fillStyle: ds.backgroundColor[i],
                                    strokeStyle: ds.backgroundColor[i],
                                    lineWidth: 0,
                                    pointStyle: 'circle',
                                    hidden: false,
                                    index: i
                                };
                            });
                        }
                    }
                },
                tooltip: {
                    enabled: true,
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            const value = context.raw || 0;
                            const total = pieDataSum(context.dataset.data);
                            return ' ' + context.label + ': ' + value + ' animal(s) (' + pctText(value, total) + ')';
                        }
                    }
                }
            }
        }
    });
    pieChartInstance.$centerTotal = pieDataSum(initialPieData);
    pieChartInstance.update();
    buildPieDescription('all');
}

/* =========================================================
   DISTRIBUTION TREND
   One line per livestock type (goat / pig / carabao) across the
   12 months. The panel on the left of the graph lists the three
   types, their totals and the month each one peaks; click a type
   to show / hide its line. With a month picked in the Month
   filter: if a year is also picked, the line shows the days of that
   month for THAT year only; if the year is "All Years", it shows that
   month across the years. It is always a line graph.
========================================================= */
const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const monthNamesFull = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const yearMonthKindTotals = <?= json_encode($yearMonthKindTotals) ?>;
const yearMonthDayKind = <?= json_encode($yearMonthDayKind) ?>;
const allYearKeys = Object.keys(yearMonthKindTotals).sort();

/* Year AND month both picked -> daily view for that exact year */
function isDayMode(sel) {
    return sel.month !== 'all' && sel.year !== 'all';
}

function daysInMonth(year, month) {
    return new Date(parseInt(year, 10), parseInt(month, 10), 0).getDate();
}

function kindDayValue(kind, year, month, day) {
    const y = yearMonthDayKind[year];
    const m = y && y[month];
    const k = m && m[kind];
    return (k && k[day]) ? k[day] : 0;
}

const kindMeta = [
    { key: 'goat',    label: 'Goat',    color: '#2e7d32' },
    { key: 'pig',     label: 'Pig',     color: '#c98a1f' },
    { key: 'carabao', label: 'Carabao', color: '#3f7cac' }
];
const hiddenKinds = {};   // key -> true when the type was toggled off in the left panel

const analyticsYearFilter = document.getElementById('analyticsYearFilter');
const analyticsMonthFilter = document.getElementById('analyticsMonthFilter');

const lineCanvas = document.getElementById('lineChart');
const lineChartSubtitle = document.getElementById('lineChartSubtitle');
const peakMonthBadgeText = document.getElementById('peakMonthBadgeText');
const trendLegend = document.getElementById('trendLegend');
let lineChartInstance = null;

const sharedScales = {
    x: {
        grid: { display: false },
        ticks: { font: { family: 'Poppins', size: 9 }, color: '#5d6b60' }
    },
    y: {
        beginAtZero: true,
        ticks: { precision: 0, font: { family: 'Poppins', size: 9 }, color: '#5d6b60' },
        grid: { color: '#e2e9e3', drawBorder: false }
    }
};

function getSelection() {
    return {
        year: analyticsYearFilter ? analyticsYearFilter.value : 'all',
        month: analyticsMonthFilter ? analyticsMonthFilter.value : 'all'
    };
}

function kindMonthValue(kind, year, month) {
    const y = yearMonthKindTotals[year];
    return (y && y[kind] && y[kind][month]) ? y[kind][month] : 0;
}

/* x-axis labels for the current selection */
function getTrendLabels(sel) {
    if (sel.month === 'all') return monthNames;
    if (isDayMode(sel)) {
        const n = daysInMonth(sel.year, sel.month);
        return Array.from({ length: n }, function(_, i) { return String(i + 1); });
    }
    return allYearKeys;
}

/* Values for one livestock type: 12 months (year filter applies), or one
   value per year when a single month is picked. */
function getKindSeries(kind, sel) {
    if (sel.month === 'all') {
        return monthNames.map(function(_, idx) {
            const m = idx + 1;
            if (sel.year === 'all') {
                return allYearKeys.reduce(function(sum, y) { return sum + kindMonthValue(kind, y, m); }, 0);
            }
            return kindMonthValue(kind, sel.year, m);
        });
    }
    const m = parseInt(sel.month, 10);
    if (isDayMode(sel)) {
        const n = daysInMonth(sel.year, m);
        return Array.from({ length: n }, function(_, i) { return kindDayValue(kind, sel.year, m, i + 1); });
    }
    return allYearKeys.map(function(y) { return kindMonthValue(kind, y, m); });
}

/* Highest value(s) in a series; ties are all reported. */
function getPeak(values) {
    const max = Math.max.apply(null, values.concat([0]));
    const idxs = [];
    if (max > 0) {
        values.forEach(function(v, i) { if (v === max) idxs.push(i); });
    }
    return { max: max, idxs: idxs };
}

function formatPeak(peak, nameFor) {
    if (peak.max <= 0) return 'No data';
    const names = peak.idxs.map(nameFor);
    const text = names.length <= 2 ? names.join(', ') : names[0] + ' +' + (names.length - 1) + ' more';
    return text + ' \u00B7 ' + peak.max;
}

function renderTrendLegend() {
    if (!trendLegend) return;
    const sel = getSelection();
    const labels = getTrendLabels(sel);
    const peakWord = sel.month === 'all' ? 'Peak month' : (isDayMode(sel) ? 'Peak day' : 'Peak year');

    trendLegend.innerHTML = kindMeta.map(function(k) {
        const values = getKindSeries(k.key, sel);
        const total = values.reduce(function(a, b) { return a + b; }, 0);
        const peak = getPeak(values);
        const allTied = peak.idxs.map(function(i) { return labels[i]; }).join(', ');
        return '<button type="button" class="trend-legend-item' + (hiddenKinds[k.key] ? ' off' : '') + '"' +
               ' data-kind="' + k.key + '" style="--c:' + k.color + '" title="Click to show / hide ' + k.label + '">' +
                   '<span class="t-name"><span class="t-dot"></span><span>' + k.label + '</span></span>' +
                   '<span class="t-total">' + total + '</span>' +
                   '<span class="t-peak" title="' + (allTied ? peakWord + ': ' + allTied : '') + '">' +
                       '<i class="fas fa-arrow-trend-up"></i> ' + formatPeak(peak, function(i) { return labels[i]; }) +
                   '</span>' +
               '</button>';
    }).join('') + '<p class="trend-hint">Click a type to show / hide its line</p>';
}

/* Badge next to the title: highest month (or year) for the animals currently shown. */
function updatePeakMonthBadge() {
    if (!peakMonthBadgeText) return;
    const sel = getSelection();
    const labels = getTrendLabels(sel);
    const totals = labels.map(function() { return 0; });

    kindMeta.forEach(function(k) {
        if (hiddenKinds[k.key]) return;
        getKindSeries(k.key, sel).forEach(function(v, i) { totals[i] += v; });
    });

    const peak = getPeak(totals);
    if (peak.max <= 0) {
        peakMonthBadgeText.textContent = 'No data yet';
        return;
    }
    const names = peak.idxs.map(function(i) {
        if (sel.month === 'all') return monthNamesFull[i];
        if (isDayMode(sel)) return monthNamesFull[parseInt(sel.month, 10) - 1] + ' ' + labels[i];
        return labels[i];
    });
    const text = names.length <= 2 ? names.join(', ') : names[0] + ' +' + (names.length - 1) + ' more';
    peakMonthBadgeText.textContent = text + ' \u00B7 ' + peak.max + ' ' + (peak.max === 1 ? 'unit' : 'units');
}

/* Human-readable label for one point on the trend chart, matching
   whatever mode it's currently in (month / year / day). */
function trendPointName(i, sel, labels) {
    if (sel.month === 'all') return monthNamesFull[i];
    if (isDayMode(sel)) return monthNamesFull[parseInt(sel.month, 10) - 1] + ' ' + labels[i];
    return labels[i];
}

/* Phrase describing the period currently shown, for use inside a sentence. */
function trendPeriodPhrase(sel) {
    if (sel.month === 'all') return sel.year === 'all' ? 'across all years' : 'in ' + sel.year;
    if (isDayMode(sel)) return 'in ' + monthNamesFull[parseInt(sel.month, 10) - 1] + ' ' + sel.year;
    return 'in ' + monthNamesFull[parseInt(sel.month, 10) - 1] + ' across the years';
}

/* Plain-language explanation of the trend chart: how the total moved
   from the start of the period to the end, and which type led. */
function buildTrendDescription() {
    const el = document.getElementById('trendChartDescText');
    if (!el) return;

    const sel = getSelection();
    const labels = getTrendLabels(sel);
    const totals = labels.map(function() { return 0; });

    kindMeta.forEach(function(k) {
        if (hiddenKinds[k.key]) return;
        getKindSeries(k.key, sel).forEach(function(v, i) { totals[i] += v; });
    });

    const period = trendPeriodPhrase(sel);
    const grand = totals.reduce(function(a, b) { return a + b; }, 0);

    if (grand <= 0) {
        el.textContent = 'No distributions have been recorded ' + period + ' yet.';
        return;
    }

    const nonZeroIdxs = [];
    totals.forEach(function(v, i) { if (v > 0) nonZeroIdxs.push(i); });
    const firstIdx = nonZeroIdxs[0];
    const lastIdx = nonZeroIdxs[nonZeroIdxs.length - 1];
    const peak = getPeak(totals);
    const peakIdx = peak.idxs[0];
    const allEqual = nonZeroIdxs.every(function(i) { return totals[i] === totals[firstIdx]; });

    let sentence;
    if (nonZeroIdxs.length === 1) {
        sentence = 'All ' + grand + ' unit(s) distributed ' + period + ' happened in ' + trendPointName(firstIdx, sel, labels) + '.';
    } else if (allEqual) {
        sentence = 'Distributions stayed steady ' + period + ', holding at ' + totals[firstIdx] + ' from ' +
            trendPointName(firstIdx, sel, labels) + ' through ' + trendPointName(lastIdx, sel, labels) + '.';
    } else if (peakIdx === lastIdx && totals[lastIdx] > totals[firstIdx]) {
        sentence = 'Distributions rose steadily ' + period + ', climbing from ' + totals[firstIdx] + ' in ' +
            trendPointName(firstIdx, sel, labels) + ' to a high of ' + totals[lastIdx] + ' in ' + trendPointName(lastIdx, sel, labels) + '.';
    } else if (peakIdx === firstIdx && totals[firstIdx] > totals[lastIdx]) {
        sentence = 'Distributions were highest early on ' + period + ', at ' + totals[firstIdx] + ' in ' +
            trendPointName(firstIdx, sel, labels) + ', then eased down to ' + totals[lastIdx] + ' by ' + trendPointName(lastIdx, sel, labels) + '.';
    } else {
        sentence = 'Distributions ' + period + ' started at ' + totals[firstIdx] + ' in ' + trendPointName(firstIdx, sel, labels) +
            ', peaked at ' + totals[peakIdx] + ' in ' + trendPointName(peakIdx, sel, labels) + ', and ' +
            (totals[lastIdx] >= totals[peakIdx] ? 'held around ' : 'settled back down to ') + totals[lastIdx] +
            ' by ' + trendPointName(lastIdx, sel, labels) + '.';
    }

    // Call out whichever visible type contributed the most, when more than one is shown
    const visibleKinds = kindMeta.filter(function(k) { return !hiddenKinds[k.key]; });
    if (visibleKinds.length > 1) {
        let topKind = null, topTotal = -1;
        visibleKinds.forEach(function(k) {
            const t = getKindSeries(k.key, sel).reduce(function(a, b) { return a + b; }, 0);
            if (t > topTotal) { topTotal = t; topKind = k; }
        });
        if (topKind && topTotal > 0) {
            sentence += ' ' + topKind.label + ' accounted for the largest share, with ' + topTotal + ' head(s) total.';
        }
    }

    el.textContent = sentence;
}

function buildTrendChart() {
    if (!lineCanvas) return;
    const sel = getSelection();
    const labels = getTrendLabels(sel);

    const datasets = kindMeta.map(function(k) {
        const values = getKindSeries(k.key, sel);
        const peak = getPeak(values);
        const isPeak = function(i) { return peak.idxs.indexOf(i) !== -1; };

        return {
            label: k.label,
            data: values,
            borderColor: k.color,
            backgroundColor: k.color + '14',
            borderWidth: 2.3,
            tension: .35,
            fill: false,
            // the peak month(s) get a larger, hollow point so they stand out on the line
            pointRadius: values.map(function(v, i) { return isPeak(i) ? 6 : 3; }),
            pointBackgroundColor: values.map(function(v, i) { return isPeak(i) ? '#ffffff' : k.color; }),
            pointBorderColor: k.color,
            pointBorderWidth: 2,
            pointHoverRadius: 7,
            hidden: !!hiddenKinds[k.key]
        };
    });

    if (lineChartInstance) lineChartInstance.destroy();
    lineChartInstance = new Chart(lineCanvas, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: false },   // the left panel is the legend
                tooltip: { padding: 9 }
            },
            scales: sharedScales
        }
    });
}

function refreshTrend() {
    const sel = getSelection();
    if (lineChartSubtitle) {
        if (sel.month === 'all') {
            lineChartSubtitle.textContent = 'By livestock type, by month' + (sel.year === 'all' ? ' \u2014 all years' : ' \u2014 ' + sel.year);
        } else {
            lineChartSubtitle.textContent = isDayMode(sel)
                ? monthNamesFull[parseInt(sel.month, 10) - 1] + ' ' + sel.year + ', by day'
                : monthNamesFull[parseInt(sel.month, 10) - 1] + ', by year';
        }
    }
    buildTrendChart();
    renderTrendLegend();
    updatePeakMonthBadge();
    buildTrendDescription();
}

if (trendLegend) {
    trendLegend.addEventListener('click', function(e) {
        const btn = e.target.closest('.trend-legend-item');
        if (!btn) return;
        const key = btn.getAttribute('data-kind');
        hiddenKinds[key] = !hiddenKinds[key];

        const idx = kindMeta.findIndex(function(k) { return k.key === key; });
        if (lineChartInstance && idx !== -1) {
            lineChartInstance.setDatasetVisibility(idx, !hiddenKinds[key]);
            lineChartInstance.update();
        }
        renderTrendLegend();
        updatePeakMonthBadge();
        buildTrendDescription();
    });
}

/* =========================================================
   STATUS CHART (Success Rate vs Death), PER YEAR
   Grouped bars, one group per year (by distribution date):
   Total Distributed, Active, Returned and Death.
   e.g. 2025: 5 distributed -> 3 returned + 2 dead.
   The Year filter highlights that year; the other years are
   dimmed so the comparison stays visible.
   Success rate = (active + returned) / total, i.e. not lost to death.
========================================================= */
const yearStatusTotals = <?= json_encode($yearStatusTotals) ?>;
const statusYears = Object.keys(yearStatusTotals).sort();

function getYearStatus(year) {
    const d = yearStatusTotals[year] || {};
    const active = parseInt(d.active, 10) || 0;
    const returned = parseInt(d.returned, 10) || 0;
    const death = parseInt(d.death, 10) || 0;
    return { active: active, returned: returned, death: death, total: active + returned + death };
}

const statusCanvas = document.getElementById('statusChart');
const statusChartSubtitle = document.getElementById('statusChartSubtitle');
const successBadgeText = document.getElementById('successBadgeText');
let statusChartInstance = null;

function updateSuccessBadge(selectedYear) {
    if (!successBadgeText) return;
    const years = selectedYear === 'all' ? statusYears : [String(selectedYear)];
    let success = 0, death = 0, total = 0;
    years.forEach(function(y) {
        const r = getYearStatus(y);
        success += r.active + r.returned;
        death += r.death;
        total += r.total;
    });
    successBadgeText.textContent = total > 0
        ? 'Success ' + pctText(success, total) + ' \u00B7 Death ' + pctText(death, total)
        : 'No data yet';
}

/* Plain-language explanation of the status chart: overall success/death
   rate, which year did best or worst, and how the rate has moved over time
   ("all years" view) — or how one selected year compares to the year
   before it. */
function buildStatusDescription(selectedYear) {
    const el = document.getElementById('statusChartDescText');
    if (!el) return;

    if (selectedYear === 'all') {
        let success = 0, death = 0, total = 0;
        let bestYear = null, bestPct = -1, worstYear = null, worstDeathPct = -1;

        statusYears.forEach(function(y) {
            const r = getYearStatus(y);
            success += r.active + r.returned;
            death += r.death;
            total += r.total;
            if (r.total > 0) {
                const sPct = (r.active + r.returned) / r.total;
                const dPct = r.death / r.total;
                if (sPct > bestPct) { bestPct = sPct; bestYear = y; }
                if (dPct > worstDeathPct) { worstDeathPct = dPct; worstYear = y; }
            }
        });

        if (total <= 0) {
            el.textContent = 'No distribution outcomes have been recorded yet.';
            return;
        }

        let sentence = 'Across all ' + statusYears.length + ' year(s) on record, ' + total.toLocaleString() +
            ' livestock have been distributed overall, for an overall success rate of ' + pctText(success, total) +
            ' and a death rate of ' + pctText(death, total) + '.';

        if (bestYear) {
            const bestR = getYearStatus(bestYear);
            sentence += ' ' + bestYear + ' had the strongest outcomes, at ' + pctText(bestR.active + bestR.returned, bestR.total) + ' success';
            if (worstYear && worstYear !== bestYear) {
                const worstR = getYearStatus(worstYear);
                sentence += ', while ' + worstYear + ' saw the highest death rate at ' + pctText(worstR.death, worstR.total) + '.';
            } else {
                sentence += '.';
            }
        }

        const yearsWithData = statusYears.filter(function(y) { return getYearStatus(y).total > 0; });
        if (yearsWithData.length > 1) {
            const firstY = yearsWithData[0];
            const lastY = yearsWithData[yearsWithData.length - 1];
            if (firstY !== lastY) {
                const firstR = getYearStatus(firstY);
                const lastR = getYearStatus(lastY);
                const firstPct = firstR.total > 0 ? (firstR.active + firstR.returned) / firstR.total : 0;
                const lastPct = lastR.total > 0 ? (lastR.active + lastR.returned) / lastR.total : 0;

                if (lastPct > firstPct + 0.01) {
                    sentence += ' Success rate has improved from ' + pctText(firstR.active + firstR.returned, firstR.total) + ' in ' + firstY +
                        ' to ' + pctText(lastR.active + lastR.returned, lastR.total) + ' in ' + lastY + '.';
                } else if (lastPct < firstPct - 0.01) {
                    sentence += ' Success rate has dropped from ' + pctText(firstR.active + firstR.returned, firstR.total) + ' in ' + firstY +
                        ' to ' + pctText(lastR.active + lastR.returned, lastR.total) + ' in ' + lastY + '.';
                } else {
                    sentence += ' Success rate has stayed roughly steady between ' + firstY + ' and ' + lastY + '.';
                }
            }
        }

        el.textContent = sentence;

    } else {
        const r = getYearStatus(selectedYear);
        if (r.total <= 0) {
            el.textContent = 'No distributions were recorded for ' + selectedYear + '.';
            return;
        }

        let sentence = 'In ' + selectedYear + ', ' + r.total.toLocaleString() + ' livestock were distributed \u2014 ' +
            pctText(r.active + r.returned, r.total) + ' succeeded (active or returned) and ' + pctText(r.death, r.total) + ' were lost to death.';

        const idx = statusYears.indexOf(String(selectedYear));
        if (idx > 0) {
            const prevYear = statusYears[idx - 1];
            const prevR = getYearStatus(prevYear);
            if (prevR.total > 0) {
                const curPct = (r.active + r.returned) / r.total;
                const prevPct = (prevR.active + prevR.returned) / prevR.total;
                if (curPct > prevPct + 0.01) {
                    sentence += ' That is an improvement over ' + pctText(prevR.active + prevR.returned, prevR.total) + ' success in ' + prevYear + '.';
                } else if (curPct < prevPct - 0.01) {
                    sentence += ' That is down from ' + pctText(prevR.active + prevR.returned, prevR.total) + ' success in ' + prevYear + '.';
                } else {
                    sentence += ' That is about the same as ' + prevYear + '.';
                }
            }
        }

        el.textContent = sentence;
    }
}

function buildStatusChart(selectedYear) {
    if (!statusCanvas) return;
    const rows = statusYears.map(getYearStatus);

    // dim every year except the selected one (nothing is dimmed for "All Years")
    function shade(color) {
        return statusYears.map(function(y) {
            return (selectedYear === 'all' || String(selectedYear) === y) ? color : color + '38';
        });
    }

    if (statusChartInstance) statusChartInstance.destroy();
    statusChartInstance = new Chart(statusCanvas, {
        type: 'bar',
        data: {
            labels: statusYears,
            datasets: [
                { label: 'Total Distributed', data: rows.map(function(r) { return r.total; }),    backgroundColor: shade('#5d6b60'), borderRadius: 6, maxBarThickness: 24 },
                { label: 'Active (Buhi)',     data: rows.map(function(r) { return r.active; }),   backgroundColor: shade('#3f7cac'), borderRadius: 6, maxBarThickness: 24 },
                { label: 'Returned',          data: rows.map(function(r) { return r.returned; }), backgroundColor: shade('#2e7d32'), borderRadius: 6, maxBarThickness: 24 },
                { label: 'Death (Napatay)',   data: rows.map(function(r) { return r.death; }),    backgroundColor: shade('#c1543f'), borderRadius: 6, maxBarThickness: 24 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { usePointStyle: true, pointStyle: 'circle', padding: 14, font: { family: 'Poppins', size: 10 } }
                },
                tooltip: {
                    padding: 9,
                    callbacks: {
                        footer: function(items) {
                            if (!items.length) return '';
                            const r = rows[items[0].dataIndex];
                            if (!r || r.total <= 0) return '';
                            return 'Success rate: ' + pctText(r.active + r.returned, r.total) +
                                   ' \u00B7 Death: ' + pctText(r.death, r.total);
                        }
                    }
                }
            },
            scales: sharedScales
        }
    });

    if (statusChartSubtitle) {
        statusChartSubtitle.textContent = (selectedYear === 'all')
            ? 'Yearly, by status \u2014 all years'
            : 'Yearly, by status \u2014 ' + selectedYear + ' highlighted';
    }
    updateSuccessBadge(selectedYear);
    buildStatusDescription(selectedYear);
}

/* =========================================================
   FILTERS
========================================================= */
function applyFilters() {
    const selectedYear = analyticsYearFilter ? analyticsYearFilter.value : 'all';

    refreshTrend();

    if (pieChartInstance) {
        const newPieData = getPieDataForYear(selectedYear);
        pieChartInstance.data.datasets[0].data = newPieData;
        pieChartInstance.$centerTotal = pieDataSum(newPieData);
        pieChartInstance.update();
    }

    if (pieChartSubtitle) {
        pieChartSubtitle.textContent = (selectedYear === 'all')
            ? 'Distribution share by type'
            : 'Distribution share by type \u2014 ' + selectedYear;
    }

    buildPieDescription(selectedYear);
    buildStatusChart(selectedYear);
}

refreshTrend();
buildStatusChart('all');

if (analyticsYearFilter) {
    analyticsYearFilter.addEventListener('change', applyFilters);
}

if (analyticsMonthFilter) {
    analyticsMonthFilter.addEventListener('change', applyFilters);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>