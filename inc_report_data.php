<?php
/* =========================================================
   SHARED REPORT DATA BUILDER
   Used by tech_reports.php (initial page render) and
   tech_reports_data.php (JSON endpoint polled by the page
   every few seconds so the charts pick up new deaths /
   returns without a full page reload).

   Requires $conn and $tech_id to already be set by the
   caller before this file is included.
========================================================= */

/* =========================================================
   PIE CHART - TOTAL LIVESTOCK
========================================================= */
$pieData = [
    'goat' => 0,
    'pig' => 0,
    'carabao' => 0
];

$pieQuery = $conn->query("
    SELECT LOWER(TRIM(l.kind)) AS livestock_type, SUM(d.quantity) AS total
    FROM distributions d
    INNER JOIN livestock l
        ON d.livestock_id = l.livestock_id
    WHERE d.technician_id = $tech_id
    GROUP BY LOWER(TRIM(l.kind))
");

if ($pieQuery) {
    while ($row = $pieQuery->fetch_assoc()) {
        $type = strtolower(trim($row['livestock_type'] ?? ''));
        $total = (int)$row['total'];

        if (isset($pieData[$type])) {
            $pieData[$type] += $total;
        }
    }
}

/* =========================================================
   PIE CHART - PER-YEAR BREAKDOWN
   "__ALL__" keeps the same all-time totals as $pieData above.
   Each year key holds just that year's totals (sourced from
   distribution_date) so the pie chart's year filter can switch
   client-side, no page reload needed.
========================================================= */
$pieDataByYear = [
    '__ALL__' => $pieData,
];

/* Union of every year seen across ANY chart's date column
   (distribution_date, return_date, monitoring.date_recorded).
   This single list drives ONE global year dropdown that
   updates all charts together. */
$availableYears = [];

$pieYearQuery = $conn->query("
    SELECT YEAR(d.distribution_date) AS yr,
           LOWER(TRIM(l.kind)) AS livestock_type,
           SUM(d.quantity) AS total
    FROM distributions d
    INNER JOIN livestock l
        ON d.livestock_id = l.livestock_id
    WHERE d.technician_id = $tech_id
      AND d.distribution_date IS NOT NULL
    GROUP BY YEAR(d.distribution_date), LOWER(TRIM(l.kind))
");

if ($pieYearQuery) {
    while ($row = $pieYearQuery->fetch_assoc()) {
        $yr = (string)(int)$row['yr'];
        $type = strtolower(trim($row['livestock_type'] ?? ''));
        $total = (int)$row['total'];

        if (!isset($pieDataByYear[$yr])) {
            $pieDataByYear[$yr] = ['goat' => 0, 'pig' => 0, 'carabao' => 0];
        }
        if (!in_array($yr, $availableYears, true)) {
            $availableYears[] = $yr;
        }

        if (isset($pieDataByYear[$yr][$type])) {
            $pieDataByYear[$yr][$type] += $total;
        }
    }
}

/* =========================================================
   BARANGAYS ASSIGNED TO THIS TECHNICIAN
========================================================= */
$assignedBarangays = [];

$barangayListQuery = $conn->query("
    SELECT br.barangay_name
    FROM technician_barangay tb
    INNER JOIN barangays br
        ON tb.barangay_id = br.barangay_id
    WHERE tb.technician_id = $tech_id
    ORDER BY br.barangay_name ASC
");

if ($barangayListQuery) {
    while ($row = $barangayListQuery->fetch_assoc()) {
        $assignedBarangays[] = $row['barangay_name'];
    }
}

/* =========================================================
   MONTHLY RETURNED vs DIED, PER YEAR, PER BARANGAY, PER KIND
   Line chart: x-axis = Jan..Dec, y-axis = count.
   Green line  = returned (success)
   Red line    = died (mortality)
   Data is now nested by year (plus an "__ALL__" year key that
   keeps the old all-time totals) so the same global Year filter
   used by the pie chart also drives this chart, with barangay +
   animal type still selectable underneath it.
========================================================= */
$livestockKinds = ['goat', 'pig', 'carabao'];

function pigobao_empty_month_map() {
    return array_fill(1, 12, 0);
}

/* Ensures $byYear[$year][$brgy][$kind] exists, pre-seeded with
   zeroed month maps for every currently-known barangay. */
function pigobao_ensure_year(&$byYear, $year, $barangays, $kinds) {
    if (!isset($byYear[$year])) {
        $byYear[$year] = [];
    }
    foreach ($barangays as $brgy) {
        if (!isset($byYear[$year][$brgy])) {
            $byYear[$year][$brgy] = [];
        }
        foreach ($kinds as $kind) {
            if (!isset($byYear[$year][$brgy][$kind])) {
                $byYear[$year][$brgy][$kind] = pigobao_empty_month_map();
            }
        }
    }
}

$monthlyReturnsByYear = [];
$monthlyDeathsByYear = [];

pigobao_ensure_year($monthlyReturnsByYear, '__ALL__', $assignedBarangays, $livestockKinds);
pigobao_ensure_year($monthlyDeathsByYear, '__ALL__', $assignedBarangays, $livestockKinds);

/* --- RETURNED (success) counts, by year + barangay + kind + month --- */
$returnsQuery = $conn->query("
    SELECT
        YEAR(d.return_date) AS yr,
        br.barangay_name,
        LOWER(TRIM(l.kind)) AS kind,
        MONTH(d.return_date) AS mn,
        COALESCE(SUM(d.quantity), 0) AS total
    FROM distributions d
    INNER JOIN beneficiaries b ON d.beneficiary_id = b.beneficiary_id
    INNER JOIN barangays br ON b.barangay_id = br.barangay_id
    INNER JOIN livestock l ON d.livestock_id = l.livestock_id
    WHERE d.technician_id = $tech_id
      AND d.status = 'returned'
      AND d.return_date IS NOT NULL
    GROUP BY YEAR(d.return_date), br.barangay_name, LOWER(TRIM(l.kind)), MONTH(d.return_date)
");

if ($returnsQuery) {
    while ($row = $returnsQuery->fetch_assoc()) {
        $yr = (string)(int)$row['yr'];
        $brgy = $row['barangay_name'];
        $kind = $row['kind'];
        $mn = (int)$row['mn'];
        $total = (int)$row['total'];

        if (!in_array($yr, $availableYears, true)) {
            $availableYears[] = $yr;
        }

        pigobao_ensure_year($monthlyReturnsByYear, $yr, $assignedBarangays, $livestockKinds);
        if (!isset($monthlyReturnsByYear[$yr][$brgy])) {
            $monthlyReturnsByYear[$yr][$brgy] = [];
        }
        if (!isset($monthlyReturnsByYear[$yr][$brgy][$kind])) {
            $monthlyReturnsByYear[$yr][$brgy][$kind] = pigobao_empty_month_map();
        }
        if (!isset($monthlyReturnsByYear['__ALL__'][$brgy])) {
            $monthlyReturnsByYear['__ALL__'][$brgy] = [];
        }
        if (!isset($monthlyReturnsByYear['__ALL__'][$brgy][$kind])) {
            $monthlyReturnsByYear['__ALL__'][$brgy][$kind] = pigobao_empty_month_map();
        }

        if ($mn >= 1 && $mn <= 12) {
            $monthlyReturnsByYear[$yr][$brgy][$kind][$mn] += $total;
            $monthlyReturnsByYear['__ALL__'][$brgy][$kind][$mn] += $total;
        }
    }
}

/* --- DIED (mortality) counts, by year + barangay + kind + month ---
   Sourced from the `monitoring` table (more detailed / more
   reliably logged than distributions.status), matched back to
   distributions via distribution_id. A death is either an
   explicit action='death' entry or a health_status='Dead'
   reading, dated by date_recorded.

   FIX: save_monitoring.php authorizes recording a death for ANY
   distribution in a barangay the technician is assigned to (via
   technician_barangay) — it does NOT require the technician doing
   the recording to be the same technician who originally created
   the distribution (distributions.technician_id). So a death could
   be recorded by technician B for livestock technician A
   distributed. The old "WHERE d.technician_id = $tech_id" only
   matched the *distributing* technician, silently dropping deaths
   recorded by anyone else — which is why a freshly recorded death
   could vanish from the chart. Now a death counts for a technician
   if either they own the distribution OR they're the one who
   recorded the monitoring/death entry (monitoring.created_by). */
$deathsQuery = $conn->query("
    SELECT
        YEAR(m.date_recorded) AS yr,
        br.barangay_name,
        LOWER(TRIM(l.kind)) AS kind,
        MONTH(m.date_recorded) AS mn,
        COUNT(*) AS total
    FROM monitoring m
    INNER JOIN distributions d ON m.distribution_id = d.distribution_id
    INNER JOIN beneficiaries b ON d.beneficiary_id = b.beneficiary_id
    INNER JOIN barangays br ON b.barangay_id = br.barangay_id
    INNER JOIN livestock l ON d.livestock_id = l.livestock_id
    WHERE (d.technician_id = $tech_id OR m.created_by = $tech_id)
      AND (m.action = 'death' OR m.health_status = 'Dead')
    GROUP BY YEAR(m.date_recorded), br.barangay_name, LOWER(TRIM(l.kind)), MONTH(m.date_recorded)
");

if ($deathsQuery) {
    while ($row = $deathsQuery->fetch_assoc()) {
        $yr = (string)(int)$row['yr'];
        $brgy = $row['barangay_name'];
        $kind = $row['kind'];
        $mn = (int)$row['mn'];
        $total = (int)$row['total'];

        if (!in_array($yr, $availableYears, true)) {
            $availableYears[] = $yr;
        }

        pigobao_ensure_year($monthlyDeathsByYear, $yr, $assignedBarangays, $livestockKinds);
        if (!isset($monthlyDeathsByYear[$yr][$brgy])) {
            $monthlyDeathsByYear[$yr][$brgy] = [];
        }
        if (!isset($monthlyDeathsByYear[$yr][$brgy][$kind])) {
            $monthlyDeathsByYear[$yr][$brgy][$kind] = pigobao_empty_month_map();
        }
        if (!isset($monthlyDeathsByYear['__ALL__'][$brgy])) {
            $monthlyDeathsByYear['__ALL__'][$brgy] = [];
        }
        if (!isset($monthlyDeathsByYear['__ALL__'][$brgy][$kind])) {
            $monthlyDeathsByYear['__ALL__'][$brgy][$kind] = pigobao_empty_month_map();
        }

        if ($mn >= 1 && $mn <= 12) {
            $monthlyDeathsByYear[$yr][$brgy][$kind][$mn] += $total;
            $monthlyDeathsByYear['__ALL__'][$brgy][$kind][$mn] += $total;
        }
    }
}

/* Fallback: if the technician has no rows in technician_barangay yet,
   still show whichever barangays turned up in the returns/deaths data
   instead of an empty dropdown. */
if (empty($assignedBarangays)) {
    $assignedBarangays = array_values(array_unique(array_merge(
        array_keys($monthlyReturnsByYear['__ALL__']),
        array_keys($monthlyDeathsByYear['__ALL__'])
    )));
    sort($assignedBarangays);
}

/* "All Barangays" aggregate, per year (including the "__ALL__" year
   itself), so the barangay dropdown can show a combined view no
   matter which year is selected. Uses the union of years so a year
   that only has returns (or only deaths) still gets a full entry
   in both structures. */
$allYearKeys = array_values(array_unique(array_merge(
    array_keys($monthlyReturnsByYear),
    array_keys($monthlyDeathsByYear)
)));

foreach ($allYearKeys as $yr) {
    pigobao_ensure_year($monthlyReturnsByYear, $yr, $assignedBarangays, $livestockKinds);
    pigobao_ensure_year($monthlyDeathsByYear, $yr, $assignedBarangays, $livestockKinds);

    $monthlyReturnsByYear[$yr]['__ALL__'] = [];
    $monthlyDeathsByYear[$yr]['__ALL__'] = [];

    foreach ($livestockKinds as $kind) {
        $monthlyReturnsByYear[$yr]['__ALL__'][$kind] = pigobao_empty_month_map();
        $monthlyDeathsByYear[$yr]['__ALL__'][$kind] = pigobao_empty_month_map();

        foreach ($assignedBarangays as $brgy) {
            for ($m = 1; $m <= 12; $m++) {
                $monthlyReturnsByYear[$yr]['__ALL__'][$kind][$m] += $monthlyReturnsByYear[$yr][$brgy][$kind][$m] ?? 0;
                $monthlyDeathsByYear[$yr]['__ALL__'][$kind][$m] += $monthlyDeathsByYear[$yr][$brgy][$kind][$m] ?? 0;
            }
        }
    }
}

rsort($availableYears);

/* =========================================================
   TOTALS
========================================================= */
$totalLivestock = array_sum($pieData);

$totalBeneficiaries = 0;

$beneficiaryCountQuery = $conn->query("
    SELECT COUNT(DISTINCT beneficiary_id) AS total
    FROM distributions
    WHERE technician_id = $tech_id
      AND status = 'active'
");

if ($beneficiaryCountQuery) {
    $countRow = $beneficiaryCountQuery->fetch_assoc();
    $totalBeneficiaries = (int)$countRow['total'];
}

/* Which livestock type has the highest distributed count — drives
   the "Most distributed" descriptive stat under the pie chart. */
$mostDistributedType = null;
$mostDistributedCount = -1;
foreach ($pieData as $type => $count) {
    if ($count > $mostDistributedCount) {
        $mostDistributedCount = $count;
        $mostDistributedType = $type;
    }
}
$mostDistributedLabel = $mostDistributedCount > 0 ? ucfirst($mostDistributedType) : 'No data yet';

$totalDistributions = 0;

$distributionCountQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM distributions
    WHERE technician_id = $tech_id
      AND status = 'active'
");

if ($distributionCountQuery) {
    $countRow = $distributionCountQuery->fetch_assoc();
    $totalDistributions = (int)$countRow['total'];
}