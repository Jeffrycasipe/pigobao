<?php

/* =========================================================
   AUTO-REFRESH DATA ENDPOINT for tech_reports.php

   Returns the same chart data as the full page (via the
   shared inc_report_data.php builder) as JSON. The report
   page polls this on an interval so the pie chart, monthly
   success/death chart, and barangay comparison chart pick up
   new "died" / "returned" entries recorded elsewhere (e.g.
   monitoring.php) without the technician reloading the page.
========================================================= */

session_start();
require '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['technician_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$tech_id = (int)$_SESSION['technician_id'];

require 'inc_report_data.php';

echo json_encode([
    'pieDataByYear'        => $pieDataByYear,
    'monthlyReturnsByYear' => $monthlyReturnsByYear,
    'monthlyDeathsByYear'  => $monthlyDeathsByYear,
    'availableYears'       => $availableYears,
    'assignedBarangays'    => $assignedBarangays,
    'totalLivestock'       => $totalLivestock,
    'totalBeneficiaries'   => $totalBeneficiaries,
    'mostDistributedLabel' => $mostDistributedLabel,
    'mostDistributedCount' => $mostDistributedCount,
]);
exit();
